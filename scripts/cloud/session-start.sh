#!/usr/bin/env bash
# 新會話／恢復時：確認 repo、讀狀態、檢查並恢復開發服務；不重設任何資料。
# 由 .claude/settings.json 的 SessionStart hook 呼叫（120 秒上限），也可手動執行。
set -Euo pipefail
usage() {
  cat <<'USAGE'
用法：scripts/cloud/session-start.sh [--help]
只讀狀態與恢復已 bootstrap 的 development 服務；耗時建置不在此執行。
輸出供新會話閱讀的簡短摘要（不含秘密）。
USAGE
}
[[ "${1:-}" == "-h" || "${1:-}" == "--help" ]] && { usage; exit 0; }
[[ $# -eq 0 ]] || { usage >&2; exit 10; }
# shellcheck source=../lib/common.sh
source "$(dirname "${BASH_SOURCE[0]}")/../lib/common.sh"
root="$(ms_root)" || exit 20
cd "$root"

echo "== mysite 會話啟動摘要 =="
echo "remote: $(git remote get-url origin 2>/dev/null | ms_redact)"
echo "branch: $(git rev-parse --abbrev-ref HEAD)  head: $(git rev-parse --short HEAD)"
echo "未提交變更：$(git status --porcelain | wc -l | tr -d ' ') 個檔案"
if [[ -f docs/state/status.json ]]; then
  jq -r '"delivery_state: \(.delivery_state)\nactive_task: \(.active_task_id)\nnext_action: \(.next_action)"' docs/state/status.json 2>/dev/null \
    || echo "docs/state/status.json 無法解析（請檢查，勿自動重建）"
else
  echo "缺少 docs/state/status.json（請檢查，勿自動重建）"
fi
# EXEC-01：規劃書原文是否存在且與紀錄相符；不在 repo 時照實說明，依內嵌契約開工，不自行補寫原文。
plan_rel="$(jq -r '.source_plan_file // empty' docs/state/status.json 2>/dev/null || true)"
plan_sha="$(jq -r '.plan_sha256 // empty' docs/state/status.json 2>/dev/null || true)"
if [[ -n "$plan_rel" && -f "$plan_rel" ]]; then
  if [[ "$(sha256sum "$plan_rel" | cut -d' ' -f1)" == "$plan_sha" ]]; then
    echo "規劃書：${plan_rel}（SHA-256 與紀錄相符）"
  else
    echo "規劃書：${plan_rel} 的 SHA-256 與 status.json 不符；請停止依此檔施工並向委託人確認原件。"
  fi
else
  echo "規劃書原文不在 repo：依 docs/執行契約.md 的內嵌契約開工，並在 status.json 記錄原文未取得（source_plan_file=null）；不得自行補寫或重建原文。"
fi
[[ -f docs/state/next-step.md ]] && { echo "--- next-step.md（前 15 行）---"; head -15 docs/state/next-step.md; }

if ! command -v docker >/dev/null 2>&1; then
  echo "Docker CLI 不存在：本會話改走 GitHub Actions（sandbox.yml）。"; exit 0
fi
if ! timeout 10 docker info >/dev/null 2>&1; then
  if command -v dockerd >/dev/null 2>&1 && [[ "$(id -u)" == 0 ]]; then
    mkdir -p .runtime
    (nohup dockerd > .runtime/dockerd.log 2>&1 &)
    ms_wait_until 30 1 docker info || { echo "dockerd 啟動失敗；見 .runtime/dockerd.log"; exit 0; }
    echo "已啟動 Docker daemon。"
  else
    echo "Docker daemon 不可用：改走 GitHub Actions。"; exit 0
  fi
fi
if [[ -f .runtime/env/development.env && -s .runtime/secrets/development/app_key ]]; then
  echo "恢復 development 服務（不重設資料）…"
  timeout 100 bash scripts/cloud/up.sh --env=development >/tmp/mysite-session-up.log 2>&1 \
    && echo "服務已就緒（doctor 通過）。" \
    || echo "服務未完全就緒；請手動執行 bash scripts/cloud/up.sh --env=development 查看（log：/tmp/mysite-session-up.log）。"
else
  echo "尚未 bootstrap：請執行 bash scripts/cloud/bootstrap.sh --env=development（會下載與建置，耗時數分鐘）。"
fi
exit 0
