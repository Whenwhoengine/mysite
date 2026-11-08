# 本專案保存的第三方檔案

這裡放「上游網址的內容會變」的第三方檔案。建置一律使用這裡已查核的副本，不在建置時下載，避免上游一更新就讓建置失敗或用到未查核的內容。

| 檔案 | 來源 | SHA-256 | 取得與查核 |
|---|---|---|---|
| `wordpress/zh_TW-7.1.4.zip` | WordPress.org 翻譯 API 對 WordPress 7.1.4 回傳的 zh_TW 套件（`https://downloads.wordpress.org/translation/core/7.1.4/zh_TW.zip`） | `a5fe391e41ec1e9f0681b7d4be8aeeaeaaa5c0cc142ce90b481c9bafb3e28ce2` | 2026-11-07 取得（API `updated` 2026-11-07 07:09:43 UTC），查核紀錄見 `docs/版本查核.md`「語系包重新固定」 |

## 為什麼要自己保存

WordPress.org 的語系包網址不是不可變的：譯者更新翻譯後，同一個網址會重新產生檔案，SHA-256 跟著改變。翻譯 API 顯示，光是 2026-11-07 一天，7.0.8 套件在 08:35 UTC、7.1.4 套件在 07:09 與 16:47 UTC 都重新產生過；CI 因此在 2026-11-07（main，run 42359119404）與 2026-11-08（PR #8，run 42473048258）兩度以「SHA-256 不符」中止。

## 誰在用

- `scripts/cloud/fetch-deps.sh`：以 `use_vendored` 從這裡複製到 `.runtime/dist/zh_TW.zip`，先核對 `infra/versions.env` 的 `WP_ZH_TW_LANGPACK_SHA256`，不符就拒絕。
- `infra/images/php/Dockerfile`：建置映像時再核對一次同一個 SHA-256。

## 要更新翻譯時

1. 查翻譯 API：`https://api.wordpress.org/translations/core/1.0/?version=<WordPress 版本>`，取 `zh_TW` 的 `package` 與 `updated`。
2. 下載兩次，確認內容相同，計算 SHA-256。
3. 查核：解開後，以專案的 PHP 映像（無網路、唯讀）對每個 `.l10n.php` 做語法符號檢查，只能有 `return`、字串、`=>`、`[`、`]`、`,`、`;`。這些檔案會被 WordPress 執行，不能含程式碼。
4. 用新檔取代這裡的檔案（檔名帶版本）；更新 `infra/versions.env` 的 `WP_ZH_TW_LANGPACK_VERSION`、`WP_ZH_TW_LANGPACK_URL`、`WP_ZH_TW_LANGPACK_FILE`、`WP_ZH_TW_LANGPACK_SHA256`，以及 `docs/versions.lock.json`、`docs/版本查核.md`。
5. 走一般的合併請求，CI 全綠才合併。

`.gitignore` 預設忽略所有 `*.zip`，只對 `infra/third-party/wordpress/*.zip` 開例外；`tests/security/ignore-rules.sh` 會檢查這個例外仍然有效。
