#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""mysite 發布清單（release payload／envelope）：產生、簽章、驗證與主機發布狀態。

規劃書 v5 §14.5–14.6、執行契約 §14.3。只用 Python 標準函式庫與 OpenSSL 3 CLI：
  簽章：openssl pkeyutl -sign -inkey <私鑰> -rawin -in <payload> -out <sig>
  驗簽：openssl pkeyutl -verify -pubin -inkey <公鑰> -rawin -in <payload> -sigfile <sig>
（OpenSSL 3.0 pkeyutl 官方文件：EdDSA 必須使用 -rawin，且不可指定 -digest。）

驗證順序（不可調換）：
  1. 限制大小後嚴格解析 envelope（只允許四個欄位、拒絕重複鍵）
  2. 依 key_id 從主機信任目錄選公鑰並驗簽（簽的是原始 payload bytes）
  3. 驗簽成功後才解析 payload（UTF-8、拒絕重複鍵／NaN）
  4. 嚴格 schema（拒絕未知欄位、shell／mounts／entrypoint／服務、非核准 registry／映像名、
     非 @sha256 digest、MySQL 大版本變更、不支援的 platform_contract、已撤銷 digest）
  5. project／environment／channel 與主機設定一致
  6. 單調序號（相同序號且內容相同＝已成功 no-op；較小＝拒絕；同序號不同內容＝拒絕）
  7. created_at／expires_at（預設最長 24 小時；已套用的同一清單即使過期也只回 no-op）

結束碼：0 ACCEPT、3 NOOP、4 REJECT、2 參數錯、5 環境缺件（無 openssl／信任目錄）、6 重試次數已達上限。
本模組不執行 payload 內任何內容，也不輸出秘密。
"""

from __future__ import annotations

import argparse
import base64
import binascii
import datetime as _dt
import hashlib
import json
import os
import re
import shutil
import stat
import subprocess
import sys
import tempfile
import time
from typing import Any

# ---------------------------------------------------------------------------
# 契約常數（變更屬高風險審核路徑）
# ---------------------------------------------------------------------------
PROJECT = "Whenwhoengine/mysite"
ENVIRONMENTS = ("staging", "production")
SCHEMA_VERSION = 1
SUPPORTED_PLATFORM_CONTRACTS = (1,)
IMAGE_NAMES = ("laravel", "wordpress", "app-web", "wp-web", "gateway")
DEFAULT_IMAGE_REPOSITORY = "ghcr.io/whenwhoengine/mysite"
APPROVED_REGISTRIES = ("ghcr.io",)
SCHEMA_CHANGE_CLASSES = ("none", "expand", "contract", "breaking")
AUTO_SCHEMA_CLASSES = ("none", "expand")
SIGNATURE_ALGORITHM = "ed25519"
ENVELOPE_KEYS = ("key_id", "payload_base64", "signature_algorithm", "signature_base64")

# 暫定值（規格未定者；調整需記錄理由）
MAX_ENVELOPE_BYTES = 65536          # 暫定：envelope 檔案上限 64 KiB
MAX_PAYLOAD_BYTES = 16384           # 暫定：payload 上限 16 KiB
MAX_VALIDITY_SECONDS = 24 * 3600    # v5 §14.6：新清單有效期預設 24 小時
CLOCK_SKEW_SECONDS = 300            # 暫定：created_at 容許的未來時鐘偏差 5 分鐘
DEFAULT_MAX_ATTEMPTS = 3            # 暫定：同一清單失敗重試上限

PAYLOAD_FIELDS = (
    "schema_version",
    "project",
    "environment",
    "channel",
    "sequence",
    "release_id",
    "source_commit",
    "created_at",
    "expires_at",
    "platform_contract",
    "images",
    "infrastructure",
    "schema_change_class",
    "migration_plan_ref",
    "verification_run_id",
    "previous_verified_release",
    "rollback_target_release",
)
INFRASTRUCTURE_FIELDS = ("mysql_major",)

RE_CHANNEL = re.compile(r"^[a-z][a-z0-9-]{1,31}$")
RE_RELEASE_ID = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]{2,79}$")
RE_COMMIT = re.compile(r"^[0-9a-f]{40}$")
RE_TIME = re.compile(r"^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$")
RE_KEY_ID = re.compile(r"^[a-z0-9][a-z0-9-]{2,63}$")
RE_PLAN_REF = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._:/#-]{2,199}$")
RE_RUN_ID = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._:/-]{0,127}$")
RE_MYSQL_MAJOR = re.compile(r"^[0-9]{1,2}\.[0-9]{1,2}$")
RE_DIGEST = re.compile(r"^sha256:[0-9a-f]{64}$")
RE_IMAGE_REF = re.compile(
    r"^(?P<registry>[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?(:[0-9]{1,5})?)/"
    r"(?P<path>[a-z0-9]+(?:[._-][a-z0-9]+)*(?:/[a-z0-9]+(?:[._-][a-z0-9]+)*)*)"
    r"@(?P<digest>sha256:[0-9a-f]{64})$"
)
RE_REPOSITORY = re.compile(
    r"^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?(:[0-9]{1,5})?/[a-z0-9]+(?:[._-][a-z0-9]+)*(?:/[a-z0-9]+(?:[._-][a-z0-9]+)*)*$"
)

EXIT_ACCEPT, EXIT_USAGE, EXIT_NOOP, EXIT_REJECT, EXIT_ENV, EXIT_RETRY_LIMIT = 0, 2, 3, 4, 5, 6


class Reject(Exception):
    """清單被拒絕：code 為穩定代碼，message 為去秘密化說明。"""

    def __init__(self, code: str, message: str) -> None:
        super().__init__(message)
        self.code = code
        self.message = message


class EnvError(Exception):
    """執行環境缺件（openssl、信任目錄、狀態檔損毀）。"""


# ---------------------------------------------------------------------------
# 嚴格 JSON
# ---------------------------------------------------------------------------
def _no_duplicates(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    out: dict[str, Any] = {}
    for k, v in pairs:
        if k in out:
            raise Reject("DUPLICATE_KEY", f"JSON 含重複鍵：{k!r}")
        out[k] = v
    return out


def _bad_constant(name: str) -> Any:
    raise Reject("INVALID_JSON", f"JSON 不允許 {name}")


def strict_json_loads(data: bytes, what: str) -> Any:
    if data.startswith(b"\xef\xbb\xbf"):
        raise Reject("INVALID_JSON", f"{what} 不得含 BOM")
    try:
        text = data.decode("utf-8", errors="strict")
    except UnicodeDecodeError as exc:
        raise Reject("INVALID_UTF8", f"{what} 不是合法 UTF-8") from exc
    try:
        return json.loads(text, object_pairs_hook=_no_duplicates, parse_constant=_bad_constant)
    except Reject:
        raise
    except (ValueError, RecursionError) as exc:
        raise Reject("INVALID_JSON", f"{what} 不是合法 JSON") from exc


def canonical_json(obj: Any) -> bytes:
    return (json.dumps(obj, ensure_ascii=False, sort_keys=True, separators=(",", ":")) + "\n").encode("utf-8")


def _is_int(v: Any) -> bool:
    return isinstance(v, int) and not isinstance(v, bool)


def parse_time(value: Any, field: str) -> int:
    if not isinstance(value, str) or not RE_TIME.match(value):
        raise Reject("INVALID_FIELD", f"{field} 必須是 UTC RFC3339（YYYY-MM-DDTHH:MM:SSZ）")
    try:
        dt = _dt.datetime.strptime(value, "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=_dt.timezone.utc)
    except ValueError as exc:
        raise Reject("INVALID_FIELD", f"{field} 不是有效時間") from exc
    return int(dt.timestamp())


def fmt_time(epoch: int) -> str:
    return _dt.datetime.fromtimestamp(epoch, tz=_dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


# ---------------------------------------------------------------------------
# payload schema
# ---------------------------------------------------------------------------
def validate_image_ref(name: str, ref: Any, repository: str) -> str:
    if not isinstance(ref, str):
        raise Reject("INVALID_IMAGE", f"images.{name} 必須是字串")
    if ref.endswith(":latest") or ":latest@" in ref or ref.split("/")[-1].split("@")[0].endswith(":latest"):
        raise Reject("IMAGE_LATEST", f"images.{name} 使用 latest 標籤")
    m = RE_IMAGE_REF.match(ref)
    if not m:
        raise Reject("IMAGE_NOT_DIGEST", f"images.{name} 必須是 <registry>/<路徑>@sha256:<64位hex>，不得使用 tag")
    registry = m.group("registry")
    if registry not in APPROVED_REGISTRIES:
        raise Reject("REGISTRY_NOT_APPROVED", f"images.{name} 的 registry 未核准：{registry}")
    expected = f"{repository}/{name}"
    actual = f"{registry}/{m.group('path')}"
    if actual != expected:
        raise Reject("IMAGE_NAME_NOT_APPROVED", f"images.{name} 必須是 {expected}@sha256:…")
    return m.group("digest")


def validate_payload(
    payload: Any,
    *,
    repository: str = DEFAULT_IMAGE_REPOSITORY,
    mysql_major: str | None = None,
    revoked_digests: frozenset[str] = frozenset(),
) -> dict[str, Any]:
    if not isinstance(payload, dict):
        raise Reject("INVALID_PAYLOAD", "payload 必須是 JSON 物件")
    unknown = sorted(set(payload) - set(PAYLOAD_FIELDS))
    if unknown:
        raise Reject("UNKNOWN_FIELD", "payload 含未核准欄位：" + ", ".join(unknown[:8]))
    missing = [f for f in PAYLOAD_FIELDS if f not in payload]
    if missing:
        raise Reject("MISSING_FIELD", "payload 缺少欄位：" + ", ".join(missing))

    if not _is_int(payload["schema_version"]) or payload["schema_version"] != SCHEMA_VERSION:
        raise Reject("UNSUPPORTED_SCHEMA", "schema_version 不支援")
    if payload["project"] != PROJECT:
        raise Reject("PROJECT_MISMATCH", "project 不是本案儲存庫")
    if payload["environment"] not in ENVIRONMENTS:
        raise Reject("INVALID_FIELD", "environment 必須是 staging 或 production")
    if not isinstance(payload["channel"], str) or not RE_CHANNEL.match(payload["channel"]):
        raise Reject("INVALID_FIELD", "channel 格式不合法")
    seq = payload["sequence"]
    if not _is_int(seq) or seq < 1 or seq > 2**53:
        raise Reject("INVALID_FIELD", "sequence 必須是 1 以上的整數")
    if not isinstance(payload["release_id"], str) or not RE_RELEASE_ID.match(payload["release_id"]):
        raise Reject("INVALID_FIELD", "release_id 格式不合法")
    if not isinstance(payload["source_commit"], str) or not RE_COMMIT.match(payload["source_commit"]):
        raise Reject("INVALID_FIELD", "source_commit 必須是 40 位小寫 hex")
    created = parse_time(payload["created_at"], "created_at")
    expires = parse_time(payload["expires_at"], "expires_at")
    if expires <= created:
        raise Reject("INVALID_VALIDITY", "expires_at 必須晚於 created_at")
    if expires - created > MAX_VALIDITY_SECONDS:
        raise Reject("INVALID_VALIDITY", "有效期間超過 24 小時上限")
    pc = payload["platform_contract"]
    if not _is_int(pc) or pc not in SUPPORTED_PLATFORM_CONTRACTS:
        raise Reject("UNSUPPORTED_PLATFORM_CONTRACT", "platform_contract 不受本主機支援")

    images = payload["images"]
    if not isinstance(images, dict):
        raise Reject("INVALID_IMAGE", "images 必須是物件")
    extra = sorted(set(images) - set(IMAGE_NAMES))
    if extra:
        raise Reject("UNKNOWN_SERVICE", "images 含未核准服務：" + ", ".join(extra[:8]))
    lack = [n for n in IMAGE_NAMES if n not in images]
    if lack:
        raise Reject("MISSING_FIELD", "images 缺少：" + ", ".join(lack))
    for name in IMAGE_NAMES:
        digest = validate_image_ref(name, images[name], repository)
        if digest in revoked_digests:
            raise Reject("DIGEST_REVOKED", f"images.{name} 的 digest 已被主機列為撤銷")

    infra = payload["infrastructure"]
    if not isinstance(infra, dict):
        raise Reject("INVALID_FIELD", "infrastructure 必須是物件")
    extra = sorted(set(infra) - set(INFRASTRUCTURE_FIELDS))
    if extra:
        raise Reject("UNKNOWN_FIELD", "infrastructure 含未核准欄位：" + ", ".join(extra[:8]))
    if "mysql_major" not in infra:
        raise Reject("MISSING_FIELD", "infrastructure 缺少 mysql_major")
    if not isinstance(infra["mysql_major"], str) or not RE_MYSQL_MAJOR.match(infra["mysql_major"]):
        raise Reject("INVALID_FIELD", "infrastructure.mysql_major 格式不合法")
    if mysql_major is not None and infra["mysql_major"] != mysql_major:
        raise Reject("MYSQL_MAJOR_CHANGE", "清單要求的 MySQL 大版本與主機平台不同；基礎設施變更須另走審核")

    cls = payload["schema_change_class"]
    if cls not in SCHEMA_CHANGE_CLASSES:
        raise Reject("INVALID_FIELD", "schema_change_class 必須是 none／expand／contract／breaking")
    plan = payload["migration_plan_ref"]
    if plan is not None and (not isinstance(plan, str) or not RE_PLAN_REF.match(plan)):
        raise Reject("INVALID_FIELD", "migration_plan_ref 格式不合法")
    if cls in ("contract", "breaking") and plan is None:
        raise Reject("MISSING_FIELD", "contract／breaking 變更必須附 migration_plan_ref")
    run_id = payload["verification_run_id"]
    if not isinstance(run_id, str) or not RE_RUN_ID.match(run_id):
        raise Reject("INVALID_FIELD", "verification_run_id 格式不合法")
    for f in ("previous_verified_release", "rollback_target_release"):
        v = payload[f]
        if v is not None and (not isinstance(v, str) or not RE_RELEASE_ID.match(v)):
            raise Reject("INVALID_FIELD", f"{f} 格式不合法")
    if payload["rollback_target_release"] is not None and cls != "none":
        raise Reject("INVALID_ROLLBACK", "回復清單不得帶 schema 變更（schema_change_class 必須是 none）")
    return payload


# ---------------------------------------------------------------------------
# OpenSSL
# ---------------------------------------------------------------------------
def _openssl() -> str:
    path = shutil.which("openssl")
    if not path:
        raise EnvError("找不到 openssl")
    return path


def _run_openssl(args: list[str], timeout: int = 30) -> subprocess.CompletedProcess[bytes]:
    return subprocess.run([_openssl(), *args], stdin=subprocess.DEVNULL, capture_output=True, timeout=timeout, check=False)


def assert_ed25519_public_key(path: str) -> None:
    r = _run_openssl(["pkey", "-pubin", "-in", path, "-noout", "-text"])
    first = r.stdout.decode("utf-8", "replace").splitlines()[:1]
    if r.returncode != 0 or not first or not first[0].startswith("ED25519 Public-Key"):
        raise Reject("TRUST_KEY_INVALID", "信任目錄中的公鑰不是 Ed25519 公鑰")


def assert_ed25519_private_key(path: str) -> None:
    r = _run_openssl(["pkey", "-in", path, "-noout", "-text"])
    first = r.stdout.decode("utf-8", "replace").splitlines()[:1]
    if r.returncode != 0 or not first or not first[0].startswith("ED25519 Private-Key"):
        raise EnvError("簽章私鑰不是 Ed25519 私鑰")


def openssl_verify(pubkey: str, payload: bytes, signature: bytes) -> bool:
    with tempfile.TemporaryDirectory(prefix="mysite-verify-") as tmp:
        p = os.path.join(tmp, "payload.bin")
        s = os.path.join(tmp, "sig.bin")
        with open(p, "wb") as fh:
            fh.write(payload)
        with open(s, "wb") as fh:
            fh.write(signature)
        r = _run_openssl(["pkeyutl", "-verify", "-pubin", "-inkey", pubkey, "-rawin", "-in", p, "-sigfile", s])
        return r.returncode == 0


def openssl_sign(privkey: str, payload: bytes) -> bytes:
    with tempfile.TemporaryDirectory(prefix="mysite-sign-") as tmp:
        p = os.path.join(tmp, "payload.bin")
        s = os.path.join(tmp, "sig.bin")
        with open(p, "wb") as fh:
            fh.write(payload)
        r = _run_openssl(["pkeyutl", "-sign", "-inkey", privkey, "-rawin", "-in", p, "-out", s])
        if r.returncode != 0:
            raise EnvError("openssl 簽章失敗")
        with open(s, "rb") as fh:
            return fh.read()


def derive_public_key(privkey: str, out_path: str) -> None:
    r = _run_openssl(["pkey", "-in", privkey, "-pubout", "-out", out_path])
    if r.returncode != 0:
        raise EnvError("無法由私鑰導出公鑰")


# ---------------------------------------------------------------------------
# envelope
# ---------------------------------------------------------------------------
def _b64decode_strict(value: Any, field: str) -> bytes:
    if not isinstance(value, str) or not value:
        raise Reject("INVALID_ENVELOPE", f"{field} 必須是非空字串")
    try:
        raw = base64.b64decode(value.encode("ascii"), validate=True)
    except (binascii.Error, UnicodeEncodeError) as exc:
        raise Reject("INVALID_BASE64", f"{field} 不是合法 base64") from exc
    if base64.b64encode(raw).decode("ascii") != value:
        raise Reject("INVALID_BASE64", f"{field} 不是標準 base64 編碼")
    return raw


def read_limited(path: str, limit: int) -> bytes:
    try:
        st = os.stat(path)
    except OSError as exc:
        raise Reject("ENVELOPE_UNREADABLE", "無法讀取 envelope 檔案") from exc
    if not stat.S_ISREG(st.st_mode):
        raise Reject("ENVELOPE_UNREADABLE", "envelope 必須是一般檔案")
    if st.st_size > limit:
        raise Reject("ENVELOPE_TOO_LARGE", f"envelope 超過 {limit} bytes 上限")
    with open(path, "rb") as fh:
        data = fh.read(limit + 1)
    if len(data) > limit:
        raise Reject("ENVELOPE_TOO_LARGE", f"envelope 超過 {limit} bytes 上限")
    return data


def parse_envelope(data: bytes) -> tuple[str, bytes, bytes]:
    env = strict_json_loads(data, "envelope")
    if not isinstance(env, dict):
        raise Reject("INVALID_ENVELOPE", "envelope 必須是 JSON 物件")
    if tuple(sorted(env)) != ENVELOPE_KEYS:
        raise Reject("INVALID_ENVELOPE", "envelope 只允許 payload_base64、signature_base64、key_id、signature_algorithm")
    if env["signature_algorithm"] != SIGNATURE_ALGORITHM:
        raise Reject("UNSUPPORTED_ALGORITHM", "signature_algorithm 必須是 ed25519")
    key_id = env["key_id"]
    if not isinstance(key_id, str) or not RE_KEY_ID.match(key_id):
        raise Reject("INVALID_KEY_ID", "key_id 格式不合法")
    payload = _b64decode_strict(env["payload_base64"], "payload_base64")
    if len(payload) > MAX_PAYLOAD_BYTES:
        raise Reject("PAYLOAD_TOO_LARGE", "payload 超過上限")
    sig = _b64decode_strict(env["signature_base64"], "signature_base64")
    if len(sig) != 64:
        raise Reject("BAD_SIGNATURE", "Ed25519 簽章長度必須是 64 bytes")
    return key_id, payload, sig


def trusted_key_path(trust_dir: str, key_id: str) -> str:
    try:
        st = os.stat(trust_dir)
    except OSError as exc:
        raise EnvError("主機信任目錄不存在") from exc
    if not stat.S_ISDIR(st.st_mode):
        raise EnvError("主機信任目錄不是目錄")
    if st.st_mode & (stat.S_IWGRP | stat.S_IWOTH):
        raise EnvError("主機信任目錄權限過寬（群組或其他人可寫）")
    path = os.path.join(trust_dir, key_id + ".pem")
    try:
        kst = os.lstat(path)
    except OSError as exc:
        raise Reject("UNKNOWN_KEY_ID", "key_id 不在本主機信任清單") from exc
    if not stat.S_ISREG(kst.st_mode):
        raise Reject("UNKNOWN_KEY_ID", "信任公鑰必須是一般檔案（不接受符號連結）")
    if kst.st_mode & (stat.S_IWGRP | stat.S_IWOTH):
        raise EnvError("信任公鑰檔權限過寬")
    assert_ed25519_public_key(path)
    return path


# ---------------------------------------------------------------------------
# 主機發布狀態
# ---------------------------------------------------------------------------
def _state_path(state_dir: str) -> str:
    return os.path.join(state_dir, "release-state.json")


def load_state(state_dir: str | None, environment: str | None = None) -> dict[str, Any]:
    empty = {"schema_version": 1, "environment": environment, "highest_accepted_sequence": 0, "active": None, "pending": None}
    if not state_dir:
        return empty
    path = _state_path(state_dir)
    if not os.path.exists(path):
        return empty
    try:
        with open(path, "rb") as fh:
            st = strict_json_loads(fh.read(), "release-state.json")
    except Reject as exc:
        raise EnvError("主機狀態檔損毀，拒絕繼續：" + exc.message) from exc
    if not isinstance(st, dict) or not _is_int(st.get("highest_accepted_sequence")):
        raise EnvError("主機狀態檔格式不合法，拒絕繼續")
    if environment and st.get("environment") not in (None, environment):
        raise EnvError("主機狀態檔屬於其他環境，拒絕繼續")
    st.setdefault("active", None)
    st.setdefault("pending", None)
    return st


def save_state(state_dir: str, st: dict[str, Any]) -> None:
    os.makedirs(state_dir, exist_ok=True)
    path = _state_path(state_dir)
    fd, tmp = tempfile.mkstemp(prefix=".release-state.", dir=state_dir)
    try:
        with os.fdopen(fd, "wb") as fh:
            fh.write(json.dumps(st, ensure_ascii=False, sort_keys=True, indent=2).encode("utf-8") + b"\n")
            fh.flush()
            os.fsync(fh.fileno())
        os.chmod(tmp, 0o640)
        os.replace(tmp, path)
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)


def read_history(state_dir: str) -> list[dict[str, Any]]:
    path = os.path.join(state_dir, "history.jsonl")
    out: list[dict[str, Any]] = []
    if not os.path.exists(path):
        return out
    with open(path, "rb") as fh:
        for line in fh:
            line = line.strip()
            if line:
                try:
                    out.append(strict_json_loads(line, "history.jsonl"))
                except Reject as exc:
                    raise EnvError("發布歷史檔損毀：" + exc.message) from exc
    return out


def append_history(state_dir: str, entry: dict[str, Any]) -> None:
    path = os.path.join(state_dir, "history.jsonl")
    with open(path, "ab") as fh:
        fh.write(json.dumps(entry, ensure_ascii=False, sort_keys=True).encode("utf-8") + b"\n")
        fh.flush()
        os.fsync(fh.fileno())


def load_revoked(path: str | None) -> frozenset[str]:
    if not path or not os.path.exists(path):
        return frozenset()
    out = set()
    with open(path, "r", encoding="utf-8") as fh:
        for line in fh:
            line = line.split("#", 1)[0].strip()
            if not line:
                continue
            if not RE_DIGEST.match(line):
                raise EnvError("撤銷 digest 清單格式不合法")
            out.add(line)
    return frozenset(out)


# ---------------------------------------------------------------------------
# 驗證主流程
# ---------------------------------------------------------------------------
def verify_envelope(
    envelope_path: str,
    *,
    trust_dir: str,
    environment: str,
    channel: str,
    state_dir: str | None,
    now: int,
    repository: str = DEFAULT_IMAGE_REPOSITORY,
    mysql_major: str | None = None,
    revoked_path: str | None = None,
    check_state: bool = True,
    check_time: bool = True,
) -> dict[str, Any]:
    data = read_limited(envelope_path, MAX_ENVELOPE_BYTES)
    key_id, payload_bytes, sig = parse_envelope(data)
    key_path = trusted_key_path(trust_dir, key_id)
    if not openssl_verify(key_path, payload_bytes, sig):
        raise Reject("BAD_SIGNATURE", "簽章驗證失敗（內容被改動、錯誤金鑰或錯誤簽章）")
    payload = strict_json_loads(payload_bytes, "payload")
    validate_payload(payload, repository=repository, mysql_major=mysql_major, revoked_digests=load_revoked(revoked_path))
    if payload["environment"] != environment:
        raise Reject("ENVIRONMENT_MISMATCH", "清單環境與本主機環境不同（跨環境清單）")
    if payload["channel"] != channel:
        raise Reject("CHANNEL_MISMATCH", "清單 channel 與本主機設定不同")
    payload_sha = hashlib.sha256(payload_bytes).hexdigest()
    result: dict[str, Any] = {
        "result": "ACCEPT",
        "code": "OK",
        "message": "驗證通過",
        "key_id": key_id,
        "sequence": payload["sequence"],
        "release_id": payload["release_id"],
        "payload_sha256": payload_sha,
        "schema_change_class": payload["schema_change_class"],
        "rollback_target_release": payload["rollback_target_release"],
        "payload": payload,
        "payload_bytes": payload_bytes,
    }
    st = load_state(state_dir, environment) if check_state else None
    seq = payload["sequence"]
    if st is not None:
        active = st.get("active") or None
        if active and seq == active.get("sequence"):
            if active.get("payload_sha256") == payload_sha:
                result.update(result="NOOP", code="ALREADY_ACTIVE", message="相同序號清單已成功套用（no-op）")
                return result
            raise Reject("SEQUENCE_REUSED", "序號與現行版相同但內容不同")
    if check_time:
        created = parse_time(payload["created_at"], "created_at")
        expires = parse_time(payload["expires_at"], "expires_at")
        if created > now + CLOCK_SKEW_SECONDS:
            raise Reject("NOT_YET_VALID", "created_at 在未來（檢查主機時間同步）")
        if now >= expires:
            raise Reject("EXPIRED", "清單已過期")
    if st is not None:
        highest = st.get("highest_accepted_sequence", 0)
        pending = st.get("pending") or None
        if seq < highest:
            raise Reject("REPLAY_OLD_SEQUENCE", f"序號 {seq} 小於已接受序號 {highest}（重播或倒退）")
        if seq == highest:
            if pending and pending.get("sequence") == seq and pending.get("payload_sha256") == payload_sha:
                result.update(code="RETRY_PENDING", message="同一清單先前未完成，允許有上限的重試")
            else:
                raise Reject("SEQUENCE_REUSED", f"序號 {seq} 已被其他內容使用")
    return result


def _env_lines(res: dict[str, Any]) -> str:
    p = res["payload"]
    lines = [
        f"RELEASE_ID={p['release_id']}",
        f"RELEASE_SEQUENCE={p['sequence']}",
        f"RELEASE_ENVIRONMENT={p['environment']}",
        f"RELEASE_CHANNEL={p['channel']}",
        f"RELEASE_SOURCE_COMMIT={p['source_commit']}",
        f"RELEASE_SCHEMA_CHANGE_CLASS={p['schema_change_class']}",
        f"RELEASE_MIGRATION_PLAN_REF={p['migration_plan_ref'] or ''}",
        f"RELEASE_ROLLBACK_TARGET={p['rollback_target_release'] or ''}",
        f"RELEASE_PAYLOAD_SHA256={res['payload_sha256']}",
        f"RELEASE_KEY_ID={res['key_id']}",
        f"RELEASE_VERIFY_CODE={res['code']}",
    ]
    for name in IMAGE_NAMES:
        lines.append(f"MYSITE_IMAGE_{name.upper().replace('-', '_')}={p['images'][name]}")
    return "\n".join(lines) + "\n"


def _write_private(path: str, data: bytes) -> None:
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o640)
    with os.fdopen(fd, "wb") as fh:
        fh.write(data)


def _public_result(res: dict[str, Any]) -> dict[str, Any]:
    return {k: v for k, v in res.items() if k not in ("payload", "payload_bytes")}


def _now(args: argparse.Namespace) -> int:
    return int(args.now) if getattr(args, "now", None) is not None else int(time.time())


def _emit(obj: dict[str, Any]) -> None:
    sys.stdout.write(json.dumps(obj, ensure_ascii=False, sort_keys=True) + "\n")


def cmd_verify(args: argparse.Namespace) -> int:
    try:
        res = verify_envelope(
            args.envelope,
            trust_dir=args.trust_dir,
            environment=args.environment,
            channel=args.channel,
            state_dir=args.state_dir,
            now=_now(args),
            repository=args.image_repository,
            mysql_major=args.mysql_major,
            revoked_path=args.revoked_digests,
            check_state=not args.no_state,
            check_time=not args.no_time,
        )
    except Reject as exc:
        _emit({"result": "REJECT", "code": exc.code, "message": exc.message})
        return EXIT_REJECT
    if args.payload_out:
        _write_private(args.payload_out, res["payload_bytes"])
    if args.env_out:
        _write_private(args.env_out, _env_lines(res).encode("utf-8"))
    _emit(_public_result(res))
    return EXIT_NOOP if res["result"] == "NOOP" else EXIT_ACCEPT


def cmd_read_verified(args: argparse.Namespace) -> int:
    """以指定公鑰驗簽並做 schema 檢查後輸出單一欄位（CI 讀 channel 現值用；不檢查時間與主機狀態）。"""
    try:
        data = read_limited(args.envelope, MAX_ENVELOPE_BYTES)
        key_id, payload_bytes, sig = parse_envelope(data)
        if args.key_id and key_id != args.key_id:
            raise Reject("UNKNOWN_KEY_ID", "envelope 的 key_id 與預期不同")
        assert_ed25519_public_key(args.public_key)
        if not openssl_verify(args.public_key, payload_bytes, sig):
            raise Reject("BAD_SIGNATURE", "簽章驗證失敗")
        payload = strict_json_loads(payload_bytes, "payload")
        validate_payload(payload, repository=args.image_repository)
    except Reject as exc:
        _emit({"result": "REJECT", "code": exc.code, "message": exc.message})
        return EXIT_REJECT
    value: Any = payload
    for part in args.field.split("."):
        if not isinstance(value, dict) or part not in value:
            sys.stderr.write("欄位不存在\n")
            return EXIT_USAGE
        value = value[part]
    sys.stdout.write((json.dumps(value, ensure_ascii=False, sort_keys=True) if isinstance(value, (dict, list)) else ("" if value is None else str(value))) + "\n")
    return 0


def cmd_validate_payload(args: argparse.Namespace) -> int:
    try:
        with open(args.payload, "rb") as fh:
            data = fh.read(MAX_PAYLOAD_BYTES + 1)
        if len(data) > MAX_PAYLOAD_BYTES:
            raise Reject("PAYLOAD_TOO_LARGE", "payload 超過上限")
        validate_payload(strict_json_loads(data, "payload"), repository=args.image_repository, mysql_major=args.mysql_major)
    except Reject as exc:
        _emit({"result": "REJECT", "code": exc.code, "message": exc.message})
        return EXIT_REJECT
    _emit({"result": "VALID", "code": "OK", "message": "payload schema 通過"})
    return 0


def cmd_validate_images(args: argparse.Namespace) -> int:
    """檢查 images JSON（CI 產生候選版時用）：只允許五個固定服務、核准 registry／名稱、@sha256 digest。"""
    try:
        with open(args.images_json, "rb") as fh:
            images = strict_json_loads(fh.read(65536), "images")
        if not isinstance(images, dict) or sorted(images) != sorted(IMAGE_NAMES):
            raise Reject("UNKNOWN_SERVICE", "images 必須恰好包含：" + ", ".join(IMAGE_NAMES))
        for name in IMAGE_NAMES:
            validate_image_ref(name, images[name], args.image_repository)
    except Reject as exc:
        _emit({"result": "REJECT", "code": exc.code, "message": exc.message})
        return EXIT_REJECT
    _emit({"result": "VALID", "code": "OK", "message": "images 通過"})
    return 0


def cmd_make_payload(args: argparse.Namespace) -> int:
    with open(args.images_json, "rb") as fh:
        images = strict_json_loads(fh.read(65536), "images")
    created = parse_time(args.created_at, "created_at") if args.created_at else int(time.time())
    validity = int(args.validity_seconds)
    payload = {
        "schema_version": SCHEMA_VERSION,
        "project": PROJECT,
        "environment": args.environment,
        "channel": args.channel,
        "sequence": int(args.sequence),
        "release_id": args.release_id,
        "source_commit": args.source_commit,
        "created_at": fmt_time(created),
        "expires_at": fmt_time(created + validity),
        "platform_contract": int(args.platform_contract),
        "images": images,
        "infrastructure": {"mysql_major": args.mysql_major},
        "schema_change_class": args.schema_change_class,
        "migration_plan_ref": args.migration_plan_ref or None,
        "verification_run_id": args.verification_run_id,
        "previous_verified_release": args.previous_verified_release or None,
        "rollback_target_release": args.rollback_target_release or None,
    }
    try:
        validate_payload(payload, repository=args.image_repository)
    except Reject as exc:
        _emit({"result": "REJECT", "code": exc.code, "message": exc.message})
        return EXIT_REJECT
    _write_private(args.out, canonical_json(payload))
    return 0


def cmd_sign(args: argparse.Namespace) -> int:
    if not RE_KEY_ID.match(args.key_id):
        sys.stderr.write("key_id 格式不合法\n")
        return EXIT_USAGE
    with open(args.payload, "rb") as fh:
        payload_bytes = fh.read(MAX_PAYLOAD_BYTES + 1)
    if len(payload_bytes) > MAX_PAYLOAD_BYTES:
        sys.stderr.write("payload 超過上限\n")
        return EXIT_REJECT
    if not args.skip_schema:
        try:
            validate_payload(strict_json_loads(payload_bytes, "payload"), repository=args.image_repository)
        except Reject as exc:
            _emit({"result": "REJECT", "code": exc.code, "message": exc.message})
            return EXIT_REJECT
    assert_ed25519_private_key(args.private_key)
    sig = openssl_sign(args.private_key, payload_bytes)
    with tempfile.TemporaryDirectory(prefix="mysite-selfcheck-") as tmp:
        pub = os.path.join(tmp, "pub.pem")
        derive_public_key(args.private_key, pub)
        if not openssl_verify(pub, payload_bytes, sig):
            raise EnvError("簽章自我驗證失敗")
        if args.expect_public_key:
            with open(pub, "rb") as a, open(args.expect_public_key, "rb") as b:
                if a.read().strip() != b.read().strip():
                    sys.stderr.write("簽章私鑰與預期公鑰不相符（環境金鑰設定錯誤）\n")
                    return EXIT_REJECT
    envelope = {
        "key_id": args.key_id,
        "payload_base64": base64.b64encode(payload_bytes).decode("ascii"),
        "signature_algorithm": SIGNATURE_ALGORITHM,
        "signature_base64": base64.b64encode(sig).decode("ascii"),
    }
    _write_private(args.out, (json.dumps(envelope, sort_keys=True, indent=2) + "\n").encode("utf-8"))
    return 0


# ---------------------------------------------------------------------------
# 狀態變更（只由持有部署鎖的 release.sh／rollback.sh 呼叫）
# ---------------------------------------------------------------------------
def _load_payload_file(path: str) -> tuple[dict[str, Any], str]:
    with open(path, "rb") as fh:
        data = fh.read(MAX_PAYLOAD_BYTES + 1)
    return strict_json_loads(data, "payload"), hashlib.sha256(data).hexdigest()


def cmd_state_begin(args: argparse.Namespace) -> int:
    payload, sha = _load_payload_file(args.payload)
    st = load_state(args.state_dir, args.environment)
    seq = payload["sequence"]
    pending = st.get("pending") or None
    attempt = 1
    if pending and pending.get("sequence") == seq and pending.get("payload_sha256") == sha:
        attempt = int(pending.get("attempt", 0)) + 1
    elif seq <= st.get("highest_accepted_sequence", 0):
        _emit({"result": "REJECT", "code": "REPLAY_OLD_SEQUENCE", "message": "序號未大於已接受序號"})
        return EXIT_REJECT
    if attempt > args.max_attempts:
        _emit({"result": "REJECT", "code": "RETRY_LIMIT_EXCEEDED", "message": f"同一清單已嘗試 {attempt - 1} 次，達上限 {args.max_attempts}；需要新序號清單"})
        return EXIT_RETRY_LIMIT
    st["environment"] = args.environment
    st["highest_accepted_sequence"] = max(int(st.get("highest_accepted_sequence", 0)), seq)
    st["pending"] = {
        "sequence": seq,
        "release_id": payload["release_id"],
        "payload_sha256": sha,
        "kind": args.kind,
        "attempt": attempt,
        "status": "in_progress",
        "step": "begin",
        "started_at": fmt_time(int(time.time())),
        "images": payload["images"],
        "schema_change_class": payload["schema_change_class"],
        "source_commit": payload["source_commit"],
        "rollback_target_release": payload["rollback_target_release"],
        "last_failure": None,
    }
    save_state(args.state_dir, st)
    _emit({"result": "BEGIN", "attempt": attempt, "sequence": seq})
    return 0


def cmd_state_step(args: argparse.Namespace) -> int:
    st = load_state(args.state_dir, args.environment)
    if not st.get("pending"):
        sys.stderr.write("沒有進行中的發布\n")
        return EXIT_USAGE
    st["pending"]["step"] = args.step
    if args.status:
        st["pending"]["status"] = args.status
    if args.reason:
        st["pending"]["last_failure"] = {"code": args.reason, "at": fmt_time(int(time.time())), "step": args.step}
    save_state(args.state_dir, st)
    return 0


def cmd_state_activate(args: argparse.Namespace) -> int:
    st = load_state(args.state_dir, args.environment)
    pending = st.get("pending")
    if not pending:
        sys.stderr.write("沒有進行中的發布\n")
        return EXIT_USAGE
    now = fmt_time(int(time.time()))
    previous = st.get("active")
    active = {
        "sequence": pending["sequence"],
        "release_id": pending["release_id"],
        "payload_sha256": pending["payload_sha256"],
        "images": pending["images"],
        "schema_change_class": pending["schema_change_class"],
        "source_commit": pending["source_commit"],
        "kind": pending.get("kind", "release"),
        "rollback_target_release": pending.get("rollback_target_release"),
        "activated_at": now,
        "confirmation": args.confirmation,
        "previous_release_id": previous.get("release_id") if previous else None,
    }
    st["active"] = active
    st["pending"] = None
    save_state(args.state_dir, st)
    append_history(args.state_dir, active)
    return 0


def cmd_state_get(args: argparse.Namespace) -> int:
    st = load_state(args.state_dir, args.environment)
    value: Any = st
    for part in args.field.split("."):
        if not isinstance(value, dict) or part not in value or value[part] is None:
            sys.stdout.write("\n")
            return 0
        value = value[part]
    sys.stdout.write((json.dumps(value, ensure_ascii=False, sort_keys=True) if isinstance(value, (dict, list)) else str(value)) + "\n")
    return 0


def cmd_rollback_check(args: argparse.Namespace) -> int:
    """回復安全檢查：目標必須是本主機曾成功啟用的版本、映像完全相同，且之後只有 none／expand 變更。"""
    payload, _sha = _load_payload_file(args.payload)
    target = payload.get("rollback_target_release")
    try:
        if not target:
            raise Reject("NOT_A_ROLLBACK", "清單沒有 rollback_target_release")
        history = read_history(args.state_dir)
        idx = None
        for i, entry in enumerate(history):
            if entry.get("release_id") == target or (entry.get("kind") == "rollback" and entry.get("rollback_target_release") == target):
                idx = i
        if idx is None:
            raise Reject("ROLLBACK_TARGET_UNKNOWN", "回復目標不在本主機已成功啟用的歷史中")
        if history[idx].get("images") != payload["images"]:
            raise Reject("ROLLBACK_IMAGES_MISMATCH", "回復清單的映像 digest 與目標版本紀錄不一致")
        for later in history[idx + 1:]:
            cls = later.get("schema_change_class")
            if cls not in AUTO_SCHEMA_CLASSES:
                raise Reject(
                    "ROLLBACK_SCHEMA_INCOMPATIBLE",
                    f"目標之後的 {later.get('release_id')} 含 {cls} schema 變更；舊程式不保證相容，須維護模式＋核准方案",
                )
        st = load_state(args.state_dir, args.environment)
        if st.get("active") and st["active"].get("images") == payload["images"]:
            raise Reject("ROLLBACK_NOOP", "目標映像即為現行版本")
    except Reject as exc:
        _emit({"result": "REJECT", "code": exc.code, "message": exc.message})
        return EXIT_REJECT
    _emit({"result": "ROLLBACK_OK", "target": target})
    return 0


def build_parser() -> argparse.ArgumentParser:
    ap = argparse.ArgumentParser(prog="manifest.py", description="mysite 發布清單工具（簽章／驗證／狀態）")
    sub = ap.add_subparsers(dest="cmd", required=True)

    v = sub.add_parser("verify", help="驗簽＋嚴格 schema＋環境＋序號＋時間")
    v.add_argument("--envelope", required=True)
    v.add_argument("--trust-dir", required=True)
    v.add_argument("--environment", required=True, choices=ENVIRONMENTS)
    v.add_argument("--channel", required=True)
    v.add_argument("--state-dir")
    v.add_argument("--image-repository", default=DEFAULT_IMAGE_REPOSITORY)
    v.add_argument("--mysql-major")
    v.add_argument("--revoked-digests")
    v.add_argument("--now", type=int)
    v.add_argument("--payload-out")
    v.add_argument("--env-out")
    v.add_argument("--no-state", action="store_true", help="不比對主機序號（只供 CI 自我檢查）")
    v.add_argument("--no-time", action="store_true", help="不檢查有效期（只供 CI 自我檢查）")
    v.set_defaults(func=cmd_verify)

    r = sub.add_parser("read-verified", help="以公鑰驗簽後讀取 payload 欄位")
    r.add_argument("--envelope", required=True)
    r.add_argument("--public-key", required=True)
    r.add_argument("--key-id")
    r.add_argument("--field", required=True)
    r.add_argument("--image-repository", default=DEFAULT_IMAGE_REPOSITORY)
    r.set_defaults(func=cmd_read_verified)

    vp = sub.add_parser("validate-payload", help="只檢查 payload schema")
    vp.add_argument("--payload", required=True)
    vp.add_argument("--image-repository", default=DEFAULT_IMAGE_REPOSITORY)
    vp.add_argument("--mysql-major")
    vp.set_defaults(func=cmd_validate_payload)

    vi = sub.add_parser("validate-images", help="只檢查 images JSON")
    vi.add_argument("--images-json", required=True)
    vi.add_argument("--image-repository", default=DEFAULT_IMAGE_REPOSITORY)
    vi.set_defaults(func=cmd_validate_images)

    m = sub.add_parser("make-payload", help="產生 payload（UTF-8 JSON）")
    m.add_argument("--environment", required=True, choices=ENVIRONMENTS)
    m.add_argument("--channel", required=True)
    m.add_argument("--sequence", required=True, type=int)
    m.add_argument("--release-id", required=True)
    m.add_argument("--source-commit", required=True)
    m.add_argument("--images-json", required=True)
    m.add_argument("--mysql-major", required=True)
    m.add_argument("--schema-change-class", required=True, choices=SCHEMA_CHANGE_CLASSES)
    m.add_argument("--migration-plan-ref", default="")
    m.add_argument("--verification-run-id", required=True)
    m.add_argument("--previous-verified-release", default="")
    m.add_argument("--rollback-target-release", default="")
    m.add_argument("--created-at", default="")
    m.add_argument("--validity-seconds", default=str(MAX_VALIDITY_SECONDS))
    m.add_argument("--platform-contract", default="1")
    m.add_argument("--image-repository", default=DEFAULT_IMAGE_REPOSITORY)
    m.add_argument("--out", required=True)
    m.set_defaults(func=cmd_make_payload)

    s = sub.add_parser("sign", help="以 Ed25519 私鑰簽署原始 payload bytes 並輸出 envelope")
    s.add_argument("--payload", required=True)
    s.add_argument("--private-key", required=True)
    s.add_argument("--key-id", required=True)
    s.add_argument("--out", required=True)
    s.add_argument("--expect-public-key")
    s.add_argument("--image-repository", default=DEFAULT_IMAGE_REPOSITORY)
    s.add_argument("--skip-schema", action="store_true", help="只供測試產生不合法 payload 的簽章")
    s.set_defaults(func=cmd_sign)

    for name, func in (("state-begin", cmd_state_begin), ("state-step", cmd_state_step), ("state-activate", cmd_state_activate), ("state-get", cmd_state_get), ("rollback-check", cmd_rollback_check)):
        p = sub.add_parser(name)
        p.add_argument("--state-dir", required=True)
        p.add_argument("--environment", required=True, choices=ENVIRONMENTS)
        if name in ("state-begin", "rollback-check"):
            p.add_argument("--payload", required=True)
        if name == "state-begin":
            p.add_argument("--max-attempts", type=int, default=DEFAULT_MAX_ATTEMPTS)
            p.add_argument("--kind", choices=("release", "rollback"), default="release")
        if name == "state-step":
            p.add_argument("--step", required=True)
            p.add_argument("--status", choices=("in_progress", "failed", "awaiting_approval", "unconfirmed"))
            p.add_argument("--reason")
        if name == "state-activate":
            p.add_argument("--confirmation", choices=("confirmed", "unconfirmed"), default="confirmed")
        if name == "state-get":
            p.add_argument("--field", required=True)
        p.set_defaults(func=func)
    return ap


def main(argv: list[str] | None = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)
    try:
        return int(args.func(args))
    except EnvError as exc:
        sys.stderr.write(f"manifest.py：{exc}\n")
        return EXIT_ENV
    except Reject as exc:
        _emit({"result": "REJECT", "code": exc.code, "message": exc.message})
        return EXIT_REJECT
    except subprocess.TimeoutExpired:
        sys.stderr.write("manifest.py：openssl 逾時\n")
        return EXIT_ENV


if __name__ == "__main__":
    sys.exit(main())
