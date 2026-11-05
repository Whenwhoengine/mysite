#!/usr/bin/env bash
# 開發／測試環境便利指令。預設 development；以環境變數 MYSITE_ENV=testing 切換到測試專案。
# 所有命令都在容器內執行，不需主機 PHP。
set -Eeuo pipefail
usage() {
  cat <<'USAGE'
用法：scripts/cloud/dev.sh <子命令> [參數...]
  compose <args...>        對 development 專案執行 docker compose
  artisan <args...>        在 app-php（www-data）執行 php artisan
  wp <args...>             在 wp-php（www-data）執行 WP-CLI
  test [--db=<後綴>] [args] 以 tools 容器執行 PHPUnit（測試庫 mysite_app_test[_後綴]）
  mysql-root <sql>         以 root 在 mysql 容器執行 SQL（僅 development）
USAGE
}
[[ $# -ge 1 ]] || { usage >&2; exit 10; }
# shellcheck source=../lib/common.sh
source "$(dirname "${BASH_SOURCE[0]}")/../lib/common.sh"
# shellcheck source=../lib/compose.sh
source "$(dirname "${BASH_SOURCE[0]}")/../lib/compose.sh"
root="$(ms_root)"; cd "$root"; ms_load_versions "$root"
target_env="${MYSITE_ENV:-development}"
ms_require_env_enum "$target_env" development testing
ms_compose_prepare "$root" "$target_env"
cmd="$1"; shift
case "$cmd" in
  -h|--help) usage ;;
  compose) ms_compose "$@" ;;
  artisan) ms_compose exec -T -u www-data app-php php artisan "$@" ;;
  wp) ms_compose exec -T -u www-data wp-php wp "$@" ;;
  test)
    suffix=""
    if [[ "${1:-}" == --db=* ]]; then suffix="_${1#--db=}"; shift; fi
    [[ -z "$suffix" || "$suffix" =~ ^_[a-z0-9]{1,20}$ ]] || ms_die "$EXIT_USAGE" "--db 後綴只允許小寫英數"
    db="mysite_app_test${suffix}"
    ms_compose exec -T -e MYSQL_PWD="$(cat "$MYSITE_SECRETS_DIR/mysql_root_password")" mysql mysql -h 127.0.0.1 -uroot \
      -e "CREATE DATABASE IF NOT EXISTS \`${db}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci; GRANT ALL PRIVILEGES ON \`${db}\`.* TO 'mysite_app'@'%';" 2>/dev/null
    ms_compose run --rm -T -u www-data -e MYSITE_TEST_DB="$db" -e HOME=/tmp tools php artisan test "$@"
    ;;
  mysql-root) ms_compose exec -T -e MYSQL_PWD="$(cat "$MYSITE_SECRETS_DIR/mysql_root_password")" mysql mysql -h 127.0.0.1 -uroot -e "$1" ;;
  *) usage >&2; exit 10 ;;
esac
