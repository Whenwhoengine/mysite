# mysite
我的網頁

個人影音內容網站：**WordPress** 負責編輯文章、影片、Podcast 與頁面；**Laravel 13** 負責公開網站與（模擬）商店；**MySQL 8.4** 分庫分帳號；全部以 Docker Compose 在雲端 Linux 建置、測試與部署。委託人只需瀏覽器，不需要在本機安裝任何開發工具。

> 狀態：開發中（`IMPLEMENTATION_IN_PROGRESS`）。**尚未部署到任何公開網址**；開發環境只在 Claude 雲端 VM 的 loopback 執行。真實進度見 `docs/state/status.json`。

## 目錄
| 路徑 | 內容 |
|---|---|
| `app/` | Laravel 專案根（公開前臺、內容整合、模擬商店、管理後臺） |
| `wp/` | WordPress 自製外掛 `site-content`、mu-plugins、ACF 欄位 JSON、wp-config 模板 |
| `infra/` | Dockerfile、Compose（base／dev／ci／prod）、Nginx、PHP 安全設定、MySQL 初始化、systemd |
| `scripts/` | 雲端啟動、CI、部署、備份還原腳本（皆有 `--help`） |
| `tests/` | 驗收登錄表、契約、E2E、安全測試 |
| `docs/` | 規劃書 v5、執行契約、ADR、API 契約、設定目錄、手冊、狀態與證據 |

## 在雲端建置與執行（Claude Code 雲端會話或任何 Linux＋Docker 主機）
```bash
bash scripts/cloud/preflight.sh
bash scripts/cloud/bootstrap.sh --env=development
bash scripts/cloud/doctor.sh --env=development
```
- 主站：`http://mysite.localhost:8080`；CMS：`http://cms.mysite.localhost:8081/wp-admin/`（只綁 VM 本機）。
- 帳號密碼由 bootstrap 隨機產生於 `.runtime/secrets/development/`（Git 忽略），不會印在 log 或 README。
- 重跑 bootstrap 不會重設資料、管理員或金鑰；`scripts/cloud/session-start.sh` 只恢復服務。

## 測試
```bash
bash scripts/ci/verify.sh --scope=content
bash scripts/ci/verify.sh --scope=simulation
```
GitHub Actions（`.github/workflows/ci.yml`）在乾淨 runner 以相同腳本重建與測試。

## 交接文件
- 新會話／維護者先讀 `CLAUDE.md`，再讀 `docs/state/next-step.md`。
- 內容編輯：`docs/內容編輯手冊.md`；故障與維運：`docs/runbooks/`。
- 尚待外部提供的資源（主機、網域、備份、告警、正式秘密）：`docs/state/blockers.md`。
