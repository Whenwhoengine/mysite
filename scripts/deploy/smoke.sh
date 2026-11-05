#!/usr/bin/env bash
# 部署冒煙測試：核對主機實際執行的 release ID＋必要 HTTPS 流程（v5 §14.8）。
# 逾時一律記 DEPLOYMENT_UNCONFIRMED（exit 50），不得當成成功。
set -Eeuo pipefail
umask 027

usage() {
  cat <<'EOF'
用法：scripts/deploy/smoke.sh --env=staging|production --expected-release=<release_id> [--timeout=<秒>] [--external] [--help]

檢查項目（全部通過才 exit 0）：
  1. internal_status：在 app-web 容器內以部署狀態權杖讀 /internal/deploy-status，
     release_id／environment 必須相符且 status=active
  2. https_status：經 gateway 443（受信任憑證，不跳過 TLS 驗證）讀同一端點並核對 release_id
  3. https_app／https_cms：主站 /healthz、CMS /wp-json/site-content/v1/health 回 200
  4. http_redirect：主站 http:// 轉 https://
  預設以 --resolve 指向本機 gateway（不依賴主機對自身公網 IP 的回送）；--external 改走公開 DNS。
  gateway 仍是 bootstrap 模式（尚無憑證）時 2–4 無法執行，結果為 DEPLOYMENT_UNCONFIRMED。

權杖：environments/<env>/secrets/deploy_status_token（只經 curl -K - 由 stdin 傳入，不出現在命令列）
exit code：0 確認；10 參數錯；20 缺權杖／設定；30 release ID 不符（實際換版失敗）；50 DEPLOYMENT_UNCONFIRMED。
EOF
}

env_name=""; expected=""; timeout_s=""; external=0
for arg in "$@"; do
  case "$arg" in
    --env=*) env_name="${arg#--env=}" ;;
    --expected-release=*) expected="${arg#--expected-release=}" ;;
    --timeout=*) timeout_s="${arg#--timeout=}" ;;
    --external) external=1 ;;
    -h|--help) usage; exit 0 ;;
    *) printf '未知參數：%s\n' "$arg" >&2; usage >&2; exit 10 ;;
  esac
done

# shellcheck source=../lib/deploy.sh
source "$(dirname "${BASH_SOURCE[0]}")/../lib/deploy.sh"
MD_SCRIPT_NAME=smoke
[[ -n "$env_name" && -n "$expected" ]] || { usage >&2; exit "$EXIT_USAGE"; }
ms_require_env_enum "$env_name" staging production
[[ "$expected" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{2,79}$ ]] || ms_die "$EXIT_USAGE" "--expected-release 格式不合法"
md_require_cmd python3 curl docker
md_resolve_host_root
md_env_paths "$env_name"
md_load_config "$env_name"
md_require_hosts
MD_PROJECT="$(md_project_name "$env_name")"
[[ -z "$timeout_s" ]] && timeout_s="${MD_CFG[SMOKE_TIMEOUT_SECONDS]}"
[[ "$timeout_s" =~ ^[0-9]{1,5}$ ]] || ms_die "$EXIT_USAGE" "--timeout 必須是整數秒"
md_secret_ok deploy_status_token || ms_die "$EXIT_EXTERNAL" "缺少 secrets/deploy_status_token（部署狀態唯讀權杖）"

app_host="${MD_CFG[APP_HOST]}"; cms_host="${MD_CFG[CMS_HOST]}"
mode="${MD_CFG[MYSITE_GATEWAY_MODE]}"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

curl_cfg() { printf 'header = "Authorization: Bearer %s"\n' "$(tr -d '\r\n' < "$MD_SECRETS_DIR/deploy_status_token")"; }

# judge <json檔>：ok／mismatch／not_ready
judge() {
  python3 -I - "$1" "$expected" "$env_name" <<'PY'
import json, sys
try:
    d = json.load(open(sys.argv[1], encoding="utf-8"))
except Exception:
    print("not_ready"); sys.exit(0)
if not isinstance(d, dict):
    print("not_ready"); sys.exit(0)
if d.get("release_id") != sys.argv[2] or d.get("environment") != sys.argv[3]:
    print("mismatch"); sys.exit(0)
print("ok" if d.get("status") == "active" else "not_ready")
PY
}

check_internal() {
  local cid out="$work/internal.json" code
  cid="$(md_container app-web)"
  [[ -n "$cid" ]] || { echo not_ready; return; }
  code="$(curl_cfg | timeout 20 docker exec -i "$cid" curl -sS --max-time 10 -K - -w '\n%{http_code}' \
    http://127.0.0.1:8080/internal/deploy-status 2>/dev/null | tee "$out.raw" | tail -n 1)" || true
  head -n -1 "$out.raw" > "$out" 2>/dev/null || true
  [[ "$code" == 200 ]] || { echo not_ready; return; }
  judge "$out"
}

resolve_args() {
  local host="$1" port="$2"
  (( external )) || printf -- '--resolve\n%s:%s:127.0.0.1\n' "$host" "$port"
}

check_https_status() {
  local out="$work/https.json" code
  local -a ra; mapfile -t ra < <(resolve_args "$app_host" 443)
  code="$(curl_cfg | curl -sS --proto '=https' --tlsv1.2 --max-time 10 --max-redirs 0 "${ra[@]}" -K - \
    -o "$out" -w '%{http_code}' "https://${app_host}/internal/deploy-status" 2>/dev/null)" || true
  [[ "$code" == 200 ]] || { echo not_ready; return; }
  judge "$out"
}

check_get() {
  # check_get <host> <path> <期望狀態碼>
  local host="$1" path="$2" want="$3" code
  local -a ra; mapfile -t ra < <(resolve_args "$host" 443)
  code="$(curl -sS --proto '=https' --tlsv1.2 --max-time 10 --max-redirs 0 "${ra[@]}" -o /dev/null -w '%{http_code}' \
    "https://${host}${path}" 2>/dev/null)" || true
  [[ "$code" == "$want" ]] && echo ok || echo not_ready
}

check_redirect() {
  local loc
  local -a ra; mapfile -t ra < <(resolve_args "$app_host" 80)
  loc="$(curl -sS --proto '=http' --max-time 10 --max-redirs 0 "${ra[@]}" -o /dev/null -w '%{http_code} %{redirect_url}' \
    "http://${app_host}/" 2>/dev/null)" || true
  [[ "$loc" == "301 https://${app_host}/" ]] && echo ok || echo not_ready
}

deadline=$(( $(md_epoch) + timeout_s ))
declare -A result=()
while :; do
  result[internal_status]="$(check_internal)"
  if [[ "$mode" == https ]]; then
    result[https_status]="$(check_https_status)"
    result[https_app]="$(check_get "$app_host" /healthz 200)"
    result[https_cms]="$(check_get "$cms_host" /wp-json/site-content/v1/health 200)"
    result[http_redirect]="$(check_redirect)"
  else
    result[https_status]="skipped_no_tls"; result[https_app]="skipped_no_tls"
    result[https_cms]="skipped_no_tls"; result[http_redirect]="skipped_no_tls"
  fi
  all_ok=1
  for k in internal_status https_status https_app https_cms http_redirect; do
    [[ "${result[$k]}" == ok ]] || all_ok=0
  done
  (( all_ok )) && break
  (( $(md_epoch) >= deadline )) && break
  sleep "${MD_SMOKE_INTERVAL:-5}"
done

verdict=CONFIRMED; rc=$EXIT_OK
if (( ! all_ok )); then
  if [[ "${result[internal_status]}" == mismatch || "${result[https_status]}" == mismatch ]]; then
    verdict=RELEASE_MISMATCH; rc=$EXIT_TEST_FAIL
  else
    verdict=DEPLOYMENT_UNCONFIRMED; rc=$EXIT_DEPLOY_INCOMPLETE
  fi
fi
summary="$(python3 -I -c '
import json,sys
keys=["internal_status","https_status","https_app","https_cms","http_redirect"]
vals=dict(zip(keys,sys.argv[5:10]))
print(json.dumps({"environment":sys.argv[1],"expected_release":sys.argv[2],"verdict":sys.argv[3],"checked_at":sys.argv[4],
  "checks":vals,"https_via":"local-gateway" if sys.argv[10]=="0" else "public-dns"},ensure_ascii=False,sort_keys=True))
' "$env_name" "$expected" "$verdict" "$(md_now)" "${result[internal_status]}" "${result[https_status]}" "${result[https_app]}" \
  "${result[https_cms]}" "${result[http_redirect]}" "$external")"
if [[ -d "$MD_STATE_DIR" ]]; then printf '%s\n' "$summary" > "$MD_STATE_DIR/smoke-last.json"; fi
printf '%s\n' "$summary"
case "$verdict" in
  CONFIRMED) md_event INFO smoke_confirmed "release ${expected} 已確認" ;;
  RELEASE_MISMATCH) md_event ERROR smoke_mismatch "主機回報的 release 與 ${expected} 不符" ;;
  *) md_event WARN deployment_unconfirmed "DEPLOYMENT_UNCONFIRMED：${timeout_s} 秒內無法確認 ${expected}" ;;
esac
exit "$rc"
