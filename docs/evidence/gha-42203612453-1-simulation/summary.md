# 驗證證據 gha-42203612453-1-simulation

- 受測 commit：`b2a91af6abcf5305e8f81a47070535e3435fe84c`
- 執行後端：github-actions
- 範圍：simulation
- 產生時間：2026-11-06T10:52:56Z
- 測試數：688（失敗 0）
- 行覆蓋率（app/app（自製業務程式））：94.83%（2987/3150）

## 步驟

| 步驟 | exit | 狀態 | 開始 | 結束 |
|---|---:|---|---|---|
| static | 0 | PASS | 2026-11-06T10:35:08Z | 2026-11-06T10:35:23Z |
| bootstrap | 0 | PASS | 2026-11-06T10:35:23Z | 2026-11-06T10:38:48Z |
| idempotency | 0 | PASS | 2026-11-06T10:38:48Z | 2026-11-06T10:39:06Z |
| doctor | 0 | PASS | 2026-11-06T10:39:06Z | 2026-11-06T10:39:10Z |
| phpunit | 0 | PASS | 2026-11-06T10:39:10Z | 2026-11-06T10:39:47Z |
| wp-contract | 0 | PASS | 2026-11-06T10:39:47Z | 2026-11-06T10:44:30Z |
| e2e | 0 | PASS | 2026-11-06T10:44:30Z | 2026-11-06T10:48:31Z |
| deploy | 0 | PASS | 2026-11-06T10:48:31Z | 2026-11-06T10:51:03Z |
| governance | 0 | PASS | 2026-11-06T10:51:03Z | 2026-11-06T10:52:56Z |

## 驗收 ID

| ID | 級別 | 狀態 | 測試數 | 失敗 |
|---|---|---|---:|---|
| CLD-01 | P0 | PASS | 1 |  |
| CLD-02 | P0 | PASS | 1 |  |
| CLD-03 | P0 | PASS | 1 |  |
| CLD-04 | P0 | PASS | 2 |  |
| CLD-05 | P0 | PASS | 2 |  |
| CLD-06 | P0 | PASS | 1 |  |
| CLD-07 | P0 | PASS | 1 |  |
| CLD-08 | P0 | PASS | 1 |  |
| CLD-09 | P1 | PASS | 2 |  |
| CLD-10 | P0 | PASS | 1 |  |
| INF-01 | P0 | PASS | 2 |  |
| INF-02 | P0 | PASS | 3 |  |
| INF-03 | P0 | PASS | 7 |  |
| INF-04 | P0 | PASS | 5 |  |
| INF-05 | P0 | PASS | 8 |  |
| INF-06 | P0 | PASS | 10 |  |
| INF-07 | P0 | PASS | 11 |  |
| INF-08 | P1 | PASS | 6 |  |
| WP-01 | P0 | PASS | 4 |  |
| WP-02 | P0 | PASS | 2 |  |
| WP-03 | P0 | PASS | 2 |  |
| WP-04 | P0 | PASS | 1 |  |
| WP-05 | P0 | PASS | 1 |  |
| WP-06 | P0 | PASS | 1 |  |
| WP-07 | P0 | PASS | 3 |  |
| WP-08 | P0 | PASS | 7 |  |
| WP-09 | P0 | PASS | 2 |  |
| WP-10 | P0 | PASS | 1 |  |
| API-01 | P0 | PASS | 10 |  |
| API-02 | P0 | PASS | 17 |  |
| API-03 | P0 | PASS | 6 |  |
| API-04 | P0 | PASS | 5 |  |
| API-05 | P0 | PASS | 13 |  |
| API-06 | P0 | PASS | 15 |  |
| API-07 | P0 | PASS | 5 |  |
| API-08 | P0 | PASS | 4 |  |
| API-09 | P0 | PASS | 3 |  |
| API-10 | P0 | PASS | 5 |  |
| API-11 | P0 | PASS | 27 |  |
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
| COM-10 | P0-B | PASS | 48 |  |
| COM-11 | P0-B | PASS | 13 |  |
| COM-12 | P0-B | PASS | 6 |  |
| OPS-01 | P0 | PASS | 25 |  |
| OPS-02 | P0 | PASS | 1 |  |
| OPS-03 | P0 | PASS | 11 |  |
| OPS-04 | P0 | PASS | 7 |  |
| OPS-05 | P0 | PASS | 3 |  |
| OPS-06 | P0 | PASS | 10 |  |
| OPS-07 | P0 | PASS | 3 |  |
| OPS-08 | P0 | BLOCKED_EXTERNAL | 0 |  |
| OPS-09 | P0 | BLOCKED_EXTERNAL | 0 |  |
| OPS-10 | P0 | BLOCKED_EXTERNAL | 0 |  |
| OPS-11 | P0 | PASS | 4 |  |
| OPS-12 | P0 | PASS | 15 |  |
| OPS-13 | P0 | BLOCKED_EXTERNAL | 0 |  |
| OPS-14 | P1 | BLOCKED_EXTERNAL | 0 |  |
| GOV-01 | P0 | PASS | 25 |  |
| GOV-02 | P0 | PASS | 4 |  |
| GOV-03 | P0 | PASS | 1 |  |
| GOV-04 | P0 | PASS | 11 |  |
| GOV-05 | P0 | PASS | 1 |  |
| GOV-06 | P0 | PASS | 6 |  |
| DAT-01 | P0 | PASS | 1 |  |
| DAT-02 | P0 | PASS | 9 |  |
| DAT-03 | P0 | PASS | 1 |  |
| DAT-04 | P0 | PASS | 7 |  |
| DAT-05 | P0 | PASS | 4 |  |
| DAT-06 | P0 | PASS | 2 |  |
| EXEC-01 | P0 | PASS | 1 |  |
| EXEC-02 | P0 | PASS | 1 |  |
| EXEC-03 | P0 | NOT_RUN | 0 |  |
| EXEC-04 | P0 | PASS | 1 |  |
| EXEC-05 | P0 | PASS | 1 |  |
| EXEC-06 | P0 | PASS | 1 |  |
