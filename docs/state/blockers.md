# 外部阻擋清單

每項含：阻擋 ID、缺什麼、影響、證據、替代路徑、仍可進行的工作、解除方式。這些項目**只阻擋真實環境驗收（M9／M10）**，不阻擋程式、測試與部署工具開發。

| ID | 缺什麼 | 影響範圍 | 證據 | 仍可進行 | 解除方式 |
|---|---|---|---|---|---|
| BLK-HOST-01 | 常駐 Linux 雲端主機（staging／production） | M9-A／B、M10-A／B；OPS-02、OPS-08、OPS-09、OPS-10、OPS-13 真實驗收 | 委託人尚未提供主機或核准費用（v5 §13.2、§17.1） | 全部程式、容器、CI、部署／備份工具與合成環境測試 | 委託人提供既有主機，或核准指定供應商與月費上限 |
| BLK-DNS-01 | 核准網域與子網域（主站、CMS） | 正式 HTTPS、Let’s Encrypt、HSTS（OPS-02、OPS-13） | 未提供網域 | gateway https 模板、ACME bootstrap 設定與續期演練腳本 | 委託人提供網域與 DNS 管理權限 |
| BLK-BACKUP-01 | 異地備份目的地與憑證 | 正式備份與還原驗收（OPS-07 真實部分、DAT-06 正式部分） | 未提供 | restic 備份／隔離還原以本機合成 repository 演練 | 核准儲存服務與保留期限 |
| BLK-ALERT-01 | 核准告警通道（郵件或其他） | OPS-10 告警送達 | 未提供，且正式郵件服務未核准 | 告警事件產生、捕捉與測試介面 | 核准通道並提供受控憑證 |
| BLK-PRODSECRET-01 | 正式環境秘密與發布簽章金鑰（GitHub environment） | 正式部署簽章、OPS-01 正式隔離驗證 | 不得進 Claude 開發會話 | 以合成金鑰測試簽章與驗簽流程 | 委託人在 GitHub environments 介面建立 staging／production 秘密 |
| BLK-APPROVAL-01 | 正式發布核准、管理員 2FA 實際登記 | M10、WP-10 正式部分 | 尚未上線 | 2FA 外掛與流程、測試帳號驗證 | 上線時由委託人核准並親自登記第二因素 |

## MySite React 前端專案（委託人動作或待提供文件，不是主機阻擋）

下列項目只卡 React 前端專案的特定步驟，不阻擋 mysite 主站的程式、測試與部署工具；與上表的主機類外部阻擋分開列。

| ID | 缺什麼 | 影響範圍 | 證據 | 仍可進行 | 解除方式 |
|---|---|---|---|---|---|
| BLK-REACT-DOCS-01 | MySite React 前端專案的 `MySiteReact前端專案v1.md`（完整正文）與 `MySiteReact前端專案v2.md`（D1～D17 裁定）全文 | React 前端專案 F0 其餘項目（v1 §1.4 範圍表與修訂註記、兩份入 docs/）、F1（公開 API 契約）、F2（Laravel 公開 API）、F3（MySiteStorage 前端骨架）、F6（併入 mysite） | 2026-11-06 只收到定案執行書；repo、對話、GitHub 皆無 v1、v2（已搜尋）；定案執行書 §10 明定不得憑檔名猜測內容 | 定案執行書本身可獨立落實的項目：gateway 敏感檔與 `.php` 規則（§4.6）、ADR-015、D13c 呼叫次數試算（§4.8） | 委託人上傳 v1、v2 全文。**已於 2026-11-06 解除**：委託人上傳兩份全文，逐位元組收進 `docs/MySiteReact前端專案v1.md`、`docs/MySiteReact前端專案v2.md`（雜湊見 `docs/sources/來源紀錄.md`）；ADR-015 範圍表與 v1 §1.4 各處修訂註記已補 |
| BLK-REACT-POLICY-01 | MySiteStorage 的 Actions 事件政策：只允許守門工作流程檔使用 `pull_request_target` | React 前端專案 FE-11：守門工作流程（MySiteStorage PR #1 已寫入）要有這條政策才會觸發；2026-11-09、11-16 16:00–18:00 UTC 試擋，12-02 起正式阻擋 | 儲存庫設定變更，定案執行書 §0、§5.2 第 7 項規定由委託人親手設定或當次授權本機 Claude Code 代設 | F3 其他準備 | 委託人在 GitHub 網頁設定，或授權本機 Claude Code 代設 |

## 工程限制（非外部阻擋，已有替代路線）

| ID | 情況 | 處置 |
|---|---|---|
| ENV-DOCKERHUB-429 | Claude VM 匿名拉取 Docker Hub 間歇 429 | 改用 digest 相同的 `public.ecr.aws/docker/library`（ADR-004） |
| ENV-GITHUB-DIST | Claude VM 代理擋 `api.github.com` zipball | Composer 改 git source；CI 用 dist（ADR-005） |
| ENV-PECL | pecl 經代理回 426 | pcov 改以 curl 下載並 SHA-256 鎖定後 phpize 編譯 |
| ENV-BAKE | Compose v5 bake 需 `--allow` 權限 | 映像改由 `scripts/lib/build.sh` 以 docker build 建置 |

## 已知功能缺口（非外部阻擋，列為後續工作）

| ID | 缺口 | 影響 | 目前替代做法 |
|---|---|---|---|
| GAP-KEYRING-01 | 事件 HMAC 與預覽簽章金鑰尚未支援多金鑰（金鑰圈）過渡 | `scripts/ops/rotate-secrets.sh --kind=event-hmac|preview` 目前以 exit 20 拒絕自動輪替 | 依 `docs/runbooks/秘密輪替.md` 同時切換兩端並重播死信事件；預覽票券最長 15 分鐘，切換後請編輯重新按預覽。**已於 2026-11-05 解決**：Laravel 與 WordPress 都讀金鑰圈檔（`content_event_hmac_keyring`、`preview_signing_keyring`，格式錯誤拒絕啟動；沒有金鑰圈時沿用單一金鑰視為 k1 active）；事件以 active key 簽章並在 `X-Mysite-Key-Id` 帶 key ID、預覽票券 header 加 `kid`，接收端依 key ID 接受 active／accept key，retire 後舊 key 被拒（契約 §7.1）；Laravel 支援 `APP_PREVIOUS_KEYS_FILE`；bootstrap／bootstrap-host 冪等建立金鑰圈與 `app_previous_keys`，Compose 只掛給 wp-php、wp-jobs、app-*；`KEYRING_SUPPORT`、`APP_PREVIOUS_KEYS_SUPPORT` 預設改為 yes，輪替每期確認兩端實際載入的金鑰圈。promote → retire 期限暫定 15 分鐘～24 小時。驗證方式：PHPUnit（`KeyRingTest`、`SecretFilesKeyRingTest`、`EventKeyRingVerificationTest`、`PreviewKeyRingTest`、`MysiteConfigKeyRingTest`、`ContentEventKeyRingTest`、`PreviewKeyRingExchangeTest`、`AppPreviousKeysTest`）、WP `wp/tests/selftest.php` 與契約測試 `keyring_loaded_matches_secret_files`、部署工具測試 O05／H01／H02／S04，以及 testing 環境以 `rotate-secrets.sh --env=testing --allow-testing` 實跑 add → promote → retire 並確認每期 WP 內容事件 Laravel 都收得到 |
| GAP-CMS-MAINT-01 | CMS（WordPress）沒有維護模式開關 | contract／breaking 遷移時無法單獨關閉 CMS 前臺 | `release.sh` 對 contract／breaking 變更停住等待核准，現行版本照常服務。**已於 2026-11-05 解決**：site-content 外掛新增維護模式（`includes/maintenance.php`，WP-CLI `wp site-content maintenance on|off|status`；狀態存 WP option，wp-php 與 wp-jobs 一致，只記開始／結束時間與原因）。開啟時 wp-admin（含 admin-ajax／admin-post／async-upload）與 REST 寫入方法（核心 `/wp/v2/*`、`/batch/v1`、`site-content/v1`，含 `_method` 改寫）一律 503＋`Retry-After`，管理員也一樣；公開 GET、health、主站照常；登入頁可開、登入後只見維護說明；wp-jobs 的到期排程、outbox 投遞與對帳暫停（exit 0，心跳照常），直接請求 `wp-cron.php` 也不執行排程；WP-CLI 不受限。`release.sh` 在 contract／breaking 時緊接 `artisan down` 開啟、新版全部就緒後關閉，失敗與 hold 路徑依既有規則保持或解除（新增 exit 50 `WP_MAINTENANCE_FAILED`／`WP_MAINTENANCE_OFF_FAILED`）。操作見 `docs/runbooks/部署與回復.md` 第 5 節、編輯者說明見 `docs/內容編輯手冊.md` 第 9 節。驗證方式：部署工具測試 R11（none／expand 不開、contract／breaking 開關順序）、R12（開啟失敗、migration 前失敗、contract migration 失敗、退出失敗、smoke hold、恢復後再部署）；WP 契約測試 `cms_maintenance_blocks_writes_keeps_public_reads`、`cms_maintenance_pauses_jobs_and_http_cron`、`cms_maintenance_cli_state_and_validation`（development 實跑） |
| GAP-EXT-PLAY-01 | YouTube 與音訊的外部實際播放 | Claude VM 無法連外部影音服務 | 嵌入契約（iframe src、Range／206、MIME）已驗；實際播放於 staging 以瀏覽器確認 |
