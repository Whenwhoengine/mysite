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

## 工程限制（非外部阻擋，已有替代路線）

| ID | 情況 | 處置 |
|---|---|---|
| ENV-DOCKERHUB-429 | Claude VM 匿名拉取 Docker Hub 間歇 429 | 改用 digest 相同的 `public.ecr.aws/docker/library`（ADR-004） |
| ENV-GITHUB-DIST | Claude VM 代理擋 `api.github.com` zipball | Composer 改 git source；CI 用 dist（ADR-005） |
| ENV-PECL | pecl 經代理回 426 | pcov 改以 curl 下載並 SHA-256 鎖定後 phpize 編譯 |
| ENV-BAKE | Compose v5 bake 需 `--allow` 權限 | 映像改由 `scripts/lib/build.sh` 以 docker build 建置 |
