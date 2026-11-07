#!/usr/bin/env bash
# 以執行期同版本 PHP（mysite/laravel-dev 映像）執行 Composer，工作目錄固定為 app/。
# Claude VM 經代理時改用 host 網路並暫時信任代理 CA。GitHub dist 受限時要改用 source（ADR-005）：
# Composer 沒有能指定下載方式的環境變數，呼叫端必須自行加 --prefer-install=source（bootstrap.sh 依 COMPOSER_PREFER 加上）。
set -Eeuo pipefail
usage() {
  cat <<'USAGE'
用法：scripts/cloud/composer.sh <composer 參數...>
例：scripts/cloud/composer.sh install --no-interaction
    scripts/cloud/composer.sh audit
只在雲端 Linux 執行；不需要主機安裝 PHP。
USAGE
}
[[ $# -eq 0 || "$1" == "-h" || "$1" == "--help" ]] && { usage; [[ $# -eq 0 ]] && exit 10 || exit 0; }
# shellcheck source=../lib/common.sh
source "$(dirname "${BASH_SOURCE[0]}")/../lib/common.sh"
root="$(ms_root)"
image="${MYSITE_TOOLS_IMAGE:-mysite/laravel-dev:local}"
docker image inspect "$image" >/dev/null 2>&1 || ms_die "$EXIT_EXTERNAL" "找不到工具映像 $image；請先執行 scripts/cloud/bootstrap.sh"
# 這個容器沒有任何執行期設定與秘密：Composer 腳本（dump-autoload 後的 package:discover）啟動 Laravel 時，
# 跟映像建置一樣以 MYSITE_SKIP_CONFIG_VALIDATION=1 略過執行期設定檢查（只對 CLI 有效，見 AppServiceProvider）。
args=(--rm -v "$root/app:/var/www/app" -w /var/www/app -e COMPOSER_ALLOW_SUPERUSER=1 -e COMPOSER_HOME=/tmp/composer-home -e MYSITE_SKIP_CONFIG_VALIDATION=1
      -v "${MYSITE_COMPOSER_CACHE:-$HOME/.cache/composer}:/tmp/composer-home/cache" --entrypoint composer)
if ms_has_proxy_ca; then
  args+=(--network host -e "https_proxy=$HTTPS_PROXY" -e "HTTPS_PROXY=$HTTPS_PROXY" -e SSL_CERT_FILE=/tmp/proxy-ca.pem
         -v /root/.ccr/ca-bundle.crt:/tmp/proxy-ca.pem:ro -e GIT_SSL_CAINFO=/tmp/proxy-ca.pem
         -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=url.https://github.com/.insteadOf -e GIT_CONFIG_VALUE_0=git@github.com:)
fi
exec docker run "${args[@]}" "$image" "$@"
