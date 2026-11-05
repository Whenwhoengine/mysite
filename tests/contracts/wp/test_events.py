"""內容事件 outbox：事件格式、簽章、狀態轉換（delivered／retry／dead）、重播、不跟隨 redirect、對帳補漏。"""

from __future__ import annotations

import base64
import json
import re
import time
from pathlib import Path

from wpct_lib import (RECEIVER_DIR, RECEIVER_ENDPOINT, RECEIVER_PORT, UTC_RE, UUID4_RE, Context, TestFailure, check,
                      compose_exec, contract, dev, event_signature, keyring, mysql_ts, outbox_rows, public_get, signing_key, wp,
                      wp_create_post)

FIXTURE = Path(__file__).resolve().parents[2] / "fixtures" / "wp" / "event-receiver.php"
BODY_KEYS = ["event_id", "event_type", "content_type", "content_id", "content_version", "status", "occurred_at"]


def start_receiver(ctx: Context) -> None:
    if ctx.receiver_started:
        return
    res = compose_exec("wp-php", f"mkdir -p {RECEIVER_DIR} && cat > {RECEIVER_DIR}/receiver.php && : > {RECEIVER_DIR}/requests.jsonl && echo 202 > {RECEIVER_DIR}/mode",
                       stdin=FIXTURE.read_bytes())
    check(res.code == 0, f"無法放置測試接收端：{res.stderr[-200:]}")
    from wpct_lib import dev
    dev("compose", "exec", "-d", "-T", "-u", "www-data", "-e", f"SC_RECEIVER_DIR={RECEIVER_DIR}", "wp-php", "sh", "-c",
        f"echo $$ > {RECEIVER_DIR}/pid; exec php -S 127.0.0.1:{RECEIVER_PORT} {RECEIVER_DIR}/receiver.php >/dev/null 2>&1")
    for _ in range(30):
        probe = compose_exec("wp-php", f"php -r 'exit(@fsockopen(\"127.0.0.1\", {RECEIVER_PORT}) ? 0 : 1);'")
        if probe.code == 0:
            ctx.receiver_started = True
            return
        time.sleep(0.3)
    raise TestFailure("測試接收端未啟動")


def stop_receiver() -> None:
    compose_exec("wp-php", f"[ -f {RECEIVER_DIR}/pid ] && kill $(cat {RECEIVER_DIR}/pid) 2>/dev/null; true")


def set_mode(mode: str) -> None:
    compose_exec("wp-php", f"echo {mode} > {RECEIVER_DIR}/mode")


def received() -> list[dict]:
    out = compose_exec("wp-php", f"cat {RECEIVER_DIR}/requests.jsonl").stdout
    return [json.loads(line) for line in out.splitlines() if line.strip()]


def deliver(event_id: str, ignore_schedule: bool = True, endpoint: str = RECEIVER_ENDPOINT) -> str:
    args = ["site-content", "outbox", "deliver", f"--event={event_id}", f"--endpoint={endpoint}", "--ignore-hold"]
    if ignore_schedule:
        args.append("--ignore-schedule")
    return wp(*args).stdout


def latest_event(content_id: int) -> dict:
    rows = outbox_rows(content_id=content_id)
    check(bool(rows), f"內容 #{content_id} 沒有任何事件")
    return rows[-1]


def one(event_id: str) -> dict:
    rows = outbox_rows(event=event_id)
    check(len(rows) == 1, f"找不到事件 {event_id}")
    return rows[0]


@contract("events_body_signature_and_retry_resign", ["API-06", "API-08"], "events")
def signature_and_retry(ctx: Context) -> str | None:
    start_receiver(ctx)
    key_id = wp("eval", "echo MYSITE_CONTENT_EVENT_KEY_ID;").stdout.strip()
    if keyring("content_event_hmac") is not None:
        # GAP-KEYRING-01：有金鑰圈時，WP 必須以金鑰圈中的 active key ID 送出
        check(key_id == signing_key("content_event_hmac")[0], f"X-Mysite-Key-Id 應為事件金鑰圈的 active key ID：{key_id}")
    pid = wp_create_post(ctx, "--post_type=post", "--post_status=publish", "--post_title=契約測試事件文章", "--post_content=sc-contract-event")
    ev = latest_event(pid)
    check(ev["event_type"] == "content.published" and ev["state"] == "pending" and int(ev["attempts"]) == 0, f"新發布應產生 pending 的 content.published：{ev}")
    body = ev["body"].encode("utf-8")
    parsed = json.loads(body)
    check(list(parsed.keys()) == BODY_KEYS, f"事件 body 欄位或順序不符：{list(parsed)}")
    check(UUID4_RE.match(parsed["event_id"]) is not None and parsed["event_id"] == ev["event_id"], "event_id 應為 UUID v4 且與欄位一致")
    check(parsed["content_type"] == "post" and parsed["content_id"] == pid and parsed["status"] == "publish", "事件內容不符")
    check(isinstance(parsed["content_version"], int) and parsed["content_version"] >= 1, "content_version 應為正整數")
    check(UTC_RE.match(parsed["occurred_at"]) is not None, "occurred_at 不是 UTC 格式")
    check(public_get(f"/posts/{pid}").json()["data"]["content_version"] == parsed["content_version"], "API 與事件的 content_version 應一致")

    set_mode("500")
    deliver(ev["event_id"])
    r1 = one(ev["event_id"])
    check(r1["state"] == "pending" and int(r1["attempts"]) == 1 and int(r1["last_http_status"]) == 500, f"500 應保留 pending 並記錄嘗試：{r1}")
    check(abs(mysql_ts(r1["next_attempt_at"]) - mysql_ts(r1["updated_at"]) - 30) <= 2, "第一次失敗應退避 30 秒")
    wp("site-content", "outbox", "deliver", f"--event={ev['event_id']}", f"--endpoint={RECEIVER_ENDPOINT}", "--ignore-hold")
    check(int(one(ev["event_id"])["attempts"]) == 1, "未到 next_attempt_at 不應重送")
    time.sleep(1.2)
    set_mode("202")
    deliver(ev["event_id"])
    r2 = one(ev["event_id"])
    check(r2["state"] == "delivered" and int(r2["attempts"]) == 2 and int(r2["last_http_status"]) == 202, f"202 應轉為 delivered：{r2}")

    reqs = [r for r in received() if r["headers"].get("x-mysite-event-id") == ev["event_id"]]
    check(len(reqs) == 2, f"接收端應收到 2 次投遞，實得 {len(reqs)}")
    stamps = []
    for req in reqs:
        raw = base64.b64decode(req["body_b64"])
        h = req["headers"]
        check(req["method"] == "POST" and req["path"] == "/internal/content-events/v1", "投遞方法或路徑不符")
        check(raw == body, "重試時 body 位元組必須完全相同")
        check(h.get("content-type", "").startswith("application/json"), "Content-Type 應為 application/json")
        check(h.get("x-mysite-key-id") == key_id, "X-Mysite-Key-Id 不符")
        ts = h.get("x-mysite-timestamp", "")
        check(re.fullmatch(r"\d{10}", ts) is not None and abs(int(ts) - time.time()) < 120, "X-Mysite-Timestamp 應為目前 unix 秒")
        sig = h.get("x-mysite-signature", "")
        check(re.fullmatch(r"[0-9a-f]{64}", sig) is not None, "簽章應為 64 位小寫 hex")
        check(sig == event_signature(ts, ev["event_id"], raw, h.get("x-mysite-key-id")), "HMAC-SHA256(timestamp\\nevent_id\\nbody) 驗證失敗")
        stamps.append((ts, sig))
    check(stamps[0][0] != stamps[1][0] and stamps[0][1] != stamps[1][1], "每次投遞應重新產生 timestamp 與簽章")
    tampered = event_signature(stamps[1][0], ev["event_id"], body + b" ")
    check(tampered != stamps[1][1], "竄改 body 後簽章應不同")
    return None


@contract("keyring_loaded_matches_secret_files", ["API-06", "API-11"], "events")
def keyring_loaded(ctx: Context) -> str | None:
    """GAP-KEYRING-01：WP（發送端）與 Laravel（接收端）實際載入的金鑰圈與秘密檔一致；狀態輸出不含金鑰。"""
    notes = []
    for name, kind in (("content_event_hmac", "event-hmac"), ("preview_signing", "preview")):
        ring = keyring(name)
        if ring is None:
            default_id = wp("eval", "echo MYSITE_CONTENT_EVENT_KEY_ID;").stdout.strip() if kind == "event-hmac" else "k1"
            expected = [f"{default_id} active"]
        else:
            expected = [f"{k} {v[1]}" for k, v in sorted(ring.items(), key=lambda kv: int(kv[0][1:]))]
        wp_out = wp("site-content", "keyring", kind).stdout
        app = dev("compose", "exec", "-T", "app-php", "php", "artisan", "mysite:keyring", kind, "--no-ansi")
        check(app.code == 0, f"Laravel mysite:keyring {kind} 執行失敗")
        for side, out in (("WordPress", wp_out), ("Laravel", app.stdout)):
            lines = [line.strip() for line in out.splitlines() if line.strip()]
            check(lines == expected, f"{side} 載入的 {kind} 金鑰圈與秘密檔不一致：{lines} ≠ {expected}")
            for key, _ in (ring or {}).values():
                check(key not in out, f"{side} 的金鑰圈狀態輸出不得含金鑰")
        notes.append(f"{kind}={'／'.join(expected)}")
    return "；".join(notes)


@contract("events_dead_letter_replay_redirect_transport", ["API-08"], "events")
def dead_letters(ctx: Context) -> str | None:
    start_receiver(ctx)
    pid = wp_create_post(ctx, "--post_type=post", "--post_status=publish", "--post_title=契約測試死信文章")
    results = {}
    for code in ("409", "401", "422"):
        wp("post", "update", str(pid), f"--post_title=契約測試死信文章 {code}")
        ev = latest_event(pid)
        check(ev["event_type"] == "content.updated", f"修改應產生 content.updated：{ev['event_type']}")
        set_mode(code)
        deliver(ev["event_id"])
        row = one(ev["event_id"])
        check(row["state"] == "dead" and int(row["attempts"]) == 1, f"HTTP {code} 應直接進死信：{row}")
        results[code] = ev
    ev409 = results["409"]
    body_before = one(ev409["event_id"])["body"]
    wp("site-content", "outbox", "replay", ev409["event_id"])
    replayed = one(ev409["event_id"])
    check(replayed["state"] == "pending" and int(replayed["attempts"]) == 0 and replayed["body"] == body_before, "重播應以同 ID、同 body 重新排入")
    set_mode("202")
    deliver(ev409["event_id"])
    check(one(ev409["event_id"])["state"] == "delivered", "重播後應可送達")
    bodies = {base64.b64decode(r["body_b64"]) for r in received() if r["headers"].get("x-mysite-event-id") == ev409["event_id"]}
    check(bodies == {body_before.encode()}, "重播前後送出的 body 必須相同")

    # 退避上限與 10 次後死信。
    wp("post", "update", str(pid), "--post_title=契約測試死信文章 503")
    ev = latest_event(pid)
    set_mode("503")
    backoffs = []
    for i in range(10):
        deliver(ev["event_id"])
        row = one(ev["event_id"])
        check(int(row["attempts"]) == i + 1, f"第 {i + 1} 次投遞後 attempts 不符：{row['attempts']}")
        if row["state"] == "pending":
            backoffs.append(round(mysql_ts(row["next_attempt_at"]) - mysql_ts(row["updated_at"])))
    check(one(ev["event_id"])["state"] == "dead", "10 次失敗後應進死信")
    expected = [min(3600, 30 * 2 ** n) for n in range(9)]
    check(all(abs(a - b) <= 2 for a, b in zip(backoffs, expected)) and len(backoffs) == 9, f"退避秒數不符：{backoffs}")

    # 3xx 不跟隨。
    wp("post", "update", str(pid), "--post_title=契約測試死信文章 302")
    ev = latest_event(pid)
    set_mode("302")
    deliver(ev["event_id"])
    row = one(ev["event_id"])
    check(row["state"] == "pending" and int(row["last_http_status"]) == 302, f"302 應視為可重試錯誤、不跟隨：{row}")
    check(not any(r["path"] == "/redirected-target" for r in received()), "不得跟隨 redirect")

    # 連線失敗 → 重試。
    wp("post", "update", str(pid), "--post_title=契約測試死信文章 連線失敗")
    ev = latest_event(pid)
    deliver(ev["event_id"], endpoint=f"http://127.0.0.1:{RECEIVER_PORT + 1}/internal/content-events/v1")
    row = one(ev["event_id"])
    check(row["state"] == "pending" and (row["last_error"] or "").startswith("transport:"), f"連線失敗應記錄 transport 錯誤並重試：{row}")
    # 錯誤路徑的接收端被拒。
    bad = wp("site-content", "outbox", "deliver", f"--event={ev['event_id']}", "--endpoint=http://127.0.0.1:9/other", "--ignore-hold", check_ok=False)
    check(bad.code != 0, "接收端路徑不是 /internal/content-events/v1 時應拒絕")
    set_mode("202")
    return None


@contract("events_reconcile_recovers_after_hook_failure", ["API-09", "API-15"], "events")
def reconcile(ctx: Context) -> str | None:
    pid = wp_create_post(ctx, "--post_type=post", "--post_status=publish", "--post_title=契約測試對帳文章")
    base = latest_event(pid)
    v0 = public_get(f"/posts/{pid}").json()["data"]["content_version"]
    try:
        wp("option", "update", "site_content_simulate_outbox_failure", "1")
        ctx.options_set.append("site_content_simulate_outbox_failure")
        ctx.persist()
        wp("post", "update", str(pid), "--post_title=契約測試對帳文章（故障中修改）")
    finally:
        wp("option", "update", "site_content_simulate_outbox_failure", "0", check_ok=False)
    check(latest_event(pid)["event_id"] == base["event_id"], "故障注入期間不應寫入 outbox")
    stale = public_get(f"/posts/{pid}").json()["data"]
    check(stale["title"] == "契約測試對帳文章（故障中修改）" and stale["content_version"] == v0, "故障期間 WP 已存內容但版本未遞增（模擬漏事件）")
    out = wp("site-content", "reconcile", "--ignore-hold").stdout
    check("新事件" in out, f"reconcile 輸出不符：{out}")
    fixed = latest_event(pid)
    check(fixed["event_id"] != base["event_id"] and fixed["event_type"] == "content.updated", f"對帳應補送 content.updated：{fixed}")
    check(int(fixed["content_version"]) == v0 + 1 and public_get(f"/posts/{pid}").json()["data"]["content_version"] == v0 + 1, "對帳後版本應遞增並一致")
    again = wp("site-content", "reconcile", "--ignore-hold").stdout
    check(latest_event(pid)["event_id"] == fixed["event_id"], f"再次對帳不應重複補送：{again}")

    # 撤下漏事件 → 對帳補 content.unpublished。
    try:
        wp("option", "update", "site_content_simulate_outbox_failure", "1")
        wp("post", "update", str(pid), "--post_status=draft")
    finally:
        wp("option", "update", "site_content_simulate_outbox_failure", "0", check_ok=False)
    wp("site-content", "reconcile", "--ignore-hold")
    unpub = latest_event(pid)
    check(unpub["event_type"] == "content.unpublished" and unpub["status"] == "draft", f"對帳應補送 content.unpublished：{unpub}")

    # 刪除漏事件 → 對帳偵測刪除。
    try:
        wp("option", "update", "site_content_simulate_outbox_failure", "1")
        wp("post", "delete", str(pid), "--force")
    finally:
        wp("option", "update", "site_content_simulate_outbox_failure", "0", check_ok=False)
    check(latest_event(pid)["event_id"] == unpub["event_id"], "故障期間刪除不應產生事件")
    wp("site-content", "reconcile", "--ignore-hold")
    gone = latest_event(pid)
    check(gone["event_type"] == "content.deleted" and gone["status"] == "deleted", f"對帳應偵測刪除並送 content.deleted：{gone}")
    vis = public_get(f"/content/post/{pid}/visibility").json()["data"]
    check(vis["visible"] is False and vis["content_version"] == int(gone["content_version"]), "刪除後 visibility 應為 false 且版本為最後值")

    # resync：即使沒有變更也送出目前狀態。
    pid2 = wp_create_post(ctx, "--post_type=page", "--post_status=publish", "--post_title=契約測試 resync 頁面")
    first = latest_event(pid2)
    wp("site-content", "resync", str(pid2))
    second = latest_event(pid2)
    check(second["event_id"] != first["event_id"] and second["event_type"] == "content.updated" and int(second["content_version"]) == int(first["content_version"]) + 1,
          f"resync 應送 content.updated 並遞增版本：{second}")
    return None
