#!/usr/bin/env bash
# Certbot webroot 續期＋重新載入 gateway（由 mysite-certbot-renew.service 執行；v5 §13.6-5，OPS-13）。
#   certbot renew 只在接近到期時續期；有續期時 deploy hook 在 /etc/letsencrypt 留下標記，
#   本腳本看到標記才對每個 https 模式的環境 gateway 執行 nginx -t → nginx -s reload。
#   任何憑證 21 天內到期（暫定告警門檻）→ exit 30，讓 unit 呈 failed 供告警。
set -Eeuo pipefail
umask 027

usage() {
  cat <<'EOF'
用法：infra/systemd/libexec/mysite-certbot-renew.sh [--dry-run] [--help]

  --dry-run   續期演練（certbot renew --dry-run，向 ACME 測試環境演練）＋各 gateway nginx -t，不 reload
exit code：0 成功；20 缺 Docker／certbot 失敗；30 憑證即將到期或 nginx 組態測試失敗。
EOF
}
dry_run=0
for arg in "$@"; do
  case "$arg" in
    --dry-run) dry_run=1 ;;
    -h|--help) usage; exit 0 ;;
    *) printf '未知參數：%s\n' "$arg" >&2; usage >&2; exit 10 ;;
  esac
done

# shellcheck source=../../../scripts/lib/deploy.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd -P)/scripts/lib/deploy.sh"
MD_SCRIPT_NAME=certbot-renew
md_resolve_host_root
md_docker_ok || ms_die "$EXIT_EXTERNAL" "Docker daemon 不可用"
tls="$MD_HOST_ROOT/tls"
marker="$tls/letsencrypt/.mysite-renewed"
certbot_ref="$(md_certbot_image)"

args=(renew --webroot -w /var/www/acme --non-interactive --deploy-hook "touch /etc/letsencrypt/.mysite-renewed")
(( dry_run )) && args+=(--dry-run)
timeout 1500 docker run --rm \
  -v "$tls/letsencrypt:/etc/letsencrypt" -v "$tls/certbot-lib:/var/lib/letsencrypt" -v "$tls/acme:/var/www/acme" \
  "docker.io/${certbot_ref}" "${args[@]}" || ms_die "$EXIT_EXTERNAL" "certbot renew 失敗（現有憑證與 gateway 未變動）"

rc=0
for envdir in "$MD_HOST_ROOT"/environments/*/; do
  env="$(basename "$envdir")"
  case "$env" in staging|production) ;; *) continue ;; esac
  grep -qx 'MYSITE_GATEWAY_MODE=https' "$envdir/state/compose.env" 2>/dev/null || continue
  MD_PROJECT="mysite-${env}"
  gw="$(md_container gateway)"
  [[ -n "$gw" ]] || continue
  if ! timeout 30 docker exec "$gw" nginx -t >/dev/null 2>&1; then
    ms_err "${env} gateway 組態測試失敗；不 reload"; rc=$EXIT_TEST_FAIL; continue
  fi
  if (( ! dry_run )) && [[ -e "$marker" ]]; then
    timeout 30 docker exec "$gw" nginx -s reload >/dev/null 2>&1 || { ms_err "${env} gateway reload 失敗"; rc=$EXIT_TEST_FAIL; continue; }
    ms_info "${env} gateway 已重新載入新憑證"
  fi
done
(( ! dry_run && rc == 0 )) && rm -f -- "$marker"

# 到期告警（暫定 21 天）
for cert in "$tls"/letsencrypt/live/*/fullchain.pem; do
  [[ -f "$cert" ]] || continue
  if ! openssl x509 -checkend $((21 * 86400)) -noout -in "$cert" >/dev/null 2>&1; then
    ms_err "憑證 $(basename "$(dirname "$cert")") 將在 21 天內到期且尚未續上"
    rc=$EXIT_TEST_FAIL
  fi
done
exit "$rc"
