#!/usr/bin/env bash
# 備份、隔離還原守門、秘密輪替（OPS-07、OPS-01、DAT-02）：臨時主機＋替身。
# shellcheck shell=bash

bk() { run_host bash "$T_REPO/scripts/ops/backup.sh" --env="$HENV" "$@"; }
rot() { run_host bash "$T_REPO/scripts/ops/rotate-secrets.sh" --env="$HENV" "$@"; }

o01_backup() {
  new_host o01 staging; host_running
  bk --reason=routine --scope=all; assert_rc 0 $? "完整備份" || { tail -n 20 "$H/last.out"; return 1; }
  [[ "$(wc -l < "$HE/state/backups.jsonl")" == 1 ]] || fail "應有 1 筆成功紀錄" || return 1
  local sid; sid="$(jq -r .snapshot_id "$HE/state/last-backup-all.json")"
  [[ "$sid" =~ ^[0-9a-f]{64}$ ]] || fail "成功紀錄缺 snapshot ID" || return 1
  local m="$STUB_DIR/restic-repo/$sid/backup-manifest.json"
  [[ -f "$m" ]] || fail "snapshot 內缺 backup-manifest.json" || return 1
  for f in db/wp.sql db/app.sql db/deletion-log.sql uploads.tar; do
    jq -e --arg f "$f" '.files[] | select(.path == $f) | .sha256 | test("^[0-9a-f]{64}$")' "$m" >/dev/null || fail "manifest 缺 $f 或其 SHA-256" || return 1
  done
  jq -e '.content_counts.wp_posts_by_status == {"draft":1,"publish":2} and .deletion_log == "present"' "$m" >/dev/null || fail "manifest 筆數／刪除紀錄欄位錯誤" || return 1
  grep -q 'mysqldump --single-transaction --routines --triggers --no-tablespaces' "$T_REPO/scripts/ops/backup.sh" || fail "必須使用一致性 mysqldump 參數" || return 1
  [[ ! -e "$H/backup-staging/staging/snapshot-all" ]] || fail "暫存 dump 必須清除" || return 1
  assert_file_no_grep 'INSERT INTO|CREATE TABLE' "$H/last.out" "輸出不得含 dump 內容" || return 1
  touch "$STUB_DIR/fail-restic-check"
  bk --reason=routine --scope=app; assert_rc 50 $? "restic check 失敗" || return 1
  [[ "$(wc -l < "$HE/state/backups.jsonl")" == 1 ]] || fail "check 失敗不得記成功" || return 1
  rm -f "$STUB_DIR/fail-restic-check"; touch "$STUB_DIR/fail-dump"
  bk --reason=routine --scope=wp; assert_rc 50 $? "dump 失敗" || return 1
  rm -f "$STUB_DIR/fail-dump"
  bk --reason=routine --scope=app; assert_rc 0 $? "app 範圍備份" || return 1
  sid="$(jq -r .snapshot_id "$HE/state/last-backup-app.json")"
  jq -e '[.files[].path] | index("db/wp.sql") == null and index("db/app.sql") != null' "$STUB_DIR/restic-repo/$sid/backup-manifest.json" >/dev/null \
    || fail "app 範圍只應含 Laravel DB" || return 1
  rm -f "$HE/secrets/restic_password"
  bk --reason=routine; assert_rc 20 $? "缺 restic 密碼應為缺外部前置" || return 1
  assert_file_grep 'BLOCKED_EXTERNAL' "$H/last.out" "應標 BLOCKED_EXTERNAL" || return 1
  echo "OK: 一致 dump＋uploads＋刪除紀錄＋manifest 雜湊交給 restic；check 通過才記成功；check／dump 失敗不記；缺目的地 exit 20"
}

o02_restore_guard() {
  new_host o02 staging
  local t rc
  for t in staging production mysite-production mysite-staging ../isolated ISOLATED; do
    run_host bash "$T_REPO/scripts/ops/restore-check.sh" --env=staging --snapshot=latest --target="$t"; rc=$?
    assert_rc 40 "$rc" "--target=$t 應拒絕" || return 1
  done
  run_host bash "$T_REPO/scripts/ops/restore-check.sh" --env=staging --snapshot=latest; rc=$?
  assert_rc 10 "$rc" "缺 --target" || return 1
  run_host env MYSITE_RESTORECHECK_PREFIX=mysite-production bash "$T_REPO/scripts/ops/restore-check.sh" --env=staging --snapshot=latest --target=isolated; rc=$?
  assert_rc 40 "$rc" "以 production 為前綴的隔離 project 應拒絕" || return 1
  run_host bash "$T_REPO/scripts/ops/restore-check.sh" --env=staging '--snapshot=latest;rm' --target=isolated; rc=$?
  assert_rc 10 "$rc" "snapshot 參數注入應拒絕" || return 1
  assert_file_no_grep '^run |volume create|compose\[' "$STUB_LOG" "被拒時不得建立任何容器或 volume" || return 1
  grep -q -- '--network none' "$T_REPO/scripts/ops/restore-check.sh" || fail "隔離 MySQL 必須不接外部網路" || return 1
  echo "OK: staging／production／其他名稱目標一律 exit 40，缺 target exit 10，被拒時不建立任何資源"
}

secret_leak_check() { # secret_leak_check <值> <說明>
  local v="$1" f
  for f in "$H/last.out" "$STUB_LOG" "$HE/state/events.jsonl" "$HE/state/rotations.jsonl" "$STUB_DIR/sql.log"; do
    [[ -f "$f" ]] || continue
    ! grep -qF -- "$v" "$f" || fail "$2 出現在 $(basename "$f")" || return 1
  done
}

o03_rotate_db() {
  new_host o03 staging; host_running
  local old new
  old="$(cat "$HE/secrets/app_db_password")"
  rot --kind=app-db --approved-plan=plan-rotate-2026-11; assert_rc 0 $? "app-db 輪替" || { tail -n 20 "$H/last.out"; return 1; }
  new="$(cat "$HE/secrets/app_db_password")"
  [[ "$new" != "$old" && "$new" =~ ^[A-Za-z0-9]{40}$ ]] || fail "秘密檔應換成新的 40 字元英數密碼" || return 1
  [[ "$(stat -c '%a' "$HE/secrets/app_db_password")" == 640 ]] || fail "秘密檔權限應為 0640" || return 1
  sed -n 1p "$STUB_DIR/sql.log" | grep -q "ALTER USER 'mysite_app'@'%' IDENTIFIED BY '\*\*\*' RETAIN CURRENT PASSWORD;" || fail "第一期必須 RETAIN CURRENT PASSWORD" || return 1
  sed -n 2p "$STUB_DIR/sql.log" | grep -q "ALTER USER 'mysite_app'@'%' DISCARD OLD PASSWORD;" || fail "驗證後才 DISCARD OLD PASSWORD" || return 1
  assert_file_grep 'up -d --no-deps --force-recreate --wait --wait-timeout 180 app-php app-worker app-scheduler' "$STUB_LOG" "應重建 Laravel 容器" || return 1
  local recreate_line discard_check
  recreate_line="$(grep -n 'force-recreate' "$STUB_LOG" | head -n 1 | cut -d: -f1)"; discard_check="$(grep -n 'migrate:status' "$STUB_LOG" | head -n 1 | cut -d: -f1)"
  (( recreate_line < discard_check )) || fail "必須先重建容器再驗證" || return 1
  secret_leak_check "$new" "新密碼" || return 1
  secret_leak_check "$old" "舊密碼" || return 1
  [[ ! -e "$HE/secrets/.rotation/app_db_password.prev" ]] || fail "成功後不應保留舊密碼副本" || return 1
  jq -e 'select(.kind=="app-db" and .result=="SUCCESS" and .approved_plan=="plan-rotate-2026-11")' "$HE/state/rotations.jsonl" >/dev/null || fail "應記錄輪替結果（不含值）" || return 1
  echo "OK: app-db 雙密碼輪替：RETAIN→換秘密檔→重建→驗證→DISCARD；新舊值不出現在輸出、log 或紀錄"
}

o04_rotate_revert() {
  new_host o04 staging; host_running
  local old; old="$(cat "$HE/secrets/wp_db_password")"
  echo 2 > "$STUB_DIR/fail-login-on"   # 第 1 次（輪替前檢查）成功，第 2 次（新密碼驗證）失敗
  rot --kind=wp-db --approved-plan=plan-rotate-2026-11; assert_rc 50 $? "新密碼驗證失敗應回復並 exit 50" || return 1
  assert_file_grep 'VERIFY_FAILED_REVERTED' "$H/last.out" "應回報已回復" || { tail "$H/last.out"; return 1; }
  [[ "$(cat "$HE/secrets/wp_db_password")" == "$old" ]] || fail "秘密檔應回到原值" || return 1
  sed -n 2p "$STUB_DIR/sql.log" | grep -q "ALTER USER 'mysite_wp'@'%' IDENTIFIED BY '\*\*\*';" || fail "應把原密碼設回主要密碼" || return 1
  sed -n 3p "$STUB_DIR/sql.log" | grep -q 'DISCARD OLD PASSWORD' || fail "回復後應撤銷新密碼" || return 1
  [[ "$(grep -c 'force-recreate' "$STUB_LOG")" == 2 ]] || fail "應重建兩次（換新、回復）" || return 1
  secret_leak_check "$old" "原密碼" || return 1
  # wp-salts：換新後可 --phase=revert 回到上一組
  local salts_old; salts_old="$(sha256sum < "$HE/secrets/wp_salts")"
  rot --kind=wp-salts --approved-plan=plan-salts-1; assert_rc 0 $? "wp-salts 輪替" || return 1
  [[ "$(sha256sum < "$HE/secrets/wp_salts")" != "$salts_old" && "$(wc -l < "$HE/secrets/wp_salts")" == 8 ]] || fail "salts 應換成 8 行新值" || return 1
  rot --kind=wp-salts --approved-plan=plan-salts-1 --phase=revert; assert_rc 0 $? "wp-salts 回復" || return 1
  [[ "$(sha256sum < "$HE/secrets/wp_salts")" == "$salts_old" ]] || fail "revert 應回到原 salts" || return 1
  echo "OK: 新密碼驗證失敗 → 原密碼設回主要、撤銷新密碼、秘密檔與容器回到原狀（exit 50）；wp-salts 可換新與回復"
}

o05_rotate_guards() {
  new_host o05 staging; host_running
  rot --kind=app-db; assert_rc 40 $? "缺 --approved-plan" || return 1
  run_host bash "$T_REPO/scripts/ops/rotate-secrets.sh" --env=development --kind=app-db --approved-plan=p-dev; assert_rc 40 $? "development 未加 --allow-development" || return 1
  rot --kind=event-hmac --approved-plan=p-1 --phase=add; assert_rc 20 $? "應用未支援金鑰圈" || return 1
  assert_file_grep 'APP_SUPPORT_MISSING' "$H/last.out" "應說明需要應用支援" || return 1
  rot --kind=app-key --approved-plan=p-1 --phase=add; assert_rc 20 $? "Laravel 未支援 APP_PREVIOUS_KEYS_FILE" || return 1
  rot --kind=event-hmac --approved-plan=p-1; assert_rc 10 $? "event-hmac 缺 --phase" || return 1
  printf 'KEYRING_SUPPORT=yes\n' >> "$HE/config/deploy.env"
  ( umask 027; openssl rand -hex 32 > "$HE/secrets/content_event_hmac_key" )
  local ring="$HE/secrets/content_event_hmac_keyring"
  rot --kind=event-hmac --approved-plan=p-1 --phase=promote; assert_rc 10 $? "未 add 不得 promote" || return 1
  rot --kind=event-hmac --approved-plan=p-1 --phase=add; assert_rc 0 $? "add" || { tail "$H/last.out"; return 1; }
  [[ "$(awk '{print $1":"$3}' "$ring" | tr '\n' ' ')" == "k1:active k2:accept " ]] || fail "add 後應為 k1 active、k2 accept" || return 1
  rot --kind=event-hmac --approved-plan=p-1 --phase=add; assert_rc 10 $? "過渡中不得再 add" || return 1
  rot --kind=event-hmac --approved-plan=p-1 --phase=promote; assert_rc 0 $? "promote" || return 1
  [[ "$(awk '{print $1":"$3}' "$ring" | tr '\n' ' ')" == "k1:accept k2:active " ]] || fail "promote 後應為 k2 active、k1 accept" || return 1
  rot --kind=event-hmac --approved-plan=p-1 --phase=retire; assert_rc 0 $? "retire" || return 1
  [[ "$(awk '{print $1":"$3}' "$ring" | tr '\n' ' ')" == "k2:active " ]] || fail "retire 後只剩 k2 active" || return 1
  local k; for k in $(awk '{print $2}' "$ring"); do secret_leak_check "$k" "金鑰圈內容" || return 1; done
  grep -nE 'compose\[\] up -d --no-deps --force-recreate --wait --wait-timeout 180 (app-php|wp-php)' "$STUB_LOG" | head -n 2 | grep -q 'app-php' \
    || fail "接收端（Laravel）必須先於發送端（WP）重建" || return 1
  echo "OK: 無核准／development 未明示／應用未支援皆拒絕；事件金鑰 add→promote→retire 雙期過渡，順序錯誤即拒絕，金鑰值不外洩"
}

case_add O01_backup_records_only_after_check OPS-07 o01_backup
case_add O02_restore_check_refuses_non_isolated OPS-07 o02_restore_guard
case_add O03_rotate_db_dual_password DAT-02,OPS-01 o03_rotate_db
case_add O04_rotate_reverts_on_failure OPS-01 o04_rotate_revert
case_add O05_rotate_guards_and_keyring_phases OPS-01 o05_rotate_guards
