"""真實後臺路徑：編輯者經 gateway 以 cookie＋nonce 建立／修改內容與上傳媒體；越權被拒；上傳白名單。"""

from __future__ import annotations

import json
import re
import time
import urllib.parse

from wpct_lib import (CMS_BASE, Context, check, cms_host, contract, http_request, internal_get, make_png, make_wav,
                      public_get, wp, wp_json)


def _inputs(html: str) -> dict[str, str]:
    out: dict[str, str] = {}
    for tag in re.findall(r"<input\b[^>]*>", html):
        name = re.search(r"\bname=(['\"])(.*?)\1", tag)
        if not name:
            continue
        value = re.search(r"\bvalue=(['\"])(.*?)\1", tag)
        out.setdefault(name.group(2), value.group(2) if value else "")
    return out


def metabox_save(s, post_id: int, acf: dict[str, str]) -> int:
    """模擬區塊編輯器儲存 ACF 欄位：送出 meta-box-loader 表單（ACF 在後臺的真實儲存路徑）。"""
    page = s.request("GET", f"/wp-admin/post.php?post={post_id}&action=edit")
    check(page.status == 200, f"編輯畫面應 200，實得 {page.status}")
    m = re.search(r"_wpMetaBoxUrl\s*=\s*\"([^\"]+)\"", page.text)
    check(m is not None, "編輯畫面缺少 meta box 儲存網址")
    url = urllib.parse.urlsplit(m.group(1).replace("\\/", "/").replace("&amp;", "&"))
    fields = _inputs(page.text)
    keep = ("_wpnonce", "_wp_http_referer", "user_ID", "action", "originalaction", "post_type", "original_post_status",
            "referredby", "_wp_original_http_referer", "post_ID", "_acf_nonce", "_acf_post_id", "_acf_screen", "_acf_validation")
    form = {k: v for k, v in fields.items() if k in keep or k.startswith("acf[")}
    check("_acf_nonce" in form and "_wpnonce" in form, "編輯畫面缺少 ACF 或 WordPress nonce")
    form["_acf_validation"] = "0"
    form.update({f"acf[{k}]": v for k, v in acf.items()})
    resp = s.request("POST", f"{url.path}?{url.query}", {"Content-Type": "application/x-www-form-urlencoded"},
                     urllib.parse.urlencode(form).encode())
    return resp.status


def _cat_id(slug: str) -> int:
    rows = wp_json("term", "list", "category", f"--slug={slug}", "--fields=term_id", "--format=json")
    check(bool(rows), f"找不到分類 {slug}（請先執行 seed）")
    return int(rows[0]["term_id"])


@contract("wp01_editor_creates_and_edits_via_gateway", ["WP-01", "WP-08"], "editor")
def wp01(ctx: Context) -> str | None:
    s = ctx.editor_session()
    stamp = str(int(time.time()))

    # 媒體上傳（PNG 封面、WAV 音訊）。
    png = s.upload(f"contract-cover-{stamp}.png", make_png(16, 9), "image/png")
    check(png.status == 201, f"編輯者上傳 PNG 應 201，實得 {png.status}：{png.text[:200]}")
    media_id = ctx.track_post(png.json()["id"])
    s.rest("POST", f"/wp/v2/media/{media_id}", {"alt_text": "契約測試封面替代文字"})
    wav = s.upload(f"contract-audio-{stamp}.wav", make_wav(1.0), "audio/wav")
    check(wav.status == 201, f"編輯者上傳 WAV 應 201，實得 {wav.status}：{wav.text[:200]}")
    audio_id = ctx.track_post(wav.json()["id"])

    # 文章：建立 → 公開 API 可讀 → 修改 → 版本遞增 → 撤下 → 公開 API 404。
    content = "<!-- wp:paragraph -->\n<p>契約測試正文 sc-contract-wp01-marker</p>\n<!-- /wp:paragraph -->"
    created = s.rest("POST", "/wp/v2/posts", {
        "title": f"契約測試文章 {stamp}", "content": content, "status": "publish", "excerpt": "契約測試摘要",
        "categories": [_cat_id("sample-notes")], "featured_media": media_id, "acf": {"sc_is_sample": True},
    })
    check(created.status == 201, f"建立文章應 201，實得 {created.status}：{created.text[:300]}")
    post_id = ctx.track_post(created.json()["id"])
    check(created.json()["link"].startswith(ctx.cache["app_url"] + "/articles/"), f"文章連結應指向主站 /articles/：{created.json()['link']}")
    pub = public_get(f"/posts/{post_id}")
    check(pub.status == 200, f"公開 API 應讀得到新文章，實得 {pub.status}")
    item = pub.json()["data"]
    check(item["title"] == f"契約測試文章 {stamp}" and "sc-contract-wp01-marker" in item["body_html"], "公開 API 內容與編輯者輸入不符")
    check(item["is_sample"] is True and item["cover"] and item["cover"]["alt"] == "契約測試封面替代文字", "ACF 示例旗標或封面未寫入")
    v1 = item["content_version"]
    upd = s.rest("POST", f"/wp/v2/posts/{post_id}", {"title": f"契約測試文章（已修改）{stamp}"})
    check(upd.status == 200, f"修改文章應 200，實得 {upd.status}")
    item2 = public_get(f"/posts/{post_id}").json()["data"]
    check(item2["title"] == f"契約測試文章（已修改）{stamp}", "修改後標題未反映到公開 API")
    check(item2["content_version"] > v1, f"content_version 應遞增：{v1} → {item2['content_version']}")
    revs = s.rest("GET", f"/wp/v2/posts/{post_id}/revisions")
    check(revs.status == 200 and len(revs.json()) >= 1, "修改後應產生修訂紀錄")

    # 影片（ACF YouTube 網址、片長）。
    vid = s.rest("POST", "/wp/v2/sc_video", {
        "title": f"契約測試影片 {stamp}", "status": "publish", "content": "<!-- wp:paragraph --><p>影片介紹</p><!-- /wp:paragraph -->",
        "acf": {"sc_youtube_url": "https://youtu.be/Contract-V1", "sc_duration_seconds": 125, "sc_is_sample": True},
    })
    check(vid.status == 201, f"建立影片應 201，實得 {vid.status}：{vid.text[:300]}")
    vid_id = ctx.track_post(vid.json()["id"])
    v = public_get(f"/videos/{vid_id}").json()["data"]["video"]
    check(v == {"youtube_id": "Contract-V1", "youtube_url": "https://www.youtube.com/watch?v=Contract-V1", "duration_seconds": 125}, f"影片欄位不符：{v}")
    upd = s.rest("POST", f"/wp/v2/sc_video/{vid_id}", {"acf": {"sc_youtube_url": "https://www.youtube.com/watch?v=Contract-V2"}})
    check(upd.status == 200, f"修改影片應 200，實得 {upd.status}")
    check(public_get(f"/videos/{vid_id}").json()["data"]["video"]["youtube_id"] == "Contract-V2", "影片網址修改未反映")
    # ACF 在區塊編輯器的真實儲存路徑（meta-box-loader 表單）→ acf/save_post → 版本遞增與事件。
    before = public_get(f"/videos/{vid_id}").json()["data"]["content_version"]
    status = metabox_save(s, vid_id, {"field_sc_youtube_url": "https://www.youtube.com/watch?v=Contract-V4", "field_sc_video_duration": "300"})
    check(status in (200, 302), f"meta box 儲存應成功，實得 {status}")
    after = public_get(f"/videos/{vid_id}").json()["data"]
    check(after["video"]["youtube_id"] == "Contract-V4" and after["video"]["duration_seconds"] == 300, f"meta box 儲存的 ACF 值未反映：{after['video']}")
    check(after["content_version"] > before, "ACF 欄位變更應遞增 content_version")
    events = [e for e in wp_json("site-content", "outbox", "list", f"--content-id={vid_id}", "--format=json", "--fields=event_type,content_version")]
    check(any(e["event_type"] == "content.updated" and int(e["content_version"]) == after["content_version"] for e in events), "ACF 欄位變更應產生對應版本的 content.updated 事件")
    bad = s.rest("POST", "/wp/v2/sc_video", {"title": "非 YouTube", "status": "draft", "acf": {"sc_youtube_url": "https://evil.example/watch?v=Contract-V3"}})
    if bad.status == 201:
        ctx.track_post(bad.json()["id"])

    # Podcast（媒體庫音訊附件）。
    pod = s.rest("POST", "/wp/v2/sc_podcast", {
        "title": f"契約測試 Podcast {stamp}", "status": "publish", "featured_media": media_id,
        "acf": {"sc_audio_attachment": audio_id, "sc_duration_seconds": 1},
    })
    check(pod.status == 201, f"建立 Podcast 應 201，實得 {pod.status}：{pod.text[:300]}")
    pod_id = ctx.track_post(pod.json()["id"])
    p = public_get(f"/podcasts/{pod_id}").json()["data"]["podcast"]
    check(p is not None and p["audio_mime"] == "audio/wav" and p["audio_url"].endswith(".wav") and p["duration_seconds"] == 1, f"Podcast 音訊欄位不符：{p}")

    # 頁面。
    page = s.rest("POST", "/wp/v2/pages", {"title": f"契約測試頁面 {stamp}", "status": "publish", "content": "<!-- wp:paragraph --><p>頁面正文</p><!-- /wp:paragraph -->"})
    check(page.status == 201, f"建立頁面應 201，實得 {page.status}")
    page_id = ctx.track_post(page.json()["id"])
    check(page.json()["link"].startswith(ctx.cache["app_url"] + "/pages/"), "頁面連結應指向主站 /pages/")
    check(public_get(f"/pages/{page_id}").status == 200, "公開 API 應讀得到新頁面")
    edit = s.rest("POST", f"/wp/v2/pages/{page_id}", {"content": "<!-- wp:paragraph --><p>頁面正文（已修改）</p><!-- /wp:paragraph -->"})
    check(edit.status == 200 and "已修改" in public_get(f"/pages/{page_id}").json()["data"]["body_html"], "頁面修改未反映")

    # 撤下（API-15 的 WP 端）：轉草稿後列表、單篇、搜尋都消失，visibility=false。
    down = s.rest("POST", f"/wp/v2/posts/{post_id}", {"status": "draft"})
    check(down.status == 200, "撤下（轉草稿）應 200")
    check(public_get(f"/posts/{post_id}").status == 404, "撤下後單篇應 404")
    check(public_get("/search", {"q": "sc-contract-wp01-marker"}).json()["meta"]["total"] == 0, "撤下後搜尋不應有結果")
    check(public_get(f"/content/post/{post_id}/visibility").json()["data"]["visible"] is False, "撤下後 visibility 應為 false")
    return None


@contract("wp04_editor_privilege_escalation_denied", ["WP-04"], "editor")
def wp04(ctx: Context) -> str | None:
    s = ctx.editor_session()
    for path in ("/wp-admin/plugins.php", "/wp-admin/users.php", "/wp-admin/user-new.php", "/wp-admin/options-general.php",
                 "/wp-admin/options-general.php?page=mysite-brand", "/wp-admin/plugin-install.php", "/wp-admin/themes.php",
                 "/wp-admin/nav-menus.php", "/wp-admin/edit.php?post_type=acf-field-group"):
        r = s.request("GET", path)
        check(r.status == 403 or (r.status in (301, 302) and "wp-login" in r.header("location")), f"編輯者存取 {path} 應被拒，實得 {r.status}")
    checks = [
        ("GET", "/wp/v2/plugins", None), ("POST", "/wp/v2/plugins/advanced-custom-fields/acf", {"status": "inactive"}),
        ("POST", "/wp/v2/users", {"username": "contract-x", "email": "x@mysite.invalid", "password": "Contract-Password-123!"}),
        ("POST", "/wp/v2/settings", {"title": "contract"}), ("GET", "/wp/v2/settings", None),
        ("POST", "/wp/v2/users/me", {"roles": ["administrator"]}),
        ("POST", f"/wp/v2/users/{ctx.admin_session().user_id}", {"email": "x@mysite.invalid"}),
        ("DELETE", f"/wp/v2/users/{ctx.admin_session().user_id}", None),
    ]
    for method, route, payload in checks:
        r = s.rest(method, route, payload, params={"force": "true", "reassign": "1"} if method == "DELETE" else None)
        check(r.status in (401, 403), f"編輯者 {method} {route} 應 401／403，實得 {r.status}")
    raw_caps = json.loads(wp("user", "list-caps", "mysite-editor", "--format=json").stdout or "[]")
    caps = {c["name"] if isinstance(c, dict) else str(c) for c in raw_caps}
    for cap in ("manage_options", "activate_plugins", "install_plugins", "edit_plugins", "list_users", "create_users", "edit_users",
                "promote_users", "delete_users", "edit_theme_options", "site_content_read_preview", "site_content_diagnostics"):
        check(cap not in caps, f"編輯者不應有 {cap}")
    for cap in ("edit_sc_videos", "publish_sc_videos", "edit_others_sc_podcasts", "edit_posts", "publish_pages"):
        check(cap in caps, f"編輯者應有內容能力 {cap}")
    roles = wp("user", "get", "mysite-editor", "--field=roles").stdout.strip()
    check(roles == "editor", f"編輯者角色被改變：{roles}")
    return "Laravel 商務權限與 WP 帳號分離，不在本測試範圍"


@contract("wp08_rest_nonce_upload_and_app_password_not_blocked", ["WP-08", "INF-04"], "editor")
def wp08(ctx: Context) -> str | None:
    s = ctx.editor_session()
    me = s.rest("GET", "/wp/v2/users/me")
    check(me.status == 200, f"cookie＋nonce 的 REST 應 200，實得 {me.status}")
    no_nonce = s.rest("GET", "/wp/v2/users/me", use_nonce=False)
    # 沒有 nonce 時 WordPress 視為未登入；未登入者看不到 /wp/v2/users（mu-plugin 移除列舉路由）→ 401 或 404。
    check(no_nonce.status in (401, 404), f"沒有 nonce 的 cookie 請求應視為未登入，實得 {no_nonce.status}")
    bad_nonce = s.request("GET", "/wp-json/wp/v2/users/me", headers={"X-WP-Nonce": "0000000000"})
    check(bad_nonce.status == 403, f"錯誤 nonce 應 403，實得 {bad_nonce.status}")
    edit_screen = s.request("GET", "/wp-admin/post-new.php")
    check(edit_screen.status == 200 and "block-editor" in edit_screen.text, f"區塊編輯器頁面應可載入，實得 {edit_screen.status}")
    heartbeat = s.request("POST", "/wp-admin/admin-ajax.php", {"Content-Type": "application/x-www-form-urlencoded"}, b"action=heartbeat&_nonce=x&data=")
    check(heartbeat.status in (200, 400, 403), f"admin-ajax 不應被 gateway 擋（實得 {heartbeat.status}）")

    # 上傳白名單：允許的格式成功、危險或偽裝的檔案被拒。
    jpeg_like = make_png(4, 4)
    rejects = [
        ("shell.php", b"<?php echo 'x';", "application/x-php", "PHP 檔"),
        ("photo.php.png", jpeg_like, "image/png", "雙副檔名"),
        ("photo.png.php", jpeg_like, "image/png", "雙副檔名（php 結尾）"),
        ("fake.png", b"this is plain text, not an image", "image/png", "偽裝 MIME"),
        ("mismatch.jpg", jpeg_like, "image/jpeg", "內容與副檔名不符"),
        ("vector.svg", b"<svg xmlns='http://www.w3.org/2000/svg'><script>alert(1)</script></svg>", "image/svg+xml", "SVG"),
        ("polyglot.png", jpeg_like + b"<?php system($_GET['c']); ?>", "image/png", "含 PHP 片段"),
        ("page.html", b"<html><script>alert(1)</script></html>", "text/html", "HTML"),
    ]
    for name, data, ctype, label in rejects:
        r = s.upload(name, data, ctype)
        if r.status == 201:
            ctx.track_post(r.json()["id"])
        check(r.status >= 400, f"{label}（{name}）應被拒，實得 {r.status}")
    big = b"\x89PNG\r\n\x1a\n" + b"\x00" * (32 * 1024 * 1024 + 1024)
    r = s.upload("too-big.png", big, "image/png")
    if r.status == 201:
        ctx.track_post(r.json()["id"])
    check(r.status >= 400, f"超過 32MB 應被拒，實得 {r.status}")
    ok = s.upload("allowed.png", make_png(4, 4), "image/png")
    check(ok.status == 201, f"合法 PNG 應可上傳，實得 {ok.status}")
    ctx.track_post(ok.json()["id"])

    # uploads 目錄：腳本副檔名一律 403、不列目錄。
    for path in ("/wp-content/uploads/contract.php", "/wp-content/uploads/a.php.png", "/wp-content/uploads/x.phtml"):
        r = http_request(CMS_BASE, "GET", path, host_header=cms_host())
        check(r.status in (403, 404), f"{path} 不得執行（實得 {r.status}）")
        check("<?php" not in r.text, f"{path} 不應回傳 PHP 原始碼")
    listing = http_request(CMS_BASE, "GET", "/wp-content/uploads/", host_header=cms_host())
    check(listing.status in (403, 404) and "Index of" not in listing.text, "uploads 不得列目錄")

    # 服務帳號 Application Password（HTTPS）不被 Two Factor 或 gateway 擋；HTTP 不接受 Application Password。
    svc = internal_get("https://wp-web:8443/wp-json/wp/v2/users/me?context=edit")
    check(svc.status == 200 and svc.json().get("roles") == ["mysite_service"], f"服務帳號 HTTPS 認證應成功，實得 {svc.status}")
    plain = internal_get("http://wp-web:8080/wp-json/wp/v2/users/me")
    check(plain.status in (401, 404), f"HTTP 不應接受 Application Password，實得 {plain.status}")
    return None
