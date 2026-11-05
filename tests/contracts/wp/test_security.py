"""權限遷移（WP-05）、XML-RPC、使用者列舉、第二因素閘門邏輯、正式環境防護、CMS 前臺轉址。"""

from __future__ import annotations

import json

from wpct_lib import (CMS_BASE, Context, check, cms_host, contract, http_request, public_get, wp, wp_eval, wp_eval_json, wp_json)

OTHER_CAP = "mysite_contract_other_plugin_cap"


def _role_caps(role: str) -> dict[str, bool]:
    return wp_eval_json(f"$r = get_role('{role}'); echo wp_json_encode($r ? $r->capabilities : null);") or {}


@contract("wp05_setup_rerun_keeps_admin_and_foreign_caps", ["WP-05"], "roles")
def wp05(ctx: Context) -> str | None:
    for role in ("administrator", "editor"):
        wp("cap", "add", role, OTHER_CAP)
        ctx.caps_added.append((role, OTHER_CAP))
    wp("cap", "add", "mysite_service", "edit_posts")  # 模擬服務帳號角色被擴權
    before_admin = _role_caps("administrator")
    before_editor = _role_caps("editor")
    wp("site-content", "setup")
    wp("site-content", "setup")
    after_admin = _role_caps("administrator")
    after_editor = _role_caps("editor")
    lost_admin = sorted(k for k, v in before_admin.items() if v and not after_admin.get(k))
    lost_editor = sorted(k for k, v in before_editor.items() if v and not after_editor.get(k))
    check(not lost_admin, f"setup 移除了管理員能力：{lost_admin}")
    check(not lost_editor, f"setup 移除了編輯者能力：{lost_editor}")
    check(after_admin.get(OTHER_CAP) and after_editor.get(OTHER_CAP), "其他外掛的能力被刪除")
    for cap in ("manage_options", "activate_plugins", "edit_users", "promote_users", "edit_sc_videos", "publish_sc_podcasts"):
        check(after_admin.get(cap), f"管理員缺少 {cap}")
    service = {k for k, v in _role_caps("mysite_service").items() if v}
    check(service == {"read", "site_content_read_preview", "site_content_diagnostics"}, f"服務帳號角色應只有三項能力：{sorted(service)}")
    # 管理員仍可登入並進入外掛與使用者管理（未被鎖死）。
    admin = ctx.admin_session()
    for path in ("/wp-admin/plugins.php", "/wp-admin/users.php", "/wp-admin/options-general.php?page=mysite-brand"):
        r = admin.request("GET", path)
        check(r.status == 200, f"setup 後管理員存取 {path} 應 200，實得 {r.status}")
    return None


@contract("security_xmlrpc_user_enumeration_jsonp", ["WP-08"], "security")
def security_basics(ctx: Context) -> str | None:
    x = http_request(CMS_BASE, "POST", "/xmlrpc.php", headers={"Content-Type": "text/xml"},
                     body=b"<?xml version='1.0'?><methodCall><methodName>system.listMethods</methodName></methodCall>", host_header=cms_host())
    check(x.status in (403, 404, 405), f"xmlrpc.php 應不可用，實得 {x.status}")
    enabled = wp_eval("echo apply_filters('xmlrpc_enabled', true) ? 'on' : 'off';").strip()
    check(enabled == "off", "xmlrpc_enabled 應為 false")
    for path in ("/wp-json/wp/v2/users", "/wp-json/wp/v2/users/1", "/?rest_route=/wp/v2/users"):
        r = http_request(CMS_BASE, "GET", path, host_header=cms_host())
        check(r.status in (401, 403, 404) and "mysite-admin" not in r.text, f"未登入不得列舉使用者：{path} → {r.status}")
    jsonp = http_request(CMS_BASE, "GET", "/wp-json/site-content/v1/health?_jsonp=cb", host_header=cms_host())
    check(not jsonp.text.startswith("/**/cb(") and not jsonp.text.startswith("cb("), "JSONP 應關閉")
    sitemap = http_request(CMS_BASE, "GET", "/wp-sitemap.xml", host_header=cms_host())
    check(sitemap.status in (301, 302, 404) and "<urlset" not in sitemap.text, "CMS 不應提供核心 sitemap")
    return None


@contract("wp10_two_factor_gate_logic", ["WP-10"], "security")
def wp10(ctx: Context) -> str | None:
    cases = wp_eval_json(r"""
$c = array();
foreach (array(array('production', true, false, 'index.php', true), array('staging', true, false, 'post.php', true),
               array('production', true, false, 'profile.php', true), array('production', true, true, 'index.php', true),
               array('development', true, false, 'index.php', true), array('production', false, false, 'index.php', true),
               array('production', true, false, 'plugins.php', false), array('production', true, false, 'plugins.php', true),
               array('production', true, false, 'rest', true)) as $a) {
  $c[] = mysite_2fa_gate_decision($a[0], $a[1], $a[2], $a[3], $a[4]);
}
echo wp_json_encode($c);
""")
    expected = ["redirect", "redirect", "notice", "allow", "notice", "allow", "notice", "redirect", "redirect"]
    check(cases == expected, f"第二因素閘門判斷不符：{cases}")
    check(wp_eval("echo class_exists('Two_Factor_Core') ? 'yes' : 'no';").strip() == "yes", "Two Factor 外掛應已啟用")
    return "程式部分（判斷邏輯與外掛啟用）；正式管理員實際登記第二因素屬 M10，未在此驗證"


@contract("production_guards_for_test_hooks", ["API-08", "WP-03"], "security")
def production_guards(ctx: Context) -> str | None:
    # 測試執行期間 hold option 已由 runner 設定；production 行程內必須視為無效。
    out = wp("eval", "update_option('site_content_simulate_outbox_failure', 1); $f = SiteContent\\Outbox::fault_injected(); "
                     "$h = SiteContent\\Outbox::hold_active(); "
                     "delete_option('site_content_simulate_outbox_failure'); "
                     "echo wp_json_encode(array($f, $h));",
             extra_env={"WP_ENVIRONMENT_TYPE": "production"}).stdout.strip()
    check(json.loads(out) == [False, False], f"production 不得啟用故障注入或暫停：{out}")
    res = wp("site-content", "outbox", "deliver", "--endpoint=http://127.0.0.1:9/internal/content-events/v1",
             "--event=00000000-0000-4000-8000-000000000000", check_ok=False, extra_env={"WP_ENVIRONMENT_TYPE": "production"})
    check(res.code != 0 and "development" in (res.stderr + res.stdout), "production 不得允許 --endpoint")
    return None


@contract("cms_frontend_redirects_to_app", ["UI-06"], "security")
def frontend(ctx: Context) -> str | None:
    app = ctx.cache["app_url"]
    slug_post = public_get("/posts", {"slug": "sample-gutenberg-blocks"}).json()["data"][0]
    cases = {
        "/": app + "/",
        f"/?p={slug_post['id']}": app + "/articles/sample-gutenberg-blocks",
        "/sample-gutenberg-blocks/": app + "/articles/sample-gutenberg-blocks",
        "/category/sample-notes/": app + "/categories/sample-notes",
        "/tag/sample/": app + "/tags/sample",
        "/videos/sample-video-1/": app + "/videos/sample-video-1",
        "/podcasts/sample-podcast-episode-1/": app + "/podcasts/sample-podcast-episode-1",
        "/about/": app + "/pages/about",
        "/?s=%E7%A4%BA%E4%BE%8B": app + "/search?q=%E7%A4%BA%E4%BE%8B",
        "/feed/": app + "/feed.xml",
        "/no-such-page-contract/": app + "/",
    }
    for path, target in cases.items():
        r = http_request(CMS_BASE, "GET", path, host_header=cms_host())
        check(r.status == 301, f"{path} 應 301，實得 {r.status}")
        check(r.header("location") == target, f"{path} 應轉到 {target}，實得 {r.header('location')}")
        check("noindex" in r.header("x-robots-tag"), f"{path} 缺少 X-Robots-Tag: noindex")
    draft = wp_json("post", "list", "--name=sample-hidden-draft", "--post_status=draft", "--fields=ID", "--format=json")
    if draft:
        r = http_request(CMS_BASE, "GET", f"/?p={draft[0]['ID']}", host_header=cms_host())
        check(r.status == 301 and r.header("location") == app + "/", "未登入讀草稿應轉主站首頁，不得洩露 slug")
    for path in ("/wp-login.php", "/wp-json/site-content/v1/health", "/wp-cron.php"):
        r = http_request(CMS_BASE, "GET", path, host_header=cms_host())
        check(r.status == 200, f"{path} 不應被前臺轉址影響，實得 {r.status}")
    admin_redirect = http_request(CMS_BASE, "GET", "/wp-admin/", host_header=cms_host())
    check(admin_redirect.status == 302 and "wp-login.php" in admin_redirect.header("location"), "未登入 wp-admin 應導向 wp-login")
    return None


@contract("brand_settings_https_only", ["API-05"], "security")
def brand_settings(ctx: Context) -> str | None:
    import re
    import urllib.parse
    original = wp("option", "get", "site_content_brand", "--format=json", check_ok=False).stdout.strip()
    admin = ctx.admin_session()
    try:
        page = admin.request("GET", "/wp-admin/options-general.php?page=mysite-brand")
        check(page.status == 200, f"品牌設定頁應 200，實得 {page.status}")
        nonce = re.search(r"name=\"_wpnonce\" value=\"([0-9a-f]{10})\"", page.text)
        check(nonce is not None, "品牌設定頁缺少 nonce")
        form = {
            "option_page": "site_content_brand_group", "action": "update", "_wpnonce": nonce.group(1),
            "_wp_http_referer": "/wp-admin/options-general.php?page=mysite-brand",
            "site_content_brand[author_bio]": "<b>契約測試</b>作者介紹",
            "site_content_brand[social_links]": "安全連結|https://example.com/contract\n不安全|http://example.com/x\n腳本|javascript:alert(1)\n沒有名稱|https://example.org/",
            "site_content_brand[logo_id]": "0", "site_content_brand[is_sample]": "1",
        }
        resp = admin.request("POST", "/wp-admin/options.php", {"Content-Type": "application/x-www-form-urlencoded"},
                             urllib.parse.urlencode(form).encode())
        check(resp.status in (302, 303), f"儲存品牌設定應導回設定頁，實得 {resp.status}")
        site = public_get("/site").json()["data"]
        check(site["social_links"] == [{"label": "安全連結", "url": "https://example.com/contract"},
                                       {"label": "沒有名稱", "url": "https://example.org/"}] or
              site["social_links"] == [{"label": "安全連結", "url": "https://example.com/contract"}],
              f"只應保留 https 社群連結：{site['social_links']}")
        check(all(not l["url"].startswith(("http:", "javascript:")) for l in site["social_links"]), "不得出現非 https 連結")
        check(site["author_bio"] == "契約測試作者介紹" and "<" not in site["author_bio"], f"作者介紹應為純文字：{site['author_bio']}")
        editor = ctx.editor_session()
        denied = editor.request("POST", "/wp-admin/options.php", {"Content-Type": "application/x-www-form-urlencoded"},
                                urllib.parse.urlencode({**form, "site_content_brand[author_bio]": "編輯者竄改"}).encode())
        check(denied.status in (403, 302) and public_get("/site").json()["data"]["author_bio"] == "契約測試作者介紹", "編輯者不得修改品牌設定")
    finally:
        if original and original != "false":
            wp("option", "update", "site_content_brand", original, "--format=json", check_ok=False)
    return None
