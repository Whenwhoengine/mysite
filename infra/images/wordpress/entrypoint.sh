#!/usr/bin/env bash
# WordPress 容器入口：驗證秘密與環境，不在啟動時安裝或重設任何資料。
# 角色：php-fpm（預設）、jobs（wp-jobs 迴圈）、cli。
set -Eeuo pipefail

role="${MYSITE_ROLE:-php-fpm}"
cd /var/www/wp

case "${WP_ENVIRONMENT_TYPE:-}" in
  development|staging|production) ;;
  *) echo "mysite-wp: WP_ENVIRONMENT_TYPE 必須是 development／staging／production（目前：${WP_ENVIRONMENT_TYPE:-未設定}）" >&2; exit 10 ;;
esac

# 由 wp-config.php 的同一套 *_FILE 規則做早期驗證（缺檔、空值、權限過寬即失敗）。
php -d display_errors=stderr -r 'define("MYSITE_CONFIG_CHECK_ONLY", true); require "/var/www/wp/wp-config.php";' \
  || { echo "mysite-wp: 必要秘密檔案缺失或不合法，拒絕啟動（${role}）。" >&2; exit 20; }

if [[ $# -gt 0 && "$1" != "php-fpm" ]]; then
  exec "$@"
fi

case "$role" in
  php-fpm) exec php-fpm -F ;;
  jobs) exec /usr/local/bin/mysite-wp-jobs ;;
  *) echo "mysite-wp: 未知角色 ${role}" >&2; exit 10 ;;
esac
