#!/usr/bin/env bash
# Laravel 容器入口：只做啟動前檢查，不在啟動時生成 APP_KEY 或執行破壞性指令。
# 角色由 MYSITE_ROLE 決定：php-fpm（預設）、worker、scheduler、migrate、cli。
set -Eeuo pipefail

role="${MYSITE_ROLE:-php-fpm}"
cd /var/www/app

case "${APP_ENV:-}" in
  development|testing|staging|production) ;;
  *) echo "mysite: APP_ENV 必須是 development／testing／staging／production（目前：${APP_ENV:-未設定}）" >&2; exit 10 ;;
esac

# 秘密檔案契約由 bootstrap/secrets.php 於 PHP 啟動時驗證；此處先做早期失敗檢查。
php -r 'require "/var/www/app/bootstrap/secrets.php"; \App\Support\Secrets\SecretFiles::assertRequiredForEnvironment(getenv("APP_ENV") ?: "");' \
  || { echo "mysite: 必要秘密檔案缺失或不合法，拒絕啟動（${role}）。" >&2; exit 20; }

if [[ "${APP_ENV}" == "staging" || "${APP_ENV}" == "production" ]]; then
  # 正式／staging：設定注入後才建立 config／route／view 快取（不烘焙進映像）。
  php artisan config:cache --no-ansi >/dev/null
  php artisan route:cache --no-ansi >/dev/null
  php artisan view:cache --no-ansi >/dev/null
fi

if [[ $# -gt 0 && "$1" != "php-fpm" ]]; then
  exec "$@"
fi

case "$role" in
  php-fpm)
    exec php-fpm -F
    ;;
  worker)
    exec php artisan queue:work database --queue="${QUEUE_NAMES:-content,default}" --sleep=2 --tries=3 --backoff=5 --max-time=3600 --timeout=60 --no-ansi
    ;;
  scheduler)
    exec php artisan schedule:work --no-ansi
    ;;
  *)
    echo "mysite: 未知角色 ${role}" >&2
    exit 10
    ;;
esac
