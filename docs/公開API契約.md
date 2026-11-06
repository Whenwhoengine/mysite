# 公開唯讀 API 契約（/api/v1）

```
狀態: F1 定稿（首次交付）
日期: 2026-11-06
依據: docs/MySiteReact前端專案v1.md §5、§6.3～§6.5、§7 F1；docs/MySiteReact前端專案v2.md；docs/MySiteReact前端專案定案執行.md §4.3、§4.4、§4.9、M-10、M-18
契約正本: app/resources/contracts/public-api-v1.openapi.json（OpenAPI 3.0.3）
樣本集: app/resources/contracts/public-api-v1/samples/（manifest.json 列出每一個樣本）
實作: app/routes/api.php、app/app/Http/Controllers/Api/V1/
```

這份文件給人看；機器以契約正本為準。前端儲存庫（MySiteStorage）保存契約與樣本集的鎖定副本，只能由施工者同步（v1 §5.7）。

---

## 1. 原則

1. 只有 GET（HEAD 隨 GET 提供）；其他方法（含 OPTIONS 預檢）回 405（`HTTP_405`，帶 `Allow: GET, HEAD`）。經「`/index.php/api/…`」進來的請求一律 404：這種寫法會被 Laravel 當成 `/api/…`，可繞過依路徑前綴設的規則，所以 gateway、app-web、Laravel 三層都擋（全域中介層 `PublicApiHeaders`）。
2. 路由不經 web 群組：沒有 Session、CSRF，回應不帶 `Set-Cookie`；一律 `Cache-Control: no-store`，並帶 `X-Robots-Tag: noindex, nofollow`——路由不存在、方法不符、維護模式的回應也一樣。不提供跨來源讀取：框架預設對 `api/*` 開放所有來源，本站以 `app/config/cors.php` 關閉（定案 C-12）。
3. 走與 Blade 公開頁同一條路：`ContentQueryService` → `ContentRepository` → `WordPressRestClient`。可見性證明、淨化、錯誤分類全部重用，不另寫讀 WordPress 的路；沿用既有的內容快取與可見性租約（撤下保證不變），不另加任何快取。
4. 只回公開內容與畫面需要的欄位。不輸出內部 ID、WordPress 的 api slug、內容版本、預覽或快照資訊、服務帳號、email、內部診斷（PAPI-05）。
5. 時間一律 UTC，格式 `YYYY-MM-DDTHH:MM:SSZ`（ADR-012）。前端以 `formatTaipeiDate()` 換成臺北時間顯示。
6. 富文字只有 `content_html` 一個欄位，內容是唯一淨化器 `Sanitizer` 的輸出。前端只能經 `SafeHtml` 輸出，不再淨化、也不自行組 HTML。
7. 媒體一律經 `App\Domain\Content\Media\MediaPolicy`（Blade 元件與 API 共用同一份，PAPI-06）。不合政策的值輸出 null，並以 `video_unavailable`、`audio_unavailable` 標示；不合政策的圖片整個輸出 null（前端顯示佔位，與 Blade 相同）。
8. 參數規則與 Blade 公開頁共用 `PublicContentInput`；對不合法參數的狀態碼與錯誤訊息兩邊一致（PAPI-07）。
9. 頁面中繼資料由 `PageMetaFactory` 統一產生，Blade 與 API 的 SEO 結果相同。
10. 不裝 Sanctum、不需要認證（v1 §5.1 第 7 點）。
11. 破壞性變更另開 `/api/v2`；`/api/v1` 只做相容的新增。

## 2. 存取範圍

| 環境 | 外部經 gateway 存取 `/api/` | 前端容器 |
|---|---|---|
| production | 一律 404（gateway 兩份範本的主站區塊 `location ^~ /api/`） | 經內部專用網路直連 app-web（F6） |
| staging | 目前同 production（404）。D5 核准開放（只放行 gateway 固定位址、gateway 每 IP 限流），在 F7 依裁定另加 | 同上 |
| development／testing | 同 production（404） | 同上 |

- app-web 對前端網段只放行 `/api/v1/`（含擋下 `/internal/`），在 F6 compose 齊備後驗收（PAPI-08，定案 M-06）。
- 搜尋不在 Laravel 限流：next 模式下 Laravel 看到的來源是前端容器，每 IP 限流移到 gateway（D13a、FE-08）。超限時 gateway 回正體中文 HTML 429 頁，不是 JSON。

## 3. 端點

| 端點 | 用途 | 參數 | 可能狀態 |
|---|---|---|---|
| `GET /api/v1/site` | 網站資訊、主選單、頁尾選單、商店是否開啟 | 無 | 200（WordPress 讀不到時 `fallback: true`，用內建後備，不回 503）；只有 Laravel 維護模式（換版）時回 503（`HTTP_503`） |
| `GET /api/v1/home` | 首頁區塊：精選 1 筆、文章 6 筆、影片 4 筆、Podcast 4 筆 | 無 | 200、503 |
| `GET /api/v1/articles`、`/videos`、`/podcasts` | 列表，每頁 12 筆 | `page` | 200、404、503 |
| `GET /api/v1/articles/{slug}`、`/videos/{slug}`、`/podcasts/{slug}` | 單篇 | — | 200、404、503 |
| `GET /api/v1/pages/{slug}` | 一般頁面 | — | 200、404、503 |
| `GET /api/v1/categories/{slug}`、`/tags/{slug}` | 分類、標籤：不帶 `type` 為總覽（各型別最新 6 筆）；帶 `type` 為分頁列表 | `type`（post、video、podcast）、`page`（只在帶 `type` 時） | 200、404、503 |
| `GET /api/v1/search` | 站內搜尋 | `q`（最長 100 字）、`type`（post、video、podcast、page）、`page` | 200、404、422、503 |

參數規則（與 Blade 相同）：
- `page`：`^[1-9]\d{0,3}$` 且不超過 1000；其他值、以及超過總頁數，一律 404。
- `slug`：路由接受 1～200 個非斜線字元（`[^/]{1,200}`），超過回 404。能對應到內容的 slug 還要符合 WordPress 格式（`app/app/Domain/Content/Support/Slug.php`）：小寫英數與連字號（`[a-z0-9-]`）最長 190 字元，含大寫一律不存在；中文 slug 以小寫百分比編碼後最長 200 字元（一個中文字編碼後 9 字元，約 22 個中文字）。不符合的一律 404、不送往上游，與 Blade 公開頁相同（現行行為，本案不改）。
- `q`：去頭尾空白、合併連續空白後計算長度；超過 100 字回 422（`SEARCH_INVALID`）。`q` 為空時不查詢上游，`searched: false`。
- `type`（搜尋）：不認得的值回 422（`SEARCH_INVALID`）；`type`（分類／標籤）：不認得的值回 404。分類／標籤總覽帶 `page` 回 404。

## 4. 回應形狀

完整定義見契約正本的 `components.schemas`。重點：

| 物件 | 欄位 |
|---|---|
| 摘要 `ContentSummary` | `type`、`slug`、`path`、`title`、`excerpt`（純文字）、`published_at`、`modified_at`、`author_name`、`cover`、`categories[]`、`tags[]`、`duration_seconds`（影片或 Podcast 片長，其他為 null）、`is_sample` |
| 單篇 `ContentDetail` | 摘要＋`content_html`（沒有內文時為空字串）、`video`（只在影片）、`podcast`（只在 Podcast） |
| 影片 `Video` | `youtube_id`、`watch_url`、`embed_url`（`youtube-nocookie.com`，點擊才載入）、`duration_seconds`、`video_unavailable` |
| Podcast `Podcast` | `audio_url`、`audio_mime`、`duration_seconds`、`audio_unavailable` |
| 圖片 `Image` | `url`、`alt`、`width`、`height` |
| 分類／標籤 `Term` | `slug`、`name`、`path`、`count` |
| 頁面中繼資料 `Seo` | `title`、`description`、`canonical_path`、`noindex`、`og_type`、`og_image`、`og_image_alt` |
| 網站 `Site` | `name`、`tagline`、`author_bio`（純文字）、`social_links[]`、`logo`、`is_sample`、`fallback`、`menus.primary[]`、`menus.footer[]`、`commerce_enabled` |
| 分頁 `Pagination` | `page`、`per_page`、`total`、`total_pages` |
| 錯誤 `Error` | `error`、`message`、`request_id`（32 位小寫十六進位） |

前端組 `<head>` 的規則（比照 `app/resources/views/layouts/app.blade.php`）：
- `<title>`：`seo.title` 為空或等於站名時只用站名，否則「`seo.title`｜站名」。站名取 `/api/v1/site` 的 `name`；`/site` 取不到時用前端環境變數的站名。
- canonical 與 `og:url`：前端以「主站公開網址」環境變數＋`seo.canonical_path` 組成，不讀請求的 Host 標頭。
- `noindex` 為 true 時 `<meta name="robots" content="noindex, nofollow">`（搜尋頁）。
- `og_image` 不為 null 時輸出 `og:image`、`og:image:alt`（有值時）與 `twitter:card=summary_large_image`，否則 `summary`。

### 4.1 與 v1 §5.3 草案的差異（F1 定稿）

| 草案 | 定稿 | 理由 |
|---|---|---|
| `author_bio_html` | `author_bio`（純文字） | 現行 WordPress 欄位是純文字，頁尾以跳脫後輸出（`app/resources/views/layouts/partials/footer.blade.php`）；不新增 HTML 欄位 |
| `video.youtube_url` | `watch_url`、`embed_url`（由驗證過的 ID 產生） | 網址樣式屬政策，集中在 `MediaPolicy`，前端不自己拼 |
| `seo.canonical_path`、Open Graph 欄位 | 照草案；另有 `title`、`description`、`noindex`、`og_type` | 前端 `<head>` 逐項比照 Blade 版型 |
| 未列 | 摘要加 `duration_seconds` | 卡片顯示片長（`app/resources/views/components/content-card.blade.php`） |
| 未列 | `Site.fallback` | 讓前端知道是內建後備，不影響畫面 |
| 未列 | 列表加 `collection`（型別標題與引言） | 標題與引言是 Laravel 現有字串（`ContentType`），不在前端另寫一份 |
| 未列 | 分類／標籤回應以 `mode` 區分總覽與列表 | 同一路徑兩種畫面（`app/resources/views/content/taxonomy.blade.php`） |
| 未列 | 搜尋回應加 `searched` | 區分「還沒查」與「查無結果」 |
| 未列 | `Term` 加 `count`（只有分類／標籤彙整的主角帶值，內容身上的分類與標籤為 null） | 彙整頁顯示「共 N 則公開內容」（`app/resources/views/content/taxonomy.blade.php`） |
| 未列 | `SocialLink` 為 `label`、`url`（只收 https） | 照現行 WordPress 欄位與 `ContentMapper` 的過濾規則 |

## 5. 錯誤碼

| 錯誤碼 | HTTP | 何時 |
|---|---:|---|
| `CONTENT_NOT_FOUND` | 404 | 內容不存在或不公開、slug 或 page 不合法、page 超過總頁數、不存在的 API 路徑 |
| `CONTENT_UPSTREAM_UNAVAILABLE` | 503 | WordPress 連線失敗、逾時、5xx、回應不是 JSON，或可見性證明過期且無法重新確認；絕不回舊正文 |
| `CONTENT_MODEL_UNSUPPORTED` | 503 | WordPress 回應格式不符 |
| `SEARCH_INVALID` | 422 | 搜尋 `q` 超過 100 字或 `type` 不正確；`message` 是具體原因，與 Blade 搜尋頁顯示的文字相同。**本案新增**，已補進 `docs/API契約.md` §6 |
| `HTTP_405` | 405 | 非 GET |
| `HTTP_500` | 500 | 未預期例外（只回固定訊息與請求編號） |
| `HTTP_503` | 503 | Laravel 維護模式 |

錯誤回應只有 `error`、`message`、`request_id` 三個欄位，不含堆疊、路徑或上游細節。`request_id` 與回應標頭、Laravel 紀錄同一個值；前端轉送的 `X-Request-Id` 符合 `^[a-f0-9]{32}$` 時沿用（`AssignRequestId`）。

## 6. 畫面欄位對照表（F1 第 4 步、M-18①）

讓委託人不必看技術細節也能確認沒漏：每一種頁型畫面要顯示的東西，對應到契約哪個欄位。

| 頁型 | 畫面要顯示 | 契約欄位 |
|---|---|---|
| 全站頁首 | 站名、主選單、商店連結（開啟時）、頁首搜尋框 | `site.name`、`site.menus.primary[]`、`site.commerce_enabled` |
| 全站頁尾 | 站名、「示例內容」標示、標語、作者簡介、頁尾選單、社群連結、年份 | `site.name`、`site.is_sample`、`site.tagline`、`site.author_bio`、`site.menus.footer[]`、`site.social_links[]`（年份由前端以臺北時間產生） |
| 首頁 | 主視覺標題與標語、示例標示、精選、文章、影片、Podcast 區塊 | `site.name`、`site.tagline`、`site.is_sample`、`home.data.featured`、`posts[]`、`videos[]`、`podcasts[]` |
| 內容卡片（各列表共用） | 封面（或佔位）、型別、示例標示、標題連結、摘要、日期、作者、片長 | 摘要的 `cover`、`type`、`is_sample`、`title`、`path`、`excerpt`、`published_at`、`author_name`、`duration_seconds` |
| 文章／影片／Podcast 列表 | 列表標題與引言、卡片、分頁（第 N 頁，共 M 頁；共 K 則） | `collection.title`、`collection.lede`、`data[]`、`meta.page`、`meta.total_pages`、`meta.total` |
| 文章單篇 | 型別、示例標示、標題、引言、日期、更新日期（晚於發布 1 天以上才顯示）、作者、封面、正文、分類、標籤、麵包屑 | `type`、`is_sample`、`title`、`excerpt`、`published_at`、`modified_at`、`author_name`、`cover`、`content_html`、`categories[]`、`tags[]`、`collection` 字串由型別對照 |
| 影片單篇 | 上列＋播放器（海報、點擊才載入）、片長、失效狀態 | `video.watch_url`、`video.embed_url`、`video.duration_seconds`、`video.video_unavailable`、海報用 `cover` |
| Podcast 單篇 | 上列＋播放器（`preload="none"`）、片長、下載連結、失效狀態 | `podcast.audio_url`、`podcast.audio_mime`、`podcast.duration_seconds`、`podcast.audio_unavailable`、封面 `cover` |
| 一般頁面 | 標題、封面、正文 | `title`、`cover`、`content_html` |
| 分類／標籤總覽 | 「分類」或「標籤」字樣、名稱、共幾則、型別分頁籤、各型別最新 6 筆與「查看全部 N 則」 | `data.label`、`data.term.name`、`data.term.count`、`data.overview.{post,video,podcast}.items[]`、`.total`、`.per_page` |
| 分類／標籤指定型別 | 上列＋卡片與分頁 | `data.type`、`data.items[]`、`meta` |
| 搜尋 | 搜尋表單（保留查詢詞與型別）、結果摘要（找到 N 則）、結果、零結果「找不到符合」、錯誤訊息 | `data.q`、`data.type`、`data.searched`、`data.items[]`、`meta.total`；錯誤時 422 的 `message` |
| 404／503／500 | 標題、說明、請求編號、回首頁 | 錯誤回應的 `message`、`request_id`；版面取 `/api/v1/site`，取不到用內建靜態頁首頁尾（定案 §4.9） |
| 每一頁 `<head>` | title、description、canonical、og 系列、robots、RSS | `seo.*`、`site.name`（RSS 連結固定 `/feed.xml`） |

覆蓋檢查：v1 §6.2 列的頁型（首頁、三種列表、三種單篇、一般頁面、分類、標籤、搜尋、404、503、500）都在表內。

## 7. 樣本集（M-18②）

- 位置：`app/resources/contracts/public-api-v1/samples/`，`manifest.json` 列出每個樣本的 operation、狀態碼與用途。
- 樣本檢查的是「回應形狀」。`articles.show.slug200` 的 200 字元 slug 形狀合法，但真實 API 的單篇查詢只接受 190 字元以內的英數 slug（第 3 節），所以這份樣本只用來測前端組網址與版面，真實資料不會出現。
- 圖片 `url`、`og_image`、`audio_url` 在真實 API 一律是政策核准的絕對網址；假資料改寫成站內相對路徑 `/mock-media/…`（M-19）。契約對這三個欄位不限定絕對網址，前端兩種都要能處理；日後若要加限制，須同步調整假資料轉換層。
- **有效** 53 個：每個端點至少一個正常樣本；每個端點都附 503 樣本（`/api/v1/site` 的 503 只在維護模式）；除 `/api/v1/site` 與首頁外都附 404 樣本；搜尋附 422；文章、影片、Podcast 列表、分類指定型別、標籤總覽都附空列表樣本；邊界案例有超長中文標題、超長網址、無封面、零篇列表、影片 ID 不合法、音訊不可播、沒有內文、中文 slug（百分比編碼）、200 字 slug、WordPress 讀不到時的網站後備。
- **無效** 16 個：多出內部 id、多出 email、時間不是 UTC、canonical 不是站內路徑、缺正文欄位、影片 ID 不合政策、嵌入網址不是 youtube-nocookie、音訊 MIME 不在白名單、列表缺分頁、列表超過 12 筆、首頁文章超過 6 筆、網站缺 `fallback`、社群連結不是 https、`q` 超過 100 字、錯誤的 request_id、錯誤回應多出診斷欄位。
- PHP 端：`app/tests/Feature/Api/PublicApiSamplesTest.php` 驗證有效樣本全部通過、無效樣本全部被擋下。前端 F3 的 zod 用同一批驗證。
- 有內容的有效樣本都帶假資料哨兵字串 `MSMOCK-7F3A9C1E`（定案 M-19、FE-09：前端建置產物與正式映像全文搜尋它，出現即失敗）。媒體網址一律 `/mock-media/…` 相對路徑。

## 8. 導航狀態矩陣（M-18③）

next 模式下，一次換頁有兩條路：瀏覽器直接請求 HTML 頁，或 Next.js 站內換頁請求 `/_next/data/<建置編號>/….json`（伺服器端再跑一次 `getServerSideProps`）。各情況的預期：

| 情況 | HTML 頁 | `/_next/data` 站內換頁 |
|---|---|---|
| API 404 | 前端設 404，完整版面錯誤頁＋請求編號 | 前端回非成功狀態，路由器改走整頁載入，最後由伺服器畫出 404 錯誤頁（定案 §3.4 第 10 項、FE-17） |
| API 503（含 `CONTENT_MODEL_UNSUPPORTED`） | 前端設 503，錯誤頁＋請求編號；絕不顯示舊正文 | 同上，最後是伺服器畫的 503 錯誤頁 |
| API 422（搜尋參數） | 前端設 422，在搜尋頁以 `role="alert"` 顯示 `message`，保留表單 | 搜尋一律原生表單、一般 `<a>`（定案 §4.4），不走站內換頁 |
| gateway 429（搜尋限流） | 直接拿到 gateway 的正體中文 429 頁 | 同上，不走站內換頁；前端不得把 HTML 429 頁當 JSON 解析，也不得錯映成 404（定案 §6 F6 第 2 項） |
| API 回應不是 JSON、格式不符、連線被拒 | 前端回 503（傳輸層失敗一律 503，v1 §6.3） | 同上，整頁載入後 503 |
| API 逾時（D13c 裁定值） | 前端回 503 | 同上 |
| `/api/v1/site` 失敗但內容 200 | 正文照常，頁首頁尾改用內建靜態版（定案 §4.9） | 同上 |
| API 500（`HTTP_500`，Laravel 未預期例外） | 前端回 503（上游不可用），附請求編號；不顯示任何正文 | 同上，整頁載入後 503 |
| API 429 | 現行 API 不在 Laravel 限流，不會回 429；萬一出現，視同上游不可用回 503，不得錯映成 404 | 同上 |
| 前端自身例外 | 500，走 `_error` 伺服器端渲染，附請求編號 | 路由器整頁載入，最後是 500 錯誤頁 |
| 換版後舊建置編號的資料請求 | — | `/_next/data` 失敗，路由器整頁載入取得新版頁面；不得白屏、不得出現舊正文（FE-17） |

gateway 對 `/_next/` 不加 `proxy_intercept_errors` 或 `error_page`（定案 §6 F6 第 1 項），讓前端的狀態碼原樣回到瀏覽器。

## 9. `@DAT-04` 邊界（M-18④）

mysite 首頁的 E2E（`tests/e2e/specs/media.spec.mjs` 的 `@DAT-04`）只許頁面向主站與 CMS 兩個主機發請求（`tests/e2e/helpers/env.mjs`）。圖片政策允許「https 任一主機」（`MediaPolicy::imageUrl`），所以真資料的封面若來自 CMS 以外的 https 主機，首頁這項檢查會失敗。**blade 模式結果相同**，不是 next 模式新增的問題；前端不得為了通過檢查而改寫或代理媒體網址，也不得放寬政策（定案 §6 F7 第 3 項）。示例資料的封面都在 CMS 主機，所以 testing 與 CI 不受影響。

## 10. DOM 測試掛鉤

清單正本：`tests/e2e/dom-hooks.json`；摘要說明：`docs/MySiteReact施工紀錄/DOM測試掛鉤清單.md`。前端 F3 照它實作；Blade 補同名 `data-*`、E2E 改用掛鉤在 F6 進行。

整理清單時發現、在本文件定稿的事項：

| 事項 | 定案 |
|---|---|
| 兩個搜尋框 | 搜尋頁表單 `id="search-q"`（定案 §4.4、AI_RULES 第 14 條）；頁首搜尋框 `id="site-search-q"`（現行 `a11y.spec.mjs` 依賴）。兩者並存，不得同名 |
| 站內連結絕對或相對 | 前端依定案 §4.4 一律輸出相對路徑（含品牌、商店、RSS `/feed.xml`）。Blade 現行部分連結用 `url()` 產生絕對網址，E2E 沒有斷言這一點，Blade 不改 |
| 搜尋錯誤分支 | 由 API 的 422 `SEARCH_INVALID` 得知（第 5 節） |
| 唯一性 | `main`、首頁的 `header`、`[data-audio-error]`、零結果時 `main` 內的 `[role="status"]`、`h1` 在頁面上各只能有一個（E2E 以嚴格模式斷言） |
| CSS 變數 | `a11y.spec.mjs` 直接讀 `--duration` 與 `--color-focus`；前端的設計變數可自訂名稱（M-12），但這兩個名稱必須保留 |

## 11. 驗證

| 項目 | 測試 |
|---|---|
| PAPI-01 契約相符（含錯誤回應） | `app/tests/Feature/Api/PublicApiTest.php`（每一個回應以契約驗證）、`app/tests/Feature/Api/PublicApiSamplesTest.php`（樣本集） |
| PAPI-02 無 Set-Cookie、no-store | `PublicApiTest` 每一個請求都檢查（另含 X-Robots-Tag、無 `Access-Control-Allow-Origin`）；`app/tests/Feature/Api/PublicApiHardeningTest.php`：POST、PUT、PATCH、DELETE、OPTIONS 一律 405，路由不存在與參數錯誤的回應標頭相同 |
| PAPI-03 撤下 | `app/tests/Feature/Api/PublicApiVisibilityTest.php`：逐項比照 Blade 的 `VisibilityLeaseTest` 八項（租約重用、60 秒硬上限、事件遺失且 WordPress 失聯、visible false 清正文、撤下事件立即生效、舊事件不復活、新版本重新取得、列表在事件後立即失效且無事件時不超過租約）；另 `PublicApiTest` 兩項 |
| PAPI-04 上游失敗 | 連線失敗、逾時、5xx、非 JSON、格式不符各自回 503 並帶請求編號 |
| PAPI-05 不外洩 | 回應不含 email、服務帳號、金鑰、內部 id、預覽或快照資訊、內部路徑；錯誤只有三個欄位 |
| PAPI-06 媒體政策 | 與 Blade 同一份 `MediaPolicy`；`app/tests/Unit/Content/MediaPolicyTest.php` 另逐項比對 Blade 元件 |
| PAPI-07 參數 | 9 種不合法參數，API 與 Blade 公開頁狀態碼相同；422 的訊息與搜尋頁相同 |
| PAPI-08 網路存取 | 已做的部分：gateway 對外 `/api/` 與 `/index.php/` 一律 404（doctor `GW-API` 以專屬標記證明上游收不到）；Laravel 擋 `/index.php/api/…`、Host 標頭不影響回應（`PublicApiHardeningTest`）。網段規則與跨網隔離在 F6 驗收（定案 M-06） |

語意金絲雀（定案 §6 F2）：暫時把影片失效旗標寫死、把 API 的 404 錯誤碼改回 `HTTP_404`、拿掉 no-store、在摘要多送內部 id、拿掉 `/index.php` 檢查、關掉假資料的 CMS 網址全文檢查、不改寫正文連結，七種改壞都讓對應測試變紅；改回後全綠，檔案逐位元組復原。紀錄見 `docs/MySiteReact施工紀錄/F1F2施工紀錄.md`。
