#!/usr/bin/env bash
# 受控秘密輪替（v5 §13.7、附錄 D.3）：明確核准參照、雙期過渡、失敗可回復；新值只寫入秘密檔，從不輸出。
#   app-db／wp-db  MySQL 雙密碼：ALTER USER … RETAIN CURRENT PASSWORD → 換秘密檔 → 重建相關容器 → 驗證 → DISCARD OLD PASSWORD
#   wp-salts       換 salts → 重建 WP 容器 → 驗證（所有 WP 登入失效，需事先公告）
#   wp-service     先發行新 Application Password → 換秘密檔 → 重建 Laravel → 以新密碼實測 → 刪除舊密碼
#   event-hmac／preview  金鑰圈三期：add（新 key 只被接受）→ promote（新 key 簽章、舊 key 仍接受）→ retire（撤舊）
#   app-key        add（新 APP_KEY，舊 key 移入 app_previous_keys）→ retire（移除舊 key）
set -Eeuo pipefail
umask 077

usage() {
  cat <<'EOF'
用法：scripts/ops/rotate-secrets.sh --env=staging|production|development|testing --kind=<種類> --approved-plan=<參照> [選項]

  --kind=app-db|wp-db|wp-salts|wp-service|event-hmac|preview|app-key
  --phase=add|promote|retire|revert   event-hmac／preview 用 add→promote→retire；app-key 用 add→retire；
                                     revert 把該種類還原到最近一次輪替前（只限尚未 retire 的雙期狀態與 wp-salts）
  --allow-development                --env=development（.runtime/secrets/development、project mysite-dev）時必填
  --allow-testing                    --env=testing（.runtime/secrets/testing、project mysite-test）時必填
  --help

前提：event-hmac／preview 需主機設定 KEYRING_SUPPORT=yes；app-key 需 APP_PREVIOUS_KEYS_SUPPORT=yes（兩者預設 yes：
應用程式已支援金鑰圈與 APP_PREVIOUS_KEYS_FILE；仍在跑舊版發布的主機請設 no，輪替會以 exit 20 拒絕）。
每期重建容器後，以 `php artisan mysite:keyring`（Laravel）與 `wp site-content keyring`（WordPress）確認
兩端實際載入的金鑰圈與秘密檔一致；不一致即視為驗證失敗並回到上一階段。
event-hmac／preview：promote 之後至少 15 分鐘（預覽票券硬上限 900 秒）且 24 小時內（暫定）必須 retire。
新值只寫入秘密檔（0640、群組 33），不印出、不寫 log；紀錄只含種類、階段、時間與結果（state/rotations.jsonl）。
exit code：0 成功；10 參數錯；20 缺前置（應用未支援、容器未執行）；40 未授權；50 輪替未完成（已嘗試回復，結果見訊息）。
EOF
}

env_name=""; kind=""; plan=""; phase=""; allow_dev=0; allow_test=0
for arg in "$@"; do
  case "$arg" in
    --env=*) env_name="${arg#--env=}" ;;
    --kind=*) kind="${arg#--kind=}" ;;
    --approved-plan=*) plan="${arg#--approved-plan=}" ;;
    --phase=*) phase="${arg#--phase=}" ;;
    --allow-development) allow_dev=1 ;;
    --allow-testing) allow_test=1 ;;
    -h|--help) usage; exit 0 ;;
    *) printf '未知參數：%s\n' "$arg" >&2; usage >&2; exit 10 ;;
  esac
done

# shellcheck source=../lib/deploy.sh
source "$(dirname "${BASH_SOURCE[0]}")/../lib/deploy.sh"
MD_SCRIPT_NAME=rotate-secrets
[[ -n "$env_name" && -n "$kind" ]] || { usage >&2; exit "$EXIT_USAGE"; }
ms_require_env_enum "$env_name" staging production development testing
case "$kind" in app-db|wp-db|wp-salts|wp-service|event-hmac|preview|app-key) ;; *) ms_die "$EXIT_USAGE" "未知 --kind：$kind" ;; esac
[[ -n "$plan" ]] || ms_die "$EXIT_FORBIDDEN" "缺少 --approved-plan：秘密輪替必須有核准參照"
[[ "$plan" =~ $MD_RE_PLAN_REF ]] || ms_die "$EXIT_USAGE" "--approved-plan 格式不合法"
case "$kind" in
  event-hmac|preview) case "$phase" in add|promote|retire|revert) ;; *) ms_die "$EXIT_USAGE" "$kind 需要 --phase=add|promote|retire|revert" ;; esac ;;
  app-key) case "$phase" in add|retire|revert) ;; *) ms_die "$EXIT_USAGE" "app-key 需要 --phase=add|retire|revert" ;; esac ;;
  wp-salts) case "$phase" in ""|revert) ;; *) ms_die "$EXIT_USAGE" "wp-salts 只接受 --phase=revert（或省略）" ;; esac ;;
  *) [[ -z "$phase" ]] || ms_die "$EXIT_USAGE" "$kind 不使用 --phase（內部已分段並自動回復）" ;;
esac
md_require_cmd python3 openssl flock docker

if [[ "$env_name" == development || "$env_name" == testing ]]; then
  if [[ "$env_name" == development ]]; then
    (( allow_dev )) || ms_die "$EXIT_FORBIDDEN" "development 輪替必須明確加上 --allow-development"
  else
    (( allow_test )) || ms_die "$EXIT_FORBIDDEN" "testing 輪替必須明確加上 --allow-testing"
  fi
  # shellcheck source=../lib/compose.sh
  source "$MD_LIB_DIR/compose.sh"
  ms_load_versions "$MD_PLATFORM_DIR"
  MD_ENV="$env_name"
  MD_SECRETS_DIR="$MD_PLATFORM_DIR/.runtime/secrets/$env_name"
  MD_STATE_DIR="$MD_PLATFORM_DIR/.runtime/state/$env_name"
  MD_PROJECT="$(ms_compose_project "$env_name")"
  # 雲端環境使用本 repo 的應用程式碼（已支援金鑰圈與 APP_PREVIOUS_KEYS_FILE），沿用預設 yes；
  # 映像過舊時由下方的「實際載入」驗證擋下並回到上一階段
  md_cfg_defaults "$env_name"
  ms_compose_prepare "$MD_PLATFORM_DIR" "$env_name"
  rs_compose() { ms_compose "$@"; }
else
  md_resolve_host_root
  md_env_paths "$env_name"
  md_load_config "$env_name"
  MD_PROJECT="$(md_project_name "$env_name")"
  rs_compose() { md_compose "$@"; }
fi
[[ -d "$MD_SECRETS_DIR" ]] || ms_die "$EXIT_EXTERNAL" "秘密目錄不存在"
mkdir -p "$MD_STATE_DIR"
md_docker_ok || ms_die "$EXIT_EXTERNAL" "Docker daemon 不可用"

# 與部署互斥：輪替期間不得換版
md_lock "$MD_STATE_DIR/deploy.lock" 10 || ms_die "$EXIT_DEPLOY_INCOMPLETE" "LOCK_BUSY：部署或其他輪替進行中"

rot_dir="$MD_SECRETS_DIR/.rotation"
mkdir -p "$rot_dir" && chmod 0700 "$rot_dir"
record() {
  python3 -I -c 'import json,sys,datetime
rec={"at":datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),"environment":sys.argv[1],"kind":sys.argv[2],
     "phase":sys.argv[3] or None,"approved_plan":sys.argv[4],"result":sys.argv[5],"detail":sys.argv[6]}
open(sys.argv[7],"a",encoding="utf-8").write(json.dumps(rec,ensure_ascii=False,sort_keys=True)+"\n")' \
    "$env_name" "$kind" "$phase" "$plan" "$1" "$2" "$MD_STATE_DIR/rotations.jsonl"
  md_event "$([[ "$1" == SUCCESS ]] && echo INFO || echo ERROR)" secret_rotation "${kind}${phase:+/$phase}：$1 $2"
}
finish_fail() { record "$1" "$2"; ms_die "$3" "$1：$2"; }

save_prev() { install -m 0600 "$MD_SECRETS_DIR/$1" "$rot_dir/$1.prev"; }
restore_prev() { if [[ -f "$rot_dir/$1.prev" ]]; then md_write_secret "$1" < "$rot_dir/$1.prev"; fi; }
drop_prev() { rm -f -- "$rot_dir/$1.prev"; }

recreate() {
  # 秘密以檔案掛載，替換檔案後必須重建容器才會讀到新內容
  rs_compose up -d --no-deps --force-recreate --wait --wait-timeout 180 "$@" >/dev/null 2>&1
}
mysql_cid() { local c; c="$(md_container mysql)"; [[ -n "$c" ]] || ms_die "$EXIT_EXTERNAL" "MySQL 容器未執行"; printf '%s\n' "$c"; }
root_sql() {
  # SQL 只經 stdin；root 密碼由容器內秘密檔讀取
  timeout 60 docker exec -i "$(mysql_cid)" sh -c 'MYSQL_PWD="$(cat /run/secrets/mysql_root_password)" exec mysql -h 127.0.0.1 -uroot' >/dev/null 2>&1
}
login_ok() {
  # login_ok <使用者> <秘密檔>：以檔案內容實際登入（密碼經 stdin 傳入容器）
  timeout 60 docker exec -i "$(mysql_cid)" sh -c 'MYSQL_PWD="$(cat)" exec mysql -h 127.0.0.1 -u "$1" -e "SELECT 1"' sh "$1" < "$2" >/dev/null 2>&1
}
app_ok() { rs_compose exec -T app-php php artisan migrate:status --no-interaction >/dev/null 2>&1; }
wp_ok() { rs_compose exec -T -u www-data wp-php wp core is-installed >/dev/null 2>&1; }
new_value_file() { local f; f="$(mktemp "$rot_dir/.new.XXXXXX")"; "$@" > "$f"; printf '%s\n' "$f"; }
keyring_loaded() {
  # keyring_loaded <event-hmac|preview> <金鑰圈檔>：Laravel（app-php）與 WordPress（wp-php、wp-jobs）實際載入的
  # key ID／狀態必須與秘密檔一致（只比對 `<key_id> <state>`，不讀也不輸出金鑰）
  local kind="$1" want got svc
  want="$(awk 'NF == 3 { print $1 " " $3 }' "$2" | sort)"
  [[ -n "$want" ]] || return 1
  got="$(rs_compose exec -T app-php php artisan mysite:keyring "$kind" --no-ansi 2>/dev/null | tr -d '\r' | awk 'NF' | sort)" || return 1
  [[ "$got" == "$want" ]] || { ms_warn "Laravel 載入的 ${kind} 金鑰圈與秘密檔不一致（映像過舊或容器未重建）"; return 1; }
  for svc in wp-php wp-jobs; do
    got="$(rs_compose exec -T -u www-data "$svc" wp site-content keyring "$kind" 2>/dev/null | tr -d '\r' | awk 'NF' | sort)" || return 1
    [[ "$got" == "$want" ]] || { ms_warn "WordPress（${svc}）載入的 ${kind} 金鑰圈與秘密檔不一致（映像過舊或容器未重建）"; return 1; }
  done
}
previous_keys_loaded() {
  # Laravel 實際載入的舊 APP_KEY 數量必須等於 app_previous_keys 的非空行數（不輸出金鑰）
  local want got
  want="$(tr ',' '\n' < "$MD_SECRETS_DIR/app_previous_keys" 2>/dev/null | awk 'NF' | wc -l | tr -d ' ')"
  got="$(rs_compose exec -T app-php php artisan mysite:keyring app-key --no-ansi 2>/dev/null | tr -d '\r' | awk '$1 == "previous_keys" { print $2 }')" || return 1
  [[ "$got" == "$want" ]] || { ms_warn "Laravel 載入的舊 APP_KEY 數量與秘密檔不一致（映像過舊或容器未重建）"; return 1; }
}

# ---------------------------------------------------------------------------
case "$kind" in
app-db|wp-db)
  if [[ "$kind" == app-db ]]; then user=mysite_app; secret=app_db_password; services=(app-php app-worker app-scheduler); check=app_ok
  else user=mysite_wp; secret=wp_db_password; services=(wp-php wp-jobs); check=wp_ok; fi
  md_secret_ok "$secret" || finish_fail PRECHECK_FAILED "目前秘密檔不存在" "$EXIT_EXTERNAL"
  login_ok "$user" "$MD_SECRETS_DIR/$secret" || finish_fail PRECHECK_FAILED "目前密碼無法登入，拒絕在不一致狀態下輪替" "$EXIT_EXTERNAL"
  newf="$(new_value_file md_rand_alnum 40)"
  [[ "$(cat "$newf")" =~ ^[A-Za-z0-9]{40}$ ]] || finish_fail GENERATE_FAILED "新密碼產生失敗" "$EXIT_DEPLOY_INCOMPLETE"
  # 第一期：新密碼成為主要密碼、舊密碼保留為次要（兩者都可登入）
  { printf "ALTER USER '%s'@'%%' IDENTIFIED BY '%s' RETAIN CURRENT PASSWORD;\n" "$user" "$(cat "$newf")"; } | root_sql \
    || { rm -f -- "$newf"; finish_fail ALTER_FAILED "ALTER USER（RETAIN CURRENT PASSWORD）失敗；未做任何變更" "$EXIT_DEPLOY_INCOMPLETE"; }
  save_prev "$secret"
  md_write_secret "$secret" < "$newf"
  if recreate "${services[@]}" && login_ok "$user" "$MD_SECRETS_DIR/$secret" && "$check"; then
    printf "ALTER USER '%s'@'%%' DISCARD OLD PASSWORD;\n" "$user" | root_sql \
      || { rm -f -- "$newf"; finish_fail DISCARD_FAILED "新密碼已生效，但撤銷舊密碼失敗；請人工執行 DISCARD OLD PASSWORD" "$EXIT_DEPLOY_INCOMPLETE"; }
    rm -f -- "$newf"; drop_prev "$secret"
    record SUCCESS "新密碼已生效並撤銷舊密碼"
  else
    # 回復：舊密碼回到主要並撤銷新密碼，秘密檔與容器回到原狀
    { printf "ALTER USER '%s'@'%%' IDENTIFIED BY '%s';\n" "$user" "$(cat "$rot_dir/$secret.prev")"
      printf "ALTER USER '%s'@'%%' DISCARD OLD PASSWORD;\n" "$user"; } | root_sql || ms_err "回復 ALTER USER 失敗"
    restore_prev "$secret"; rm -f -- "$newf"
    if recreate "${services[@]}" && login_ok "$user" "$MD_SECRETS_DIR/$secret"; then
      drop_prev "$secret"
      finish_fail VERIFY_FAILED_REVERTED "新密碼驗證失敗，已回到原密碼" "$EXIT_DEPLOY_INCOMPLETE"
    fi
    finish_fail VERIFY_FAILED_REVERT_FAILED "新密碼驗證失敗且回復未完成；舊值保存在 secrets/.rotation，請依秘密輪替手冊處理" "$EXIT_DEPLOY_INCOMPLETE"
  fi
  ;;

wp-salts)
  services=(wp-php wp-jobs)
  if [[ "$phase" == revert ]]; then
    [[ -f "$rot_dir/wp_salts.prev" ]] || finish_fail NOTHING_TO_REVERT "沒有可回復的上一組 salts" "$EXIT_USAGE"
    restore_prev wp_salts
    recreate "${services[@]}" && wp_ok || finish_fail REVERT_FAILED "salts 回復後 WordPress 未就緒" "$EXIT_DEPLOY_INCOMPLETE"
    drop_prev wp_salts; record SUCCESS "已回復上一組 salts"; exit "$EXIT_OK"
  fi
  salts() { local n; for n in AUTH_KEY SECURE_AUTH_KEY LOGGED_IN_KEY NONCE_KEY AUTH_SALT SECURE_AUTH_SALT LOGGED_IN_SALT NONCE_SALT; do printf '%s=%s\n' "$n" "$(md_rand_alnum 64)"; done; }
  newf="$(new_value_file salts)"
  save_prev wp_salts
  md_write_secret wp_salts < "$newf"; rm -f -- "$newf"
  if recreate "${services[@]}" && wp_ok; then
    record SUCCESS "新 salts 已生效（所有 WP 登入需重新登入）；上一組保留於 .rotation 供 --phase=revert"
  else
    restore_prev wp_salts
    recreate "${services[@]}" && wp_ok && finish_fail VERIFY_FAILED_REVERTED "新 salts 驗證失敗，已回到原 salts" "$EXIT_DEPLOY_INCOMPLETE"
    finish_fail VERIFY_FAILED_REVERT_FAILED "新 salts 驗證失敗且回復未完成" "$EXIT_DEPLOY_INCOMPLETE"
  fi
  ;;

wp-service)
  wpc() { rs_compose exec -T -u www-data wp-php wp "$@"; }
  old_uuids="$(wpc user application-password list mysite-service --field=uuid 2>/dev/null)" \
    || finish_fail PRECHECK_FAILED "無法列出服務帳號 Application Password" "$EXIT_EXTERNAL"
  name="mysite-laravel-$(date -u +%Y%m%d%H%M%S)"
  save_prev wp_service_app_password
  wpc user application-password create mysite-service "$name" --porcelain | tr -d '\r\n ' | md_write_secret wp_service_app_password \
    || { restore_prev wp_service_app_password; finish_fail CREATE_FAILED "無法發行新的 Application Password" "$EXIT_DEPLOY_INCOMPLETE"; }
  verify_service() {
    rs_compose exec -T app-php sh -c 'printf "user = \"%s:%s\"\n" "${CONTENT_SERVICE_USERNAME:-mysite-service}" "$(cat /run/secrets/wp_service_app_password)" \
      | curl -fsS -o /dev/null --max-time 10 --cacert /run/secrets/internal_ca_cert -K - https://wp-web:8443/wp-json/wp/v2/users/me' >/dev/null 2>&1
  }
  if recreate app-php app-worker app-scheduler && verify_service; then
    for u in $old_uuids; do
      [[ "$u" =~ ^[0-9a-f-]{36}$ ]] && { wpc user application-password delete mysite-service "$u" >/dev/null 2>&1 || ms_warn "舊 Application Password ${u:0:8} 刪除失敗，請人工撤銷"; }
    done
    drop_prev wp_service_app_password
    record SUCCESS "新 Application Password 已生效並撤銷舊密碼"
  else
    restore_prev wp_service_app_password
    new_uuid="$(wpc user application-password list mysite-service --name="$name" --field=uuid 2>/dev/null || true)"
    [[ "$new_uuid" =~ ^[0-9a-f-]{36}$ ]] && wpc user application-password delete mysite-service "$new_uuid" >/dev/null 2>&1
    recreate app-php app-worker app-scheduler && verify_service && { drop_prev wp_service_app_password; finish_fail VERIFY_FAILED_REVERTED "新密碼驗證失敗，已回到原密碼" "$EXIT_DEPLOY_INCOMPLETE"; }
    finish_fail VERIFY_FAILED_REVERT_FAILED "新密碼驗證失敗且回復未完成" "$EXIT_DEPLOY_INCOMPLETE"
  fi
  ;;

event-hmac|preview)
  [[ "${MD_CFG[KEYRING_SUPPORT]}" == yes ]] || finish_fail APP_SUPPORT_MISSING "應用程式尚未支援多金鑰（金鑰圈），無法做雙期過渡；需整合者實作後設 KEYRING_SUPPORT=yes" "$EXIT_EXTERNAL"
  if [[ "$kind" == event-hmac ]]; then ring=content_event_hmac_keyring; single=content_event_hmac_key
  else ring=preview_signing_keyring; single=preview_signing_key; fi
  # 金鑰圈格式（每行）：<key_id> <64 hex> <active|accept>；恰好一把 active
  ringf="$MD_SECRETS_DIR/$ring"
  if [[ ! -s "$ringf" ]]; then
    md_secret_ok "$single" || finish_fail PRECHECK_FAILED "沒有既有金鑰" "$EXIT_EXTERNAL"
    md_write_secret "$ring" < <(printf 'k1 %s active\n' "$(tr -d '\r\n' < "$MD_SECRETS_DIR/$single")")
  fi
  newring="$(mktemp "$rot_dir/.ring.XXXXXX")"
  python3 -I - "$ringf" "$phase" "$newring" "$rot_dir/$ring.prev" <<'PY' || { rm -f -- "$newring"; finish_fail PHASE_INVALID "金鑰圈狀態不允許此階段（順序必須 add→promote→retire）" "$EXIT_USAGE"; }
import os, re, secrets, sys
src, phase, out, prev = sys.argv[1:5]
rows = []
for line in open(src, encoding="utf-8"):
    p = line.split()
    if not p:
        continue
    if len(p) != 3 or not re.fullmatch(r"k[0-9]{1,6}", p[0]) or not re.fullmatch(r"[0-9a-f]{64}", p[1]) or p[2] not in ("active", "accept"):
        sys.exit(2)
    rows.append(p)
active = [r for r in rows if r[2] == "active"]
if len(active) != 1:
    sys.exit(2)
if phase == "add":
    if len(rows) != 1:
        sys.exit(3)   # 已有過渡中的金鑰
    n = max(int(r[0][1:]) for r in rows) + 1
    rows.append([f"k{n}", secrets.token_hex(32), "accept"])
elif phase == "promote":
    newest = max(rows, key=lambda r: int(r[0][1:]))
    if newest[2] != "accept" or len(rows) != 2:
        sys.exit(3)
    for r in rows:
        r[2] = "active" if r is newest else "accept"
elif phase == "retire":
    if len(rows) != 2 or max(rows, key=lambda r: int(r[0][1:]))[2] != "active":
        sys.exit(3)
    rows = [r for r in rows if r[2] == "active"]
elif phase == "revert":
    if not os.path.exists(prev):
        sys.exit(3)
    rows = [l.split() for l in open(prev, encoding="utf-8") if l.strip()]
with open(out, "w", encoding="utf-8") as fh:
    for r in rows:
        fh.write(" ".join(r) + "\n")
PY
  [[ "$phase" == revert ]] || save_prev "$ring"
  md_write_secret "$ring" < "$newring"; rm -f -- "$newring"
  if [[ "$phase" == retire ]]; then
    # retire 後單一金鑰檔同步為剩下的 active key：日後若改回單一金鑰模式，也不會回到已撤銷的舊 key
    save_prev "$single"
    md_write_secret "$single" < <(awk '$3 == "active" { print $2 }' "$ringf")
  fi
  # 接收端（Laravel）先重建，再重建發送端（WP），確保任何時刻雙方都至少共用一把可驗證的金鑰
  if recreate app-php app-worker app-scheduler && recreate wp-php wp-jobs && app_ok && wp_ok && keyring_loaded "$kind" "$ringf"; then
    [[ "$phase" == retire || "$phase" == revert ]] && drop_prev "$ring"
    [[ "$phase" == retire ]] && drop_prev "$single"
    if [[ "$phase" == promote ]]; then
      due="$(date -u -d '+24 hours' +%FT%TZ 2>/dev/null || echo '24 小時後')"
      ms_info "新 key 已開始簽章；舊 key 仍被接受。請在至少 15 分鐘後、${due} 之前執行 --phase=retire（暫定上限 24 小時，見 docs/runbooks/秘密輪替.md）"
      record SUCCESS "金鑰圈已進入 promote 階段；retire 期限 ${due}（暫定 24 小時）"
    else
      record SUCCESS "金鑰圈已進入 ${phase} 階段"
    fi
  else
    restore_prev "$ring"
    [[ "$phase" == retire ]] && restore_prev "$single"
    recreate app-php app-worker app-scheduler && recreate wp-php wp-jobs \
      && finish_fail VERIFY_FAILED_REVERTED "服務驗證失敗，金鑰圈已回到上一階段" "$EXIT_DEPLOY_INCOMPLETE"
    finish_fail VERIFY_FAILED_REVERT_FAILED "服務驗證失敗且回復未完成" "$EXIT_DEPLOY_INCOMPLETE"
  fi
  ;;

app-key)
  [[ "${MD_CFG[APP_PREVIOUS_KEYS_SUPPORT]}" == yes ]] || finish_fail APP_SUPPORT_MISSING "Laravel 尚未支援 APP_PREVIOUS_KEYS_FILE；直接換 APP_KEY 會讓既有加密資料與 Session 失效" "$EXIT_EXTERNAL"
  case "$phase" in
    add)
      [[ ! -s "$MD_SECRETS_DIR/app_previous_keys" ]] || finish_fail PHASE_INVALID "上一次輪替尚未 retire" "$EXIT_USAGE"
      save_prev app_key
      md_write_secret app_previous_keys < "$MD_SECRETS_DIR/app_key"
      md_write_secret app_key < <(printf 'base64:%s\n' "$(openssl rand -base64 32)") ;;
    retire)
      [[ -s "$MD_SECRETS_DIR/app_previous_keys" ]] || finish_fail PHASE_INVALID "沒有待撤銷的舊 APP_KEY" "$EXIT_USAGE"
      install -m 0600 "$MD_SECRETS_DIR/app_previous_keys" "$rot_dir/app_previous_keys.prev"
      md_write_secret app_previous_keys < /dev/null ;;
    revert)
      [[ -f "$rot_dir/app_key.prev" ]] || finish_fail NOTHING_TO_REVERT "沒有可回復的 APP_KEY" "$EXIT_USAGE"
      restore_prev app_key; md_write_secret app_previous_keys < /dev/null ;;
  esac
  if recreate app-php app-worker app-scheduler && app_ok && previous_keys_loaded; then
    [[ "$phase" == add ]] || { drop_prev app_key; rm -f -- "$rot_dir/app_previous_keys.prev"; }
    record SUCCESS "APP_KEY ${phase} 完成"
  else
    restore_prev app_key
    [[ -f "$rot_dir/app_previous_keys.prev" ]] && md_write_secret app_previous_keys < "$rot_dir/app_previous_keys.prev"
    [[ "$phase" == add ]] && md_write_secret app_previous_keys < /dev/null
    recreate app-php app-worker app-scheduler && app_ok && finish_fail VERIFY_FAILED_REVERTED "Laravel 驗證失敗，已回到原 APP_KEY" "$EXIT_DEPLOY_INCOMPLETE"
    finish_fail VERIFY_FAILED_REVERT_FAILED "Laravel 驗證失敗且回復未完成" "$EXIT_DEPLOY_INCOMPLETE"
  fi
  ;;
esac
if [[ "$env_name" == development || "$env_name" == testing ]] && [[ -f "$MD_STATE_DIR/fingerprint.json" ]]; then
  # 雲端環境：bootstrap 以 fingerprint.json 檢查「重跑 bootstrap 不得改變金鑰」（CLD-05）。受控輪替成功後同步更新基準
  # （只存 SHA-256，不存秘密），否則下一次 bootstrap 會把核准過的輪替誤判為重設。
  python3 -I - "$MD_STATE_DIR/fingerprint.json" "$MD_SECRETS_DIR" <<'PY' || ms_warn "無法更新 bootstrap 冪等指紋基準（$MD_STATE_DIR/fingerprint.json）；下一次 bootstrap 的 CLD-05 檢查會不一致"
import hashlib, json, os, sys
path, sec = sys.argv[1:3]
def h(*names):
    d = hashlib.sha256()
    for n in names:
        d.update(open(os.path.join(sec, n), "rb").read())
    return d.hexdigest()
fp = json.load(open(path, encoding="utf-8"))
fp["app_key_sha256"] = h("app_key")
fp["wp_salts_sha256"] = h("wp_salts")
fp["db_passwords_sha256"] = h("mysql_root_password", "wp_db_password", "app_db_password")
tmp = path + ".tmp"
with open(tmp, "w", encoding="utf-8") as fh:
    json.dump(fp, fh, ensure_ascii=False, indent=2)
    fh.write("\n")
os.replace(tmp, path)
PY
fi
exit "$EXIT_OK"
