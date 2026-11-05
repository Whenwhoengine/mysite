#!/usr/bin/env bash
# 治理與流程類驗收入口：靜態／流程檢查（checks.py）、重建保存（persistence.sh）、架構與資源量測（resources.sh）、弱點掃描（scan.sh）。
set -Euo pipefail

usage() {
  cat <<'EOF'
用法：tests/governance/run.sh [--only=checks,persistence,resources,scan] [--help]

結果寫入 ${MYSITE_RESULTS_DIR:-.runtime/results}/governance.jsonl（每列含驗收 ID）。
persistence／resources／scan 需要已 bootstrap 的 testing 環境；不存在時這些部分記 FAIL，不略過成通過。
exit code：0 全部通過；10 參數錯；30 有失敗
EOF
}
only="checks,persistence,resources,scan"
for a in "$@"; do
  case "$a" in
    --only=*) only="${a#--only=}" ;;
    -h|--help) usage; exit 0 ;;
    *) printf '未知參數：%s\n' "$a" >&2; usage >&2; exit 10 ;;
  esac
done
[[ "$only" =~ ^[a-z,]+$ ]] || { usage >&2; exit 10; }
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
export MYSITE_RESULTS_DIR="${MYSITE_RESULTS_DIR:-$here/../../.runtime/results}"
mkdir -p "$MYSITE_RESULTS_DIR"
rc_all=0
part() {
  local name="$1"; shift
  [[ ",$only," == *",$name,"* ]] || return 0
  local script="${*: -1}"  # 最後一個參數是腳本路徑（前面是直譯器）
  if [[ ! -f "$script" ]]; then
    jq -cn --arg t "$name-missing" --arg at "$(date -u +%FT%TZ)" '{suite:"governance", test:$t, ids:[], status:"FAIL", message:"腳本不存在", started_at:$at, finished_at:$at}' >> "$MYSITE_RESULTS_DIR/governance.jsonl"
    rc_all=30; return 0
  fi
  "$@"; local rc=$?
  if (( rc != 0 )); then
    printf '治理檢查 %s 失敗（exit %s）\n' "$name" "$rc" >&2
    rc_all=30
  fi
}
part checks python3 "$here/checks.py"
part persistence bash "$here/persistence.sh"
part resources bash "$here/resources.sh"
part scan bash "$here/scan.sh"
exit "$rc_all"
