"""排程發布（WP-07 的 WP 端）：編輯者排程 → wp cron event run --due-now → 公開 API 出現且 published_at 為 UTC。"""

from __future__ import annotations

import time
from datetime import datetime, timedelta, timezone

from wpct_lib import Context, check, contract, outbox_rows, public_get, wp


@contract("wp07_scheduled_post_published_by_cron_utc", ["WP-07"], "schedule")
def wp07(ctx: Context) -> str | None:
    s = ctx.editor_session()
    tz = wp("option", "get", "timezone_string").stdout.strip()
    check(tz == "Asia/Taipei", f"站台時區應為 Asia/Taipei：{tz}")
    due_utc = datetime.now(timezone.utc).replace(microsecond=0) + timedelta(seconds=20)
    local = (due_utc + timedelta(hours=8)).strftime("%Y-%m-%dT%H:%M:%S")
    created = s.rest("POST", "/wp/v2/posts", {"title": "契約測試排程文章 sc-contract-schedule", "status": "future", "date": local,
                                               "content": "<!-- wp:paragraph --><p>排程正文</p><!-- /wp:paragraph -->"})
    check(created.status == 201, f"建立排程文章失敗：{created.status} {created.text[:200]}")
    body = created.json()
    pid = ctx.track_post(body["id"])
    check(body["status"] == "future", f"狀態應為 future：{body['status']}")
    check(body["date_gmt"] == due_utc.strftime("%Y-%m-%dT%H:%M:%S"), f"date_gmt 應為本地時間減 8 小時：{body['date_gmt']}")
    check(public_get(f"/posts/{pid}").status == 404, "到期前不得公開")
    check(public_get(f"/content/post/{pid}/visibility").json()["data"]["visible"] is False, "到期前 visibility 應為 false")
    events = wp("cron", "event", "list", "--hook=publish_future_post", "--fields=hook,args", "--format=csv").stdout
    check(str(pid) in events, "應排入 publish_future_post 排程事件")

    wait = (due_utc - datetime.now(timezone.utc)).total_seconds() + 1
    if wait > 0:
        time.sleep(wait)
    # 先不手動觸發：wp-jobs 每輪執行 `wp cron event run --due-now`（零訪客情境）。
    by_jobs = False
    status = 0
    deadline = time.time() + 75
    while time.time() < deadline:
        status = public_get(f"/posts/{pid}").status
        if status == 200:
            by_jobs = True
            break
        time.sleep(3)
    if not by_jobs:
        wp("cron", "event", "run", "--due-now")
        status = public_get(f"/posts/{pid}").status
    check(status == 200, f"到期後應出現在公開 API，實得 {status}")
    item = public_get(f"/posts/{pid}").json()["data"]
    check(item["published_at"] == due_utc.strftime("%Y-%m-%dT%H:%M:%SZ"), f"published_at 應為 UTC：{item['published_at']}（預期 {due_utc:%Y-%m-%dT%H:%M:%SZ}）")
    rows = [r for r in outbox_rows(content_id=pid) if r["event_type"] == "content.published"]
    check(len(rows) == 1 and rows[0]["status"] == "publish", f"排程發布應產生一筆 content.published：{rows}")
    return "由 wp-jobs 自動發布（未手動觸發）" if by_jobs else "wp-jobs 75 秒內未發布，改由測試手動執行 wp cron event run --due-now 後發布"
