# mysite：全雲端施工規約

## 專案
- 目標儲存庫：`Whenwhoengine/mysite`；先讀 `git remote -v`，不是就停止寫入。
- 委託人的電腦只使用瀏覽器；不得依賴 Windows、WSL、Docker Desktop 或本機 CLI。
- WordPress（`wp/`）管內容；Laravel 13（`app/`，PHP 類別在 `app/app/`）管公開前臺與模擬商店；只以 REST＋簽章事件整合；MySQL 8.4 分庫分帳號。
- 正體中文介面；Blade＋原生 CSS／JS；不引入 WooCommerce、SPA、SSO、Redis、真實金流。

## 每輪開始必讀（依序）
1. `docs/規劃書v5.md`（新會話完整讀）與 `docs/執行契約.md`（施工細則）。
2. `docs/state/status.json`、`docs/state/tasks.json`、`docs/state/blockers.md`、`docs/state/next-step.md`。
3. `docs/versions.lock.json`、`infra/versions.env`、`docs/API契約.md`、最近 CI 證據與 `git status`。

## 環境與命令（全部在雲端 Linux）
- `bash scripts/cloud/preflight.sh`：預檢（Docker daemon 未啟動時可 `dockerd &` 後重測；仍失敗走 Actions）。
- `bash scripts/cloud/session-start.sh`：讀狀態、恢復開發服務，不重設資料。
- `bash scripts/cloud/bootstrap.sh --env=development`：首次建置／冪等補缺；`--env=testing` 為乾淨測試環境。
- `bash scripts/cloud/up.sh --env=development`、`bash scripts/cloud/doctor.sh --env=development`。
- `bash scripts/ci/verify.sh --scope=content|simulation`：測試入口（CI 共用）。
- `bash scripts/cloud/composer.sh <args>`：以 PHP 8.5.12 容器跑 Composer（工作目錄 `app/`）。
- artisan：`docker compose ... exec -u www-data app-php php artisan ...`（見 `scripts/lib/compose.sh` 的 `ms_compose`）。
- 開發入口：主站 `http://mysite.localhost:8080`、CMS `http://cms.mysite.localhost:8081`（只綁 VM loopback，不是公開網址）。
- Docker Hub 匿名拉取會 429：開發環境自動改用 digest 相同的 `public.ecr.aws/docker/library`（ADR-004）。

## 程式與安全
- 版本、digest、Action SHA 先查官方再寫；來源記入 `docs/版本查核.md`。
- 秘密只放 `.runtime/secrets/<env>/`（Git 忽略）並以 `*_FILE` 讀取；不得輸出到 log、證據或對話。
- HTML 只經 `App\Support\Html\SafeContent` 與 `<x-rich-content>` 輸出；其他 `{!! !!}` 由 `scripts/ci/check-blade-raw.sh` 阻擋。
- 預覽票券、事件 HMAC、可見性租約（30 秒／硬上限 60 秒）、冪等與交易依 `docs/API契約.md`。
- `COMMERCE_MODE` 預設 disabled；`real` 一律拒絕；不收卡號、真實地址、電話。
- 禁止：force push、`git clean -fdx`、全域 prune、正式環境 `down -v`／`migrate:fresh`、在 `up`／`session-start` 藏 reset。

## 流程
- M0→M1→M2→M3→M4→M8-A 為內容站主線；主機缺件不阻擋 M5→M8-B。
- 共用檔（migrations、`infra/versions.env`、compose、workflows、`docs/state/*`）只由整合者修改。
- 每切片：實作→測試→蒐證→更新 state→檢查秘密→提交→推送→讀回 CI。
- 只對實際執行過的測試寫 PASS；未跑 `NOT_RUN`、外部缺件 `BLOCKED_EXTERNAL`。

## 授權
- 既定架構不再詢問；費用、正式部署、改可見性、刪資料、推 main、合併 PR 須明確核准。
- 上傳發布清單不等於部署成功；未驗證正式站不宣稱上線。

## 結束／中斷前
- 執行 `bash scripts/cloud/checkpoint.sh --reason=<原因>`，更新 `docs/state/*`，推送工作分支並讀回。
- 對委託人回報最多三行：實際完成、阻擋、唯一必要操作。
