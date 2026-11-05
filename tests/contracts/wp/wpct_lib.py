"""WordPress 契約測試共用函式庫（只用 Python 3 標準函式庫）。

- 目標環境由 MYSITE_ENV（development 預設／testing）決定；WP-CLI 一律經 scripts/cloud/dev.sh。
- CMS／主站基底網址讀 MYSITE_CMS_BASE／MYSITE_APP_BASE，不寫死埠號。
- 秘密只從 .runtime/secrets/<env>/ 以檔案路徑讀入記憶體使用，絕不輸出；所有訊息經 redact()。
"""

from __future__ import annotations

import base64
import hashlib
import hmac
import http.client
import json
import os
import re
import shlex
import struct
import subprocess
import time
import urllib.parse
import zlib
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Callable

ROOT = Path(__file__).resolve().parents[3]
ENV = os.environ.get("MYSITE_ENV", "development")
CMS_BASE = os.environ.get("MYSITE_CMS_BASE", "http://127.0.0.1:8081").rstrip("/")
APP_BASE = os.environ.get("MYSITE_APP_BASE", "http://127.0.0.1:8080").rstrip("/")
SECRETS_DIR = ROOT / ".runtime" / "secrets" / ENV
RESULTS_DIR = Path(os.environ.get("MYSITE_RESULTS_DIR", str(ROOT / ".runtime" / "results")))
RESULTS_FILE = RESULTS_DIR / "wp-contract.jsonl"
CLEANUP_FILE = RESULTS_DIR / "wp-contract-pending-cleanup.json"
NS = "/wp-json/site-content/v1"
UTC_RE = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$")
UUID4_RE = re.compile(r"^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$")
HEX32_RE = re.compile(r"^[0-9a-f]{32}$")
RECEIVER_PORT = 18765
RECEIVER_DIR = "/tmp/sc-contract-receiver"
RECEIVER_ENDPOINT = f"http://127.0.0.1:{RECEIVER_PORT}/internal/content-events/v1"

_SECRET_VALUES: list[str] = []


class TestFailure(AssertionError):
    """測試斷言失敗。"""


def now_utc() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def check(cond: bool, message: str) -> None:
    if not cond:
        raise TestFailure(message)


def secret(name: str) -> str:
    """讀取秘密檔（只在記憶體使用；值會加入遮罩清單）。"""
    path = SECRETS_DIR / name
    value = path.read_text(encoding="utf-8").rstrip("\r\n")
    if value and value not in _SECRET_VALUES:
        _SECRET_VALUES.append(value)
    return value


def keyring(name: str) -> dict[str, tuple[str, str]] | None:
    """讀取金鑰圈檔（name：content_event_hmac 或 preview_signing）；檔案不存在回傳 None（沿用單一金鑰）。

    回傳 key_id → (金鑰, active|accept)；金鑰只在記憶體使用並加入遮罩清單（GAP-KEYRING-01）。
    """
    path = SECRETS_DIR / f"{name}_keyring"
    if not path.exists():
        return None
    ring: dict[str, tuple[str, str]] = {}
    for line in path.read_text(encoding="utf-8").splitlines():
        parts = line.split()
        if not parts:
            continue
        check(len(parts) == 3, f"{path.name} 格式不合法")
        kid, key, state = parts
        if key not in _SECRET_VALUES:
            _SECRET_VALUES.append(key)
        ring[kid] = (key, state)
    return ring


def signing_key(name: str, kid: str | None = None) -> tuple[str, str]:
    """回傳 (key_id, 金鑰)：指定 kid 時取該把（active 或 accept），否則取 active。

    沒有金鑰圈時以單一金鑰檔 `<name>_key` 視為 k1 active（事件的 key ID 實際由 CONTENT_EVENT_KEY_ID 決定，預設 k1）。
    """
    ring = keyring(name)
    if ring is None:
        return (kid or "k1", secret(f"{name}_key"))
    if kid is not None:
        check(kid in ring, f"金鑰圈沒有 key ID {kid}")
        return (kid, ring[kid][0])
    active = [(k, v[0]) for k, v in ring.items() if v[1] == "active"]
    check(len(active) == 1, "金鑰圈必須恰好一把 active")
    return active[0]


def redact(text: str) -> str:
    out = str(text)
    for value in _SECRET_VALUES:
        if len(value) >= 6:
            out = out.replace(value, "***")
    out = re.sub(r"[A-Za-z0-9_-]{16,}\.[A-Za-z0-9_-]{16,}\.[A-Za-z0-9_-]{16,}", "<ticket>", out)
    out = re.sub(r"(?i)(authorization|cookie|x-wp-nonce|_wpnonce)(\s*[=:]\s*)[^\s&,;]+", r"\1\2***", out)
    out = re.sub(r"wordpress_(logged_in|sec)_[0-9a-f]+=[^;\s]+", "wordpress_\\1_***=***", out)
    return out[:2000]


# ---------------------------------------------------------------------------
# 命令執行
# ---------------------------------------------------------------------------

@dataclass
class CmdResult:
    code: int
    stdout: str
    stderr: str


def _run(args: list[str], stdin: bytes | None = None, timeout: int = 180) -> CmdResult:
    env = dict(os.environ)
    env["MYSITE_ENV"] = ENV
    proc = subprocess.run(args, input=stdin, capture_output=True, timeout=timeout, cwd=str(ROOT), env=env)
    return CmdResult(proc.returncode, proc.stdout.decode("utf-8", "replace"), proc.stderr.decode("utf-8", "replace"))


def _clean_stderr(text: str) -> str:
    lines = [l for l in text.splitlines() if "Deprecated" not in l and "wp_update_plugins" not in l and l.strip()]
    return "\n".join(lines[-5:])


_NOT_RUNNING = ("is not running", "is restarting", "No such container", "no such service", "container not found")


def dev(*args: str, stdin: bytes | None = None, timeout: int = 180) -> CmdResult:
    """經 scripts/cloud/dev.sh 執行；容器重啟中（整合者重建服務）時最多等待 3 分鐘再重試。"""
    deadline = time.time() + 180
    while True:
        res = _run(["bash", "scripts/cloud/dev.sh", *args], stdin=stdin, timeout=timeout)
        if res.code == 0 or not any(marker in res.stderr for marker in _NOT_RUNNING) or time.time() > deadline:
            return res
        time.sleep(5)


def wp(*args: str, check_ok: bool = True, stdin: bytes | None = None, extra_env: dict[str, str] | None = None) -> CmdResult:
    """在 wp-php 以 www-data 執行 WP-CLI（經 scripts/cloud/dev.sh）。"""
    if extra_env:
        env_args: list[str] = []
        for k, v in extra_env.items():
            env_args += ["-e", f"{k}={v}"]
        res = dev("compose", "exec", "-T", "-u", "www-data", *env_args, "wp-php", "wp", *args, stdin=stdin)
    else:
        res = dev("wp", *args, stdin=stdin)
    if check_ok and res.code != 0:
        raise TestFailure(redact(f"WP-CLI 失敗（{' '.join(args[:3])} … exit {res.code}）：{_clean_stderr(res.stderr) or res.stdout[-300:]}"))
    return res


def wp_json(*args: str) -> Any:
    res = wp(*args)
    try:
        return json.loads(res.stdout.strip() or "null")
    except json.JSONDecodeError as exc:
        raise TestFailure(redact(f"WP-CLI 輸出不是 JSON：{res.stdout[:300]}")) from exc


def wp_eval(code: str) -> str:
    """執行 PHP（經 STDIN 的 eval-file，避免引號問題）；回傳 stdout。"""
    res = wp("eval-file", "-", stdin=("<?php\n" + code).encode("utf-8"))
    return res.stdout


def wp_eval_json(code: str) -> Any:
    out = wp_eval(code).strip()
    try:
        return json.loads(out)
    except json.JSONDecodeError as exc:
        raise TestFailure(redact(f"eval 輸出不是 JSON：{out[:300]}")) from exc


def compose_exec(service: str, script: str, stdin: bytes | None = None, user: str = "www-data") -> CmdResult:
    return dev("compose", "exec", "-T", "-u", user, service, "sh", "-c", script, stdin=stdin)


# ---------------------------------------------------------------------------
# HTTP（經 gateway；自管 cookie，不自動跟隨 redirect）
# ---------------------------------------------------------------------------

@dataclass
class Response:
    status: int
    headers: dict[str, list[str]]
    body: bytes

    def header(self, name: str) -> str:
        values = self.headers.get(name.lower(), [])
        return values[-1] if values else ""

    def header_all(self, name: str) -> list[str]:
        return [v.strip() for v in self.headers.get(name.lower(), [])]

    @property
    def text(self) -> str:
        return self.body.decode("utf-8", "replace")

    def json(self) -> Any:
        try:
            return json.loads(self.body.decode("utf-8"))
        except (UnicodeDecodeError, json.JSONDecodeError) as exc:
            raise TestFailure(f"回應不是 JSON（HTTP {self.status}）：{redact(self.text[:200])}") from exc


def http_request(base: str, method: str, path: str, headers: dict[str, str] | None = None,
                 body: bytes | None = None, host_header: str | None = None, timeout: int = 60) -> Response:
    """單次 HTTP 請求；gateway 回 502／504 或連線失敗（服務重建中）時最多重試 2 分鐘。"""
    deadline = time.time() + 120
    while True:
        try:
            resp = _http_once(base, method, path, headers, body, host_header, timeout)
        except (ConnectionError, http.client.HTTPException, OSError):
            if time.time() > deadline:
                raise
            time.sleep(3)
            continue
        if resp.status in (502, 504) and b"nginx" in resp.body and time.time() <= deadline:
            time.sleep(3)
            continue
        return resp


def _http_once(base: str, method: str, path: str, headers: dict[str, str] | None,
               body: bytes | None, host_header: str | None, timeout: int) -> Response:
    parsed = urllib.parse.urlsplit(base)
    conn_cls = http.client.HTTPSConnection if parsed.scheme == "https" else http.client.HTTPConnection
    conn = conn_cls(parsed.hostname, parsed.port or (443 if parsed.scheme == "https" else 80), timeout=timeout)
    hdrs = {"Accept": "application/json, text/html;q=0.9, */*;q=0.1", "User-Agent": "mysite-wp-contract/1"}
    if host_header:
        hdrs["Host"] = host_header
    hdrs.update(headers or {})
    try:
        conn.request(method, path, body=body, headers=hdrs)
        resp = conn.getresponse()
        data = resp.read()
        out: dict[str, list[str]] = {}
        for k, v in resp.getheaders():
            out.setdefault(k.lower(), []).append(v)
        return Response(resp.status, out, data)
    finally:
        conn.close()


_CMS_HOST: str | None = None


def cms_host() -> str:
    """CMS 的公開 Host（取自 WordPress home 設定，含埠號），讓 gateway 與 WP 視為真實瀏覽器請求。"""
    global _CMS_HOST
    if _CMS_HOST is None:
        home = wp("option", "get", "home").stdout.strip()
        _CMS_HOST = urllib.parse.urlsplit(home).netloc
    return _CMS_HOST


def app_url() -> str:
    """WordPress 端設定的主站網址（MYSITE_APP_URL）。"""
    return wp_eval("echo defined('MYSITE_APP_URL') ? MYSITE_APP_URL : '';").strip()


def public_get(path: str, params: dict[str, Any] | None = None) -> Response:
    query = ("?" + urllib.parse.urlencode(params, doseq=True)) if params else ""
    return http_request(CMS_BASE, "GET", NS + path + query, host_header=cms_host())


class CmsSession:
    """以 cookie＋nonce 操作 WordPress 後臺（經 gateway，與真實瀏覽器相同路徑）。"""

    def __init__(self) -> None:
        self.cookies: dict[str, str] = {}
        self.nonce: str | None = None
        self.user_id: int | None = None

    def _cookie_header(self) -> str:
        return "; ".join(f"{k}={v}" for k, v in self.cookies.items())

    def _store(self, resp: Response) -> None:
        for raw in resp.headers.get("set-cookie", []):
            first = raw.split(";", 1)[0]
            if "=" not in first:
                continue
            name, value = first.split("=", 1)
            lower = raw.lower()
            if value in ("", "deleted") or "max-age=0" in lower or "expires=thu, 01-jan-1970" in lower or re.search(r"expires=[^;]*19[0-9]{2}", lower):
                self.cookies.pop(name.strip(), None)
            else:
                self.cookies[name.strip()] = value.strip()

    def request(self, method: str, path: str, headers: dict[str, str] | None = None, body: bytes | None = None,
                use_nonce: bool = False, timeout: int = 120) -> Response:
        hdrs = dict(headers or {})
        if self.cookies:
            hdrs["Cookie"] = self._cookie_header()
        if use_nonce and self.nonce:
            hdrs["X-WP-Nonce"] = self.nonce
        resp = http_request(CMS_BASE, method, path, headers=hdrs, body=body, host_header=cms_host(), timeout=timeout)
        self._store(resp)
        return resp

    def login(self, username: str, password: str) -> None:
        self.cookies["wordpress_test_cookie"] = "WP%20Cookie%20check"
        form = urllib.parse.urlencode({
            "log": username, "pwd": password, "wp-submit": "登入", "testcookie": "1",
            "redirect_to": f"http://{cms_host()}/wp-admin/",
        }).encode()
        resp = self.request("POST", "/wp-login.php", {"Content-Type": "application/x-www-form-urlencoded"}, form)
        logged = any(k.startswith("wordpress_logged_in_") for k in self.cookies)
        check(resp.status in (302, 303) and logged, f"登入失敗（HTTP {resp.status}）：{username}")
        nonce = self.request("GET", "/wp-admin/admin-ajax.php?action=rest-nonce")
        check(nonce.status == 200 and re.fullmatch(r"[0-9a-f]{10}", nonce.text.strip() or "") is not None,
              f"取得 REST nonce 失敗（HTTP {nonce.status}）")
        self.nonce = nonce.text.strip()
        me = self.rest("GET", "/wp/v2/users/me")
        check(me.status == 200, f"/wp/v2/users/me 失敗（HTTP {me.status}）")
        self.user_id = int(me.json()["id"])

    def rest(self, method: str, route: str, payload: Any = None, params: dict[str, Any] | None = None,
             use_nonce: bool = True, raw_body: bytes | None = None, headers: dict[str, str] | None = None) -> Response:
        query = ("?" + urllib.parse.urlencode(params, doseq=True)) if params else ""
        hdrs = dict(headers or {})
        body = raw_body
        if payload is not None:
            body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
            hdrs["Content-Type"] = "application/json; charset=utf-8"
        return self.request(method, "/wp-json" + route + query, hdrs, body, use_nonce=use_nonce)

    def upload(self, filename: str, data: bytes, content_type: str) -> Response:
        return self.rest("POST", "/wp/v2/media", raw_body=data, headers={
            "Content-Type": content_type,
            "Content-Disposition": f'attachment; filename="{filename}"',
        })


# ---------------------------------------------------------------------------
# 服務帳號（容器內 HTTPS，經本案 CA 驗證；密碼只在容器內由 /run/secrets 讀取）
# ---------------------------------------------------------------------------

@dataclass
class InternalResponse:
    status: int
    headers: dict[str, str]
    body: str

    def json(self) -> Any:
        try:
            return json.loads(self.body)
        except json.JSONDecodeError as exc:
            raise TestFailure(f"回應不是 JSON（HTTP {self.status}）：{redact(self.body[:200])}") from exc


def internal_get(url: str, auth: str = "service", ticket: str | None = None, password_override: str | None = None) -> InternalResponse:
    """在 app-php 容器以 curl 讀取 wp-web（https 會以本案 CA 驗證）。

    auth: service（Application Password）、none、bad（錯誤密碼）。
    ticket 以 stdin 傳入容器，不出現在命令列參數。
    """
    auth_line = {
        "service": """printf 'user = "mysite-service:%s"\\n' "$(tr -d '\\r\\n ' < /run/secrets/wp_service_app_password)" >> "$d/cfg"; """,
        "bad": """printf 'user = "mysite-service:wrong-password-for-contract-test"\\n' >> "$d/cfg"; """,
    }.get(auth, "")
    script = (
        'set -e; t=$(cat); d=$(mktemp -d); trap \'rm -rf "$d"\' EXIT; : > "$d/cfg"; '
        + auth_line
        + 'if [ -n "$t" ]; then printf \'header = "X-Mysite-Preview-Ticket: %s"\\n\' "$t" >> "$d/cfg"; fi; '
        + 'code=$(curl -sS --max-time 15 --cacert /run/secrets/internal_ca_cert -K "$d/cfg" -D "$d/h" -o "$d/b" -w "%{http_code}" '
        + shlex.quote(url) + ' || true); '
        + 'echo "STATUS:$code"; echo HEADERS-BEGIN; cat "$d/h" 2>/dev/null || true; echo HEADERS-END; cat "$d/b" 2>/dev/null || true'
    )
    res = compose_exec("app-php", script, stdin=(ticket or "").encode())
    out = res.stdout
    m = re.search(r"STATUS:(\d+)", out)
    status = int(m.group(1)) if m else 0
    headers: dict[str, str] = {}
    if "HEADERS-BEGIN" in out and "HEADERS-END" in out:
        block = out.split("HEADERS-BEGIN", 1)[1].split("HEADERS-END", 1)[0]
        for line in block.splitlines():
            if ":" in line:
                k, v = line.split(":", 1)
                key = k.strip().lower()
                # 重複標頭以「, 」串接（例如 Nginx 與 PHP 各送一次）。
                headers[key] = (headers[key] + ", " + v.strip()) if key in headers else v.strip()
    body = out.split("HEADERS-END", 1)[1].lstrip("\r\n") if "HEADERS-END" in out else ""
    return InternalResponse(status, headers, body)


# ---------------------------------------------------------------------------
# 票券與簽章（以秘密檔驗證／偽造測試票券；不輸出金鑰）
# ---------------------------------------------------------------------------

def b64u(raw: bytes) -> str:
    return base64.urlsafe_b64encode(raw).rstrip(b"=").decode()


def b64u_decode(text: str) -> bytes:
    return base64.urlsafe_b64decode(text + "=" * (-len(text) % 4))


def ticket_parts(ticket: str) -> tuple[dict, dict, bytes]:
    h, c, s = ticket.split(".")
    return json.loads(b64u_decode(h)), json.loads(b64u_decode(c)), b64u_decode(s)


def ticket_valid_signature(ticket: str) -> bool:
    """依票券 header 的 kid（沒有 kid 時用 active key）以秘密檔驗證 HMAC。"""
    h, c, s = ticket.split(".")
    kid = json.loads(b64u_decode(h)).get("kid")
    ring = keyring("preview_signing")
    if ring is not None and kid is not None and kid not in ring:
        return False
    _, key = signing_key("preview_signing", kid if ring is not None else None)
    expected = hmac.new(key.encode(), f"{h}.{c}".encode(), hashlib.sha256).digest()
    return hmac.compare_digest(expected, b64u_decode(s))


def forge_ticket(claims: dict, header: dict | None = None, key: str | None = None) -> str:
    """以 active 預覽金鑰（或指定金鑰）偽造測試票券；預設 header 不帶 kid（舊格式，接收端以 active key 驗證）。"""
    head = header or {"alg": "HS256", "typ": "mysite-preview"}
    h = b64u(json.dumps(head, separators=(",", ":")).encode())
    c = b64u(json.dumps(claims, separators=(",", ":")).encode())
    k = (key if key is not None else signing_key("preview_signing")[1]).encode()
    return f"{h}.{c}.{b64u(hmac.new(k, f'{h}.{c}'.encode(), hashlib.sha256).digest())}"


def event_signature(timestamp: str, event_id: str, body: bytes, key_id: str | None = None) -> str:
    """以事件金鑰圈的 active key（或指定 key ID）計算 HMAC；沒有金鑰圈時用單一金鑰檔。"""
    key = signing_key("content_event_hmac", key_id if keyring("content_event_hmac") is not None else None)[1].encode()
    return hmac.new(key, timestamp.encode() + b"\n" + event_id.encode() + b"\n" + body, hashlib.sha256).hexdigest()


# ---------------------------------------------------------------------------
# 測試素材（標準函式庫產生）
# ---------------------------------------------------------------------------

def make_png(width: int = 8, height: int = 8, rgb: tuple[int, int, int] = (40, 90, 160)) -> bytes:
    raw = b"".join(b"\x00" + bytes(rgb) * width for _ in range(height))

    def chunk(kind: bytes, data: bytes) -> bytes:
        return struct.pack(">I", len(data)) + kind + data + struct.pack(">I", zlib.crc32(kind + data) & 0xFFFFFFFF)

    return b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", struct.pack(">IIBBBBB", width, height, 8, 2, 0, 0, 0)) + \
        chunk(b"IDAT", zlib.compress(raw)) + chunk(b"IEND", b"")


def make_wav(seconds: float = 1.0, rate: int = 8000) -> bytes:
    import math
    n = int(seconds * rate)
    data = b"".join(struct.pack("<h", int(8000 * math.sin(2 * math.pi * 440 * i / rate))) for i in range(n))
    return b"RIFF" + struct.pack("<I", 36 + len(data)) + b"WAVE" + b"fmt " + struct.pack("<IHHIIHH", 16, 1, 1, rate, rate * 2, 2, 16) + \
        b"data" + struct.pack("<I", len(data)) + data


# ---------------------------------------------------------------------------
# 測試框架：結果寫入 JSONL、清理登記
# ---------------------------------------------------------------------------

@dataclass
class TestCase:
    name: str
    ids: list[str]
    func: Callable[["Context"], str | None]
    group: str


@dataclass
class Context:
    """測試共用狀態與清理登記（只清本次測試建立的資料）。"""
    created_posts: list[int] = field(default_factory=list)
    created_terms: list[tuple[str, int]] = field(default_factory=list)
    caps_added: list[tuple[str, str]] = field(default_factory=list)
    options_set: list[str] = field(default_factory=list)
    editor: CmsSession | None = None
    admin: CmsSession | None = None
    receiver_started: bool = False
    cache: dict[str, Any] = field(default_factory=dict)

    def editor_session(self) -> CmsSession:
        if self.editor is None:
            s = CmsSession()
            s.login("mysite-editor", secret("wp_editor_password"))
            self.editor = s
        return self.editor

    def admin_session(self) -> CmsSession:
        if self.admin is None:
            s = CmsSession()
            s.login("mysite-admin", secret("wp_admin_password"))
            self.admin = s
        return self.admin

    created_users: list[int] = field(default_factory=list)

    def track_post(self, post_id: int) -> int:
        self.created_posts.append(int(post_id))
        self.persist()
        return int(post_id)

    def persist(self) -> None:
        """把待清理清單寫到結果目錄；中斷的執行可在下次開始時補清。"""
        data = {"posts": self.created_posts, "caps": self.caps_added, "options": self.options_set}
        try:
            RESULTS_DIR.mkdir(parents=True, exist_ok=True)
            CLEANUP_FILE.write_text(json.dumps(data), encoding="utf-8")
        except OSError:
            pass


REGISTRY: list[TestCase] = []


def contract(name: str, ids: list[str], group: str) -> Callable:
    def deco(func: Callable[[Context], str | None]) -> Callable[[Context], str | None]:
        REGISTRY.append(TestCase(name, ids, func, group))
        return func
    return deco


def wp_create_post(ctx: Context, *args: str) -> int:
    out = wp("post", "create", "--porcelain", *args).stdout.strip()
    check(out.isdigit(), f"建立內容失敗：{out[:100]}")
    return ctx.track_post(int(out))


def outbox_rows(content_id: int | None = None, event: str | None = None, limit: int = 100) -> list[dict]:
    args = ["site-content", "outbox", "list", "--format=json", f"--limit={limit}",
            "--fields=id,event_id,event_type,content_type,content_id,content_version,status,state,attempts,next_attempt_at,updated_at,last_http_status,last_error,body"]
    if content_id is not None:
        args.append(f"--content-id={content_id}")
    if event is not None:
        args.append(f"--event={event}")
    rows = wp_json(*args) or []
    return sorted(rows, key=lambda r: int(r["id"]))


def mysql_ts(value: str) -> float:
    return datetime.strptime(value, "%Y-%m-%d %H:%M:%S").replace(tzinfo=timezone.utc).timestamp()


def hold_jobs(seconds: int = 900) -> None:
    """暫停 wp-jobs 的自動投遞與對帳（開發用 option，最長 900 秒，逾時自動失效）。"""
    wp("option", "update", "site_content_jobs_hold_until", str(int(time.time()) + seconds))


def release_jobs() -> None:
    wp("option", "update", "site_content_jobs_hold_until", "0", check_ok=False)
