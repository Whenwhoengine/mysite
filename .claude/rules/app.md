# app/（Laravel 13）規則
- `app/` 是 Laravel 專案根；artisan 與 composer 一律在容器內以 `app/` 為工作目錄執行。
- 整合層固定分層：Controller → ContentQueryService → ContentRepository → WordPressRestClient／DTO／Sanitizer／快取與可見性租約。Controller 不拼 URL、不持有密碼。
- 富文字只經 `SafeContent`＋`<x-rich-content>`；其他 `{!! !!}` 禁止。
- 測試使用 MySQL 測試庫 `mysite_app_test`；不得改用 SQLite 冒充整合或併發證據。
- 金額只用 `App\Domain\Commerce\Money`（整數最小單位）；禁止 float。
