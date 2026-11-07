# MySite React 前端專案 定案執行

```
狀態: 現行本（定案；首次交付，檔名由委託人指定）
日期: 2026-11-06
案名: MySite React 前端專案
本檔: C:\Users\whenwhosay\.claude\plans\001整體程式建構計畫\MySiteReact前端專案定案執行.md
授權: 委託人 2026-11-06「如擬決行」：詳讀並整合 Codex、Argon 兩份建議，迭代三次、每次思考兩遍，生成本書
承接（皆同資料夾）:
  MySiteReact前端專案v1.md（完整正文）
  MySiteReact前端專案v2.md（D1～D17 裁定）
  MySiteReact前端專案_F0本地施工紀錄.md
  MySiteReact前端專案v2_codex建議v1.md
  MySiteReact前端專案v2_Argon建議v2.md
讀法: 施工依據＝v1 全文＋v2＋本書。三者衝突時，本書優先，其次 v2，再次 v1。
      兩份建議書自本書起不再直接作為施工依據：採納的部分已改寫進本書；
      本書未採納或已改寫之處，不得照建議書原文施工（包括 Argon 建議書 §7 提示詞所寫「衝突處以 Argon 為準」一句）。
```

---

## 0. 一頁結論

**架構與 D1～D17 裁定全部不變**：WordPress 管內容；Laravel 開公開唯讀 API，並繼續獨掌可見性與唯一淨化器；Next.js Pages Router 畫公開頁；委託人用 MyDyad 改畫面；gateway 保留 `blade`／`next` 開關，隨時可切回。

兩份建議書的每一項事實說法都先查證再處置（§3）：Codex 19 項、Argon 26 項，另補兩份都沒看到的缺口 10 項，並查核 mysite 線上程式碼 13 項、官方文件 12 項。處置細目在 §3、§4，不採納的集中在 §12。

**最重要的十項修訂**

1. **匯入 MyDyad 之前先查埠。** 原始碼實證：按下「匯入」成功的那一刻，MyDyad 就自動選取該 app、啟動預覽，並先清掉預覽埠上的一切。一般模式以 `TaskKill /F` 結束行程，而且不分連線狀態；Docker 模式則停掉發布該埠的容器。所以查埠要在委託人按「匯入」之前，範圍是整段 32100～42099（§6 F4）。
2. **代理埠會反向占住委託人的服務**（新發現）。代理埠＝42100＋app 編號，若剛好是 42600、42601、43411 等已登記的埠，參勤交代或翻譯線之後就開不起來。匯入後立刻讀回，撞到就停下呈報。
3. **預覽出問題時，不得拿掉「只綁本機」。** 刪除 v1 F4 第 5 步改回不帶 `-H` 的備案。
4. **受保護路徑守門重新設計。** 設計分支（`design/` 開頭）改到受保護檔一律紅燈。施工者分支可以改，但 PR 須逐檔宣告，工作流程自動在 PR 列出清單，供委託人合併前核對。他人 fork 的 PR 一律紅燈。另外，GitHub 自 2026-12-02 起預設阻擋公開儲存庫的 `pull_request_target`，F3 須在 MySiteStorage 建立「只允許守門這一支工作流程」的事件政策（§5.2）；Argon 提的 `workflow_run` 備援，官方寫明其檢查不計入必要檢查，不採為主線。
5. **零啟動自改寫。** `next dev` 會自動補寫 `tsconfig.json`、重新產生 `next-env.d.ts`（官方文件）；MyDyad 原始碼註解明載，預覽啟動期間的變動可能被自動存成版本，推上去就紅燈。定案：`next-env.d.ts` 依官方建議列入 `.gitignore`；`tsconfig.json` 在 F3 定型；CI 檢查建置與啟動後工作樹沒有任何變動。
6. **Tailwind CSS v4 的寫法寫死在 AI_RULES。** 只用 `@import "tailwindcss"` 與 `@theme`；不建 `tailwind.config.*`、不用 v3 的 `@tailwind` 指令、不拼接 class 字串。
7. **next 模式不得比 blade 模式更容易被掃描拖累。** 敏感檔與 `.php` 探測改在 gateway 就回 404（現行只有 app-web 有這道阻擋）。另外，前端容器主動連往 gateway 的連線一律斷開。
8. **撤下保證寫精確。** 服務端與中間層不留副本，下一次請求就拿不到。瀏覽器「上一頁」快取與已下載的內容不在 HTTP 可收回範圍；這與現行 Blade 公開頁相同，不是新缺口，也不加強制重新整理。
9. **連結分流。** 交給 Laravel 的路徑（商店、後臺、預覽、RSS、sitemap）與搜尋，一律用一般連結與原生表單；公開內容頁用 `next/link` 且不預取。由新的受保護元件 `SiteLink` 統一判斷，MyDyad 不必自己分辨。
10. **對齊 mysite 既有 E2E。** 搜尋零結果保留「找不到符合」；Podcast 的音訊網址要出現在伺服器輸出的 HTML 裡；站內連結輸出相對路徑。

**委託人要做或要定的事**

| 時機 | 事項 |
|---|---|
| 現在 | 關閉 MyDyad 後，在 MyDyadOne 面板關閉「全權略過」（D17；F0 紀錄顯示仍待辦） |
| 現在 | 在 GitHub 網頁合併雲端施工者開的 F0 PR（ADR-015 與三份文件入庫；施工者尚未開，§2） |
| 隨時 | Node.js 24 線修補版何時安裝。電腦上的 v24.18.0 沒有包含 2026-10-21 的安全修補（v24.22.1）；24 線目前最新是 v24.22.2；2026-11-11 另有一批。D11 原裁定由委託人決定（§3.4、§6 F4 第 13 步） |
| F3 開工時 | 在 MySiteStorage 建立「只允許守門工作流程使用 `pull_request_target`」的 Actions 事件政策：委託人親手設定，或明確授權本機 Claude Code 代設（屬帳號設定變更，須當次授權；§5.2 第 7 項） |
| F4 匯入當下 | 本機 Claude Code 說「可以按了」才按「匯入」；若查出撞埠，等委託人定奪 |
| 各階段 | D13 數值仍由施工者附算式呈報，屆時再裁定 |

除上表的動作外，本書沒有新增待裁定事項。

**交付**：雲端施工者在 F0 把 v1、v2、本書三份收進 mysite `docs/`。兩份建議書留在委託人電腦存查，不收進儲存庫。

---

## 1. 依據與查證方法

### 1.1 依據檔（2026-11-06 以 `Get-FileHash` 實算 SHA-256）

| 檔案（同資料夾） | SHA-256 |
|---|---|
| `MySiteReact前端專案v1.md` | `602CFEC145928AA6D992AD9BC0D86C2CE0F6430D506D2A45C702FDB660693B3D` |
| `MySiteReact前端專案v2.md` | `A1BCC6BDE048CC4BD24DB31F852AA35695B34CB03443BF5010F7A618A561614E` |
| `MySiteReact前端專案v2_codex建議v1.md` | `81364230B595EDB2991055076EC57C4A394AF63BE8429594D5D1DC661C62A642` |
| `MySiteReact前端專案v2_Argon建議v2.md` | `8B8477A98B57A33E41EA9879C460D3C7B45393CD4D8335E3D4D3ABA0C0B8FA06` |
| `MySiteReact前端專案_F0本地施工紀錄.md` | `6E817C31A2DB10F2BE4400B6A6751728BB9DED620B4E0737A1FEAAFE1319F9C6` |

v1 的雜湊與兩份建議書所記相同，表示三方讀的是同一版 v1。上表是本書整合時讀到的版本；本書完成後，在五份檔案的檔首各加了一行指向本書的後註（正文未改），所以現在重算會與上表不同。

### 1.2 查證方法

| 對象 | 誰查、怎麼查 |
|---|---|
| MyDyad 原始碼 | 主筆親讀 `C:\Users\whenwhosay\MyPythons\MyDyad\`（1.16.0），含 `node_modules\kill-port\index.js` |
| mysite、MySiteStorage | 子代理以 `gh api` 唯讀讀取，全程不寫入；讀 `infra/versions.env` 時被金鑰鎖攔下，未繞道 |
| 官方文件 | 子代理查 GitHub、Node.js、Next.js、Tailwind CSS、Chrome、nginx 官方頁面（§13） |
| 本機正典 | `MyVault\00_系統規格\本機埠分配表.md`、`MyVault\08_工法手冊\寫程式工法_本機落地規範.md` |

查不到的照實標「查不到」，不以推測補。

---

## 2. 現況（2026-11-06 讀回）

| 項目 | 讀回結果 |
|---|---|
| MySiteStorage | 公開；`main` 與 `design/work` 同在 `a5e8136e3c778ee2478caebb9317e9989fc565ca`；只有三個初始檔；沒有工作流程；rulesets 為空 |
| MySiteStorage `main` 保護 | 需 PR、核准 0、`enforce_admins` 為 true、禁強推、禁刪除；必要檢查**未設定**（F3 回填） |
| MySiteStorage Actions | 啟用；允許全部 Action；工作流程預設權限 read；不得核准 PR |
| mysite `main` | HEAD `98bd557da54f7734e0cade5c0f64cabcefc94003`（PR #2 的合併提交）；開著的 PR 為 0 |
| mysite `main` 保護 | `enforce_admins` 為 true；必要檢查三項「靜態檢查與秘密掃描」「整合驗證（content）」「整合驗證（simulation）」，來源綁 GitHub Actions（`app_id` 15368），`strict` 為 false |
| F0 雲端部分 | **尚未執行**：`docs/架構決策.md` 最後一條是 ADR-014；`docs/` 沒有 v1、v2 |
| mysite 其他分支 | `claude/happy-euler-evh676` 領先 main 一個提交（「新增 mysite 建置總結 v1…」），未開 PR；F0 雲端會話開工前先向委託人確認它的去留，不得自行處理 |
| D17 全權略過 | F0 紀錄載明仍開啟；本次未讀執行中狀態，不代為判定現況 |

---

## 3. 查證帳本

「處置」欄：**採納**＝照原意寫入本書；**改寫**＝方向對，但事實或做法須修正後寫入；**不採**＝理由見該列或 §12。

### 3.1 Codex 建議 v1

| 編號 | 說法（Codex 原位置） | 查證結果 | 處置 |
|---|---|---|---|
| C-1 | 預覽啟動前就清埠，F4 先啟動、後查埠的順序錯（§4.1） | 成立：`app_runtime_service.ts:1615` 在 `startProcess` 前呼叫 `cleanPort` | 採納 |
| C-2 | 若匯入必定自動啟動、無法先查，F4 不具安全前提，交 MyDyad 案修正（§4.1） | 前提成立（匯入即啟動，見 A-1），結論不成立：不必知道 app 編號，查整段即可 | 改寫：匯入前查整段 32100～42099（§4.1） |
| C-3 | 預覽失敗不得拿掉 `-H localhost`（§4.2） | 成立：不帶 `-H` 時 Next.js 預設綁所有介面（v1 §6.6 已引官方 CLI 文件） | 採納：刪 v1 F4 第 5 步備案 |
| C-4 | Node 24 線已有發布的修補版，不能只等 11-11（§4.3） | 成立：10 月安全修補 v24.22.1；24 線最新 v24.22.2（§3.4 第 4 項） | 採納為呈報事項，不改 D11 |
| C-5 | 撤下保證要涵蓋返回快取等真實導航，不能宣稱收回已交付內容（§4.4） | 成立 | 採納（§5 M-01） |
| C-6 | 公開內容與分頁可優先評估一般 `<a>`（§4.4） | 對 `getServerSideProps` 頁面，預取只載程式碼、不先抓資料（§3.4 第 5 項），撤下保證不受影響 | 改寫：連結分流（§4.4） |
| C-7 | F2 第 4 步「app-web 只許 backend 網段」與 §4.6 新網路矛盾（§4.5） | 成立：v1 F2 第 4 步原文確有此句 | 採納（§5 M-06） |
| C-8 | 前端經 gateway 可能繞到其他上游（§4.5） | 現行 gateway 與 app-web 都沒有依來源位址放行的規則（§3.3 查核 13），前端經 gateway 拿到的不會比外網訪客多 | 改寫：以低成本 `geo` 斷線作縱深防禦，照實說明不是已知漏洞（§4.5） |
| C-9 | GitHub 對公開儲存庫 `pull_request_target` 的預設阻擋政策（§4.6） | 成立：2026-12-02 起強制（§3.4 第 1 項） | 採納：F3 建立只允許守門工作流程的事件政策（§5.2 第 7 項） |
| C-10 | 受保護路徑判定對所有 PR 生效，不靠分支前綴豁免；施工者改受保護區時以委託人核准、綁定該 PR 與提交處理（§4.6） | 單人儲存庫沒有現成的「委託人核准」機制：PR 作者不能核准自己的 PR，核准數設 0，且 `enforce_admins` 為 true。若照「一律阻擋」實作，施工者合法修改受保護區的 PR 會永遠紅燈，連委託人也無法合併 | 改寫：分三類判定，施工者分支以逐檔宣告＋清單留言＋結果綁定 head 提交，作為「綁定該 PR 與提交」的具體做法（§4.2） |
| C-11 | 首次建立 CI 的啟用順序、檔案清單分頁與改名、結果綁最新提交、留言工作不執行 PR 產物（§4.6） | 成立 | 採納 |
| C-12 | 假資料同源與真資料核准來源分開判準；不放寬 CORS（§4.7） | 成立：v1 FE-06「只對同源」與 §5.6 圖片政策（https 任一主機）在真資料下互相衝突 | 採納 |
| C-13 | npm 選擇器只看 `packageManager` 字首，不鎖定執行版本；pnpm 舊備案不是自動換線授權（§4.8） | 成立：`package_manager_selection.ts`；另見 N-4 | 採納 |
| C-14 | 契約、型別、zod 不能各自漂移；假資料轉換層；映像搜尋假資料標記；查 `SafeHtml` 呼叫端（§4.9） | 成立 | 採納 |
| C-15 | 429 狀態矩陣、跨 Laravel 用一般連結、換版時舊頁面（§4.10） | 成立 | 採納 |
| C-16 | 頁面配置與狀態優先（§3） | 方向成立；§3.4「空列表」的文案與既有 E2E 不符 | 改寫：零結果文字保留「找不到符合」（§4.3） |
| C-17 | 資源量測只看本專案的行程樹；D13c 依實作語意算，不機械相加（§5.4） | 成立：實作中 5 秒是含連線的總時限（§3.3 查核 6） | 採納（§4.8） |
| C-18 | 交付回報分五個狀態（§5.5） | 成立 | 採納（§9.1） |
| C-19 | mysite 必要檢查 `strict` 為 false，須明確設計（§1.1） | 成立（§2） | 本書定案：維持 false，以流程補足（§4.12） |

### 3.2 Argon 建議 v2

| 編號 | 說法（Argon 原位置） | 查證結果 | 處置 |
|---|---|---|---|
| A-1 | 匯入成功即自動啟動預覽並清埠（§1.1、§4.1） | 成立：`ImportAppDialog.tsx:309` `setSelectedAppId(result.appId)` → `PreviewPanel.tsx:226` `runApp(selectedAppId)` → `app_runtime_service.ts:1615` `cleanPort` | 採納 |
| A-2 | 匯入前只查「監聽中」的埠即可（§4.1 指令 `-State Listen`） | 不足：`kill-port` 在 Windows 比對 `netstat -nao` 每一行的本機埠欄位，不分連線狀態，比對到就 `TaskKill /F`（`node_modules\kill-port\index.js:13-30`）；Docker 模式改停發布該埠的容器（`app_runtime_service.ts:1405-1451`） | 改寫：不限狀態＋容器＋浮動埠範圍（§6 F4 第 2 步） |
| A-3 | 預覽埠段只登記 6 個埠；代理埠段含 42600、42601、43411 與浮動禁區（§4.1） | 成立；代理埠段另有 44424、50501 未列 | 採納並補列 |
| A-4 | 預覽啟動不排他，期間變動可能被自動存版（§4.3） | 成立：`app_runtime_service.ts:1520-1530` 註解 | 採納 |
| A-5 | `next dev` 會自動改 `tsconfig.json`、`next-env.d.ts`（§4.3） | 成立（§3.4 第 7 項）；官方建議 `next-env.d.ts` 列入 `.gitignore`，不提交 | 改寫：`next-env.d.ts` 列入 `.gitignore`，`tsconfig.json` 定型，零啟動自改寫封條（§4.10、§5 M-11） |
| A-6 | AI_RULES 要寫明 Tailwind v4 寫法（§4.4） | 成立（§3.4 第 8 項） | 採納 |
| A-7 | 外網掃描在 next 模式放大「逾千倍」（§4.5） | 倍數沒有量測依據，不採；缺口本身成立：`deny-sensitive.conf` 只被 `app-web.conf:32` 與 `wp-site.conf:9` 引入，gateway 兩份範本都沒有 | 改寫：以「與 blade 模式等價」為準（§4.6） |
| A-8 | 萬用路由對超過兩段的路徑免打 API（§4.5） | 成立：公開 GET 路由最深兩段，分頁用 `?page=`（`app/routes/content.php:26-44`、`pagination.blade.php:7-9`） | 採納（只用段數判斷，不用副檔名猜測） |
| A-9 | gateway 以 `geo` 斷開前端連入（§4.6） | 做法可行；現況不是漏洞（見 C-8） | 採納為縱深防禦 |
| A-10 | 搜尋與跨系統路徑用原生 HTML，內容頁用 `<Link prefetch={false}>`（§4.7） | 成立 | 採納，並集中到受保護元件 `SiteLink` |
| A-11 | 不加 `pageshow` 強制重新整理（§2.2） | 成立 | 採納 |
| A-12 | `/_next/` 維持不攔截錯誤，換版舊分頁自動退回整頁載入（§4.7） | gateway 全樹沒有 `proxy_intercept_errors` 也沒有 `error_page`；`/_next/data` 回錯誤時路由器改走整頁載入，依原始碼成立（§3.4 第 10 項） | 採納（F6 不得新增攔截） |
| A-13 | 既有 E2E 斷言「找不到符合」（§4.8） | 成立：`media.spec.mjs:125`；Blade 原文「找不到符合「{{ $q }}」的內容」帶 `role="status"`（`search.blade.php:34-35`） | 採納 |
| A-14 | `infra.spec.mjs` 以正規式從原始 HTML 抓音訊網址（§4.8） | 成立：`infra.spec.mjs:43-54`；另以 `href="(\/podcasts\/[^"?#]+)"` 從列表頁抓詳情連結 | 採納，並補「站內連結輸出相對路徑」 |
| A-15 | 首頁 `@DAT-04` 只許主站與 CMS 兩個主機（§4.8） | 成立：`media.spec.mjs:145-150` | 採納：F1 記錄真資料封面圖若來自其他主機時的邊界（與 blade 模式相同） |
| A-16 | `src/server` 以 `Promise.all` 並行取資料（§4.9） | 並行成立；`Promise.all` 有一方失敗就丟掉另一方的結果 | 改寫：`Promise.allSettled` 並附結果表（§4.9） |
| A-17 | D13c 算式：重試次數×（連線＋讀取） | 不成立：5 秒是含連線的總時限；只有連線失敗與 5xx 才重試，逾時不重試；重試間隔 100 毫秒（`WordPressRestClient.php:22-30,145-147,271-273`） | 改寫（§4.8） |
| A-18 | `.env.local` ↔ `.env.local.off` 改名切換真資料預覽（§4.10） | 成立：`.gitignore` 含 `.env.*`；MyDyad 永不把 `.env`、`.env.local` 送進模型（`codebase.ts:94`）。另查到 MyDyad 的「環境變數」面板寫的是同一個 `.env.local`（`app_env_var_utils.ts:18`） | 改寫：只由本機 Claude Code 切換，不用面板設這個變數（§9.4） |
| A-19 | 本機看圖一律用 Firefox ESR 免安裝版（§4.11） | 成立：`寫程式工法_本機落地規範.md:40-41` | 採納 |
| A-20 | 切到別的 app 時預覽保留 10 分鐘才回收（§4.11） | 成立：`PreviewPanel.tsx:237-243` | 採納：收工一律關閉 MyDyad |
| A-21 | 守門：Bootstrap、`design/*` 硬性阻擋、施工分支 `[PROTECTED-CHANGE]` 宣告並留言、`env:` 傳值、`workflow_run` 備援（§4.2） | 前四項成立，但宣告只檢查「有沒有標記」不夠；`workflow_run` 備援不成立：官方寫明 `workflow_run` 觸發的工作所產生的檢查不計入必要檢查，改寫 commit status 是否計入，官方沒寫（§3.4 第 3 項） | 改寫：宣告須逐檔與實際清單一致、加 `edited` 觸發、fork 一律失敗；主線改為建立事件政策，`workflow_run` 只在實測證明有效後才可用（§4.2、§5.2） |
| A-22 | 假資料植入哨兵字串，建置產物與映像全檔搜尋（§4.12） | 成立 | 採納（字串由施工者定） |
| A-23 | 語意金絲雀改壞驗證（§4.12） | 成立，與 `寫程式工法` 及記憶卡「外部測試要查有沒有載入主程式」一致 | 採納 |
| A-24 | 「墨韻深色內容站」與 `@theme` 語意變數分層（§3） | 變數分層成立；風格名稱是新造詞，不採 | 改寫：只採分層與命名規則（§5 M-12） |
| A-25 | `postcss.config.*`、`next-env.d.ts` 補入受保護清單（§4.3） | `postcss.config.*` 成立（v1 §6.2 未列）；`next-env.d.ts` 依官方建議應列入 `.gitignore` | 改寫：`postcss.config.*` 列受保護；`next-env.d.ts` 列入 `.gitignore` |
| A-26 | mysite HEAD 為 `98bd557`、開著的 PR 為 0、F0 雲端部分未做（§1.1） | 成立（§2） | 採納 |

### 3.3 mysite 與 MySiteStorage 唯讀查核（子代理，2026-11-06）

| 查核 | 結論 | 證據位置（mysite） |
|---|---|---|
| 1 | HEAD 與 PR 如 §2；`docs/` 沒有 v1、v2；沒有 ADR-015 | `docs/架構決策.md:68` 為 ADR-014 |
| 2 | 搜尋零結果斷言 `main [role="status"]` 含「找不到符合」；首頁只許 `APP_URL.host`、`CMS_URL.host` | `tests/e2e/specs/media.spec.mjs:125,145-150`；`helpers/env.mjs:11-14` |
| 3 | 以不跑 JS 的請求取 HTML，正規式抓 `<source>`／`<audio>` 的 `src`，送 `Range: bytes=0-99` 驗 206 | `tests/e2e/specs/infra.spec.mjs:43-54` |
| 4 | SEO：title 唯一；canonical 指主站且等於本頁路徑加查詢字串；`og:url` 指主站；頁面連結不得指向 CMS 頁面（uploads 除外）；公開頁不得 noindex；沒有 JSON-LD 與 robots.txt 的測試 | `tests/e2e/specs/seo.spec.mjs:11-92` |
| 5 | `deny-sensitive.conf` 只在 `app-web.conf:32`、`common/wp-site.conf:9` 引入；app-web 另有 `location = /index.php` 交給 PHP（第 48 行）、`location ~ \.php$ { return 404; }`（第 53 行，主筆以 `gh api` 唯讀補讀）；gateway 沒有 `proxy_intercept_errors`、沒有 `error_page`；gateway 的 `add_header` 只有 https 範本的 HSTS | `infra/nginx/` 全樹 |
| 6 | WordPress：連線 2 秒、總時限 5 秒、最多 2 次嘗試；只對連線失敗與 5xx 重試，逾時不重試；重試間隔 100 毫秒；gateway `proxy_read_timeout 60s` | `docs/設定目錄.md:24-25`；`app/config/mysite.php:27-29`；`WordPressRestClient.php:22-30,145-147,271-273`；`common/gateway-proxy.conf:15-16` |
| 7 | `edge` 非 internal；`backend`、`db` 為 internal；子網 `172.30.10.0/24`、`172.30.20.0/24`、`172.30.30.0/24`；gateway 固定 `172.30.10.10` | `infra/compose.base.yml:263-266,285-297` |
| 8 | `ci.yml` 觸發：推送到 `main` 與 `claude/**`、對 main 的 PR；檢查名稱同 §2 | `.github/workflows/ci.yml:5-10,27,56,65` |
| 9 | 正式主機「2 vCPU／4 GB RAM」標明為工程估算、需實測；PHPUnit 356 項 | `docs/runbooks/主機開通.md:28`；`docs/規劃書v5.md:1240-1245`；`docs/交付報告v2.md:54` |
| 10 | 公開 GET 路由最深兩段；分頁用 `?page=N` | `app/routes/content.php:23,26-44`；`components/pagination.blade.php:7-9` |
| 11 | Blade 搜尋零結果：`<div class="empty" role="status">`，文字「找不到符合「{{ $q }}」的內容」；有結果時摘要也是 `role="status"`；錯誤分支是 `role="alert"` | `app/resources/views/content/search.blade.php:30-39` |
| 12 | 兩個 main 的保護如 §2 | `gh api` 讀回 |
| 13 | gateway、app-web 沒有任何依來源位址放行的規則，也沒有 real_ip；只有 wp-web 8081 以 `geo $realip_remote_addr` 只許 gateway 固定位址；`InternalOnly` 對經 gateway 的請求回 404、對容器直連放行 | `wp-web.conf.template:31-34,65-73`；`InternalOnly.php:19-26` |

查核 13 的含意：前端容器若直連 app-web，`InternalOnly` 會放行 `/internal/` 下的路由。v1 §4.6、§5.5 已要求 app-web 對前端網段只放行 `/api/v1/`，這一條因此是**必要**防線，F6 不得省略（PAPI-08 必驗）。

### 3.4 官方文件查核（子代理，2026-11-06）

版本、政策與議題狀態可能變動，施工當日以下列網址重查。

| # | 查核項目 | 結論 | 對本書的影響 | 出處 |
|---|---|---|---|---|
| 1 | GitHub 對公開儲存庫 `pull_request_target` 的預設政策 | 成立。尚無適用 Actions 事件政策的公開儲存庫，GitHub 會加一條預設政策阻擋 `pull_request_target`；現為評估模式（照跑、只在 Policy insights 標出），**2026-12-02 起強制**；強制前另有 2026-11-09、11-16 兩次演練封鎖（各 16:00～18:00 UTC）。要繼續使用，須在儲存庫 Settings → Actions → Policies 建立明確允許的事件政策，可限定到特定工作流程檔；設定權限屬儲存庫管理員 | 守門主線改為「建立只允許守門工作流程的事件政策」（§5.2 第 7 項）；mysite 的工作流程不用 `pull_request_target`，不受影響（§3.3 查核 8） | https://docs.github.com/en/actions/reference/security/securely-using-pull_request_target ；https://github.blog/changelog/2026-11-02-pull_request_target-blocked-by-default-december-2 ；https://docs.github.com/en/actions/how-tos/administer/control-workflow-execution |
| 2 | 必要檢查的來源與同名結果 | 必要檢查可指定預期來源 App；有寫入權的任何人或整合都能設定任何狀態；commit status 同名時以最新一筆為準；不同工作流程用相同 job 名稱會造成「ambiguous」結果並可能阻擋合併；多個同名 check run 以哪個為準，官方查不到。同儲存庫 PR 的工作流程可在檔內宣告 `statuses: write` 取得寫入權 | 殘餘風險照實寫（§4.2 末）；以 check run 為守門結果，比改寫 commit status 不易被同名結果覆蓋 | https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-protected-branches/about-protected-branches ；https://docs.github.com/en/pull-requests/how-tos/merge-and-close-pull-requests/troubleshooting-required-status-checks ；https://docs.github.com/en/rest/commits/statuses ；https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax#permissions |
| 3 | `workflow_run` | 一律執行預設分支上的定義，成立。但官方列出只有 `push`、`pull_request`、`pull_request_review`、`pull_request_target`、`deployment`、`deployment_status` 觸發的工作所產生的檢查會被 PR 評估、能滿足必要檢查；`workflow_run` 不在其中。以 REST 寫的 commit status 算不算，官方沒寫 | Argon 的備援不採為主線；只在實測證明有效後才可用（§5.2 第 7 項） | https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows ；https://docs.github.com/en/pull-requests/how-tos/merge-and-close-pull-requests/troubleshooting-required-status-checks ；https://securitylab.github.com/resources/github-actions-preventing-pwn-requests/ |
| 4 | Node.js 24 線 | 2026-10-21 安全發布，24 線修補版 v24.22.1；v24.22.2（CHANGELOG 標 2026-10-28，`dist/index.json` 記 10-27）不是安全發布；24 線目前最新 v24.22.2；2026-11-11（或稍後）另有安全發布，26、24、22 三線最高嚴重度 High | 電腦上的 v24.18.0 沒有 10 月修補；何時安裝依 D11 由委託人決定（§6 F4 第 13 步） | https://nodejs.org/en/blog/vulnerability/october-2026-security-releases ；https://nodejs.org/en/blog/vulnerability/november-2026-security-releases ；https://nodejs.org/dist/index.json |
| 5 | `next/link` 預取 | `prefetch={false}` 時進入視窗不預取，但滑鼠停留仍會預取（只在正式建置）。對 `getServerSideProps` 頁面，官方文件沒寫預取抓不抓資料；原始碼只對靜態產生的頁面預先抓 `/_next/data`，其他頁面只預載程式碼；例外是路徑命中 middleware／proxy 時（本案禁用） | 預取不會提前取得正文，撤下保證不受影響；仍保留 `next/link`（§4.4） | https://nextjs.org/docs/pages/api-reference/components/link ；https://github.com/vercel/next.js/blob/v16.2.0/packages/next/src/shared/lib/router/router.ts |
| 6 | Next.js CLI | `next dev` 的 hostname 預設 `0.0.0.0`；Next 16 的 `dev` 與 `build` 預設 Turbopack，`--webpack` 改回 webpack；`dev` 輸出到 `.next/dev` | 維持 `-H localhost`（§4.1、§5 M-11） | https://nextjs.org/docs/app/api-reference/cli/next ；https://nextjs.org/blog/next-16 |
| 7 | TypeScript 與 `next-env.d.ts` | `next dev`、`next build`、`next typegen` 都會重新產生 `next-env.d.ts`；官方建議把它加入 `.gitignore`，已追蹤者應移出版本控制；CI 建議先跑 `next typegen` 再跑 `tsc --noEmit`。`next dev`／`next build` 會把缺少的建議值寫回既有 `tsconfig.json`（有 `extends` 或 `references` 時不改） | `next-env.d.ts` 列入 `.gitignore`；`typecheck` 指令先 `next typegen`；`tsconfig.json` 定型（§4.10、§5 M-11） | https://nextjs.org/docs/pages/api-reference/config/typescript ；https://nextjs.org/docs/app/api-reference/cli/next ；https://github.com/vercel/next.js/blob/canary/packages/next/src/lib/typescript/writeConfigurationDefaults.ts |
| 8 | Tailwind CSS v4＋Next.js | 安裝 `tailwindcss`、`@tailwindcss/postcss`、`postcss`；`postcss.config.mjs` 只放 `@tailwindcss/postcss`；CSS 入口 `@import "tailwindcss";`；不需要 `tailwind.config.js`（JS 設定只以 `@config` 相容保留）；v3 的 `@tailwind` 指令不再是正確寫法。瀏覽器需求：Safari 16.4+、Chrome 111+、Firefox 128+ | AI_RULES 寫死 v4 寫法（§5.1）；瀏覽器需求列入 F3 核對（R29） | https://tailwindcss.com/docs/guides/nextjs ；https://tailwindcss.com/docs/upgrade-guide ；https://tailwindcss.com/docs/functions-and-directives ；https://nextjs.org/docs/pages/getting-started/css |
| 9 | Chrome 返回快取與 `no-store` | Chrome 已允許帶 `Cache-Control: no-store` 的頁面在安全時進入返回快取（2025 年 3～4 月全面推出）；這類頁面在快取中的時限縮為 3 分鐘；cookie 變動會逐出；僅限 Chrome | 撤下保證的瀏覽器邊界照實寫（§4.7、§5 M-01） | https://developer.chrome.com/docs/web-platform/bfcache-ccns |
| 10 | `/_next/data` 回錯誤時的路由行為 | 依原始碼成立（官方文件查不到專門敘述）：非成功回應（帶 `notFound` 的 404 JSON 除外）或網路失敗，路由器改以 `window.location.href` 整頁載入；預取階段的失敗不觸發導覽 | 換版時舊分頁、站內換頁遇到 404／503 時，預期行為是整頁載入後由伺服器畫出正確錯誤頁（§7 FE-17） | https://github.com/vercel/next.js/blob/v16.2.0/packages/next/src/shared/lib/router/router.ts |
| 11 | 上游議題 | dyad #2265（Next.js＋Turbopack 的元件標記器）仍開著；npm/cli #4828 於 2025-04-03 關閉，關閉後仍有留言者稱在 npm 11.16.0、11.17.0 復現（網友說法，官方未確認）。電腦上的 npm 是 11.16.0（Codex 建議書 §1.1 讀回） | 元件標記器仍以 F3 實測為準；F4 鎖定檔四時點檢查必做（R30） | https://github.com/dyad-sh/dyad/issues/2265 ；https://github.com/npm/cli/issues/4828 |
| 12 | nginx | `if` 放在 server 層、內含 `return` 是安全用法；`return 444` 不送回應直接關閉連線；`geo` 依 `$remote_addr`（或指定變數）判斷，只能寫在 `http` 層 | `geo` 斷線做法成立（§4.5） | https://github.com/nginxinc/nginx-wiki/blob/master/source/start/topics/depth/ifisevil.rst ；https://nginx.org/en/docs/http/ngx_http_rewrite_module.html ；https://nginx.org/en/docs/http/ngx_http_geo_module.html |

### 3.5 兩份建議都沒看到、本書新補

| 編號 | 缺口 | 依據 | 處置位置 |
|---|---|---|---|
| N-1 | 代理埠反向占埠：代理固定先綁 `42100＋編號`，若該埠是委託人已登記但當下沒開的服務（42600、42601、43411、44424、50501），那個服務之後就綁不上 | `MyDyad\shared\ports.ts:42-85`；本機埠分配表第 70、71、106、108、109 列 | §6 F4 第 4 步 |
| N-2 | 清埠不分連線狀態、強制結束；若 Docker Desktop 的轉送程式正占著該區段的埠，被結束的會是 Docker 的轉送程式，連帶所有容器的對外連線 | `kill-port\index.js:13-30`；`app_runtime_service.ts:1397-1451` | §6 F4 第 2 步 |
| N-3 | 本機浮動埠範圍若被改到涵蓋 32100～42099，任何程式的對外連線都可能落在預覽埠上而被誤殺 | 埠分配表第 17–24 列（浮動範圍 43500 起、4001 個，可能被系統更新改掉） | §6 F4 第 2 步 |
| N-4 | MyDyad 不一定用電腦上的 Node：設定有 `nodeRuntimePreference`（system／managed）與 `customNodePath` | `MyDyad\src\lib\schemas.ts:590-591` | §6 F4 第 8 步 |
| N-5 | MyDyad「環境變數」面板與真資料切換寫同一個 `.env.local`，兩個寫入者會互相覆蓋 | `app_env_var_utils.ts:18` | §9.4 |
| N-6 | `Promise.all` 一方失敗就丟另一方結果 | JavaScript 語意 | §4.9 |
| N-7 | 站內連結規則若只寫在 AI_RULES，MyDyad 改版時容易寫錯；應集中到受保護元件 | v1 §6.4 既有做法（YouTube、Podcast、圖片皆以受保護元件守住） | §5 M-09 |
| N-8 | MyDyad 若切換或新建分支，守門對 `design/` 的硬性阻擋會被繞開 | D8「MyDyad 只推 `design/work`」 | AI_RULES 第 15 條 |
| N-9 | `workflow_run` 觸發的工作所產生的檢查不計入必要檢查；改 PR 說明若不加 `edited` 事件類型，不會重新判定宣告 | §3.4 第 3 項 | §4.2、§5.2 |
| N-10 | 同儲存庫 PR 可在工作流程檔宣告寫入權，理論上能送出與守門同名的結果 | §3.4 第 2 項 | §4.2 殘餘風險 |

---

## 4. 衝突裁量（兩份建議或與 v1 不一致之處）

### 4.1 查埠時機
- v1：匯入、預覽之後才查（F4 第 10 步）。Codex：查不到就停工交 MyDyad 案。Argon：匯入前查整段「監聽中」的埠。
- **定案**：匯入前由本機 Claude Code 查整段 32100～42099，**不限連線狀態**，並查發布在該段的容器、確認浮動埠範圍不重疊；全空才請委託人按「匯入」。匯入後立刻讀回實際的預覽埠與代理埠，比對埠分配表並登記。不改 MyDyad 本體、不交 MyDyad 案。
- 理由：清埠的目標埠由 app 編號決定，匯入前不知道編號，但整段沒有任何占用者時，不論編號為何都清不到別人。之後每次啟動預覽都會再清一次，所以登記進埠分配表、讓其他服務避開，才是長期保護。

### 4.2 受保護路徑守門的適用範圍
- v1：只判 `design/` 分支。Codex：所有 PR 一律判，不靠分支名豁免。Argon：`design/*` 硬性阻擋、其他施工分支憑 `[PROTECTED-CHANGE]` 標記放行並留言列清單。
- **定案**：分三類。

| PR 來源 | 改到受保護路徑時 |
|---|---|
| 他人 fork 的儲存庫 | 一律失敗 |
| 本儲存庫、分支名以 `design/` 開頭 | 一律失敗；PR 留言列檔名，附本機 Claude Code 可執行的單檔還原指令（v1） |
| 本儲存庫、其他分支（施工者） | PR 說明須有一個結構化宣告區塊，逐一列出本次改動的受保護檔案；與實際清單**完全一致**才通過，缺漏或多列都失敗。工作流程在 PR 留一則（之後更新同一則）留言，列出受保護檔案清單，供委託人合併前核對 |

- 理由：兩個 main 都設了「管理員也不得繞過」且核准數為 0，若所有 PR 一律阻擋，施工者合法修改受保護區的 PR 永遠無法合併，連委託人也按不下合併。分支名不是授權；真正的授權是委託人看過清單後按下合併。守門在前端儲存庫的任務是兩件事：攔下 MyDyad 的意外改動，以及把受保護改動攤在委託人眼前。
- 補強：觸發加上 `edited`，改 PR 說明會重新判定；檔案清單分頁讀完，改名同時檢查舊路徑與新路徑；清單取得不完整就判失敗；所有來自 PR 的欄位（分支名、標題、說明、檔名）一律經 `env:` 傳入腳本，不在 `run:` 裡直接展開 `${{ }}`。
- **殘餘風險（照實寫）**：同儲存庫 PR 可以在工作流程檔宣告寫入權，理論上能送出與守門同名的結果；官方只說「不同工作流程用相同 job 名稱會造成 ambiguous 結果並可能阻擋合併」，多個同名 check run 以哪個為準查不到（§3.4 第 2 項）。能推送到本儲存庫的只有持委託人帳號權限的工具；能抵抗刻意偽造的是 mysite F6 的獨立重驗（在另一個儲存庫執行，前端 PR 改不到）與委託人合併前看到的清單。前端儲存庫守門的定位是第一道、防意外。因此守門結果採 `pull_request_target` 產生的 check run，不改用以最新一筆為準、較易被覆蓋的 commit status。

### 4.3 搜尋零結果的文字
- Codex §3.4：「說明目前沒有符合內容」。Argon：必須含「找不到符合」。
- **定案**：沿用 Blade 原文「找不到符合「（查詢詞）」的內容」，放在 `<main>` 內、帶 `role="status"`；有結果時的摘要同樣 `role="status"`；錯誤分支用 `role="alert"`（皆照 `search.blade.php:30-39`）。設計可改字級、排版、圖示，不改這幾個字與角色屬性。
- 理由：`media.spec.mjs:125` 直接斷言這幾個字；F3 的原則是先複刻 Blade。

### 4.4 連結
- Codex：公開內容可優先評估一般 `<a>`。Argon：分流。
- **定案**：新增受保護元件 `SiteLink`（`src/components/safe/`），依目標決定輸出：

| 目標 | 輸出 |
|---|---|
| v1 §4.2 路徑分工表中交給前端的公開頁（首頁、列表、單篇、一般頁面、分類、標籤） | `next/link`，`prefetch={false}`，`href` 為相對路徑（例如 `/articles/某篇`） |
| 搜尋頁與搜尋結果的分頁 | 一般 `<a>` |
| 其他站內路徑（商店、購物車、結帳、訂單、後臺、預覽、`/feed.xml`、`/sitemap.xml`、不認得的路徑） | 一般 `<a>`，相對路徑 |
| 外部網址（選單項目 `external` 為真） | 一般 `<a>`，屬性比照現行 Blade |

- 搜尋輸入一律原生 `<form method="get" action="/search">`，輸入框 `id="search-q"`，不做逐字即時搜尋。
- 禁止 shallow 路由（CI 阻擋 `shallow` 字樣）。
- 理由：Laravel 負責的路徑 Next 本來就無法在瀏覽器端換頁；搜尋走原生請求，一次點擊就是一次請求，超過限流時直接拿到正體中文 429 頁。若搜尋改走程式換頁，`/_next/data` 遇到 429 時路由器會改走整頁載入（§3.4 第 10 項），同一次點擊會吃兩次限流額度。內容頁保留 `next/link`：`prefetch={false}` 時滑鼠停留仍會預取，但對 `getServerSideProps` 頁面只預載程式碼、不先取資料（§3.4 第 5 項），點擊時才經 `/_next/data` 重跑 `getServerSideProps`（v1 §4.3 第 6 步、FE-17），撤下保證不受影響。`href` 一律相對路徑，因為 `infra.spec.mjs:46` 以 `href="/podcasts/…"` 的字面樣式抓詳情頁。

### 4.5 前端經 gateway 的反向連線
- Codex：列為待驗證風險。Argon：`geo` 一律 `return 444`。
- **定案**：F6 在 gateway 以 `geo` 依連線實際來源位址，標記「gateway↔前端」網段；在每一個 `server` 區塊開頭加 `if ($變數) { return 444; }`。gateway 目前沒有 real_ip（查核 13），所以直接用 `$remote_addr`；若日後加上 real_ip，改用 `$realip_remote_addr`。網段位址依 D13f 裁定。
- 照實說明：現行設定下，前端經 gateway 拿到的不會比外網訪客多（查核 13）。這一條是縱深防禦：前端沒有任何正當理由主動連 gateway，斷開它，日後 gateway 若新增「信任內部來源」的規則也不會意外信任到前端。

### 4.6 掃描流量
- Argon：next 模式放大「逾千倍」，gateway 加 `deny-sensitive.conf`、萬用路由再加深度與副檔名過濾。
- **定案**：
  1. gateway 兩份範本的**主站** server 區塊（http 範本的 8080、https 範本的 `${APP_HOST}`）照搬 `app-web.conf` 的三條現行規則：引入 `deny-sensitive.conf`（`app-web.conf:32`）；`location = /index.php` 交給 app-web（`app-web.conf:48`，Laravel 前端控制器）；其他 `\.php$` 直接回 404（`app-web.conf:53`）。CMS 的 server 區塊不加——WordPress 需要 `.php`，而 wp-web 已有自己的 `deny-sensitive.conf`（`wp-site.conf:9`）。ACME 驗證路徑的放行順序不變；`deny-sensitive.conf` 第 2 行本身已排除 `.well-known/`。這三條在 blade 模式下與 app-web 現行結果相同，兩種模式行為一致。
  2. 萬用路由 `[...path].tsx`：路徑段數超過公開路由最深段數（現為 2，出處 `app/routes/content.php:26-44`）時，不呼叫任何 API，直接以 `ErrorView` 內建靜態頁首頁尾回 404 並附請求編號。不以副檔名猜測，因為 slug 規則 `[^/]{1,200}` 允許句點。
- 理由：「逾千倍」沒有量測依據，不寫。可確認的是：blade 模式下 app-web 用 `deny-sensitive.conf` 在 nginx 層直接回 404，next 模式若不補，這些請求會一路進到 Node 與 Laravel。準則是「next 模式不得比 blade 模式差」。至於一般不存在的一、兩段路徑，blade 模式同樣會畫帶選單的 404 頁，維持現狀。

### 4.7 返回快取
- Codex、Argon 一致：不能宣稱瀏覽器絕不留副本。Argon 另主張不加 `pageshow` 強制重新整理。
- **定案**：不加強制重新整理。驗收分兩欄記錄：「服務端與站內換頁」必須在撤下期限內停止供應正文；「瀏覽器單一分頁的返回快取、已下載、截圖、另存」列為 HTTP 無法收回的邊界，照實記錄觀察結果，不判失敗。
- 已知邊界（§3.4 第 9 項）：Chrome 自 2025 年起允許帶 `no-store` 的頁面在安全時進入返回快取，這類頁面在快取中最多保留 3 分鐘；本案公開頁不設 cookie，不會因 cookie 變動被逐出。其他瀏覽器各有規則。
- 理由：強制重新整理會讓訪客按上一頁時畫面閃動、捲動位置跳回頂端，關閉 JavaScript 時也無效；現行 Blade 公開頁本來就受同一套瀏覽器規則管。

### 4.8 D13c 前端逾時的算式
- **定案**：施工者照下列輸入與算式呈報，數值仍由委託人裁定（D13）。
  - WordPress 單次呼叫的最壞耗時＝第一次嘗試上限（總時限 5 秒，已含連線 2 秒）＋重試間隔（100 毫秒）＋第二次嘗試上限（5 秒）。只有連線失敗與 5xx 會走到第二次；逾時不重試，該情況的上限是 5 秒（出處：§3.3 查核 6）。
  - 單一 API 端點的最壞耗時＝該端點對 WordPress「依序」呼叫的次數 × 單次最壞耗時＋Laravel 自身處理時間。依序呼叫次數由施工者讀 `ContentQueryService` 等實作逐端點列出。
  - 前端每頁並行取 `/api/v1/site` 與內容端點，資料等待＝兩者最壞耗時取大者。
  - 前端逾時須大於上一項加上 JSON 解析與驗證時間，且整頁處理須小於 gateway `proxy_read_timeout 60s`。
- 理由：Argon 的「次數×（連線＋讀取）」把已含連線的總時限又加一次，也把不重試的逾時算成重試；Codex 要求依實作語意計算，查核後語意已明。

### 4.9 並行取資料
- **定案**：`src/server` 以 `Promise.allSettled` 並行取 `/api/v1/site` 與內容端點，結果對照：

| 內容端點 | `/api/v1/site` | 回應 |
|---|---|---|
| 200 | 成功 | 正常畫面 |
| 200 | 失敗（傳輸失敗、逾時、格式不符） | 正文照常；頁首頁尾改用 `ErrorView` 同一套內建靜態版（站名取環境變數，只放「回首頁」「隱私說明」） |
| 404／503 | 成功 | 錯誤頁，完整版面，附請求編號 |
| 404／503 | 失敗 | 錯誤頁，內建靜態頁首頁尾，附請求編號 |
| 傳輸失敗、逾時、非 JSON、格式不符 | 任一 | 503（v1 §6.3） |

- 理由：`/api/v1/site` 依設計在 WordPress 讀不到時回內建後備選單、不回 503（v1 §5.2），所以它「失敗」只會是傳輸層問題；這時正文已經合法取得，沒有理由讓整頁變 503。

### 4.10 `next-env.d.ts`
- Argon：列入受保護清單並提交。
- **定案**：依 Next.js 官方 TypeScript 文件（§3.4 第 7 項），`next-env.d.ts` 列入 `.gitignore`、不提交；`typecheck` 指令先跑 `next typegen` 再跑 `tsc --noEmit`。`tsconfig.json` 照常提交，F3 先讓 `next dev` 與正式建置各跑一次，把 Next.js 補寫後的內容定型。兩者都納入零啟動自改寫封條（FE-19）。
- 理由：被忽略的檔案 MyDyad 不會提交，自動存版的競態自然消失；列入受保護清單反而會讓每次重新產生都觸發紅燈。

### 4.11 首次啟用守門的順序
- Codex、Argon 一致，採納（§6 F3 第 1～3 步）。

### 4.12 必要檢查的 strict（合併前須與最新 main 同步）
- **定案**：兩個 main 維持現行 `strict: false`，以流程補足：
  1. 施工者把受保護區的修改合併進前端 main 後，立即把 main 合併回 `design/work`（v1 既定），讓開著的設計 PR 以最新 main 重跑 CI。
  2. 施工者在 mysite 請委託人合併前，確認自己的 PR 已含最新 main 並跑過 CI，在回報中寫明。
  3. main 本身的推送 CI 是第二道；前端 main 若紅燈，mysite 不得鎖定該提交（v1 §6.9）。
- 理由：開 `strict` 會讓委託人每次多按「Update branch」、多等一輪 CI；單人儲存庫、PR 依序進行，上述流程已涵蓋主要風險。這是維持現狀，不新增流程門檻；委託人日後若要開啟，另行裁定。

---

## 5. 對 v1 正文的定案修訂（施工照此）

修訂編號 M-xx；「v1 位置」以 v1 的章節與步驟編號表示。修訂內容裡的「§」未註明者指本書章節，指 v1 章節時一律寫「v1 §」。

| 編號 | v1 位置 | 定案修訂 |
|---|---|---|
| M-01 | §0 第 1 條；§3 第 1 列「新架構的做法」 | 「瀏覽器與中間層都不會留副本」改為：「服務端與中間層不保存副本，撤下後下一次請求（含站內換頁）就拿不到正文。瀏覽器的返回快取在符合條件時仍可能保留整頁，按上一頁時直接顯示；已下載、截圖、另存的內容也無法以 HTTP 標頭收回。這兩項與現行 Blade 公開頁相同。」驗收分欄見 §7 |
| M-02 | §4.2 路徑分工表 | 表首加兩列：「現行 `deny-sensitive.conf` 所列路徑、`/index.php` 以外以 `.php` 結尾的路徑｜gateway 主站區塊直接回 404｜照搬 `app-web.conf:32,53`」；「`/index.php`｜app-web｜照搬 `app-web.conf:48`」。CMS 區塊不加；ACME 驗證路徑順序不變（本書 §4.6） |
| M-03 | §4.3 第 2 步 | 改為並行取 `/api/v1/site` 與內容端點，結果依 §4.9 對照表 |
| M-04 | §4.3 第 4 步「不存在的路徑由根層的萬用路由…接住」 | 加：段數超過公開路由最深段數時，不呼叫 API，以內建靜態頁首頁尾回 404（§4.6） |
| M-05 | §4.6 末 | 加「反向連線」一段：gateway 以 `geo` 標記「gateway↔前端」網段，該網段連入 gateway 一律 `return 444`（§4.5）；並註明 app-web 對前端網段只放行 `/api/v1/` 是**必要**防線（§3.3 查核 13） |
| M-06 | §7 F2 第 4 步 | 「app-web 只許 `backend` 網段」改為：「gateway 對外 `/api/` 回 404；app-web 對 `/api/` 的來源規則依 v1 §4.6、v1 §5.5。F2 先以應用層測試驗證路由、中介層、回應；網段規則與跨網隔離在 F6 compose 齊備後驗收（PAPI-08）。」 |
| M-07 | §6.1 禁用功能檢查 | 增列：任何 `tailwind.config.*`、v3 的 `@tailwind` 指令、`shallow` 路由、`src/styles/globals.css` 缺少 `@import "tailwindcss";` |
| M-08 | §6.2 目錄與保護；§6.6 `.gitignore` 一列 | 受保護增列：`postcss.config.*`、`src/components/safe/SiteLink.tsx`。`.gitignore` 增列 `next-env.d.ts`（§4.10；施工者在 F3 改，`.gitignore` 本身受保護）。`src/styles/` 仍開放 |
| M-09 | §6.3、§6.4 | §6.3 加：站內連結一律經 `SiteLink`（§4.4）；並行取資料（§4.9）；萬用路由深度過濾（§4.6）。§6.4 表加 `SiteLink` 一列；`PodcastPlayer` 一列加「伺服器輸出的 HTML 就帶 `<audio>` 或 `<source>` 的 `src`（`preload="none"`），不得延到瀏覽器端才設定」 |
| M-10 | §6.5 DOM 測試掛鉤 | 清單增列：搜尋零結果與結果摘要的 `role="status"` 與文字「找不到符合」；錯誤分支 `role="alert"`；Podcast 詳情原始 HTML 含音訊 `src`；列表頁原始 HTML 含相對路徑的詳情連結 |
| M-11 | §6.6 開發與建置指令 | ①`dev` 一列加：「任何情況都不得移除 `-H localhost`」。②新增「Tailwind CSS v4」一列：`postcss.config.mjs` 只載入 `@tailwindcss/postcss`；`src/styles/globals.css` 第一行 `@import "tailwindcss";`，設計變數寫在 `@theme`；不建 `tailwind.config.*`。③新增「零啟動自改寫封條」一列：F3 實際跑一次 `next dev --webpack` 到就緒、再跑一次正式建置，把 Next.js 補寫後的 `tsconfig.json` 定型提交；`next-env.d.ts` 列入 `.gitignore`（§4.10）；CI 在正式建置後、以及短暫啟動 `next dev` 之後，`git status --porcelain` 都必須是空的（FE-19）。④`typecheck` 一列改為先 `next typegen` 再 `tsc --noEmit`（§3.4 第 7 項） |
| M-12 | §6.6 末或 §6.7 前（設計變數） | `@theme` 變數依「表面層次、文字層次、品牌與內容類型識別、狀態、閱讀排版」分組命名，畫面元件只用語意工具類（例如 `bg-surface-card`、`text-ink-primary`）；F3 的值全部取自現行 Blade CSS（規劃書v5 §9.2），本書不定任何色碼與尺寸。名稱由施工者定稿 |
| M-13 | §6.7 AI_RULES.md 全文 | 以 §5.1 定案全文取代。v2 表中「技術一行照原文、不改」指 D4 未改變技術選擇；本書補寫版本與寫法，與 D4（Tailwind CSS v4）一致 |
| M-14 | §6.8 主 CI | 加：禁用功能增列（M-07）；FE-19 封條；正式建置產物（`.next/`）全文搜尋假資料哨兵字串；`SafeHtml` 除計數外，查呼叫端只傳入 API 的 `*_html` 欄位；首次建立與每次大改驗收時做一次語意金絲雀改壞驗證（§6 F3 第 7 步） |
| M-15 | §6.8 獨立的受保護路徑檢查 | 以 §5.2 定案規格取代 |
| M-16 | §6.8 失敗留言 | 留言工作只讀取已驗證的結果資料，不執行 PR 的任何產物；執行 PR 程式的工作維持唯讀權限 |
| M-17 | §6.9 正式映像 | 加：最終映像全檔搜尋假資料哨兵字串，出現即失敗（FE-09）；若排除假資料導致建置失敗，修正載入邊界，不得把假資料放回映像 |
| M-18 | §7 F1 | 加：①畫面欄位對照表（每個欄位供哪個畫面用）。②契約樣本集分「有效」「無效」兩組，PHP 驗證器與前端 zod 用同一批（F2、F3 驗）。③導航狀態矩陣：HTML 頁與 `/_next/data` 各自遇到 404、429、503、500、非 JSON、逾時的預期。④記錄 `@DAT-04` 邊界：真資料封面圖若來自 CMS 以外的 HTTPS 主機，首頁檢查會失敗，blade 模式相同 |
| M-19 | §7 F2 第 5 步 | 加：媒體網址改寫放在明確的假資料轉換層，轉換後的資料仍以契約驗證；合成文字植入固定哨兵字串（字串由施工者定）；不得為了假資料放寬正式來源政策 |
| M-20 | §7 F3 | 以 §6 F3 定案步驟取代動作順序（內容不減） |
| M-21 | §7 F4 | 以 §6 F4 定案步驟取代 |
| M-22 | §7 F6、F7 | 依 §6 F6、F7 增補 |
| M-23 | §8 委託人日常操作 | 依 §9 增補 |
| M-24 | §9 驗收登錄 | 依 §7 增補 |
| M-25 | §10 風險 | 依 §8 增補 |
| M-26 | §6.1 Node.js（委託人電腦）一列 | 依 §3.4 補最新修補版資訊；何時更新仍依 D11 由委託人決定 |

### 5.1 AI_RULES.md 定案全文（取代 v1 §6.7 草案；`AGENTS.md` 內容相同）

```markdown
# MySite 前端：給 AI 的規則

## 你的工作
你負責 MySite 公開網站的「畫面設計」：版面、配色、字級、間距、元件外觀與互動細節。
資料已經準備好，以 props 傳進 `src/views/` 的畫面元件。你不需要、也不可以自己抓資料。
預覽畫面上的內容全是示例資料（來自 `mocks/`）。

## 技術
- Next.js Pages Router（不要建立 `app/` 或 `src/app/` 目錄）、React、TypeScript。
- Tailwind CSS v4，用 CSS 設定：
  - 全站樣式入口是 `src/styles/globals.css`，第一行 `@import "tailwindcss";` 不可刪改。
  - 設計變數寫在同檔的 `@theme { … }`；畫面元件用語意工具類（例如 `bg-surface-card`、`text-ink-primary`），不要把色碼散寫在各元件裡。
  - 不要建立任何 `tailwind.config.*`（`.js`、`.ts`、`.mjs` 都不行）。
  - 不要使用 v3 的 `@tailwind base;`、`@tailwind components;`、`@tailwind utilities;`。
- 不使用 React Router，不使用 shadcn/ui。
- props 型別在 `src/lib/content/types.ts`（只讀）。

## 你可以改
- `src/views/**`、`src/components/**`（`src/components/safe/` 除外）、`src/styles/**`、`public/design/**`

## 你不可以改（改了 CI 會攔下）
- `src/pages/**`、`src/server/**`、`src/lib/**`、`src/components/safe/**`
- `contracts/**`、`mocks/**`、`tests/**`、`public/fonts/**`、`public/mock-media/**`、`.github/**`
- `package.json`、`package-lock.json`、`.npmrc`、`next.config.*`、`postcss.config.*`、`tsconfig.json`、ESLint 設定、`.gitignore`、`.gitattributes`、`.nvmrc`、`AI_RULES.md`、`AGENTS.md`、`CLAUDE.md`、`README.md`
- 需要動這些地方時：停下來，用一段話寫清楚需求，交給施工者處理。

## 硬規則
1. 不新增任何套件，不執行安裝某個套件的指令。
2. 不呼叫任何網址：不用 fetch、axios、XMLHttpRequest、WebSocket；不載入外部字型、外部圖片、外部 script、分析或追蹤碼。
3. 不使用 dangerouslySetInnerHTML。正文一律用 `<SafeHtml html={...} />`。
4. YouTube 一律用 `<YouTubeFacade>`；Podcast 一律用 `<PodcastPlayer>`；內容圖片一律用 `<ContentImage>`；錯誤畫面一律用 `<ErrorView>`；站內與站外連結一律用 `<SiteLink>`。
5. 不寫 cookie、localStorage、sessionStorage。
6. 保留所有 `data-*` 測試掛鉤、`<main id="main">`、跳到主要內容連結、`<header>`、`<nav>`、`<footer>` 與標題階層。搜尋沒有結果時，提示放在 `<main>` 裡並帶 `role="status"`，文字保留「找不到符合」；有結果時的摘要同樣帶 `role="status"`。不得刪除或改名。
7. 介面文字一律正體中文（臺灣用語）；日期只用 `formatTaipeiDate()`。
8. 手機 390、平板 768、桌面 1440 寬度都不能出現水平捲軸；超長中文標題與網址要能換行；正文裡的寬表格與程式碼區塊在自己的框內捲動，不撐開整頁；200% 縮放也要能用。
9. 對比、焦點框、鍵盤操作要符合 WCAG 2.2 AA；尊重「減少動態效果」設定。
10. `is_sample` 為真時，保留「示例內容」標示。
11. 不建立新的頁面路由；需要新頁面時交給施工者。
12. 即使使用者說「記住這件事」，也不得修改 `AI_RULES.md` 或 `AGENTS.md`；請改為回覆一段文字，請使用者交給施工者寫入。
13. 不要用字串拼接組 class 名稱（例如 `bg-${type}-500`）；不同內容類型的樣式，用寫滿完整 class 的對照表。
14. 搜尋一律用原生表單 `<form method="get" action="/search">`，輸入框保留 `id="search-q"`；不要改成邊打字邊搜尋，也不要改用程式換頁。
15. 不切換、不建立、不刪除 Git 分支；只在目前的 `design/work` 上工作。
```

### 5.2 受保護路徑檢查定案規格（取代 v1 §6.8「獨立的受保護路徑檢查」）

1. **觸發**：`pull_request_target`，事件類型 `opened`、`synchronize`、`reopened`、`edited`。不 checkout、不執行 PR 的任何程式、不使用任何秘密；權限只有讀取內容與 PR 留言。
2. **讀檔案清單**：以 GitHub API 分頁讀完 PR 的全部變更檔；改名時舊路徑與新路徑都檢查（檔案從開放區移出、或從受保護區移入開放區，都算動到受保護路徑）。API 回報清單不完整時判失敗。
3. **判定**：依 §4.2 三類。施工者分支的宣告區塊格式由施工者在 F3 定稿並寫進前端 `CLAUDE.md`；比對以檔案路徑逐一相等為準。
4. **留言**：同一則留言反覆更新，不洗版。`design/` 分支失敗時列檔名與還原指令；施工者分支列出受保護檔案清單，開頭寫「合併前請核對」。
5. **防注入**：分支名、標題、說明、檔名等 PR 欄位一律經 `env:` 傳進腳本，不在 `run:` 裡直接展開 `${{ }}`。
6. **綁定提交**：結果寫在 PR 的 head 提交上；必要檢查名稱＝此工作的顯示名稱，F3 回填進 main 保護時一併指定預期來源為 GitHub Actions（比照 mysite 現行三項必要檢查的 `app_id` 15368，§2）。守門的工作名稱在全儲存庫唯一，不得與主 CI 任何工作同名。
7. **事件政策**：GitHub 自 2026-12-02 起，對沒有適用事件政策的公開儲存庫預設阻擋 `pull_request_target`（§3.4 第 1 項）。F3 第 1 步先讀 MySiteStorage 的 Actions 事件政策與 Policy insights：
   - **主線**：在 MySiteStorage 建立一條事件政策，只允許守門這一支工作流程檔使用 `pull_request_target`，其餘照預設。這是儲存庫設定變更，由委託人親手設定，或當次明確授權本機 Claude Code 代設；設定後讀回確認。不放寬組織或帳號層的其他安全政策。
   - **備援（僅在實測證明有效後才可用）**：主 CI 用 `pull_request`，守門改用 `workflow_run` 在主 CI 完成後執行預設分支上的定義，以 API 讀 PR 檔案清單並寫回 commit status。官方寫明 `workflow_run` 工作產生的檢查不計入必要檢查，commit status 是否計入官方沒寫（§3.4 第 3 項），且 commit status 以最新一筆為準、較易被同名結果覆蓋（§3.4 第 2 項）；因此只有在測試 PR 實際證明「失敗狀態會阻擋合併、成功狀態會放行」後，才可呈報委託人改用。fork 來的 PR 在此路線拿不到 PR 編號時判失敗。
   - 兩條都走不通時，停下呈報，不得以關閉守門或放寬保護換取通過。
8. **首次啟用**：見 §6 F3 第 1～3 步。
9. **殘餘風險**：見 §4.2 末。最後一道防線仍是 mysite F6 對鎖定提交的獨立重驗（v1 §6.8 末），以及委託人合併前看到的清單。

---

## 6. 各階段定案步驟

v1 §7 原有的動作與驗收全部保留；下列為定案後的順序與增補。

### F0　開通收尾
- **已完成（本機，2026-11-06）**：MySiteStorage 公開儲存庫、`main`＋`design/work`、只允許合併提交、兩個 main 的分支保護（管理員也不得繞過）、本機資料夾 `C:\Users\whenwhosay\MyPythons\MySiteStorage`、MyDyadOne 閘道 11907 回 200。紀錄見 F0 本地施工紀錄。
- **待完成**
  1. 雲端施工者（mysite 會話）：先向委託人確認 `claude/happy-euler-evh676` 的去留（§2），不自行處理；從最新 main 開工作分支；新增 ADR-015（核准日期 2026-11-06、範圍照 v1 §1.4）；在 v1 §1.4 表列各處加修訂註記（原文不刪）；把 `MySiteReact前端專案v1.md`、`MySiteReact前端專案v2.md`、`MySiteReact前端專案定案執行.md` 三份收進 `docs/`，檔名照原檔名；開 PR，mysite CI 全綠後由委託人合併。
  2. 委託人：關閉 MyDyad 後，在 MyDyadOne 面板「MyDyad 連結」卡的橘色列關閉「全權略過」（D17）。
- **驗收**：v1 F0 驗收照舊（儲存庫與保護設定已讀回通過）；ADR-015 的 PR 已合併；本機 Claude Code 讀回 D17 為關閉並記入施工紀錄。

### F1　公開 API 契約定稿（mysite）
- v1 F1 全部照做，另加 §5 M-18 四項。
- DOM 測試掛鉤清單依 §5 M-10 增列。
- **驗收**：v1 F1 驗收照舊；畫面欄位對照表涵蓋全部頁型；有效、無效兩組樣本齊備；導航狀態矩陣涵蓋 HTML 與 `/_next/data` 兩條路。

### F2　Laravel 公開 API 實作（mysite）
- v1 F2 全部照做；第 4 步依 §5 M-06 改寫；第 5 步依 §5 M-19 增補。
- 施工者讀實作，逐端點列出對 WordPress 依序呼叫的次數，依 §4.8 算式呈報 D13c。
- 語意金絲雀改壞驗證：暫改一處 API 的狀態碼對應或欄位，確認對應測試變紅，再改回；結果記入證據。
- **驗收**：PAPI-01～07 PASS；既有 PHPUnit 356 項不退步；金絲雀紀錄齊備；PAPI-08 留到 F6。

### F3　前端儲存庫骨架（MySiteStorage）
1. **事件政策**：讀 MySiteStorage 的 Actions 事件政策與 Policy insights；依 §5.2 第 7 項主線，請委託人親手（或當次授權本機 Claude Code 代設）建立「只允許守門工作流程檔使用 `pull_request_target`」的政策，讀回確認後才寫守門工作流程。結論寫進施工紀錄。同時核對規劃書v5 與 mysite 文件中對訪客瀏覽器的支援說明，是否涵蓋 Tailwind CSS v4 的瀏覽器需求（§3.4 第 8 項）；有衝突就呈報，不改 D4（R29）。
2. **啟用 PR（施工者分支）**：骨架、主 CI、受保護路徑檢查一起放進第一個施工 PR。此時 main 還沒有必要檢查，委託人依 PR 說明與 CI 結果審視後合併。
3. **回填必要檢查並實測**：合併後，把主 CI 與守門兩個檢查名稱設為 MySiteStorage main 的必要檢查，並指定預期來源（§5.2 第 6 項）。這是儲存庫設定變更：委託人親手設定，或當次明確授權本機 Claude Code 代設；設定後讀回。再由施工者以測試 PR 跑完正反案例（FE-11 定案內容，§7）。測試 PR 一律關閉不合併；測試分支不刪，列清單交委託人處置。**FE-11 通過前，不合併任何設計 PR。**
4. **先打通一個切片**：文章列表＋文章詳情，從契約、假資料、資料層、畫面、錯誤頁、正式建置到 E2E 全部走通，再鋪開其餘頁型。切片通過不等於交付，全部頁型與驗收照 v1。
5. **Tailwind CSS v4 與設計變數**：依 §5 M-11②、M-12；第一版複刻現行 Blade 外觀（v1 F3 第 5 步）。
6. **零啟動自改寫封條**：依 §5 M-11③（FE-19）。
7. **語意金絲雀改壞驗證**：暫刪一個畫面元件的 `data-*` 掛鉤、暫改資料層一個狀態碼對應，確認 FE-12、FE-05 各自變紅後改回；結果記入施工紀錄。
8. 其餘照 v1 F3：手動建立 Pages Router 結構、版本鎖定、受保護元件（加 `SiteLink`）、AI_RULES（§5.1）與 `AGENTS.md`、施工者用 `CLAUDE.md`（含宣告區塊格式）、README、元件標記器實測。另在受保護層加「目前資料來源」提示：只在開發模式顯示「示例資料」或「staging 真資料」，正式建置不得出現（§9.4）。
- **驗收**：v1 F3 驗收照舊，另加 FE-19；FE-11 依 §7 定案內容；三種寬度截圖與現行 Blade 並排供委託人目視確認。

### F4　MyDyad 接上與第一次設計循環（委託人＋本機 Claude Code）

前置：F3 驗收通過（含 FE-11）。

1. **取回最新**：本機 Claude Code 在 `C:\Users\whenwhosay\MyPythons\MySiteStorage` 取回 `design/work` 最新提交，確認工作樹乾淨。
2. **匯入前查埠**（本機 Claude Code；委託人此時先不要按「匯入」）
   - 列出本機所有 TCP 連線中，本機埠落在 32100～42099 的項目，**不限連線狀態**，含擁有行程的編號、名稱與完整命令列（`Get-NetTCPConnection` 不加 `-State`，再以 `Get-CimInstance Win32_Process` 補命令列）。
   - 列出所有容器中，發布埠落在 32100～42099 的項目（`docker ps --format "{{.Names}} {{.Ports}}"`）。
   - 以 `netsh int ipv4 show dynamicport tcp` 確認浮動埠範圍沒有和 32100～42099 重疊（埠分配表記載現為 43500 起、4001 個）。
   - 三項全空、且範圍不重疊 → 告訴委託人「可以按了」。任一不空 → 停下，列出占用者呈報，不停止任何服務、不改任何設定。
3. **匯入**（委託人）：MyDyad → 匯入 app → 選該資料夾 → 取消勾選「複製到 dyad-apps 資料夾」→「進階選項」的安裝命令與啟動命令留空 → 匯入。匯入成功後 MyDyad 會自動選取並啟動預覽，這是正常現象。
4. **匯入後立刻讀回**（本機 Claude Code）
   - 從實際監聽讀回預覽埠與代理埠；app 編號＝預覽埠－32100。代理埠若不在 `42100＋編號`，表示它退到了備用段（52100 起，`ports.ts:52-54`），照實記錄。
   - 比對埠分配表：預覽埠若是已登記的埠（目前段內有 33661～33665、39391），那個服務日後只要開著、MyDyad 一啟動預覽就會被結束；代理埠若是已登記的埠（段內有 42600、42601、43411、44424、50501），那個服務日後可能綁不上；若落在浮動範圍（43500～47500）或系統保留段，違反埠分配表的禁區規定，代理本身也可能時好時壞。**任一撞到 → 停下呈報，等委託人定奪，不自行處理。**
   - 沒撞到 → 把兩個埠登記進埠分配表（註明由 MyDyad 依 app 編號決定、不可改）。
5. **連上 GitHub**（委託人）：照 v1 §8.1 第 5 步，分支選 `design/work`。
6. **遙測**：照 v1 F4 第 4 步。
7. **預覽代理與監聽位址**（本機 Claude Code）：確認預覽代理已啟動、預覽埠的監聽位址只有 `127.0.0.1` 或 `::1`。若代理沒有啟動：**不得**移除 `-H localhost`；先查 dev 伺服器輸出是否含 `http://localhost:埠`（MyDyad 只認這個樣式，`app_runtime_service.ts:804`）、IPv4 與 IPv6 的監聽與連線是否一致，整理成報告交施工者；需改受保護檔時，施工者走非 `design/` 分支的 PR。
8. **讀回實際的 Node 與 npm**：從預覽行程樹的命令列與執行檔路徑，記錄 MyDyad 實際用的 `node.exe` 路徑與版本、npm 版本，以及 MyDyad 設定裡的 Node 來源（N-4）。只記錄，不更換。
9. **鎖定檔與封條**：在 v1 F4 第 6 步的四個時點，`git status --porcelain` 必須完全為空（涵蓋 `package-lock.json`、`tsconfig.json`、`next-env.d.ts` 與其他任何檔）。有變動 → 停下呈報。舊文的 pnpm 備案不是自動換線授權，D14 已裁定 npm。
10. **第一次設計循環**（委託人）：做一次小設計 → 同步到 GitHub → 看前端 CI → 在 GitHub 網頁合併（v1 F4 第 7 步）。回報依 §9.1 狀態卡。
11. **收工與資源**：一律**關閉 MyDyad**，不要只切到別的 app——切走時預覽會保留 10 分鐘才由背景回收（`PreviewPanel.tsx:237-243`）。本機 Claude Code 只以「命令列含本專案資料夾」的行程樹判斷：關閉後必須全部結束、記憶體歸還；不以全機 `node.exe` 是否消失作判準。
12. **Agent 模式與全域規則檔**：照 v1 F4 第 9、11 步。
13. **Node.js 24 線修補資訊呈報**：本機 Claude Code 當日重查並列出：電腦上的版本（現為 v24.18.0）、10 月安全修補 v24.22.1、24 線最新版（2026-11-06 查為 v24.22.2）、2026-11-11 安全發布的實際結果（§3.4 第 4 項），連同第 8 步讀回的 MyDyad 實際 Node 來源，交委託人決定何時安裝（D11）。只呈報，不代裝。
- **驗收**：FE-14、FE-15、FE-16、FE-18、FE-19（本機部分）PASS；看截圖一律用 `C:\App2\FirefoxPortableESR\App\Firefox64\firefox.exe`（`寫程式工法_本機落地規範.md:40-41`，永不 Edge）。

### F5　頁面設計迭代
- v1 F5 照舊。設計方向：沿用現行深色、高對比、文字易讀的影音內容站；先以文章詳情與首頁並排確認新風格，再推展到其他頁型（不減少最後須驗收的頁面）。
- 首輪不加目錄、相關推薦、收藏、會員、分享追蹤、閱讀時間、瀏覽數等契約沒有的東西；確有需求時，先確認契約可供應，再交施工者。
- 每次回報附 §9.1 狀態卡。

### F6　併入 mysite
- v1 F6 全部照做，另加：
  1. gateway 兩份範本：主站區塊照搬 app-web 的敏感檔與 `.php` 規則（§4.6）；`geo` 斷開前端連入（§4.5）；不得對 `/_next/` 新增 `proxy_intercept_errors` 或 `error_page`；`/search` 與 `/_next/data/*/search.json` 共用同一個限流區（D13a），超限回正體中文 429 頁。
  2. 狀態矩陣：HTML 頁與 `/_next/data` 各自遇到 API 404／429／503、gateway 429、非 JSON、逾時、前端例外時的回應，逐一測試；不得把 HTML 429 頁當 JSON 解析，也不得錯映成 404。
  3. app-web 對前端網段只放行 `/api/v1/`（v1 §4.6、§5.5），PAPI-08 必驗 `/internal/` 下的路由從前端網段一律 404。
  4. `ci.yml` 矩陣擴為 `scope × render_mode` 後，**同一個 PR 內**更新 mysite main 的必要檢查名稱（F0 紀錄已提醒）。
  5. mysite 端重驗（v1 §6.8 末）另加：該前端提交與上一個鎖定提交之間，受保護路徑改了哪些檔，逐一列在升版 PR 說明。
- **驗收**：v1 F6 驗收照舊；PAPI-08、FE-20 PASS。

### F7　staging 驗收
- v1 F7 照舊，另加：
  1. 撤下演練分欄記錄：直接請求、站內換頁、滑鼠停在連結後再點、上一頁／下一頁、分頁離開後回來，以及撤下事件遺失且 WordPress 失聯的情境（§4.7）。
  2. 換版演練：訪客開著舊版頁面時發布新版本，再點下一頁與播放器，應取得新版頁面或明確錯誤，不得白屏、不得出現舊正文。
  3. 真資料媒體：只允許政策核准的來源；YouTube 點擊前零請求；不為了測試通過而封掉合法媒體或新增媒體代理。
  4. D5 真資料預覽：依 §9.4 切換，試一次後切回假資料。

### F8、F9
- 照 v1 與 D10。

---

## 7. 驗收登錄增補（不重編既有 ID）

| ID | 定案增補 |
|---|---|
| PAPI-03 | 分兩欄：①服務端與站內換頁在撤下期限內停止供應正文（判通過／失敗）；②瀏覽器返回快取、已下載內容（照實記錄，不判失敗） |
| PAPI-08 | 增驗：前端網段連入 gateway 一律被斷（444）；前端網段直連 app-web 的 `/internal/` 路由一律 404；production／staging／testing 三種環境的最終網路矩陣 |
| FE-03 | 除計數外，查 `SafeHtml` 呼叫端只傳入 API 的 `*_html` 欄位 |
| FE-04 | 增列 `tailwind.config.*`、`@tailwind` 指令、`shallow` 路由、`globals.css` 缺 `@import "tailwindcss";` |
| FE-05 | 增列並行取資料的結果對照（§4.9）與 HTML／`/_next/data` 兩條路的狀態矩陣（§6 F6 第 2 項） |
| FE-06 | 分兩個判準：假資料模式全部同源；真資料模式只到政策核准的來源，YouTube 點擊前零請求 |
| FE-09 | 增驗：`.next/` 產物（F3）與最終映像（F6）全文搜尋假資料哨兵字串，零命中 |
| FE-11 | 定案正反案例：①`design/` 分支改開放區 → 通過；②`design/` 分支改受保護檔 → 失敗且無法合併；③`design/` 分支改 `.github/` → 守門仍執行且失敗；④`design/` 分支把檔案從開放區改名移出 → 失敗；⑤施工者分支改受保護檔、沒有宣告 → 失敗；⑥宣告與實際清單一致 → 通過且出現清單留言；⑦宣告多列或少列 → 失敗；⑧只改 PR 說明 → 重新判定；⑨結果對應 PR 最新提交，舊提交的結果不算；⑩他人 fork 的 PR 改受保護檔 → 失敗；⑪事件政策讀回正確，Policy insights 沒有守門工作流程被阻擋的紀錄 |
| FE-16 | 以命令列含本專案資料夾的行程樹判斷；必須是「關閉 MyDyad」後立即歸零，不以切換 app 代替 |
| FE-17 | 增驗：①段數超過公開路由最深段數的路徑，不呼叫任何 API、回 404 並附請求編號。②站內換頁時 `/_next/data` 回 404／503，或換版後舊建置編號的資料請求失敗，路由器改走整頁載入（§3.4 第 10 項），最後畫面是伺服器畫出的正確錯誤頁或新版頁面，狀態碼正確；不得白屏、不得出現舊正文 |
| FE-18 | 增驗：匯入前整段查埠紀錄（不限狀態、含容器、浮動範圍）；匯入後預覽埠與代理埠皆比對埠分配表並登記 |
| **FE-19（新）** | 零啟動自改寫：前端 CI 在正式建置後、短暫啟動 `next dev` 後，工作樹零變動；F4 四個時點同樣零變動 |
| **FE-20（新）** | next 與 blade 兩種模式對敏感檔與 `.php` 探測的回應一致：`deny-sensitive.conf` 所列路徑與 `/index.php` 以外的 `.php` 由 gateway 主站區塊直接回 404，前端容器與 Laravel 都收不到；`/index.php` 照舊交給 app-web；CMS 網域的 WordPress 不受影響 |

既有 ID 在 next 模式重跑的清單（v1 §9.3）不變。

---

## 8. 風險增補（接 v1 §10）

| # | 風險 | 對策 | 何時確認 |
|---|---|---|---|
| R20 | 匯入的瞬間清埠，誤殺委託人的服務或 Docker 轉送程式 | 匯入前整段查（不限狀態、含容器、浮動範圍） | F4 |
| R21 | 代理埠占住委託人已登記的埠 | 匯入後讀回比對；撞到停下呈報 | F4 |
| R22 | `next dev` 自動改設定檔，被 MyDyad 自動存版推上去 | F3 定型；FE-19 | F3、F4 |
| R23 | MyDyad 的模型照 v3 習慣寫 Tailwind | AI_RULES 寫死 v4 寫法；FE-04 阻擋 | F3、F5 |
| R24 | next 模式讓掃描請求一路進到 Node 與 Laravel | gateway 主站區塊照搬 app-web 的 `deny-sensitive.conf` 與 `.php` 規則；萬用路由深度過濾；FE-20 | F6 |
| R25 | GitHub 自 2026-12-02 起預設阻擋公開儲存庫的 `pull_request_target`，守門不執行、必要檢查永遠等不到結果 | F3 第 1 步建立只允許守門工作流程的事件政策並讀回；備援須實測證明有效（§5.2 第 7 項） | F3 |
| R26 | 同儲存庫 PR 偽造同名檢查結果 | 守門工作名稱全儲存庫唯一、必要檢查指定預期來源；只有持委託人推送權的工具做得到；mysite F6 獨立重驗；委託人合併前核對清單 | F3、F6 |
| R27 | 真資料切換檔被 MyDyad 環境變數面板覆寫 | 只由本機 Claude Code 切換，不用面板設這個變數 | F7 |
| R28 | 委託人只切換 app，預覽仍在背景跑 10 分鐘 | 收工一律關閉 MyDyad；FE-16 | F4 起 |
| R29 | Tailwind CSS v4 的瀏覽器需求（Safari 16.4+、Chrome 111+、Firefox 128+）高於現站可能承諾的支援範圍 | F3 第 1 步核對規劃書v5 與 mysite 文件；有衝突呈報，不改 D4 | F3 |
| R30 | npm 在 `node_modules` 已存在時重新安裝漏寫平臺套件的問題，網友稱在 npm 11.16.0 復現（官方未確認），電腦上正是 11.16.0 | F4 第 9 步四個時點工作樹零變動；有變動停下呈報 | F4 |

---

## 9. 委託人日常操作增補（接 v1 §8）

### 9.1 每次回報的狀態卡

施工者與本機 Claude Code 每次回報，開頭固定列出五格；沒做的寫「尚未執行」，不預填通過。

```text
[ ] 1. 設計已存　　　design/work 提交：____
[ ] 2. 設計已合併　　MySiteStorage main 的 PR：____
[ ] 3. 整合已驗證　　mysite 鎖定提交的 PR 與四個矩陣組合 CI：____
[ ] 4. staging 已確認　外部 HTTPS 與影音實測：____
[ ] 5. 正式已發布　　release 序號與外部冒煙測試：____
```

「前端 PR 已合併」只到第 2 格，不代表正式站已更新。這張卡只出現在 PR 說明與回報，不放進公開網站。

### 9.2 每次設計（v1 §8.2 增補）
- 第 1 步前：若上次沒有正常關閉 MyDyad，先請本機 Claude Code 確認沒有殘留的預覽行程。
- 第 2 步「同步到 GitHub」：同步不只是下載，它會先取回、再把本機已存的版本一起推上去。按之前，若最近幾輪的修改還沒決定要不要，先在「版本歷史」用「恢復到此版本」回到想要的那一版，再同步。
- 第 7 步收工：一律關閉 MyDyad（§6 F4 第 11 步）。
- 要看 CI 截圖：本機 Claude Code 下載後，以 `C:\App2\FirefoxPortableESR\App\Firefox64\firefox.exe` 開給委託人看。

### 9.3 對 MyDyad 說話的範例（Build 模式「建置」）
- 「只改 `src/styles/` 的主題變數：整體背景再深一些、卡片表面稍亮一點，文字對比要符合無障礙標準；不要改任何功能與測試屬性。」
- 「調整 `ArticleView`：段落間距放寬一些，引文左側加一條品牌色細線與淡底色；確認手機 390 寬度下長網址與表格不會撐出整頁水平捲軸。」
- 「調整首頁與共用內容卡片：影片卡片右上角顯示片長；沒有封面的文章卡片不要留空框；保留所有 `data-*` 屬性。」

### 9.4 用 staging 真資料預覽（F7 開放 staging API 後；D5）
1. 本機 Claude Code 在 `C:\Users\whenwhosay\MyPythons\MySiteStorage\` 建立 `.env.local`，只寫資料模式與 staging 唯讀 API 網址（變數名稱依 F3 定稿；不含任何秘密）。
2. 委託人在 MyDyad 重新啟動預覽；開發模式畫面上會顯示「目前資料：staging 真資料」的提示（只在開發模式出現，F3 實作）。
3. 看完切回假資料：本機 Claude Code 把 `.env.local` 改名為 `.env.local.off`（不刪除），委託人重新啟動預覽。
4. 這個變數不要用 MyDyad 的「環境變數」面板設定：面板寫的也是同一個 `.env.local`，兩邊會互相覆蓋（`app_env_var_utils.ts:18`）。
5. `.gitignore` 已含 `.env.*`，兩個檔都不會進 Git；MyDyad 也不會把 `.env.local` 送給模型（`codebase.ts:94`）。若施工者日後要提交範本檔，須在 `.gitignore` 加 `!.env.example`，或改寫在 README。

---

## 10. 給施工者的交辦提示（統一版，取代兩份建議書的提示詞）

```text
請接續「MySite React 前端專案」。開工前完整閱讀 mysite docs/ 內的三份文件：
1. MySiteReact前端專案v1.md（完整正文）
2. MySiteReact前端專案v2.md（委託人 D1～D17 裁定）
3. MySiteReact前端專案定案執行.md（整合審查後的定案修訂；三者衝突時以它為準，其次 v2，再次 v1）
若三份尚未收進 docs/（F0 未完成），請先向委託人取得全文，不得憑檔名猜測內容。

起點：先以唯讀方式核對當下狀態——兩個儲存庫的 main 與 design/work、開著的 PR、分支保護與必要檢查、mysite docs/ 與 ADR 清單。F0 本機部分已完成（MySiteStorage 公開儲存庫、兩個 main 保護、管理員也不得繞過、只允許合併提交），不重建、不覆寫。

依定案執行書施工：
- F0：ADR-015、v1 §1.4 修訂註記、三份文件入 docs/；mysite 分支 claude/happy-euler-evh676 的去留先問委託人。
- F1～F3、F6、F7：依定案執行書 §5 修訂表與 §6 各階段步驟，連同 v1 原有動作與驗收全部完成；先打通「文章列表＋文章詳情」切片，再完成全部頁型。
- 守門依 §5.2。GitHub 自 2026-12-02 起預設阻擋公開儲存庫的 pull_request_target：F3 第 1 步先請委託人建立（或當次授權代設）只允許守門工作流程檔的事件政策，讀回後才寫守門；首次啟用依 §6 F3 第 1～3 步；FE-11 通過前不合併設計 PR。
- next-env.d.ts 列入 .gitignore；typecheck 先跑 next typegen；tsconfig.json 定型；FE-19 封條。
- AI_RULES.md 與 AGENTS.md 用 §5.1 全文。

鐵則：
- 一律在工作分支施工、推送、讀回 CI；不推 main、不合併 PR、不自動發布。
- 名稱、埠、子網、資源上限、逾時、重試等數值，依 D13 附出處與算式呈報，不自行定值。
- npm 或 MyDyad 接線失敗，不得擅換套件管理器、不改 MyDyad 本體；停下呈報。
- 沒有委託人的「刪除」二字，不刪任何檔案、分支或內容；測試 PR 關閉不合併，測試分支列清單交委託人處置。

回報：開頭附五格狀態卡（定案執行書 §9.1）；每項發現附檔案、提交或官方來源；未執行的測試明標未驗收；外部主機或登入阻擋只卡相關階段，其他已授權工作繼續完成。
```

---

## 11. 三輪迭代紀錄（每輪思考兩遍）

第一遍以委託人操作的角度，第二遍以 AI 施工、日後維運與現行施工的角度。

### 第一輪：把五份文件收成一份施工依據
- **第一遍（委託人操作）**
  - 委託人手上同時有 v1、v2、兩份建議書與 F0 紀錄，雲端施工者讀到哪份、照哪份做並不確定；Argon 提示詞還寫「衝突以 Argon 為準」→ 本書明定優先順序，兩份建議書退為存查（檔首「讀法」）。
  - F4 是委託人唯一親自動手又最容易出事的一步，兩份建議都指出查埠順序有問題 → F4 改為「本機 Claude Code 說可以按了才按匯入」。
  - 「前端 PR 合併」容易被誤以為正式站已更新 → 五格狀態卡。
- **第二遍（AI 施工）**
  - 兩份建議書互相糾正，Argon 又引了大量行號與數字，不能直接採信 → 全部查證：MyDyad 原始碼主筆親讀；mysite 與 MySiteStorage 派子代理唯讀查；官方文件派子代理查。
  - v1 內部矛盾（F2 第 4 步與 §4.6）、v1 F4 第 5 步的備案會擴大暴露 → 列入修訂表。

### 第二輪：以查證結果壓測兩份建議
- **第一遍（委託人操作）**
  - Argon 的查埠只看「監聽中」，但 `kill-port` 比對所有連線狀態、強制結束，Docker 模式還會停容器；若打中 Docker 的轉送程式，委託人所有容器的連線會一起斷 → 查埠改為不限狀態、含容器、確認浮動範圍。
  - 代理埠反向占住 42600、42601、43411 的情形兩份都沒寫，一旦發生，委託人會發現參勤交代或翻譯線莫名開不起來 → 匯入後立刻比對，撞到停下呈報。
  - 切到別的 app 時預覽還會跑 10 分鐘 → 收工一律關閉 MyDyad。
  - 真資料預覽的切換檔與 MyDyad 環境變數面板是同一個檔 → 只由本機 Claude Code 切換。
- **第二遍（AI 施工）**
  - Codex「所有 PR 一律阻擋」在單人、管理員不得繞過、核准數 0 的設定下會鎖死施工者 PR；Argon 的標記只查「有沒有」，攔不住宣告與實際不符 → 三類判定，宣告逐檔比對，`edited` 重新判定，fork 一律失敗。
  - Argon 的 D13c 算式把含連線的總時限又加上連線時限，且把不重試的逾時算成重試 → 依實作語意重寫（§4.8）。
  - `Promise.all` 一方失敗就丟另一方 → `allSettled`＋結果表。
  - Argon「逾千倍」沒有量測 → 不寫倍數，改以「與 blade 模式等價」為準。
  - gateway 與 app-web 沒有依來源放行的規則 → 反向連線不是現存漏洞，`geo` 斷線定位為縱深防禦；同時確認 `InternalOnly` 對容器直連放行，app-web「只放行 `/api/v1/`」是必要防線。

### 第三輪：挑錯定稿
- **第一遍（委託人操作）**
  - 本書不得變成又一份要逐題裁定的清單 → 整合時遇到的取捨，凡屬兩份建議範圍內的由本書定案並寫明理由；`strict` 維持現狀，以流程補足，不新增流程門檻；新增待裁定事項為零（F3 的事件政策屬帳號設定動作，列在 §0 表中）。
  - 委託人要做的事集中在 §0 一張表。
  - 用語：文件內稱「委託人」；看圖瀏覽器照正典寫完整路徑；建議書裡的新造風格名稱不採。
- **第二遍（AI 施工）**
  - 逐條回查 §3 每一列的證據位置是否與查核紀錄一致；把 v1 每個受影響章節對應到修訂編號，確認沒有「建議書寫了、本書沒交代處置」的項目（§3 處置欄＋§12）。
  - AI_RULES 改為給全文，避免施工者自行拼接 v1 草案與增補。
  - 交辦提示改為一份統一版，取代兩份建議書各自的提示詞。
  - 官方文件查核推翻了兩處原本的寫法，回頭修正：
    - GitHub 自 2026-12-02 起預設阻擋公開儲存庫的 `pull_request_target`，而 `workflow_run` 工作的檢查不計入必要檢查 → 守門主線改為建立只允許守門工作流程的事件政策；`workflow_run` 降為須實測證明有效的備援；§0 委託人事項表加 F3 一列。
    - 官方建議 `next-env.d.ts` 列入 `.gitignore` → 不列受保護清單，從 AI_RULES「不可以改」移除；`typecheck` 先跑 `next typegen`。
  - 其餘查核（預取只載程式碼、Chrome 返回快取 3 分鐘、資料請求失敗改走整頁載入、Tailwind v4 寫法與瀏覽器需求、Node 24 線版本、nginx `geo` 與 `if` 用法）補進 §3.4，並在 §4.4、§4.7、FE-17、R29、R30、F4 第 13 步落實。
  - 通讀挑錯：
    - 原寫「gateway 對 `.php` 一律回 404」→ 補讀 `app-web.conf` 原文，發現 `/index.php` 是 Laravel 前端控制器、CMS 區塊需要 `.php` → 改為只在主站區塊照搬 app-web 的三條規則（§4.6、FE-20）。
    - Codex「所有 PR 一律判」原被概括為「會鎖死」→ 補上 Codex 原意（委託人核准、綁定 PR 與提交），並說明單人儲存庫沒有現成核准機制，本書以逐檔宣告＋清單留言＋綁定 head 提交作為具體做法（C-10）。
    - 「§4.2 路徑表」與本書 §4.2 同號易混 → 一律寫明「v1 §」。
    - 自掃殘體字與半截詞：「擋不住」改「攔不住」、「再裁」改「再裁定」、「不鎖執行版本」改「不鎖定執行版本」、「硬鎖」改「硬性阻擋」。
  - 子代理查證時違規寫下一個暫存檔，依規矩不由 AI 刪除，交委託人處置（見交付回報）。

---

## 12. 不採納與保留事項

| 來源 | 內容 | 不採或保留的理由 |
|---|---|---|
| Codex §4.1 | 匯入必定自動啟動時交 MyDyad 案修正 | 匯入前查整段即可安全進行，不必改 MyDyad |
| Codex §4.6 | 受保護路徑判定一律不認分支 | 會鎖死施工者合法 PR（§4.2） |
| Codex §3.4 | 零結果文案「說明目前沒有符合內容」 | 與既有 E2E 斷言不符（§4.3） |
| Codex §4.4 | 公開內容優先改一般 `<a>` | 預取不抓資料，撤下保證不受影響；保留 `/_next/data` 驗收（§4.4） |
| Argon §0、§4.5 | 「逾千倍」放大 | 沒有量測依據 |
| Argon §4.1 | 只查監聽中的埠 | 清埠不分連線狀態 |
| Argon §4.2 | `pull_request_target` 被阻擋時改用 `workflow_run`＋commit status | `workflow_run` 工作的檢查不計入必要檢查，commit status 是否計入官方沒寫；降為須實測證明的備援（§5.2 第 7 項） |
| Argon §4.3 | `next-env.d.ts` 列受保護並提交 | 官方建議列入 `.gitignore`（§4.10） |
| Argon §4.5 | 萬用路由以副檔名猜測非頁面路徑 | slug 規則允許句點 |
| Argon §4.9 | `Promise.all` | 改 `allSettled` |
| Argon §4.9 | D13c 算式 | 不合實作語意（§4.8） |
| Argon §3 | 「墨韻深色內容站」名稱 | 新造風格名稱；只採變數分層 |
| Argon §7 | 「兩份建議衝突處以 Argon 為準」 | 由本書取代 |
| 兩份共同 | 待施工者實測才能定的數值 | 依 D13 呈報，不在本書定值 |

---

## 13. 查證來源（2026-11-06）

**本機**
- MyDyad 原始碼：`C:\Users\whenwhosay\MyPythons\MyDyad\src\components\ImportAppDialog.tsx`（第 61、309、437–447 行）、`src\components\preview_panel\PreviewPanel.tsx`（第 205–243 行）、`src\ipc\services\app_runtime_service.ts`（第 8、804、1397–1451、1520–1530、1615 行）、`shared\ports.ts`、`node_modules\kill-port\index.js`、`src\utils\codebase.ts`（第 94 行）、`src\ipc\utils\app_env_var_utils.ts`（第 18 行）、`src\lib\schemas.ts`（第 589–591 行）
- `C:\Users\whenwhosay\Documents\MyVault\00_系統規格\本機埠分配表.md`
- `C:\Users\whenwhosay\Documents\MyVault\08_工法手冊\寫程式工法_本機落地規範.md`（第 40–41 行）

**mysite（GitHub `Whenwhoengine/mysite`，main `98bd557`）**
- `tests/e2e/specs/media.spec.mjs`、`infra.spec.mjs`、`seo.spec.mjs`；`tests/e2e/helpers/env.mjs`
- `infra/nginx/app-web.conf`、`common/deny-sensitive.conf`、`common/gateway-proxy.conf`、`gateway/http/gateway.conf.template`、`gateway/https/gateway.conf.template`、`wp-web.conf.template`
- `infra/compose.base.yml`；`.github/workflows/ci.yml`
- `docs/設定目錄.md`、`docs/架構決策.md`、`docs/runbooks/主機開通.md`、`docs/規劃書v5.md`、`docs/交付報告v2.md`
- `app/config/mysite.php`；`app/app/Domain/Content/Http/WordPressRestClient.php`；`app/app/Http/Middleware/InternalOnly.php`；`app/routes/content.php`；`app/resources/views/content/search.blade.php`；`app/resources/views/components/pagination.blade.php`

**官方文件與上游**（網址逐列見 §3.4）
- GitHub：`securely-using-pull_request_target`、2026-11-02 更新日誌、`control-workflow-execution`、`about-protected-branches`、`troubleshooting-required-status-checks`、REST `commits/statuses`、`workflow-syntax#permissions`、`events-that-trigger-workflows`；GitHub Security Lab〈Preventing pwn requests〉
- Node.js：2026 年 10 月與 11 月安全發布公告、`dist/index.json`
- Next.js：`next/link`（Pages Router）、CLI、TypeScript 設定、Pages Router CSS、Next 16 發布文；原始碼 `router.ts`（v16.2.0）、`writeConfigurationDefaults.ts`（canary）
- Tailwind CSS：Next.js 安裝指南、升級指南、`functions-and-directives`
- Chrome：`bfcache-ccns`
- nginx：wiki〈If is Evil〉、`ngx_http_rewrite_module`、`ngx_http_geo_module`
- 上游議題：dyad-sh/dyad #2265、npm/cli #4828

---

## 14. 相關檔案

- 本書：[MySiteReact前端專案定案執行.md](MySiteReact前端專案定案執行.md)
  `C:\Users\whenwhosay\.claude\plans\001整體程式建構計畫\MySiteReact前端專案定案執行.md`
- v1：[MySiteReact前端專案v1.md](MySiteReact前端專案v1.md)
  `C:\Users\whenwhosay\.claude\plans\001整體程式建構計畫\MySiteReact前端專案v1.md`
- v2：[MySiteReact前端專案v2.md](MySiteReact前端專案v2.md)
  `C:\Users\whenwhosay\.claude\plans\001整體程式建構計畫\MySiteReact前端專案v2.md`
- F0 紀錄：[MySiteReact前端專案_F0本地施工紀錄.md](MySiteReact前端專案_F0本地施工紀錄.md)
  `C:\Users\whenwhosay\.claude\plans\001整體程式建構計畫\MySiteReact前端專案_F0本地施工紀錄.md`
- Codex 建議（存查）：[MySiteReact前端專案v2_codex建議v1.md](MySiteReact前端專案v2_codex建議v1.md)
  `C:\Users\whenwhosay\.claude\plans\001整體程式建構計畫\MySiteReact前端專案v2_codex建議v1.md`
- Argon 建議（存查）：[MySiteReact前端專案v2_Argon建議v2.md](MySiteReact前端專案v2_Argon建議v2.md)
  `C:\Users\whenwhosay\.claude\plans\001整體程式建構計畫\MySiteReact前端專案v2_Argon建議v2.md`
- 前端工作資料夾：`C:\Users\whenwhosay\MyPythons\MySiteStorage`
