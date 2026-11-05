"""CMS 維護模式（GAP-CMS-MAINT-01；規劃書 v5 §14.7）。

開啟時：wp-admin 與 REST 寫入一律 503＋Retry-After（管理員也一樣）；公開讀取、容器內 health 與主站首頁照常；
登入頁可開、登入後只看到維護說明；wp-jobs 的到期排程、outbox 投遞與對帳暫停，直接請求 wp-cron.php 也不執行排程。
關閉後寫入與排程恢復。每個測試結束（含失敗）一律關閉維護模式；run_contracts.py 收尾時會再確認一次。
"""

from __future__ import annotations

import json
import time
import urllib.parse

from wpct_lib import (APP_BASE, CMS_BASE, UTC_RE, CmdResult, CmsSession, Context, check, cms_host, contract, dev,
                      http_request, internal_get, make_png, public_get, secret, wp, wp_create_post, wp_eval, wp_json)

PROBE_HOOK = "site_content_maintenance_probe"
RETRY_AFTER = "60"
BROWSER = {"Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8"}  # 瀏覽器的 Accept（不要求 JSON）


def _status() -> dict:
    out = wp("site-content", "maintenance", "status", "--format=json").stdout
    lines = [line for line in out.splitlines() if line.startswith("{")]
    check(bool(lines), f"maintenance status 沒有 JSON 輸出：{out[-200:]}")
    return json.loads(lines[-1])


def _require_inactive() -> None:
    check(not _status()["active"], "維護模式已在開啟中（可能有人正在維護），不執行本測試；確認後以 wp site-content maintenance off 關閉")


def _on(ctx: Context, reason: str = "contract test") -> CmdResult:
    ctx.cache["maintenance_touched"] = True
    return wp("site-content", "maintenance", "on", f"--reason={reason}")


def _off() -> CmdResult:
    return wp("site-content", "maintenance", "off", check_ok=False)


def _jobs(*args: str) -> CmdResult:
    """在 wp-jobs 容器（MYSITE_ROLE=jobs）執行 WP-CLI，與 infra/images/wordpress/jobs.sh 相同身分。"""
    return dev("compose", "exec", "-T", "-u", "www-data", "wp-jobs", "wp", *args)


def _form_login(session: CmsSession, username: str, password: str) -> None:
    """只送登入表單（維護中 admin-ajax 取 nonce 會 503，不能用 CmsSession.login）。"""
    session.cookies["wordpress_test_cookie"] = "WP%20Cookie%20check"
    form = urllib.parse.urlencode({
        "log": username, "pwd": password, "wp-submit": "登入", "testcookie": "1",
        "redirect_to": f"http://{cms_host()}/wp-admin/",
    }).encode()
    resp = session.request("POST", "/wp-login.php", {"Content-Type": "application/x-www-form-urlencoded"}, form)
    check(resp.status in (302, 303) and any(k.startswith("wordpress_logged_in_") for k in session.cookies),
          f"維護中登入失敗（HTTP {resp.status}）：{username}")


@contract("cms_maintenance_blocks_writes_keeps_public_reads", ["OPS-06", "WP-08"], "maintenance")
def maintenance_blocks_writes(ctx: Context) -> str | None:
    _require_inactive()
    editor = ctx.editor_session()
    admin = ctx.admin_session()
    post_id = wp_create_post(ctx, "--post_type=post", "--post_status=draft", "--post_title=維護模式測試草稿")
    base = editor.rest("POST", f"/wp/v2/posts/{post_id}", {"title": "維護模式測試草稿（基準）"})
    check(base.status == 200, f"未維護時編輯者應可修改草稿（HTTP {base.status}）")

    blocked: list[str] = []

    def expect_503(label: str, resp) -> None:
        check(resp.status == 503, f"{label} 應回 503，實際 HTTP {resp.status}")
        check(resp.header("retry-after") == RETRY_AFTER, f"{label} 的 Retry-After 應為 {RETRY_AFTER}：{resp.header('retry-after')!r}")
        blocked.append(label)

    _on(ctx)
    try:
        st = _status()
        check(st["active"] and UTC_RE.match(st["enabled_at"] or "") is not None and st["reason"] == "contract test", f"開啟後狀態不符：{st}")

        # --- REST 寫入：編輯者（cookie＋nonce）、管理員、未登入者，含方法改寫與 batch ---
        created = editor.rest("POST", "/wp/v2/posts", {"title": "維護中不得建立", "status": "draft"})
        expect_503("編輯者 POST /wp/v2/posts", created)
        body = created.json()
        check(body.get("code") == "site_content_maintenance" and "維護" in str(body.get("message", "")),
              f"503 回應應附正體中文說明：{str(body)[:200]}")
        expect_503("編輯者修改草稿", editor.rest("POST", f"/wp/v2/posts/{post_id}", {"title": "維護中修改"}))
        expect_503("編輯者發布", editor.rest("POST", f"/wp/v2/posts/{post_id}", {"status": "publish"}))
        expect_503("PUT", editor.rest("PUT", f"/wp/v2/posts/{post_id}", {"title": "維護中 PUT"}))
        expect_503("PATCH", editor.rest("PATCH", f"/wp/v2/posts/{post_id}", {"title": "維護中 PATCH"}))
        expect_503("DELETE", editor.rest("DELETE", f"/wp/v2/posts/{post_id}"))
        expect_503("GET＋_method=DELETE", editor.rest("GET", f"/wp/v2/posts/{post_id}", params={"_method": "DELETE"}))
        expect_503("自動儲存", editor.rest("POST", f"/wp/v2/posts/{post_id}/autosaves", {"title": "維護中自動儲存"}))
        expect_503("媒體上傳", editor.upload("maintenance-probe.png", make_png(), "image/png"))
        expect_503("batch", editor.rest("POST", "/batch/v1", {"requests": [{"method": "POST", "path": "/wp/v2/posts", "body": {"title": "x"}}]}))
        expect_503("site-content/v1 寫入方法", editor.rest("POST", "/site-content/v1/health", {}))
        expect_503("管理員改設定", admin.rest("POST", "/wp/v2/settings", {"title": "維護中不得改站名"}))
        expect_503("管理員新增頁面", admin.rest("POST", "/wp/v2/pages", {"title": "維護中不得建立", "status": "draft"}))
        expect_503("未登入 POST", http_request(CMS_BASE, "POST", "/wp-json/wp/v2/posts", headers={"Content-Type": "application/json"},
                                             body=b'{"title":"x"}', host_header=cms_host()))
        post = wp_json("post", "get", str(post_id), "--fields=post_title,post_status", "--format=json")
        check(post == {"post_title": "維護模式測試草稿（基準）", "post_status": "draft"}, f"維護中內容不應改變：{post}")

        # --- 讀取照常：公開 REST、容器內 health（Laravel 走的路徑）、編輯者讀取、主站首頁 ---
        for path in ("/health", "/posts", "/site"):
            resp = public_get(path)
            check(resp.status == 200, f"維護中公開 GET {path} 應 200，實際 {resp.status}")
        check(editor.rest("GET", f"/wp/v2/posts/{post_id}", params={"context": "edit"}).status == 200, "維護中編輯者讀取應 200")
        inner = internal_get("http://wp-web:8080/wp-json/site-content/v1/health", auth="none")
        check(inner.status == 200, f"容器內 health（Laravel 讀取路徑）應 200，實際 {inner.status}")
        app = http_request(APP_BASE, "GET", "/", host_header=urllib.parse.urlsplit(ctx.cache["app_url"]).netloc)
        check(app.status == 200, f"維護中主站首頁應 200，實際 {app.status}")

        # --- wp-admin（含 admin-ajax、admin-post、async-upload）只顯示維護說明 ---
        page = editor.request("GET", "/wp-admin/", BROWSER)
        expect_503("wp-admin 首頁", page)
        check(page.header("content-type").startswith("text/html") and "CMS 維護中" in page.text and "登出" in page.text,
              f"wp-admin 應以 HTML 顯示維護說明與登出連結（{page.header('content-type')}）")
        expect_503("管理員新增文章頁", admin.request("GET", "/wp-admin/post-new.php", BROWSER))
        expect_503("管理員外掛頁", admin.request("GET", "/wp-admin/plugins.php", BROWSER))
        form = {"Content-Type": "application/x-www-form-urlencoded"}
        expect_503("admin-ajax heartbeat", editor.request("POST", "/wp-admin/admin-ajax.php", form, b"action=heartbeat&interval=60"))
        expect_503("async-upload", editor.request("POST", "/wp-admin/async-upload.php", form, b"action=upload-attachment"))
        expect_503("admin-post 預覽", editor.request("GET", f"/wp-admin/admin-post.php?action=site_content_preview&post={post_id}"))

        # --- 登入頁可開；維護中登入後進 wp-admin 只看到維護說明 ---
        login_page = http_request(CMS_BASE, "GET", "/wp-login.php", host_header=cms_host())
        check(login_page.status == 200 and "CMS 維護中" in login_page.text, f"登入頁應可開啟並提示維護中（HTTP {login_page.status}）")
        fresh = CmsSession()
        _form_login(fresh, "mysite-editor", secret("wp_editor_password"))
        landing = fresh.request("GET", "/wp-admin/", BROWSER)
        expect_503("維護中登入後的 wp-admin", landing)
        check("CMS 維護中" in landing.text, "維護中登入後應只看到維護說明")
    finally:
        _off()

    st = _status()
    check(not st["active"] and UTC_RE.match(st["disabled_at"] or "") is not None, f"關閉後狀態不符：{st}")
    after = editor.rest("POST", f"/wp/v2/posts/{post_id}", {"title": "維護模式測試草稿（恢復）"})
    check(after.status == 200, f"關閉維護後寫入應恢復（HTTP {after.status}）")
    check(editor.request("GET", "/wp-admin/", BROWSER).status == 200, "關閉維護後 wp-admin 應恢復")
    return f"維護中 {len(blocked)} 種寫入／後臺入口皆 503＋Retry-After {RETRY_AFTER}（含管理員、未登入、_method 改寫、batch）；公開 GET、容器內 health、主站首頁 200；關閉後寫入恢復"


@contract("cms_maintenance_pauses_jobs_and_http_cron", ["OPS-06", "WP-07"], "maintenance")
def maintenance_pauses_jobs(ctx: Context) -> str | None:
    _require_inactive()
    marker = f"probe-{int(time.time())}"
    wp_eval(f"wp_schedule_single_event(time() - 10, '{PROBE_HOOK}', array('{marker}'));")

    def probe_scheduled() -> bool:
        return wp_eval(f"echo wp_next_scheduled('{PROBE_HOOK}', array('{marker}')) ? 'yes' : 'no';").strip().endswith("yes")

    check(probe_scheduled(), "到期探針排程未建立")
    _on(ctx)
    try:
        for args, what in ((("cron", "event", "run", "--due-now"), "到期排程"), (("site-content", "outbox", "deliver"), "outbox 投遞"),
                           (("site-content", "reconcile"), "對帳")):
            res = _jobs(*args)
            check(res.code == 0 and "維護模式中" in res.stdout, f"wp-jobs 的{what}應暫停且 exit 0（exit {res.code}）：{res.stdout[-200:]}")
        http_cron = http_request(CMS_BASE, "GET", "/wp-cron.php", host_header=cms_host())
        check(http_cron.status == 200, f"wp-cron.php 應正常回應（HTTP {http_cron.status}）")
        check(probe_scheduled(), "維護中到期探針被執行了（wp-jobs 或 wp-cron.php 未暫停排程）")
        manual = wp("site-content", "maintenance", "status")
        check("維護模式：開啟" in manual.stdout, "維運者在 wp-php 的 WP-CLI 應不受維護模式限制")
    finally:
        _off()
    resumed = _jobs("cron", "event", "run", "--due-now")
    check(resumed.code == 0 and "維護模式中" not in resumed.stdout, f"關閉維護後 wp-jobs 排程應恢復：{resumed.stdout[-200:]}")
    check(not probe_scheduled(), "關閉維護後到期探針應已執行")
    return "維護中 wp-jobs 的排程、投遞、對帳皆暫停（exit 0，心跳照常）且 wp-cron.php 不執行到期排程；關閉後排程恢復"


@contract("cms_maintenance_cli_state_and_validation", ["OPS-06", "DAT-02"], "maintenance")
def maintenance_cli(ctx: Context) -> str | None:
    _require_inactive()
    email = wp("site-content", "maintenance", "on", "--reason=請聯絡 someone@example.test", check_ok=False)
    check(email.code == 10 and not _status()["active"], f"含 email 的原因應以 exit 10 拒絕（exit {email.code}）")
    too_long = wp("site-content", "maintenance", "on", "--reason=" + "長" * 201, check_ok=False)
    check(too_long.code == 10 and not _status()["active"], f"超過 200 字的原因應以 exit 10 拒絕（exit {too_long.code}）")
    try:
        _on(ctx, "第一次")
        first = _status()
        time.sleep(1.2)
        again = _on(ctx, "第二次")
        second = _status()
        check("未變更" in again.stdout and second["enabled_at"] == first["enabled_at"] and second["reason"] == "第一次",
              f"重複開啟應保留原本的開始時間與原因：{first} → {second}")
        check("維護模式：開啟" in wp("site-content", "maintenance", "status").stdout, "文字狀態應顯示開啟")
    finally:
        _off()
    off_again = wp("site-content", "maintenance", "off")
    check("未變更" in off_again.stdout, "重複關閉應為不變更")
    closed = _status()
    check(not closed["active"] and closed["enabled_at"] == first["enabled_at"] and UTC_RE.match(closed["disabled_at"] or "") is not None,
          f"關閉後應保留上次開始時間並記錄結束時間：{closed}")
    try:
        _on(ctx, "")
        check(_status()["reason"] == "未註明", "未提供原因時應記為「未註明」")
    finally:
        _off()
    raw = wp_json("option", "get", "site_content_maintenance", "--format=json")
    check(sorted(raw) == ["active", "disabled_at", "enabled_at", "reason"], f"狀態只應有時間與原因欄位（不含操作者）：{sorted(raw)}")
    return "含 email／超長原因拒絕（exit 10）；重複開啟保留原時間與原因、重複關閉不變更；無原因記「未註明」；狀態只含時間與原因"
