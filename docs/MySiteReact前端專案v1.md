# MySite React 前端專案 執行步驟書 v1

```
狀態: 現行本（首次交付；三輪迭代、每輪思考兩遍後定稿）
版本: v1（檔名由委託人指定）
日期: 2026-11-05 起草與查核；2026-11-06 定稿
案名: MySite React 前端專案
本檔: C:\Users\whenwhosay\.claude\plans\001整體程式建構計畫\MySiteReact前端專案v1.md
授權: 2026-11-05 委託人「假節鉞決行」。授權範圍是「擬定本步驟書」；書中 F0 起的施工，要等委託人對 §2 待裁事項作成裁定後才開工。
承接: 雲端 Claude 的建議（Laravel JSON API＋獨立 React 前端＋假資料／真資料兩種模式）
讀者: 委託人；雲端施工者（Claude Code 官方雲端會話）；本機 Claude Code；MyDyad（讀 AI_RULES.md）
```

> ⛔後註（2026-11-06）：已有新版 `C:\Users\whenwhosay\.claude\plans\001整體程式建構計畫\MySiteReact前端專案v2.md`（記錄 D1～D17 裁定；儲存庫名 MySiteStorage）。施工依據＝本檔全文＋v2，凡「待裁」之處以 v2 為準。

> ⛔後註（2026-11-06，第二則）：已有定案修訂 `C:\Users\whenwhosay\.claude\plans\001整體程式建構計畫\MySiteReact前端專案定案執行.md`（整合 Codex、Argon 兩份建議並逐條查證）。施工依據＝本檔全文＋v2＋定案執行；三者衝突時，以定案執行為準，其次 v2。

**依據（皆於 2026-11-05 實查）**
- `C:\Users\whenwhosay\Desktop\MySite網站建置.txt`（mysite 建置總結 v1，下稱「建置總結」）
- `C:\Users\whenwhosay\.claude\plans\001整體程式建構計畫\網站規劃書v5.md`（下稱「規劃書v5」）
- `C:\Users\whenwhosay\.claude\plans\001整體程式建構計畫\mysite_ClaudeCode雲端自主施工完整Prompt.md`（下稱「雲端施工 Prompt」）
- GitHub `Whenwhoengine/mysite` main（公開儲存庫；以 gh 唯讀實查路由、gateway、compose、測試、CI；下文 mysite 內路徑皆指該儲存庫）
- `C:\Users\whenwhosay\MyPythons\MyDyad\`（MyDyad 原始碼，版本 1.16.0，基於上游 Dyad v1.16.0；下文以 `MyDyad\` 簡稱此根目錄）
- `C:\Users\whenwhosay\MyPythons\MyDyadOne\MyDyadOne報告書v6.md`
- 官方網站與 GitHub 安全公告（見 §13）

---

## 0. 一頁總覽

**要做什麼**：mysite 的「公開頁面」改由一個新的、獨立的 Next.js 前端儲存庫負責畫；Laravel 多開一組只讀的 JSON API 給前端取資料。委託人在自己電腦上用 MyDyad 改畫面（看假資料即時預覽），推到 GitHub 的設計分支；自動測試全綠、委託人在 GitHub 網頁按合併後，經 mysite 既有的「CI → release → 簽章清單 → 主機下載」流程上線。

**不變的事**：WordPress、內容可見性規則、撤下最遲 60 秒（出處：建置總結 §4.5）、唯一淨化器、草稿預覽、模擬商店、管理後臺、備份、部署簽章全部照舊。前端只負責「長相」。

**更正雲端 Claude 建議的一處**：它說 Dyad「需要一把 AI 服務的 API 金鑰，會有使用費」。委託人已建成 MyDyadOne 閘道（埠 11907），MyDyad 經它使用 ChatGPT 與 Claude 訂閱，不必另申請 API 金鑰（出處：`C:\Users\whenwhosay\Documents\MyVault\00_系統規格\本機埠分配表.md` 的 11907 列、`MyDyadOne報告書v6.md` §一）。

**五條關鍵設計**
1. 前端只透過 Laravel 公開 API 取資料、每次請求即時渲染、自己不存內容副本 → 撤下保證原封不動。
2. 採 Next.js **Pages Router**（不是 App Router）→ 頁面能回正確的 404／503、不受 React Server Components 系列漏洞影響、沒有「建置時預先產生內容頁」的陷阱。
3. 「薄頁面＋畫面元件」分層：MyDyad 只改 `src/views`、`src/components`、`src/styles`；資料、接線、契約、測試全受保護，CI 會阻擋。
4. 假資料模式是預設：MyDyad 打開就有逼真內容，不需要後端、不需要任何金鑰；正式映像拒絕以假資料模式啟動。
5. 前端映像由 mysite 的統一建置腳本依「鎖定的前端 commit」建置，進同一份簽章清單；gateway 加渲染模式開關（`blade`／`next`），隨時可切回 Blade。

**階段表**

| 階段 | 做什麼 | 誰做 | 前置 | 沒有主機也能做 |
|---|---|---|---|---|
| F0 | 裁定、修訂正典、建立儲存庫與分支保護 | 委託人＋本機 Claude Code＋雲端施工者 | — | 是 |
| F1 | 公開 API 契約定稿 | 雲端施工者（mysite） | F0 | 是 |
| F2 | Laravel 公開 API 實作與測試 | 雲端施工者（mysite） | F1 | 是 |
| F3 | 前端儲存庫骨架（先複刻現行外觀） | 雲端施工者（前端儲存庫） | F1；假資料要等 F2 匯出 | 是 |
| F4 | MyDyad 接上與第一次設計循環 | 委託人＋本機 Claude Code | F3 | 是 |
| F5 | 頁面設計迭代（持續） | 委託人用 MyDyad；受保護部分交施工者 | F4 | 是 |
| F6 | 併入 mysite：容器、gateway、建置、CI 雙模式 | 雲端施工者（mysite） | F2、F3 | 是 |
| F7 | staging 驗收 | 雲端施工者＋委託人 | F6、主機（現為外部阻擋 BLK-HOST-01） | 否 |
| F8 | 正式切換 | 委託人核准＋施工者 | F7、核准（BLK-APPROVAL-01） | 否 |
| F9 | Blade 公開頁退場 | 只在委託人下「刪除」命令後 | F8 穩定 | — |

F2 與 F3 可並行；F5 與 F6 可並行。外部阻擋只卡 F7、F8，與 mysite 現況（M9／M10 外部阻擋）相同，不拖住其他階段。

**委託人要親手做的事**：
- 一次性：裁定 §2；建立前端儲存庫與設定分支保護、合併設定（或授權本機 Claude Code 代辦）；在 MyDyadOne 面板登入 ChatGPT（若要用 GPT 線）；在 MyDyad 登入 GitHub。
- 每次設計：在 MyDyad 設計並按「同步到 GitHub」；沒有開著的 PR 時按黃色「Compare & pull request」開 PR；CI 綠燈後按「Merge pull request」。
- 施工者每完成一段：在 GitHub 網頁合併施工者開的 PR（例如 ADR-015、前端鎖定 SHA 升版）。
- 上線時：觸發 release 與 deploy-staging（或請施工者觸發）、核准正式發布。
- 其餘全由施工者處理。

---

## 1. 目標、範圍、不做的事

### 1.1 目標
- 委託人能用 MyDyad 以對話改公開頁面的設計，改完就能在本機預覽、推上 GitHub、看到自動測試結果與三種寬度的截圖。
- 設計變更經過與現行同等嚴格的測試與發布流程才上線；任何一次設計都不能讓 mysite 的既有保證退步。

### 1.2 v1 範圍
- **mysite（Laravel）**：公開唯讀 JSON API（品牌、選單、首頁、列表、單篇、頁面、分類、標籤、搜尋）。
- **前端儲存庫（新）**：Next.js Pages Router＋TypeScript＋樣式方案（D4）＋假資料＋AI_RULES.md＋CI。
- **mysite（整合）**：前端容器、gateway 渲染模式開關、統一建置腳本與簽章清單納入前端映像、CI 雙模式、驗收登錄新增項目、文件更新。

### 1.3 v1 不做
- 不改 WordPress 與內容模型。
- 不把管理後臺、商店（含商品列表）、購物車、結帳、訂單、草稿預覽搬到 React。
- 瀏覽器不直接呼叫 API；全部由前端伺服器端取資料。
- 不做 ISR、靜態匯出、CDN 內容快取（與規劃書v5 第 757 行「初版不讓 CDN 長期快取公開 HTML」一致）。
- 不移除 Blade 公開頁（退場須委託人下「刪除」命令；AI 不執行刪除）。
- 不升級 MyDyad 本體（現役 1.16.0；上游最新 v1.22.0，出處：https://api.github.com/repos/dyad-sh/dyad/releases/latest ）。
- MyDyad 端不放任何秘密，也不需要任何秘密。

### 1.4 與現行正典的衝突（F0 必須先修訂，否則雲端施工者會依規拒絕）

| 位置 | 現行原文（摘） | 本案的改變 |
|---|---|---|
| mysite `docs/執行契約.md` §2.1 | 「不自行替換成 Next.js、React／Vue SPA……」 | 公開頁改由 Next.js 伺服器渲染（不是 SPA） |
| mysite `CLAUDE.md` 第 7 行 | 「Blade＋原生 CSS／JS；不引入……SPA」 | 同上 |
| mysite `docs/規劃書v5.md` 第 98、119、1708–1709、1775 行 | 不引入 React／Vue SPA；Laravel＋Blade＋原生 CSS／JS | 同上 |
| 規劃書v5 第 496 行 | 「Node 只負責前端建置與 E2E；正式 Web 不跑開發伺服器」 | 正式環境多一個 Node.js 伺服器行程（`node server.js`，不是開發伺服器；INF-07「正式無開發伺服器」仍成立） |
| 雲端施工 Prompt §0.3；mysite `CLAUDE.md` 第 5 行；mysite `docs/規劃書v5.md` 第 1773 行 | 「委託人的電腦只使用瀏覽器；不得依賴 Windows、WSL、Docker Desktop 或本機 CLI」 | 委託人電腦使用 MyDyad（已安裝）與 Node.js（已裝 v24.18.0，本機實查），只用於設計預覽；正式建置、測試、部署仍全在雲端 |
| 建置總結 §12 技術版本表（第 548 行） | 「Node.js（只用於前端建置）」 | 正式環境多一個 Node.js 伺服器行程；建置總結屬交付紀錄，由施工者在下一版交付報告更新，不改舊文 |

修訂方式：mysite 新增一條架構決策（建議編號 ADR-015，接在現有 ADR-001～014 之後），寫明委託人核准日期與範圍；`執行契約.md`、`CLAUDE.md` 以「修訂註記」標出被 ADR-015 取代的句子，原文保留不刪；本步驟書收進 mysite `docs/`，檔名照委託人所定 `MySiteReact前端專案v1.md`，作為雲端施工者的施工依據（雲端會話讀不到委託人電腦上的檔案）。

---

## 2. 待裁事項

| 編號 | 要裁定什麼 | 建議 | 不照建議的影響 |
|---|---|---|---|
| D1 | 核准 §1.4 的架構變更 | 核准，並由施工者以 ADR-015 記錄、修訂執行契約與 CLAUDE.md | 不核准則本案停止，MyDyad 只能當「設計草圖」工具，成品要人工改寫成 Blade |
| D2 | 前端儲存庫名稱 | 由委託人命名（本書以「前端儲存庫」代稱） | — |
| D3 | 前端儲存庫可見性 | **公開**：GitHub 官方方案說明把「Protected branches」列在 GitHub Pro 的「私人儲存庫進階工具」，而 GitHub Free 只在公開儲存庫提供完整功能、私人儲存庫為「有限功能」（出處：https://docs.github.com/en/get-started/learning-about-github/githubs-plans ）；委託人帳號的方案以 gh 查不到（回傳空值），所以先以 Free 估計。本案靠分支保護阻擋「直接推 main」；mysite 本身也是公開儲存庫。注意：MyDyad 自己「建立儲存庫」時一律建成私人儲存庫（出處：`MyDyad\src\ipc\handlers\github_handlers.ts` 第 703–706 行），所以要在 GitHub 網頁或以 gh 建，不用 MyDyad 建 | 選私人：需付費方案才能設保護，否則 MyDyad 誤推 main 時沒有任何閘門；設計半成品不公開 |
| D4 | 樣式方案 | **Tailwind CSS v4**：MyDyad 內建範本與規則以 Tailwind 為預設（出處：`MyDyad\scaffold\AI_RULES.md` 第 12 行），AI 改得最穩；shadcn/ui 會帶進一批 Radix 相依套件，內容站用不到多少，先不裝 | 選原生 CSS：與現站一致，但 MyDyad 的 AI 較不熟、改動品質較不穩 |
| D5 | staging 是否對外開放唯讀 API，讓委託人電腦用真資料預覽 | staging 開放（只讀、gateway 每 IP 限流）；production 不開放 | 不開放：委託人在 MyDyad 只能看假資料，真資料只能看 staging 網站成品 |
| D6 | 草稿預覽外觀 | v1 維持 Blade 樣式 | 若要一致，預覽要搬到 React，會碰到預覽票券與 Session 安全設計，工程大且風險高 |
| D7 | 商店 | v1 整個留在 Laravel | 商店開啟時，商店頁與內容頁外觀不同；商店預設關閉（出處：建置總結 §6.1），影響小 |
| D8 | MyDyad 推送與合併規則 | ①MyDyad 只推一條長期設計分支（建議名 `design/work`，可改）。②main 設保護，細節見 F0 第 3 步；重點是**管理員也不得繞過**——MyDyad 以委託人自己的 GitHub 帳號登入，等於管理員，不勾「不得繞過」保護就形同虛設。③合併方式只開「Create a merge commit」，在儲存庫設定關閉 Squash 與 Rebase：MyDyad 推送前只會先取回同名分支（`git pull --rebase=false origin <分支>`，出處：`MyDyad\src\ipc\utils\git_utils.ts` 第 3033–3038 行），壓縮合併會讓設計分支與 main 歷史分岔、下一次 PR 重複出現舊提交。④關閉「合併後自動刪除分支」，`design/work` 是長期分支。⑤v1 不用任何自動化權杖：開 PR 由委託人按一下黃色按鈕；main 的新提交由施工者以一般推送合併回設計分支（理由見 §6.8） | 用 Squash：每次合併後設計分支都要重建，委託人得懂 git；改用自動化：需要一把 GitHub 權杖存進 Actions 秘密 |
| D9 | 套件政策 | MyDyad 不得新增套件；需要時由施工者在 Linux 環境加入並更新鎖定檔 | 允許 MyDyad 加套件：Windows 上改寫的 npm 鎖定檔可能缺 Linux 平臺套件，CI 會壞（npm/cli#4828 曾回報此問題，官方於 2025-04-03 標為已完成，但同類回報此後仍見於 Tailwind、lightningcss 等套件；本案以 F4 實測為準） |
| D10 | Blade 公開頁保留期 | 保留到委託人下「刪除」命令為止，不設天數 | — |
| D11 | 委託人電腦的 Node.js | 維持 v24.18.0：24 線維護到 2028-04-30（出處：https://raw.githubusercontent.com/nodejs/Release/main/schedule.json ）；MyDyad 原始碼宣告的 Node 範圍是 `>=24 <26`（出處：`MyDyad\package.json` 第 218–220 行），MyDyadOne 重新打包 MyDyad 時會用到電腦上的 Node | 升 26：與 CI 一致，但日後重新打包 MyDyad 會超出它宣告的範圍 |
| D12 | mysite main 分支保護 | 目前 main 未設保護、無 rulesets（2026-11-05 以 gh 實查）；建議一併設定「需 PR＋CI、禁強推」 | 不設：維持現狀，靠施工者守規（雲端施工 Prompt §0.2 禁推主幹） |
| D13 | 數值類（依規矩攤牌，AI 不自訂） | 見下表 | — |
| D14 | 套件管理器 | **npm**：`package.json` 寫 `packageManager: "npm@…"`，MyDyad 就固定用 npm（出處：`MyDyad\src\ipc\utils\package_manager_selection.ts` 第 46–93 行）；委託人電腦的 pnpm 在 PATH 上，若不寫這一欄、MyDyad 會優先選 pnpm。與 mysite 的 `npm ci` 做法一致 | 改 pnpm：多一個工具要鎖版本；F4 若實測 npm 會改寫鎖定檔，才改走 pnpm（見 F4 第 6 步） |
| D15 | MyDyad 的工作模式（模式選單顯示為「建置」與「代理」，出處：`MyDyad\src\components\ChatModeSelector.tsx` 第 151、162 行＋`MyDyad\src\i18n\locales\zh-TW\interface.json` 第 184、338 行） | 先用 **Build 模式「建置」**設計：每輪改完自動存成一個版本，不在委託人電腦跑完整建置；需要 AI 自己驗證時才開 Agent 模式。Agent 模式每輪會在專案目錄執行 `npm run build` 與 test、lint、typecheck（出處：`MyDyad\src\sovereign\verification\project_checks.ts` 第 88–106 行），建置會吃滿 CPU | 常用 Agent 模式：可能造成桌面卡頓（第一不可忍）；F4 實測後再定 |
| D17 | MyDyad「全權略過權限確認」 | 目前為開啟（Agent 改檔、執行指令、上網都不跳確認，出處：`MyDyadOne報告書v6.md` §一第 2 項）。建議**做這個專案時關閉**，或至少只用 Build 模式；這是 MyDyadOne 面板上的全域開關，影響所有 MyDyad 專案，所以由委託人決定。不論開關，本案的最後把關都在 CI 與 mysite 端重驗（§6.8） | 保持開啟：Agent 可能自行改到 `.github/` 等受保護檔，被 CI 攔下的次數會變多 |
| D16 | 委託人電腦上的前端工作資料夾 | 放在 `C:\Users\whenwhosay\MyPythons\` 底下，資料夾名由委託人定；**匯入 MyDyad 時取消勾選「複製到 dyad-apps 資料夾」（英文介面為 Copy to the dyad-apps folder；正體中文字串出處：`MyDyad\src\i18n\locales\zh-TW\home.json` 第 24 行）**，保留原位置（出處：`MyDyad\src\components\ImportAppDialog.tsx` 第 433–449 行）。不勾選的理由：預設複製目的地在 `AppData\Roaming\MyDyad\mydyad-apps`，本機 Claude Code 對 AppData 的判讀不可信（MSIX 沙盒），協助除錯會失準 | 用預設複製：多一份副本、位置在 AppData |

**D13 數值類**

| 項目 | 依據與約束 | 待裁定的值 |
|---|---|---|
| a. next 模式的搜尋限流（gateway，每 IP） | 現行 Laravel `content-search` 為每 IP 每分鐘 30 次（出處：mysite `app/app/Providers/ContentServiceProvider.php` 的 `SEARCH_PER_MINUTE = 30`，2026-11-05 實查）；next 模式下 Laravel 看到的來源是前端容器，所以每 IP 限流必須移到 gateway | 建議等效沿用 30 次／分鐘；nginx 的 `rate` 與 `burst` 換算式由施工者寫出呈報。注意兩者演算法不同：nginx `limit_req` 是漏桶、Laravel 是固定時間窗，結果不會完全相同，要以測試記錄差異；超限時 gateway 預設回 nginx 的英文 429 頁，須另以 `error_page 429` 指向正體中文頁（比照 gateway 現有的 `infra/nginx/maintenance/index.html` 做法） |
| b. 公開 API 的服務層總量上限 | 只有前端容器會呼叫；裁定前只靠 gateway 每 IP 限流 | 施工者在 F6 量測正常流量後提數字 |
| c. 前端呼叫 API 的逾時 | 須大於 Laravel 處理一次 API 請求的最壞耗時：Laravel 讀 WordPress 每次連線上限 2 秒、讀取上限 5 秒（出處：mysite `docs/設定目錄.md` 的 `CONTENT_CONNECT_TIMEOUT_SECONDS`、`CONTENT_HTTP_TIMEOUT_SECONDS`），而且同一份設定目錄註明「冪等讀取最多 2 次嘗試」，一次請求也可能呼叫 WordPress 不只一次（例如先確認可見性再取正文）；前端每頁另要取 `/api/v1/site`，兩次呼叫串行或並行也會影響總耗時。算式輸入：重試次數、每頁 WordPress 呼叫次數、兩次 API 呼叫的方式。同時須小於 gateway `proxy_read_timeout 60s`（出處：mysite `infra/nginx/gateway/*/gateway.conf.template`） | 施工者附算式提出確切值 |
| d. 前端容器記憶體 | 現行 compose 各服務都沒有資源限制（實查四個 compose 檔）；不預設上限 | F6 實測後寫入資源量測（INF-08）；若超出主機建議規格（2 核 4 GB，出處：建置總結 §16）再呈報 |
| e. Agent 模式建置的 CPU 用量 | 只在 D15 選常用 Agent 模式、且 F4 實測確有卡頓時才需要 | 屆時由施工者提出限制方式與數值 |
| f. 兩張新網路的名稱與子網位址（§4.6） | 依現行 edge／backend／db 的編排慣例（出處：`infra/compose.base.yml`），且不得與 testing 環境另用的位址段衝突（bootstrap 對 development 與 testing 用不同段，出處：`scripts/cloud/bootstrap.sh`） | 施工者提出，委託人裁定 |

---

## 3. 現行保證對照（新架構如何守住）

| # | 現行保證 | 出處 | 新架構的做法 | 驗收 |
|---|---|---|---|---|
| 1 | 撤下最遲 60 秒（實測約 29 秒） | 建置總結 §4.5；mysite `ContentCache::HARD_LIMIT_SECONDS = 60` | 前端每次請求向 API 取；不快取；API 走同一套可見性證明；Pages Router 的動態頁預設送出 `Cache-Control: private, no-cache, no-store, max-age=0, must-revalidate`，瀏覽器與中間層都不會留副本（出處：https://nextjs.org/docs/app/guides/self-hosting ） | `wp-editor.spec.mjs` 在 next 模式重跑；PAPI-03 |
| 2 | CMS 失聯回 503、絕不回舊正文 | 建置總結 §5.2；`ContentRepository` 註解 | API 原樣回 503；前端以 `res.statusCode = 503` 轉成 503 錯誤頁 | API-02、UI-03；FE-05 |
| 3 | 唯一淨化器、唯一 HTML 輸出元件 | `App\Support\Html\Sanitizer`、`<x-rich-content>`、`scripts/ci/check-blade-raw.sh` | API 的 `*_html` 只來自 Sanitizer；前端只有 `SafeHtml` 能輸出 HTML，CI 阻擋 | API-05；FE-03 |
| 4 | YouTube 按下播放前不連線 | `components/youtube-player.blade.php`＋`resources/js/app.js` | `YouTubeFacade` 逐項複刻（海報用 WordPress 封面、點擊才建 iframe） | `media.spec.mjs` next 模式重跑 |
| 5 | 圖片、音訊來源政策 | `Sanitizer::isAllowedMediaUrl`、`AudioPlayer`（MIME 白名單、`PODCAST_AUDIO_ALLOWED_HOSTS`） | 這些政策現在寫在 Blade 元件類別裡；F2 抽成共用政策類別，API 只輸出已通過政策的值 | PAPI-06 |
| 6 | 匿名公開頁不設 cookie | 建置總結 §5.6；`cookies.spec.mjs` | 前端不用 cookie／Session；API 路由群組不掛 Session | OPS-12；FE-06 |
| 7 | 後臺、預覽、訂單 `private, no-store` | `PrivateNoStore` 中介層 | 仍由 Laravel 提供，路徑分工不變 | OPS-12 |
| 8 | 只信任 gateway 固定位址 | `TRUSTED_PROXIES` 只有 `GATEWAY_EDGE_IP`；`InternalOnly` | 不改信任清單；搜尋的每 IP 限流移到 gateway（D13a） | FE-08 |
| 9 | 只部署簽章清單上的 digest | `docs/schemas/release-payload.schema.json` | 清單 `images` 增列前端映像；前端映像由統一建置腳本產生 | OPS-03、OPS-04 |
| 10 | 版本全鎖定 | `NODE_IMAGE_REF=node:26.13.0-trixie-slim@sha256:…`（`infra/images/php/Dockerfile`） | 前端沿用同一個 Node 映像參照；npm 鎖定檔；Action 以 SHA 固定 | CLD-06、GOV-02 |
| 11 | 秘密不進 Git／映像／證據 | CLD-04、DAT-05 | 前端完全不需要秘密 | CLD-04；FE-10 |
| 12 | 無障礙與版面 | `a11y.spec.mjs`、`layout.spec.mjs`（390／768／1440、200%） | 前端 CI 每次推送就跑假資料版；mysite CI 在 next 模式跑全套 | UI-02、UI-04、UI-05 |
| 13 | SEO：title 唯一、canonical 指主站、noindex、sitemap／RSS 只由主站 | `seo.spec.mjs` | 前端 `<head>` 取自 API 的 `seo` 欄位；`/sitemap.xml`、`/feed.xml` 仍由 Laravel | UI-06 |
| 14 | JS 失敗仍可閱讀與導航 | 規劃書v5 §9.3 | Pages Router 伺服器渲染；關閉 JS 的 E2E | FE-07 |
| 15 | 存取紀錄不記查詢字串 | gateway、app-web、wp-web 三層只記 `$uri` | gateway 規則不變；前端容器不另記請求紀錄 | DAT-02 |

---

## 4. 目標架構

### 4.1 架構圖（next 模式）
```
訪客瀏覽器
   │ HTTPS
   ▼
gateway（Nginx，唯一對外入口；edge 網路固定 .10）
   ├─ 公開頁、/_next/*、未列路徑 ─────────► 前端容器（Next.js standalone，node server.js）
   │                                          │ 伺服器端呼叫，內部網路
   │                                          ▼
   ├─ /admin、商店、/preview、/feed.xml、 ──► app-web ──► app-php（Laravel）──► wp-web／wp-php（WordPress）
   │   /sitemap.xml、/healthz、/readyz、/build
   └─ CMS 網域 ───────────────────────────► wp-web
```
blade 模式：所有主站路徑照現在一樣交給 app-web，前端容器照常啟動但不接流量。

### 4.2 路徑分工表（next 模式）

| 路徑（依 mysite `app/routes/*.php` 實查） | 交給 | 說明 |
|---|---|---|
| `/_next/`（`/_next/image` 除外，gateway 直接回 404） | 前端容器 | Next.js 的程式與樣式資產（檔名帶雜湊）與站內換頁資料 |
| `/`、`/articles`、`/articles/{slug}`、`/videos`、`/videos/{slug}`、`/podcasts`、`/podcasts/{slug}`、`/pages/{slug}`、`/categories/{slug}`、`/tags/{slug}`、`/search` | 前端容器 | 公開頁 |
| 其他未列路徑 | 前端容器 | 404 頁由前端畫 |
| `/admin`、`/admin/*`（含 Fortify 登入、2FA、重設密碼） | app-web | 登入 POST 限流規則照舊 |
| `/shop`、`/shop/*`、`/cart`、`/cart/*`、`/checkout`、`/checkout/*`、`/orders/*` | app-web | 商店（D7） |
| `/preview/*` | app-web | 草稿預覽（D6） |
| `/feed.xml`、`/sitemap.xml` | app-web | 只由主站產生 |
| `/healthz`、`/readyz` | app-web | 健康檢查 |
| `/internal/deploy-status` | app-web | 現行規則照舊；其他 `/internal/` 仍回 404 |
| `/build/*` 與 Laravel `public/` 根目錄下的實體檔（以 F6 實查清單為準） | app-web | 後臺、商店、預覽頁仍需 Vite 資產 |
| `/api/*` | production：gateway 直接回 404；staging：依 D5 | 前端走內部網路，不經 gateway |

### 4.3 一次頁面請求怎麼走
1. 訪客開 `/articles/某篇`。gateway 帶上 `X-Request-Id`（`$request_id`，32 位十六進位）轉給前端容器。
2. 前端的 `getServerSideProps` 呼叫 `src/server` 資料層 → 經內部網路打 `http://app-web:8080/api/v1/articles/某篇`，並轉送同一個 `X-Request-Id`（Laravel `AssignRequestId` 只在符合 `^[a-f0-9]{32}$` 時沿用，出處：mysite `app/app/Http/Middleware/AssignRequestId.php`）。
3. Laravel 走既有「控制器 → `ContentQueryService` → `ContentRepository` → `WordPressRestClient`」，持有未過期的可見性證明才回正文；確認失敗回 503、找不到回 404。
4. 前端照狀態碼回應：200 畫文章；404、503 都由頁面自己設 `res.statusCode` 並以完整版面（含即時選單）畫錯誤頁，附同一個請求編號。前端不保存任何內容副本。
   - 不用 `notFound: true`：它會改用 `pages/404`，而 Pages Router 的 `pages/404`、`pages/500` 都在建置時靜態產生（出處：https://nextjs.org/docs/pages/building-your-application/routing/custom-error ），拿不到即時選單，也印不出請求編號（現行 E2E 要求 404 頁有「請求編號」）。
   - 不存在的路徑由根層的萬用路由（`src/pages/[...path].tsx`）接住，同樣回 404 與完整版面；Next.js 的路由優先序是「固定路徑 > 動態 > 萬用」，不會蓋掉其他頁。
   - 未預期的例外（500）走 `_error` 的伺服器端渲染，也顯示請求編號；不建立靜態的 `404.tsx`、`500.tsx`。
5. 撤下：WordPress 改狀態 → 內容事件 → Laravel 清正文與證明；下一次請求就拿不到。前端沒有自己的快取，所以撤下時間與 blade 模式相同。
6. 站內換頁：Pages Router 經 `/_next/data/<建置編號>/….json` 再跑一次 `getServerSideProps`（出處：https://nextjs.org/docs/pages/building-your-application/data-fetching/get-server-side-props ），一樣即時取資料。

### 4.4 兩種資料模式

| 模式 | 用在哪 | 資料從哪來 | 需要秘密嗎 |
|---|---|---|---|
| 假資料（未設定時的預設） | MyDyad 預覽、前端 CI | 前端儲存庫 `mocks/` 的 JSON | 不需要 |
| 真資料 | 正式與 staging 容器、mysite CI、（D5 核准時）委託人電腦連 staging | Laravel 公開 API | 不需要（API 只回公開內容） |

防誤用：
- 守門依據 mysite 既有的部署環境變數 `DEPLOY_ENVIRONMENT`（compose.prod 已列為必填）：值為 `staging` 或 `production` 而資料模式不是真資料 → 啟動即失敗退出。不以 `NODE_ENV` 判斷，因為前端 CI 要用「正式建置＋假資料」跑 E2E，`next start` 本身就會設 `NODE_ENV=production`。
- 正式映像不含 `mocks/` 與 `public/mock-media/`（CI 檢查映像內容），即使守門被繞過也沒有假資料可用。
- 假資料用的示例圖檔與音檔放 `public/mock-media/`（合成素材、無授權疑慮）；F2 的匯出工具把媒體網址改寫成這個路徑，前端 CI 才不會向外部網址發請求。
- 假資料只放示例內容（合成文字），**不得放未公開稿件或委託人的創作原文**：MyDyad 的 Build 模式會把專案內 `.json` 檔連同程式碼送給 AI 模型當脈絡（副檔名白名單含 `.json`，出處：`MyDyad\src\utils\codebase.ts` 第 18–62 行）。

### 4.5 渲染模式開關與回復
- gateway 現行已用變數式上游（`set $mysite_app_upstream http://app-web:8080; proxy_pass $mysite_app_upstream;`）與每 10 秒重新解析（`resolver 127.0.0.11 valid=10s`）。開關沿用同一手法：以 `map` 依環境變數選擇公開頁的上游（`blade` → app-web；`next` → 前端容器），http 與 https 兩套範本都要加。
- 正式與 staging 的開關值由簽章發布清單提供的環境決定（與 `MYSITE_GATEWAY_MODE` 同一管道），不允許在主機上手改。
- 回復：發一份新序號的已簽章清單，把開關改回 `blade`；Blade 公開頁在 F9 之前一直保留且持續受 CI 測試，所以回復時一定有一個已驗證的去處。

### 4.6 容器與網路
- 現況（實查 `infra/compose.base.yml`）：gateway 只接 `edge`（固定 `.10`）；app-web 接 `edge` 與 `backend`；`edge` 不是 internal（可對外連線），`backend`、`db` 是 internal。
- 前端容器**不比照 app-web 掛 `edge`＋`backend`**，原因有三：①`edge` 可對外連線，而前端執行時完全不需要上網；②同時接兩張網路時，`app-web` 這個名稱解析到哪一張網路的位址不確定，以網段做允許名單會失準；③`backend` 上還有 PHP 服務，前端不需要接觸。
- 建議新增兩張 internal 網路：
  - 「gateway↔前端」網路：只有 gateway 與前端容器，gateway 經它把公開頁轉給前端。
  - 「前端↔app-web API」網路：只有前端容器與 app-web；app-web 在這張網路上設一個專用別名，前端只用這個別名連線；app-web 對來自這個網段的請求**只放行 `/api/v1/`**，其餘一律 404（含 `/internal/`）。
  - 兩張網路的名稱與子網位址依現行編排慣例（edge／backend／db 各用一個 `/24`，出處：`infra/compose.base.yml`）由施工者提出，列為 D13f 呈委託人裁定。
- 這樣前端容器沒有任何對外連線能力，也只碰得到 app-web 的 API。
- 映像：`output: 'standalone'`；多階段建置；以非 root 使用者執行；`CMD ["node","server.js"]`；`PORT=3000`、`HOSTNAME=0.0.0.0` 為官方範例預設（出處：https://github.com/vercel/next.js/tree/canary/examples/with-docker ）。這是容器內部埠，不對主機發布。
- healthcheck：Node 精簡映像沒有 curl，改用 `node -e` 呼叫容器內的健康路由；健康路由不依賴 Laravel（避免後端暫停時前端被誤判失效而重啟）；這條路由不經 gateway 對外。
- `EXPECTED_SERVICES` 名單寫死在三處（mysite `tests/governance/persistence.sh:92`、`tests/governance/resources.sh:85`、`scripts/cloud/doctor.sh:69`），新增服務時三處都要改。

---

## 5. 公開唯讀 JSON API 契約草案

### 5.1 原則
1. 只有 GET。路由群組不掛 `StartSession`、`ShareErrorsFromSession`、`PreventRequestForgery`（比照 mysite `app/routes/content.php` 的做法），回應不得帶 `Set-Cookie`。
2. 回應一律 `Cache-Control: no-store`：任何一層都不得替內容保留副本。
3. 控制器沿用既有 `ContentQueryService`，不得另寫一條讀 WordPress 的路；可見性、淨化、錯誤分類全部重用。
4. 只回公開內容與畫面需要的欄位；不得出現服務帳號、email、預覽資料或內部診斷。
5. 時間一律 UTC ISO 8601（mysite ADR-012 時區）。
6. 富文字欄位（`*_html`）一律是 `Sanitizer` 的輸出；前端不再淨化、也不自行組 HTML。
7. 不裝 Sanctum：Laravel 官方的 `php artisan install:api` 會安裝 Sanctum（出處：https://laravel.com/docs/13.x/routing ），本案的 API 不需要認證；改為自建 `routes/api.php`，比照 mysite 現行在 `bootstrap/app.php` 的 `withRouting(then: …)` 載入 `health.php`、`internal.php` 的做法掛上（官方文件也以 `then` 閉包示範註冊額外路由檔，出處同上）。

### 5.2 端點

| 端點 | 用途 | 參數規則（沿用現行） |
|---|---|---|
| `GET /api/v1/site` | 網站名稱、標語、作者簡介、社群連結、Logo、主選單、頁尾選單、商店是否開啟（現由 `View::composer('layouts.app')` 提供的同一批資料）。WordPress 讀不到時沿用現行的內建後備選單（`ContentQueryService::fallbackMenu()`），不回 503，讓 503 錯誤頁仍有版面可畫（出處：mysite `app/app/Providers/ContentServiceProvider.php` 註解「讀不到 WP 時改用內建後備，不讓整頁失敗」） | 無 |
| `GET /api/v1/home` | 首頁區塊 | 數量沿用 `HOME_POSTS=6`、`HOME_MEDIA=4`（出處：`ContentQueryService` 常數） |
| `GET /api/v1/articles`、`/videos`、`/podcasts` | 列表 | `page` 規則 `^[1-9]\d{0,3}$` 且 ≤1000，每頁 12（出處：`ContentController`、`ContentRepository` 常數） |
| `GET /api/v1/articles/{slug}`、`/videos/{slug}`、`/podcasts/{slug}` | 單篇 | slug 規則 `[^/]{1,200}`（出處：`app/routes/content.php`） |
| `GET /api/v1/pages/{slug}` | 一般頁面 | 同上 |
| `GET /api/v1/categories/{slug}`、`/tags/{slug}` | 分類、標籤彙整 | `page` 同上 |
| `GET /api/v1/search` | 站內搜尋 | `q` 最長 100 字（出處：`ContentController::SEARCH_MAX_LENGTH`）、`page` 同上 |

路徑前綴 `/api/v1` 是建議值，F1 定稿；定稿後受版本管理，破壞性變更須開 `v2`。

### 5.3 資料形狀（草案；以既有 DTO 為本，F1 定稿）
- **摘要**（列表用，對應 `ContentItem` 去掉 `body`）：`type`、`slug`、`path`、`title`、`excerpt`、`published_at`、`modified_at`、`author_name`、`cover`（可為 null）、`categories[]`、`tags[]`、`is_sample`。
- **單篇**：摘要＋`content_html`、`seo`（對應既有 `App\Domain\Content\Seo\PageMeta`：`title`、`description`、`canonical_path`、Open Graph 欄位、`noindex`）。
  - 影片：`video`（`youtube_id` 已驗證為 `^[A-Za-z0-9_-]{11}$`，不合法時為 null 並附 `video_unavailable: true`；`youtube_url`；`duration_seconds`）。
  - Podcast：`podcast`（`audio_url`、`audio_mime`、`duration_seconds`；未通過來源或 MIME 政策時 `audio_url` 為 null 並附 `audio_unavailable: true`，簡介照常提供）。
- **圖片**（對應 `Image`）：`url`（只輸出通過 `isAllowedMediaUrl` 的網址）、`alt`、`width`、`height`。
- **分類／標籤**（對應 `Term`）：`slug`、`name`、`path`。
- **選單項目**（對應 `MenuItem`）：`label`、`url`、`external`。
- **網站**（對應 `SiteInfo`）：`name`、`tagline`、`author_bio_html`、`social_links[]`、`logo`、`is_sample`、`menus.primary[]`、`menus.footer[]`、`commerce_enabled`。
- **分頁**（對應 `ContentPage`）：`meta.page`、`meta.per_page`、`meta.total`、`meta.total_pages`。

### 5.4 錯誤格式
沿用現行 `ErrorRenderer` 的 JSON 分支（出處：mysite `app/app/Support/Errors/ErrorRenderer.php`）：
```json
{ "error": "CONTENT_NOT_FOUND", "message": "找不到這篇內容", "request_id": "0123456789abcdef0123456789abcdef" }
```
錯誤碼只用規劃書v5 既有表、且語意相符者：`CONTENT_NOT_FOUND` 404、`CONTENT_UPSTREAM_UNAVAILABLE` 503、`CONTENT_MODEL_UNSUPPORTED` 503、`RATE_LIMITED` 429（出處：規劃書v5 第 780–789 行）。現行 `page` 超界一律回 404，API 照舊。若真需要新錯誤碼，須同時補進 mysite `docs/API契約.md` §6，不得只在程式裡新增。

### 5.5 存取範圍
- 一律：app-web 的 `/api/` 只接受「前端↔app-web API」網段的來源（§4.6），並對該網段只放行 `/api/v1/`。注意：現行 `InternalOnly` 不是網段判斷，它的規則是「來源不是受信任代理、且不帶 `X-Forwarded-For`／`Forwarded` 就放行」（實查 `app/app/Http/Middleware/InternalOnly.php`），前端容器直連 app-web 正好符合放行條件，所以 `/internal/` 必須另由上述網段規則阻擋在外。
- production：gateway 對 `/api/` 直接回 404。
- staging：依 D5。若開放，這是明文例外：gateway 經 `edge` 轉發 `/api/` 給 app-web，app-web 只對 gateway 固定位址 `.10` 放行 `/api/v1/`，gateway 對 `/api/` 套每 IP 限流（數值依 D13a 的裁定方式）。
- 前端以 §4.6 的專用別名連線；Laravel 是否需要設定可接受的 Host，由施工者在 F2 實測確認。

### 5.6 F2 必須先做的抽取
現行三項安全政策寫在 Blade 元件類別裡，API 不經過 Blade，若不先抽出，前端就能拿到未經政策過濾的值：
1. `YoutubePlayer`：影片 ID 驗證。
2. 圖片網址政策：`Sanitizer::isAllowedMediaUrl`（https 任一主機，或與 `WP_PUBLIC_URL` 同源；預覽只許後者）。
3. `AudioPlayer`：音訊 MIME 白名單與來源主機（`WP_PUBLIC_URL` 同源或 `PODCAST_AUDIO_ALLOWED_HOSTS`，僅 https）。

做法：抽成共用政策類別，Blade 元件與 API Resource 都呼叫同一個；既有 PHPUnit（如 `PublicRoutesTest::video_detail_embeds_only_after_click_and_shows_broken_state`）必須保持全綠，證明 Blade 行為未變。

### 5.7 契約的唯一來源
- 契約檔放 mysite（位置依 mysite ADR-001 的路徑契約定稿，名稱由施工者定），採 **OpenAPI 3.0.3**：PHP 端三套回應驗證套件都沒有明確支援 3.1 驗證（`league/openapi-psr7-validator` 0.24 只寫 3.0.x，出處：https://github.com/thephpleague/openapi-psr7-validator ）。F1 先做可行性測試再定。
- mysite 端：PHPUnit 以契約驗證實際回應。
- 前端端：以同一份檔案產生 TypeScript 型別（`openapi-typescript` 支援 3.0／3.1）；`src/server` 執行期再以 zod 驗一次，不符即當 `CONTENT_MODEL_UNSUPPORTED` 回 503（與 Laravel 讀 WordPress「資料格式不符回 503」同一原則，出處：建置總結 §5.2）。
- 前端儲存庫保存「已鎖定版本」的契約副本（含來源 commit），只能由施工者同步；CI 比對副本雜湊。

---

## 6. 前端儲存庫設計

### 6.1 技術選型（查核日 2026-11-05；施工當日須重查並寫入版本鎖定）

| 項目 | 查核結果 | 本案用法 | 出處 |
|---|---|---|---|
| Next.js | 最新穩定 16.4.5（2026-11-03）；16.4 為 Active LTS；舊線 15.5.29 | 鎖定 16.4.x 最新修補版，不得低於 16.4.5 | https://nextjs.org/blog/november-2026-security-release |
| 路由模式 | Pages Router 官方文件仍維護（2026-03-03 更新） | 只用 Pages Router，不建立 `app/` | https://nextjs.org/docs/pages/api-reference/functions/get-server-side-props |
| React | 最新穩定 19.3.1（2026-10-14）；19.3.0 受 GHSA-xqc2-xf4c-x3h3 影響 | 鎖定 19.3.1 以上 | https://github.com/react/react/security/advisories/GHSA-xqc2-xf4c-x3h3 |
| Node.js（正式與 CI） | 26 為 Active LTS（2026-10-28 起），最新 26.13.0；2026-11-11 有預告的安全發布 | 沿用 mysite 的 `NODE_IMAGE_REF`（digest 鎖定）；11-11 之後重查 | https://nodejs.org/en/blog/vulnerability/november-2026-security-releases |
| Node.js（委託人電腦） | 已裝 v24.18.0（本機實查）；24 線 2026-10-20 轉維護 | 預覽可用（D11） | https://raw.githubusercontent.com/nodejs/Release/main/schedule.json |
| 打包器 | 16.x 的 `next dev`／`next build` 預設 Turbopack；專案有自訂 webpack 設定時 `next build` 會直接失敗，須明寫 `--webpack` 或 `--turbopack` | 見 §6.6 | https://nextjs.org/docs/app/guides/upgrading/version-16 |
| Tailwind CSS | 4.3.4（2026-11-02） | D4 | https://registry.npmjs.org/tailwindcss/latest |
| zod | 4.6.7（2026-10-12） | 執行期回應驗證 | https://api.github.com/repos/colinhacks/zod/releases/latest |
| openapi-typescript | 7.13.0（2026-02-11），支援 OpenAPI 3.0／3.1 | 由契約產生型別 | https://registry.npmjs.org/openapi-typescript/latest |
| Playwright | 1.64.1（2026-10-14）；mysite 現用 1.63.0（`tests/e2e/package.json`） | 前端 CI 先與 mysite 用同一版本；升級另案 | https://github.com/microsoft/playwright/releases |
| @axe-core/playwright | 4.14.0（2026-10-15） | 無障礙掃描 | https://api.github.com/repos/dequelabs/axe-core-npm/releases/latest |
| MyDyad 元件標記器 | `@dyad-sh/nextjs-webpack-component-tagger` 0.8.0（npm 最新版，授權 Apache-2.0），只有 webpack 載入器；Turbopack 支援的上游 issue #2265 仍未關閉（2026-11-05 查） | 開發用相依套件，只在開發模式掛上 | `MyDyad\packages\@dyad-sh\nextjs-webpack-component-tagger\package.json`；https://github.com/dyad-sh/dyad/issues/2265 |

**為何是 Pages Router（安全面）**：2025-12 起的 React Server Components 系列漏洞（含 CVSS 10.0 的 CVE-2025-55182），Next.js 官方公告明載 Pages Router 不受影響（出處：https://nextjs.org/blog/CVE-2025-66478 ）。2026 年 Next.js 的重大公告集中在 Proxy（舊稱 middleware）繞過、Server Actions、`use cache`、圖片最佳化、`next/og`、rewrites 與自訂伺服器（出處：https://github.com/vercel/next.js/security/advisories ）。本案一律不用這些功能：

| 功能 | 本案 | 理由 |
|---|---|---|
| App Router／Server Components／Server Actions | 不用 | 攻擊面；頁面無法回 503（出處：https://github.com/vercel/next.js/discussions/53225 ） |
| `proxy.ts`（舊 `middleware.ts`） | 不用 | 多次繞過公告；本案沒有需要它的功能 |
| 圖片最佳化 | 關閉（`images.unoptimized: true`），內容圖用一般 `<img>` | 2026-08-25 的 AVIF 遠端執行（出處：https://nextjs.org/blog/august-2026-security-release ）與 2026-10-14 的 SVG 遠端執行（出處：https://nextjs.org/blog/nextjs-security-update-october-14-2026 ）都在這裡；2026-11-03 又有 SVG 繞過（中度）；圖片尺寸已由 WordPress 提供 |
| `next/og`、`use cache`、外部 rewrites、自訂伺服器 | 不用 | 皆有 2026 年公告 |
| `x-powered-by` | 關閉（`poweredByHeader: false`） | 減少指紋 |
| 遙測 | 關閉（正式與 CI 設 `NEXT_TELEMETRY_DISABLED=1`；委託人電腦執行一次 `next telemetry disable`） | 隱私（出處：https://nextjs.org/telemetry ） |
| `next/font/google` | 不用；字型用系統字型，或施工者放進 `public/fonts/` 的授權字型檔 | 它在建置時需連 Google；新增第三方依賴須同步改隱私說明（DAT-04） |

CI 另加「禁用功能檢查」：出現 `app/` 或 `src/app/` 目錄、`proxy.ts`、`middleware.ts`、`next/og`、`'use server'`、`'use cache'`、`cacheComponents`、Edge 執行環境（`runtime: 'edge'`）、`next.config` 的 `rewrites` 指向外部網域、`next/font/google`，或 `src/pages/api/` 底下出現健康檢查以外的路由，一律失敗。佐證：2026-11-03 的 Next.js 安全發布共七項，全部落在 Cache Components／`use cache`、Edge 路由、圖片最佳化與「原樣轉傳外部回應」這幾類本案不用的功能（出處：https://nextjs.org/blog/november-2026-security-release ）。

### 6.2 目錄結構與保護等級
```
（前端儲存庫根目錄）
├─ AI_RULES.md                 受保護｜MyDyad 每次對話都會讀
├─ AGENTS.md                   受保護｜內容與 AI_RULES.md 相同（CI 比對）；給 MyDyad Agent 模式底層的 OpenCode 讀
├─ CLAUDE.md                   受保護｜只給 Claude Code 施工者的須知（見 §6.7 末）
├─ README.md                   受保護｜給人看的說明（正體中文，含 §8 操作步驟）
├─ contracts/                  受保護｜公開 API 契約副本、DOM 測試掛鉤清單
├─ mocks/                      受保護｜假資料（只放示例內容）
├─ src/
│  ├─ pages/                   受保護｜薄頁面：只接「取資料函式」與「畫面元件」
│  ├─ server/                  受保護｜資料層：真資料／假資料、回應驗證、錯誤分類
│  ├─ lib/                     受保護｜型別、臺北時間格式、網址工具
│  ├─ components/
│  │  ├─ safe/                 受保護｜SafeHtml、YouTubeFacade、PodcastPlayer、ContentImage、SkipLink、ErrorView
│  │  └─ （其他）              開放｜卡片、導覽、分頁、按鈕……
│  ├─ views/                   開放｜每種頁型一個畫面元件
│  └─ styles/                  開放｜設計 token、全站樣式
├─ public/design/              開放｜設計用靜態圖（不得放外部連結）
├─ public/fonts/               受保護｜授權字型檔（施工者放入並記授權）
├─ public/mock-media/          受保護｜假資料用的合成圖檔與音檔（正式映像不複製）
├─ tests/                      受保護｜單元、元件、假資料 E2E
└─ 設定檔                      受保護｜package.json、package-lock.json、.npmrc、next.config.*、tsconfig.json、ESLint 設定、.gitignore、.gitattributes、.nvmrc、.github/
```

頁型與畫面元件：首頁、文章列表、影片列表、Podcast 列表、文章、影片、Podcast、一般頁面、分類、標籤、搜尋、404、503、500（對應 mysite `app/routes/content.php` 的全部公開路由與錯誤頁）。

一個頁面檔長這樣（示意）：
```tsx
// src/pages/articles/[slug].tsx —— 受保護，只做接線
import { articleDetailProps } from '@/server/pages/article';
import ArticleView from '@/views/ArticleView';
export const getServerSideProps = articleDetailProps;
export default ArticleView;
```
只在 `getServerSideProps` 用到的匯入不會打包進瀏覽器端（出處：https://nextjs.org/docs/pages/api-reference/functions/get-server-side-props ）。MyDyad 只需要改 `ArticleView`，看到的 props 型別固定。

### 6.3 資料層（`src/server`）
- 唯一入口：頁面只能經 `src/server/pages/*` 取資料；其他地方不得出現 `fetch`（ESLint 與 CI 阻擋）。
- 真資料：伺服器端呼叫環境變數指定的 API 根網址（名稱由施工者定稿，**不得沿用 Laravel 已用的 `CONTENT_API_BASE_URL`**，避免兩個服務同名不同義）；每次請求都呼叫，不快取；轉送 `X-Request-Id`。
- 假資料：讀 `mocks/` 的 JSON；未設定資料模式時預設使用。
- 組網址的規矩（防路徑穿越）：
  - slug 先驗 `^[^/]{1,200}$`（沿用 `app/routes/content.php`），且不得為 `.` 或 `..`；`page` 照 §5.2 規則；不合格直接回 404，不送出請求。
  - 每一段都用 `encodeURIComponent` 編碼，用 URL 物件組成完整網址，送出前斷言路徑以 `/api/v1/` 開頭，否則視為程式錯誤。
  - 單元測試至少涵蓋：`..`、`%2e%2e`、`%2F`、`%00`、中文 slug（WordPress 的百分比編碼）、200 字 slug、`q` 含 `?`、`#`、`&`、`/` 與全形字。
  - 第二道防線在 app-web：來自前端網段的請求只放行 `/api/v1/`（§4.6、§5.5）。
- 回應驗證：zod；不符即 503。
- 狀態碼：404、503 → 設 `res.statusCode` 並交 `ErrorView`（完整版面＋請求編號）；不存在的路徑由萬用路由接住（§4.3 第 4 步）。
- 傳輸層失敗一律當 503：連線被拒（例如 app-web 重建中）、逾時、回應不是 JSON、回應格式驗證失敗，全部回 503 並附請求編號，與現行「上游不可用回 503」一致（建置總結 §5.2）；只有前端自身的程式錯誤才走 `_error`（500）。
- `/api/v1/site` 本身也取不到時，錯誤頁改用 `ErrorView` 內建的靜態頁首與頁尾（站名取環境變數、只放「回首頁」與「隱私說明」連結），仍附請求編號。
- 每一頁都另取 `/api/v1/site`（品牌與選單）；v1 不在前端快取任何資料，連選單也不快取，因為選單變更同樣要在撤下期限內反映（API-15）。
- 頁面 props 只放畫面需要的欄位（`getServerSideProps` 的 props 會原樣出現在 HTML 裡，出處同上）。
- 正式守門：見 §4.4（依 `DEPLOY_ENVIRONMENT`）。
- 逾時：見 D13c。
- 網址：canonical 與 og:url 以環境變數設定的主站公開網址組成，不讀請求的 Host 標頭。
- `<head>` 內容逐項比照現行 `layouts/app.blade.php`：`<html lang="zh-Hant-TW">`、title 格式「頁面標題｜站名」、description、canonical、Open Graph、robots（noindex 時為 `noindex, nofollow`）、`og:url`／`og:type`／`og:image`／`og:image:alt`、RSS 連結 `<link rel="alternate" type="application/rss+xml" href="/feed.xml">`；搜尋結果頁帶 noindex（出處：建置總結 §5.4）；由施工者逐項比對並寫進測試（2026-11-05 實查該檔）。
- 錯誤紀錄不得輸出完整網址的查詢字串（搜尋字詞可能含個資；比照 gateway 只記 `$uri`）。

### 6.4 受保護元件（行為對齊現行 Blade）

| 元件 | 必須複刻的現行行為（出處） | 測試 |
|---|---|---|
| `SafeHtml` | 全站唯一使用 `dangerouslySetInnerHTML`；只接 API 的 `*_html` | ESLint `react/no-danger` 全域禁用、只對此檔放行；CI 計數只允許一處 |
| `YouTubeFacade` | 伺服器只輸出 `<a data-youtube-start href="https://www.youtube.com/watch?v=…">`、海報（WordPress 封面）與 `data-embed-src`；點擊（左鍵、無修飾鍵）才建立 `https://www.youtube-nocookie.com/embed/{id}?autoplay=1&rel=0` 的 iframe，`referrerPolicy='strict-origin-when-cross-origin'`；無 JS 時是一般連結；ID 不合法顯示失效狀態（出處：mysite `components/youtube-player.blade.php`、`resources/js/app.js`） | `media.spec.mjs`：點擊前對 `YOUTUBE_HOST_RE` 列的網域（youtube.com、youtube-nocookie.com、youtu.be、ytimg.com、googlevideo.com、ggpht.com；出處：mysite `tests/e2e/helpers/site.mjs`）零請求 |
| `PodcastPlayer` | `<audio controls preload="none">`、片長、下載連結、`data-audio-error` 失效訊息與保留簡介（出處：mysite `components/audio-player.blade.php`） | `media.spec.mjs`、`infra.spec.mjs`（Range／206） |
| `ContentImage` | 載入失敗顯示明確失效狀態；寬高由 API 提供；`loading="lazy"` | `media.spec.mjs` |
| `SkipLink` | 第一個 Tab 是「跳到主要內容」，焦點落到 `#main` | `a11y.spec.mjs` |
| `ErrorView` | 404 標題含「找不到」、有「回首頁」連結；404、503、500 都顯示「請求編號」（出處：mysite `app/resources/views/errors/mysite.blade.php` 與現行 E2E 的 404 檢查） | E2E；FE-17 |

### 6.5 DOM 測試掛鉤契約
mysite 的 E2E 大量依賴 Blade 的 class 與原始 HTML 字串（實查：`.site-nav__list`、`.site-nav__link`、`#search-q`、`.skip-link`、`.media-frame.is-broken`、`.search-result`、`.player__audio-meta a[download]`，以及以正規式比對 `<title>`、canonical、`og:url`、robots meta 與 `href="/articles/…"`）。換成 Tailwind 設計後 class 一定會變，所以：
1. 定一份「DOM 測試掛鉤清單」：沿用現行已有的 `data-*`（`data-site-nav-toggle`、`data-youtube-player`、`data-youtube-start`、`data-embed-src`、`data-audio-player`、`data-audio-error`、`data-media-img`、`data-sim-notice` 等）與 `id`（`id="main"`、`id="search-q"`），class 型選擇器改為新增的 `data-*` 掛鉤。
2. Blade 樣板同步補上這些 `data-*`（不改外觀），E2E 改用掛鉤；原始 HTML 正規式改為 DOM 查詢，避免屬性順序不同就失敗。
3. 清單正本放 mysite 的 E2E 目錄，前端儲存庫 `contracts/` 保存同步副本；前端 CI 以假資料逐頁檢查每個畫面元件都有該有的掛鉤。
4. AI_RULES.md 明文禁止刪改掛鉤。
5. MyDyad 的元件標記器在開發模式會替元素加 `data-dyad-id`、`data-dyad-name`（出處：`MyDyad\packages\@dyad-sh\nextjs-webpack-component-tagger\src\index.ts` 第 55–66 行）；這兩個屬性**不得出現在正式建置**，CI 檢查。

### 6.6 開發與建置指令（配合 MyDyad 實際行為）

| 項目 | 設定 | 依據 |
|---|---|---|
| `package.json` 的 `packageManager` | 寫 `npm@<版本>` | 讓 MyDyad 固定選 npm（`MyDyad\src\ipc\utils\package_manager_selection.ts` 第 46–93 行） |
| `.npmrc` | 只放 `legacy-peer-deps=true` 與 `save=false`，不放任何憑證。`save=false` 的作用：npm 官方文件寫明它「也會阻止寫入 `package-lock.json`」（出處：https://docs.npmjs.com/cli/v11/using-npm/config ），MyDyad 每次啟動預覽都會跑一次 `npm install`，有了它就不會改寫鎖定檔；施工者要新增套件時須明寫 `npm install <套件> --save`，寫進前端儲存庫的 `CLAUDE.md` | MyDyad 預覽的 npm 路徑一律加 `--legacy-peer-deps`（`MyDyad\src\ipc\services\app_runtime_service.ts` 第 150–152、204–209 行），但 Agent 模式驗證缺 `node_modules` 時跑的是不帶旗標的 `npm install`（`MyDyad\src\sovereign\verification\project_checks.ts` 第 92–100 行）；寫進 `.npmrc` 才能讓兩條路、以及 Linux 產生鎖定檔時都用同一語意，避免委託人電腦安裝時改寫鎖定檔 |
| `dev` 指令 | `next dev --webpack -H localhost` | ①元件標記器只支援 webpack；②只綁本機迴路位址，區網看不到（Next.js 不帶 `-H` 時預設綁 `0.0.0.0`，出處：https://nextjs.org/docs/app/api-reference/cli/next ）。這一點對委託人電腦特別要緊：2026-08 公告的 CVE-2026-75604 是「Next.js 在 Windows 檔案系統上執行時可遭未經認證遠端執行」，Pages Router 也在影響範圍，16.3.3 起修補（出處：https://nextjs.org/blog/august-2026-security-release ）。附帶確認：MyDyad 只在 dev 伺服器輸出符合 `https?://localhost:\d+` 時才啟動預覽代理（`app_runtime_service.ts` 第 804–821 行），而 Next.js 不論帶不帶 `-H localhost`，印出的主機名都是 `localhost`（出處：Next.js 原始碼 `packages/next/src/server/lib/start-server.ts`〔canary 分支〕第 335–340、366 行，2026-11-05 查），所以這個寫法不影響預覽。MyDyad 會自動在後面接 `--port <埠>`；若實測預覽代理仍沒啟動，依 F4 第 5 步的備案處理 |
| `build` 指令 | 標記器可用時 `next build --webpack`；不可用時 `next build` | 有自訂 webpack 設定時不明寫打包器，`next build` 會失敗（https://nextjs.org/docs/app/guides/upgrading/version-16 ）；預覽與正式同用 webpack，避免兩種打包器的樣式順序差異讓「預覽好看、上線跑版」 |
| `lint` 指令 | `eslint .`（不能用已移除的 `next lint`） | Next.js 16 移除 `next lint`；MyDyad Agent 模式會自動跑存在的 lint |
| `typecheck`、`test` 指令 | 只做型別檢查與快速單元測試；E2E 另取名（例如 `test:e2e`） | MyDyad Agent 模式會自動跑 `test`（`project_checks.ts` 第 88–106 行），不能在委託人電腦觸發瀏覽器測試 |
| `.gitignore` | 預先加入 `.dyad/`、`.mydyad/`、`.next/` | MyDyad 會自動往 `.gitignore` 追加 `.dyad/`（`MyDyad\src\ipc\handlers\gitignoreUtils.ts` 第 43–45 行）；`.mydyad` 是 MyDyad 讀專案時當自家資料夾排除的名稱（`MyDyad\src\sovereign\context.ts` 第 20 行），一併預防；先寫好，就不會改到受保護檔 |
| `.gitattributes` | `* text=auto eol=lf` | 與 MyDyad 匯入時建立的內容相同（`MyDyad\src\ipc\utils\git_utils.ts` 第 588–601 行，既有檔不覆寫） |
| Next.js 16 的開發與建置輸出分離 | `next dev` 輸出到 `.next/dev`，可與 `next build` 同時執行 | MyDyad Agent 模式邊預覽邊建置不會互相打壞（https://nextjs.org/docs/app/guides/upgrading/version-16 ） |

### 6.7 AI_RULES.md 全文草案（放前端儲存庫根目錄）
MyDyad 只讀專案根目錄的 `AI_RULES.md` 這一個檔名，Agent 模式把它當「權威專案脈絡」（出處：`MyDyad\src\prompts\system_prompt.ts` 第 910–921 行、`MyDyad\src\prompts\local_agent_prompt.ts` 第 411–436 行）。匯入前就要放好，否則 MyDyad 會自動叫模型生成一份（`MyDyad\src\components\ImportAppDialog.tsx` 第 302–308 行），而且生成前套用的是 React Router＋Vite 的內建預設規則，與本案不符。

```markdown
# MySite 前端：給 AI 的規則

## 你的工作
你負責 MySite 公開網站的「畫面設計」：版面、配色、字級、間距、元件外觀與互動細節。
資料已經準備好，以 props 傳進 `src/views/` 的畫面元件。你不需要、也不可以自己抓資料。
預覽畫面上的內容全是示例資料（來自 `mocks/`）。

## 技術
- Next.js Pages Router（不要建立 `app/` 或 `src/app/` 目錄）、React、TypeScript、Tailwind CSS。
- 不使用 React Router，不使用 shadcn/ui。
- props 型別在 `src/lib/content/types.ts`（只讀）。

## 你可以改
- `src/views/**`、`src/components/**`（`src/components/safe/` 除外）、`src/styles/**`、`public/design/**`

## 你不可以改（改了 CI 會攔下）
- `src/pages/**`、`src/server/**`、`src/lib/**`、`src/components/safe/**`
- `contracts/**`、`mocks/**`、`tests/**`、`public/fonts/**`、`public/mock-media/**`、`.github/**`
- `package.json`、`package-lock.json`、`.npmrc`、`next.config.*`、`tsconfig.json`、ESLint 設定、`.gitignore`、`.gitattributes`、`.nvmrc`、`AI_RULES.md`、`AGENTS.md`、`CLAUDE.md`、`README.md`
- 需要動這些地方時：停下來，用一段話寫清楚需求，交給施工者處理。

## 硬規則
1. 不新增任何套件，不執行安裝某個套件的指令。
2. 不呼叫任何網址：不用 fetch、axios、XMLHttpRequest、WebSocket；不載入外部字型、外部圖片、外部 script、分析或追蹤碼。
3. 不使用 dangerouslySetInnerHTML。正文一律用 `<SafeHtml html={...} />`。
4. YouTube 一律用 `<YouTubeFacade>`；Podcast 一律用 `<PodcastPlayer>`；內容圖片一律用 `<ContentImage>`；錯誤畫面一律用 `<ErrorView>`。
5. 不寫 cookie、localStorage、sessionStorage。
6. 保留所有 `data-*` 測試掛鉤、`<main id="main">`、跳到主要內容連結、`<header>`、`<nav>`、`<footer>` 與標題階層；不得刪除或改名。
7. 介面文字一律正體中文（臺灣用語）；日期只用 `formatTaipeiDate()`。
8. 手機 390、平板 768、桌面 1440 寬度都不能出現水平捲軸；超長中文標題與網址要能換行；200% 縮放也要能用。
9. 對比、焦點框、鍵盤操作要符合 WCAG 2.2 AA；尊重「減少動態效果」設定。
10. `is_sample` 為真時，保留「示例內容」標示。
11. 不建立新的頁面路由；需要新頁面時交給施工者。
12. 即使使用者說「記住這件事」，也不得修改 `AI_RULES.md` 或 `AGENTS.md`；請改為回覆一段文字，請使用者交給施工者寫入。
```
（390／768／1440、200% 與 WCAG 2.2 AA 的出處：規劃書v5 §9.2、§9.3。「Tailwind CSS」一行依 D4 裁定，若改裁定則隨之改寫。第 12 條的理由：MyDyad Agent 模式的系統提示寫明，使用者要求跨對話記住事項時，模型可以編輯 `AI_RULES.md`〔出處：`MyDyad\src\prompts\local_agent_prompt.ts` 第 418 行〕，若不先封住，日常一句「記住」就會改到受保護檔。）

**AGENTS.md 與 CLAUDE.md 的分工**
- MyDyad 的 Agent 模式底層是 OpenCode（MyDyad 以 `opencode serve` 啟動它，出處：`MyDyad\src\sovereign\agent\opencode_backend.ts` 第 82–107 行）。OpenCode 會讀專案根目錄的 `AGENTS.md`；只有在沒有 `AGENTS.md` 時才改讀 `CLAUDE.md`（出處：https://opencode.ai/docs/rules/ ）。
- 所以前端儲存庫放一份與 `AI_RULES.md` 內容相同的 `AGENTS.md`（CI 比對兩檔一致），讓 MyDyad 不論走哪條路都拿到同一套限制。
- 給 Claude Code 施工者的須知寫在 `CLAUDE.md`，開頭註明「只適用施工者；其他 AI 依 AI_RULES.md」。重點：施工者不改委託人的設計（`src/views`、`src/components`、`src/styles`），除非委託人要求；改受保護區要同步契約與測試；不推 main、不合併 PR。

### 6.8 前端 CI（給委託人快速回饋）
**觸發**：對 main 的 PR（`pull_request`）與 main 本身的推送。不對設計分支的推送另跑一次，避免同一個提交出現兩份同名檢查、其中一份卡住必要檢查。委託人推送後，在 GitHub 按黃色「Compare & pull request」開 PR，CI 就會跑；PR 開著時，之後每次推送都會自動重跑。PR 由委託人本人開，CI 不需要另按核准。

**主 CI（`pull_request`）依序**：
1. `npm ci`（Linux）——鎖定檔不一致即失敗。
2. 受保護路徑檢查：見下方「獨立的受保護路徑檢查」。
3. 禁用功能檢查（§6.1）。
4. 型別檢查、ESLint（含 `react/no-danger`、禁止 `fetch` 出現在 `src/server` 以外）。
5. 契約副本雜湊比對；假資料以契約驗證（含邊界案例：超長中文標題、超長網址、無封面、零篇、影片 ID 不合法、音訊失效、示例內容）。
6. 單元與元件測試（資料層狀態碼對應、正式守門、`SafeHtml` 唯一性）。
7. 正式建置：除 Next.js 內建錯誤頁外，路由全部必須是 `ƒ`（動態），出現 `○`、`●` 即失敗（符號定義出處：https://nextjs.org/docs/app/guides/building ，該頁明載未啟用 Cache Components 時 Pages Router 也用同一組符號）；正式產物不得含 `data-dyad-id`。
8. 假資料 E2E（Playwright，三種寬度，對正式建置跑）：無水平溢位、200% 縮放、axe 無嚴重與重大問題、鍵盤導覽、關閉 JS 仍可讀正文、YouTube 點擊前零請求、所有請求只到同源、零 cookie、DOM 掛鉤齊全。
9. 截圖：各頁型三種寬度存成產物，並在工作摘要用正體中文列出「哪一項沒過、可能原因、怎麼辦」。
10. 秘密掃描（gitleaks）；`npm audit --omit=dev --audit-level=high`（比照 mysite `dependency-audit.yml` 的門檻）。
11. `AGENTS.md` 與 `AI_RULES.md` 內容一致。

所有外部 Action 以完整 SHA 固定，工作流程權限預設唯讀（比照 mysite `ci.yml`）；失敗時在 PR 留一則正體中文留言（工作摘要在 Actions 執行頁、不在 PR 頁，委託人不容易找到），留言只需要 `pull-requests: write`。

**獨立的受保護路徑檢查（列為必要檢查）**
- 為什麼要獨立：`pull_request` 觸發時，執行的是 PR 分支自己的工作流程檔；設計分支若把 `.github/` 裡的檢查改掉或刪掉，檢查就形同不存在。D17 的「全權略過」開著時，MyDyad Agent 改到 `.github/` 並不需要委託人同意。
- 做法：改用 `pull_request_target` 觸發——它執行的是 main 上的工作流程定義，設計分支改不掉。這支工作流程只透過 GitHub API 讀 PR 的檔案清單，**不 checkout、不執行 PR 的任何程式碼、不使用任何秘密**，權限只有讀取與 PR 留言；這正是 mysite 規則「禁止拿 `pull_request_target` 執行不可信 PR 程式並提供秘密」所容許的用法（出處：雲端施工 Prompt 第 594 行）。
- 結果：`design/` 分支改到受保護路徑即失敗，PR 留言用正體中文寫「這個檔案受保護，請交給施工者」、列出檔名，並附本機 Claude Code 可直接執行的還原指令（還原單一檔案不靠 AI 口頭操作）。

**最後一道防線在 mysite**：F6 每次鎖定前端 SHA、建置映像前，用 mysite 自己的腳本對該 SHA 重跑同一組內容檢查（禁用功能、`SafeHtml` 只有一處、正式產物無 `data-dyad-id`、`AGENTS.md` 與 `AI_RULES.md` 一致），並把「與上一個鎖定 SHA 相比，受保護路徑改了哪些檔」列在升版 PR 說明裡供複核。前端儲存庫的工作流程檔即使被動過，這一關仍在 mysite 手上。

**main 的更新怎麼回到設計分支（不用自動化權杖）**
- 施工者把受保護區的修改合併進 main 之後，以一般推送把 main 合併進 `design/work`；委託人下次在 MyDyad 按「同步到 GitHub」就會取回。若 PR 頁出現「Update branch」按鈕，委託人也可以直接按它。
- 不用 `GITHUB_TOKEN` 自動化的原因：`GITHUB_TOKEN` 觸發的事件不會建立新的工作流程執行（`workflow_dispatch`、`repository_dispatch` 除外），以它開的 PR 其 CI 要另按核准（出處：https://docs.github.com/en/actions/concepts/security/github_token ），而且儲存庫的「允許 GitHub Actions 建立 PR」設定預設關閉。日後若要全自動，需要 GitHub App 或個人權杖存進 Actions 秘密，另案裁定。

### 6.9 正式映像（由 mysite 建置，不在前端儲存庫推映像）
- 多階段：相依套件 → 建置 → 執行；執行層只含 `.next/standalone`、`.next/static`、`public`（standalone 預設不複製後兩者，要手動複製，出處：https://nextjs.org/docs/app/api-reference/config/next-config-js/output ）。
- 不含 `mocks/`、`public/mock-media/`、`tests/`、原始碼地圖；以非 root 執行；`NEXT_TELEMETRY_DISABLED=1`。
- 注意：Next.js 建置時會靜態分析 `import`、`require` 與 `fs` 的用法，自動把可能讀到的檔案複製進 `.next/standalone`（出處：https://nextjs.org/docs/app/api-reference/config/next-config-js/output ）。假資料讀取器用 `fs` 讀 `mocks/`，可能被一併帶進去，所以 `next.config` 要以 `outputFileTracingExcludes` 排除 `mocks/**` 與 `public/mock-media/**`，並由 FE-09 檢查最終映像確實沒有。
- 官方 with-docker 範例會把整個 `public/` 複製進執行層（`COPY --from=builder /app/public ./public`；出處：`examples/with-docker/Dockerfile`〔canary 分支〕，2026-11-05 以 gh 讀取），照抄會把 `public/mock-media/` 帶進正式映像。最穩的做法是在 `.dockerignore` 直接排除 `mocks/`、`public/mock-media/`、`tests/`，讓它們連建置來源都進不去；正式建置本來就不需要它們。
- 官方範例在相依套件階段會一併複製 `.npmrc`，所以 §6.6 的 `legacy-peer-deps=true` 在映像建置時同樣生效。
- 原始碼依 mysite 鎖定的 commit 從前端儲存庫取得；前端儲存庫若為公開儲存庫（D3），建置時不需要任何權杖。鎖定的條件：必須是前端 main 上已合併、CI 全綠的提交，以完整 40 碼 SHA 取得。
- 外部儲存庫的 `package.json` 腳本與相依套件會在 mysite 的建置與發布工作中執行，所以相依套件階段以 `npm ci --ignore-scripts` 為預設；若有套件確實需要安裝腳本，列出並經審核（比照規劃書v5 第 496 行「插件要求執行安裝腳本時，先審核允許範圍」）。建置階段不掛簽章金鑰或套件登錄憑證，簽章與推送 GHCR 留在既有 release 流程的後段。
- Trivy 掃描比照現行：`--severity HIGH,CRITICAL --ignore-unfixed --exit-code 1`（出處：mysite `release.yml`）。

### 6.10 Windows 與 Linux 差異的防護
- `.gitattributes` 設 `* text=auto eol=lf`；MyDyad 每次 `git add` 也會把該庫本地設定成 `core.autocrlf=false`、`core.eol=lf`（出處：`MyDyad\src\ipc\utils\git_utils.ts` 第 561–577 行）。
- 鎖定檔只在 Linux 產生與更新（D9）；F4 實測委託人電腦第一次安裝後鎖定檔是否不變。
- 匯入路徑大小寫：Windows 不分、Linux 分；Linux CI 的建置會抓出來，委託人看到紅燈時照 §8.4 處理。
- MyDyad 的提交一律帶 `--no-verify`（出處：`MyDyad\src\ipc\utils\git_utils.ts` 第 927–938 行），所以不能靠 git hook 阻擋任何東西；把關全靠 CI 與分支保護。

---

## 7. 執行步驟

每一步都寫明：執行者、動作、產出、驗收。施工者一律在工作分支施工、推送、讀回 CI，不推 main、不合併 PR（雲端施工 Prompt §0.2）。

雲端施工者以「一個儲存庫一個雲端會話」分開施工：F0（施工者部分）、F1、F2、F6 在 mysite 會話；F3 在前端儲存庫會話。前端會話需要的契約與掛鉤清單，以唯讀方式從 mysite 公開儲存庫取得。

### F0　裁定與開通
- **執行者**：委託人；本機 Claude Code 與雲端施工者協助。
- **動作**
  1. 委託人裁定 D1～D17。
  2. 建立前端儲存庫（名稱 D2、可見性 D3）：委託人在 GitHub 網頁建立，或明確授權本機 Claude Code 以 `gh` 建立。
  3. 設定前端儲存庫（委託人在網頁設定，或授權本機 Claude Code 代辦）：
     - main 分支保護（D12 的 mysite main 比照）：需 PR 才能合併、核准人數設 0（只有委託人一人，無法自己核准自己的 PR）、需通過的必要檢查（名稱依 §6.8 定稿的工作名稱）、禁止強制推送、禁止刪除 main，並勾選「**管理員也不得繞過**」（API 欄位 `enforce_admins`）。
     - 儲存庫合併設定：只允許「Create a merge commit」，關閉 Squash 與 Rebase 合併；關閉「合併後自動刪除分支」。
     - 用傳統分支保護而不用 rulesets，驗收指令才查得到（rulesets 在下列指令會回 404）。
  4. 雲端施工者在 mysite 工作分支：新增 ADR-015；在 §1.4 表列的每一處加修訂註記；把本步驟書收進 `docs/`（§1.4）。
  5. 本機 Claude Code 確認 MyDyad 現況：MyDyadOne 閘道 11907 可用、現役 MyDyad 為 `out\MyDyadV1-build-20261104-205728`（出處：`MyDyadOne報告書v6.md` §五）、要用的模型線已登入、D17 的全權略過開關狀態，並記錄。
- **產出**：裁定紀錄（寫入 ADR-015）、前端儲存庫、分支保護與合併設定、mysite 內的施工依據。
- **驗收**：`gh api repos/<擁有者>/<前端儲存庫>/branches/main/protection` 回傳保護設定，且 `enforce_admins.enabled` 為 true；`gh api repos/<擁有者>/<前端儲存庫>` 的 `allow_squash_merge`、`allow_rebase_merge`、`delete_branch_on_merge` 皆為 false；ADR-015 的 PR 經 mysite CI 全綠、由委託人合併。

### F1　公開 API 契約定稿（mysite）
- **執行者**：雲端施工者。
- **動作**
  1. 寫契約檔（OpenAPI 3.0.3）與說明（併入 `docs/API契約.md` 新章節或另立檔，施工者定稿）。
  2. 每個端點附範例回應：正常、空列表、404、503、超長中文標題、超長網址、無封面、示例內容、影片 ID 不合法、音訊不可播、中文 slug（百分比編碼）、200 字 slug。
  3. 以範例檔試跑 PHP 端驗證套件（`osteel/openapi-httpfoundation-testing` 或 `hotmeteor/spectator`），確認可行再定。
  4. 附一張「畫面需要什麼 → 契約哪個欄位」對照表，讓委託人不必看技術細節也能確認沒漏。
  5. 定出「DOM 測試掛鉤清單」初版（§6.5）：前端 F3 要照它實作，所以必須在 F3 開工前定好；E2E 與 Blade 的對應改寫留到 F6。
- **驗收**：契約檔語法檢查通過；全部範例通過驗證；對照表涵蓋 §6.2 的全部頁型；掛鉤清單涵蓋現行 E2E 用到的全部 class 型選擇器。

### F2　Laravel 公開 API 實作（mysite）
- **執行者**：雲端施工者。
- **動作**
  1. 抽出三項政策為共用類別（§5.6），既有 PHPUnit 全綠。
  2. 自建 `routes/api.php`、在 `withRouting` 註冊；路由群組移除 Session 相關中介層；只 GET。
  3. API Resource 對應既有 DTO；錯誤走既有 `ErrorRenderer` 的 JSON 分支；全部回應 `Cache-Control: no-store`。
  4. 存取範圍（§5.5）：gateway 對外 `/api/` 回 404；app-web 只許 `backend` 網段。
  5. 匯出假資料工具：在 testing 環境以示例資料呼叫 API，產出前端 `mocks/` 用 JSON（只含示例內容），附邊界案例；媒體網址改寫為 `/mock-media/…`，並附對應的合成圖檔與音檔。
- **驗收（新增 ID，§9）**：PAPI-01～PAPI-08 全部 PASS；既有 356 項 PHPUnit 不退步（數字出處：建置總結 §1）。

### F3　前端儲存庫骨架（同等起點）
- **執行者**：雲端施工者（在前端儲存庫的工作分支）。
- **動作**
  1. 手動建立 Pages Router 結構（不使用 `create-next-app` 的推薦預設：它預設 App Router〔出處：https://nextjs.org/blog/next-16 〕，新建專案還會預設開啟 Cache Components〔出處：https://nextjs.org/docs/app/getting-started/caching 〕）。
  2. 鎖定版本（§6.1）；`.nvmrc` 對齊 mysite 的 Node 主版本、`engines` 同時容許委託人電腦的 24 線（D11）；在 Linux 以 §6.6 的 `.npmrc` 產生 `package-lock.json`。
  3. 實作資料層（§6.3）、受保護元件（§6.4）、薄頁面、錯誤頁、`<head>` 中繼資料、DOM 掛鉤（§6.5）、指令與設定（§6.6）。
  4. **元件標記器實測**：在 Next.js 16.4 的 webpack 開發模式裝上 `@dyad-sh/nextjs-webpack-component-tagger`，確認畫面元素帶有 `data-dyad-id`。可用 → `dev`、`build` 都用 webpack；不可用 → 移除標記器、`dev`、`build` 都回到預設 Turbopack，MyDyad 只用對話修改（不能點選元件），結果寫進 README。
  5. 畫面元件第一版**複刻現行 Blade 外觀**：把現行 CSS token（顏色、字級、間距、圓角、焦點、卡片、按鈕、導覽、分頁、播放器、訊息列、正文，出處：規劃書v5 §9.2）轉成 Tailwind 主題。切換前後訪客看到的一樣，之後才由委託人用 MyDyad 改新設計。
  6. 匯入 F2 匯出的假資料（F2 未完成前先用 F1 的範例回應）；寫 `AI_RULES.md`、內容相同的 `AGENTS.md`、施工者用的 `CLAUDE.md`（§6.7）與 README（正體中文，含 §8 的操作步驟）。
  7. 建主 CI 與獨立的受保護路徑檢查（§6.8），並把兩者的工作名稱回填到 F0 第 3 步的必要檢查；建立 `design/work` 分支（名稱依 D8）。
- **驗收**：FE-01～FE-07、FE-11、FE-12、FE-17 全部 PASS，FE-09 的守門與 `data-dyad-id` 兩部分、FE-10 的儲存庫與 CI 產物部分 PASS（§9；FE-08、FE-13 與 FE-09、FE-10 的映像部分屬 F6）；三種寬度截圖與現行 Blade 截圖並排供委託人目視確認。

### F4　MyDyad 接上與第一次設計循環
- **執行者**：委託人；本機 Claude Code 在旁協助並實測。
- **動作**（操作細節見 §8.1）
  1. 本機 Claude Code 把前端儲存庫 clone 到 D16 指定的資料夾，切到 `design/work`。
  2. 委託人開 MyDyadOne 面板 → 「開 MyDyad」→ 匯入該資料夾（取消勾選複製；不填自訂指令）。
  3. 委託人在 MyDyad 的 GitHub 面板連到既有儲存庫、選 `design/work`，以 GitHub 裝置碼流程親手登入。
  4. 第一次預覽完成（相依套件已裝好）後，本機 Claude Code 在專案資料夾執行一次 `npx next telemetry disable`（寫入使用者層設定，對所有 Next.js 專案生效），並以 `npx next telemetry status` 的輸出確認已停用（設定檔位於 AppData，依規矩不以 shell 查檔判斷）。
  5. 同時實測並記錄：預覽代理是否啟動；dev 伺服器是否只綁本機迴路位址（`Get-NetTCPConnection`）；預覽期間記憶體與 CPU。若預覽代理沒有啟動，改回不帶 `-H` 的 `next dev --webpack`；此時 Windows 防火牆若跳出詢問，選「不允許」，維持只有本機能連。這類備案（以及第 6 步的 pnpm 備案）都要改 `package.json` 或鎖定檔，屬受保護路徑，**一律由施工者在非 `design/` 分支開 PR 合併進 main，再合併回設計分支**，不在設計分支上直接改。
  6. 本機 Claude Code 在四個時點檢查 `git status`，`package-lock.json` 都必須沒有變動：第一次預覽後、第二與第三次啟動預覽後（此時 `node_modules` 已存在，正是 npm/cli#4828 所述的情形）、AI 改檔並自動提交後、關閉再重開 MyDyad 後。有變動 → 停下呈報，改採 D14 的備案（pnpm）或另議。
  7. 委託人做一次小設計（例如文章標題字級）→ 同步推送 → 看前端 CI → 在 GitHub 網頁合併。
  8. 收工：關閉 MyDyad；本機 Claude Code 確認專案資料夾相關的 Node 行程全部結束、記憶體歸還（MyDyad 關閉時會以 `taskkill /T /F` 結束預覽行程樹，出處：`MyDyad\src\ipc\utils\kill_process_tree_sync.ts` 第 49–58 行；強制結束 MyDyad 時是否留下孤兒行程未經查證，所以要實測）。
  9. 若 D15 有意常用 Agent 模式：試跑一輪，量測建置期間是否造成桌面卡頓；有卡頓就呈報 D13e。
  10. 本機 Claude Code 查出這個 app 的編號，算出預覽埠（32100＋編號）與代理埠（42100＋編號，出處：`MyDyad\shared\ports.ts` 第 35、85 行），比對本機埠分配表沒有撞到既有服務後登記進去。**這一步不能省**：MyDyad 每次啟動預覽前，會強制結束佔用該埠的任何行程（`killPort`，出處：`MyDyad\src\ipc\services\app_runtime_service.ts` 第 1397–1403 行）；若撞到委託人的常駐服務，那個服務會被直接關掉。撞到時停下呈報，不自行改動任何服務。
  11. 本機 Claude Code 確認 MyDyad Agent 模式是否讀到委託人的全域規則檔 `C:\Users\whenwhosay\.claude\CLAUDE.md`：OpenCode 的相容層會把它當全域規則的後備（出處：https://opencode.ai/docs/rules/ ），而 MyDyad 啟動 OpenCode 時沒有設定停用相容層的環境變數（出處：`MyDyad\src\sovereign\agent\opencode_backend.ts` 第 82–107 行）。MyDyad 把 OpenCode 的 `XDG_CONFIG_HOME` 指到自己的資料夾，那裡通常沒有全域 `AGENTS.md`，正是會退而讀 `C:\Users\whenwhosay\.claude\CLAUDE.md` 的條件（OpenCode 規則：全域 `AGENTS.md` 不存在時才讀它）。若實測會讀到，記錄並呈報；OpenCode 提供只停用這一項的環境變數 `OPENCODE_DISABLE_CLAUDE_CODE_PROMPT`（出處同上），要不要在 MyDyad 端設定，屬 MyDyad 案，不在本案施工。
- **驗收**：FE-14、FE-15、FE-16、FE-18 PASS。

### F5　頁面設計迭代（持續）
- **執行者**：委託人用 MyDyad；受保護部分交施工者。
- **做法**：一次只改一種頁型或一個元件；每次推送都看前端 CI 的截圖與紅綠燈；需要新欄位、新頁型、新套件時，寫一段需求交給施工者（雲端會話或本機 Claude Code），由施工者改受保護區並同步契約。

### F6　併入 mysite（mysite）
- **執行者**：雲端施工者。
- **動作**
  1. 前端版本鎖定：mysite 新增一個記錄「前端儲存庫網址＋完整 commit SHA」的鎖定項目（放 `infra/versions.env` 或獨立檔，施工者定稿；本次審查讀 `infra/versions.env` 被金鑰鎖攔下，施工者須先確認該檔的格式慣例）。
  2. 統一建置腳本 `scripts/lib/build.sh` 加前端映像（依鎖定的完整 SHA 取原始碼建置，沿用 `NODE_IMAGE_REF`，相依套件以 `npm ci --ignore-scripts` 安裝，見 §6.9）；建置前先跑 §6.8 所說的 mysite 端重驗；`scripts/lib/release-ci.sh` 的 `IMAGE_NAMES`、Trivy 掃描清單加前端。
  3. 簽章清單 schema（`docs/schemas/release-payload.schema.json`）的 `images` 增列前端；因為 `additionalProperties: false`，主機端驗證工具必須同步升級。主機尚未開通（BLK-HOST-01）：F6 若在主機開通前合併，主機一開始就裝新版工具，沒有相容問題；若主機已開通，依 `docs/runbooks/部署與回復.md` 先升級主機工具再發新清單。
  4. compose：base 加前端服務與 §4.6 的兩張 internal 網路（app-web 加掛 API 網路與專用別名、gateway 加掛前端網路）、healthcheck、`restart: unless-stopped`；prod 以清單提供的 digest 引用、`pull_policy: never`；dev／ci 用本機建置映像。三處 `EXPECTED_SERVICES` 一起改。環境變數逐一傳給前端服務：`DEPLOY_ENVIRONMENT`、資料模式、API 根網址（§4.6 的專用別名）、主站公開網址、`NEXT_TELEMETRY_DISABLED=1`。注意 `compose.base.yml` 的 `DEPLOY_ENVIRONMENT` 預設為空、只有 `compose.prod.yml` 列為必填（實查），所以 testing／ci 的 next 矩陣不會自然觸發 §4.4 的守門——另寫一個測試：以 `DEPLOY_ENVIRONMENT=production`＋假資料模式啟動前端容器，必須立即失敗退出。
  5. gateway：http 與 https 範本加入路徑分工（§4.2）與渲染模式開關（§4.5）；前端的 location 引入同一份 `infra/nginx/common/security-headers.conf`（現行安全標頭在 app-web 層、不在 gateway，next 模式若不補就會少掉 `nosniff`、`Permissions-Policy` 等）；這份檔案用到 `$mysite_frame_options`、`$mysite_referrer_policy` 兩個變數，對應的 `map` 只定義在 `infra/nginx/app-web.conf`（實查），gateway 的 http、https 範本都沒有，直接引入會讓 nginx 載入失敗，所以 gateway 範本要一併補上這兩個 `map`；另外注意 nginx 的繼承規則：location 內一旦有 `add_header`，就不再繼承上一層的 `add_header`（出處：https://nginx.org/en/docs/http/ngx_http_headers_module.html#add_header ），而 gateway 的 HSTS 正是寫在 server 層，所以前端 location 要一併加回 HSTS，並以 `infra.spec.mjs` 的安全標頭檢查驗證；`/search` 與 `/_next/data/*/search.json` 套每 IP 限流（D13a），超限回正體中文的 429 頁；`/_next/image` 直接回 404（本案關閉圖片最佳化，不讓這個曾有多筆公告的端點對外）；防禦性移除 `x-middleware-subrequest` 請求標頭；存取紀錄維持只記 `$uri`。
  6. CI：`ci.yml` 的 `verify` 矩陣由 `scope: [content, simulation]` 擴為 `scope × render_mode: [blade, next]`；E2E 改用 DOM 掛鉤（§6.5），兩種模式跑同一套。
  7. doctor 與治理檢查加前端（服務健康、資源量測 INF-08）；`dependency-audit.yml` 加前端鎖定檔與映像。
  8. 驗收登錄 `tests/acceptance/registry.json` 新增 PAPI、FE 兩組 ID（不重編既有 ID）。
  9. 文件：`docs/架構決策.md`（ADR-015）、`docs/網址映射.md`（渲染分工）、`docs/設定目錄.md`（新環境變數與開關）、`docs/維護手冊.md` 與 `docs/runbooks/故障判斷.md`（切換、回復、前端容器故障判斷）、`docs/版本查核.md` 與 `docs/versions.lock.json`（前端版本）、`docs/資料清單.md`（註明前端不新增個資）、`.claude/rules/`（加前端施工須知）。
- **驗收**：四個矩陣組合全綠；FE-08、FE-09 與 FE-10 的映像部分 PASS；在 testing 環境實際切換 `blade → next → blade` 各跑一次冒煙測試（FE-13）；前端容器在 testing 的記憶體量測寫入證據；§9.3 列出的既有 ID 在 next 模式全部 PASS。

### F7　staging 驗收（外部阻擋解除後）
- **執行者**：雲端施工者＋委託人。
- **前置**：主機與網域（BLK-HOST-01、BLK-DNS-01）；依 mysite `docs/runbooks/主機開通.md` 完成 P0～P8。
- **動作**：以 next 模式部署 staging；外部 HTTPS 冒煙；真實瀏覽器確認 YouTube 與音訊實際播放（現為 GAP-EXT-PLAY-01）；D5 核准時開放 staging 唯讀 API，讓委託人在 MyDyad 試一次真資料模式。
- **驗收**：next 模式下 staging 全部 E2E 通過；切回 blade 再切 next 的演練成功。

### F8　正式切換
- **執行者**：委託人核准；施工者依 `deploy-production` 流程執行。
- **做法**：正式站只接受 staging 已驗證的同一批映像 digest（現行規則）；以新序號清單切到 next；切換後立即跑外部冒煙；異常即以新清單切回 blade。

### F9　Blade 公開頁退場（只在委託人下「刪除」命令後）
- AI 不執行刪除。委託人下令後，施工者提出要移除的檔案清單與對應測試調整，走一般 PR；本書 v1 不排程此步。

---

## 8. 委託人日常操作（MyDyad 設計循環）

### 8.1 一次性設定（F4，本機 Claude Code 陪同）
1. 本機 Claude Code 已把前端儲存庫放在 D16 的資料夾，並切好 `design/work` 分支。
2. 雙擊 `MyDyadOne.bat` → 按「開 MyDyad」（出處：`MyDyadOne報告書v6.md` §三）。
3. MyDyad → 匯入 app → 選該資料夾 → **取消勾選「複製到 dyad-apps 資料夾」（英文介面為 Copy to the dyad-apps folder；正體中文字串出處：`MyDyad\src\i18n\locales\zh-TW\home.json` 第 24 行）** → 「進階選項」裡的「安裝命令」與「啟動命令」都留空（出處：`MyDyad\src\i18n\locales\zh-TW\home.json` 第 30–32 行）→ 匯入。專案已有 `AI_RULES.md`，不會觸發自動生成。
4. 第一次預覽：MyDyad 會自動執行 `npm install --legacy-peer-deps` 與 `npm run dev -- --port <自動配置的埠>`，第一次安裝較久。
5. GitHub 面板 →「連線到 GitHub」（畫面會顯示「1. 前往：」網址與「2. 輸入程式碼：」，由委託人親手在瀏覽器完成登入）→「連線到現有儲存庫」→「選擇一個儲存庫」選前端儲存庫 →「分支」選 `design/work` →「連線到儲存庫」。（介面字樣出處：`MyDyad\src\i18n\locales\zh-TW\home.json` 第 487–507 行）
6. 模型：選 MyDyadOne 的訂閱線。GPT 線要先在 MyDyadOne 面板親手按「登入 ChatGPT」（出處：`MyDyadOne報告書v6.md` §三）；Claude 線由閘道呼叫官方 claude.exe（出處：記憶卡 `C:\Users\whenwhosay\.claude\projects\C--Users-whenwhosay\memory\project_mydyadone.md` 的「架構」一行）。

### 8.2 每次設計
1. 開 MyDyadOne → 開 MyDyad → 選這個 app。
2. 先在 GitHub 面板按一次「同步到 GitHub」，把施工者已合併回 `design/work` 的更新帶進來（MyDyad 同步時先取回、再推送）。
3. 用對話描述要改什麼，例如「文章頁標題字級加大一級、行距放寬一些」。若 F3 實測標記器可用，也可以用「選取元件」直接點畫面上的元素。
4. 看預覽。不滿意就繼續說，或打開「版本歷史」選一版按「恢復到此版本」（MyDyad 每輪都自動存一版；介面字樣出處：`MyDyad\src\i18n\locales\zh-TW\chat.json` 第 220、226 行）。
5. 滿意後在 GitHub 面板按「同步到 GitHub」（推送）。MyDyad 只推 `design/work`。**若畫面出現「強制推送」「安全強制推送」「變基並同步」「中止變基」「繼續變基」，一律不要按**，改把畫面交給本機 Claude Code 處理：強制推送會覆蓋遠端歷史（MyDyad 自己的警告字樣即寫明「危險且不可逆」），變基類操作會改寫提交歷史（介面字樣出處：`MyDyad\src\i18n\locales\zh-TW\home.json` 第 469–485 行）。
6. 到 GitHub 網頁：若還沒有開著的 PR，儲存庫頁上方會出現黃色的「Compare & pull request」按鈕，按一下並按「Create pull request」；PR 開好後 CI 自動開跑（之後每次推送自動重跑）。
   - 綠燈 → 在 PR 頁按「Merge pull request」（儲存庫已設定只能用合併提交）；合併後**不要按「Delete branch」**，`design/work` 要一直留著。
   - 紅燈 → 看 CI 在 PR 頁留下的正體中文說明；或把 PR 網址交給本機 Claude Code 處理。
   - 想看三種寬度的截圖：請本機 Claude Code 把該次 CI 的截圖下載下來開給委託人看（截圖存成 CI 產物，網頁上只能整包下載）。
7. 收工：直接關閉 MyDyad（它會結束所有預覽行程）。

### 8.3 Build 模式與 Agent 模式
（模式選單的介面字樣：Build 模式顯示為「建置」、Agent 模式顯示為「代理」，出處：`MyDyad\src\components\ChatModeSelector.tsx` 第 151、162 行與 `MyDyad\src\i18n\locales\zh-TW\interface.json` 第 184、338 行；F4 以截圖再確認一次。）
- **Build 模式「建置」**（建議日常使用）：MyDyad 依對話直接改檔，每輪自動存一版；不在委託人的電腦跑完整建置。
- **Agent 模式「代理」**：AI 會自己跑建置、型別檢查與測試來驗證，比較穩，但每輪都會讓電腦滿載一陣子。要用之前先看 F4 第 9 步的實測結果。

### 8.4 常見紅燈與處理

| 紅燈說明 | 原因 | 怎麼辦 |
|---|---|---|
| 這個檔案受保護 | MyDyad 改到 `src/pages`、設定檔、鎖定檔，或因為一句「記住」改了 `AI_RULES.md` | 把 PR 網址交給本機 Claude Code，照 CI 留言附的還原指令處理（不靠 AI 口頭還原單一檔案）；或交施工者 |
| 鎖定檔不一致 | 安裝時改寫了 `package-lock.json` | 交施工者在 Linux 重新產生 |
| 型別檢查或建置失敗 | 改動弄壞了程式 | 把錯誤貼回 MyDyad 請它修；或交施工者 |
| 檔名大小寫不符 | Windows 不分大小寫、Linux 分 | 交施工者 |
| 水平溢位、無障礙問題 | 新設計在某個寬度跑版，或對比不足 | 看 CI 截圖，請 MyDyad 修那個寬度 |
| 缺少測試掛鉤 | 改版時刪掉了 `data-*` 屬性 | 請 MyDyad 補回 |
| 禁用功能 | 出現 App Router、外部字型、外部網址等 | 請 MyDyad 移除；或交施工者 |

### 8.5 上線五步（staging 開通之後）
1. 前端 PR 合併到前端 main。
2. 請施工者把 mysite 的前端鎖定 SHA 升到最新（mysite PR，CI 四個矩陣組合全綠後由委託人合併）。開雲端會話（選 mysite 儲存庫）時可直接貼這句固定請求：「依 `docs/MySiteReact前端專案v1.md` §8.5，把前端鎖定 SHA 升到前端 main 最新且 CI 全綠的提交，跑 F6 的重驗與全套 CI，開 PR 給我合併。」
3. release（手動觸發，或委託人開啟自動發布開關 `MYSITE_AUTO_RELEASE` 後自動）。
4. deploy-staging → 在 staging 網站確認。
5. deploy-production（委託人核准）。

---

## 9. 驗收登錄（新增 ID，不重編既有 90＋6 項）

### 9.1 公開 API（PAPI）

| ID | 驗證與預期結果 |
|---|---|
| PAPI-01 | 每個端點的實際回應符合契約（含錯誤回應） |
| PAPI-02 | 回應無 `Set-Cookie`；全部 `Cache-Control: no-store` |
| PAPI-03 | 撤下事件後 API 立即 404；事件遺失且 WordPress 失聯時，可見性證明到期後不再回正文（比照 `VisibilityLeaseTest` 八項） |
| PAPI-04 | WordPress 失聯、逾時、格式不符分別回 503 並帶請求編號；絕不回舊正文 |
| PAPI-05 | 回應不含服務帳號、email、預覽資料、內部診斷 |
| PAPI-06 | 影片 ID、圖片網址、音訊來源與 MIME 政策與 Blade 相同；不合格值輸出 null 與 `*_unavailable` |
| PAPI-07 | 參數不合法（`page` 超界、slug 超長、`q` 超過 100 字）的回應與現行公開頁一致 |
| PAPI-08 | 三種情境：①前端容器經專用網路可取得 `/api/v1/`；②production 設定下外部經 gateway 存取 `/api/` 一律 404；③staging 依 D5（開放時只放行 gateway 固定位址）。另驗：來自前端網段的 `/internal/…`、`/admin/…` 一律 404 |

### 9.2 前端（FE）

| ID | 驗證與預期結果 |
|---|---|
| FE-01 | 全新 Linux runner 依鎖定檔重建成功 |
| FE-02 | 除 Next.js 內建錯誤頁外，所有路由都是動態渲染（建置輸出 `ƒ`） |
| FE-03 | 全專案只有 `SafeHtml` 一處輸出 HTML |
| FE-04 | 禁用功能檢查通過（無 App Router、proxy、`next/og`、`use cache`、Cache Components、Edge 執行環境、Server Actions、外部 rewrites、`next/font/google`） |
| FE-05 | API 回 404／503 時前端回同一狀態碼與正確錯誤頁（含請求編號）；以假伺服器模擬「連線被拒、逾時、非 JSON、格式不符」時一律回 503；網址組合的穿越測試全部通過 |
| FE-06 | 匿名瀏覽全部公開頁零 cookie、瀏覽器只對同源發請求（YouTube 點擊後除外） |
| FE-07 | 關閉 JavaScript 仍可閱讀正文與使用基本連結 |
| FE-08 | next 模式下搜尋的每 IP 限流由 gateway 執行，且 Laravel 的信任清單未變 |
| FE-09 | `DEPLOY_ENVIRONMENT` 為 staging／production 時以假資料模式啟動會失敗；正式映像不含 `mocks/`、`public/mock-media/`、`tests/`；正式產物無 `data-dyad-id` |
| FE-10 | 前端儲存庫、CI 產物無秘密（F3）；正式映像無秘密（F6） |
| FE-11 | `design/` 分支改到受保護路徑時，由 main 上定義的檢查判定失敗並在 PR 以正體中文說明；設計分支即使改動 `.github/` 也無法讓這項檢查消失 |
| FE-12 | 每個畫面元件都具備 DOM 測試掛鉤清單上的掛鉤 |
| FE-13 | 渲染模式 `blade ↔ next` 切換在 testing 實測成功（F6） |
| FE-14 | 委託人在 MyDyad 完成一次「預覽 → 推送 → CI 全綠 → 合併」（F4） |
| FE-15 | F4 第 6 步的四個時點，鎖定檔都無變動；dev 伺服器只綁本機迴路位址（F4） |
| FE-16 | 關閉 MyDyad 後無殘留 Node 行程、記憶體歸還（F4） |
| FE-17 | 404、503、500 錯誤頁都有請求編號、狀態碼正確；`/api/v1/site` 可取得時有完整版面，取不到時改用靜態頁首頁尾；不存在的路徑回 404；站內換頁（`/_next/data`）遇到 404／503 時也能正確顯示錯誤頁 |
| FE-18 | MyDyad 這個 app 的預覽埠與代理埠沒有撞到本機埠分配表上的服務（F4） |

### 9.3 既有 ID 在 next 模式必須重跑
UI-01～UI-08、API-02、API-05、API-10、API-13、API-15、OPS-03、OPS-04、OPS-12、INF-07、INF-08、DAT-02、DAT-04、DAT-05、CLD-04、CLD-06、GOV-02。施工者在 F6 以腳本比對 §3 與本節引用的 ID 都存在於 `tests/acceptance/registry.json`，且本節涵蓋 §3 列出的全部既有 ID。

---

## 10. 風險與對策

| # | 風險 | 對策 | 何時確認 |
|---|---|---|---|
| R1 | 雲端施工者依《執行契約》拒絕引入 Next.js | F0 先修訂正典（ADR-015） | F0 |
| R2 | MyDyad 每次啟動預覽都跑 `npm install`，改寫鎖定檔後被自動提交，每次推送都紅燈 | `packageManager` 固定 npm；`.npmrc` 寫 `legacy-peer-deps=true` 與 `save=false`（後者依 npm 官方文件會阻止寫入鎖定檔）；鎖定檔只在 Linux 產生；F4 在四個時點實測，不行就改走 pnpm | F4 |
| R3 | MyDyad 元件標記器不支援 Next.js 16（上游 #1750 曾回報升級後失效） | F3 先實測；不行就移除標記器、全用 Turbopack，MyDyad 改用對話修改 | F3 |
| R4 | Agent 模式每輪在委託人電腦跑完整建置，造成桌面卡頓（第一不可忍） | 日常用 Build 模式（D15）；F4 量測；需要時呈報 D13e | F4 |
| R5 | MyDyad 啟動預覽時強制結束佔用同埠的行程 | F4 比對埠分配表並登記（FE-18） | F4 |
| R6 | 預覽與正式用不同打包器，樣式順序不同導致上線跑版 | 標記器可用時開發與建置同用 webpack；CI 截圖以正式建置為準 | F3 |
| R7 | Next.js 自 2026-07-13 起改為「大約每月一次、事先公告」的安全發布，緊急漏洞仍會臨時發布（出處：https://nextjs.org/blog/next-security-release-program ） | 本案不用高風險功能並由 CI 阻擋；施工者每月看公告，Critical 立即升級並經 mysite CI 雙模式驗證；mysite `dependency-audit.yml` 納入前端 | 長期 |
| R8 | 前端多一個對外服務 | 不用高風險功能、`/_next/image` 回 404、安全標頭同一份、Trivy 掃描、容器不接 `db` 網路 | F6 |
| R9 | 公開 API 被當成爬蟲入口 | production 不對外；staging 依 D5 限流 | F2、F7 |
| R10 | 設計分支與 main 分岔 | 只用合併提交；施工者改完 main 後以一般推送合併回設計分支（D8、§6.8） | F3 |
| R18 | 設計分支改掉自己的 CI 檢查來繞過把關 | 受保護路徑檢查改用 `pull_request_target`（執行 main 上的定義）；mysite 鎖定 SHA 前重驗（§6.8） | F3、F6 |
| R11 | 假資料夾帶真實創作被送進 AI 模型 | 假資料只由施工者從 testing 示例匯出；`mocks/` 受保護 | F2、F3 |
| R12 | 主機驗證工具不認得新清單欄位 | F6 第 3 步的順序 | F6 |
| R13 | MyDyad 本體（1.16.0）落後上游（1.22.0）；上游 #3360 回報 Windows 上 Next.js 專案的型別檢查工作行程異常 | 本案不升級 MyDyad；F4 實測遇到再另案處理 | F4 |
| R14 | MyDyad Agent 模式讀到委託人的全域規則檔 | F4 第 11 步確認並呈報 | F4 |
| R15 | Pages Router 未來若被官方棄用 | 現況仍維護；若官方公告棄用，另案評估遷移（屆時須另解 503 問題） | 長期 |
| R16 | Node.js 預告 2026-11-11 安全發布，26.x、24.x、22.x 最高嚴重度皆為 HIGH（出處：https://nodejs.org/en/blog/vulnerability/november-2026-security-releases ） | F3 開工時重查並更新 mysite `NODE_IMAGE_REF`；委託人電腦的 24 線也應在修補版發布後更新（由委託人決定何時裝） | F3、F4 |
| R17 | 選取元件時，MyDyad 會替元素加 `data-dyad-id` | 只在開發模式掛上；CI 檢查正式產物沒有（FE-09） | F3 |
| R19 | 委託人電腦上的預覽伺服器本身成為攻擊目標（2026-08 曾有只影響 Windows 主機、含 Pages Router 的遠端執行漏洞 CVE-2026-75604） | 前端儲存庫的 Next.js 不得低於已修補版本（本案下限 16.4.5）；dev 伺服器只綁本機迴路位址；日後出現同類公告時，先由施工者升級前端儲存庫，委託人同步後再開預覽 | F3、F4、長期 |

## 11. 回復方案

| 層級 | 怎麼退 | 誰做 |
|---|---|---|
| 一次設計改壞了 | MyDyad 版本紀錄還原；已合併的話在 GitHub 對該 PR 按 Revert | 委託人（或請本機 Claude Code） |
| 前端某一版有問題 | mysite 把前端鎖定 SHA 改回上一版（PR、CI、合併、發新清單） | 施工者＋委託人合併 |
| next 模式整體有問題 | 發新序號的已簽章清單，把渲染模式切回 `blade` | 施工者＋委託人核准 |
| 公開 API 有問題 | blade 模式不用它；切回 blade 即與 API 脫鉤，API 本身可另行修補 | 施工者 |
| 架構決策要撤回 | 新增一條 ADR 撤回 ADR-015，原文不刪 | 委託人裁定、施工者記錄 |

Blade 公開頁在 F9 之前一直保留，且 mysite CI 持續以 blade 模式驗證，所以任何時候都有一個已驗證的去處。

## 12. 三輪迭代紀錄（每輪思考兩遍）

### 第一輪：骨架
- **第一遍（委託人操作角度）**：委託人每天要做的只有「開 MyDyad、說要改什麼、看預覽、推送、看紅綠燈、按合併」；凡是需要懂 PHP、Docker、Linux 的事都不能落到委託人身上 → 定出「委託人只碰畫面、其餘全歸施工者」的分工，以及假資料預設模式。
- **第二遍（AI 施工角度）**：撤下 60 秒、503 語意、唯一淨化器、只信任 gateway IP、簽章部署是 mysite 的骨幹，每一條都要有對應機制與測試。查到 App Router 無法由頁面回 503（出處：https://github.com/vercel/next.js/discussions/53225 ），而 Pages Router 的 `getServerSideProps` 可經 `res` 設狀態碼、每次請求執行 → 改採 Pages Router。

### 第二輪：對齊實況
- **第一遍（委託人操作角度）**
  - mysite main 目前沒有分支保護、前端儲存庫也還不存在（gh 實查）→ 不設保護，MyDyad 一次誤推就繞過全部測試 → 新增 D3、D8、D12。
  - 雲端施工者會依 `執行契約.md` §2.1 拒絕引入 Next.js → F0 先修訂正典（§1.4），否則委託人會在 F1 卡住且不知原因；雲端會話也讀不到本機檔案 → 本書要收進 mysite `docs/`。
  - 委託人電腦已有 Node v24.18.0、Git 2.55、pnpm（本機實查）；MyDyad 預設會選 pnpm → 以 `packageManager` 固定 npm（D14）。
  - MyDyad 預設把專案複製到 AppData；本機 Claude Code 對 AppData 的判讀不可信 → 保留原位置匯入（D16）。
  - MyDyad 推送前只會先取回同名分支 → 合併方式改用合併提交，並把 main 合併回設計分支（D8）。
  - 雲端 Claude 說要付 API 費用 → 實查 MyDyadOne 已綁訂閱，更正（§0）。
- **第二遍（AI 施工角度）**
  - YouTube、圖片、音訊三項政策寫在 Blade 元件類別裡，API 不經 Blade → F2 第一步先抽共用政策類別（§5.6）。
  - 現行安全標頭由 app-web 加、不在 gateway → next 模式要在 gateway 的前端 location 引入同一份標頭檔（F6 第 5 步）。
  - Laravel 看到的來源會變成前端容器，現行每 IP 搜尋限流會變成「全站共用」→ 限流移到 gateway（D13a）。
  - E2E 依賴 Blade class 與原始 HTML 正規式 → DOM 測試掛鉤契約（§6.5）。
  - 簽章清單 schema `additionalProperties: false` → 加前端映像要同步升級主機驗證工具（F6 第 3 步）。
  - 2026 年 Next.js 公告集中在 proxy、Server Actions、`use cache`、圖片最佳化 → 一律不用並以 CI 阻擋（§6.1）。
  - PHP 驗證套件無明確 OpenAPI 3.1 支援 → 契約採 3.0.3。
  - `create-next-app` 預設 App Router 與 Cache Components → 骨架手動建立。
  - MyDyad 元件標記器只支援 webpack，而 Next.js 16 有自訂 webpack 設定時 `next build` 會失敗 → 指令明寫打包器、F3 先實測標記器（§6.6）。
  - MyDyad 只在 dev 伺服器輸出 `http://localhost:埠` 時才啟動預覽代理 → `dev` 指令用 `-H localhost`。
  - MyDyad 會往 `.gitignore` 追加 `.dyad/`、提交一律 `--no-verify` → 預先寫好 `.gitignore`；把關全靠 CI 與分支保護。
  - MyDyad Agent 模式會自動跑 `test` 與 `build` → `test` 只放快速單元測試；E2E 另取名；預設 Build 模式（D15）。
  - MyDyad Build 模式會把 `.json` 假資料送給模型 → 假資料只放示例內容。

### 第三輪：挑錯定稿（自審＋獨立審查員）
- **第一遍（委託人操作角度）**
  - 委託人不知道 PR 從哪裡來 → 寫明黃色「Compare & pull request」按鈕（§8.2）。（原本另加了自動開 PR 的輔助流程，經審查員指出 `GITHUB_TOKEN` 的限制後移除，見處置表必改-5。）
  - CI 截圖只能整包下載 → 改由本機 Claude Code 代為下載開給委託人看（§8.2）。
  - MyDyad 啟動預覽前會強制結束佔用同埠的行程（實查 `app_runtime_service.ts` 第 1397–1403 行）→ F4 第 10 步比對埠分配表、新增 FE-18；撞到就停下呈報。
  - 操作步驟原本用英文或概稱 → 全部換成 MyDyad 畫面上實際的中文字樣（「複製到 dyad-apps 資料夾」「連線到現有儲存庫」「同步到 GitHub」「版本歷史」「恢復到此版本」「建置」「代理」），並加「看到強制推送一律不要按」。
  - `next telemetry disable` 原排在安裝之前，執行不到 → 移到第一次預覽完成之後。
- **第二遍（AI 施工角度）**
  - Pages Router 的 `pages/404` 只能在建置時產生，拿不到即時選單、印不出請求編號 → 404／503 由頁面自己設狀態碼、不存在的路徑用萬用路由、500 走 `_error`（§4.3 第 4 步、FE-17）。
  - 以 `NODE_ENV` 守門會阻擋前端 CI 的「正式建置＋假資料」E2E → 改依 mysite 既有必填的 `DEPLOY_ENVIRONMENT`（實查 `compose.prod.yml`）（§4.4、FE-09）。
  - 假資料裡的媒體網址會讓前端 CI 向外部發請求 → `public/mock-media/` 合成素材、匯出時改寫網址、正式映像不複製。
  - DOM 測試掛鉤清單原排在 F6，但 F3 就要照它實作 → 提前到 F1。
  - MyDyad Agent 模式底層 OpenCode 讀 `AGENTS.md`，沒有它才讀 `CLAUDE.md`，且可能讀到委託人全域規則檔 → 前端儲存庫放與 AI_RULES 相同的 `AGENTS.md`、施工者須知寫在 `CLAUDE.md`、F4 第 11 步實測。
  - Agent 模式驗證時跑的是不帶旗標的 `npm install`（實查 `project_checks.ts` 第 92–100 行）→ `legacy-peer-deps` 必須寫進 `.npmrc`。
  - `/_next/image` 端點即使關閉最佳化仍可能存在，且 2026 年有多筆公告 → gateway 直接回 404。
  - gateway 的 HSTS 寫在 server 層，前端 location 一旦加 `add_header` 就不再繼承 → F6 第 5 步要一併加回（nginx 官方文件）。
  - `/api/v1/site` 若 WordPress 讀不到就回 503，503 錯誤頁將無版面可畫 → 沿用現行內建後備選單（實查 `ContentServiceProvider.php`）。
  - D13c 的逾時下限原只算單次 WordPress 呼叫 → 改為「一次 API 請求的最壞耗時」並附算式。
  - 驗收 ID 與階段對應有誤（FE-08、FE-09 映像部分無法在前端儲存庫驗）→ 重新分配到 F3／F6。
  - 以 Next.js 原始碼確認 `-H localhost` 時印出的網址主機名是 `localhost`，符合 MyDyad 的預覽偵測規則。
  - Next.js 建置時會靜態追蹤 `fs` 讀檔、把檔案複製進 standalone → 以 `outputFileTracingExcludes` 排除 `mocks/` 與 `public/mock-media/`，FE-09 檢查映像（§6.9）。
  - 抽查引用的 GitHub issue 狀態：npm/cli#4828 已於 2025-04-03 標為完成 → D9 改為照實描述；dyad #2265 仍未關閉、#3360 仍未關閉，維持原判。
  - MyDyad 的 Node 範圍 `>=24 <26` 只在重新打包 MyDyad 時相關 → D11 改寫為精確說法。
  - 逐字核實 Next.js 官方公告「Pages Router 應用程式不受 CVE-2025-66478 影響」、GitHub 方案說明中分支保護屬 Pro 的私人儲存庫功能、OpenCode 的規則檔讀取順序。
- **獨立審查員的發現與處置**：見下方「第三輪審查處置表」。

#### 第三輪審查處置表
審查員獨立讀全文並抽查引用（MyDyad 原始碼 24 處、mysite 儲存庫 16 處），抽查結果除下表註明者外全部相符。每一條發現都經主筆再實查後才處置。

| 編號 | 發現（摘要） | 主筆實查 | 處置 |
|---|---|---|---|
| 必改-1 | 分支保護預設不阻擋管理員；MyDyad 以委託人帳號登入等於管理員；缺核准人數、必要檢查、合併方式、自動刪分支等設定 | 屬實 | D8 改寫；F0 第 3 步列全設定與驗收欄位（`enforce_admins` 等）；§8.2 加「不要按 Delete branch」 |
| 必改-2 | `pull_request` 跑的是 PR 分支自己的工作流程，設計分支可改掉檢查；MyDyad「全權略過」目前開啟 | 屬實（MyDyadOne 報告書 v6 §一第 2 項） | 受保護路徑檢查改用 `pull_request_target`（不 checkout、不執行 PR 程式、無秘密）；mysite 鎖定 SHA 前重驗；新增 D17、R18 |
| 必改-3 | 前端掛 edge＋backend 與「只許 backend 網段」、staging 經 gateway 轉發互相矛盾；`InternalOnly` 不是網段判斷；edge 可對外 | 屬實（實查 `compose.base.yml`、`InternalOnly.php`） | §4.6 改為兩張 internal 新網路、前端無對外能力；§5.5 改寫並修正 `InternalOnly` 描述；PAPI-08 改三種情境；新增 D13f |
| 必改-4 | 未規定網址組合，可能經正規化穿越到 `/internal/` | 風險成立（未實測能否穿越，照實標為風險） | §6.3 加組網址規矩與穿越測試；app-web 對前端網段只放行 `/api/v1/`；F1 範例加中文 slug、200 字 slug |
| 必改-5 | `GITHUB_TOKEN` 推送不觸發 CI、開 PR 要另按核准、「允許 Actions 建立 PR」預設關閉、push 與 PR 雙觸發會卡必要檢查 | 屬實（GitHub 官方文件） | 移除兩支輔助自動化；CI 改為 PR＋main 推送；PR 由委託人按黃色按鈕；main 的更新由施工者一般推送合併回設計分支；自動化權杖列為日後另案 |
| 必改-6 | §3 引用的 API-02、OPS-03、OPS-04 不在 §9.3 重跑清單 | 屬實 | 補進 §9.3，並要求 F6 以腳本比對 |
| 必改-7 | MyDyad 每次啟動都跑 `npm install`，第二次起 `node_modules` 已存在，正是鎖定檔被改寫的情形；只驗第一次會假性通過 | 屬實 | `.npmrc` 加 `save=false`（npm 官方文件：阻止寫入鎖定檔）；F4 第 6 步改四個時點；FE-15、R2 同步 |
| 應改-1 | 連線被拒、逾時、非 JSON 會落成 500 | 屬實 | 傳輸層失敗一律 503；FE-05 加假伺服器情境 |
| 應改-2 | `/api/v1/site` 取不到時沒有版面可畫 | 屬實 | `ErrorView` 內建靜態頁首頁尾；FE-17 分兩種情境 |
| 應改-3 | `security-headers.conf` 用到的兩個 `map` 只在 app-web 定義，gateway 引入會載入失敗 | 屬實（實查） | F6 第 5 步要求 gateway 補兩個 `map` |
| 應改-4 | F4 備案要改受保護檔，在設計分支上改會被判紅燈 | 屬實 | 備案一律由施工者走非設計分支 PR |
| 應改-5 | §0「委託人要親手做的事（全部）」漏項 | 屬實 | 改為分情境完整列出 |
| 應改-6 | mysite `CLAUDE.md` 第 5 行、規劃書v5 第 1773 行、建置總結第 548 行未列入衝突 | 屬實（實查三處原文） | 補進 §1.4，F0 第 4 步改為修訂 §1.4 表列各處 |
| 應改-7 | Agent 模式實際顯示「代理」不是「代理 v2」 | 屬實（`ChatModeSelector.tsx` 第 151 行、`interface.json` 第 184 行） | D15、§8.3、本紀錄更正 |
| 應改-8 | §8.2 強制推送字樣的出處檔名錯；未禁變基類按鈕 | 屬實 | 出處改 `home.json` 第 469–485 行；變基類按鈕一併列入不要按 |
| 應改-9 | AI 口頭還原單一檔案不可靠；工作摘要不在 PR 頁 | 採納 | CI 在 PR 留正體中文留言並附還原指令；§8.4 改寫 |
| 應改-10 | D13c 未納入重試與 `/site` 呼叫方式 | 屬實（設定目錄「冪等讀取最多 2 次嘗試」） | D13c 改列算式輸入 |
| 應改-11 | 鎖定 SHA 的條件、安裝腳本風險未寫 | 採納 | §6.9、F6 第 2 步補完整 SHA、main 且 CI 全綠、`npm ci --ignore-scripts`、建置不掛憑證 |
| 應改-12 | 每次上線都要開雲端會話請施工者 | 採納 | §8.5 給一句固定請求 |
| 應改-13 | D3 出處頁不含分支保護方案說明 | 屬實 | 改引 GitHub 方案頁（審查期間已修正） |
| 應改-14 | 遙測設定在 AppData，不能用 shell 查檔驗證 | 屬實（本機規矩） | 改以 `next telemetry status` 輸出驗證 |
| 應改-15 | MyDyad Agent 提示允許在使用者說「記住」時改 `AI_RULES.md` | 屬實（`local_agent_prompt.ts` 第 418 行） | AI_RULES 加第 12 條；§8.4 紅燈表補原因 |
| 應改-16 | FE-10 含映像部分，不能全在 F3 驗 | 屬實 | 比照 FE-09 拆成 F3、F6 兩部分 |
| 應改-17 | 狀態行仍寫第二輪；處置表不存在 | 屬實（審查時的版本） | 狀態行已改「現行本」；本表補上 |
| 應改-18 | nginx 漏桶與 Laravel 固定時間窗不同；gateway 429 是英文預設頁 | 屬實 | D13a 註明差異並要求測試；gateway 加正體中文 429 頁 |
| 應改-19 | 前端服務的環境變數未列；testing／ci 的 `DEPLOY_ENVIRONMENT` 預設為空，守門不會被觸發 | 屬實 | F6 第 4 步逐一列出，並另寫守門測試 |
| 可改-1 | `.mydyad/` 無出處 | 補出處：`MyDyad\src\sovereign\context.ts` 第 20 行把它當自家資料夾排除 | 保留並註明 |
| 可改-2 | 圖片最佳化公告與「16.4 起」的出處不精確 | 屬實 | 改引 2026-08、2026-10 兩則具體公告；刪去「16.4 起」 |
| 可改-3 | `-H localhost` 的理由② 不成立（不帶 `-H` 也印 localhost） | 屬實（Next.js 原始碼） | 理由只留 webpack 與只綁迴路位址，②改為附帶確認 |
| 可改-4 | 「Claude 線走 claude.exe」出處不在報告書 v6 | 屬實 | 改引記憶卡與埠分配表 |
| 可改-5～9 | 「擋」截斷、「拉」、接入、選單項、鎖定項、存版、矩陣格、私人庫／公開庫、「您」、回退 | 屬實 | 全文逐一改為「阻擋／攔下」「下載／取回」「接上」「選單項目」「鎖定項目」「存成一個版本」「矩陣組合」「私人儲存庫／公開儲存庫」「委託人」「回復」；「渲染」維持（臺灣技術文件常用） |
| 主筆追加 | 2026-08 的 CVE-2026-75604 影響 Windows 上執行的 Next.js（含 Pages Router） | 實查 August 2026 Security Release | `dev` 指令理由與 R19 補入；版本下限 16.4.5 已涵蓋修補 |

---

## 13. 查證來源（2026-11-05 查核）

**Next.js／React**
- https://nextjs.org/docs/pages/api-reference/functions/get-server-side-props
- https://nextjs.org/docs/pages/building-your-application/data-fetching/get-server-side-props
- https://nextjs.org/docs/pages/building-your-application/routing/custom-error
- https://github.com/vercel/next.js/discussions/53225
- https://nextjs.org/blog/next-16
- https://nextjs.org/docs/app/guides/upgrading/version-16
- https://nextjs.org/docs/app/api-reference/cli/next
- https://github.com/vercel/next.js/blob/canary/packages/next/src/server/lib/start-server.ts
- https://nextjs.org/docs/app/getting-started/caching
- https://nextjs.org/docs/app/guides/building
- https://nextjs.org/docs/app/api-reference/config/next-config-js/output
- https://nextjs.org/docs/app/guides/self-hosting
- https://github.com/vercel/next.js/tree/canary/examples/with-docker
- https://nextjs.org/telemetry
- https://nextjs.org/blog/november-2026-security-release
- https://nextjs.org/blog/august-2026-security-release
- https://nextjs.org/blog/nextjs-security-update-october-14-2026
- https://nextjs.org/blog/next-security-release-program
- https://nextjs.org/blog/CVE-2025-66478
- https://github.com/vercel/next.js/security/advisories
- https://github.com/react/react/security/advisories/GHSA-xqc2-xf4c-x3h3

**Node.js、套件、工具**
- https://raw.githubusercontent.com/nodejs/Release/main/schedule.json
- https://nodejs.org/en/blog/vulnerability/november-2026-security-releases
- https://registry.npmjs.org/tailwindcss/latest
- https://api.github.com/repos/colinhacks/zod/releases/latest
- https://registry.npmjs.org/openapi-typescript/latest
- https://github.com/microsoft/playwright/releases
- https://api.github.com/repos/dequelabs/axe-core-npm/releases/latest
- https://github.com/npm/cli/issues/4828

**Laravel、OpenAPI、nginx**
- https://laravel.com/docs/13.x/routing
- https://github.com/thephpleague/openapi-psr7-validator
- https://nginx.org/en/docs/http/ngx_http_headers_module.html#add_header

**GitHub**
- https://docs.github.com/en/get-started/learning-about-github/githubs-plans
- https://docs.github.com/en/actions/concepts/security/github_token

**Dyad／OpenCode**
- https://www.dyad.sh/docs/guides/importing
- https://www.dyad.sh/docs/upgrades/select-component
- https://api.github.com/repos/dyad-sh/dyad/releases/latest
- https://github.com/dyad-sh/dyad/issues/2265
- https://github.com/dyad-sh/dyad/issues/1750
- https://github.com/dyad-sh/dyad/issues/3360
- https://opencode.ai/docs/rules/

## 14. 相關檔案

**委託人電腦（本書依據）**
- `C:\Users\whenwhosay\Desktop\MySite網站建置.txt`
- `C:\Users\whenwhosay\.claude\plans\001整體程式建構計畫\網站規劃書v5.md`
- `C:\Users\whenwhosay\.claude\plans\001整體程式建構計畫\mysite_ClaudeCode雲端自主施工完整Prompt.md`
- `C:\Users\whenwhosay\MyPythons\MyDyadOne\MyDyadOne報告書v6.md`
- `C:\Users\whenwhosay\MyPythons\MyDyad\`（MyDyad 原始碼；本書引用的檔案：`package.json`、`scaffold\AI_RULES.md`、`shared\ports.ts`、`src\ipc\utils\package_manager_selection.ts`、`src\ipc\services\app_runtime_service.ts`、`src\components\ImportAppDialog.tsx`、`src\ipc\handlers\github_handlers.ts`、`src\ipc\handlers\gitignoreUtils.ts`、`src\ipc\utils\git_utils.ts`、`src\ipc\utils\kill_process_tree_sync.ts`、`src\sovereign\verification\project_checks.ts`、`src\sovereign\agent\opencode_backend.ts`、`src\prompts\system_prompt.ts`、`src\prompts\local_agent_prompt.ts`、`src\utils\codebase.ts`、`packages\@dyad-sh\nextjs-webpack-component-tagger\`）
- `C:\Users\whenwhosay\Documents\MyVault\00_系統規格\本機埠分配表.md`

**mysite 儲存庫（GitHub `Whenwhoengine/mysite` main）**
- 路由：`app/routes/content.php`、`preview.php`、`shop.php`、`admin.php`、`health.php`、`internal.php`；`app/bootstrap/app.php`
- 內容層：`app/app/Domain/Content/`（`ContentQueryService`、`ContentRepository`、`Dto/`）、`app/app/Support/Html/Sanitizer.php`、`app/app/Support/Errors/ErrorRenderer.php`、`app/app/Http/Middleware/AssignRequestId.php`、`app/app/Providers/ContentServiceProvider.php`
- 元件：`app/resources/views/components/`（`rich-content`、`youtube-player`、`audio-player`）、`app/resources/js/app.js`、`app/resources/views/layouts/app.blade.php`
- 基礎設施：`infra/compose.base.yml`、`compose.dev.yml`、`compose.ci.yml`、`compose.prod.yml`；`infra/nginx/gateway/*/gateway.conf.template`；`infra/nginx/common/security-headers.conf`；`infra/images/php/Dockerfile`；`scripts/lib/build.sh`、`scripts/lib/release-ci.sh`；`docs/schemas/release-payload.schema.json`
- 測試與 CI：`tests/e2e/specs/`、`tests/e2e/playwright.config.mjs`、`tests/acceptance/registry.json`、`scripts/ci/check-blade-raw.sh`、`.github/workflows/`
- 文件：`docs/執行契約.md`、`docs/架構決策.md`、`docs/API契約.md`、`docs/網址映射.md`、`docs/設定目錄.md`、`docs/state/`、`CLAUDE.md`
