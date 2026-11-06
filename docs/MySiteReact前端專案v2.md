# MySite React 前端專案 執行步驟書 v2（裁定版）

```
狀態: 現行本
版本: v2
日期: 2026-11-06
承接: MySiteReact前端專案v1.md（同資料夾；三輪迭代定稿的完整正文）
本檔: C:\Users\whenwhosay\.claude\plans\001整體程式建構計畫\MySiteReact前端專案v2.md
本版內容: 記錄委託人對 v1 §2 待裁事項 D1～D17 的裁定，並列出裁定後 v1 正文的對應更動。
讀法: 施工依據＝v1 全文＋本版。凡 v1 寫「待裁」「由委託人命名」「代稱」之處，一律以本版為準；本版沒提到的部分，照 v1 原文執行。
```

> ⛔後註（2026-11-06）：已有定案修訂 `C:\Users\whenwhosay\.claude\plans\001整體程式建構計畫\MySiteReact前端專案定案執行.md`。施工依據＝v1 全文＋本版＋定案執行；三者衝突時，以定案執行為準，其次本版。D1～D17 裁定不變。

---

## 1. 裁定結果（2026-11-06，委託人經詢問單裁定）

裁定方式：委託人裁示「D2、D16 以外的 15 項全部照建議」；D2、D16 由委託人親自命名。

| 編號 | 裁定 |
|---|---|
| D1 | **核准**架構變更。施工者以 ADR-015 記錄核准日期（2026-11-06）與範圍，並在 v1 §1.4 表列各處加修訂註記（原文不刪） |
| D2 | 前端儲存庫名稱：**`MySiteStorage`**（GitHub：`Whenwhoengine/MySiteStorage`） |
| D3 | **公開**儲存庫 |
| D4 | **Tailwind CSS v4**；不裝 shadcn/ui |
| D5 | **staging 開放**唯讀 API（只放行 gateway 固定位址、gateway 每 IP 限流）；**production 不開放** |
| D6 | 草稿預覽 v1 **維持 Blade** 樣式 |
| D7 | 商店 v1 **整個留在 Laravel** |
| D8 | 長期設計分支 **`design/work`**；main 分支保護含「管理員也不得繞過」；只允許「Create a merge commit」；關閉合併後自動刪分支；v1 不用任何自動化權杖 |
| D9 | MyDyad **不得新增套件**，需要時交施工者在 Linux 環境加入 |
| D10 | Blade 公開頁**保留到委託人下「刪除」命令為止**，不設天數 |
| D11 | 委託人電腦**維持 Node.js 24 線**（現裝 v24.18.0；2026-11-11 修補版發布後由委託人決定何時更新，見 v1 R16） |
| D12 | mysite main **一併設定分支保護**（比照 D8：需 PR＋CI、禁強推、管理員也不得繞過） |
| D13 | 數值類 a～f **由施工者附算式逐項呈報，屆時再裁**；本次不定任何數值 |
| D14 | 套件管理器 **npm**（`packageManager: "npm@…"`） |
| D15 | MyDyad 日常以 **Build 模式「建置」**為主；Agent 模式「代理」待 F4 實測後再定 |
| D16 | 電腦上的工作資料夾與儲存庫同名：**`C:\Users\whenwhosay\MyPythons\MySiteStorage`**（2026-11-06 以 Everything 查核無同名檔案或資料夾） |
| D17 | 做此案時**關閉** MyDyad「全權略過權限確認」（MyDyadOne 面板的全域開關；MyDyad 開著時不能切換，出處：`C:\Users\whenwhosay\MyPythons\MyDyadOne\MyDyadOne報告書v6.md` §三） |

---

## 2. 裁定後 v1 正文的對應更動

| v1 位置 | 原文 | 依裁定改為 |
|---|---|---|
| 全文「前端儲存庫」 | 代稱 | 指 `Whenwhoengine/MySiteStorage` |
| §2 D13 表 | 待裁定的值 | 仍待施工者呈報（D13 裁定方式已定，數值未定） |
| §6.7 AI_RULES.md 草案「技術」一行 | Tailwind CSS（依 D4） | 照原文，不改 |
| §7 F0 第 2 步 | 「名稱 D2、可見性 D3」 | 建立公開儲存庫 `MySiteStorage` |
| §7 F0 第 3 步 | 分支保護與合併設定 | 對 `MySiteStorage` 與 mysite 兩個 main 都照設 |
| §7 F0 驗收 | `gh api repos/<擁有者>/<前端儲存庫>/…` | `gh api repos/Whenwhoengine/MySiteStorage/branches/main/protection` 與 `gh api repos/Whenwhoengine/MySiteStorage` |
| §7 F0 第 4 步 | 「本步驟書收進 mysite `docs/`」 | v1 與本版兩份都收進 mysite `docs/`，檔名照原檔名 |
| §7 F0 第 5 步 | 記錄全權略過開關狀態 | 並請委託人在 MyDyad 關閉狀態下，於 MyDyadOne 面板關閉「全權略過」 |
| §7 F4 第 1 步 | 「clone 到 D16 指定的資料夾」 | clone 到 `C:\Users\whenwhosay\MyPythons\MySiteStorage`，切到 `design/work` |
| §8.1 第 1、3 步 | D16 的資料夾 | `C:\Users\whenwhosay\MyPythons\MySiteStorage` |
| §8.1 第 5 步 | 選前端儲存庫 | 選 `MySiteStorage`，分支 `design/work` |
| §8.5 第 2 步的固定請求句 | 「依 `docs/MySiteReact前端專案v1.md` §8.5」 | 「依 `docs/MySiteReact前端專案v1.md` 與 `docs/MySiteReact前端專案v2.md` 的 §8.5」 |

---

## 3. 接下來（F0 開通）

施工順序與 v1 §7 相同。F0 現在可以開始，需要的動作：

| 步驟 | 誰做 | 內容 |
|---|---|---|
| 1 | 委託人，或明確授權本機 Claude Code 代辦 | 建立公開儲存庫 `Whenwhoengine/MySiteStorage` |
| 2 | 同上 | 照 v1 §7 F0 第 3 步設定 `MySiteStorage` 與 mysite 的 main 分支保護、合併設定 |
| 3 | 雲端施工者（mysite 會話） | 新增 ADR-015、加修訂註記、把 v1 與本版收進 mysite `docs/` |
| 4 | 委託人 | 關閉 MyDyad 後，在 MyDyadOne 面板關閉「全權略過」 |
| 5 | 本機 Claude Code | 確認 MyDyadOne 閘道 11907、現役 MyDyad、模型線登入狀態並記錄 |

提醒：
- 本機 Claude Code 代辦第 1、2 步，會改動 GitHub 帳號的設定，須委託人另行明確授權（例如有效決行）。
- 建立儲存庫要在 GitHub 網頁或以 gh 建，不要用 MyDyad 建：它一律建成私人儲存庫（出處：v1 §2 D3）。

---

## 4. 本版沒有改的事

- v1 的架構、API 契約草案、前端設計、CI、驗收登錄、風險與回復方案全部不變。
- 仍待裁：D13 a～f 的確切數值（施工者在 F2、F3、F6 依約束附算式呈報）。
