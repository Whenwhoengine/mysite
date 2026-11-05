# 驗證證據 gha-41961995266-1-content

- 受測 commit：`1073cedcdffcabae4d8e6012e052dff1c0fde5a6`
- 執行後端：github-actions
- 範圍：content
- 產生時間：2026-11-05T03:45:13Z
- 測試數：616（失敗 1）
- 行覆蓋率（app/app（自製業務程式））：94.55%（2882/3048）

## 步驟

| 步驟 | exit | 狀態 | 開始 | 結束 |
|---|---:|---|---|---|
| static | 0 | PASS | 2026-11-05T03:31:23Z | 2026-11-05T03:31:37Z |
| bootstrap | 0 | PASS | 2026-11-05T03:31:37Z | 2026-11-05T03:34:57Z |
| idempotency | 0 | PASS | 2026-11-05T03:34:57Z | 2026-11-05T03:35:14Z |
| doctor | 0 | PASS | 2026-11-05T03:35:14Z | 2026-11-05T03:35:17Z |
| phpunit | 0 | PASS | 2026-11-05T03:35:17Z | 2026-11-05T03:35:54Z |
| wp-contract | 0 | PASS | 2026-11-05T03:35:54Z | 2026-11-05T03:38:54Z |
| e2e | 30 | FAIL | 2026-11-05T03:38:54Z | 2026-11-05T03:43:01Z |
| deploy | 0 | PASS | 2026-11-05T03:43:01Z | 2026-11-05T03:45:13Z |

## 驗收 ID

| ID | 級別 | 狀態 | 測試數 | 失敗 |
|---|---|---|---:|---|
| CLD-01 | P0 | NOT_RUN | 0 |  |
| CLD-02 | P0 | NOT_RUN | 0 |  |
| CLD-03 | P0 | NOT_RUN | 0 |  |
| CLD-04 | P0 | PASS | 2 |  |
| CLD-05 | P0 | PASS | 2 |  |
| CLD-06 | P0 | NOT_RUN | 0 |  |
| CLD-07 | P0 | NOT_RUN | 0 |  |
| CLD-08 | P0 | NOT_RUN | 0 |  |
| CLD-09 | P1 | NOT_RUN | 0 |  |
| CLD-10 | P0 | NOT_RUN | 0 |  |
| INF-01 | P0 | PASS | 2 |  |
| INF-02 | P0 | PASS | 3 |  |
| INF-03 | P0 | PASS | 7 |  |
| INF-04 | P0 | PASS | 5 |  |
| INF-05 | P0 | PASS | 8 |  |
| INF-06 | P0 | NOT_RUN | 0 |  |
| INF-07 | P0 | PASS | 11 |  |
| INF-08 | P1 | NOT_RUN | 0 |  |
| WP-01 | P0 | PASS | 4 |  |
| WP-02 | P0 | PASS | 2 |  |
| WP-03 | P0 | PASS | 2 |  |
| WP-04 | P0 | PASS | 1 |  |
| WP-05 | P0 | PASS | 1 |  |
| WP-06 | P0 | PASS | 1 |  |
| WP-07 | P0 | PASS | 1 |  |
| WP-08 | P0 | PASS | 6 |  |
| WP-09 | P0 | PASS | 2 |  |
| WP-10 | P0 | PASS | 1 |  |
| API-01 | P0 | PASS | 10 |  |
| API-02 | P0 | PASS | 17 |  |
| API-03 | P0 | PASS | 6 |  |
| API-04 | P0 | PASS | 5 |  |
| API-05 | P0 | PASS | 13 |  |
| API-06 | P0 | PASS | 8 |  |
| API-07 | P0 | PASS | 5 |  |
| API-08 | P0 | PASS | 4 |  |
| API-09 | P0 | PASS | 3 |  |
| API-10 | P0 | PASS | 5 |  |
| API-11 | P0 | PASS | 20 |  |
| API-12 | P0 | PASS | 6 |  |
| API-13 | P0 | PASS | 17 |  |
| API-14 | P0 | PASS | 3 |  |
| API-15 | P0 | PASS | 9 |  |
| API-16 | P1 | PASS | 7 |  |
| UI-01 | P0 | PASS | 14 |  |
| UI-02 | P0 | PASS | 93 |  |
| UI-03 | P0 | PASS | 26 |  |
| UI-04 | P0 | PASS | 15 |  |
| UI-05 | P1 | PASS | 15 |  |
| UI-06 | P0 | PASS | 19 |  |
| UI-07 | P0 | PASS | 10 |  |
| UI-08 | P1 | PASS | 90 |  |
| COM-01 | P0-B | PASS | 12 |  |
| COM-02 | P0-B | PASS | 15 |  |
| COM-03 | P0-B | PASS | 22 |  |
| COM-04 | P0-B | PASS | 7 |  |
| COM-05 | P0-B | PASS | 10 |  |
| COM-06 | P0-B | PASS | 6 |  |
| COM-07 | P0-B | PASS | 2 |  |
| COM-08 | P0-B | PASS | 8 |  |
| COM-09 | P0-B | PASS | 10 |  |
| COM-10 | P0-B | PASS | 46 |  |
| COM-11 | P0-B | PASS | 13 |  |
| COM-12 | P0-B | PASS | 6 |  |
| OPS-01 | P0 | PASS | 6 |  |
| OPS-02 | P0 | BLOCKED_EXTERNAL | 0 |  |
| OPS-03 | P0 | PASS | 11 |  |
| OPS-04 | P0 | PASS | 7 |  |
| OPS-05 | P0 | PASS | 3 |  |
| OPS-06 | P0 | PASS | 5 |  |
| OPS-07 | P0 | PASS | 3 |  |
| OPS-08 | P0 | BLOCKED_EXTERNAL | 0 |  |
| OPS-09 | P0 | BLOCKED_EXTERNAL | 0 |  |
| OPS-10 | P0 | BLOCKED_EXTERNAL | 0 |  |
| OPS-11 | P0 | PASS | 3 |  |
| OPS-12 | P0 | FAIL | 15 | e2e:tablet-768 › cookies.spec.mjs › Cookie 與快取隔離 › CMS 與主站後臺登入 cookie 皆為 host-only、名稱不同、互不送出：TimeoutError: locator.fill: Timeout 20000ms exceeded. |
| OPS-13 | P0 | BLOCKED_EXTERNAL | 0 |  |
| OPS-14 | P1 | BLOCKED_EXTERNAL | 0 |  |
| GOV-01 | P0 | PASS | 17 |  |
| GOV-02 | P0 | NOT_RUN | 0 |  |
| GOV-03 | P0 | PASS | 1 |  |
| GOV-04 | P0 | PASS | 10 |  |
| GOV-05 | P0 | NOT_RUN | 0 |  |
| GOV-06 | P0 | PASS | 6 |  |
| DAT-01 | P0 | NOT_RUN | 0 |  |
| DAT-02 | P0 | PASS | 7 |  |
| DAT-03 | P0 | NOT_RUN | 0 |  |
| DAT-04 | P0 | PASS | 7 |  |
| DAT-05 | P0 | PASS | 4 |  |
| DAT-06 | P0 | PASS | 2 |  |
| EXEC-01 | P0 | NOT_RUN | 0 |  |
| EXEC-02 | P0 | NOT_RUN | 0 |  |
| EXEC-03 | P0 | NOT_RUN | 0 |  |
| EXEC-04 | P0 | NOT_RUN | 0 |  |
| EXEC-05 | P0 | NOT_RUN | 0 |  |
| EXEC-06 | P0 | NOT_RUN | 0 |  |
