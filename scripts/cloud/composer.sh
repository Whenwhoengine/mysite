#!/usr/bin/env bash
# 以執行期同版本 PHP（mysite/laravel-dev 映像）執行 Composer，工作目錄固定為 app/。
# Claude VM 經代理時改用 host 網路並暫時信任代理 CA；GitHub dist 受限時優先 source（ADR-005）。
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
args=(--rm -v "$root/app:/var/www/app" -w /var/www/app -e COMPOSER_ALLOW_SUPERUSER=1 -e COMPOSER_HOME=/tmp/composer-home
      -v "${MYSITE_COMPOSER_CACHE:-$HOME/.cache/composer}:/tmp/composer-home/cache" --entrypoint composer)
if ms_has_proxy_ca; then
  args+=(--network host -e "https_proxy=$HTTPS_PROXY" -e "HTTPS_PROXY=$HTTPS_PROXY" -e SSL_CERT_FILE=/tmp/proxy-ca.pem
         -v /root/.ccr/ca-bundle.crt:/tmp/proxy-ca.pem:ro -e GIT_SSL_CAINFO=/tmp/proxy-ca.pem
         -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=url.https://github.com/.insteadOf -e GIT_CONFIG_VALUE_0=git@github.com:)
fi
exec docker run "${args[@]}" "$image" "$@"
