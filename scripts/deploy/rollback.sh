#!/usr/bin/env bash
# 程式回復（v5 §15.5，OPS-11）：以「新的、更大序號」的已簽章回復清單，把程式換回本主機曾成功啟用、
# schema 相容且未撤銷的舊 digest。只換程式，不倒帶資料：不執行 migrate:rollback、不還原 DB、不刪 volume。
set -Eeuo pipefail
umask 027

usage() {
  cat <<'EOF'
用法：scripts/deploy/rollback.sh --env=staging|production --approved-release=<回復清單 envelope> [--lock-wait=<秒>] [--help]

回復清單必須同時滿足：
  1. 驗簽、schema、環境、channel 全部通過，且 sequence 大於本主機已接受序號（不能重播舊清單）
  2. rollback_target_release 是本主機歷史中曾成功啟用的 release，images 與當時紀錄完全相同
  3. 目標之後套用過的版本只有 none／expand schema 變更（含 contract／breaking 即拒絕，需維護＋核准方案）
  4. schema_change_class=none、所有 digest 不在主機撤銷清單
流程：部署鎖 → pending → 拉齊 digest → 回復前備份（失敗即停）→ 換版（不跑 migration）→ smoke → active
exit code：0 成功；10 參數錯；20 缺外部前置；30 smoke 失敗（已回到回復前版本）；40 拒絕；50 未完成或 DEPLOYMENT_UNCONFIRMED。
EOF
}

env_name=""; envelope=""; lock_wait=10
for arg in "$@"; do
  case "$arg" in
    --env=*) env_name="${arg#--env=}" ;;
    --approved-release=*) envelope="${arg#--approved-release=}" ;;
    --lock-wait=*) lock_wait="${arg#--lock-wait=}" ;;
    -h|--help) usage; exit 0 ;;
    *) printf '未知參數：%s\n' "$arg" >&2; usage >&2; exit 10 ;;
  esac
done

# shellcheck source=../lib/deploy.sh
source "$(dirname "${BASH_SOURCE[0]}")/../lib/deploy.sh"
MD_SCRIPT_NAME=rollback
[[ -n "$env_name" && -n "$envelope" ]] || { usage >&2; exit "$EXIT_USAGE"; }
ms_require_env_enum "$env_name" staging production
[[ "$lock_wait" =~ ^[0-9]{1,4}$ ]] || ms_die "$EXIT_USAGE" "--lock-wait 必須是整數秒"
[[ -f "$envelope" && ! -L "$envelope" ]] || ms_die "$EXIT_USAGE" "回復清單必須是存在的一般檔案"
md_require_cmd python3 openssl flock docker
md_resolve_host_root
md_env_paths "$env_name"
md_load_config "$env_name"
md_require_hosts
MD_PROJECT="$(md_project_name "$env_name")"

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

verify_or_exit() {
  local rc=0
  md_verify_envelope "$envelope" "$work" || rc=$?
  case "$rc" in
    0) ;;
    3) ms_info "NOOP：$(md_verify_message "$work/verify.json")"; exit "$EXIT_OK" ;;
    4) md_event ERROR rollback_rejected "$(md_verify_message "$work/verify.json")"; exit "$EXIT_FORBIDDEN" ;;
    5) ms_die "$EXIT_EXTERNAL" "驗證環境缺件" ;;
    *) ms_die "$EXIT_USAGE" "驗證工具參數錯誤（rc=$rc）" ;;
  esac
  md_read_release_env "$work/release.env"
  [[ -n "${MD_REL[RELEASE_ROLLBACK_TARGET]:-}" ]] || ms_die "$EXIT_USAGE" "這不是回復清單（rollback_target_release 為空）；一般發布請用 release.sh"
}
verify_or_exit

md_lock "$MD_STATE_DIR/deploy.lock" "$lock_wait" || ms_die "$EXIT_DEPLOY_INCOMPLETE" "LOCK_BUSY：部署鎖被占用"
verify_or_exit
seq="${MD_REL[RELEASE_SEQUENCE]}"; rid="${MD_REL[RELEASE_ID]}"; target="${MD_REL[RELEASE_ROLLBACK_TARGET]}"

rc=0
md_manifest rollback-check --state-dir "$MD_STATE_DIR" --environment "$env_name" --payload "$work/payload.json" > "$work/check.json" || rc=$?
if (( rc != 0 )); then
  md_event ERROR rollback_refused "$(md_verify_message "$work/check.json")"
  ms_die "$EXIT_FORBIDDEN" "回復被拒：$(md_verify_message "$work/check.json")"
fi

rc=0
md_manifest state-begin --state-dir "$MD_STATE_DIR" --environment "$env_name" --payload "$work/payload.json" \
  --max-attempts "${MD_CFG[RELEASE_MAX_ATTEMPTS]}" --kind rollback > "$work/begin.json" || rc=$?
case "$rc" in
  0) ;;
  6) ms_die "$EXIT_DEPLOY_INCOMPLETE" "RETRY_LIMIT_EXCEEDED：回復清單已達重試上限，需要新序號清單" ;;
  4) ms_die "$EXIT_FORBIDDEN" "$(md_verify_message "$work/begin.json")" ;;
  *) ms_die "$EXIT_EXTERNAL" "無法寫入主機發布狀態（rc=$rc）" ;;
esac

current_env="$MD_COMPOSE_ENV"; prev_env="$MD_STATE_DIR/compose.env.prev"
fail() {
  local code="$1" ex="$2" msg="$3"
  trap - ERR
  md_state_step --step "$code" --status failed --reason "$code" || true
  md_event ERROR rollback_failed "${code}：${msg}"
  ms_die "$ex" "${code}：${msg}"
}
trap 'trap - ERR; fail UNEXPECTED_ERROR "$EXIT_DEPLOY_INCOMPLETE" "第 ${LINENO} 行意外失敗"' ERR
install -m 0640 "$envelope" "$MD_RELEASES_DIR/seq-${seq}.envelope.json"
install -m 0640 "$work/payload.json" "$MD_RELEASES_DIR/seq-${seq}.payload.json"
md_event INFO rollback_begin "開始程式回復：${rid}（sequence=${seq}）→ 目標 ${target}；資料不倒帶"

md_state_step --step capacity
md_docker_ok || fail DOCKER_UNAVAILABLE "$EXIT_EXTERNAL" "Docker daemon 不可用"
free="$(md_free_mb "$MD_HOST_ROOT")"; min_mb="${MD_CFG[MIN_FREE_DISK_MB]}"
[[ "$free" =~ ^[0-9]+$ ]] && (( free >= min_mb )) || fail INSUFFICIENT_DISK "$EXIT_EXTERNAL" "主機可用空間不足"

md_state_step --step pull
for key in MYSITE_IMAGE_LARAVEL MYSITE_IMAGE_WORDPRESS MYSITE_IMAGE_APP_WEB MYSITE_IMAGE_WP_WEB MYSITE_IMAGE_GATEWAY; do
  ref="${MD_REL[$key]}"
  timeout 900 docker pull --quiet "$ref" >/dev/null 2>&1 || fail IMAGE_PULL_FAILED "$EXIT_DEPLOY_INCOMPLETE" "無法拉取 ${ref%@*}"
  md_image_digest_ok "$ref" || fail IMAGE_DIGEST_MISMATCH "$EXIT_FORBIDDEN" "本機映像 digest 與清單不符：${ref%@*}"
done

md_state_step --step backup
bash "$MD_PLATFORM_DIR/scripts/ops/backup.sh" --env="$env_name" --reason="pre-rollback-seq-${seq}" --scope=all \
  || fail BACKUP_FAILED "$EXIT_DEPLOY_INCOMPLETE" "回復前備份失敗，停止（現有服務未變動）"

md_state_step --step switch
md_write_compose_env "$MD_STATE_DIR/compose.env.next"
[[ -f "$current_env" ]] && cp -p "$current_env" "$prev_env"
mv -f "$MD_STATE_DIR/compose.env.next" "$current_env"


back_to_before() {
  [[ -f "$prev_env" ]] || return 1
  cp -p "$current_env" "$MD_STATE_DIR/compose.env.failed-seq-${seq}"
  cp -p "$prev_env" "$current_env"
  md_compose up -d --wait --wait-timeout 300 >/dev/null 2>&1
}

if ! md_compose up -d --wait --wait-timeout 300 >/dev/null; then
  back_to_before && fail SWITCH_FAILED_REVERTED "$EXIT_DEPLOY_INCOMPLETE" "回復目標未就緒，已回到回復前版本"
  fail SWITCH_FAILED_HOLD "$EXIT_DEPLOY_INCOMPLETE" "回復目標未就緒且無法回到回復前版本；維持現場待人工處理"
fi

md_state_step --step smoke
trap - ERR
src=0
bash "$MD_PLATFORM_DIR/scripts/deploy/smoke.sh" --env="$env_name" --expected-release="$rid" > "$MD_RELEASES_DIR/seq-${seq}.smoke.json" || src=$?
case "$src" in
  0) md_manifest state-activate --state-dir "$MD_STATE_DIR" --environment "$env_name" --confirmation confirmed
     md_event INFO rollback_active "程式已回復到 ${target} 的 digest（新 release ${rid}，sequence=${seq}）；資料未倒帶"
     exit "$EXIT_OK" ;;
  50) md_manifest state-activate --state-dir "$MD_STATE_DIR" --environment "$env_name" --confirmation unconfirmed
      ms_die "$EXIT_DEPLOY_INCOMPLETE" "DEPLOYMENT_UNCONFIRMED：回復版已換上但未能確認" ;;
  *) back_to_before && fail SMOKE_FAILED_REVERTED "$EXIT_TEST_FAIL" "回復版 smoke 失敗，已回到回復前版本"
     fail SMOKE_FAILED_HOLD "$EXIT_DEPLOY_INCOMPLETE" "回復版 smoke 失敗且無法自動處理" ;;
esac
