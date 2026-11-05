"""排程發布（WP-07 的 WP 端）：編輯者排程 → wp cron event run --due-now → 公開 API 出現且 published_at 為 UTC。"""

from __future__ import annotations

import time
from datetime import datetime, timedelta, timezone

from wpct_lib import Context, check, contract, outbox_rows, public_get, wp, wp_eval_json


@contract("wp07_scheduled_post_published_by_cron_utc", ["WP-07"], "schedule")
def wp07(ctx: Context) -> str | None:
    s = ctx.editor_session()
    tz = wp("option", "get", "timezone_string").stdout.strip()
    check(tz == "Asia/Taipei", f"站台時區應為 Asia/Taipei：{tz}")
    # WordPress 對不足 60 秒的未來時間會直接發布，因此排在 70 秒後。
    due_utc = datetime.now(timezone.utc).replace(microsecond=0) + timedelta(seconds=70)
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
    # WordPress 的 cron 陣列整包讀寫：排程當下若 wp-jobs 正在執行到期事件，新事件可能被覆寫而遺失。
    # 系統保證的是「到期前一定排得到發布事件」：遺失時 wp-jobs 的 `schedule sweep` 會在一輪（30 秒）內補排。
    scheduled_by = ""
    poll_until = time.time() + 45
    while time.time() < poll_until:
        events = wp("cron", "event", "list", "--hook=publish_future_post", "--fields=hook,args", "--format=csv").stdout
        if str(pid) in events:
            scheduled_by = "建立時即排入" if not scheduled_by else scheduled_by
            break
        scheduled_by = "排程補救補排"
        time.sleep(3)
    else:
        scheduled_by = ""
    check(scheduled_by != "", "到期前應排入 publish_future_post 排程事件（含補救機制）")

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
    how = "由 wp-jobs 自動發布（未手動觸發）" if by_jobs else "wp-jobs 75 秒內未發布，改由測試手動執行 wp cron event run --due-now 後發布"
    return f"發布事件{scheduled_by}；{how}"


def _lose_event(pid: int) -> None:
    """模擬 cron 陣列被覆寫：只移除這篇內容的發布事件（不動其他排程）。"""
    gone = wp_eval_json(
        f"$ts = wp_next_scheduled('publish_future_post', array({pid}));"
        f"if ($ts) {{ wp_unschedule_event($ts, 'publish_future_post', array({pid})); }}"
        f"echo wp_json_encode(array('gone' => false === wp_next_scheduled('publish_future_post', array({pid}))));"
    )
    check(gone["gone"] is True, "無法模擬事件遺失")


@contract("wp07_missed_schedule_recovered_by_sweep", ["WP-07"], "schedule")
def wp07_sweep(ctx: Context) -> str | None:
    """排程補救：發布事件遺失時 `schedule sweep` 補排（時間不變）；已到期仍未發布者補發，published_at 為原排程時間（UTC）。"""
    s = ctx.editor_session()
    due_utc = datetime.now(timezone.utc).replace(microsecond=0) + timedelta(seconds=70)
    local = (due_utc + timedelta(hours=8)).strftime("%Y-%m-%dT%H:%M:%S")
    created = s.rest("POST", "/wp/v2/posts", {"title": "契約測試排程補救 sc-contract-schedule-sweep", "status": "future", "date": local,
                                               "content": "<!-- wp:paragraph --><p>排程補救正文</p><!-- /wp:paragraph -->"})
    check(created.status == 201, f"建立排程文章失敗：{created.status} {created.text[:200]}")
    pid = ctx.track_post(created.json()["id"])

    # 1. 事件遺失 → 補排，時間等於原排程時間
    _lose_event(pid)
    wp("site-content", "schedule", "sweep")
    ts = wp_eval_json(f"echo wp_json_encode(array('ts' => wp_next_scheduled('publish_future_post', array({pid}))));")["ts"]
    check(ts == int(due_utc.timestamp()), f"補排時間應為原排程時間：{ts} ≠ {int(due_utc.timestamp())}")

    # 2. 再次遺失並等到過期 → 補發（wp-jobs 每輪也會執行 sweep；兩者任一先發布皆可）
    _lose_event(pid)
    wait = (due_utc - datetime.now(timezone.utc)).total_seconds() + 1
    if wait > 0:
        time.sleep(wait)
    if public_get(f"/posts/{pid}").status != 200:
        wp("site-content", "schedule", "sweep")
    check(public_get(f"/posts/{pid}").status == 200, "事件遺失且已到期的排程內容應被補發")
    item = public_get(f"/posts/{pid}").json()["data"]
    check(item["published_at"] == due_utc.strftime("%Y-%m-%dT%H:%M:%SZ"), f"補發後 published_at 應為原排程時間（UTC）：{item['published_at']}")
    rows = [r for r in outbox_rows(content_id=pid) if r["event_type"] == "content.published"]
    check(len(rows) == 1, f"補發應產生恰好一筆 content.published：{rows}")
    return "事件遺失時補排（時間不變）；到期未發布時補發，published_at 為原排程時間"

