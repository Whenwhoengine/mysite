#!/usr/bin/env bash
# 保存進度：更新 status.json 時間與 commit、檢查 state 一致性、掃描秘密、提交並推送工作分支，最後讀回遠端。
set -Eeuo pipefail
usage() {
  cat <<'USAGE'
用法：scripts/cloud/checkpoint.sh --reason=<原因> [--no-push] [--help]
  --reason   本次保存原因（寫入提交訊息）
  --no-push  只提交不推送（會在 next-step.md 註明尚未推送）
不使用 force push；推送失敗以 exit 20 回報，不把本地 commit 當成遠端已保存。
USAGE
}
reason=""; push=1
for a in "$@"; do
  case "$a" in
    --reason=*) reason="${a#--reason=}" ;;
    --no-push) push=0 ;;
    -h|--help) usage; exit 0 ;;
    *) usage >&2; exit 10 ;;
  esac
done
[[ -n "$reason" ]] || { usage >&2; exit 10; }
[[ "$reason" =~ ^[[:print:]]{1,200}$ ]] || { echo "原因過長或含控制字元" >&2; exit 10; }
# shellcheck source=../lib/common.sh
source "$(dirname "${BASH_SOURCE[0]}")/../lib/common.sh"
root="$(ms_root)"; cd "$root"
branch="$(git rev-parse --abbrev-ref HEAD)"
case "$branch" in main|master) ms_die "$EXIT_FORBIDDEN" "禁止在 $branch 直接 checkpoint；請使用工作分支" ;; esac

python3 scripts/ci/check-state.py || ms_die "$EXIT_TEST_FAIL" "state 檢查失敗，未提交"
tmp="$(mktemp)"
jq --arg at "$(date -u +%FT%TZ)" --arg br "$branch" '.updated_at=$at | .working_branch=$br' docs/state/status.json > "$tmp" && mv "$tmp" docs/state/status.json
git add -A
bash scripts/ci/secret-scan.sh --staged || ms_die "$EXIT_FORBIDDEN" "暫存區疑似含秘密，未提交"
if git diff --cached --quiet; then
  ms_info "沒有變更需要提交"
else
  git commit -q -m "checkpoint: ${reason}"
fi
if (( push )); then
  for delay in 0 2 4 8 16; do
    sleep "$delay"
    if git push -u origin "$branch" >/dev/null 2>&1; then
      remote="$(git ls-remote origin "refs/heads/$branch" | cut -f1)"
      [[ "$remote" == "$(git rev-parse HEAD)" ]] || ms_die "$EXIT_EXTERNAL" "推送後讀回的遠端 SHA 不一致"
      ms_info "已推送並讀回：$branch @ ${remote:0:12}"
      exit 0
    fi
  done
  ms_die "$EXIT_EXTERNAL" "推送失敗（已重試）；成果仍只在 VM，請勿當成已保存"
fi
ms_warn "未推送：成果只在本地 commit $(git rev-parse --short HEAD)"
