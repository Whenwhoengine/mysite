#!/usr/bin/env bash
# 驗證發布 envelope：驗簽 → 嚴格 schema → 環境／channel → 序號 → 時效（v5 §14.5–14.6，OPS-03／OPS-04）。
# 只回報驗證結果，不改任何主機狀態、不部署。
set -Eeuo pipefail
umask 027

usage() {
  cat <<'EOF'
用法：scripts/deploy/verify-release.sh --env=staging|production --envelope=<檔案> [--json] [--help]

  --env=<環境>        本主機的環境（必須與主機設定 DEPLOY_ENVIRONMENT 一致）
  --envelope=<檔案>   受限大小的 envelope JSON（payload_base64／signature_base64／key_id／signature_algorithm）
  --json              以 JSON 輸出驗證結果（不含 payload 內容以外的秘密；清單本身不得含秘密）

信任公鑰：$MYSITE_HOST_ROOT/environments/<env>/config/trusted-keys/<key_id>.pem（預設根目錄 /srv/mysite）
exit code：0 通過（ACCEPT 或相同序號已套用的 NOOP）；10 參數／設定錯；20 缺信任目錄／openssl；40 清單被拒絕。
EOF
}

env_name=""; envelope=""; as_json=0
for arg in "$@"; do
  case "$arg" in
    --env=*) env_name="${arg#--env=}" ;;
    --envelope=*) envelope="${arg#--envelope=}" ;;
    --json) as_json=1 ;;
    -h|--help) usage; exit 0 ;;
    *) printf '未知參數：%s\n' "$arg" >&2; usage >&2; exit 10 ;;
  esac
done

# shellcheck source=../lib/deploy.sh
source "$(dirname "${BASH_SOURCE[0]}")/../lib/deploy.sh"
MD_SCRIPT_NAME=verify-release
[[ -n "$env_name" && -n "$envelope" ]] || { usage >&2; exit "$EXIT_USAGE"; }
ms_require_env_enum "$env_name" staging production
[[ -f "$envelope" && ! -L "$envelope" ]] || ms_die "$EXIT_USAGE" "envelope 必須是存在的一般檔案"
md_require_cmd python3 openssl
md_resolve_host_root
md_env_paths "$env_name"
md_load_config "$env_name"
[[ -d "$MD_TRUST_DIR" ]] || ms_die "$EXIT_EXTERNAL" "缺少信任公鑰目錄（請以 bootstrap-host.sh 安裝公鑰）"

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
rc=0
md_verify_envelope "$envelope" "$work" || rc=$?
msg="$(md_verify_message "$work/verify.json")"
(( as_json )) && cat "$work/verify.json"
case "$rc" in
  0) ms_info "ACCEPT：${msg}（sequence=$(python3 -I -c 'import json,sys;print(json.load(open(sys.argv[1]))["sequence"])' "$work/verify.json")）"; exit "$EXIT_OK" ;;
  3) ms_info "NOOP：${msg}"; exit "$EXIT_OK" ;;
  4) md_event ERROR release_rejected "$msg"; ms_die "$EXIT_FORBIDDEN" "REJECT：${msg}" ;;
  5) ms_die "$EXIT_EXTERNAL" "驗證環境缺件（openssl、信任目錄或狀態檔）" ;;
  *) ms_die "$EXIT_USAGE" "驗證工具參數錯誤（rc=$rc）" ;;
esac
