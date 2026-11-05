#!/usr/bin/env bash
# 換版／回復／拉取流程（OPS-04～OPS-06、OPS-11）：臨時主機＋docker／curl／restic 替身，檢查流程順序與停止條件。
# shellcheck shell=bash

rel() { # rel <envelope> [額外參數…]：執行 release.sh
  run_host env MD_SMOKE_INTERVAL=0.2 bash "$T_REPO/scripts/deploy/release.sh" --env="$HENV" --verified-envelope="$1" --lock-wait=0 "${@:2}"
}
rb() { run_host env MD_SMOKE_INTERVAL=0.2 bash "$T_REPO/scripts/deploy/rollback.sh" --env="$HENV" --approved-release="$1" --lock-wait=0 "${@:2}"; }
state() { "${MANIFEST[@]}" state-get --state-dir "$HE/state" --environment "$HENV" --field "$1"; }
cenv() { grep -E "^$1=" "$HE/state/compose.env" | cut -d= -f2-; }
# line_of <固定字串>：在 stub log 中第一次出現的行號（0＝沒出現）
line_of() { # 以 "=" 開頭表示整行完全相符
  local n
  if [[ "$1" == =* ]]; then n="$(grep -nxF -- "${1#=}" "$STUB_LOG" | head -n 1 | cut -d: -f1)"
  else n="$(grep -nF -- "$1" "$STUB_LOG" | head -n 1 | cut -d: -f1)"; fi
  printf '%s\n' "${n:-0}"
}
assert_before() { # assert_before <A> <B>：A 先於 B 出現
  local a b; a="$(line_of "$1")"; b="$(line_of "$2")"
  (( a > 0 && b > 0 && a < b )) || fail "順序錯誤：「$1」(#$a) 應早於「$2」(#$b)"
}
deploy_ok() { # deploy_ok <seq> <images> [overrides…]：部署並要求成功
  envelope "$H/e$1.json" "$HENV" "$1" "$2" "${@:3}"
  rel "$H/e$1.json"; assert_rc 0 $? "部署 sequence $1" || { tail -n 20 "$H/last.out"; return 1; }
}

r01_happy() {
  new_host r01 staging; host_running; images_json "$H/A.json"; images_json "$H/B.json"
  deploy_ok 1 "$H/A.json" || return 1
  : > "$STUB_LOG"
  deploy_ok 2 "$H/B.json" 'schema_change_class="expand"' || return 1
  local lar; lar="$(jq -r .laravel "$H/B.json")"
  assert_before "pull $lar" "exec mysql mysqldump" || return 1
  assert_before "exec mysql mysqldump" "restic backup" || return 1
  assert_before "restic check" "compose[r-test-1] stop app-worker app-scheduler wp-jobs" || return 1
  assert_file_no_grep 'artisan down' "$STUB_LOG" "expand 變更不需維護模式（舊版可繼續服務）" || return 1
  assert_before "compose[r-test-1] stop app-worker" "compose[r-test-2] run --rm --no-deps -T -e MYSITE_ROLE=cli app-php php artisan migrate --force" || return 1
  assert_before "php artisan migrate --force" "compose[r-test-2] run --rm --no-deps -T -u www-data wp-php wp site-content setup" || return 1
  assert_before "wp site-content setup" "compose[r-test-2] up -d --no-deps app-php" || return 1
  assert_before "compose[r-test-2] up -d --no-deps app-php" "compose[r-test-2] exec -T app-php php artisan up" || return 1
  assert_before "compose[r-test-2] exec -T app-php php artisan up" "=compose[r-test-2] up -d --wait --wait-timeout 300" || return 1
  assert_before "=compose[r-test-2] up -d --wait --wait-timeout 300" "exec app-web deploy-status" || return 1
  [[ "$(state active.sequence)" == 2 && "$(state active.confirmation)" == confirmed ]] || fail "active 應為 sequence 2（confirmed）" || return 1
  [[ "$(cenv RELEASE_ID)" == r-test-2 && "$(cenv MYSITE_IMAGE_LARAVEL)" == "$lar" ]] || fail "compose.env 未換成新 digest" || return 1
  grep -q '^RELEASE_ID=r-test-1$' "$HE/state/compose.env.prev" || fail "未保留上一版 compose.env" || return 1
  [[ "$(wc -l < "$HE/state/history.jsonl")" == 2 ]] || fail "發布歷史應有 2 筆" || return 1
  [[ "$(wc -l < "$HE/state/backups.jsonl")" == 2 ]] || fail "每次部署前都應有成功備份紀錄" || return 1
  assert_file_no_grep 'down -v|migrate:fresh|migrate:rollback|prune' "$STUB_LOG" "不得出現破壞性指令" || return 1
  echo "OK: 拉 digest→備份→暫停寫入者→migration→WP setup→新 app-php＋解除維護→全部換版並等就緒→smoke→active，順序正確且無破壞性指令"
}

r02_lock() {
  new_host r02 staging; host_running; images_json "$H/A.json"
  envelope "$H/e1.json" staging 1 "$H/A.json"
  touch "$STUB_DIR/slow-pull"
  env MYSITE_HOST_ROOT="$H" PATH="$T_STUBS:$PATH" STUB_DIR="$STUB_DIR" STUB_LOG="$STUB_LOG" STUB_HOST_ENV_DIR="$HE" MD_SMOKE_INTERVAL=0.2 \
    bash "$T_REPO/scripts/deploy/release.sh" --env=staging --verified-envelope="$H/e1.json" > "$H/a.out" 2>&1 &
  local pid=$! i
  for i in $(seq 1 100); do [[ -e "$STUB_DIR/pull-started" ]] && break; sleep 0.1; done
  [[ -e "$STUB_DIR/pull-started" ]] || { kill "$pid"; fail "第一個部署未進入拉取階段"; return 1; }
  rel "$H/e1.json"; local rc=$?
  assert_rc 50 "$rc" "第二個部署者應因鎖被占用而停止" || { kill "$pid"; return 1; }
  assert_file_grep 'LOCK_BUSY' "$H/last.out" "應回報 LOCK_BUSY" || { kill "$pid"; return 1; }
  wait "$pid"; assert_rc 0 $? "持鎖的部署應完成" || { tail "$H/a.out"; return 1; }
  [[ "$(state active.sequence)" == 1 && "$(state pending)" == "" ]] || fail "狀態應只有一次成功部署" || return 1
  [[ "$(grep -c 'artisan migrate --force' "$STUB_LOG")" == 1 ]] || fail "migration 應只執行一次" || return 1
  echo "OK: 並發兩個部署只有一個取得 flock，另一個 exit 50 LOCK_BUSY；migration 只跑一次"
}

r03_retry_limit() {
  new_host r03 staging; host_running; images_json "$H/A.json"
  envelope "$H/e1.json" staging 1 "$H/A.json"
  touch "$STUB_DIR/fail-migrate"
  local i rc
  for i in 1 2 3; do
    rel "$H/e1.json"; rc=$?
    assert_rc 50 "$rc" "第 $i 次（migration 失敗）" || return 1
    assert_file_grep 'MIGRATION_FAILED' "$H/last.out" "第 $i 次應回報 MIGRATION_FAILED" || return 1
  done
  rel "$H/e1.json"; rc=$?
  assert_rc 50 "$rc" "第 4 次應被重試上限擋下" || return 1
  assert_file_grep 'RETRY_LIMIT_EXCEEDED' "$H/last.out" "應回報 RETRY_LIMIT_EXCEEDED" || return 1
  [[ "$(grep -c 'artisan migrate --force' "$STUB_LOG")" == 3 ]] || fail "migration 只能被嘗試 3 次（實際 $(grep -c 'artisan migrate --force' "$STUB_LOG")）" || return 1
  [[ "$(state pending.attempt)" == 3 && "$(state pending.status)" == failed ]] || fail "pending 應記錄 3 次失敗" || return 1
  assert_file_no_grep 'volume rm|down -v' "$STUB_LOG" "失敗時不得刪 volume" || return 1
  rm -f "$STUB_DIR/fail-migrate"
  deploy_ok 2 "$H/A.json" || return 1
  echo "OK: 同一清單失敗 3 次後第 4 次 RETRY_LIMIT_EXCEEDED（migration 只跑 3 次、未刪 volume）；新序號可再部署"
}

r04_backup_fail() {
  new_host r04 staging; host_running; images_json "$H/A.json"; images_json "$H/B.json"
  deploy_ok 1 "$H/A.json" || return 1
  : > "$STUB_LOG"; touch "$STUB_DIR/fail-restic-backup"
  envelope "$H/e2.json" staging 2 "$H/B.json"
  rel "$H/e2.json"; assert_rc 50 $? "備份失敗應停止" || return 1
  assert_file_grep 'BACKUP_FAILED' "$H/last.out" "應回報 BACKUP_FAILED" || return 1
  assert_file_no_grep 'compose\[r-test-2\] (up|run)' "$STUB_LOG" "備份失敗後不得換版或 migration" || return 1
  assert_file_no_grep 'artisan down' "$STUB_LOG" "備份失敗後不得進維護模式" || return 1
  [[ "$(cenv RELEASE_ID)" == r-test-1 && "$(state active.sequence)" == 1 ]] || fail "現行版本不得改變" || return 1
  [[ "$(wc -l < "$HE/state/backups.jsonl")" == 1 ]] || fail "失敗的備份不得記為成功" || return 1
  echo "OK: 部署前備份失敗 → exit 50 BACKUP_FAILED，未進維護、未 migration、未換版，現行版本不變"
}

r05_schema() {
  new_host r05 staging; host_running; images_json "$H/A.json"; images_json "$H/B.json"
  deploy_ok 1 "$H/A.json" || return 1
  : > "$STUB_LOG"
  envelope "$H/e2.json" staging 2 "$H/B.json" 'schema_change_class="contract"' 'migration_plan_ref="plan-2026-11-05-drop-col"'
  rel "$H/e2.json"; assert_rc 40 $? "contract 未核准應停止" || return 1
  assert_file_grep 'SCHEMA_APPROVAL_REQUIRED' "$H/last.out" "應回報需要核准" || return 1
  assert_file_no_grep 'compose\[r-test-2\] (up|run)|artisan down|artisan migrate' "$STUB_LOG" "未核准時不得維護、migration 或換版" || return 1
  [[ "$(cenv RELEASE_ID)" == r-test-1 ]] || fail "現行版本不得改變" || return 1
  [[ "$(state pending.status)" == awaiting_approval ]] || fail "pending 應為 awaiting_approval" || return 1
  # timer 拉到同一清單時不得再呼叫 release.sh（避免耗盡重試次數）
  cp "$H/e2.json" "$STUB_DIR/asset.json"
  printf '{"draft":false,"assets":[{"name":"release-envelope.json","state":"uploaded","size":%s,"url":"https://api.github.com/repos/Whenwhoengine/mysite/releases/assets/5"}]}' "$(wc -c < "$H/e2.json")" > "$STUB_DIR/meta.json"
  run_host bash "$T_REPO/scripts/deploy/pull-release.sh" --env=staging --force-now; assert_rc 40 $? "等待核准的清單由 timer 拉到應停在 40" || return 1
  [[ "$(state pending.attempt)" == 1 ]] || fail "timer 不得消耗重試次數（attempt=$(state pending.attempt)）" || return 1
  rel "$H/e2.json" --approved-schema-plan=plan-other; assert_rc 40 $? "核准參照不符應拒絕" || return 1
  : > "$STUB_LOG"
  rel "$H/e2.json" --approved-schema-plan=plan-2026-11-05-drop-col; assert_rc 0 $? "核准後應可部署" || { tail "$H/last.out"; return 1; }
  assert_before "compose[r-test-1] exec -T app-php php artisan down" "php artisan migrate --force" || return 1
  assert_before "php artisan migrate --force" "compose[r-test-2] exec -T app-php php artisan up" || return 1
  [[ "$(state active.schema_change_class)" == contract ]] || fail "active 應記錄 contract" || return 1
  echo "OK: contract 變更未核准或參照不符 → exit 40 且未換版；核准參照相符後才在維護模式下部署"
}

r06_first_install() {
  new_host r06 staging; images_json "$H/A.json"
  envelope "$H/e1.json" staging 1 "$H/A.json"
  rel "$H/e1.json"; assert_rc 40 $? "空環境首次安裝未核准應拒絕" || return 1
  assert_file_grep 'FIRST_INSTALL_APPROVAL_REQUIRED' "$H/last.out" "應要求首次安裝核准" || return 1
  [[ -f "$HE/releases/seq-1.first-install.json" ]] || fail "應留下空環境證據" || return 1
  assert_file_no_grep 'restic backup' "$STUB_LOG" "確實空環境不捏造備份" || return 1
  printf 'ADMIN_EMAIL=admin@example.test\n' >> "$HE/config/deploy.env"
  ( umask 027; openssl rand -hex 16 > "$HE/secrets/wp_admin_password"; printf 'PENDING-BOOTSTRAP-1234\n' > "$HE/secrets/wp_service_app_password" )
  touch "$STUB_DIR/wp-not-installed" "$STUB_DIR/running/app-web"   # 換版後 app-web 容器在執行（替身）
  rel "$H/e1.json" --first-install-approved=approval-2026-11-05; assert_rc 0 $? "核准後首次安裝" || { tail -n 20 "$H/last.out"; return 1; }
  assert_file_grep 'wp core install' "$STUB_LOG" "應執行 WP 首次安裝" || return 1
  assert_file_grep 'application-password create mysite-service' "$STUB_LOG" "應發行服務帳號 Application Password" || return 1
  [[ "$(cat "$HE/secrets/wp_service_app_password")" == abcdefghijklmnopqrstuvwx ]] || fail "Application Password 應寫入秘密檔" || return 1
  assert_file_no_grep 'abcdefghijklmnopqrstuvwx|abcd efgh' "$H/last.out" "Application Password 不得出現在輸出" || return 1
  echo "OK: 空環境首次發布需核准並留下空環境證據（不捏造備份）；WP 安裝與服務帳號密碼只寫入秘密檔"
}

r07_smoke_revert() {
  new_host r07 staging; host_running; images_json "$H/A.json"; images_json "$H/B.json"
  deploy_ok 1 "$H/A.json" || return 1
  touch "$STUB_DIR/smoke-mismatch"
  envelope "$H/e2.json" staging 2 "$H/B.json" 'schema_change_class="expand"'
  rel "$H/e2.json"; assert_rc 30 $? "smoke 發現 release 不符應失敗" || return 1
  assert_file_grep 'SMOKE_FAILED_REVERTED' "$H/last.out" "應自動回到上一版" || return 1
  [[ "$(cenv RELEASE_ID)" == r-test-1 && -f "$HE/state/compose.env.failed-seq-2" ]] || fail "應回到上一版 compose.env 並保留失敗版本紀錄" || return 1
  [[ "$(state active.sequence)" == 1 && "$(state pending.status)" == failed ]] || fail "active 應維持 1、pending 記失敗" || return 1
  assert_file_no_grep 'migrate:rollback' "$STUB_LOG" "不得自動 migrate:rollback" || return 1
  rm -f "$STUB_DIR/smoke-mismatch"; touch "$STUB_DIR/smoke-down"
  envelope "$H/e3.json" staging 3 "$H/B.json"
  rel "$H/e3.json"; assert_rc 50 $? "smoke 逾時應為 DEPLOYMENT_UNCONFIRMED" || return 1
  assert_file_grep 'DEPLOYMENT_UNCONFIRMED' "$H/last.out" "應標 DEPLOYMENT_UNCONFIRMED" || return 1
  [[ "$(state active.confirmation)" == unconfirmed ]] || fail "未確認不得記為 confirmed" || return 1
  echo "OK: smoke 不符→回到上一版程式（不倒帶資料）exit 30；smoke 逾時→DEPLOYMENT_UNCONFIRMED exit 50 且不記成功"
}

r08_rollback() {
  new_host r08 staging; host_running; images_json "$H/A.json"; images_json "$H/B.json"
  deploy_ok 1 "$H/A.json" || return 1
  deploy_ok 2 "$H/B.json" 'schema_change_class="expand"' || return 1
  envelope "$H/rb3.json" staging 3 "$H/A.json" 'rollback_target_release="r-test-1"'
  rel "$H/rb3.json"; assert_rc 10 $? "回復清單不得走 release.sh" || return 1
  envelope "$H/e9.json" staging 9 "$H/B.json"
  rb "$H/e9.json"; assert_rc 10 $? "一般清單不得走 rollback.sh" || return 1
  : > "$STUB_LOG"
  rb "$H/rb3.json"; assert_rc 0 $? "合法回復（新序號 3 → r-test-1 的 digest）" || { tail -n 20 "$H/last.out"; return 1; }
  assert_file_no_grep 'artisan migrate|migrate:rollback|restore' "$STUB_LOG" "回復不得跑 migration 或還原資料" || return 1
  assert_file_grep 'restic backup' "$STUB_LOG" "回復前應先備份" || return 1
  [[ "$(state active.sequence)" == 3 && "$(state active.kind)" == rollback ]] || fail "active 應為回復清單 sequence 3" || return 1
  [[ "$(cenv MYSITE_IMAGE_LARAVEL)" == "$(jq -r .laravel "$H/A.json")" ]] || fail "應換回 r-test-1 的 digest" || return 1
  rb "$H/rb3.json"; assert_rc 0 $? "同一回復清單再次執行應為 no-op" || return 1
  assert_file_grep 'NOOP' "$H/last.out" "應回報 NOOP" || return 1
  envelope "$H/rb2.json" staging 2 "$H/A.json" 'rollback_target_release="r-test-1"' 'release_id="r-test-2b"'
  rb "$H/rb2.json"; assert_rc 40 $? "較小序號的回復清單（重播）應拒絕" || return 1
  rel "$H/e1.json"; assert_rc 40 $? "重播舊的 sequence 1 清單應拒絕" || return 1
  echo "OK: 回復必須是新的更大序號；只換 digest、先備份、不跑 migration／不倒帶資料；舊清單重播被拒"
}

r09_rollback_refused() {
  new_host r09 staging; host_running; images_json "$H/A.json"; images_json "$H/B.json"; images_json "$H/C.json"
  deploy_ok 1 "$H/A.json" || return 1
  envelope "$H/e2.json" staging 2 "$H/B.json" 'schema_change_class="contract"' 'migration_plan_ref="plan-x1"'
  rel "$H/e2.json" --approved-schema-plan=plan-x1; assert_rc 0 $? "核准的 contract 部署" || return 1
  envelope "$H/rb3.json" staging 3 "$H/A.json" 'rollback_target_release="r-test-1"'
  rb "$H/rb3.json"; assert_rc 40 $? "目標之後有 contract 變更應拒絕回復" || return 1
  assert_file_grep 'ROLLBACK_SCHEMA_INCOMPATIBLE' "$H/last.out" "應回報 schema 不相容" || return 1
  envelope "$H/rb4.json" staging 4 "$H/C.json" 'rollback_target_release="r-test-2"'
  rb "$H/rb4.json"; assert_rc 40 $? "digest 與目標紀錄不同應拒絕" || return 1
  assert_file_grep 'ROLLBACK_IMAGES_MISMATCH' "$H/last.out" "應回報 digest 不符" || return 1
  envelope "$H/rb5.json" staging 5 "$H/A.json" 'rollback_target_release="r-never-deployed"'
  rb "$H/rb5.json"; assert_rc 40 $? "目標不在歷史中應拒絕" || return 1
  grep -o 'sha256:[0-9a-f]*' "$H/A.json" | head -n 1 > "$HE/config/revoked-digests.txt"
  envelope "$H/rb6.json" staging 6 "$H/A.json" 'rollback_target_release="r-test-1"'
  rb "$H/rb6.json"; assert_rc 40 $? "已撤銷（不安全）的 digest 不得回復" || return 1
  [[ "$(cenv RELEASE_ID)" == r-test-2 ]] || fail "被拒的回復不得改動現行版本" || return 1
  echo "OK: 目標後有 contract 變更、digest 不符、目標未曾部署、digest 已撤銷 → 回復全部拒絕且現行版本不變"
}

r10_pull() {
  new_host r10 staging; host_running; images_json "$H/A.json"
  envelope "$H/e1.json" staging 1 "$H/A.json"
  cp "$H/e1.json" "$STUB_DIR/asset.json"
  mk_meta() { printf '{"draft":false,"assets":[{"name":"release-envelope.json","state":"uploaded","size":%s,"url":"https://api.github.com/repos/Whenwhoengine/mysite/releases/assets/987"}]}' "$1" > "$STUB_DIR/meta.json"; }
  mk_meta "$(wc -c < "$H/e1.json")"
  pr() { run_host env MD_SMOKE_INTERVAL=0.2 bash "$T_REPO/scripts/deploy/pull-release.sh" --env=staging "$@"; }
  pr --dry-run; assert_rc 0 $? "dry-run 拉取並驗證" || { cat "$H/last.out"; return 1; }
  assert_file_grep '^curl https://api.github.com/repos/Whenwhoengine/mysite/releases/tags/channel-staging$' "$STUB_LOG" "必須查固定 channel（不取 latest）" || return 1
  assert_file_no_grep 'releases/latest' "$STUB_LOG" "不得使用 releases/latest" || return 1
  [[ -f "$HE/releases/verified/seq-1.envelope.json" ]] || fail "驗證後應保存 verified 清單" || return 1
  assert_file_no_grep 'compose\[r-test-1\] up' "$STUB_LOG" "dry-run 不得部署" || return 1
  pr; assert_rc 0 $? "拉取並部署" || { tail -n 20 "$H/last.out"; return 1; }
  [[ "$(state active.sequence)" == 1 ]] || fail "應部署 sequence 1" || return 1
  pr; assert_rc 0 $? "再次拉取同序號應 no-op" || return 1
  assert_file_grep 'NOOP' "$H/last.out" "應回報 NOOP" || return 1
  # 404：保留現況並退避；退避期間不再呼叫 API
  echo 404 > "$STUB_DIR/meta.code"; : > "$STUB_LOG"
  pr; assert_rc 50 $? "channel 404" || return 1
  [[ -f "$HE/state/pull-backoff.json" ]] || fail "應建立退避紀錄" || return 1
  pr; assert_rc 0 $? "退避期間應安靜略過" || return 1
  [[ "$(grep -c '^curl ' "$STUB_LOG")" == 1 ]] || fail "退避期間不得再次呼叫 GitHub API" || return 1
  rm -f "$STUB_DIR/meta.code"
  mk_meta 70000; pr --force-now; assert_rc 50 $? "asset 超過大小上限" || return 1
  assert_file_grep 'ASSET_INVALID' "$H/last.out" "應回報 ASSET_INVALID" || return 1
  mk_meta 100; echo "https://evil.example.test/x" > "$STUB_DIR/asset.effective"
  pr --force-now; assert_rc 50 $? "asset 轉址到非核准網域" || return 1
  assert_file_grep 'ASSET_SOURCE_INVALID' "$H/last.out" "應回報來源不合法" || return 1
  rm -f "$STUB_DIR/asset.effective"
  payload "$H/p2.json" staging 2 "$H/A.json"; sign "$H/p2.json" attacker staging-k1 "$STUB_DIR/asset.json"
  pr --force-now; assert_rc 40 $? "竄改的 channel 清單應拒絕" || return 1
  [[ "$(state active.sequence)" == 1 ]] || fail "被拒清單不得改動現行版本" || return 1
  touch "$HE/state/channel-paused"; : > "$STUB_LOG"
  pr --force-now; assert_rc 0 $? "channel 暫停時安靜略過" || return 1
  assert_file_no_grep '^curl ' "$STUB_LOG" "channel 暫停時不得拉取" || return 1
  echo "OK: 固定 channel 取 asset、dry-run 不部署、新序號部署、同序號 no-op、404 退避、超大／異網域／竄改清單拒絕、channel 可暫停"
}

case_add R01_release_happy_path_order OPS-04,OPS-06 r01_happy
case_add R02_deploy_lock_single_holder OPS-05 r02_lock
case_add R03_retry_limit_enforced OPS-05 r03_retry_limit
case_add R04_backup_failure_stops_before_switch OPS-06 r04_backup_fail
case_add R05_schema_incompatible_requires_approval OPS-06 r05_schema
case_add R06_first_install_requires_approval OPS-06 r06_first_install
case_add R07_smoke_failure_reverts_program_only OPS-11 r07_smoke_revert
case_add R08_rollback_requires_new_sequence OPS-11 r08_rollback
case_add R09_rollback_refuses_unsafe_targets OPS-11 r09_rollback_refused
case_add R10_pull_release_fixed_channel_backoff OPS-03,OPS-05 r10_pull
