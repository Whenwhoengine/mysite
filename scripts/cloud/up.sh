#!/usr/bin/env bash
# 啟動已定義的開發服務並等待 readiness；不清空 volume、不重設資料。
set -Eeuo pipefail
usage() {
  cat <<'USAGE'
用法：scripts/cloud/up.sh --env=development [--help]
啟動 Compose 服務並等待健康（上限 300 秒），之後執行 doctor。
若尚未 bootstrap（缺環境檔或秘密），exit 20 並提示先執行 bootstrap.sh。
USAGE
}
env_name=""
for arg in "$@"; do
  case "$arg" in
    --env=*) env_name="${arg#--env=}" ;;
    -h|--help) usage; exit 0 ;;
    *) printf '未知參數：%s\n' "$arg" >&2; usage >&2; exit 10 ;;
  esac
done
# shellcheck source=../lib/common.sh
source "$(dirname "${BASH_SOURCE[0]}")/../lib/common.sh"
# shellcheck source=../lib/compose.sh
source "$(dirname "${BASH_SOURCE[0]}")/../lib/compose.sh"
[[ -n "$env_name" ]] || { usage >&2; exit "$EXIT_USAGE"; }
ms_require_env_enum "$env_name" development testing
root="$(ms_root)"; cd "$root"; ms_load_versions "$root"
timeout 15 docker info >/dev/null 2>&1 || ms_die "$EXIT_USE_ACTIONS" "Docker daemon 不可用"
ms_compose_prepare "$root" "$env_name"
[[ -s "$MYSITE_SECRETS_DIR/app_key" ]] || ms_die "$EXIT_EXTERNAL" "尚未 bootstrap：缺少秘密；請執行 scripts/cloud/bootstrap.sh --env=$env_name"
for img in $(ms_compose config --images 2>/dev/null | sort -u); do
  docker image inspect "$img" >/dev/null 2>&1 || ms_die "$EXIT_EXTERNAL" "映像 $img 不存在；請執行 scripts/cloud/bootstrap.sh --env=$env_name"
done
ms_compose up -d --wait --wait-timeout 300 || ms_die "$EXIT_TEST_FAIL" "服務未在 300 秒內全部就緒"
exec bash "$root/scripts/cloud/doctor.sh" --env="$env_name"
