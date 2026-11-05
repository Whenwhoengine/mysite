#!/usr/bin/env bash
# 常駐主機一次性開通（v5 §13.3 P3–P7、§13.4、§13.6）：只在指定、已授權的雲端主機上執行。
# 檢查 OS／Docker → 增量建立 /srv/mysite 目錄與權限 → 主機設定範本 → 產生缺少的應用秘密（不覆寫、不輸出）→
# 安裝驗簽公鑰 → 安裝 systemd unit／timer →（選用）安裝 restic、首次 HTTP 驗證入口、申請 TLS 憑證。
# 不安裝 Docker（依官方文件在主控臺手動安裝）、不執行遠端管線、不刪除任何既有資料。
set -Eeuo pipefail
umask 027

usage() {
  cat <<'EOF'
用法：scripts/deploy/bootstrap-host.sh --env=staging|production --approved-plan=<核准參照> [選項]

選項（可組合；全部冪等、只補缺）：
  --trust-key=<key_id>:<公鑰檔>   安裝發布驗簽公鑰（Ed25519 PEM；同一 key_id 已存在且內容不同即拒絕）
  --install-restic                依 infra/versions.env 的版本與 SHA-256 安裝 restic 到 /usr/local/bin/restic
  --start-edge                    尚無任何發布時，啟動只提供 ACME 驗證＋維護頁的 HTTP 入口（port 80）
  --issue-tls                     以 Certbot webroot 為 APP_HOST／CMS_HOST 申請憑證；成功後切換 gateway 為 https
  --check-only                    只檢查並列出將建立的項目，不做任何變更
  --help

前置：以 root 在主機上執行；平台程式（本儲存庫已審核 commit）位於 /srv/mysite/platform。
測試可設 MYSITE_HOST_ROOT（臨時目錄）與 MYSITE_SYSTEMD_DIR；正式主機一律 /srv/mysite。
exit code：0 成功；10 參數錯；20 缺外部前置（OS、Docker、DNS、憑證申請失敗）；40 未授權（非 root、金鑰衝突）。
EOF
}

env_name=""; plan=""; check_only=0; install_restic=0; start_edge=0; issue_tls=0
declare -a trust_keys=()
for arg in "$@"; do
  case "$arg" in
    --env=*) env_name="${arg#--env=}" ;;
    --approved-plan=*) plan="${arg#--approved-plan=}" ;;
    --trust-key=*) trust_keys+=("${arg#--trust-key=}") ;;
    --install-restic) install_restic=1 ;;
    --start-edge) start_edge=1 ;;
    --issue-tls) issue_tls=1 ;;
    --check-only) check_only=1 ;;
    -h|--help) usage; exit 0 ;;
    *) printf '未知參數：%s\n' "$arg" >&2; usage >&2; exit 10 ;;
  esac
done

# shellcheck source=../lib/deploy.sh
source "$(dirname "${BASH_SOURCE[0]}")/../lib/deploy.sh"
MD_SCRIPT_NAME=bootstrap-host
[[ -n "$env_name" ]] || { usage >&2; exit "$EXIT_USAGE"; }
ms_require_env_enum "$env_name" staging production
[[ -n "$plan" ]] || ms_die "$EXIT_FORBIDDEN" "缺少 --approved-plan：主機開通必須有核准紀錄參照"
[[ "$plan" =~ $MD_RE_PLAN_REF ]] || ms_die "$EXIT_USAGE" "--approved-plan 格式不合法"
md_require_cmd python3 openssl sha256sum
md_resolve_host_root
md_env_paths "$env_name"
MD_PROJECT="$(md_project_name "$env_name")"
systemd_dir="${MYSITE_SYSTEMD_DIR:-/etc/systemd/system}"
if (( ! MD_TEST_ROOT )); then
  [[ "$(id -u)" == 0 ]] || ms_die "$EXIT_FORBIDDEN" "必須以 root 在目標主機執行"
  [[ "$MD_PLATFORM_DIR" == /srv/mysite/platform ]] || ms_die "$EXIT_FORBIDDEN" "平台程式必須位於 /srv/mysite/platform（目前：$MD_PLATFORM_DIR）"
  [[ "$systemd_dir" == /etc/systemd/system ]] || ms_die "$EXIT_FORBIDDEN" "正式主機不得改變 systemd 目錄"
fi

# ---------------------------------------------------------------------------
# 1. 作業系統與 Docker（只檢查；Docker 依官方文件安裝）
# ---------------------------------------------------------------------------
os_file="/etc/os-release"
(( MD_TEST_ROOT )) && os_file="${MYSITE_OS_RELEASE_FILE:-/etc/os-release}"
os_id="$(sed -n 's/^ID=//p' "$os_file" | tr -d '"')"; os_ver="$(sed -n 's/^VERSION_ID=//p' "$os_file" | tr -d '"')"
case "${os_id}:${os_ver}" in
  ubuntu:24.04|ubuntu:22.04|debian:12|debian:13) ms_info "作業系統：${os_id} ${os_ver}" ;;
  *) ms_die "$EXIT_EXTERNAL" "不支援的作業系統 ${os_id} ${os_ver}（需 Docker 官方支援的 Ubuntu 24.04／22.04 或 Debian 12／13）" ;;
esac
[[ "$(uname -m)" == x86_64 ]] || ms_die "$EXIT_EXTERNAL" "平台契約為 linux/amd64；目前架構 $(uname -m)"
command -v docker >/dev/null 2>&1 || ms_die "$EXIT_EXTERNAL" "尚未安裝 Docker Engine（依 docs/runbooks/主機開通.md P3 於主控臺安裝）"
server_ver="$(timeout 20 docker version --format '{{.Server.Version}}' 2>/dev/null || true)"
compose_ver="$(timeout 20 docker compose version --short 2>/dev/null || true)"
ver_ge() { [[ "$(printf '%s\n%s\n' "$2" "$1" | sort -V | head -n 1)" == "$2" ]]; }
[[ -n "$server_ver" ]] && ver_ge "$server_ver" 25.0 || ms_die "$EXIT_EXTERNAL" "Docker Engine 需 ≥ 25.0（暫定下限），目前：${server_ver:-無法連線}"
[[ -n "$compose_ver" ]] && ver_ge "${compose_ver#v}" 2.24 || ms_die "$EXIT_EXTERNAL" "Docker Compose v2 需 ≥ 2.24（暫定下限），目前：${compose_ver:-未安裝}"
ms_info "Docker ${server_ver}／Compose ${compose_ver}"

# ---------------------------------------------------------------------------
# 2. 目錄與權限（增量）
# ---------------------------------------------------------------------------
declare -a dirs=(
  "0755:$MD_HOST_ROOT" "0755:$MD_HOST_ROOT/environments" "0750:$MD_ENV_DIR"
  "0750:$MD_CONFIG_DIR" "0755:$MD_TRUST_DIR" "0750:$MD_SECRETS_DIR" "0700:$MD_SECRETS_DIR/ca"
  "0750:$MD_RELEASES_DIR" "0750:$MD_RELEASES_DIR/incoming" "0750:$MD_RELEASES_DIR/verified" "0750:$MD_STATE_DIR"
  "0755:$MD_TLS_DIR" "0755:$MD_TLS_DIR/acme" "0700:$MD_TLS_DIR/letsencrypt" "0700:$MD_TLS_DIR/certbot-lib"
  "0700:$MD_HOST_ROOT/backup-staging" "0700:$MD_BACKUP_STAGING"
)
if (( check_only )); then
  for e in "${dirs[@]}"; do [[ -d "${e#*:}" ]] || ms_info "將建立：${e#*:}（${e%%:*}）"; done
  [[ -f "$MD_CONFIG_DIR/deploy.env" ]] || ms_info "將建立主機設定範本：$MD_CONFIG_DIR/deploy.env"
  ms_info "check-only：未做任何變更"
  exit "$EXIT_OK"
fi
for e in "${dirs[@]}"; do
  d="${e#*:}"; mode="${e%%:*}"
  [[ -d "$d" ]] || mkdir -p "$d"
  chmod "$mode" "$d"
done
chgrp 33 "$MD_SECRETS_DIR" 2>/dev/null || ms_warn "無法把 secrets 群組設為 33（測試環境可忽略；正式主機必須成功）"
mkdir -p "$MD_TLS_DIR/acme/.well-known/acme-challenge"

# ---------------------------------------------------------------------------
# 3. 主機設定範本（存在就驗證，不覆寫）
# ---------------------------------------------------------------------------
if [[ ! -f "$MD_CONFIG_DIR/deploy.env" ]]; then
  {
    printf '# mysite %s 主機設定（非秘密）。格式 KEY=VALUE；鍵名與格式見 scripts/lib/deploy.sh 的 MD_CFG_PATTERN。\n' "$env_name"
    printf '# 由 bootstrap-host.sh 建立於 %s（核准參照：%s）。\n' "$(md_now)" "$plan"
    printf 'DEPLOY_ENVIRONMENT=%s\nDEPLOY_CHANNEL=%s\n' "$env_name" "$env_name"
    printf '# 核准的主站與 CMS 主機名稱（DNS 就緒後填入）\nAPP_HOST=\nCMS_HOST=\n'
    printf '# 憑證就緒前維持 bootstrap；bootstrap-host.sh --issue-tls 成功後會改為 https\nMYSITE_GATEWAY_MODE=bootstrap\n'
    printf '# HSTS 在所有正式子網域確認後才開啟，例如 max-age=31536000\nMYSITE_HSTS_HEADER=\n'
    printf 'COMMERCE_MODE=disabled\nACME_EMAIL=\nACME_SERVER=production\nADMIN_EMAIL=\n'
    printf 'BACKUP_RETENTION_MODE=preview\n'
  } > "$MD_CONFIG_DIR/deploy.env"
  chmod 0640 "$MD_CONFIG_DIR/deploy.env"
  ms_info "已建立主機設定範本（請填 APP_HOST／CMS_HOST／ACME_EMAIL／ADMIN_EMAIL）"
fi
md_load_config "$env_name"

# ---------------------------------------------------------------------------
# 4. 應用秘密：只補缺；資料卷已存在而 DB 秘密遺失 → 拒絕（不得隨機換密碼假恢復）
# ---------------------------------------------------------------------------
need_secret() {
  local name="$1" guard="$2"
  md_secret_ok "$name" && return 1
  [[ -e "$MD_SECRETS_DIR/$name" ]] && ms_die "$EXIT_FORBIDDEN" "秘密 $name 存在但為空或權限過寬；請人工檢查（不自動覆寫）"
  if [[ -n "$guard" ]] && md_docker_ok && md_volume_exists "$guard"; then
    ms_die "$EXIT_EXTERNAL" "資料卷 ${MD_PROJECT}_${guard} 已存在但秘密 $name 遺失；請依 docs/runbooks/秘密輪替.md 受控處理"
  fi
  return 0
}
generated=()
gen() { md_write_secret "$1"; generated+=("$1"); }
need_secret mysql_root_password mysql-data && gen mysql_root_password < <(md_rand_alnum 40)
need_secret wp_db_password mysql-data && gen wp_db_password < <(md_rand_alnum 40)
need_secret app_db_password mysql-data && gen app_db_password < <(md_rand_alnum 40)
need_secret wp_admin_password mysql-data && gen wp_admin_password < <(md_rand_alnum 32)
need_secret app_key mysql-data && gen app_key < <(printf 'base64:%s\n' "$(openssl rand -base64 32)")
salts_lines() {
  local n
  for n in AUTH_KEY SECURE_AUTH_KEY LOGGED_IN_KEY NONCE_KEY AUTH_SALT SECURE_AUTH_SALT LOGGED_IN_SALT NONCE_SALT; do
    printf '%s=%s\n' "$n" "$(md_rand_alnum 64)"
  done
}
need_secret wp_salts mysql-data && gen wp_salts < <(salts_lines)
need_secret content_event_hmac_key "" && gen content_event_hmac_key < <(openssl rand -hex 32)
need_secret preview_signing_key "" && gen preview_signing_key < <(openssl rand -hex 32)
# 金鑰圈（GAP-KEYRING-01）：缺少才由既有單一金鑰建立 `k1 <金鑰> active`；已存在不覆寫（輪替由 rotate-secrets.sh 改寫）
for pair in content_event_hmac_key:content_event_hmac_keyring preview_signing_key:preview_signing_keyring; do
  single="${pair%%:*}"; ring="${pair##*:}"
  md_secret_ok "$ring" && continue
  [[ -e "$MD_SECRETS_DIR/$ring" ]] && ms_die "$EXIT_FORBIDDEN" "秘密 $ring 存在但為空或權限過寬；請人工檢查（不自動覆寫）"
  [[ "$(tr -d '\r\n' < "$MD_SECRETS_DIR/$single")" =~ ^[0-9a-f]{64}$ ]] \
    || ms_die "$EXIT_FORBIDDEN" "$single 不是 64 字元小寫 hex，無法建立 $ring；請依 docs/runbooks/秘密輪替.md 處理"
  gen "$ring" < <(printf 'k1 %s active\n' "$(tr -d '\r\n' < "$MD_SECRETS_DIR/$single")")
done
# APP_KEY 輪替過渡期的舊金鑰檔：平時為空檔（Compose 需要檔案存在）
md_optional_secret_ok app_previous_keys || {
  [[ -e "$MD_SECRETS_DIR/app_previous_keys" ]] && ms_die "$EXIT_FORBIDDEN" "秘密 app_previous_keys 權限過寬或不是一般檔；請人工檢查"
  gen app_previous_keys < /dev/null
}
need_secret diagnostics_token "" && gen diagnostics_token < <(openssl rand -hex 24)
need_secret deploy_status_token "" && gen deploy_status_token < <(openssl rand -hex 32)
need_secret wp_service_app_password "" && gen wp_service_app_password < <(printf 'PENDING-BOOTSTRAP-%s\n' "$(openssl rand -hex 8)")
# 內部 CA 與 wp-web 內部 TLS（只供容器內 HTTPS）
if [[ ! -s "$MD_SECRETS_DIR/ca/ca.key" ]]; then
  ( umask 077
    openssl genpkey -algorithm EC -pkeyopt ec_paramgen_curve:P-256 -out "$MD_SECRETS_DIR/ca/ca.key" 2>/dev/null
    openssl req -x509 -new -key "$MD_SECRETS_DIR/ca/ca.key" -sha256 -days 825 -subj "/CN=mysite ${env_name} internal CA" \
      -addext "basicConstraints=critical,CA:TRUE" -addext "keyUsage=critical,keyCertSign,cRLSign" -out "$MD_SECRETS_DIR/ca/ca.crt" )
  rm -f -- "$MD_SECRETS_DIR/wp_internal_tls_cert"
fi
md_secret_ok internal_ca_cert || { md_write_secret internal_ca_cert < "$MD_SECRETS_DIR/ca/ca.crt"; generated+=(internal_ca_cert); }
if ! md_secret_ok wp_internal_tls_cert || ! openssl x509 -checkend $((30*86400)) -noout -in "$MD_SECRETS_DIR/wp_internal_tls_cert" >/dev/null 2>&1; then
  tmpd="$(mktemp -d)"
  ( umask 077
    openssl genpkey -algorithm EC -pkeyopt ec_paramgen_curve:P-256 -out "$tmpd/key" 2>/dev/null
    openssl req -new -key "$tmpd/key" -subj "/CN=wp-web" -out "$tmpd/csr"
    printf 'subjectAltName=DNS:wp-web\nextendedKeyUsage=serverAuth\nkeyUsage=critical,digitalSignature\n' > "$tmpd/ext"
    openssl x509 -req -in "$tmpd/csr" -CA "$MD_SECRETS_DIR/ca/ca.crt" -CAkey "$MD_SECRETS_DIR/ca/ca.key" -CAcreateserial \
      -CAserial "$tmpd/srl" -days 397 -sha256 -extfile "$tmpd/ext" -out "$tmpd/crt" 2>/dev/null )
  md_write_secret wp_internal_tls_key < "$tmpd/key"; md_write_secret wp_internal_tls_cert < "$tmpd/crt"
  rm -rf -- "$tmpd"; generated+=(wp_internal_tls_cert wp_internal_tls_key)
fi
(( ${#generated[@]} )) && ms_info "已產生秘密（只寫入檔案，不輸出）：${generated[*]}"
for s in restic_password restic_repository; do
  md_secret_ok "$s" || ms_warn "BLOCKED_EXTERNAL：缺 secrets/$s（備份目的地與密碼由維護者先存入受控保管處，再於主控臺寫入；本腳本不代為產生）"
done

# ---------------------------------------------------------------------------
# 5. 驗簽公鑰（雙期信任：新舊 key_id 可並存；同 key_id 不得被換掉）
# ---------------------------------------------------------------------------
for spec in "${trust_keys[@]}"; do
  kid="${spec%%:*}"; kfile="${spec#*:}"
  [[ "$kid" =~ ^[a-z0-9][a-z0-9-]{2,63}$ ]] || ms_die "$EXIT_USAGE" "key_id 格式不合法：$kid"
  [[ "$kid" == "${env_name}"-* ]] || ms_die "$EXIT_FORBIDDEN" "key_id 必須以環境名稱開頭（${env_name}-…），避免跨環境共用金鑰"
  [[ -f "$kfile" && ! -L "$kfile" ]] || ms_die "$EXIT_USAGE" "公鑰檔不存在：$kfile"
  grep -q 'PRIVATE KEY' "$kfile" && ms_die "$EXIT_FORBIDDEN" "這是私鑰！主機只能保存公鑰"
  openssl pkey -pubin -in "$kfile" -noout -text 2>/dev/null | head -n 1 | grep -q '^ED25519 Public-Key' \
    || ms_die "$EXIT_USAGE" "$kfile 不是 Ed25519 公鑰"
  dest="$MD_TRUST_DIR/${kid}.pem"
  if [[ -f "$dest" ]]; then
    cmp -s "$dest" "$kfile" || ms_die "$EXIT_FORBIDDEN" "key_id ${kid} 已存在且內容不同；輪替必須使用新的 key_id"
    ms_info "公鑰 ${kid} 已安裝（相同內容）"
  else
    install -m 0644 "$kfile" "$dest"
    ms_info "已安裝驗簽公鑰 ${kid}（SHA-256 指紋 $(openssl pkey -pubin -in "$dest" -outform DER 2>/dev/null | sha256sum | cut -c1-16)…）"
  fi
done
[[ -f "$MD_CONFIG_DIR/revoked-digests.txt" ]] || { printf '# 已撤銷（不得再部署或回復）的映像 digest，一行一個 sha256:<64 hex>\n' > "$MD_CONFIG_DIR/revoked-digests.txt"; chmod 0640 "$MD_CONFIG_DIR/revoked-digests.txt"; }

# ---------------------------------------------------------------------------
# 6. systemd unit／timer
# ---------------------------------------------------------------------------
unit_src="$MD_PLATFORM_DIR/infra/systemd"
mkdir -p "$systemd_dir"
for u in "$unit_src"/*.service "$unit_src"/*.timer; do
  [[ -f "$u" ]] || continue
  if (( MD_TEST_ROOT )); then sed "s#/srv/mysite#${MD_HOST_ROOT}#g" "$u" > "$systemd_dir/$(basename "$u")"
  else install -m 0644 "$u" "$systemd_dir/$(basename "$u")"; fi
done
if command -v systemctl >/dev/null 2>&1; then
  systemctl daemon-reload
  timers=("mysite-pull-release@${env_name}.timer" "mysite-certbot-renew.timer")
  if md_restic_ready; then
    timers+=("mysite-backup-wp@${env_name}.timer" "mysite-backup-app@${env_name}.timer" "mysite-backup-check@${env_name}.timer")
  else
    ms_warn "備份 timer 暫不啟用：${MD_RESTIC_MISSING}（BLOCKED_EXTERNAL: 備份目的地）"
  fi
  systemctl enable --now "${timers[@]}" >/dev/null || ms_die "$EXIT_EXTERNAL" "systemd timer 啟用失敗"
  ms_info "已啟用 timer：${timers[*]}"
else
  ms_warn "找不到 systemctl；unit 已複製到 ${systemd_dir}，未啟用"
fi

# ---------------------------------------------------------------------------
# 7. 選用：restic（固定版本＋SHA-256）
# ---------------------------------------------------------------------------
if (( install_restic )); then
  rv="$(md_restic_version)"; rsha="$(md_restic_sha256)"
  bin="${MD_CFG[RESTIC_BIN]}"
  (( MD_TEST_ROOT )) && bin="$MD_HOST_ROOT/bin/restic"
  if [[ -x "$bin" ]] && "$bin" version 2>/dev/null | grep -q "restic ${rv} "; then
    ms_info "restic ${rv} 已安裝"
  else
    tmpd="$(mktemp -d)"
    curl -fsSL --proto '=https' --tlsv1.2 --max-time 300 -o "$tmpd/restic.bz2" \
      "https://github.com/restic/restic/releases/download/v${rv}/restic_${rv}_linux_amd64.bz2" \
      || { rm -rf -- "$tmpd"; ms_die "$EXIT_EXTERNAL" "restic 下載失敗"; }
    printf '%s  %s\n' "$rsha" "$tmpd/restic.bz2" | sha256sum -c --status - || { rm -rf -- "$tmpd"; ms_die "$EXIT_FORBIDDEN" "restic SHA-256 不符，拒絕安裝"; }
    bunzip2 -c "$tmpd/restic.bz2" > "$tmpd/restic"
    mkdir -p "$(dirname "$bin")"
    install -m 0755 "$tmpd/restic" "$bin"
    rm -rf -- "$tmpd"
    ms_info "已安裝 restic ${rv}（SHA-256 相符）"
  fi
fi

# ---------------------------------------------------------------------------
# 8. 選用：首次 HTTP 入口（只有 ACME＋維護頁；不引用任何憑證）
# ---------------------------------------------------------------------------
if (( start_edge )); then
  md_require_hosts
  [[ -z "$(md_state_get active.release_id 2>/dev/null)" ]] || ms_die "$EXIT_FORBIDDEN" "已有發布在執行，ACME 由正式 gateway 提供；不啟動 edge-bootstrap"
  if docker ps -a -q --filter "name=^mysite-edge-bootstrap$" | grep -q .; then
    ms_info "edge-bootstrap 已存在"
  else
    nginx_ref="${MD_CFG[BASE_IMAGE_REGISTRY]}/$(md_version_value NGINX_IMAGE_REF)"
    timeout 600 docker run -d --name mysite-edge-bootstrap --restart unless-stopped -p 80:80 \
      --label mysite.role=edge-bootstrap \
      -e APP_HOST="${MD_CFG[APP_HOST]}" -e CMS_HOST="${MD_CFG[CMS_HOST]}" -e NGINX_ENVSUBST_TEMPLATE_DIR=/etc/nginx/templates/bootstrap \
      -v "$MD_PLATFORM_DIR/infra/nginx/gateway:/etc/nginx/templates:ro" \
      -v "$MD_PLATFORM_DIR/infra/nginx/maintenance:/var/www/maintenance:ro" \
      -v "$MD_TLS_DIR/acme:/var/www/acme:ro" "$nginx_ref" >/dev/null \
      || ms_die "$EXIT_EXTERNAL" "edge-bootstrap 啟動失敗"
    ms_info "已啟動 edge-bootstrap（port 80：ACME 驗證＋維護頁）"
  fi
fi

# ---------------------------------------------------------------------------
# 9. 選用：申請 TLS 憑證（Certbot webroot；失敗保留維護頁，不以自簽憑證充數）
# ---------------------------------------------------------------------------
if (( issue_tls )); then
  md_require_hosts
  [[ "${MD_CFG[ACME_EMAIL]}" =~ @ ]] || ms_die "$EXIT_USAGE" "申請憑證需要主機設定 ACME_EMAIL"
  certbot_ref="$(md_certbot_image)"
  extra=(); [[ "${MD_CFG[ACME_SERVER]}" == staging ]] && extra+=(--test-cert)
  for h in "${MD_CFG[APP_HOST]}" "${MD_CFG[CMS_HOST]}"; do
    timeout 600 docker run --rm \
      -v "$MD_TLS_DIR/letsencrypt:/etc/letsencrypt" -v "$MD_TLS_DIR/certbot-lib:/var/lib/letsencrypt" \
      -v "$MD_TLS_DIR/acme:/var/www/acme" "docker.io/${certbot_ref}" \
      certonly --webroot -w /var/www/acme --cert-name "$h" -d "$h" --email "${MD_CFG[ACME_EMAIL]}" \
      --agree-tos --no-eff-email --non-interactive --keep-until-expiring "${extra[@]}" \
      || ms_die "$EXIT_EXTERNAL" "憑證申請失敗：$h（檢查 DNS A 紀錄與 port 80；維護頁保持不變）"
    [[ -s "$MD_TLS_DIR/letsencrypt/live/$h/fullchain.pem" ]] || ms_die "$EXIT_EXTERNAL" "找不到 $h 的憑證檔"
  done
  if [[ "${MD_CFG[MYSITE_GATEWAY_MODE]}" != https ]]; then
    sed -i 's/^MYSITE_GATEWAY_MODE=.*/MYSITE_GATEWAY_MODE=https/' "$MD_CONFIG_DIR/deploy.env"
    ms_info "已將 MYSITE_GATEWAY_MODE 改為 https"
    if [[ -f "$MD_COMPOSE_ENV" && -n "$(md_state_get active.release_id 2>/dev/null)" ]]; then
      sed -i 's/^MYSITE_GATEWAY_MODE=.*/MYSITE_GATEWAY_MODE=https/' "$MD_COMPOSE_ENV"
      md_compose up -d --no-deps gateway >/dev/null || ms_die "$EXIT_EXTERNAL" "gateway 切換 https 失敗"
      gw="$(md_container gateway)"
      [[ -n "$gw" ]] && docker exec "$gw" nginx -t >/dev/null 2>&1 || ms_die "$EXIT_EXTERNAL" "gateway 組態測試失敗"
      ms_info "gateway 已以 https 模板重建"
    fi
  fi
fi

platform_commit="$(git -C "$MD_PLATFORM_DIR" rev-parse HEAD 2>/dev/null || echo unknown)"
python3 -I -c 'import json,sys
rec={"approved_plan":sys.argv[1],"at":sys.argv[2],"environment":sys.argv[3],"platform_commit":sys.argv[4],
     "generated_secrets":[s for s in sys.argv[5].split(",") if s],"trust_keys_installed":int(sys.argv[6]),
     "options":{"install_restic":sys.argv[7]=="1","start_edge":sys.argv[8]=="1","issue_tls":sys.argv[9]=="1"}}
open(sys.argv[10],"a",encoding="utf-8").write(json.dumps(rec,ensure_ascii=False,sort_keys=True)+"\n")' \
  "$plan" "$(md_now)" "$env_name" "$platform_commit" "$(IFS=,; echo "${generated[*]}")" "${#trust_keys[@]}" \
  "$install_restic" "$start_edge" "$issue_tls" "$MD_STATE_DIR/bootstrap-approvals.jsonl"
md_event INFO host_bootstrap "主機開通步驟完成（核准參照 ${plan}）"
ms_info "完成。下一步：bash $MD_PLATFORM_DIR/scripts/deploy/preflight.sh --env=${env_name}"
