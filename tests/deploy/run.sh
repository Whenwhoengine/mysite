#!/usr/bin/env bash
# 部署／發布／備份／還原工具的自動化測試單一入口（OPS-03～OPS-07、OPS-11、DAT-06、GOV-04 等）。
# 只依賴 bash、openssl、jq、python3、docker（GitHub-hosted Ubuntu runner 預裝）；docker／compose／curl／restic 的
# 流程測試使用 tests/deploy/stubs 替身，整合測試（I01）另以專屬 mysite-deploytest-* project 起真實 MySQL＋restic。
set -uo pipefail
umask 022

usage() {
  cat <<'EOF'
用法：tests/deploy/run.sh [--only=<名稱片段>] [--no-integration] [--keep-temp] [--help]

  --only=<片段>      只跑名稱含此片段的測試
  --no-integration   不跑需要 Docker 的整合測試（I01：真實 MySQL＋restic 備份→隔離還原）
  --keep-temp        保留臨時目錄（除錯用；內含測試用臨時金鑰，用完請刪除）

結果：終端輸出＋${MYSITE_RESULTS_DIR:-.runtime/results}/deploy.jsonl（每次執行先清空；每個測試一行）
  {"suite":"deploy","test":…,"ids":[…],"status":"PASS|FAIL","message":…,"started_at":…,"finished_at":…}
未執行的測試（例如沒有 Docker 時的整合測試）不寫入 jsonl，只在終端標 NOT_RUN。
exit code：0 全部通過；30 任一失敗；10 參數錯。
EOF
}

only=""; integration=1; keep=0
for arg in "$@"; do
  case "$arg" in
    --only=*) only="${arg#--only=}" ;;
    --no-integration) integration=0 ;;
    --keep-temp) keep=1 ;;
    -h|--help) usage; exit 0 ;;
    *) printf '未知參數：%s\n' "$arg" >&2; usage >&2; exit 10 ;;
  esac
done

for c in bash openssl jq python3; do command -v "$c" >/dev/null 2>&1 || { echo "缺少 $c" >&2; exit 30; }; done
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
# shellcheck source=lib.sh
source "$HERE/lib.sh"
results_dir="${MYSITE_RESULTS_DIR:-$T_REPO/.runtime/results}"
mkdir -p "$results_dir"
results="$results_dir/deploy.jsonl"
: > "$results"

T_ROOT="$(mktemp -d)"
cleanup_all() {
  local rc=$?
  if declare -F it_cleanup >/dev/null; then it_cleanup; fi
  if (( keep )); then echo "保留臨時目錄：$T_ROOT"; else rm -rf -- "$T_ROOT"; fi
  return "$rc"
}
trap cleanup_all EXIT
mkdir -p "$T_ROOT/logs"
keys_init

n_pass=0; n_fail=0; n_skip=0; failed_names=()
now_iso() { date -u +%FT%TZ; }

# t <名稱> <ID,ID> <函式>
t() {
  local name="$1" ids="$2" fn="$3" started finished rc msg log
  [[ -z "$only" || "$name" == *"$only"* ]] || return 0
  log="$T_ROOT/logs/$name.log"
  started="$(now_iso)"
  ( set -uo pipefail; "$fn" ) > "$log" 2>&1; rc=$?
  finished="$(now_iso)"
  if (( rc == 77 )); then
    n_skip=$((n_skip + 1)); printf '  NOT_RUN %-46s %s\n' "$name" "$(grep -E '^SKIP: ' "$log" | tail -n 1 | sed 's/^SKIP: //')"
    return 0
  fi
  if (( rc == 0 )); then
    n_pass=$((n_pass + 1)); msg="$(grep -E '^OK: ' "$log" | tail -n 1 | sed 's/^OK: //')"; msg="${msg:-通過}"
    printf '  PASS  %-48s %s\n' "$name" "$msg"
    status=PASS
  else
    n_fail=$((n_fail + 1)); failed_names+=("$name")
    msg="$(grep -E '^FAIL: ' "$log" | tail -n 1 | sed 's/^FAIL: //')"; msg="${msg:-測試函式回傳 $rc}"
    printf '  FAIL  %-48s %s\n' "$name" "$msg"
    sed 's/^/        | /' "$log" | tail -n 15
    status=FAIL
  fi
  msg="$(printf '%s' "$msg" | sed -E 's#(BEGIN [A-Z ]*PRIVATE KEY)#[已遮罩]#g' | cut -c1-300)"
  jq -cn --arg test "$name" --arg ids "$ids" --arg status "$status" --arg message "$msg" --arg s "$started" --arg f "$finished" \
    '{suite:"deploy", test:$test, ids:($ids|split(",")), status:$status, message:$message, started_at:$s, finished_at:$f}' >> "$results"
}

CASES=()
# case <名稱> <ID,ID> <函式>：由 cases/*.sh 登錄
case_add() { CASES+=("$1|$2|$3"); }
run_cases() { local c n i f; for c in "${CASES[@]}"; do IFS='|' read -r n i f <<< "$c"; t "$n" "$i" "$f"; done; }
for f in "$HERE"/cases/*.sh; do
  # shellcheck source=/dev/null
  source "$f"
done

echo "== mysite 部署工具測試（$(now_iso)）"
run_cases
echo "== 結果：PASS ${n_pass}／FAIL ${n_fail}／NOT_RUN ${n_skip}（明細：${results}）"
if (( n_fail > 0 )); then
  printf '失敗：%s\n' "${failed_names[*]}"
  exit 30
fi
exit 0
