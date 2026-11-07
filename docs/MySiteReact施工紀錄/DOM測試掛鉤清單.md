# DOM 測試掛鉤清單（F1 初版）

```
狀態: F1 初版（首次交付）
日期: 2026-11-06
正本: tests/e2e/dom-hooks.json（機器可讀；本文件只是摘要，兩者不一致時以 JSON 為準）
依據: MySiteReact前端專案v1 §6.5、§6.4、§6.3、§7 F1 第 5 步、§9.2 FE-12；定案執行書 §5 M-10、M-09、§4.3、§4.4、§5.1 第 6、10、14 條
讀碼基準: 分支 claude/happy-euler-evh676，提交 71d4b46（行號一律以此提交為準；整理期間同一施工線正在重構 ContentController.php（後來成為提交 b1a81de），其行號請對照提交 71d4b46）
範圍: 只新增清單；Blade 樣板補 data-*、E2E 改用掛鉤，都留到 F6
```

---

## 0. 先讀

- **正本在 `tests/e2e/dom-hooks.json`**。前端 F3 照它替畫面元件加掛鉤；前端 CI 以假資料逐頁檢查（FE-12）；前端儲存庫 `contracts/` 保存同步副本（v1 §6.5 第 3 點）。
- **F6 才改 Blade 與 E2E**：Blade 在 JSON 的 `blade_element` 位置補上同名 `data-*`（不改外觀），E2E 把 class 選擇器與原始 HTML 正規式改為下表的掛鉤（v1 §6.5 第 2 點、§7 F6 第 6 步）。
- 統計：DOM 掛鉤 46 個（必要 44、選用 2），新提議的 `data-*` 30 個；原始 HTML 條件 17 條；CSS 契約 5 條；正式建置禁止屬性 2 個。另列 Laravel 專屬頁的選擇器 12 個、範圍外（後臺、WordPress）13 組，確保「涵蓋現行 E2E 用到的全部 class 型選擇器」（v1 F1 驗收）。
- 每一條在 JSON 都附 E2E 依據（檔名:行號）與現行 Blade 位置；E2E 未斷言的掛鉤 `used_by` 為空，依據寫在 `note`。

## 1. 規則摘要

1. 新掛鉤一律 `data-*`、kebab-case；現有的 `data-*` 與 `id` 不改名。
2. 布林掛鉤以「存在」判斷（`[data-x]`）；React 寫 `data-x=""`。帶值的只有 `data-error-view`（狀態碼）與既有的 `data-embed-src`。
3. 設計可改 class、版面、圖示；不得刪改掛鉤、`id`、角色與指定文字（AI_RULES 第 6 條）。
4. 掛鉤要在**伺服器輸出的 HTML** 就存在（E2E 有一半是讀原始 HTML）；只有 YouTube 的 `iframe`、圖片與音訊的失效狀態是瀏覽器端事後加上。

## 2. 頁型 × 掛鉤摘要

### 2.1 頁型套用哪些元件

| 頁型 | 路徑 | 套用 | 主要 E2E |
|---|---|---|---|
| home | `/` | 全站、主標題、卡片、圖片 | a11y.spec.mjs:64、media.spec.mjs:131、shop.spec.mjs:40、wp-editor.spec.mjs:82 |
| articles_index | `/articles` | 全站、主標題、卡片、圖片 | media.spec.mjs:112、layout.spec.mjs:33、wp-editor.spec.mjs:95 |
| videos_index | `/videos` | 同上 | media.spec.mjs:9、layout.spec.mjs:42 |
| podcasts_index | `/podcasts` | 同上 | infra.spec.mjs:46、media.spec.mjs:57 |
| article_detail | `/articles/{slug}` | 全站、單篇、圖片 | wp-editor.spec.mjs:90、a11y.spec.mjs:168 |
| video_detail | `/videos/{slug}` | 全站、單篇、YouTube 播放器、圖片 | media.spec.mjs:29 |
| podcast_detail | `/podcasts/{slug}` | 全站、單篇、Podcast 播放器、圖片 | media.spec.mjs:61、infra.spec.mjs:48 |
| page_detail | `/pages/{slug}` | 全站、單篇、圖片 | layout.spec.mjs:26、media.spec.mjs:133 |
| category／tag | `/categories/{slug}`、`/tags/{slug}` | 全站、主標題、卡片、圖片 | layout.spec.mjs:16、:17 |
| search | `/search` | 全站、主標題、搜尋專屬（有結果／零結果／錯誤） | a11y.spec.mjs:98、media.spec.mjs:124、wp-editor.spec.mjs:83 |
| error_404／503／500 | 不存在的路徑、撤下的單篇、上游失敗、前端例外 | 全站、錯誤頁 | media.spec.mjs:119–123、layout.spec.mjs:88（503、500 無 E2E，依 FE-17） |

### 2.2 掛鉤明細（「新」＝本清單提議；「現」＝現行已有）

| 範圍 | 掛鉤 | 取代的現行選擇器 | 狀態／文字 | E2E 依據 |
|---|---|---|---|---|
| 全站 | 新 `[data-skip-link]` | `.skip-link` | 文字「跳到主要內容」；第一個 Tab | a11y.spec.mjs:65–71、site.mjs:110 |
| 全站 | 現 `main#main`（`tabindex="-1"`） | — | 全頁唯一 | a11y.spec.mjs:72、media.spec.mjs:105、:122–125 |
| 全站 | 新 `[data-site-header]` | `header`（標籤定位） | 首頁上須唯一 | shop.spec.mjs:41、:43、:60 |
| 全站 | 現 `[data-site-nav]` | — | 完整版面時 | （app.js 使用，E2E 無） |
| 全站 | 現 `[data-site-nav-toggle]` | `.site-nav__toggle` | `aria-expanded`；<768 可見 | a11y.spec.mjs:78–120、shop.spec.mjs:58 |
| 全站 | 新 `[data-site-nav-list]` | `.site-nav__list` | 手機收合時隱藏 | a11y.spec.mjs:106–121 |
| 全站 | 新 `[data-site-nav-link]` | `.site-nav__link` | — | a11y.spec.mjs:82–84、:133 |
| 全站 | 新 `[data-site-nav-shop]` | `.site-nav__link--shop` | simulation 時「商店」；disabled 時不得出現 | shop.spec.mjs:41–43、:60 |
| 全站 | 現 `#site-search-q` | — | 頁首搜尋框 | a11y.spec.mjs:96 |
| 全站 | 新 `[data-site-footer]` | `footer`（標籤定位） | — | media.spec.mjs:132 |
| 全站 | `[data-site-footer] a[href="/pages/privacy"]` | — | 文字「隱私說明」 | media.spec.mjs:132–133 |
| 全站 | 新 `[data-sample-badge]` | `.badge--sample` | 示例時「示例內容」 | （E2E 無；定案 §5.1 第 10 條） |
| 主標題 | 新 `[data-page-title]` | `.hero__title`、`.page-header h1` | 唯一 h1 | a11y.spec.mjs:85 |
| 卡片 | 新 `[data-content-card]` | `.content-card` | 列表非空 | （E2E 以原始 HTML 判斷） |
| 卡片 | 新 `[data-content-link]` | `.card__title a`、`href="/{type}/…"` 正規式 | 相對路徑、純標題文字 | site.mjs:29、infra.spec.mjs:46、layout.spec.mjs:36、wp-editor.spec.mjs:96 |
| 圖片 | 新 `[data-media-frame]` | `.media-frame` | — | media.spec.mjs:115 |
| 圖片 | 現 `img[data-media-img]` | — | 有封面時 | media.spec.mjs:113 |
| 圖片 | 新 `[data-media-frame][data-media-broken]` | `.media-frame.is-broken` | 載入失敗 | media.spec.mjs:115 |
| 單篇 | 新 `[data-entry]` | `.entry` | — | （E2E 以 main 定位） |
| 單篇 | 新 `[data-entry-title]` | `.entry__title` | h1 只含標題 | wp-editor.spec.mjs:92、:111 |
| 單篇 | 新 `[data-entry-body]` | `.entry__body`、`.prose` | SafeHtml 唯一出口 | wp-editor.spec.mjs:93、:128、media.spec.mjs:134 |
| 單篇 | `[data-entry] img[data-media-img]` | `main img[alt=…]` | 有封面時 | wp-editor.spec.mjs:94 |
| YouTube | 現 `[data-youtube-player]`＋同元素 `data-embed-src` | — | ID 合法時 | media.spec.mjs:11–12、:35、:45 |
| YouTube | 現 `[data-youtube-start]` | — | `href` 為 watch 網址 | media.spec.mjs:36–39 |
| YouTube | `[data-youtube-player] iframe` | — | 點擊後才建立 | media.spec.mjs:33、:40–47 |
| YouTube | 新 `[data-youtube-unavailable]` | `.player--video.is-broken …` | ID 不合法；「影片目前無法播放」 | （E2E 無；v1 §6.4） |
| Podcast | 現 `[data-audio-player]`、其內 `audio` | — | `controls`、`preload="none"` | media.spec.mjs:62–67、:101–102 |
| Podcast | 新 `[data-audio-duration]` | `[data-audio-player] time` | `datetime="PT…"` | media.spec.mjs:69–71 |
| Podcast | 新 `[data-audio-download]` | `.player__audio-meta a[download]` | `href`＝音訊 src | media.spec.mjs:72–74、:104 |
| Podcast | 現 `[data-audio-error]` | — | 初始隱藏，失敗後顯示 | media.spec.mjs:103 |
| Podcast | 新 `[data-audio-unavailable]` | `.player--audio.is-broken .player__state` | 無音訊；「音訊目前無法播放」 | （E2E 無；v1 §5.3） |
| 錯誤頁 | 新 `[data-error-view]`（值為狀態碼，如 `"404"`） | `.state` | — | （E2E 無） |
| 錯誤頁 | 新 `[data-error-title]` | `.state__title` | 404 含「找不到」 | media.spec.mjs:121 |
| 錯誤頁 | 新 `[data-error-home-link]` | `.state__actions .button--primary` | 名稱完全等於「回首頁」 | media.spec.mjs:122 |
| 錯誤頁 | 新 `[data-request-id]` | `.state__ref` | 「請求編號」 | media.spec.mjs:123 |
| 錯誤頁 | 新 `[data-error-retry-link]`（選用） | `.state__actions .button` | 503「重新整理」 | （E2E 無） |
| 錯誤頁 | 現 `#state-q`（選用） | — | 404 頁搜尋框 | （E2E 無） |
| 搜尋 | 新 `[data-search-form]` | `.search-panel` | 原生 GET 表單 | （E2E 無；定案 §4.4） |
| 搜尋 | 現 `#search-q` | — | `value` 回填查詢字詞 | a11y.spec.mjs:99 |
| 搜尋 | 新 `[data-search-summary]` | `.search-summary` | 有結果；`role="status"` | a11y.spec.mjs:100 |
| 搜尋 | 新 `[data-search-result]` | 原始 HTML 字面 `class="search-result"` | 有結果 | wp-editor.spec.mjs:13、:83、:120、preview.spec.mjs:138 |
| 搜尋 | 新 `[data-search-result-link]` | `.search-result__title a` | 原始 HTML 呈現 `>標題<` | wp-editor.spec.mjs:106 |
| 搜尋 | 新 `[data-search-empty]` | `.empty[role="status"]` | 零結果；`role="status"`；「找不到符合」 | media.spec.mjs:125 |
| 搜尋 | 新 `[data-search-error]` | `.notice--danger[role="alert"]` | 錯誤分支；`role="alert"` | （E2E 無；定案 §4.3、M-10） |

## 3. 原始 HTML 條件（不跑 JavaScript）

| 條件 | 頁型 | E2E 依據 |
|---|---|---|
| `<html lang="zh-Hant-TW">` | 全部 | a11y.spec.mjs:156（axe 間接） |
| `<title>頁面標題｜站名</title>`，單一文字節點、各頁不重複 | 全部 | seo.spec.mjs:38–40、:57–58 |
| `<link rel="canonical" href="主站絕對網址">`（rel 在前） | 公開頁 | seo.spec.mjs:41–46 |
| `og:url` 主機為主站 | 公開頁 | seo.spec.mjs:48–49 |
| 公開頁無 noindex；搜尋與錯誤頁 `noindex, nofollow` | 全部 | seo.spec.mjs:55（後者 E2E 無） |
| description、Open Graph、twitter:card、RSS `<link rel="alternate">` | 全部 | E2E 無（v1 §6.3） |
| 列表詳情連結為相對路徑 `href="/{type}/{slug}"` | 列表頁 | site.mjs:29、infra.spec.mjs:46、layout.spec.mjs:36 |
| Podcast `<source src>` 或 `<audio src>`，該網址 Range 回 206 | podcast_detail | infra.spec.mjs:49–53 |
| `data-embed-src` 在原始 HTML；點擊前無 `<iframe>` | video_detail | media.spec.mjs:11、:33 |
| 搜尋結果標記與 `>標題<` | search | wp-editor.spec.mjs:13、:106、preview.spec.mjs:138 |
| 首頁、列表、單篇含最新標題，撤下後不含 | home、列表、單篇 | wp-editor.spec.mjs:81–82、:103–105、:118–119 |
| `<a href>` 不指向 CMS（媒體檔例外） | 公開頁 | seo.spec.mjs:51–54 |

## 4. CSS 契約與禁止屬性

- `:root` 的 `--duration`（減少動態時為 0）與 `--color-focus` 名稱固定：a11y.spec.mjs:23、:135、:145–148 直接讀取（現行 `tokens.css:23`、`:54`、`:59–60`）。每個 Tab 停點要有可見焦點框（a11y.spec.mjs:53）。
- 溢位檢查目前豁免 `.skip-link`、`.visually-hidden`（site.mjs:110）。`.visually-hidden` 刻意**不設**掛鉤：1px 的視覺隱藏元素本來就被尺寸條件略過，F6 只把豁免改為 `[data-skip-link]`。
- 正式建置不得出現 `data-dyad-id`、`data-dyad-name`（v1 §6.5 第 5 點、§6.8 第 7 步、FE-09）。

## 5. 疑點（第 1、2、4 點已在 `docs/公開API契約.md` 第 10 節與第 5 節定稿；其餘待 F6 處理）

1. **兩個搜尋框 id**：定案 §4.4 與 §5.1 第 14 條只寫 `id="search-q"`，但頁首搜尋框現行是 `id="site-search-q"`（header.blade.php:36，a11y.spec.mjs:96 依賴）。兩者都要保留；頁首若也叫 `search-q`，搜尋頁會重複 id。**已定稿**：兩者並存、不得同名。
2. **絕對或相對連結**：v1 §6.3 寫 RSS `href="/feed.xml"`，Blade 用 `url()` 輸出絕對網址（app.blade.php:52）；品牌、商店、頁尾搜尋與 RSS 連結也是絕對網址（header.blade.php:13、:31；footer.blade.php:22、:25），與定案 §4.4「站內一律相對路徑」不一致。E2E 未驗。**已定稿**：前端依定案 §4.4 一律相對路徑；Blade 不改。
3. **搜尋頁 noindex 無 E2E**：seo.spec.mjs:62 的測試名稱提到搜尋，但內文只驗預覽與後臺登入。清單依 v1 §6.3 列為必要，建議 F6 補測。
4. **搜尋錯誤分支的資料來源**：Blade 由 Laravel 判定並回 422（`app/app/Http/Controllers/Content/ContentController.php` 第 97–102 行，提交 71d4b46），v1 §5.4 的 API 錯誤碼未列 422；**已定稿**：API 回 422 `SEARCH_INVALID`，`message` 為具體原因，前端在搜尋頁以 `role="alert"` 顯示。
5. **`.search-result` 是字面比對**：E2E 比對原始 HTML 的 `class="search-result"`，多一個 class 就失配；F6 前前端無法通過，F6 必須改用 `data-search-result`。
6. **相對路徑以嚴者為準**：site.mjs:29 接受絕對網址，infra.spec.mjs:46、layout.spec.mjs:36 只認相對路徑；清單採相對（與 M-10、定案 §4.4 一致）。
7. **YouTube 巢狀關係**：v1 §6.4 未寫明，E2E 要求 `data-embed-src` 與 `[data-youtube-player]` 同元素、`[data-youtube-start]` 與點擊後的 `iframe` 都在它之內；清單已寫明。
8. **唯一性**：現行 E2E 對 `main`、首頁 `header`、`[data-audio-error]`、播放器內 `audio`、零結果時 `main [role="status"]`、`h1` 是嚴格模式斷言，頁面上都只能有一個（定案 §4.3 未提「只能一個」）。
9. **PHPUnit 字面斷言**：`app/tests/Feature/Content/PublicRoutesTest.php` 以完整字串斷言 Blade（如第 240 行 `<audio class="player__audio-control" controls preload="none"`、第 359 行 `site-nav__link--shop`）。F6 補 `data-*` 時不可插進這些字串中間，否則要同步改 PHPUnit。
10. site.mjs:154 的 `listItems()` 沒有任何 spec 呼叫（查無引用），不列為依據。
11. media.spec.mjs:111 只攔截 `/wp-content/uploads/…` 圖片；前端假資料媒體在 `/mock-media/…`，前端 CI 沿用時要改攔截規則。
12. 定案 M-12 讓施工者定設計變數名稱，但 `--duration`、`--color-focus` 一改名 a11y 測試就會失敗，所以固定。
