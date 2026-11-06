# API 契約（site-content/v1、內容事件、預覽票券）

本檔是 WordPress `site-content` 外掛與 Laravel 整合層的唯一共同規格。修改破壞相容性的欄位或語意須走 v2 並記 ADR。

- REST namespace：`site-content/v1`；實際 HTTP 路徑前綴 `/wp-json/site-content/v1`。
- `api_version`：`"v1"`；`model_version`：`1`（整數）。Laravel 只接受 `model_version === 1`，其他值 → `CONTENT_MODEL_UNSUPPORTED`（503）。
- 所有時間：UTC ISO 8601，格式 `YYYY-MM-DDTHH:MM:SSZ`（例如 `2026-11-05T01:23:45Z`）。
- 核准內容型別（`type`）：`post`、`page`、`video`、`podcast`。對應 WP post type：`post`、`page`、`sc_video`、`sc_podcast`。
- 公開條件（全部同時成立）：`post_status === 'publish'`、`post_password === ''`、型別在核准清單、非 `sc_exclude` 標記。

## 1. 共同回應結構

成功：
```json
{
  "data": [ ... ] 或 { ... },
  "meta": {
    "api_version": "v1",
    "model_version": 1,
    "generated_at": "2026-11-05T01:23:45Z",
    "page": 1, "per_page": 12, "total": 34, "total_pages": 3
  }
}
```
分頁欄位只在列表回應出現。

錯誤（HTTP 狀態對應）：
```json
{ "code": "site_content_not_found", "message": "找不到內容", "data": { "status": 404 } }
```
WP 端錯誤碼：`site_content_not_found`(404)、`site_content_invalid_param`(400)、`site_content_forbidden`(403)、`site_content_unauthorized`(401)、`site_content_preview_invalid`(403)。

## 2. 內容物件（ContentItem，model_version 1）

```json
{
  "id": 123,
  "type": "post",
  "slug": "sample-article",
  "title": "純文字標題（已去除 HTML）",
  "excerpt": "純文字摘要",
  "body_html": "<p>經 WP 端 wp_kses_post 與渲染後的區塊 HTML</p>",
  "author": { "display_name": "公開作者名稱" },
  "categories": [ { "id": 3, "slug": "news", "name": "新聞" } ],
  "tags": [ { "id": 7, "slug": "demo", "name": "示例" } ],
  "cover": { "url": "https://cms.example/wp-content/uploads/2026/11/a.png", "alt": "替代文字", "width": 1200, "height": 630 } 或 null,
  "published_at": "2026-11-01T02:00:00Z",
  "modified_at": "2026-11-02T03:00:00Z",
  "content_version": 5,
  "is_sample": true,
  "video": { "youtube_id": "dQw4w9WgXcQ", "youtube_url": "https://www.youtube.com/watch?v=dQw4w9WgXcQ", "duration_seconds": 245 } 或 null,
  "podcast": { "audio_url": "https://cms.example/wp-content/uploads/2026/11/ep1.wav", "audio_mime": "audio/wav", "duration_seconds": 1800 } 或 null
}
```
- 列表回應的 item 不含 `body_html`（欄位省略），其餘相同。
- `video` 只在 `type=video` 出現；`podcast` 只在 `type=podcast` 出現。
- 絕不回傳：使用者 email、登入名稱、密碼 hash、內部路徑、post_password、草稿正文、私人統計。
- `is_sample`：內容是否為示例（ACF 欄位 `sc_is_sample` 或 meta）；Laravel 顯示「示例內容」標示。
- `content_version`：外掛維護的單調遞增整數（每次觀察到內容或狀態改變 +1）。

## 3. 端點

| 方法與路徑 | 權限 | 參數與行為 |
|---|---|---|
| `GET /health` | 公開 | 回 `{"data":{"status":"ok"},"meta":{...}}`；DB 不可用回 503。不含版本與秘密。 |
| `GET /posts` | 公開 | 列表；參數見 §3.1 |
| `GET /posts/{id}` | 公開 | 單篇（含 `body_html`）；不存在或非公開 → 404 |
| `GET /pages`、`GET /videos`、`GET /podcasts` | 公開 | 同 `/posts` 列表參數；另支援 `slug` 精確查詢 |
| `GET /content/{type}/{id}/visibility` | 公開 | `{"data":{"type":"post","id":123,"visible":true,"content_version":5,"checked_at":"..."}}`；不存在或非公開時 **仍回 200** 且 `visible:false`、`content_version` 為目前值或 0；不回標題或正文。 |
| `GET /taxonomies` | 公開 | `{"data":{"categories":[{"id","slug","name","count"}],"tags":[...]}}`；`count` 只計公開內容 |
| `GET /menus/{location}` | 公開 | location ∈ `primary`、`footer`；`{"data":{"location":"primary","items":[{"label":"文章","url":"/articles","external":false}]}}`。內部連結一律轉為主站相對路徑；外部連結只允許 `https:`。 |
| `GET /site` | 公開 | 品牌最小集（v1 相容擴充）：`{"data":{"name":"mysite","tagline":"…","author_bio":"純文字","social_links":[{"label":"YouTube","url":"https://…"}],"logo":{url,alt,width,height}或 null,"is_sample":true}}`；只回 https 社群連結 |
| `GET /search` | 公開 | `q`（必填，1–100 字元）、`type`（可選，核准型別）、`page`、`per_page`；只搜公開內容的標題、摘要、正文 |
| `GET /preview/{type}/{id}` | 服務帳號（Application Password，僅 HTTPS）＋票券 | header `X-Mysite-Preview-Ticket: <ticket>`；參數 `snapshot=<sid>`。驗證見 §5；回 `{"data": ContentItem(含 body_html，preview:true, snapshot_id, revision_id), "meta":{...}}` |

### 3.1 列表參數

| 參數 | 規則 |
|---|---|
| `page` | 整數 ≥ 1，預設 1；> 1000 → 400 |
| `per_page` | 整數 1–50，預設 12；超出 → 400（不靜默截斷） |
| `slug` | `[a-z0-9-]{1,190}`（WP 可能含 URL 編碼的中文 slug：接受 `[%a-z0-9-]`，長度 ≤ 200）；有 `slug` 時回單筆陣列或 404 |
| `category`、`tag` | slug；不存在 → 回空列表 |
| `orderby` | `date`（預設）或 `title` |
| `order` | `desc`（預設）或 `asc` |
| 其他未知參數 | 忽略 |

## 4. 內容事件（WP → Laravel）

`POST {CONTENT_EVENT_URL}`（Laravel：`/internal/content-events/v1`）

Header：
- `Content-Type: application/json`
- `X-Mysite-Key-Id: <key id>`（例：`k1`）
- `X-Mysite-Timestamp: <delivery unix seconds>`（每次送達重新產生）
- `X-Mysite-Signature: <hex(HMAC-SHA256(key, timestamp + "\n" + event_id + "\n" + raw_body))>`
- `X-Mysite-Event-Id: <event_id>`（與 body 相同，供快速去重）

Body（UTF-8 JSON，原始位元組被簽署，重試時**位元組完全不變**）：
```json
{
  "event_id": "uuid v4",
  "event_type": "content.published | content.updated | content.unpublished | content.deleted",
  "content_type": "post | page | video | podcast",
  "content_id": 123,
  "content_version": 6,
  "status": "publish | draft | pending | private | future | trash | deleted",
  "occurred_at": "2026-11-05T01:23:45Z"
}
```

Laravel 回應：
| 情境 | HTTP | body |
|---|---|---|
| 首次有效事件，已持久保存 | 202 | `{"status":"accepted"}` |
| 同 ID 同內容重送 | 202 | `{"status":"duplicate"}` |
| 同 ID 不同內容 | 409 | `{"error":"EVENT_CONFLICT"}`（記錄告警） |
| 簽章錯、key ID 未知、時差 > 300 秒、缺 header | 401 | `{"error":"EVENT_SIGNATURE_INVALID"}` |
| body 結構不合法 | 422 | `{"error":"EVENT_INVALID"}` |

WP outbox 視 2xx 為送達；401／409／422 為不可重試（直接進死信）；其他（含連線失敗、5xx、429）以指數退避重試，上限 10 次後進死信。

## 5. 預覽票券

格式：`base64url(header) "." base64url(claims) "." base64url(HMAC-SHA256(PREVIEW_SIGNING_KEY, base64url(header) "." base64url(claims)))`，無 padding。

header 固定：`{"alg":"HS256","typ":"mysite-preview"}`。

claims：
```json
{
  "iss": "mysite-cms",
  "aud": "mysite-app",
  "typ": "preview",
  "ctype": "post",
  "cid": 123,
  "sid": "32 字元 hex 快照 ID",
  "rev": 456,
  "iat": 1730770000,
  "exp": 1730770600,
  "jti": "32 字元 hex",
  "sub": 7
}
```
- `exp - iat` ≤ `PREVIEW_TTL_SECONDS`（預設 600，硬上限 900）；任一端收到超過 900 秒的票券即拒絕。
- 流程：WP 編輯者在編輯器「主站預覽」→ WP `admin-post.php?action=site_content_preview&post=<id>&_wpnonce=...` → 驗 nonce 與 `edit_post` 權限 → 要求內容已保存（以最新 revision／autosave 為準；有未保存變更時 UI 先提示保存）→ 建立快照（正文、ACF 欄位、封面同一份 JSON，存 `{prefix}site_content_preview_snapshots`）→ 簽發票券 → 302 至 `{MYSITE_APP_URL}/preview/exchange?ticket=<ticket>`。
- Laravel `/preview/exchange`：驗簽、`iss/aud/typ/exp/ttl`；在 DB 以 `jti` 唯一鍵原子寫入 `preview_ticket_uses`（第二次寫入失敗即拒絕）；建立 preview session（隨機 ID，存 hash），設 HttpOnly cookie `mysite_preview`（path `/preview`，SameSite=Lax，正式 Secure），**清除網址參數後** 302 至 `/preview/view`。
- Laravel `/preview/view`：以 preview session 取得票券，呼叫 WP `GET /preview/{ctype}/{cid}?snapshot={sid}`（Basic auth 服務帳號 Application Password，HTTPS＋本案 CA 驗證）並帶 `X-Mysite-Preview-Ticket`。
- WP 端驗證：簽章、`iss/aud/typ/exp`、path 的 type/id 與 claims 一致、`snapshot` 參數與 `sid` 一致、快照存在且 `post_id/revision_id` 與 claims 一致、內容未被刪除、服務帳號具 `site_content_read_preview` 能力、票券核發者 `sub` 仍具該內容 `edit_post` 權限。任一不符 → 403 `site_content_preview_invalid`。
- 預覽回應：`Cache-Control: private, no-store`、`X-Robots-Tag: noindex, nofollow`、`Referrer-Policy: no-referrer`；不載入第三方資源；不寫入公開快取、RSS、sitemap、搜尋；log 不記票券。
- 預覽金鑰與事件 HMAC 金鑰不同（不同秘密檔）。

## 6. Laravel 錯誤碼

| 錯誤碼 | HTTP | 使用時機 |
|---|---:|---|
| `CONTENT_NOT_FOUND` | 404 | WP 回 404 或可見性為 false |
| `CONTENT_UPSTREAM_UNAVAILABLE` | 503 | 連線失敗、逾時、5xx、invalid JSON、可見性證明過期且無法重新確認 |
| `CONTENT_MODEL_UNSUPPORTED` | 503 | `model_version` 不是 1 或缺必要欄位 |
| `PREVIEW_INVALID` | 403 | 票券或 preview session 不合法、過期、重播、跨內容 |
| `EVENT_SIGNATURE_INVALID` | 401 | 事件驗簽失敗 |
| `RATE_LIMITED` | 429 | 超過限流 |
| `COMMERCE_DISABLED` | 404 | `COMMERCE_MODE=disabled` 時所有商店路由 |
| `SIMULATION_ONLY` | 422 | 嘗試真實交易語意（例如 real 模式或付款欄位） |
| `SEARCH_INVALID` | 422 | 公開唯讀 API `/api/v1/search` 的 `q` 超過 100 字或 `type` 不正確（2026-11-06 新增，MySite React 前端專案；訊息為具體原因，與 Blade 搜尋頁相同，見 `docs/公開API契約.md`） |
| `HTTP_{狀態碼}` | 該狀態碼 | 框架層的錯誤（例如 `HTTP_405` 方法不符、`HTTP_500` 未預期例外、`HTTP_503` 維護模式），由 `ErrorRenderer` 統一產生（既有行為，2026-11-06 補記）。公開 API 的路由層 404 一律改用 `CONTENT_NOT_FOUND` |

公開錯誤頁顯示正體中文訊息與隨機 request ID，不含 stack trace、路徑或上游細節。

## 7. v1 相容擴充（2026-11-05，WP 工作包實作後補記；皆為新增，不破壞既有欄位語意）

1. 以 `slug` 查詢且回單筆時，item 含 `body_html`（列表一般查詢仍不含）。
2. 新增 `GET /pages/{id}`、`GET /videos/{id}`、`GET /podcasts/{id}`（與 `/posts/{id}` 相同規則）。
3. 選單項目可能帶 `children`（同結構的子項陣列）；不支援巢狀的用戶端可忽略。
4. `/taxonomies` 只列出「公開內容數量大於 0」的分類與標籤。
5. 影片 YouTube ID 或 Podcast 音訊來源不合法時，`video`／`podcast` 為 `null`；Laravel 顯示播放器失效狀態並保留簡介。
6. 已刪除內容的 `visibility` 回 `visible:false` 與最後一個 `content_version`。
7. 只有「公開狀態相關」的變化才產生事件（發布、公開內容修改、撤下、刪除）；草稿之間的修改不發事件。
8. 錯誤的 Application Password 會被 WordPress 視為匿名請求（核心認證順序），私密預覽端點仍回 403 `site_content_preview_invalid`。

### 7.1 金鑰圈與 key ID 過渡（2026-11-05，GAP-KEYRING-01；皆為新增，不改 §4、§5 既有欄位語意）

9. 事件 HMAC 與預覽簽章各自有一個金鑰圈（用途分離，不共用金鑰）。金鑰圈每把 key 有 key ID（格式 `k[0-9]{1,6}`）與狀態：`active`（發送端以它簽章，接收端也接受）或 `accept`（只供接收端驗證）。任何時刻恰好一把 `active`。沒有金鑰圈的部署沿用單一金鑰：事件以 `CONTENT_EVENT_KEY_ID`（預設 `k1`）、預覽以 `k1` 視為唯一一把 `active`。
10. 內容事件（§4）：WP 一律以事件金鑰圈的 `active` key 簽章，並在既有的 `X-Mysite-Key-Id` 送出該 key 的 ID（標頭名稱不變）。Laravel 依 `X-Mysite-Key-Id` 在金鑰圈中找金鑰，`active` 或 `accept` 皆可驗證；key ID 不在金鑰圈（含已 retire 的舊 key）→ 401 `EVENT_SIGNATURE_INVALID`（即 §4 的「key ID 未知」）。`X-Mysite-Key-Id` 仍為必填，缺少時同 §4 回 401。
11. 預覽票券（§5）：header 新增可選欄位 `kid`，WP 新簽發的票券一律帶 `active` 預覽金鑰的 ID：`{"alg":"HS256","typ":"mysite-preview","kid":"k2"}`。驗證端（Laravel `/preview/exchange`、WP 私密端點 `GET /preview/{type}/{id}`）依 `kid` 找金鑰（`active` 或 `accept`）；`kid` 不在金鑰圈、格式不合法、不是字串，或 header 出現 `alg`／`typ`／`kid` 以外的欄位 → Laravel 403 `PREVIEW_INVALID`、WP 403 `site_content_preview_invalid`。沒有 `kid` 的舊格式票券只以 `active` key 驗證（不嘗試 `accept` key）。
12. 輪替三期（`scripts/ops/rotate-secrets.sh --kind=event-hmac|preview`）：add（新 key 以 `accept` 加入，接收端先認得）→ promote（新 key 改為 `active` 開始簽章，舊 key 改為 `accept`，在途事件與已核發票券仍可驗證）→ retire（舊 key 移出金鑰圈，之後以它簽的事件與票券一律被拒）。每期先重建接收端（Laravel）再重建發送端（WP）。舊 key 不無期限接受：promote 後須在期限內 retire（見 `docs/runbooks/秘密輪替.md`）。

## 8. 公開唯讀 API（2026-11-06，MySite React 前端專案 F1／F2）

主站另開給前端容器使用的公開唯讀 JSON API `/api/v1/*`，契約正本為 `app/resources/contracts/public-api-v1.openapi.json`（OpenAPI 3.0.3），說明見 `docs/公開API契約.md`。它沿用本文件的內容物件與錯誤碼語意；新增的錯誤碼只有 §6 的 `SEARCH_INVALID`，另會回 §6 的 `HTTP_405`、`HTTP_500`、`HTTP_503`。
