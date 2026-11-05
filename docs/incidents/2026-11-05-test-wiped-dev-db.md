# 事故：PHPUnit 清空開發資料庫 mysite_app（2026-11-05）

- **等級**：SEV4（只影響雲端 VM 開發環境的合成資料；無正式資料、無個資、無外洩）
- **發現**：商店工作包於 00:51 UTC 發現開發庫 `mysite_app` 全部資料表被清空，並回報整合者。
- **影響**：開發庫的 sessions、cache、jobs 與示例商品資料遺失；WordPress 資料庫 `mysite_wp` 不受影響。資料皆為合成資料，可由 migrations 與 seed 重建。

## 原因
1. tools 容器的 Compose 環境變數（`DB_DATABASE=mysite_app`、`CACHE_STORE=database` 等）位於 `$_SERVER`。
2. `phpunit.xml` 的 `<env force="true">` 只改 `$_ENV` 與 `putenv`；Laravel 的 `env()` 先讀 `$_SERVER`，因此框架啟動時仍指向開發庫。
3. Service provider 在 boot 階段（RateLimiter 解析 cache store）即建立 MySQL 連線；`tests/TestCase.php` 在 `parent::createApplication()` **之後**才改資料庫名稱，為時已晚。
4. `RefreshDatabase` 的 `migrate:fresh` 因而對開發庫執行 `db:wipe`。

## 處置
- 通知所有工作包暫停執行 PHPUnit。
- `app/tests/TestCase.php`：在框架啟動**之前**同時覆寫 `$_SERVER`、`$_ENV`、`putenv` 的 `DB_DATABASE`、`APP_ENV`、`CACHE_STORE`、`SESSION_DRIVER`、`QUEUE_CONNECTION`、`MAIL_MAILER`；啟動後以 `SELECT DATABASE()` 確認實際連線為測試庫，否則丟例外中止。
- 第二道防護：`scripts/cloud/dev.sh test` 與 `scripts/ci/verify.sh` 以 `-e DB_DATABASE=<測試庫>` 等參數啟動 tools 容器。
- 以 `php artisan migrate --force` 重建開發庫結構。

## 驗證
在開發庫建立金絲雀資料表與資料後，刻意**不帶**容器層防護參數執行含 `RefreshDatabase` 的 `HealthTest`（5 項通過），事後金絲雀資料仍在；確認單靠 TestCase 即能阻擋，之後移除金絲雀。

## 防再發
- 測試庫名稱白名單（`mysite_app_test[_後綴]`）＋實際連線斷言，任何一層失效都會中止而不是清錯庫。
- 正式與 staging 環境不存在 tools 容器與 PHPUnit；CI 只在一次性 runner 的測試專案執行。
