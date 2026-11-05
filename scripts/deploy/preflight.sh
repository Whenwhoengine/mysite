#!/usr/bin/env bash
# 部署前置檢查（不部署、不改服務）：授權／設定、目錄權限、信任公鑰、秘密檔、磁碟、Docker、DB、
# 備份目的地、時間同步（v5 §13、附錄 D.4）。結果寫入 state/preflight.json（不含秘密）。
set -Eeuo pipefail
umask 027

usage() {
  cat <<'EOF'
用法：scripts/deploy/preflight.sh --env=staging|production [--skip-backup-probe] [--help]

檢查（每項 PASS／FAIL，全部 PASS 才 exit 0）：
  config        主機設定可解析、環境一致、APP_HOST／CMS_HOST 已核准
  layout        /srv/mysite 目錄存在且權限正確（secrets 0750 以下、其他人不可讀）
  trust_keys    至少一把 Ed25519 信任公鑰；目錄不可被群組／其他人寫入
  secrets       正式秘密檔齊全、非空、其他人不可讀（含 deploy_status_token、restic_*）
  disk          主機根目錄可用空間 ≥ MIN_FREE_DISK_MB
  docker        Docker daemon 與 Compose v2 可用
  database      若 MySQL 已在執行：mysqladmin ping 成功
  backup        restic 可開啟 repository（--skip-backup-probe 可略過網路探測）
  time_sync     systemd-timesyncd／chrony 回報已同步（簽章有效期依賴正確時間）
exit code：0 全部通過；10 設定錯；20 缺外部前置（主機、DNS、秘密、備份目的地等）。
EOF
}

env_name=""; probe_backup=1
for arg in "$@"; do
  case "$arg" in
    --env=*) env_name="${arg#--env=}" ;;
    --skip-backup-probe) probe_backup=0 ;;
    -h|--help) usage; exit 0 ;;
    *) printf '未知參數：%s\n' "$arg" >&2; usage >&2; exit 10 ;;
  esac
done

# shellcheck source=../lib/deploy.sh
source "$(dirname "${BASH_SOURCE[0]}")/../lib/deploy.sh"
MD_SCRIPT_NAME=preflight
[[ -n "$env_name" ]] || { usage >&2; exit "$EXIT_USAGE"; }
ms_require_env_enum "$env_name" staging production
md_require_cmd python3 openssl
md_resolve_host_root
md_env_paths "$env_name"

declare -A res=()
pass() { res[$1]="PASS"; ms_info "✓ $1"; }
failc() { res[$1]="FAIL: $2"; ms_err "✗ $1：$2"; }

# config（設定錯誤是 exit 10；以子程序載入以便收集結果）
if ( md_load_config "$env_name" ) 2>"$MD_ENV_DIR.preflight-config.err"; then
  md_load_config "$env_name"
  if [[ "${MD_CFG[APP_HOST]}" =~ $MD_RE_HOST && "${MD_CFG[CMS_HOST]}" =~ $MD_RE_HOST ]]; then pass config
  else failc config "APP_HOST／CMS_HOST 未設定（BLOCKED_EXTERNAL: DNS 未授權）"; fi
  config_ok=1
else
  failc config "主機設定不存在或不合法：$(tail -n 1 "$MD_ENV_DIR.preflight-config.err" 2>/dev/null | ms_redact)"
  config_ok=0
fi
rm -f "$MD_ENV_DIR.preflight-config.err" 2>/dev/null

# layout
layout_err=""
for d in "$MD_CONFIG_DIR" "$MD_SECRETS_DIR" "$MD_RELEASES_DIR" "$MD_STATE_DIR" "$MD_TLS_DIR/acme" "$MD_TLS_DIR/letsencrypt" "$MD_BACKUP_STAGING"; do
  [[ -d "$d" ]] || { layout_err+="缺 ${d#"$MD_HOST_ROOT"/} "; continue; }
done
if [[ -d "$MD_SECRETS_DIR" ]]; then
  m="$(stat -c '%a' "$MD_SECRETS_DIR")"; (( (8#$m & 8#027) == 0 )) || layout_err+="secrets 權限過寬(${m}) "
fi
if [[ -d "$MD_BACKUP_STAGING" ]]; then
  m="$(stat -c '%a' "$MD_BACKUP_STAGING")"; (( (8#$m & 8#077) == 0 )) || layout_err+="backup-staging 權限過寬(${m}) "
fi
[[ -z "$layout_err" ]] && pass layout || failc layout "$layout_err"

# trust_keys
n_keys=0; tk_err=""
if [[ -d "$MD_TRUST_DIR" ]]; then
  m="$(stat -c '%a' "$MD_TRUST_DIR")"; (( (8#$m & 8#022) == 0 )) || tk_err+="信任目錄可被群組／其他人寫入 "
  for k in "$MD_TRUST_DIR"/*.pem; do
    [[ -f "$k" ]] || continue
    if openssl pkey -pubin -in "$k" -noout -text 2>/dev/null | head -n 1 | grep -q '^ED25519 Public-Key'; then n_keys=$((n_keys + 1))
    else tk_err+="$(basename "$k") 不是 Ed25519 公鑰 "; fi
  done
fi
(( n_keys > 0 )) || tk_err+="沒有任何信任公鑰 "
[[ -z "$tk_err" ]] && pass trust_keys || failc trust_keys "$tk_err"

# secrets
missing=""
for s in mysql_root_password wp_db_password app_db_password app_key wp_salts content_event_hmac_key preview_signing_key \
         wp_service_app_password internal_ca_cert wp_internal_tls_cert wp_internal_tls_key diagnostics_token \
         deploy_status_token restic_password restic_repository; do
  md_secret_ok "$s" || missing+="$s "
done
# 金鑰圈（GAP-KEYRING-01）：必須存在且格式合法；app_previous_keys 可為空但必須存在（Compose 掛載）
for s in content_event_hmac_keyring preview_signing_keyring; do
  md_keyring_ok "$s" || missing+="$s(缺少或格式不合法) "
done
md_optional_secret_ok app_previous_keys || missing+="app_previous_keys "
[[ -z "$missing" ]] && pass secrets || failc secrets "缺少或權限過寬：${missing}"

# disk
min_mb=3072; (( config_ok )) && min_mb="${MD_CFG[MIN_FREE_DISK_MB]}"
free="$(md_free_mb "$MD_HOST_ROOT" 2>/dev/null || echo 0)"
[[ "$free" =~ ^[0-9]+$ ]] && (( free >= min_mb )) && pass disk || failc disk "可用 ${free:-?} MB < ${min_mb} MB"

# docker
if md_docker_ok && timeout 20 docker compose version >/dev/null 2>&1; then pass docker; else failc docker "Docker daemon 或 Compose v2 不可用"; fi

# database（只在已部署時檢查；不啟動任何服務）
if (( config_ok )) && md_docker_ok; then
  MD_PROJECT="$(md_project_name "$env_name")"
  cid="$(md_container mysql)"
  if [[ -z "$cid" ]]; then res[database]="SKIPPED: 尚未部署"
  elif timeout 20 docker exec "$cid" mysqladmin ping -h 127.0.0.1 --silent >/dev/null 2>&1; then pass database
  else failc database "MySQL 未回應"; fi
else
  res[database]="SKIPPED: 設定或 Docker 不可用"
fi

# backup
if (( config_ok )) && md_restic_ready; then
  if (( probe_backup )); then
    if MD_RESTIC_TIMEOUT=120 md_restic cat config >/dev/null 2>&1; then pass backup
    else failc backup "無法開啟 restic repository（目的地、憑證或密碼錯誤；未初始化請先 restic init）"; fi
  else
    res[backup]="SKIPPED: --skip-backup-probe"
  fi
else
  failc backup "BLOCKED_EXTERNAL：${MD_RESTIC_MISSING:-主機設定不可用}"
fi

# time_sync
if command -v timedatectl >/dev/null 2>&1 && [[ "$(timedatectl show -p NTPSynchronized --value 2>/dev/null)" == yes ]]; then pass time_sync
elif command -v chronyc >/dev/null 2>&1 && chronyc tracking 2>/dev/null | grep -q 'Leap status *: Normal'; then pass time_sync
else failc time_sync "無法確認時間同步（timedatectl／chrony）"; fi

tsv="$(mktemp)"
for k in "${!res[@]}"; do printf '%s\t%s\n' "$k" "${res[$k]//$'\t'/ }" >> "$tsv"; done
python3 -I - "$MD_STATE_DIR" "$env_name" "$(md_now)" "$tsv" <<'PY'
import json, os, sys
state, env, at, tsv = sys.argv[1:5]
res = {}
with open(tsv, encoding="utf-8") as fh:
    for line in fh:
        k, _, v = line.rstrip("\n").partition("\t")
        if k:
            res[k] = v
out = {"environment": env, "checked_at": at, "checks": dict(sorted(res.items())),
       "result": "PASS" if all(v == "PASS" or v.startswith("SKIPPED") for v in res.values()) else "FAIL"}
print(json.dumps(out, ensure_ascii=False, sort_keys=True))
if os.path.isdir(state):
    with open(os.path.join(state, "preflight.json"), "w", encoding="utf-8") as fh:
        json.dump(out, fh, ensure_ascii=False, indent=2, sort_keys=True)
PY
rm -f -- "$tsv"

if (( ! config_ok )); then exit "$EXIT_USAGE"; fi
for v in "${res[@]}"; do
  [[ "$v" == PASS || "$v" == SKIPPED* ]] || exit "$EXIT_EXTERNAL"
done
exit "$EXIT_OK"
