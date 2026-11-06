#!/usr/bin/env bash
# 實際 HTTP／DB／排程／設定檢查（去秘密化摘要寫入 .runtime/evidence/doctor-<env>.json）。
set -Euo pipefail

usage() {
  cat <<'EOF'
用法：scripts/cloud/doctor.sh --env=development|testing [--help]

檢查項目（任一失敗 exit 30）：
  - 全部服務容器在執行且健康
  - 主站 /healthz、/readyz，CMS site-content health（經 gateway）
  - Laravel 與 WordPress 兩個 Web SAPI 經 HTTP→Nginx→FPM 讀回 PHP 版本與 upload_progress 等安全設定（INF-01）
  - WordPress 核心 checksum（離線 JSON，hash 鎖定）
  - MySQL 帳號不可跨庫存取（INF-02）、埠未對外發布
  - .env／vendor／.git／composer.json 不可下載（INF-03）
  - uploads 內 PHP／雙副檔名不可執行（INF-04 基礎）
  - 主站敏感檔與 .php 探測由 gateway 直接回 404，上游收不到（定案執行書 §4.6、FE-20）
  - wp-jobs 心跳、app-worker、app-scheduler 在執行
EOF
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
ms_compose_prepare "$root" "$env_name"
secrets_dir="$MYSITE_SECRETS_DIR"
env_file="$root/.runtime/env/$env_name.env"
app_port="$(grep -E '^APP_PORT=' "$env_file" | cut -d= -f2)"
cms_port="$(grep -E '^CMS_PORT=' "$env_file" | cut -d= -f2)"
app_url="$(grep -E '^APP_URL=' "$env_file" | cut -d= -f2-)"
cms_url="$(grep -E '^WP_PUBLIC_URL=' "$env_file" | cut -d= -f2-)"
diag_token="$(cat "$secrets_dir/diagnostics_token" 2>/dev/null || true)"

results=()
fail=0
check() {
  # check <id> <說明> <命令...>
  local id="$1" desc="$2"; shift 2
  local out rc
  out="$("$@" 2>&1)"; rc=$?
  if (( rc == 0 )); then
    results+=("$(jq -cn --arg id "$id" --arg d "$desc" '{id:$id, check:$d, status:"PASS"}')")
    printf '  PASS  %-28s %s\n' "$id" "$desc"
  else
    fail=1
    results+=("$(jq -cn --arg id "$id" --arg d "$desc" --arg o "$(printf '%s' "$out" | ms_redact | tail -c 400)" '{id:$id, check:$d, status:"FAIL", detail:$o}')")
    printf '  FAIL  %-28s %s\n' "$id" "$desc"
    printf '%s\n' "$out" | ms_redact | tail -5 | sed 's/^/        /'
  fi
}

http_code() { curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$@"; }
expect_code() { local want="$1"; shift; local got; got="$(http_code "$@")"; [[ "$got" == "$want" ]] || { echo "期望 $want，實得 $got：$*"; return 1; }; }

services_healthy() {
  local bad
  bad="$(ms_compose ps --format json | jq -r 'select(.State != "running" or (.Health != "" and .Health != "healthy")) | "\(.Service):\(.State)/\(.Health)"')"
  [[ -z "$bad" ]] || { echo "未就緒：$bad"; return 1; }
  local expected="mysql wp-php wp-jobs wp-web app-php app-worker app-scheduler app-web gateway" s
  for s in $expected; do
    ms_compose ps --format json "$s" | jq -e 'select(.State=="running")' >/dev/null || { echo "服務未執行：$s"; return 1; }
  done
}

php_settings() {
  # $1=service name for exec; $2=url inside that container
  local json
  json="$(ms_compose exec -T "$1" curl -fsS --max-time 10 -H "X-Mysite-Diagnostics-Token: $diag_token" "$2")" || { echo "診斷端點無回應"; return 1; }
  echo "$json" | jq -e --arg v "$PHP_VERSION" '
    .php_version == $v and .sapi == "fpm-fcgi"
    and (.["session.upload_progress.enabled"] == "0" or .["session.upload_progress.enabled"] == "" or .["session.upload_progress.enabled"] == false)
    and (.expose_php == "0" or .expose_php == "" or .expose_php == false)
    and ((.display_errors|tostring|ascii_downcase) as $d | ($d == "0" or $d == "" or $d == "off" or $d == "false"))
    and (.log_errors == "1")' >/dev/null || { echo "設定不符：$(echo "$json" | jq -c '{service,sapi,php_version,"session.upload_progress.enabled",expose_php,display_errors,log_errors}')"; return 1; }
  echo "$json" | jq -c '{service,sapi,php_version,"session.upload_progress.enabled",expose_php,display_errors,log_errors}' >> "$root/.runtime/evidence/web-php-$env_name.jsonl"
}

db_isolation() {
  local app_pw wp_pw
  app_pw="$(cat "$secrets_dir/app_db_password")"; wp_pw="$(cat "$secrets_dir/wp_db_password")"
  if ms_compose exec -T -e MYSQL_PWD="$app_pw" mysql mysql -h 127.0.0.1 -umysite_app -N -e 'SELECT COUNT(*) FROM mysite_wp.wp_users' >/dev/null 2>&1; then
    echo "Laravel 帳號可讀取 WordPress 資料庫"; return 1
  fi
  if ms_compose exec -T -e MYSQL_PWD="$wp_pw" mysql mysql -h 127.0.0.1 -umysite_wp -N -e 'SELECT COUNT(*) FROM mysite_app.migrations' >/dev/null 2>&1; then
    echo "WordPress 帳號可讀取 Laravel 資料庫"; return 1
  fi
  ms_compose exec -T -e MYSQL_PWD="$app_pw" mysql mysql -h 127.0.0.1 -umysite_app -N -e 'SELECT 1' mysite_app >/dev/null || { echo "Laravel 帳號無法存取自己的資料庫"; return 1; }
}

no_public_ports() {
  local published
  published="$(ms_compose ps --format json | jq -r '.Publishers[]? | select(.PublishedPort != 0) | "\(.URL):\(.PublishedPort)->\(.TargetPort)"' | sort -u)"
  while read -r line; do
    [[ -z "$line" ]] && continue
    [[ "$line" == 127.0.0.1:* ]] || { echo "非 loopback 發布埠：$line"; return 1; }
    case "$line" in *"->8080"|*"->8081") ;; *) echo "非預期發布埠：$line"; return 1 ;; esac
  done <<< "$published"
}

sensitive_blocked() {
  local p
  for p in /.env /.git/config /vendor/autoload.php /composer.json /composer.lock /artisan /storage/logs/laravel.log /index.php.bak /package.json; do
    local c; c="$(http_code "http://127.0.0.1:${app_port}${p}")"
    [[ "$c" == 404 || "$c" == 403 ]] || { echo "主站 $p 回 $c"; return 1; }
  done
  for p in /wp-config.php /xmlrpc.php /readme.html /wp-content/acf-json/ /.env /wp-content/debug.log /wp-comments-post.php /wp-json/wp/v2/comments; do
    local c; c="$(http_code "http://127.0.0.1:${cms_port}${p}")"
    [[ "$c" == 404 || "$c" == 403 ]] || { echo "CMS $p 回 $c"; return 1; }
  done
}

gateway_blocks_probes() {
  # 定案執行書 §4.6、FE-20：主站的敏感檔與非前端控制器 .php 探測由 gateway 直接回 404，上游（app-web）完全收不到；
  # /index.php 仍交給 app-web（Laravel 前端控制器）。以本次專屬標記比對 app-web 存取紀錄，證明不是上游回的 404。
  local mark since p c
  mark="fe20probe$(date +%s)$RANDOM"
  since="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  for p in "/${mark}.php" "/.${mark}" "/vendor/${mark}" "/${mark}.env" "/${mark}.sql"; do
    c="$(http_code "http://127.0.0.1:${app_port}${p}")"
    [[ "$c" == 404 ]] || { echo "gateway 對 ${p} 回 $c（應 404）"; return 1; }
  done
  c="$(http_code "http://127.0.0.1:${app_port}/index.php")"
  [[ "$c" == 200 ]] || { echo "/index.php 經 gateway 回 $c（應交給 Laravel 前端控制器回 200）"; return 1; }
  sleep 1
  if ms_compose logs --no-color --since "$since" app-web 2>/dev/null | grep -q "$mark"; then
    echo "探測請求到達 app-web（gateway 未直接攔截）"; return 1
  fi
}

uploads_not_executable() {
  local dir="/var/www/wp/wp-content/uploads/mysite-doctor"
  ms_compose exec -T -u www-data wp-php sh -c "mkdir -p $dir && printf '<?php echo \"EXECUTED\";' > $dir/probe.php && printf '<?php echo \"EXECUTED\";' > $dir/probe.php.jpg && printf 'GIF89a<?php echo \"EXECUTED\";' > $dir/probe.gif" || return 1
  local body code
  for f in probe.php probe.php.jpg; do
    code="$(http_code "http://127.0.0.1:${cms_port}/wp-content/uploads/mysite-doctor/$f")"
    [[ "$code" == 403 || "$code" == 404 ]] || { echo "uploads/$f 回 $code（應拒絕）"; return 1; }
  done
  body="$(curl -s --max-time 10 "http://127.0.0.1:${cms_port}/wp-content/uploads/mysite-doctor/probe.gif")"
  # 未被執行時會原樣回傳原始碼（含 <?php）；被執行則只會剩輸出文字
  [[ "$body" == *'<?php'* ]] || { echo "uploads 偽裝檔被執行（回應不含原始碼）"; return 1; }
  ms_compose exec -T -u www-data wp-php rm -rf "$dir"
}

jobs_running() {
  ms_compose exec -T wp-jobs sh -c 'test -f /tmp/mysite-wp-jobs/heartbeat && [ $(( $(date +%s) - $(cat /tmp/mysite-wp-jobs/heartbeat) )) -lt 300 ]' || { echo "wp-jobs 心跳逾時或不存在"; return 1; }
  ms_compose ps --format json app-worker | jq -e 'select(.State=="running")' >/dev/null || { echo "app-worker 未執行"; return 1; }
  ms_compose ps --format json app-scheduler | jq -e 'select(.State=="running")' >/dev/null || { echo "app-scheduler 未執行"; return 1; }
}

printf 'doctor（%s）：\n' "$env_name"
check SERVICES "全部服務執行且健康" services_healthy
check APP-LIVE "主站 /healthz" expect_code 200 "http://127.0.0.1:${app_port}/healthz"
check APP-READY "主站 /readyz（DB 就緒）" expect_code 200 "http://127.0.0.1:${app_port}/readyz"
check CMS-HEALTH "CMS site-content health（經 gateway）" expect_code 200 "http://127.0.0.1:${cms_port}/wp-json/site-content/v1/health"
check INF-01-APP "Laravel Web PHP（HTTP→Nginx→FPM）" php_settings app-web "http://127.0.0.1:8080/internal/diagnostics/php"
check INF-01-WP "WordPress Web PHP（HTTP→Nginx→FPM）" php_settings wp-web "http://127.0.0.1:8080/wp-json/site-content/v1/diagnostics"
check WP-CORE "WordPress 核心 checksum" ms_compose exec -T wp-php php /opt/mysite/verify-core.php /var/www/wp /opt/mysite/wp-core-checksums.json
check INF-02-DB "DB 帳號不可跨庫" db_isolation
check INF-02-PORTS "只發布 loopback gateway 埠" no_public_ports
check INF-03 "敏感檔不可下載" sensitive_blocked
check INF-04 "uploads 腳本不可執行" uploads_not_executable
check GW-PROBES "敏感檔與 .php 探測由 gateway 直接回 404" gateway_blocks_probes
check INF-07 "公開診斷不可經 gateway 存取" expect_code 404 -H "X-Mysite-Diagnostics-Token: ${diag_token}" "http://127.0.0.1:${app_port}/internal/diagnostics/php"
check JOBS "wp-jobs 心跳、worker、scheduler" jobs_running

out="$root/.runtime/evidence/doctor-$env_name.json"
printf '%s\n' "${results[@]}" | jq -s --arg env "$env_name" --arg commit "$(git rev-parse HEAD)" --arg at "$(date -u +%FT%TZ)" \
  '{environment:$env, source_commit:$commit, checked_at:$at, results:.}' > "$out"
if (( fail )); then
  ms_err "doctor 發現失敗項目；摘要：$out"
  exit "$EXIT_TEST_FAIL"
fi
ms_info "doctor 全部通過；摘要：$out"
exit "$EXIT_OK"
