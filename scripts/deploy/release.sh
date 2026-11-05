#!/usr/bin/env bash
# 受控換版（v5 §14.6–14.7，OPS-04／OPS-05／OPS-06）：
#   重新驗證 → flock 部署鎖 → pending 持久化（重試有上限）→ 容量 → 拉齊全部 digest →
#   部署前備份（失敗即停）→ schema 相容判斷（none／expand 自動；contract／breaking 需核准）→
#   維護模式＋暫停寫入者 → migration（Laravel migrate --force、WP 外掛 setup）→ 換版 →
#   worker／scheduler 隨新映像重啟 → 退出維護 → smoke → 記錄 active。
# 不執行清單內任何指令；只把已驗證的 digest 填入既有 Compose 契約。不執行 down -v、migrate:fresh 或 prune。
set -Eeuo pipefail
umask 027

usage() {
  cat <<'EOF'
用法：scripts/deploy/release.sh --env=staging|production --verified-envelope=<檔案> [選項]

  --approved-schema-plan=<參照>    清單 schema_change_class 為 contract／breaking 時必填，且須等於 migration_plan_ref
  --first-install-approved=<參照>  空環境首次安裝 WordPress 時必填（核准紀錄參照）
  --lock-wait=<秒>                 等待部署鎖秒數（預設 10）
  --help

規則：
  - 同序號且內容相同已成功 → no-op（exit 0）；較小序號 → 拒絕（exit 40）。
  - 同一清單失敗重試上限由主機設定 RELEASE_MAX_ATTEMPTS（預設 3）；超過需新序號清單。
  - 備份失敗、映像不齊、schema 未核准時現有服務不換版。
  - 回復清單（rollback_target_release 非空）必須改用 scripts/deploy/rollback.sh。
exit code：0 成功；10 參數錯；20 缺外部前置；30 smoke 失敗（已回到上一版）；40 拒絕／需核准；50 部署未完成或 DEPLOYMENT_UNCONFIRMED。
EOF
}

env_name=""; envelope=""; schema_plan=""; first_install_ref=""; lock_wait=10
for arg in "$@"; do
  case "$arg" in
    --env=*) env_name="${arg#--env=}" ;;
    --verified-envelope=*) envelope="${arg#--verified-envelope=}" ;;
    --approved-schema-plan=*) schema_plan="${arg#--approved-schema-plan=}" ;;
    --first-install-approved=*) first_install_ref="${arg#--first-install-approved=}" ;;
    --lock-wait=*) lock_wait="${arg#--lock-wait=}" ;;
    -h|--help) usage; exit 0 ;;
    *) printf '未知參數：%s\n' "$arg" >&2; usage >&2; exit 10 ;;
  esac
done

# shellcheck source=../lib/deploy.sh
source "$(dirname "${BASH_SOURCE[0]}")/../lib/deploy.sh"
MD_SCRIPT_NAME=release
[[ -n "$env_name" && -n "$envelope" ]] || { usage >&2; exit "$EXIT_USAGE"; }
ms_require_env_enum "$env_name" staging production
[[ "$lock_wait" =~ ^[0-9]{1,4}$ ]] || ms_die "$EXIT_USAGE" "--lock-wait 必須是整數秒"
[[ -z "$schema_plan" || "$schema_plan" =~ $MD_RE_PLAN_REF ]] || ms_die "$EXIT_USAGE" "--approved-schema-plan 格式不合法"
[[ -z "$first_install_ref" || "$first_install_ref" =~ $MD_RE_PLAN_REF ]] || ms_die "$EXIT_USAGE" "--first-install-approved 格式不合法"
[[ -f "$envelope" && ! -L "$envelope" ]] || ms_die "$EXIT_USAGE" "envelope 必須是存在的一般檔案"
md_require_cmd python3 openssl flock docker
md_resolve_host_root
md_env_paths "$env_name"
md_load_config "$env_name"
md_require_hosts
MD_PROJECT="$(md_project_name "$env_name")"

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# ---------------------------------------------------------------------------
# 1. 重新驗證（不信任呼叫者已驗證的說法）
# ---------------------------------------------------------------------------
verify_or_exit() {
  local rc=0
  md_verify_envelope "$envelope" "$work" || rc=$?
  case "$rc" in
    0) ;;
    3) ms_info "NOOP：$(md_verify_message "$work/verify.json")"; exit "$EXIT_OK" ;;
    4) md_event ERROR release_rejected "$(md_verify_message "$work/verify.json")"; exit "$EXIT_FORBIDDEN" ;;
    5) ms_die "$EXIT_EXTERNAL" "驗證環境缺件（openssl、信任目錄或狀態檔）" ;;
    *) ms_die "$EXIT_USAGE" "驗證工具參數錯誤（rc=$rc）" ;;
  esac
  md_read_release_env "$work/release.env"
}
verify_or_exit
[[ -z "${MD_REL[RELEASE_ROLLBACK_TARGET]:-}" ]] || ms_die "$EXIT_USAGE" "這是回復清單；請改用 scripts/deploy/rollback.sh"

# ---------------------------------------------------------------------------
# 2. 部署鎖（同一環境只允許一個部署者；OPS-05）
# ---------------------------------------------------------------------------
md_lock "$MD_STATE_DIR/deploy.lock" "$lock_wait" || {
  md_event WARN lock_busy "另一個部署程序持有鎖；本次不執行"
  ms_die "$EXIT_DEPLOY_INCOMPLETE" "LOCK_BUSY：部署鎖被占用"
}
verify_or_exit   # 取得鎖後以最新主機狀態再驗一次（避免競態）
seq="${MD_REL[RELEASE_SEQUENCE]}"; rid="${MD_REL[RELEASE_ID]}"; cls="${MD_REL[RELEASE_SCHEMA_CHANGE_CLASS]}"

# ---------------------------------------------------------------------------
# 3. pending 持久化（同一清單重試有上限）
# ---------------------------------------------------------------------------
rc=0
md_manifest state-begin --state-dir "$MD_STATE_DIR" --environment "$env_name" --payload "$work/payload.json" \
  --max-attempts "${MD_CFG[RELEASE_MAX_ATTEMPTS]}" --kind release > "$work/begin.json" || rc=$?
case "$rc" in
  0) ;;
  6) md_event ERROR retry_limit "$(md_verify_message "$work/begin.json")"; ms_die "$EXIT_DEPLOY_INCOMPLETE" "RETRY_LIMIT_EXCEEDED：序號 ${seq} 已達重試上限，需要新序號清單" ;;
  4) ms_die "$EXIT_FORBIDDEN" "$(md_verify_message "$work/begin.json")" ;;
  *) ms_die "$EXIT_EXTERNAL" "無法寫入主機發布狀態（rc=$rc）" ;;
esac
maintenance_on=0; writers_stopped=0; switched=0
current_env="$MD_COMPOSE_ENV"; next_env="$MD_STATE_DIR/compose.env.next"; prev_env="$MD_STATE_DIR/compose.env.prev"

restore_old_service() {
  # 未換版前失敗：讓舊版恢復服務（只有原本就在執行時才動作）
  [[ -f "$current_env" ]] || return 0
  if (( writers_stopped )); then md_compose start app-worker app-scheduler wp-jobs >/dev/null 2>&1 || ms_warn "寫入者重啟失敗，請人工檢查"; fi
  if (( maintenance_on )); then md_compose exec -T app-php php artisan up >/dev/null 2>&1 || ms_warn "artisan up 失敗，請人工檢查"; fi
}

fail() {
  # fail <代碼> <exit> <訊息>：記錄失敗並結束（pending 保留，供有上限的重試）
  local code="$1" ex="$2" msg="$3"
  trap - ERR
  md_state_step --step "$code" --status failed --reason "$code" || true
  md_event ERROR "release_failed" "${code}：${msg}"
  ms_die "$ex" "${code}：${msg}"
}
on_error() {
  local rc="$1" line="$2"
  trap - ERR
  (( switched )) || restore_old_service
  fail UNEXPECTED_ERROR "$EXIT_DEPLOY_INCOMPLETE" "第 ${line} 行意外失敗（rc=${rc}）；請看 events.jsonl"
}
trap 'on_error $? $LINENO' ERR
install -m 0640 "$envelope" "$MD_RELEASES_DIR/seq-${seq}.envelope.json"
install -m 0640 "$work/payload.json" "$MD_RELEASES_DIR/seq-${seq}.payload.json"
md_event INFO release_begin "開始部署 ${rid}（sequence=${seq}，schema=${cls}）"

# ---------------------------------------------------------------------------
# 4. 容量與 Docker
# ---------------------------------------------------------------------------
md_state_step --step capacity
md_docker_ok || fail DOCKER_UNAVAILABLE "$EXIT_EXTERNAL" "Docker daemon 不可用"
min_mb="${MD_CFG[MIN_FREE_DISK_MB]}"
docker_root="$(docker info --format '{{.DockerRootDir}}' 2>/dev/null || true)"
for d in "$MD_HOST_ROOT" "${docker_root:-$MD_HOST_ROOT}"; do
  free="$(md_free_mb "$d")"
  [[ "$free" =~ ^[0-9]+$ ]] || fail CAPACITY_UNKNOWN "$EXIT_EXTERNAL" "無法取得 $d 的可用空間"
  (( free >= min_mb )) || fail INSUFFICIENT_DISK "$EXIT_EXTERNAL" "$d 可用 ${free} MB，低於 ${min_mb} MB"
done
if [[ "${MD_CFG[MYSITE_GATEWAY_MODE]}" == https ]]; then
  for h in "${MD_CFG[APP_HOST]}" "${MD_CFG[CMS_HOST]}"; do
    [[ -s "$MD_TLS_DIR/letsencrypt/live/$h/fullchain.pem" && -s "$MD_TLS_DIR/letsencrypt/live/$h/privkey.pem" ]] \
      || fail TLS_NOT_READY "$EXIT_EXTERNAL" "https 模式但缺少 $h 的憑證；請先完成 bootstrap-host.sh --issue-tls"
  done
fi

# ---------------------------------------------------------------------------
# 5. 拉齊全部映像並核對 digest（任何一個失敗，現有服務不動）
# ---------------------------------------------------------------------------
md_state_step --step pull
if [[ "${MD_CFG[GHCR_AUTH]}" == token ]]; then
  md_secret_ok ghcr_pull_token || fail GHCR_TOKEN_MISSING "$EXIT_EXTERNAL" "GHCR_AUTH=token 但缺 secrets/ghcr_pull_token"
  timeout 60 docker login ghcr.io -u "${MD_CFG[GHCR_USERNAME]}" --password-stdin < "$MD_SECRETS_DIR/ghcr_pull_token" >/dev/null 2>&1 \
    || fail GHCR_LOGIN_FAILED "$EXIT_EXTERNAL" "GHCR 唯讀登入失敗"
fi
mysql_ref="${MD_CFG[BASE_IMAGE_REGISTRY]}/$(md_version_value MYSQL_IMAGE_REF)"
for key in MYSITE_IMAGE_LARAVEL MYSITE_IMAGE_WORDPRESS MYSITE_IMAGE_APP_WEB MYSITE_IMAGE_WP_WEB MYSITE_IMAGE_GATEWAY; do
  ref="${MD_REL[$key]}"
  timeout 900 docker pull --quiet "$ref" >/dev/null 2>&1 || fail IMAGE_PULL_FAILED "$EXIT_DEPLOY_INCOMPLETE" "無法拉取 ${ref%@*}"
  md_image_digest_ok "$ref" || fail IMAGE_DIGEST_MISMATCH "$EXIT_FORBIDDEN" "本機映像 digest 與清單不符：${ref%@*}"
done
timeout 900 docker pull --quiet "$mysql_ref" >/dev/null 2>&1 || fail IMAGE_PULL_FAILED "$EXIT_DEPLOY_INCOMPLETE" "無法拉取平台 MySQL 映像"

# ---------------------------------------------------------------------------
# 6. 部署前備份（失敗即停）；確實空環境才記錄初次安裝證據
# ---------------------------------------------------------------------------
md_state_step --step backup
active_rid="$(md_state_get active.release_id)"
first_install=0
if [[ -z "$active_rid" ]] && ! md_volume_exists mysql-data && ! md_volume_exists wp-uploads; then
  first_install=1
  python3 -I -c 'import json,sys; json.dump({"environment":sys.argv[1],"sequence":int(sys.argv[2]),"release_id":sys.argv[3],"recorded_at":sys.argv[4],"evidence":"no active release and no mysql-data/wp-uploads volumes","backup":"not_applicable_empty_environment"},open(sys.argv[5],"w"),ensure_ascii=False,indent=2)' \
    "$env_name" "$seq" "$rid" "$(md_now)" "$MD_RELEASES_DIR/seq-${seq}.first-install.json"
  md_event INFO first_install "空環境首次發布：無既有資料卷，以空環境證據代替部署前備份"
else
  bash "$MD_PLATFORM_DIR/scripts/ops/backup.sh" --env="$env_name" --reason="pre-release-seq-${seq}" --scope=all \
    || fail BACKUP_FAILED "$EXIT_DEPLOY_INCOMPLETE" "部署前備份失敗，停止換版（現有服務未變動）"
fi

# ---------------------------------------------------------------------------
# 7. schema 相容判斷（只允許 none／expand 自動進行）
# ---------------------------------------------------------------------------
md_state_step --step schema
case "$cls" in
  none|expand) ;;
  contract|breaking)
    if [[ -z "$schema_plan" || "$schema_plan" != "${MD_REL[RELEASE_MIGRATION_PLAN_REF]}" ]]; then
      trap - ERR
      md_state_step --step schema --status awaiting_approval --reason SCHEMA_APPROVAL_REQUIRED || true
      md_event ERROR schema_approval_required "schema_change_class=${cls}：需要與 migration_plan_ref 相符的 --approved-schema-plan；現有服務未換版"
      ms_die "$EXIT_FORBIDDEN" "SCHEMA_APPROVAL_REQUIRED：${cls} 變更未核准，停止（未換版）"
    fi
    md_event WARN schema_approved "${cls} 變更已核准（${schema_plan}），將在維護模式下執行"
    ;;
  *) fail INVALID_SCHEMA_CLASS "$EXIT_FORBIDDEN" "未知 schema 分類" ;;
esac
if (( first_install )) && [[ -z "$first_install_ref" ]]; then
  trap - ERR
  md_state_step --step first_install --status awaiting_approval --reason FIRST_INSTALL_APPROVAL_REQUIRED || true
  md_event ERROR first_install_approval_required "空環境首次安裝需要 --first-install-approved=<核准參照>；未做任何變更"
  ms_die "$EXIT_FORBIDDEN" "FIRST_INSTALL_APPROVAL_REQUIRED：空環境首次安裝需要 --first-install-approved=<核准參照>"
fi

# ---------------------------------------------------------------------------
# 8. 暫停寫入者；contract／breaking 另進維護模式（none／expand 向後相容，舊版可在 migration 期間繼續服務讀取）
# ---------------------------------------------------------------------------
md_state_step --step maintenance
md_write_compose_env "$next_env"
if [[ -f "$current_env" && -n "$(md_container app-php)" ]]; then
  if [[ "$cls" == contract || "$cls" == breaking ]]; then
    md_compose exec -T app-php php artisan down --retry=60 >/dev/null || fail MAINTENANCE_FAILED "$EXIT_DEPLOY_INCOMPLETE" "無法進入維護模式"
    maintenance_on=1
  fi
  md_compose stop app-worker app-scheduler wp-jobs >/dev/null || { restore_old_service; fail WRITER_STOP_FAILED "$EXIT_DEPLOY_INCOMPLETE" "無法暫停寫入者"; }
  writers_stopped=1
fi
# 若尚有首次 HTTP 驗證用的 edge-bootstrap 容器，換版前移除（只動固定名稱容器）
if docker ps -a -q --filter "name=^mysite-edge-bootstrap$" 2>/dev/null | grep -q .; then
  docker rm -f mysite-edge-bootstrap >/dev/null || fail EDGE_BOOTSTRAP_STOP_FAILED "$EXIT_DEPLOY_INCOMPLETE" "無法停止 edge-bootstrap"
fi

# ---------------------------------------------------------------------------
# 9. migration（用新映像、一次性容器；不 fresh、不 rollback）
# ---------------------------------------------------------------------------
md_state_step --step migrate
md_next() { MD_COMPOSE_ENV_OVERRIDE="$next_env" md_compose "$@"; }
md_next up -d --wait --wait-timeout 300 mysql >/dev/null || { restore_old_service; fail MYSQL_NOT_READY "$EXIT_DEPLOY_INCOMPLETE" "MySQL 未就緒"; }
if ! md_next run --rm --no-deps -T -e MYSITE_ROLE=cli app-php php artisan migrate --force --no-interaction; then
  if [[ "$cls" == none || "$cls" == expand ]]; then restore_old_service; fi
  fail MIGRATION_FAILED "$EXIT_DEPLOY_INCOMPLETE" "Laravel migration 失敗；$( [[ "$cls" == none || "$cls" == expand ]] && echo '已讓舊版恢復服務' || echo '維持維護模式待人工處理')"
fi
wp_run() { md_next run --rm --no-deps -T -u www-data wp-php wp "$@"; }
if ! wp_run core is-installed >/dev/null 2>&1; then
  (( first_install )) || { restore_old_service; fail WP_NOT_INSTALLED "$EXIT_DEPLOY_INCOMPLETE" "既有環境的 WordPress 未安裝（資料異常），停止"; }
  [[ "${MD_CFG[ADMIN_EMAIL]}" =~ @ ]] || fail ADMIN_EMAIL_MISSING "$EXIT_USAGE" "首次安裝需要主機設定 ADMIN_EMAIL"
  md_secret_ok wp_admin_password || fail WP_ADMIN_SECRET_MISSING "$EXIT_EXTERNAL" "首次安裝需要 secrets/wp_admin_password"
  wp_run core install --url="https://${MD_CFG[CMS_HOST]}" --title="${MD_CFG[SITE_TITLE]}" --admin_user="${MD_CFG[ADMIN_USERNAME]}" \
    --admin_email="${MD_CFG[ADMIN_EMAIL]}" --skip-email --locale=zh_TW --prompt=admin_password \
    < "$MD_SECRETS_DIR/wp_admin_password" >/dev/null || fail WP_INSTALL_FAILED "$EXIT_DEPLOY_INCOMPLETE" "WordPress 首次安裝失敗"
  md_event INFO wp_first_install "WordPress 首次安裝完成（核准參照：${first_install_ref}；管理員密碼只在秘密檔）"
fi
{ wp_run plugin activate advanced-custom-fields two-factor site-content --quiet \
  && wp_run core update-db --quiet && wp_run site-content setup; } >/dev/null \
  || { [[ "$cls" == none || "$cls" == expand ]] && restore_old_service; fail WP_SETUP_FAILED "$EXIT_DEPLOY_INCOMPLETE" "WordPress 外掛 setup 失敗"; }
if grep -q '^PENDING-BOOTSTRAP-' "$MD_SECRETS_DIR/wp_service_app_password" 2>/dev/null; then
  # Laravel 讀 WP 私有 API 的服務帳號：只在首次建立；值直接寫入秘密檔，不經終端或 log
  if ! wp_run user get mysite-service --field=ID >/dev/null 2>&1; then
    wp_run user create mysite-service service@mysite.invalid --role=mysite_service --display_name="mysite 服務帳號" \
      --porcelain --prompt=user_pass < <(openssl rand -hex 32) >/dev/null || fail WP_SERVICE_USER_FAILED "$EXIT_DEPLOY_INCOMPLETE" "無法建立 WP 服務帳號"
  fi
  wp_run user application-password create mysite-service mysite-laravel --porcelain | tr -d '\r\n ' \
    | md_write_secret wp_service_app_password || fail WP_SERVICE_PASSWORD_FAILED "$EXIT_DEPLOY_INCOMPLETE" "無法發行 WP 服務帳號 Application Password"
  md_event INFO wp_service_password "已發行 WP 服務帳號 Application Password（只寫入秘密檔）"
fi
if (( first_install )); then
  { wp_run option update blog_public 0 --quiet && wp_run option update timezone_string Asia/Taipei --quiet \
    && wp_run rewrite structure '/%postname%/' --quiet; } >/dev/null || fail WP_SETUP_FAILED "$EXIT_DEPLOY_INCOMPLETE" "WordPress 初始設定失敗"
fi

# ---------------------------------------------------------------------------
# 10. 換版（所有服務以新 digest 重建；worker／scheduler 隨之重啟，scheduler 仍只有一個）
# ---------------------------------------------------------------------------
md_state_step --step switch
[[ -f "$current_env" ]] && cp -p "$current_env" "$prev_env"
mv -f "$next_env" "$current_env"
switched=1

revert_to_previous() {
  # 換版後失敗：schema 為 none／expand 且有上一版時，回到上一版程式（不動資料）
  [[ -f "$prev_env" && ( "$cls" == none || "$cls" == expand ) ]] || return 1
  cp -p "$current_env" "$MD_STATE_DIR/compose.env.failed-seq-${seq}"
  cp -p "$prev_env" "$current_env"
  md_compose up -d --wait --wait-timeout 300 >/dev/null 2>&1 || return 1
  md_compose exec -T app-php php artisan up >/dev/null 2>&1 || return 1
  return 0
}

# 先以新映像重建 app-php 並解除維護（維護中 app-web 的 /healthz 會回 503，gateway 依賴其健康狀態），再等全部服務就緒
switch_failed() {
  if revert_to_previous; then fail SWITCH_FAILED_REVERTED "$EXIT_DEPLOY_INCOMPLETE" "新版服務未就緒，已回到上一版程式（資料未倒帶）"; fi
  fail SWITCH_FAILED_HOLD "$EXIT_DEPLOY_INCOMPLETE" "新版服務未就緒且無法自動回到上一版；維持現場待人工處理"
}
md_compose up -d --no-deps app-php >/dev/null || switch_failed
ms_wait_until 120 2 md_compose exec -T app-php php -r 'exit(0);' || switch_failed
md_compose exec -T app-php php artisan up >/dev/null || fail MAINTENANCE_OFF_FAILED "$EXIT_DEPLOY_INCOMPLETE" "無法退出維護模式"
maintenance_on=0
md_compose up -d --wait --wait-timeout 300 >/dev/null || switch_failed

# ---------------------------------------------------------------------------
# 11. smoke（release ID＋HTTPS）
# ---------------------------------------------------------------------------
md_state_step --step smoke
trap - ERR
src=0
bash "$MD_PLATFORM_DIR/scripts/deploy/smoke.sh" --env="$env_name" --expected-release="$rid" > "$MD_RELEASES_DIR/seq-${seq}.smoke.json" || src=$?
case "$src" in
  0)
    md_manifest state-activate --state-dir "$MD_STATE_DIR" --environment "$env_name" --confirmation confirmed
    md_event INFO release_active "release ${rid}（sequence=${seq}）已啟用並確認"
    ms_info "部署完成：${rid}（sequence=${seq}）"
    exit "$EXIT_OK" ;;
  50)
    md_manifest state-activate --state-dir "$MD_STATE_DIR" --environment "$env_name" --confirmation unconfirmed
    md_event WARN deployment_unconfirmed "已換版但無法確認（DEPLOYMENT_UNCONFIRMED）；不記為成功，請人工核對"
    ms_die "$EXIT_DEPLOY_INCOMPLETE" "DEPLOYMENT_UNCONFIRMED：${rid} 已換上但未能確認" ;;
  *)
    if revert_to_previous; then
      fail SMOKE_FAILED_REVERTED "$EXIT_TEST_FAIL" "smoke 失敗（rc=${src}），已回到上一版程式（資料未倒帶）"
    fi
    md_compose exec -T app-php php artisan down --retry=60 >/dev/null 2>&1 || true
    fail SMOKE_FAILED_HOLD "$EXIT_DEPLOY_INCOMPLETE" "smoke 失敗且無法自動回到上一版；已進維護模式待人工處理" ;;
esac
