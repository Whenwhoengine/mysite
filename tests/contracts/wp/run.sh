#!/usr/bin/env bash
# WordPress（site-content 外掛）契約測試單一入口：從主機對開發／測試環境執行。
set -Eeuo pipefail

usage() {
  cat <<'USAGE'
用法：tests/contracts/wp/run.sh [--only=<測試或群組,...>] [--skip=<測試或群組,...>] [--list] [--help]

環境變數：
  MYSITE_ENV          development（預設）或 testing；決定 scripts/cloud/dev.sh 的 Compose 專案與秘密目錄
  MYSITE_CMS_BASE     CMS（gateway）基底網址，預設 http://127.0.0.1:8081（testing 為 http://127.0.0.1:18081）
  MYSITE_APP_BASE     主站基底網址，預設 http://127.0.0.1:8080（testing 為 http://127.0.0.1:18080）
  MYSITE_RESULTS_DIR  結果目錄，預設 .runtime/results；每次執行先清空 wp-contract.jsonl

群組：lint、api、security、roles、editor、events、preview、schedule（排程測試約需 2 分鐘）
需求：環境已由 scripts/cloud/bootstrap.sh 建立並執行過 `wp site-content seed`；主機需 python3、docker。
測試期間會暫停 wp-jobs 的自動投遞與對帳（開發用 option，最長 15 分鐘，結束時解除），並在結束時清理本次建立的資料。

exit code：0 全部通過；10 參數錯；20 環境未就緒；30 有測試失敗。
USAGE
}

for arg in "$@"; do
  case "$arg" in
    -h|--help) usage; exit 0 ;;
    --only=*|--skip=*|--list) ;;
    *) printf '未知參數：%s\n' "$arg" >&2; usage >&2; exit 10 ;;
  esac
done

case "${MYSITE_ENV:-development}" in
  development|testing) ;;
  *) printf 'MYSITE_ENV 只允許 development 或 testing\n' >&2; exit 10 ;;
esac
command -v python3 >/dev/null || { printf '缺少 python3\n' >&2; exit 20; }
command -v docker >/dev/null || { printf '缺少 docker\n' >&2; exit 20; }

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
set +e
PYTHONDONTWRITEBYTECODE=1 python3 -I "$here/run_contracts.py" "$@"
rc=$?
set -e
case "$rc" in
  0|10|20) exit "$rc" ;;
  *) exit 30 ;;
esac
