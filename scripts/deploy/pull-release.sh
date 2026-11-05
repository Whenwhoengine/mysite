#!/usr/bin/env bash
# 主機主動拉取發布清單（v5 §14.6；由 systemd timer 約每 5 分鐘執行）：
#   固定 GitHub Releases channel（主機設定提供，不取「最新任何 release」）→ 限制大小與來源取得 envelope asset →
#   驗證 → 相同序號 no-op／新序號交給 release.sh 或 rollback.sh。失敗保留現況並指數退避。
set -Eeuo pipefail
umask 027

usage() {
  cat <<'EOF'
用法：scripts/deploy/pull-release.sh --env=staging|production [--dry-run] [--force-now] [--help]

  --dry-run     只取得並驗證，不部署
  --force-now   忽略退避等待（人工排查用）

來源（全部由 environments/<env>/config/deploy.env 決定）：
  GET https://api.github.com/repos/<RELEASE_REPOSITORY>/releases/tags/<RELEASE_CHANNEL_TAG>
  取名稱等於 <RELEASE_ASSET_NAME> 的單一 asset（上限 64 KiB），只接受 GitHub 網域的 HTTPS 轉址。
  私人 repo 時可放唯讀權杖 secrets/github_read_token（只經 curl -K - 由 stdin 傳入）。
事故處理：建立 state/channel-paused 檔即暫停採用 channel（例如簽章私鑰疑似外洩）。
exit code：0 無事可做／no-op／部署成功；10 參數錯；20 缺設定或工具；40 清單被拒；50 取得失敗或部署未完成（已排退避）。
EOF
}

env_name=""; dry_run=0; force_now=0
for arg in "$@"; do
  case "$arg" in
    --env=*) env_name="${arg#--env=}" ;;
    --dry-run) dry_run=1 ;;
    --force-now) force_now=1 ;;
    -h|--help) usage; exit 0 ;;
    *) printf '未知參數：%s\n' "$arg" >&2; usage >&2; exit 10 ;;
  esac
done

# shellcheck source=../lib/deploy.sh
source "$(dirname "${BASH_SOURCE[0]}")/../lib/deploy.sh"
MD_SCRIPT_NAME=pull-release
[[ -n "$env_name" ]] || { usage >&2; exit "$EXIT_USAGE"; }
ms_require_env_enum "$env_name" staging production
md_require_cmd curl python3 openssl flock
md_resolve_host_root
md_env_paths "$env_name"
md_load_config "$env_name"
mkdir -p "$MD_RELEASES_DIR/incoming" "$MD_RELEASES_DIR/verified"

if [[ -e "$MD_STATE_DIR/channel-paused" ]]; then
  md_event WARN channel_paused "channel 採用已暫停（state/channel-paused）；不拉取、不部署"
  exit "$EXIT_OK"
fi

backoff_file="$MD_STATE_DIR/pull-backoff.json"
now="$(md_epoch)"
next_at="$(python3 -I -c 'import json,sys
try: print(int(json.load(open(sys.argv[1])).get("next_attempt_epoch",0)))
except Exception: print(0)' "$backoff_file")"
if (( ! force_now && now < next_at )); then
  ms_info "退避中：$((next_at - now)) 秒後再試"
  exit "$EXIT_OK"
fi

md_lock "$MD_STATE_DIR/pull.lock" 0 || { ms_info "另一個 pull-release 正在執行"; exit "$EXIT_OK"; }

record_failure() {
  # record_failure <代碼> [<伺服器建議的重試秒數>]：指數退避（暫定：起點 300 秒、上限 3600 秒＋0–60 秒抖動）
  python3 -I - "$backoff_file" "$1" "${2:-0}" "${MD_CFG[PULL_BACKOFF_BASE_SECONDS]}" "${MD_CFG[PULL_BACKOFF_MAX_SECONDS]}" <<'PY'
import json, os, random, sys, time
path, code, hint, base, cap = sys.argv[1], sys.argv[2], int(sys.argv[3]), int(sys.argv[4]), int(sys.argv[5])
try:
    st = json.load(open(path))
except Exception:
    st = {}
n = int(st.get("failures", 0)) + 1
delay = max(min(base * (2 ** (n - 1)), cap), hint) + random.randint(0, 60)
st = {"failures": n, "last_failure": code, "last_failure_at": int(time.time()), "next_attempt_epoch": int(time.time()) + delay}
tmp = path + ".tmp"
with open(tmp, "w") as fh:
    json.dump(st, fh)
os.replace(tmp, path)
print(delay)
PY
}
reset_backoff() { rm -f -- "$backoff_file"; }

fail_fetch() {
  local code="$1" msg="$2" hint="${3:-0}" delay
  delay="$(record_failure "$code" "$hint")"
  md_event WARN pull_failed "${code}：${msg}；保留現況，${delay} 秒後重試"
  exit "$EXIT_DEPLOY_INCOMPLETE"
}

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
repo="${MD_CFG[RELEASE_REPOSITORY]}"; tag="${MD_CFG[RELEASE_CHANNEL_TAG]}"; asset_name="${MD_CFG[RELEASE_ASSET_NAME]}"
tmo="${MD_CFG[PULL_TIMEOUT_SECONDS]}"
auth_cfg() {
  if md_secret_ok github_read_token; then
    printf 'header = "Authorization: Bearer %s"\n' "$(tr -d '\r\n' < "$MD_SECRETS_DIR/github_read_token")"
  fi
}

# 1. channel release 中繼資料（固定 URL、只允許 HTTPS、不跟隨轉址、上限 1 MiB）
meta_code="$(auth_cfg | curl -sS --proto '=https' --tlsv1.2 --max-redirs 0 --max-time "$tmo" --max-filesize 1048576 -K - \
  -H 'Accept: application/vnd.github+json' -H 'X-GitHub-Api-Version: 2022-11-28' -H 'User-Agent: mysite-pull-release' \
  -D "$work/meta.headers" -o "$work/meta.json" -w '%{http_code}' \
  "https://api.github.com/repos/${repo}/releases/tags/${tag}" 2>/dev/null)" || meta_code="000"
case "$meta_code" in
  200) ;;
  404) fail_fetch CHANNEL_NOT_FOUND "channel ${tag} 不存在或尚無發布" ;;
  403|429)
    retry="$(grep -i '^retry-after:' "$work/meta.headers" 2>/dev/null | tr -dc '0-9' | head -c 6 || true)"
    fail_fetch RATE_LIMITED "GitHub API 限流（HTTP ${meta_code}）" "${retry:-0}" ;;
  *) fail_fetch FETCH_FAILED "無法取得 channel 中繼資料（HTTP ${meta_code}）" ;;
esac

asset_url="$(python3 -I - "$work/meta.json" "$asset_name" "$repo" <<'PY'
import json, re, sys
d = json.load(open(sys.argv[1], encoding="utf-8"))
if d.get("draft"):
    sys.exit(3)
cands = [a for a in d.get("assets") or [] if a.get("name") == sys.argv[2]]
if len(cands) != 1:
    sys.exit(4)
a = cands[0]
if a.get("state") != "uploaded" or not isinstance(a.get("size"), int) or a["size"] > 65536:
    sys.exit(5)
url = a.get("url", "")
if not re.fullmatch(r"https://api\.github\.com/repos/" + re.escape(sys.argv[3]) + r"/releases/assets/[0-9]{1,20}", url):
    sys.exit(6)
print(url)
PY
)" || { rc=$?; case "$rc" in
  3) fail_fetch CHANNEL_DRAFT "channel release 為草稿" ;;
  4) fail_fetch ASSET_MISSING "channel 中沒有唯一的 ${asset_name}（可能正在更新）" ;;
  5) fail_fetch ASSET_INVALID "envelope asset 尚未上傳完成或超過大小上限" ;;
  *) fail_fetch ASSET_URL_INVALID "asset 來源不是預期的 GitHub API 位址" ;;
esac; }

# 2. 下載 asset（只接受 HTTPS 與 GitHub 網域轉址；上限 64 KiB）
incoming="$MD_RELEASES_DIR/incoming/envelope-$(date -u +%Y%m%dT%H%M%SZ).json"
dl="$(auth_cfg | curl -sS -L --proto '=https' --proto-redir '=https' --tlsv1.2 --max-redirs 3 --max-time "$tmo" --max-filesize 65536 -K - \
  -H 'Accept: application/octet-stream' -H 'User-Agent: mysite-pull-release' \
  -o "$work/envelope.json" -w '%{http_code} %{url_effective} %{size_download}' "$asset_url" 2>/dev/null)" || dl="000"
read -r dl_code dl_url dl_size <<< "$dl"
[[ "$dl_code" == 200 ]] || fail_fetch ASSET_DOWNLOAD_FAILED "下載 envelope 失敗（HTTP ${dl_code}）"
case "$dl_url" in
  https://api.github.com/*|https://github.com/*|https://objects.githubusercontent.com/*|https://release-assets.githubusercontent.com/*) ;;
  *) fail_fetch ASSET_SOURCE_INVALID "envelope 最終來源不在核准網域" ;;
esac
[[ "${dl_size%.*}" =~ ^[0-9]+$ ]] && (( ${dl_size%.*} <= 65536 )) || fail_fetch ASSET_TOO_LARGE "envelope 超過大小上限"
install -m 0640 "$work/envelope.json" "$incoming"

# 3. 驗證
rc=0
md_verify_envelope "$incoming" "$work" || rc=$?
case "$rc" in
  0) ;;
  3) reset_backoff; ms_info "NOOP：$(md_verify_message "$work/verify.json")"; rm -f -- "$incoming"; exit "$EXIT_OK" ;;
  4) msg="$(md_verify_message "$work/verify.json")"; record_failure REJECTED >/dev/null
     md_event ERROR release_rejected "channel 清單被拒：${msg}"; exit "$EXIT_FORBIDDEN" ;;
  5) record_failure VERIFY_ENV >/dev/null; ms_die "$EXIT_EXTERNAL" "驗證環境缺件（openssl、信任目錄或狀態檔）" ;;
  *) ms_die "$EXIT_USAGE" "驗證工具參數錯誤（rc=$rc）" ;;
esac
md_read_release_env "$work/release.env"
seq="${MD_REL[RELEASE_SEQUENCE]}"
verified="$MD_RELEASES_DIR/verified/seq-${seq}.envelope.json"
install -m 0640 "$incoming" "$verified"
rm -f -- "$incoming"
if (( dry_run )); then
  ms_info "模擬測試：sequence=${seq} release=${MD_REL[RELEASE_ID]} 驗證通過；未部署"
  exit "$EXIT_OK"
fi

# 4. 等待人工核准的清單（schema contract／breaking、空環境首次安裝）不由 timer 重試，以免耗盡重試次數
if [[ "$(md_state_get pending.sequence)" == "$seq" && "$(md_state_get pending.status)" == awaiting_approval ]]; then
  record_failure AWAITING_APPROVAL >/dev/null
  md_event WARN awaiting_approval "sequence=${seq} 等待人工核准（$(md_state_get pending.last_failure.code)）；請依 docs/runbooks/部署與回復.md 以核准參照執行 release.sh"
  exit "$EXIT_FORBIDDEN"
fi

# 5. 交給固定部署 wrapper（不執行清單內任何內容）
drc=0
if [[ -n "${MD_REL[RELEASE_ROLLBACK_TARGET]:-}" ]]; then
  bash "$MD_PLATFORM_DIR/scripts/deploy/rollback.sh" --env="$env_name" --approved-release="$verified" || drc=$?
else
  bash "$MD_PLATFORM_DIR/scripts/deploy/release.sh" --env="$env_name" --verified-envelope="$verified" || drc=$?
fi
if (( drc == 0 )); then reset_backoff; exit "$EXIT_OK"; fi
delay="$(record_failure "DEPLOY_EXIT_${drc}")"
md_event WARN deploy_not_completed "sequence=${seq} 部署未完成（exit ${drc}）；${delay} 秒後重試（同一清單重試次數由 release.sh 限制）"
exit "$drc"
