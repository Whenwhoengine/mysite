#!/usr/bin/env bash
# mysite 主機端部署／備份／還原共用函式（staging／production 常駐主機；v5 第 13–15 章）。
# 依賴 scripts/lib/common.sh 的 exit code 常數、ms_log／ms_die、ms_redact、ms_wait_until。
#
# 設計界線：
#   - 主機設定檔只以「白名單鍵＋正規表示式」解析，不 source、不 eval。
#   - 驗證過的發布值由 scripts/lib/manifest.py 以嚴格格式輸出，再以正規表示式讀入。
#   - 任何 rm、volume、project、SQL 名稱都只由固定前綴＋已驗證 enum 組成。
#   - 秘密只以檔案路徑傳遞（*_FILE、--password-stdin、curl -K -），不放進命令列參數或 log。
# shellcheck shell=bash

MD_LIB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
MD_PLATFORM_DIR="$(cd "$MD_LIB_DIR/../.." && pwd -P)"
# shellcheck source=common.sh
source "$MD_LIB_DIR/common.sh"

# 已查核但尚未由整合者寫入 infra/versions.env 的工具版本（versions.env 有值時以其為準）。
# 來源：restic 官方 GitHub Release SHA256SUMS（v0.19.1）；certbot 官方 Docker Hub 映像（v5.9.0）。
MD_DEFAULT_RESTIC_VERSION="0.19.1"
MD_DEFAULT_RESTIC_LINUX_AMD64_SHA256="f415415624dcc452f2a02b8c33641791a8c6d6d3b65bbb3543fcf9a25151585c"
MD_DEFAULT_CERTBOT_IMAGE_REF="certbot/certbot:v5.9.0@sha256:bc93cb710b413e6b456d622f9b0133b3e109c0a78c2ed9976af43d209b0a1805"

readonly MD_RE_HOST='^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$'
readonly MD_RE_REASON='^[a-z0-9][a-z0-9._-]{0,63}$'
readonly MD_RE_PLAN_REF='^[A-Za-z0-9][A-Za-z0-9._:/#-]{2,199}$'
readonly MD_RE_SNAPSHOT='^([0-9a-f]{8}|[0-9a-f]{64}|latest)$'
readonly MD_RE_TEST_PROJECT='^mysite-deploytest-[a-z0-9]{4,16}$'

# ---------------------------------------------------------------------------
# 基本
# ---------------------------------------------------------------------------
md_now() { date -u +%FT%TZ; }
md_epoch() { date -u +%s; }

md_require_cmd() {
  local c
  for c in "$@"; do
    command -v "$c" >/dev/null 2>&1 || ms_die "$EXIT_EXTERNAL" "缺少必要指令：$c"
  done
}

md_python() { python3 -I "$@"; }
md_manifest() { python3 -I "$MD_LIB_DIR/manifest.py" "$@"; }

# 主機根目錄：預設 /srv/mysite；測試可用 MYSITE_HOST_ROOT 指向臨時目錄。
md_resolve_host_root() {
  local r="${MYSITE_HOST_ROOT:-/srv/mysite}"
  r="${r%/}"
  [[ "$r" =~ ^/[A-Za-z0-9._/-]+$ && "$r" != *..* ]] || ms_die "$EXIT_USAGE" "MYSITE_HOST_ROOT 必須是乾淨的絕對路徑"
  MD_HOST_ROOT="$r"
  if [[ "$r" == /srv/mysite ]]; then MD_TEST_ROOT=0; else MD_TEST_ROOT=1; fi
}

# md_env_paths <env>：設定該環境的所有路徑（env 必須先經 enum 驗證）
md_env_paths() {
  local env="$1"
  case "$env" in staging|production|development) ;; *) ms_die "$EXIT_USAGE" "未知環境：$env" ;; esac
  MD_ENV="$env"
  MD_ENV_DIR="$MD_HOST_ROOT/environments/$env"
  MD_CONFIG_DIR="$MD_ENV_DIR/config"
  MD_SECRETS_DIR="$MD_ENV_DIR/secrets"
  MD_RELEASES_DIR="$MD_ENV_DIR/releases"
  MD_STATE_DIR="$MD_ENV_DIR/state"
  MD_TRUST_DIR="$MD_CONFIG_DIR/trusted-keys"
  MD_BACKUP_STAGING="$MD_HOST_ROOT/backup-staging/$env"
  MD_TLS_DIR="$MD_HOST_ROOT/tls"
  MD_COMPOSE_ENV="$MD_STATE_DIR/compose.env"
}

# Compose project：固定前綴＋環境；測試只允許 mysite-deploytest-* 覆寫。
md_project_name() {
  local env="$1"
  if [[ -n "${MYSITE_PROJECT_OVERRIDE:-}" ]]; then
    [[ "$MYSITE_PROJECT_OVERRIDE" =~ $MD_RE_TEST_PROJECT ]] \
      || ms_die "$EXIT_FORBIDDEN" "MYSITE_PROJECT_OVERRIDE 只允許 mysite-deploytest-*（測試專用）"
    printf '%s\n' "$MYSITE_PROJECT_OVERRIDE"
    return 0
  fi
  case "$env" in
    staging|production) printf 'mysite-%s\n' "$env" ;;
    development) printf '%s\n' "mysite-dev" ;;
    *) ms_die "$EXIT_USAGE" "未知環境：$env" ;;
  esac
}

# ---------------------------------------------------------------------------
# 主機設定（config/deploy.env）：白名單鍵＋格式驗證，不 source
# ---------------------------------------------------------------------------
declare -gA MD_CFG=()
declare -gA MD_CFG_PATTERN=(
  [DEPLOY_ENVIRONMENT]='^(staging|production)$'
  [DEPLOY_CHANNEL]='^[a-z][a-z0-9-]{1,31}$'
  [RELEASE_REPOSITORY]='^Whenwhoengine/mysite$'
  [RELEASE_CHANNEL_TAG]='^[a-z0-9][a-z0-9._-]{0,63}$'
  [RELEASE_ASSET_NAME]='^[a-z0-9][a-z0-9._-]{0,58}\.json$'
  [IMAGE_REPOSITORY]='^ghcr\.io/[a-z0-9]+([._-][a-z0-9]+)*(/[a-z0-9]+([._-][a-z0-9]+)*)*$'
  [APP_HOST]='^(|([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63})$'
  [CMS_HOST]='^(|([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63})$'
  [MYSITE_GATEWAY_MODE]='^(bootstrap|https)$'
  [MYSITE_HSTS_HEADER]='^(|max-age=[0-9]{1,9}(; includeSubDomains)?(; preload)?)$'
  [COMMERCE_MODE]='^(disabled|simulation)$'
  [MAIL_MAILER]='^log$'
  [EDGE_SUBNET]='^172\.(1[6-9]|2[0-9]|3[01])\.[0-9]{1,3}\.0/24$'
  [BACKEND_SUBNET]='^172\.(1[6-9]|2[0-9]|3[01])\.[0-9]{1,3}\.0/24$'
  [DB_SUBNET]='^172\.(1[6-9]|2[0-9]|3[01])\.[0-9]{1,3}\.0/24$'
  [BASE_IMAGE_REGISTRY]='^(docker\.io/library|public\.ecr\.aws/docker/library)$'
  [ACME_EMAIL]='^(|[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,63})$'
  [ACME_SERVER]='^(production|staging)$'
  [SITE_TITLE]='^[A-Za-z0-9 ._-]{1,64}$'
  [ADMIN_USERNAME]='^[a-z][a-z0-9_-]{2,31}$'
  [ADMIN_EMAIL]='^(|[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,63})$'
  [RESTIC_BIN]='^/[A-Za-z0-9._/-]+$'
  [BACKUP_RETENTION_MODE]='^(preview|apply)$'
  [BACKUP_KEEP_HOURLY]='^[0-9]{1,4}$'
  [BACKUP_KEEP_DAILY]='^[0-9]{1,4}$'
  [BACKUP_KEEP_MONTHLY]='^[0-9]{1,3}$'
  [MIN_FREE_DISK_MB]='^[0-9]{1,7}$'
  [SMOKE_TIMEOUT_SECONDS]='^[0-9]{1,5}$'
  [RELEASE_MAX_ATTEMPTS]='^([1-9]|10)$'
  [PULL_BACKOFF_BASE_SECONDS]='^[0-9]{1,5}$'
  [PULL_BACKOFF_MAX_SECONDS]='^[0-9]{1,6}$'
  [PULL_TIMEOUT_SECONDS]='^[0-9]{1,4}$'
  [KEYRING_SUPPORT]='^(yes|no)$'
  [APP_PREVIOUS_KEYS_SUPPORT]='^(yes|no)$'
  [GHCR_AUTH]='^(none|token)$'
  [GHCR_USERNAME]='^(|[A-Za-z0-9-]{1,39})$'
)

md_cfg_defaults() {
  local env="$1" net
  case "$env" in production) net=30 ;; *) net=29 ;; esac
  MD_CFG=(
    [RELEASE_REPOSITORY]="Whenwhoengine/mysite"
    [RELEASE_ASSET_NAME]="release-envelope.json"
    [IMAGE_REPOSITORY]="ghcr.io/whenwhoengine/mysite"
    [APP_HOST]="" [CMS_HOST]=""
    [MYSITE_GATEWAY_MODE]="bootstrap"
    [MYSITE_HSTS_HEADER]=""
    [COMMERCE_MODE]="disabled"
    [MAIL_MAILER]="log"
    [EDGE_SUBNET]="172.${net}.10.0/24" [BACKEND_SUBNET]="172.${net}.20.0/24" [DB_SUBNET]="172.${net}.30.0/24"
    [BASE_IMAGE_REGISTRY]="docker.io/library"
    [ACME_EMAIL]="" [ACME_SERVER]="production"
    [SITE_TITLE]="mysite" [ADMIN_USERNAME]="mysite-admin" [ADMIN_EMAIL]=""
    [RESTIC_BIN]="/usr/local/bin/restic"
    [BACKUP_RETENTION_MODE]="preview"
    [BACKUP_KEEP_HOURLY]="48" [BACKUP_KEEP_DAILY]="30" [BACKUP_KEEP_MONTHLY]="12"
    [MIN_FREE_DISK_MB]="3072"          # 暫定：換版前主機最低可用空間
    [SMOKE_TIMEOUT_SECONDS]="300"      # 暫定：冒煙測試等待上限
    [RELEASE_MAX_ATTEMPTS]="3"         # 暫定：同一清單失敗重試上限
    [PULL_BACKOFF_BASE_SECONDS]="300"  # 暫定：拉取失敗退避起點
    [PULL_BACKOFF_MAX_SECONDS]="3600"  # 暫定：拉取失敗退避上限
    [PULL_TIMEOUT_SECONDS]="30"        # 暫定：單次 HTTPS 請求上限
    [KEYRING_SUPPORT]="no" [APP_PREVIOUS_KEYS_SUPPORT]="no"
    [GHCR_AUTH]="none" [GHCR_USERNAME]=""
  )
}

# md_load_config <env>：讀 $MD_CONFIG_DIR/deploy.env
md_load_config() {
  local env="$1" file="$MD_CONFIG_DIR/deploy.env" line key value n=0
  md_cfg_defaults "$env"
  [[ -f "$file" ]] || ms_die "$EXIT_EXTERNAL" "缺少主機設定 $file（請先執行 scripts/deploy/bootstrap-host.sh）"
  while IFS= read -r line || [[ -n "$line" ]]; do
    n=$((n + 1))
    [[ "$line" =~ ^[[:space:]]*(#.*)?$ ]] && continue
    [[ "$line" =~ ^([A-Z][A-Z0-9_]*)=(.*)$ ]] || ms_die "$EXIT_USAGE" "設定檔第 ${n} 行格式錯誤（只允許 KEY=VALUE）"
    key="${BASH_REMATCH[1]}"; value="${BASH_REMATCH[2]}"
    [[ -n "${MD_CFG_PATTERN[$key]+x}" ]] || ms_die "$EXIT_USAGE" "設定檔第 ${n} 行：未知鍵 ${key}"
    [[ "$value" =~ ${MD_CFG_PATTERN[$key]} ]] || ms_die "$EXIT_USAGE" "設定檔第 ${n} 行：${key} 的值不合法"
    MD_CFG[$key]="$value"
  done < "$file"
  [[ "${MD_CFG[DEPLOY_ENVIRONMENT]:-}" == "$env" ]] \
    || ms_die "$EXIT_FORBIDDEN" "主機設定的 DEPLOY_ENVIRONMENT 與 --env=$env 不符（防止跨環境操作）"
  [[ -n "${MD_CFG[DEPLOY_CHANNEL]:-}" ]] || ms_die "$EXIT_USAGE" "主機設定缺少 DEPLOY_CHANNEL"
  [[ -n "${MD_CFG[RELEASE_CHANNEL_TAG]:-}" ]] || MD_CFG[RELEASE_CHANNEL_TAG]="channel-${MD_CFG[DEPLOY_CHANNEL]}"
}

md_cfg() { printf '%s\n' "${MD_CFG[$1]:-}"; }

md_require_hosts() {
  [[ "${MD_CFG[APP_HOST]}" =~ $MD_RE_HOST && "${MD_CFG[CMS_HOST]}" =~ $MD_RE_HOST ]] \
    || ms_die "$EXIT_EXTERNAL" "主機設定缺少核准的 APP_HOST／CMS_HOST（BLOCKED_EXTERNAL: DNS 未授權）"
  [[ "${MD_CFG[APP_HOST]}" != "${MD_CFG[CMS_HOST]}" ]] || ms_die "$EXIT_USAGE" "APP_HOST 與 CMS_HOST 不得相同"
}

# 讀取 versions.env 中的單一鍵（不 source 整檔到目前 shell 之外的用途）。
md_version_value() {
  local key="$1" line
  line="$(grep -E "^${key}=" "$MD_PLATFORM_DIR/infra/versions.env" 2>/dev/null | tail -1 || true)"
  line="${line#*=}"; line="${line%\"}"; line="${line#\"}"
  printf '%s\n' "$line"
}

md_mysql_major() {
  local v; v="$(md_version_value MYSQL_VERSION)"
  [[ "$v" =~ ^([0-9]+\.[0-9]+)\. ]] || ms_die "$EXIT_USAGE" "infra/versions.env 缺少 MYSQL_VERSION"
  printf '%s\n' "${BASH_REMATCH[1]}"
}

md_restic_version() { local v; v="$(md_version_value RESTIC_VERSION)"; printf '%s\n' "${v:-$MD_DEFAULT_RESTIC_VERSION}"; }
md_restic_sha256() { local v; v="$(md_version_value RESTIC_LINUX_AMD64_SHA256)"; printf '%s\n' "${v:-$MD_DEFAULT_RESTIC_LINUX_AMD64_SHA256}"; }
md_certbot_image() { local v; v="$(md_version_value CERTBOT_IMAGE_REF)"; printf '%s\n' "${v:-$MD_DEFAULT_CERTBOT_IMAGE_REF}"; }

# ---------------------------------------------------------------------------
# 驗證後的發布值（manifest.py --env-out）：只接受嚴格格式
# ---------------------------------------------------------------------------
declare -gA MD_REL=()
md_read_release_env() {
  local file="$1" line key value
  MD_REL=()
  while IFS= read -r line || [[ -n "$line" ]]; do
    [[ "$line" =~ ^(RELEASE_[A-Z0-9_]+|MYSITE_IMAGE_[A-Z_]+)=([A-Za-z0-9@:/._-]*)$ ]] \
      || ms_die "$EXIT_FORBIDDEN" "驗證輸出格式異常，拒絕繼續"
    key="${BASH_REMATCH[1]}"; value="${BASH_REMATCH[2]}"
    MD_REL[$key]="$value"
  done < "$file"
  local k
  for k in RELEASE_ID RELEASE_SEQUENCE RELEASE_SOURCE_COMMIT RELEASE_SCHEMA_CHANGE_CLASS MYSITE_IMAGE_LARAVEL \
           MYSITE_IMAGE_WORDPRESS MYSITE_IMAGE_APP_WEB MYSITE_IMAGE_WP_WEB MYSITE_IMAGE_GATEWAY; do
    [[ -n "${MD_REL[$k]:-}" ]] || ms_die "$EXIT_FORBIDDEN" "驗證輸出缺少 $k"
  done
}

# md_verify_envelope <envelope> <workdir>：驗證並把 payload／env 寫入 workdir；回傳 manifest.py 的結束碼
md_verify_envelope() {
  local envelope="$1" work="$2" rc=0
  md_manifest verify --envelope "$envelope" --trust-dir "$MD_TRUST_DIR" --environment "$MD_ENV" \
    --channel "${MD_CFG[DEPLOY_CHANNEL]}" --state-dir "$MD_STATE_DIR" --image-repository "${MD_CFG[IMAGE_REPOSITORY]}" \
    --mysql-major "$(md_mysql_major)" --revoked-digests "$MD_CONFIG_DIR/revoked-digests.txt" \
    --payload-out "$work/payload.json" --env-out "$work/release.env" > "$work/verify.json" || rc=$?
  return "$rc"
}

md_verify_message() { python3 -I -c 'import json,sys; d=json.load(open(sys.argv[1])); print(d.get("code","?")+"："+d.get("message",""))' "$1" 2>/dev/null || echo "?"; }

# ---------------------------------------------------------------------------
# 鎖、事件、狀態
# ---------------------------------------------------------------------------
# md_lock <檔案> <等待秒數>：取得 flock（程序結束自動釋放）；取不到回傳 1
md_lock() {
  local file="$1" wait="$2" fd
  exec {fd}>>"$file" || ms_die "$EXIT_EXTERNAL" "無法開啟鎖檔 $file"
  flock -w "$wait" "$fd" || return 1
  MD_LAST_LOCK_FD="$fd"
}

# md_event <level> <event> <message>：寫入 state/events.jsonl（不含秘密）與 stderr
md_event() {
  local level="$1" event="$2" msg="$3"
  ms_log "$level" "[$event] $msg"
  [[ -n "${MD_STATE_DIR:-}" && -d "${MD_STATE_DIR:-/nonexistent}" ]] || return 0
  python3 -I -c '
import json,sys,datetime
rec={"timestamp":datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
     "service":"deploy","environment":sys.argv[1],"level":sys.argv[2],"event":sys.argv[3],"message":sys.argv[4],
     "script":sys.argv[5]}
with open(sys.argv[6],"a",encoding="utf-8") as fh: fh.write(json.dumps(rec,ensure_ascii=False)+"\n")
' "$MD_ENV" "$level" "$event" "$msg" "${MD_SCRIPT_NAME:-deploy}" "$MD_STATE_DIR/events.jsonl" 2>/dev/null || true
}

md_state_get() { md_manifest state-get --state-dir "$MD_STATE_DIR" --environment "$MD_ENV" --field "$1"; }
md_state_step() { md_manifest state-step --state-dir "$MD_STATE_DIR" --environment "$MD_ENV" "$@"; }

# ---------------------------------------------------------------------------
# Docker／Compose
# ---------------------------------------------------------------------------
md_docker_ok() { command -v docker >/dev/null 2>&1 && timeout 20 docker info >/dev/null 2>&1; }

md_compose() {
  # 以固定 project、平台 versions.env 與本環境 compose.env 呼叫；檔案只取平台內的 base＋prod
  local envfile="${MD_COMPOSE_ENV_OVERRIDE:-$MD_COMPOSE_ENV}"
  timeout "${MD_COMPOSE_TIMEOUT:-900}" docker compose --project-name "$MD_PROJECT" \
    --project-directory "$MD_PLATFORM_DIR/infra" \
    --env-file "$MD_PLATFORM_DIR/infra/versions.env" --env-file "$envfile" \
    -f "$MD_PLATFORM_DIR/infra/compose.base.yml" -f "$MD_PLATFORM_DIR/infra/compose.prod.yml" "$@"
}

# md_container <service>：依 Compose label 找執行中容器（只看本 project）
md_container() {
  # 查詢用：docker 查不到時視為「未執行」（空字串），由呼叫端判斷
  local out
  out="$(docker ps -q --filter "label=com.docker.compose.project=$MD_PROJECT" \
    --filter "label=com.docker.compose.service=$1" 2>/dev/null)" || out=""
  printf '%s\n' "${out%%$'\n'*}"
}

md_volume_exists() { docker volume inspect "${MD_PROJECT}_$1" >/dev/null 2>&1; }

# md_image_digest_ok <ref>：本機映像的 RepoDigests 必須含清單指定的 digest
md_image_digest_ok() {
  local ref="$1" repo digest
  repo="${ref%@*}"; digest="${ref#*@}"
  docker image inspect --format '{{range .RepoDigests}}{{println .}}{{end}}' "$ref" 2>/dev/null \
    | grep -Fxq "${repo}@${digest}"
}

# md_write_compose_env <輸出檔>：由已驗證主機設定＋MD_REL 產生 Compose 插值檔（非秘密）
md_write_compose_env() {
  local out="$1" tmp
  tmp="$(mktemp "${out}.XXXXXX")"
  {
    printf '# 由 %s 於 %s 產生；非秘密。請勿手改（下次部署會覆寫）。\n' "${MD_SCRIPT_NAME:-deploy}" "$(md_now)"
    printf 'COMPOSE_PROJECT_NAME=%s\n' "$MD_PROJECT"
    printf 'MYSITE_ENVIRONMENT=%s\nWP_ENVIRONMENT_TYPE=%s\n' "$MD_ENV" "$MD_ENV"
    printf 'APP_DEBUG=false\nLOG_LEVEL=info\nSESSION_SECURE_COOKIE=true\n'
    printf 'APP_URL=https://%s\nWP_PUBLIC_URL=https://%s\n' "${MD_CFG[APP_HOST]}" "${MD_CFG[CMS_HOST]}"
    printf 'APP_HOST=%s\nCMS_HOST=%s\n' "${MD_CFG[APP_HOST]}" "${MD_CFG[CMS_HOST]}"
    printf 'MYSITE_GATEWAY_MODE=%s\nMYSITE_HSTS_HEADER=%s\n' "${MD_CFG[MYSITE_GATEWAY_MODE]}" "${MD_CFG[MYSITE_HSTS_HEADER]}"
    printf 'COMMERCE_MODE=%s\nMAIL_MAILER=%s\n' "${MD_CFG[COMMERCE_MODE]}" "${MD_CFG[MAIL_MAILER]}"
    printf 'EDGE_SUBNET=%s\nBACKEND_SUBNET=%s\nDB_SUBNET=%s\n' "${MD_CFG[EDGE_SUBNET]}" "${MD_CFG[BACKEND_SUBNET]}" "${MD_CFG[DB_SUBNET]}"
    printf 'BASE_IMAGE_REGISTRY=%s\n' "${MD_CFG[BASE_IMAGE_REGISTRY]}"
    printf 'DEPLOY_ENVIRONMENT=%s\nDEPLOY_CHANNEL=%s\n' "$MD_ENV" "${MD_CFG[DEPLOY_CHANNEL]}"
    printf 'RELEASE_ID=%s\nMYSITE_RELEASE_SEQUENCE=%s\nMYSITE_SOURCE_COMMIT=%s\n' \
      "${MD_REL[RELEASE_ID]}" "${MD_REL[RELEASE_SEQUENCE]}" "${MD_REL[RELEASE_SOURCE_COMMIT]}"
    printf 'MYSITE_IMAGE_LARAVEL=%s\nMYSITE_IMAGE_WORDPRESS=%s\n' "${MD_REL[MYSITE_IMAGE_LARAVEL]}" "${MD_REL[MYSITE_IMAGE_WORDPRESS]}"
    printf 'MYSITE_IMAGE_APP_WEB=%s\nMYSITE_IMAGE_WP_WEB=%s\nMYSITE_IMAGE_GATEWAY=%s\n' \
      "${MD_REL[MYSITE_IMAGE_APP_WEB]}" "${MD_REL[MYSITE_IMAGE_WP_WEB]}" "${MD_REL[MYSITE_IMAGE_GATEWAY]}"
    printf 'MYSITE_SECRETS_DIR=%s\n' "$MD_SECRETS_DIR"
    printf 'MYSITE_TLS_DIR=%s\nMYSITE_ACME_DIR=%s\n' "$MD_TLS_DIR/letsencrypt" "$MD_TLS_DIR/acme"
  } > "$tmp"
  chmod 0640 "$tmp"
  mv -f "$tmp" "$out"
}

md_free_mb() { df -Pm "$1" 2>/dev/null | awk 'NR==2 {print $4}'; }

# ---------------------------------------------------------------------------
# 秘密檔
# ---------------------------------------------------------------------------
# md_secret_ok <名稱>：存在、非空、一般檔、其他人不可讀寫
md_secret_ok() {
  local f="$MD_SECRETS_DIR/$1" mode
  [[ -f "$f" && ! -L "$f" && -s "$f" ]] || return 1
  mode="$(stat -c '%a' "$f")"
  (( (8#$mode & 8#007) == 0 ))
}

# md_write_secret <名稱>（值由 stdin 提供；不經命令列參數）
md_write_secret() {
  local name="$1" path tmp
  [[ "$name" =~ ^[a-z][a-z0-9_]{1,63}$ ]] || ms_die "$EXIT_USAGE" "秘密名稱不合法"
  path="$MD_SECRETS_DIR/$name"
  tmp="$(umask 077; mktemp "$MD_SECRETS_DIR/.${name}.XXXXXX")"
  cat > "$tmp"
  chgrp 33 "$tmp" 2>/dev/null || true
  chmod 0640 "$tmp"
  mv -f "$tmp" "$path"
}

md_rand_alnum() {
  # md_rand_alnum <長度>：只含英數字（避免 SQL／shell 跳脫問題）
  local n="$1" out=""
  while (( ${#out} < n )); do
    out+="$(openssl rand -base64 64 | tr -dc 'A-Za-z0-9')"
  done
  printf '%s' "${out:0:n}"
}

# ---------------------------------------------------------------------------
# restic（密碼與目的地只以檔案提供；雲端憑證限白名單鍵，只匯出給 restic 子程序）
# ---------------------------------------------------------------------------
md_restic_ready() {
  local bin="${MD_CFG[RESTIC_BIN]}"
  [[ -x "$bin" ]] || { MD_RESTIC_MISSING="restic 執行檔不存在（$bin）"; return 1; }
  md_secret_ok restic_password || { MD_RESTIC_MISSING="缺少 secrets/restic_password"; return 1; }
  md_secret_ok restic_repository || { MD_RESTIC_MISSING="缺少 secrets/restic_repository（備份目的地）"; return 1; }
  return 0
}

md_restic() {
  (
    export RESTIC_REPOSITORY_FILE="$MD_SECRETS_DIR/restic_repository"
    export RESTIC_PASSWORD_FILE="$MD_SECRETS_DIR/restic_password"
    export RESTIC_CACHE_DIR="$MD_BACKUP_STAGING/.restic-cache"
    if [[ -f "$MD_SECRETS_DIR/restic_env" ]]; then
      local line
      while IFS= read -r line || [[ -n "$line" ]]; do
        [[ "$line" =~ ^[[:space:]]*(#.*)?$ ]] && continue
        if [[ "$line" =~ ^(AWS_ACCESS_KEY_ID|AWS_SECRET_ACCESS_KEY|AWS_DEFAULT_REGION|AWS_REGION|B2_ACCOUNT_ID|B2_ACCOUNT_KEY|AZURE_ACCOUNT_NAME|AZURE_ACCOUNT_KEY|GOOGLE_PROJECT_ID|GOOGLE_APPLICATION_CREDENTIALS|RESTIC_COMPRESSION)=(.*)$ ]]; then
          export "${BASH_REMATCH[1]}=${BASH_REMATCH[2]}"
        else
          printf '%s\n' "restic_env 含未核准鍵，拒絕使用" >&2
          exit "$EXIT_USAGE"
        fi
      done < "$MD_SECRETS_DIR/restic_env"
    fi
    exec timeout "${MD_RESTIC_TIMEOUT:-3600}" "${MD_CFG[RESTIC_BIN]}" "$@"
  )
}
