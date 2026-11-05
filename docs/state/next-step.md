# next-step

- 目前分支：`claude/happy-euler-evh676`（以 `git ls-remote origin claude/happy-euler-evh676` 讀回為準）。未開 PR、未合併 main。
- 進行中的任務：`M8A-01`（內容範圍乾淨 CI）與 `M8B-01`（內容＋商務全量乾淨 CI），兩者共用 `.github/workflows/ci.yml`。
- 最近一次完整 CI：run 41961995266（受測 commit `1073ced`）。simulation 範圍全部步驟通過（PHPUnit 覆蓋率 94.55%、E2E、部署工具、Trivy 映像掃描）；content 範圍只有 1 項 E2E 失敗（OPS-12 cookie 測試：Fortify 每 IP 每分鐘 60 次整體限流使登入頁回 429）。證據已匯入 `docs/evidence/gha-41961995266-1-*`。
- 之後已修正（待下一輪 CI 驗證）：E2E 登入輔助程式辨識 429 並依 Retry-After 等候；gateway 登入限流改套用實際的 `/admin/login` 等路徑；WordPress 留言全面關閉；新增治理類驗收 `tests/governance/`（CLD／EXEC／GOV／DAT、INF-06 重建保存、INF-08 架構與資源、GOV-02 弱點掃描），由 `verify.sh` 的 governance 步驟執行。
- 第一個可執行下一步：讀回最新一輪 CI（content／simulation）；全部通過就以 `scripts/ci/import_evidence.py` 匯入證據、更新 `docs/state/status.json`（`delivery_state` 改 `CI_VERIFIED`）與 `docs/交付報告.md`；有失敗就依證據修正後重跑。
- 恢復服務：`bash scripts/cloud/session-start.sh`；首次或缺件：`bash scripts/cloud/bootstrap.sh --env=development`。
- 注意：本 VM 可寫磁碟約剩 1 GB（Docker 建置快取約 13 GB，清理需委託人同意）；完整驗證以 GitHub Actions 為主。
- 不可重跑的破壞性動作：無。開發資料卷只能由委託人明確要求後手動清除；正式環境不存在 reset。
- 外部阻擋：見 `docs/state/blockers.md`（主機、DNS、備份、告警、正式秘密、發布核准）；只阻擋 M9／M10。
- 尚未推送的內容：以 `git status` 與 `git log origin/claude/happy-euler-evh676..HEAD` 檢查。
