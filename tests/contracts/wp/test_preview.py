"""草稿預覽：編輯者預覽入口 → 票券；服務帳號（HTTPS＋Application Password）讀快照；各種不合法票券被拒。"""

from __future__ import annotations

import time
import urllib.parse

from wpct_lib import (HEX32_RE, Context, TestFailure, check, contract, forge_ticket, internal_get, make_png, public_get,
                      signing_key, ticket_parts, ticket_valid_signature, wp)

PRIVATE = "https://wp-web:8443/wp-json/site-content/v1/preview"


def _issue(ctx: Context, post_id: int, rest_base: str) -> tuple[str, dict]:
    """以編輯者 cookie 走真實預覽入口：autosave 取得 preview_link → admin-post → 302 到主站交換頁。"""
    s = ctx.editor_session()
    auto = s.rest("POST", f"/wp/v2/{rest_base}/{post_id}/autosaves", {"title": ctx.cache.get("preview_title", ""), "content": ctx.cache.get("preview_content", "")})
    check(auto.status in (200, 201), f"autosave 應成功，實得 {auto.status}：{auto.text[:200]}")
    link = auto.json().get("preview_link", "")
    check("admin-post.php" in link and "action=site_content_preview" in link and "_wpnonce=" in link, f"preview_link 應指向本外掛入口：{link[:120]}")
    path = urllib.parse.urlsplit(link)
    resp = s.request("GET", path.path + "?" + path.query)
    check(resp.status == 302, f"預覽入口應 302，實得 {resp.status}")
    loc = resp.header("location")
    check(loc.startswith(ctx.cache["app_url"] + "/preview/exchange?ticket="), f"應導向主站交換頁：{loc[:80]}")
    check("no-referrer" in resp.header_all("referrer-policy"), f"預覽入口應送 Referrer-Policy: no-referrer：{resp.header_all('referrer-policy')}")
    ctx.cache.setdefault("referrer_policy_seen", resp.header_all("referrer-policy"))
    check("no-store" in resp.header("cache-control"), "預覽入口應 no-store")
    ticket = urllib.parse.parse_qs(urllib.parse.urlsplit(loc).query)["ticket"][0]
    head, claims, _ = ticket_parts(ticket)
    return ticket, claims


def _get(ctype: str, cid: int, sid: str, ticket: str | None, base: str = PRIVATE, auth: str = "service"):
    return internal_get(f"{base}/{ctype}/{cid}?snapshot={sid}", auth=auth, ticket=ticket)


def _flip_signature(ticket: str) -> str:
    head, body, sig = ticket.split(".")
    return f"{head}.{body}.{('B' if sig[0] == 'A' else 'A') + sig[1:]}"


def _rejected(resp, label: str) -> None:
    check(resp.status in (401, 403), f"{label}：應被拒，實得 {resp.status}")
    if resp.status == 403:
        check(resp.json().get("code") == "site_content_preview_invalid", f"{label}：錯誤碼應為 site_content_preview_invalid")


@contract("preview_ticket_snapshot_and_rejections", ["API-11", "API-12", "API-13"], "preview")
def preview(ctx: Context) -> str | None:
    s = ctx.editor_session()
    stamp = str(int(time.time()))
    m1 = s.upload(f"preview-a-{stamp}.png", make_png(8, 8, (200, 40, 40)), "image/png")
    m2 = s.upload(f"preview-b-{stamp}.png", make_png(8, 8, (40, 200, 40)), "image/png")
    check(m1.status == 201 and m2.status == 201, "預覽測試媒體上傳失敗")
    m1_id, m2_id = ctx.track_post(m1.json()["id"]), ctx.track_post(m2.json()["id"])
    created = s.rest("POST", "/wp/v2/sc_video", {
        "title": f"預覽草稿 sc-preview-marker-{stamp}", "status": "draft", "featured_media": m1_id,
        "content": "<!-- wp:paragraph --><p>預覽正文 A</p><!-- /wp:paragraph -->",
        "acf": {"sc_youtube_url": "https://youtu.be/PreviewAAAA", "sc_duration_seconds": 60},
    })
    check(created.status == 201, f"建立草稿影片失敗：{created.status}")
    vid = ctx.track_post(created.json()["id"])
    ctx.cache["preview_title"] = f"預覽草稿 A sc-preview-marker-{stamp}"
    ctx.cache["preview_content"] = "<!-- wp:paragraph --><p>預覽正文 A（autosave）</p><!-- /wp:paragraph -->"
    ticket, claims = _issue(ctx, vid, "sc_video")

    # 票券格式（docs/API契約.md §5）。
    head, _, _ = ticket_parts(ticket)
    # 契約 §7 擴充（GAP-KEYRING-01）：header 帶 active 預覽金鑰的 kid（沒有金鑰圈時為 k1）
    check(head == {"alg": "HS256", "typ": "mysite-preview", "kid": signing_key("preview_signing")[0]}, f"票券 header 不符：{head}")
    check(list(claims.keys()) == ["iss", "aud", "typ", "ctype", "cid", "sid", "rev", "iat", "exp", "jti", "sub"], f"claims 欄位或順序不符：{list(claims)}")
    check(claims["iss"] == "mysite-cms" and claims["aud"] == "mysite-app" and claims["typ"] == "preview", "iss／aud／typ 不符")
    check(claims["ctype"] == "video" and claims["cid"] == vid and claims["sub"] == s.user_id, "ctype／cid／sub 不符")
    check(HEX32_RE.match(claims["sid"]) is not None and HEX32_RE.match(claims["jti"]) is not None, "sid／jti 應為 32 字元 hex")
    check(isinstance(claims["rev"], int) and claims["rev"] > 0, "rev 應為正整數")
    check(0 < claims["exp"] - claims["iat"] <= 900 and abs(claims["iat"] - time.time()) < 120, "iat／exp 不合理或超過 900 秒")
    check(ticket_valid_signature(ticket), "票券 HMAC 簽章驗證失敗")
    check("=" not in ticket, "票券不得含 base64 padding")

    ok = _get("video", vid, claims["sid"], ticket)
    check(ok.status == 200, f"合法票券應 200，實得 {ok.status}：{ok.body[:200]}")
    data = ok.json()["data"]
    check(data["preview"] is True and data["snapshot_id"] == claims["sid"] and data["revision_id"] == claims["rev"], "預覽回應的 preview／snapshot_id／revision_id 不符")
    check(data["title"] == ctx.cache["preview_title"] and "預覽正文 A（autosave）" in data["body_html"], "快照應包含 autosave 的標題與正文")
    check(data["video"]["youtube_id"] == "PreviewAAAA" and data["cover"]["url"].endswith(".png"), "快照應包含 ACF 欄位與封面")
    cover_a = data["cover"]["url"]
    check(ok.headers.get("cache-control") == "private, no-store", f"預覽回應 Cache-Control 應為 private, no-store：{ok.headers.get('cache-control')}")
    check("noindex" in ok.headers.get("x-robots-tag", "") and "no-referrer" in ok.headers.get("referrer-policy", "").split(", "), "預覽回應缺少 noindex 或 no-referrer")
    ctx.cache["preview_referrer_policy"] = ok.headers.get("referrer-policy", "")
    replay = _get("video", vid, claims["sid"], ticket)
    check(replay.status == 200, "WP 端不限制同 jti 重複讀取（單次使用由 Laravel 交換端負責）")

    # API-12：之後修改正文、ACF 與封面，舊快照不變；新預覽三者一起更新。
    upd = s.rest("POST", f"/wp/v2/sc_video/{vid}", {"title": f"預覽草稿 B sc-preview-marker-{stamp}", "featured_media": m2_id,
                                                     "content": "<!-- wp:paragraph --><p>預覽正文 B</p><!-- /wp:paragraph -->",
                                                     "acf": {"sc_youtube_url": "https://youtu.be/PreviewBBBB"}})
    check(upd.status == 200, "修改草稿失敗")
    old = _get("video", vid, claims["sid"], ticket).json()["data"]
    check(old["title"] == ctx.cache["preview_title"] and old["video"]["youtube_id"] == "PreviewAAAA" and old["cover"]["url"] == cover_a, "舊快照被後續修改影響（混合版本）")
    ctx.cache["preview_title"] = f"預覽草稿 B sc-preview-marker-{stamp}"
    ctx.cache["preview_content"] = "<!-- wp:paragraph --><p>預覽正文 B</p><!-- /wp:paragraph -->"
    ticket_b, claims_b = _issue(ctx, vid, "sc_video")
    new = _get("video", vid, claims_b["sid"], ticket_b).json()["data"]
    check(new["title"].startswith("預覽草稿 B") and "預覽正文 B" in new["body_html"] and new["video"]["youtube_id"] == "PreviewBBBB" and new["cover"]["url"] != cover_a,
          "新快照的正文、ACF 與封面應來自同一版本")
    check(claims_b["sid"] != claims["sid"] and claims_b["jti"] != claims["jti"], "每次預覽應有新的 sid 與 jti")

    # API-13：預覽內容不進公開列表與搜尋。
    check(public_get(f"/videos/{vid}").status == 404, "草稿不得出現在公開單篇")
    check(public_get("/search", {"q": f"sc-preview-marker-{stamp}"}).json()["meta"]["total"] == 0, "草稿不得出現在公開搜尋")

    # API-11：各種不合法情境。
    other = s.rest("POST", "/wp/v2/sc_video", {"title": "預覽跨內容對照", "status": "draft", "acf": {"sc_youtube_url": "https://youtu.be/PreviewCCCC"}})
    other_id = ctx.track_post(other.json()["id"])
    now = int(time.time())
    base = dict(claims_b)
    cases = {
        "沒有票券": (("video", vid, claims_b["sid"], None), {}),
        "竄改 claims": (("video", vid, claims_b["sid"], ticket_b.split(".")[0] + "." + forge_ticket({**base, "cid": other_id}).split(".")[1] + "." + ticket_b.split(".")[2]), {}),
        "竄改簽章": (("video", vid, claims_b["sid"], _flip_signature(ticket_b)), {}),
        "錯誤金鑰": (("video", vid, claims_b["sid"], forge_ticket(base, key="not-the-real-key")), {}),
        "已過期": (("video", vid, claims_b["sid"], forge_ticket({**base, "iat": now - 1000, "exp": now - 400})), {}),
        "TTL 超過 900 秒": (("video", vid, claims_b["sid"], forge_ticket({**base, "iat": now, "exp": now + 1000})), {}),
        "iat 在未來": (("video", vid, claims_b["sid"], forge_ticket({**base, "iat": now + 600, "exp": now + 900})), {}),
        "aud 不符": (("video", vid, claims_b["sid"], forge_ticket({**base, "aud": "someone-else"})), {}),
        "iss 不符": (("video", vid, claims_b["sid"], forge_ticket({**base, "iss": "attacker"})), {}),
        "header alg 不符": (("video", vid, claims_b["sid"], forge_ticket(base, header={"alg": "none", "typ": "mysite-preview"})), {}),
        "跨內容（路徑 ID）": (("video", other_id, claims_b["sid"], ticket_b), {}),
        "跨型別": (("post", vid, claims_b["sid"], ticket_b), {}),
        "snapshot 參數不符": (("video", vid, claims["sid"], ticket_b), {}),
        "錯 revision": (("video", vid, claims_b["sid"], forge_ticket({**base, "rev": base["rev"] + 1})), {}),
        "核發者不可編輯": (("video", vid, claims_b["sid"], forge_ticket({**base, "sub": int(wp("user", "get", "mysite-service", "--field=ID").stdout.strip())})), {}),
        "核發者不存在": (("video", vid, claims_b["sid"], forge_ticket({**base, "sub": 999999})), {}),
        "非 HTTPS": (("video", vid, claims_b["sid"], ticket_b), {"base": "http://wp-web:8080/wp-json/site-content/v1/preview"}),
        "沒有服務帳號": (("video", vid, claims_b["sid"], ticket_b), {"auth": "none"}),
        "錯誤密碼": (("video", vid, claims_b["sid"], ticket_b), {"auth": "bad"}),
    }
    for label, (args, kw) in cases.items():
        _rejected(_get(*args, **kw), label)
    nf = _get("video", vid, "0" * 32, forge_ticket({**base, "sid": "0" * 32}))
    _rejected(nf, "快照不存在")

    # 核發者沒有（或已失去）編輯權限：快照與票券都以不可編輯的帳號（服務帳號）為核發者，簽章與欄位全部一致，仍必須拒絕。
    service_id = int(wp("user", "get", "mysite-service", "--field=ID").stdout.strip())
    issued = wp("eval", f"$p = get_post({vid}); $snap = SiteContent\\Preview::create_snapshot($p, {service_id}); "
                        f"echo SiteContent\\Ticket::issue('video', {vid}, $snap['snapshot_id'], $snap['revision_id'], {service_id});").stdout.strip()
    _, svc_claims, _ = ticket_parts(issued)
    check(svc_claims["sub"] == service_id and ticket_valid_signature(issued), "核發者測試票券格式不符")
    _rejected(_get("video", vid, svc_claims["sid"], issued), "核發者不可編輯該內容")

    # 內容刪除（垃圾桶）後拒絕。
    trash = s.rest("DELETE", f"/wp/v2/sc_video/{vid}")
    check(trash.status == 200, "移到垃圾桶失敗")
    _rejected(_get("video", vid, claims_b["sid"], ticket_b), "內容已刪除")
    return "WP 端不消耗 jti；重播防護由 Laravel /preview/exchange 原子寫入 jti 負責（整合者端到端驗收）"
