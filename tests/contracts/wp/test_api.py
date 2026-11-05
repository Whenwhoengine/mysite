"""公開 REST 契約：結構、參數界限、敏感欄位、非公開狀態、區塊語意、隱私頁、欄位來源、示例資料。"""

from __future__ import annotations

import json
import re
import time

from wpct_lib import (APP_BASE, NS, UTC_RE, Context, check, contract, internal_get, public_get, secret, wp,
                      wp_create_post, wp_eval_json, wp_json, ROOT)

ITEM_KEYS = ["id", "type", "slug", "title", "excerpt", "author", "categories", "tags", "cover",
             "published_at", "modified_at", "content_version", "is_sample"]
COLLECTIONS = {"posts": "post", "pages": "page", "videos": "video", "podcasts": "podcast"}


def _meta(meta: dict, paged: bool) -> None:
    check(meta.get("api_version") == "v1", f"meta.api_version 應為 v1：{meta.get('api_version')}")
    check(meta.get("model_version") == 1 and isinstance(meta.get("model_version"), int), "meta.model_version 應為整數 1")
    check(isinstance(meta.get("generated_at"), str) and UTC_RE.match(meta["generated_at"]) is not None, "meta.generated_at 不是 UTC 格式")
    if paged:
        for key in ("page", "per_page", "total", "total_pages"):
            check(isinstance(meta.get(key), int), f"列表 meta.{key} 應為整數")
    else:
        check("total" not in meta and "page" not in meta, "非列表回應不應有分頁欄位")


def _item(item: dict, with_body: bool, expected_type: str | None = None) -> None:
    keys = list(item.keys())
    expected = list(ITEM_KEYS)
    if with_body:
        expected.insert(5, "body_html")
    if item.get("type") == "video":
        expected.append("video")
    if item.get("type") == "podcast":
        expected.append("podcast")
    extra = [k for k in ("preview", "snapshot_id", "revision_id") if k in keys]
    check(keys == expected + extra, f"ContentItem 欄位或順序不符：{keys}")
    if expected_type:
        check(item["type"] == expected_type, f"type 應為 {expected_type}：{item['type']}")
    check(isinstance(item["id"], int) and item["id"] > 0, "id 應為正整數")
    for key in ("slug", "title", "excerpt"):
        check(isinstance(item[key], str), f"{key} 應為字串")
    check("<" not in item["title"] and "<" not in item["excerpt"], "title／excerpt 必須是純文字")
    check(list(item["author"].keys()) == ["display_name"], f"author 只能有 display_name：{item['author']}")
    for tax in ("categories", "tags"):
        for term in item[tax]:
            check(list(term.keys()) == ["id", "slug", "name"], f"{tax} 欄位不符：{term}")
    if item["cover"] is not None:
        check(list(item["cover"].keys()) == ["url", "alt", "width", "height"], f"cover 欄位不符：{item['cover']}")
        check(isinstance(item["cover"]["width"], int) and isinstance(item["cover"]["height"], int), "cover 寬高應為整數")
    check(UTC_RE.match(item["published_at"] or "") is not None, f"published_at 不是 UTC：{item['published_at']}")
    check(UTC_RE.match(item["modified_at"] or "") is not None, f"modified_at 不是 UTC：{item['modified_at']}")
    check(isinstance(item["content_version"], int) and item["content_version"] >= 0, "content_version 應為非負整數")
    check(isinstance(item["is_sample"], bool), "is_sample 應為布林")
    if "video" in item and item["video"] is not None:
        v = item["video"]
        check(list(v.keys()) == ["youtube_id", "youtube_url", "duration_seconds"], f"video 欄位不符：{v}")
        check(re.fullmatch(r"[A-Za-z0-9_-]{11}", v["youtube_id"]) is not None, "youtube_id 格式不符")
        check(v["youtube_url"] == "https://www.youtube.com/watch?v=" + v["youtube_id"], "youtube_url 應為正規化網址")
    if "podcast" in item and item["podcast"] is not None:
        p = item["podcast"]
        check(list(p.keys()) == ["audio_url", "audio_mime", "duration_seconds"], f"podcast 欄位不符：{p}")
        check(p["audio_mime"] in ("audio/mpeg", "audio/mp4", "audio/wav", "audio/ogg"), f"audio_mime 不在核准清單：{p['audio_mime']}")
    if with_body:
        check(isinstance(item["body_html"], str) and "<script" not in item["body_html"].lower(), "body_html 不得含 script")


def _error(resp, status: int, code: str) -> None:
    check(resp.status == status, f"{code}：預期 HTTP {status}，實得 {resp.status}")
    body = resp.json()
    check(body.get("code") == code and body.get("data", {}).get("status") == status, f"錯誤格式不符：{body}")
    check(isinstance(body.get("message"), str) and body["message"], "錯誤應有訊息")


@contract("api01_structure_and_full_paths", ["API-01"], "api")
def api01(ctx: Context) -> str | None:
    health = public_get("/health")
    check(health.status == 200 and health.json()["data"] == {"status": "ok"}, "health 應回 {status: ok}")
    _meta(health.json()["meta"], paged=False)
    check("version" not in health.text.replace("api_version", "").replace("model_version", ""), "health 不得洩露版本")
    first: dict[str, dict] = {}
    for coll, ctype in COLLECTIONS.items():
        resp = public_get("/" + coll, {"per_page": 5})
        check(resp.status == 200, f"/{coll} 應回 200，實得 {resp.status}")
        body = resp.json()
        check(list(body.keys()) == ["data", "meta"], f"/{coll} 外框應只有 data、meta")
        _meta(body["meta"], paged=True)
        check(body["meta"]["per_page"] == 5 and body["meta"]["page"] == 1, "分頁 meta 不符")
        check(len(body["data"]) >= 1, f"/{coll} 應有示例內容（請先執行 seed）")
        for item in body["data"]:
            _item(item, with_body=False, expected_type=ctype)
        first[coll] = body["data"][0]
        detail = public_get(f"/{coll}/{body['data'][0]['id']}")
        check(detail.status == 200, f"/{coll}/{{id}} 應回 200")
        _item(detail.json()["data"], with_body=True, expected_type=ctype)
        _meta(detail.json()["meta"], paged=False)
        by_slug = public_get("/" + coll, {"slug": body["data"][0]["slug"]})
        check(by_slug.status == 200 and len(by_slug.json()["data"]) == 1, "slug 精確查詢應回單筆陣列")
        _item(by_slug.json()["data"][0], with_body=True, expected_type=ctype)
    vid = first["videos"]
    check(vid.get("video") is not None, "示例影片應有 video 物件")
    pod = first["podcasts"]
    check(pod.get("podcast") is not None, "示例 Podcast 應有 podcast 物件")
    check("video" not in first["posts"] and "podcast" not in first["posts"], "文章不應有 video／podcast 欄位")

    # 型別雙重確認：用文章 ID 讀 /pages/{id} 應 404。
    _error(public_get(f"/pages/{first['posts']['id']}"), 404, "site_content_not_found")
    _error(public_get("/posts/999999999"), 404, "site_content_not_found")
    _error(public_get("/posts", {"slug": "no-such-slug-contract"}), 404, "site_content_not_found")

    vis = public_get(f"/content/post/{first['posts']['id']}/visibility")
    data = vis.json()["data"]
    check(list(data.keys()) == ["type", "id", "visible", "content_version", "checked_at"], f"visibility 欄位不符：{data}")
    check(data["visible"] is True and data["content_version"] == first["posts"]["content_version"], "公開文章 visibility 應為 true 且版本一致")
    gone = public_get("/content/post/999999999/visibility")
    check(gone.status == 200 and gone.json()["data"]["visible"] is False and gone.json()["data"]["content_version"] == 0, "不存在內容應回 200 visible:false 版本 0")

    tax = public_get("/taxonomies").json()["data"]
    check(set(tax.keys()) == {"categories", "tags"}, "taxonomies 應有 categories、tags")
    for term in tax["categories"] + tax["tags"]:
        check(list(term.keys()) == ["id", "slug", "name", "count"] and term["count"] > 0, f"taxonomy 欄位或 count 不符：{term}")
    for loc in ("primary", "footer"):
        menu = public_get(f"/menus/{loc}").json()["data"]
        check(menu["location"] == loc and isinstance(menu["items"], list), f"選單 {loc} 結構不符")
        for it in menu["items"]:
            check({"label", "url", "external"} <= set(it.keys()), f"選單項目欄位不符：{it}")
            check(it["url"].startswith("/") if not it["external"] else it["url"].startswith("https://"), f"選單網址不合規：{it}")
    site = public_get("/site").json()["data"]
    check(list(site.keys()) == ["name", "tagline", "author_bio", "social_links", "logo", "is_sample"], f"site 欄位不符：{list(site)}")
    check(all(link["url"].startswith("https://") for link in site["social_links"]), "社群連結只能是 https")
    search = public_get("/search", {"q": "示例"})
    check(search.status == 200 and search.json()["meta"]["total"] >= 1, "搜尋「示例」應有結果")
    for item in search.json()["data"]:
        _item(item, with_body=False)

    # 不允許的方法：直接拒絕。
    from wpct_lib import CMS_BASE, cms_host, http_request
    post = http_request(CMS_BASE, "POST", NS + "/posts", host_header=cms_host(), body=b"{}", headers={"Content-Type": "application/json"})
    check(post.status in (404, 405), f"POST /posts 應被拒，實得 {post.status}")

    # Laravel 實際使用的容器內完整路徑（http://wp-web:8080/wp-json/site-content/v1）。
    internal = internal_get("http://wp-web:8080/wp-json/site-content/v1/posts?per_page=1", auth="none")
    check(internal.status == 200 and internal.json()["meta"]["api_version"] == "v1", f"容器內完整路徑應可讀取，實得 {internal.status}")
    return None


@contract("api03_parameter_bounds", ["API-03"], "api")
def api03(ctx: Context) -> str | None:
    bad = [
        ("/posts", {"per_page": "51"}), ("/posts", {"per_page": "0"}), ("/posts", {"per_page": "-1"}),
        ("/posts", {"per_page": "abc"}), ("/posts", {"per_page": "1.5"}), ("/posts", {"per_page": "012"}),
        ("/posts", {"per_page": "1000000000000000000000"}), ("/posts", {"page": "0"}), ("/posts", {"page": "1001"}),
        ("/posts", {"page": "x"}), ("/posts", {"slug": "Bad_Slug"}), ("/posts", {"slug": "a" * 201}),
        ("/posts", {"slug": "../etc"}), ("/posts", {"category": "UPPER"}), ("/posts", {"tag": "a b"}),
        ("/posts", {"orderby": "rand"}), ("/posts", {"order": "sideways"}), ("/videos", {"per_page": "100"}),
        ("/pages", {"page": "1001"}), ("/podcasts", {"per_page": "51"}),
        ("/search", {}), ("/search", {"q": ""}), ("/search", {"q": "   "}), ("/search", {"q": "字" * 101}),
        ("/search", {"q": "ok", "type": "event"}), ("/search", {"q": "ok", "per_page": "51"}),
        ("/menus/sidebar", None), ("/content/event/1/visibility", None), ("/content/post/abc/visibility", None),
        ("/content/post/0/visibility", None), ("/posts/abc", None), ("/posts/0", None), ("/posts/-5", None),
    ]
    for path, params in bad:
        resp = public_get(path, params)
        check(resp.status == 400, f"{path} {params} 應回 400，實得 {resp.status}")
        check(resp.json().get("code") == "site_content_invalid_param", f"{path} {params} 錯誤碼應為 site_content_invalid_param")
    ok = public_get("/posts", {"per_page": "50", "page": "1", "unknown_param": "ignored", "orderby": "title", "order": "asc"})
    check(ok.status == 200 and ok.json()["meta"]["per_page"] == 50, "per_page=50 與未知參數應被接受")
    check(len(ok.json()["data"]) <= 50, "回傳筆數不得超過 per_page")
    beyond = public_get("/posts", {"page": "1000"})
    check(beyond.status == 200 and beyond.json()["data"] == [], "超出總頁數的合法 page 應回空列表")
    empty_cat = public_get("/posts", {"category": "no-such-category-contract"})
    check(empty_cat.status == 200 and empty_cat.json()["data"] == [], "不存在的分類應回空列表")
    q100 = public_get("/search", {"q": "字" * 100})
    check(q100.status == 200, "100 字的 q 應被接受")
    return None


def _all_public_bodies(extra_paths: list[str]) -> list[tuple[str, str]]:
    bodies: list[tuple[str, str]] = []
    for coll in COLLECTIONS:
        page = 1
        while True:
            resp = public_get("/" + coll, {"per_page": 50, "page": page})
            bodies.append((f"/{coll}?page={page}", resp.text))
            data = resp.json()
            for item in data["data"]:
                detail = public_get(f"/{coll}/{item['id']}")
                bodies.append((f"/{coll}/{item['id']}", detail.text))
            if page >= data["meta"]["total_pages"]:
                break
            page += 1
    for path in ["/taxonomies", "/menus/primary", "/menus/footer", "/site", "/health"] + extra_paths:
        bodies.append((path, public_get(path).text))
    bodies.append(("/search?q=示例", public_get("/search", {"q": "示例", "per_page": 50}).text))
    return bodies


@contract("api14_no_sensitive_fields", ["API-14"], "api")
def api14(ctx: Context) -> str | None:
    forbidden = ["@mysite.invalid", "mysite-admin", "mysite-service", "user_email", "user_login", "user_pass",
                 "post_password", "/var/www", "/run/secrets", "mysite_wp", "DB_PASSWORD", "wp-config", "\"email\"",
                 "\"password\"", "PHP_VERSION", "php_version"]
    secrets = []
    for name in ("content_event_hmac_key", "preview_signing_key", "wp_service_app_password", "diagnostics_token"):
        try:
            secrets.append(secret(name))
        except OSError:
            pass
    pw_posts = wp_json("post", "list", "--post_type=post", "--post_status=publish", "--has_password=1", "--fields=ID", "--format=json")
    passwords = [wp("post", "get", str(p["ID"]), "--field=post_password").stdout.strip() for p in pw_posts]
    bodies = _all_public_bodies(["/content/post/1/visibility"])
    for path, text in bodies:
        lower = text.lower()
        for needle in forbidden:
            check(needle.lower() not in lower, f"{path} 出現禁止字串：{needle}")
        for value in secrets + [p for p in passwords if p]:
            check(value not in text, f"{path} 出現秘密或文章密碼")
        check("mysite-editor" not in text, f"{path} 出現登入名稱")
    return f"掃描 {len(bodies)} 個公開回應"


@contract("wp03_non_public_states_hidden", ["WP-03"], "api")
def wp03(ctx: Context) -> str | None:
    hidden = wp_json("post", "list", "--post_type=post", "--post_status=draft,private,publish,trash,future,pending",
                     "--meta_key=_sc_seed_key", "--fields=ID,post_name,post_status,post_password", "--format=json", "--posts_per_page=100")
    keys = {"sample-hidden-draft": "draft", "sample-hidden-private": "private", "sample-hidden-password": "password",
            "sample-hidden-trash__trashed": "trash", "sample-scheduled-future": "future"}
    targets = {row["post_name"]: int(row["ID"]) for row in hidden if row["post_name"] in keys}
    check(set(targets) == set(keys), f"缺少 seed 的非公開測試資料：{sorted(set(keys) - set(targets))}")

    # 另建：待審、sc_exclude 已發布、只被草稿使用的分類（count 與列出都不可洩漏）。
    pending = wp_create_post(ctx, "--post_type=post", "--post_status=pending", "--post_title=契約測試待審 sc-contract-pending-marker",
                             "--post_content=sc-contract-pending-marker")
    excluded = wp_create_post(ctx, "--post_type=sc_video", "--post_status=publish", "--post_title=契約測試排除影片 sc-contract-exclude-marker",
                              "--post_content=sc-contract-exclude-marker", "--meta_input={\"sc_exclude\":\"1\",\"sc_youtube_url\":\"https://youtu.be/Contract-01\"}")
    term = wp("term", "create", "category", "契約測試私人分類", "--slug=sc-contract-private-cat", "--porcelain").stdout.strip()
    ctx.created_terms.append(("category", int(term)))
    ctx.persist()
    wp("post", "term", "set", str(targets["sample-hidden-draft"]), "category", "sc-contract-private-cat", "sample-notes", "--by=slug")
    targets["pending"] = pending
    targets["excluded"] = excluded

    all_ids: set[int] = set()
    for coll in COLLECTIONS:
        page = 1
        while True:
            data = public_get("/" + coll, {"per_page": 50, "page": page}).json()
            all_ids.update(item["id"] for item in data["data"])
            if page >= data["meta"]["total_pages"]:
                break
            page += 1
    for name, pid in targets.items():
        check(pid not in all_ids, f"{name}（#{pid}）出現在公開列表")
        for coll in COLLECTIONS:
            r = public_get(f"/{coll}/{pid}")
            check(r.status == 404, f"{name} 經 /{coll}/{{id}} 應 404，實得 {r.status}")
        ctype = "video" if name == "excluded" else "post"
        vis = public_get(f"/content/{ctype}/{pid}/visibility").json()["data"]
        check(vis["visible"] is False, f"{name} visibility 應為 false")
        check(set(vis.keys()) == {"type", "id", "visible", "content_version", "checked_at"}, "visibility 不得回標題或正文")
    for slug in ("sample-hidden-draft", "sample-hidden-private", "sample-hidden-password", "sample-hidden-trash", "sample-scheduled-future"):
        check(public_get("/posts", {"slug": slug}).status == 404, f"slug {slug} 應 404")
    for marker in ("sc-hidden-draft-marker", "sc-hidden-private-marker", "sc-hidden-password-marker", "sc-hidden-trash-marker",
                   "sc-hidden-future-marker", "sc-contract-pending-marker", "sc-contract-exclude-marker"):
        r = public_get("/search", {"q": marker})
        check(r.status == 200 and r.json()["meta"]["total"] == 0, f"搜尋 {marker} 不應有結果")
    tax = public_get("/taxonomies").json()["data"]
    check(all(t["slug"] != "sc-contract-private-cat" for t in tax["categories"]), "只被草稿使用的分類不得出現在 taxonomies")
    check(public_get("/posts", {"category": "sc-contract-private-cat"}).json()["data"] == [], "私人分類篩選應回空列表")
    # 恢復 seed 草稿原本的分類（只移除本測試加上的詞彙）。
    wp("post", "term", "remove", str(targets["sample-hidden-draft"]), "category", "sc-contract-private-cat", "--by=slug")
    return None


@contract("wp06_gutenberg_block_semantics", ["WP-06"], "api")
def wp06(ctx: Context) -> str | None:
    resp = public_get("/posts", {"slug": "sample-gutenberg-blocks"})
    check(resp.status == 200, "找不到 Gutenberg 驗收文章（請先執行 seed）")
    html = resp.json()["data"][0]["body_html"]
    required = {
        "段落": r"<p[ >]", "H2": r"<h2[ >]", "H3": r"<h3[ >]", "圖片": r"<figure class=\"wp-block-image[^\"]*\"><img [^>]*src=\"https?://",
        "圖說": r"<figcaption[^>]*>示例圖說", "引用": r"<blockquote[^>]*>[\s\S]*<cite>", "無序列表": r"<ul[^>]*>\s*<li>",
        "有序列表": r"<ol[^>]*>\s*<li>", "連結": r"<a href=\"https://example\.com/\">", "分隔線": r"<hr[ />]",
        "程式碼": r"<pre class=\"wp-block-code\"><code>&lt;\?php", "表格": r"<table[^>]*><thead><tr><th>",
        "封面": r"wp-block-cover[\s\S]*<img [^>]*wp-block-cover__image-background",
    }
    for label, pattern in required.items():
        check(re.search(pattern, html) is not None, f"body_html 缺少「{label}」語意")
    for bad in ("<script", "onerror=", "onclick=", "javascript:", "<iframe"):
        check(bad not in html.lower(), f"body_html 不得含 {bad}")
    return None


@contract("dat04_privacy_page_reflects_behaviour", ["DAT-04"], "api")
def dat04(ctx: Context) -> str | None:
    resp = public_get("/pages", {"slug": "privacy"})
    check(resp.status == 200, "找不到隱私說明頁（slug=privacy）")
    html = resp.json()["data"][0]["body_html"]
    for phrase in ("youtube-nocookie.com", "點擊播放後", "CMS", "mysite_app_session", "XSRF-TOKEN", "mysite_preview",
                   "登入 cookie", "不使用任何網站分析", "信用卡號", "真實地址"):
        check(phrase in html, f"隱私說明缺少：{phrase}")
    check(resp.json()["data"][0]["is_sample"] is True, "seed 的隱私頁應標示示例內容")
    return "WP 端隱私頁內容；主站顯示與正式聯絡方式由委託人確認"


@contract("wp02_types_fields_tables_rebuildable", ["WP-02"], "api")
def wp02(ctx: Context) -> str | None:
    info = wp_eval_json(r"""
$out = array('types' => array(), 'groups' => array(), 'tables' => array(), 'db_version' => get_option('site_content_db_version'), 'acf_cpt' => array());
foreach (array('sc_video','sc_podcast') as $t) {
  $o = get_post_type_object($t);
  $out['types'][$t] = $o ? array('rest' => (bool) $o->show_in_rest, 'cap' => $o->cap->edit_posts, 'tax' => get_object_taxonomies($t), 'map' => (bool) $o->map_meta_cap) : null;
}
if (function_exists('acf_get_field_groups')) {
  foreach (acf_get_field_groups() as $g) {
    $fields = array_map(fn($f) => $f['name'], acf_get_fields($g));
    $out['groups'][$g['key']] = array('local' => $g['local'] ?? '', 'fields' => $fields, 'id' => $g['ID'] ?? 0);
  }
}
global $wpdb;
foreach (array('outbox','index','preview_snapshots') as $t) { $out['tables'][$t] = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . 'site_content_' . $t)); }
$out['acf_cpt'] = function_exists('acf_get_internal_post_type_posts') ? count(acf_get_internal_post_type_posts('acf-post-type')) : 0;
echo wp_json_encode($out);
""")
    for t in ("sc_video", "sc_podcast"):
        ti = info["types"].get(t)
        check(ti is not None and ti["rest"] and ti["map"], f"{t} 未註冊或未開 REST／map_meta_cap")
        check("category" in ti["tax"] and "post_tag" in ti["tax"], f"{t} 應共用 category 與 post_tag")
        check(ti["cap"] == ("edit_sc_videos" if t == "sc_video" else "edit_sc_podcasts"), f"{t} capability_type 不符：{ti['cap']}")
    expected = {"group_sc_video": {"sc_youtube_url", "sc_duration_seconds"},
                "group_sc_podcast": {"sc_audio_attachment", "sc_audio_url", "sc_duration_seconds"},
                "group_sc_common": {"sc_is_sample", "sc_exclude"}}
    for key, fields in expected.items():
        g = info["groups"].get(key)
        check(g is not None, f"ACF 欄位群組 {key} 未載入")
        check(g["local"] == "json" and not g["id"], f"{key} 應只來自 Local JSON（不在資料庫）：{g}")
        check(set(g["fields"]) == fields, f"{key} 欄位不符：{g['fields']}")
    check(all(info["tables"].values()), f"外掛資料表缺漏：{info['tables']}")
    check(info["db_version"] == "1", "site_content_db_version 應為 1")
    check(info["acf_cpt"] == 0, "ACF 不得另行註冊 CPT")
    # setup 冪等：再跑一次不出錯、資料表仍在。
    wp("site-content", "setup")
    return None


@contract("wp09_acf_version_traceable", ["WP-09"], "api")
def wp09(ctx: Context) -> str | None:
    versions = (ROOT / "infra" / "versions.env").read_text(encoding="utf-8")
    m = re.search(r"^ACF_VERSION=(\S+)$", versions, re.M)
    check(m is not None, "infra/versions.env 缺少 ACF_VERSION")
    got = wp("plugin", "get", "advanced-custom-fields", "--field=version").stdout.strip()
    status = wp("plugin", "get", "advanced-custom-fields", "--field=status").stdout.strip()
    check(got == m.group(1), f"執行中的 ACF 版本 {got} 與 versions.env {m.group(1)} 不符")
    check(status == "active", "ACF 應為啟用狀態")
    check(re.search(r"^ACF_ZIP_SHA256=[0-9a-f]{64}$", versions, re.M) is not None, "versions.env 應鎖定 ACF zip SHA-256")
    return "版本一致；zip hash 於映像建置時由 Dockerfile sha256sum 驗證"


@contract("seed_idempotent_and_refused_in_production", ["CLD-05"], "api")
def seed(ctx: Context) -> str | None:
    def counts() -> tuple[int, int]:
        posts = int(wp("post", "list", "--post_type=post,page,sc_video,sc_podcast,attachment,nav_menu_item",
                       "--post_status=any,trash", "--format=count").stdout.strip() or 0)
        events = int(wp("site-content", "outbox", "list", "--format=count", "--limit=1000").stdout.strip() or 0)
        return posts, events
    wp("site-content", "seed")
    before = counts()
    second = wp("site-content", "seed").stdout
    check("新建 0、" in second, f"第二次 seed 不應新建任何資料：{second[-200:]}")
    after = counts()
    check(before == after, f"重跑 seed 改變了內容或事件數量：{before} → {after}")
    for env in ("production", "staging"):
        res = wp("site-content", "seed", check_ok=False, extra_env={"WP_ENVIRONMENT_TYPE": env})
        check(res.code != 0, f"{env} 環境不得允許 seed")
        check("拒絕" in res.stderr or "拒絕" in res.stdout, f"{env} 拒絕訊息不明確")
    check(counts() == after, "被拒的 seed 不得寫入資料")
    return None
