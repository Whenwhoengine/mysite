#!/usr/bin/env bash
# 主機開通與部署前置檢查（OPS-01、GOV-04）：臨時根目錄（MYSITE_HOST_ROOT）＋替身，不碰真實 /srv 與 systemd。
# shellcheck shell=bash

bh() {
  env MYSITE_HOST_ROOT="$BH" MYSITE_SYSTEMD_DIR="$BH-systemd" MYSITE_OS_RELEASE_FILE="$BH-os-release" PATH="$T_STUBS:$PATH" \
    STUB_DIR="$BH-stub" STUB_LOG="$BH-stub.log" bash "$T_REPO/scripts/deploy/bootstrap-host.sh" "$@" > "$BH.out" 2>&1
}

h01_bootstrap() {
  BH="$T_ROOT/bh/srv"; mkdir -p "$BH-stub/running" "$BH-stub/volumes" "$(dirname "$BH")"; : > "$BH-stub.log"
  printf 'ID=ubuntu\nVERSION_ID="24.04"\n' > "$BH-os-release"
  bh --env=staging; assert_rc 40 $? "缺 --approved-plan" || return 1
  bh --env=staging --approved-plan=plan-host-2026-11 --trust-key="staging-k1:$KEYS/staging-k1.pub.pem"
  assert_rc 0 $? "開通" || { tail -n 20 "$BH.out"; return 1; }
  local E="$BH/environments/staging" d m
  for d in "$E/secrets:750" "$BH/backup-staging:700" "$BH/backup-staging/staging:700" "$BH/tls/letsencrypt:700" "$E/config/trusted-keys:755" "$E/state:750" "$BH/tls/acme:755"; do
    m="$(stat -c '%a' "${d%:*}" 2>/dev/null)"; [[ "$m" == "${d##*:}" ]] || fail "${d%:*} 權限應為 ${d##*:}（實際 ${m:-不存在}）" || return 1
  done
  grep -qx 'DEPLOY_ENVIRONMENT=staging' "$E/config/deploy.env" || fail "主機設定範本錯誤" || return 1
  local s
  for s in mysql_root_password wp_db_password app_db_password wp_admin_password app_key wp_salts content_event_hmac_key preview_signing_key \
           diagnostics_token deploy_status_token wp_service_app_password internal_ca_cert wp_internal_tls_cert wp_internal_tls_key; do
    [[ -s "$E/secrets/$s" && "$(stat -c '%a' "$E/secrets/$s")" == 640 ]] || fail "秘密 $s 未產生或權限不是 0640" || return 1
    if [[ "$s" != *cert && "$s" != *key && "$s" != wp_salts ]]; then
      ! grep -qF -- "$(head -n 1 "$E/secrets/$s")" "$BH.out" || fail "秘密 $s 出現在輸出" || return 1
    fi
  done
  # 金鑰圈（GAP-KEYRING-01）：由既有單一金鑰組成 k1 active；app_previous_keys 為空檔
  local pair
  for pair in content_event_hmac_key:content_event_hmac_keyring preview_signing_key:preview_signing_keyring; do
    s="${pair##*:}"
    [[ -s "$E/secrets/$s" && "$(stat -c '%a' "$E/secrets/$s")" == 640 ]] || fail "金鑰圈 $s 未產生或權限不是 0640" || return 1
    [[ "$(awk '{print $1":"$3}' "$E/secrets/$s")" == "k1:active" ]] || fail "金鑰圈 $s 應只有 k1 active" || return 1
    [[ "$(awk '{print $2}' "$E/secrets/$s")" == "$(tr -d '\n' < "$E/secrets/${pair%%:*}")" ]] || fail "金鑰圈 $s 的 k1 應等於既有單一金鑰" || return 1
    ! grep -qF -- "$(awk '{print $2}' "$E/secrets/$s")" "$BH.out" || fail "金鑰圈 $s 的金鑰出現在輸出" || return 1
  done
  [[ -f "$E/secrets/app_previous_keys" && ! -s "$E/secrets/app_previous_keys" && "$(stat -c '%a' "$E/secrets/app_previous_keys")" == 640 ]] \
    || fail "app_previous_keys 應為 0640 空檔" || return 1
  [[ ! -e "$E/secrets/restic_password" ]] || fail "不得代為產生備份密碼（需先存入受控保管處）" || return 1
  [[ "$(wc -l < "$E/secrets/wp_salts")" == 8 ]] && grep -q '^base64:' "$E/secrets/app_key" || fail "salts／APP_KEY 格式錯誤" || return 1
  cmp -s "$E/config/trusted-keys/staging-k1.pem" "$KEYS/staging-k1.pub.pem" || fail "公鑰未安裝" || return 1
  grep -q "/usr/bin/bash $BH/platform/scripts/deploy/pull-release.sh --env=%i" "$BH-systemd/mysite-pull-release@.service" || fail "unit 未安裝或路徑未改寫" || return 1
  grep -q 'systemctl enable --now mysite-pull-release@staging.timer mysite-certbot-renew.timer' "$BH-stub.log" || fail "應啟用拉取與憑證續期 timer" || return 1
  ! grep -q 'mysite-backup-wp@staging.timer' "$BH-stub.log" || fail "沒有備份目的地時不應啟用備份 timer" || return 1
  assert_file_grep 'BLOCKED_EXTERNAL' "$BH.out" "應提示備份目的地缺件" || return 1
  [[ "$(jq -r .approved_plan "$E/state/bootstrap-approvals.jsonl")" == plan-host-2026-11 ]] || fail "應記錄核准參照" || return 1
  # 冪等：重跑不覆寫既有秘密
  local before; before="$(cat "$E"/secrets/* | sha256sum)"
  bh --env=staging --approved-plan=plan-host-2026-11 --trust-key="staging-k1:$KEYS/staging-k1.pub.pem"; assert_rc 0 $? "重跑" || return 1
  [[ "$(cat "$E"/secrets/* | sha256sum)" == "$before" ]] || fail "重跑改變了既有秘密" || return 1
  # 拒絕：私鑰、跨環境 key_id、同 key_id 換內容、不支援的作業系統、資料卷存在但秘密遺失
  bh --env=staging --approved-plan=p-1 --trust-key="staging-k9:$KEYS/staging-k2.pem"; assert_rc 40 $? "把私鑰當公鑰安裝" || return 1
  bh --env=staging --approved-plan=p-1 --trust-key="production-k1:$KEYS/production-k1.pub.pem"; assert_rc 40 $? "跨環境 key_id" || return 1
  bh --env=staging --approved-plan=p-1 --trust-key="staging-k1:$KEYS/staging-k2.pub.pem"; assert_rc 40 $? "同 key_id 換公鑰" || return 1
  printf 'ID=debian\nVERSION_ID="11"\n' > "$BH-os-release"
  bh --env=staging --approved-plan=p-1; assert_rc 20 $? "不支援的作業系統" || return 1
  printf 'ID=ubuntu\nVERSION_ID="24.04"\n' > "$BH-os-release"
  rm -f "$E/secrets/app_db_password"; touch "$BH-stub/volumes/mysite-staging_mysql-data"
  bh --env=staging --approved-plan=p-1; assert_rc 20 $? "資料卷存在但 DB 秘密遺失應拒絕" || return 1
  [[ ! -e "$E/secrets/app_db_password" ]] || fail "不得隨機補一個新 DB 密碼假恢復" || return 1
  echo "OK: 目錄／權限／設定範本／秘密（0640、不輸出、不重產）／公鑰／unit 安裝正確；私鑰、跨環境、換 key、舊 OS、秘密遺失皆拒絕"
}

h02_preflight() {
  new_host h02 staging
  pf() { run_host env PATH="$T_STUBS:$PATH" bash "$T_REPO/scripts/deploy/preflight.sh" --env=staging "$@"; }
  pf; assert_rc 20 $? "缺秘密時應 exit 20" || { tail "$H/last.out"; return 1; }
  jq -e '.result == "FAIL" and (.checks.secrets | startswith("FAIL"))' "$HE/state/preflight.json" >/dev/null || fail "preflight.json 應記錄秘密缺件" || return 1
  local s
  for s in app_key content_event_hmac_key preview_signing_key internal_ca_cert wp_internal_tls_cert wp_internal_tls_key diagnostics_token; do
    ( umask 027; openssl rand -hex 16 > "$HE/secrets/$s" )
  done
  pf; assert_rc 20 $? "缺金鑰圈與 app_previous_keys 時應 exit 20" || { tail "$H/last.out"; return 1; }
  jq -e '(.checks.secrets | contains("content_event_hmac_keyring")) and (.checks.secrets | contains("app_previous_keys"))' "$HE/state/preflight.json" >/dev/null \
    || fail "preflight.json 應列出缺少的金鑰圈與 app_previous_keys" || return 1
  for s in content_event_hmac_keyring preview_signing_keyring; do
    ( umask 027; printf 'k1 %s active\n' "$(openssl rand -hex 32)" > "$HE/secrets/$s" )
  done
  ( umask 027; : > "$HE/secrets/app_previous_keys" )
  pf; assert_rc 0 $? "前置齊全應通過" || { cat "$H/last.out"; return 1; }
  jq -e '.result == "PASS" and .checks.trust_keys == "PASS" and .checks.backup == "PASS" and .checks.time_sync == "PASS"' "$HE/state/preflight.json" >/dev/null \
    || fail "preflight.json 結果不正確" || return 1
  chmod 0644 "$HE/secrets/app_key"
  pf; assert_rc 20 $? "秘密檔其他人可讀應失敗" || return 1
  chmod 0640 "$HE/secrets/app_key"
  # 金鑰圈格式不合法（兩把 active）→ 失敗
  printf 'k2 %s active\n' "$(openssl rand -hex 32)" >> "$HE/secrets/preview_signing_keyring"
  pf; assert_rc 20 $? "金鑰圈兩把 active 應失敗" || return 1
  jq -e '.checks.secrets | contains("preview_signing_keyring")' "$HE/state/preflight.json" >/dev/null || fail "應指出不合法的金鑰圈" || return 1
  ( umask 027; printf 'k1 %s active\nk2 %s accept\n' "$(openssl rand -hex 32)" "$(openssl rand -hex 32)" > "$HE/secrets/preview_signing_keyring" )
  pf; assert_rc 0 $? "合法的過渡期金鑰圈（active＋accept）應通過" || { cat "$H/last.out"; return 1; }
  printf 'SOMETHING_UNKNOWN=1\n' >> "$HE/config/deploy.env"
  pf; assert_rc 10 $? "設定檔含未知鍵應 exit 10" || return 1
  echo "OK: 缺秘密（含金鑰圈）exit 20、齊全 exit 0、秘密權限過寬或金鑰圈格式錯 exit 20、設定未知鍵 exit 10；結果寫入 state/preflight.json"
}

case_add H01_bootstrap_host_layout_keys_units OPS-01,GOV-04 h01_bootstrap
case_add H02_preflight_checks GOV-04 h02_preflight
