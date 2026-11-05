#!/usr/bin/env bash
# 彙整真實結果為逐驗收 ID 的證據；只整理，不改判定（FAIL 不會變 PASS）。
set -Eeuo pipefail
usage() {
  cat <<'USAGE'
用法：scripts/ci/collect-evidence.sh --run-id=<識別> [--update-registry] [--help]
輸入：${MYSITE_RESULTS_DIR:-.runtime/results}；輸出：docs/evidence/<run-id>/summary.{json,md}、results.jsonl
--update-registry：把逐 ID 結果寫回 tests/acceptance/registry.json（受測 commit 必須等於 HEAD）
輸出後會對證據目錄做秘密掃描，發現疑似秘密即 exit 40。
USAGE
}
run_id=""; extra=()
for a in "$@"; do
  case "$a" in
    --run-id=*) run_id="${a#--run-id=}" ;;
    --update-registry) extra+=(--update-registry) ;;
    -h|--help) usage; exit 0 ;;
    *) usage >&2; exit 10 ;;
  esac
done
[[ "$run_id" =~ ^[A-Za-z0-9._-]{1,80}$ ]] || { usage >&2; exit 10; }
root="$(git rev-parse --show-toplevel)"; cd "$root"
python3 scripts/ci/collect_evidence.py --run-id="$run_id" "${extra[@]}"
bash scripts/ci/secret-scan.sh --path="docs/evidence/$run_id" >/dev/null || { echo "證據目錄疑似含秘密：docs/evidence/$run_id" >&2; exit 40; }
