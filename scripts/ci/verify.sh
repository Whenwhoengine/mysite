#!/usr/bin/env bash
# 測試範圍入口（Claude VM 與 GitHub Actions 共用同一標準）。
#   --scope=content     內容站 A 範圍（商店維持 disabled）
#   --scope=simulation  內容＋模擬商店 B 範圍（COMMERCE_MODE=simulation）
# 每個步驟都會執行並記錄；任何步驟失敗，最後 exit 30（不中途吞掉）。
set -Euo pipefail

usage() {
  cat <<'EOF'
用法：scripts/ci/verify.sh --scope=content|simulation [--skip-build] [--steps=a,b,...] [--help]

步驟（預設全部）：static、bootstrap、idempotency、doctor、phpunit、wp-contract、e2e、deploy、governance、collect
  --skip-build   testing 映像已存在時略過重建
  --steps=...    只跑指定步驟（逗號分隔）
結果：.runtime/results/*.jsonl、junit.xml、coverage；彙整：docs/evidence/<run-id>/（由 collect 步驟產生）
exit code：0 全部通過；10 參數錯；21 無 Docker（改用 Actions）；30 任一步驟失敗
EOF
}

scope=""; skip_build=0; steps="static,bootstrap,idempotency,doctor,phpunit,wp-contract,e2e,deploy,governance,collect"
for a in "$@"; do
  case "$a" in
    --scope=*) scope="${a#--scope=}" ;;
    --skip-build) skip_build=1 ;;
    --steps=*) steps="${a#--steps=}" ;;
    -h|--help) usage; exit 0 ;;
    *) printf '未知參數：%s\n' "$a" >&2; usage >&2; exit 10 ;;
  esac
done
# shellcheck source=../lib/common.sh
source "$(dirname "${BASH_SOURCE[0]}")/../lib/common.sh"
# shellcheck source=../lib/compose.sh
source "$(dirname "${BASH_SOURCE[0]}")/../lib/compose.sh"
case "$scope" in content|simulation) ;; *) usage >&2; exit "$EXIT_USAGE" ;; esac
[[ "$steps" =~ ^[a-z0-9,-]+$ ]] || ms_die "$EXIT_USAGE" "--steps 格式錯誤"

root="$(ms_root)"; cd "$root"; ms_load_versions "$root"
results="$root/.runtime/results"
mkdir -p "$results"
# 結果目錄不含秘密：允許 tools 容器（www-data）寫入
chmod 0777 "$results"
run_id="${MYSITE_RUN_ID:-local-$(date -u +%Y%m%dT%H%M%SZ)}"
commit="$(git rev-parse HEAD)"
backend="${MYSITE_EXECUTION_BACKEND:-$([[ -n "${GITHUB_ACTIONS:-}" ]] && echo github-actions || echo claude-cloud-compose)}"
steps_log="$results/steps.jsonl"
: > "$steps_log"
overall=0

want() { [[ ",$steps," == *",$1,"* ]]; }
now() { date -u +%FT%TZ; }
step() {
  # step <名稱> <命令...>：執行、計時、記錄 exit code；失敗不中止其他步驟
  local name="$1"; shift
  local start rc
  start="$(now)"
  ms_info "▶ 步驟 ${name}"
  "$@"; rc=$?
  jq -cn --arg s "$name" --arg st "$start" --arg en "$(now)" --argjson rc "$rc" --arg cmd "$*" \
    '{step:$s, command:$cmd, started_at:$st, finished_at:$en, exit_code:$rc, status:(if $rc==0 then "PASS" else "FAIL" end)}' >> "$steps_log"
  if (( rc != 0 )); then ms_err "✗ 步驟 ${name} 失敗（exit ${rc}）"; overall=1; else ms_info "✓ 步驟 ${name} 通過"; fi
  return 0
}
record() {
  # record <suite> <test> <ids-csv> <PASS|FAIL> [message]
  jq -cn --arg suite "$1" --arg t "$2" --arg ids "$3" --arg st "$4" --arg m "${5:-}" --arg at "$(now)" \
    '{suite:$suite, test:$t, ids:($ids|split(",")|map(select(length>0))), status:$st, message:$m, started_at:$at, finished_at:$at}' >> "$results/$1.jsonl"
}

# ---------------------------------------------------------------------------
static_checks() {
  local rc=0 f
  : > "$results/static.jsonl"
  for f in $(git ls-files '*.sh'); do bash -n "$f" || { echo "語法錯誤：$f"; rc=1; }; done
  # 只要求「可執行入口」支援 --help：scripts/{cloud,ci,deploy,ops}/*.sh、tests/**/run.sh、tests/security/*.sh
  # （scripts/lib 與 tests/deploy/cases 等被 source 的模組不是獨立指令）
  for f in $(git ls-files 'scripts/cloud/*.sh' 'scripts/ci/*.sh' 'scripts/deploy/*.sh' 'scripts/ops/*.sh' 'tests/**/run.sh' 'tests/security/*.sh'); do
    bash "$f" --help >/dev/null 2>&1 || { echo "--help 失敗：$f"; rc=1; }
  done
  (( rc == 0 )) && record static script-syntax-and-help GOV-04 PASS || record static script-syntax-and-help GOV-04 FAIL "腳本語法或 --help 失敗"
  if python3 scripts/ci/check-state.py; then record static state-registry GOV-03 PASS; else record static state-registry GOV-03 FAIL "state 檢查失敗"; rc=1; fi
  if bash tests/security/ignore-rules.sh; then record static ignore-rules "CLD-04,DAT-05" PASS; else record static ignore-rules "CLD-04,DAT-05" FAIL "忽略規則失敗"; rc=1; fi
  if bash scripts/ci/secret-scan.sh; then record static secret-scan-tracked "CLD-04,DAT-05" PASS; else record static secret-scan-tracked "CLD-04,DAT-05" FAIL "追蹤檔疑似含秘密"; rc=1; fi
  if [[ -x scripts/ci/check-blade-raw.sh || -f scripts/ci/check-blade-raw.sh ]]; then
    if bash scripts/ci/check-blade-raw.sh; then record static blade-raw-output API-05 PASS; else record static blade-raw-output API-05 FAIL "未授權 {!! !!}"; rc=1; fi
  else
    record static blade-raw-output API-05 FAIL "check-blade-raw.sh 不存在"; rc=1
  fi
  if bash scripts/ci/check-doc-refs.sh; then record static doc-references GOV-04 PASS; else record static doc-references GOV-04 FAIL "文件參照不存在的檔案"; rc=1; fi
  for f in $(git ls-files '*.json'); do python3 -c 'import json,sys; json.load(open(sys.argv[1],encoding="utf-8"))' "$f" || { echo "JSON 錯誤：$f"; rc=1; }; done
  # YAML：允許 Compose 的自訂標籤（例如 !override）
  local yaml_check='import yaml,sys
class L(yaml.SafeLoader): pass
L.add_multi_constructor("!", lambda loader, suffix, node: None)
yaml.load(open(sys.argv[1], encoding="utf-8"), Loader=L)'
  if python3 -c 'import yaml' 2>/dev/null; then
    for f in $(git ls-files '*.yml' '*.yaml'); do python3 -c "$yaml_check" "$f" || { echo "YAML 錯誤：$f"; rc=1; }; done
  else
    echo "缺少 python3-yaml：YAML 語法檢查未執行"; rc=1
  fi
  # 版本鎖定：正式依賴不得使用浮動 latest 或缺 digest
  if grep -nE '_IMAGE_REF=[^@]+$' infra/versions.env; then echo "映像未以 digest 固定"; rc=1; fi
  if grep -rnE 'image: *[^$ ]+:latest' infra/ .github/ 2>/dev/null; then echo "使用 latest 映像"; rc=1; fi
  if grep -rnE 'uses: [^ ]+@(v[0-9.]+|main|master)\s*$' .github/workflows/ 2>/dev/null; then echo "Action 未以完整 SHA 固定"; rc=1; fi
  return $rc
}

ensure_testing_env() {
  local mode="disabled"; [[ "$scope" == simulation ]] && mode="simulation"
  export MYSITE_COMMERCE_MODE="$mode"
  local env_file="$root/.runtime/env/testing.env"
  if [[ -f "$env_file" ]]; then
    sed -i -E "s/^COMMERCE_MODE=.*/COMMERCE_MODE=${mode}/" "$env_file"
  fi
  local args=(--env=testing)
  (( skip_build )) && args+=(--skip-build)
  bash scripts/cloud/bootstrap.sh "${args[@]}"
}

idempotency() {
  # CLD-05：第二次 bootstrap 必須成功且指紋（金鑰、salts、DB 密碼、管理員）不變
  if bash scripts/cloud/bootstrap.sh --env=testing --skip-build >/dev/null 2>"$results/idempotency.err"; then
    record bootstrap second-run-keeps-state CLD-05 PASS
  else
    record bootstrap second-run-keeps-state CLD-05 FAIL "$(tail -3 "$results/idempotency.err" | ms_redact)"
    return 1
  fi
}

doctor_step() {
  local rc=0
  bash scripts/cloud/doctor.sh --env=testing || rc=$?
  local f="$root/.runtime/evidence/doctor-testing.json"
  : > "$results/doctor.jsonl"
  [[ -f "$f" ]] || return 1
  jq -c --arg at "$(now)" '.results[] | {suite:"doctor", test:.id, ids:(
      if .id=="INF-01-APP" or .id=="INF-01-WP" then ["INF-01"]
      elif .id=="INF-02-DB" or .id=="INF-02-PORTS" then ["INF-02"]
      elif .id=="APP-READY" or .id=="APP-LIVE" then ["INF-05"]
      elif .id=="WP-CORE" then ["WP-09"]
      elif (.id|test("^(INF|WP|API|UI|OPS|GOV|DAT|CLD)-[0-9]+$")) then [.id]
      else [] end),
      status:.status, message:(.detail // ""), started_at:$at, finished_at:$at}' "$f" >> "$results/doctor.jsonl"
  return $rc
}

phpunit_step() {
  ms_compose_prepare "$root" testing
  rm -f "$results/junit.xml" "$results/phpunit-tests.xml" "$results/coverage.xml" "$results/coverage.txt"
  ms_compose run --rm -T -u www-data -e MYSITE_TEST_DB=mysite_app_test -e DB_DATABASE=mysite_app_test -e APP_ENV=testing -e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync -e MAIL_MAILER=array -e HOME=/tmp tools \
    php vendor/bin/phpunit --list-tests-xml /opt/mysite/results/phpunit-tests.xml >/dev/null
  ms_compose run --rm -T -u www-data -e MYSITE_TEST_DB=mysite_app_test -e DB_DATABASE=mysite_app_test -e APP_ENV=testing -e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync -e MAIL_MAILER=array -e HOME=/tmp tools \
    php -d pcov.enabled=1 -d pcov.directory=/var/www/app/app vendor/bin/phpunit \
      --log-junit /opt/mysite/results/junit.xml \
      --coverage-clover /opt/mysite/results/coverage.xml \
      --coverage-text=/opt/mysite/results/coverage.txt --colors=never
  local rc=$?
  # 覆蓋率閘門：自製業務程式（app/app）行覆蓋率 ≥ 70%（不得以擴大排除或灌水測試達標）
  local pct
  pct="$(grep -m1 -oE 'Lines:[[:space:]]+[0-9.]+%' "$results/coverage.txt" 2>/dev/null | grep -oE '[0-9.]+' || echo 0)"
  if awk -v p="$pct" 'BEGIN{exit !(p+0 >= 70)}'; then
    record coverage line-coverage-gate "" PASS "行覆蓋率 ${pct}%"
  else
    record coverage line-coverage-gate "" FAIL "行覆蓋率 ${pct}% 低於 70%"
    rc=30
  fi
  return $rc
}

wp_contract_step() {
  [[ -f tests/contracts/wp/run.sh ]] || { record wp-contract runner-missing "" FAIL "tests/contracts/wp/run.sh 不存在"; return 1; }
  MYSITE_ENV=testing MYSITE_RESULTS_DIR="$results" \
  MYSITE_APP_BASE="http://127.0.0.1:$(grep -E '^APP_PORT=' .runtime/env/testing.env | cut -d= -f2)" \
  MYSITE_CMS_BASE="http://127.0.0.1:$(grep -E '^CMS_PORT=' .runtime/env/testing.env | cut -d= -f2)" \
    bash tests/contracts/wp/run.sh
}

deploy_step() {
  [[ -f tests/deploy/run.sh ]] || { record deploy runner-missing "" FAIL "tests/deploy/run.sh 不存在"; return 1; }
  MYSITE_RESULTS_DIR="$results" bash tests/deploy/run.sh
}

e2e_step() {
  [[ -f tests/e2e/run.sh ]] || { record e2e runner-missing "" FAIL "tests/e2e/run.sh 不存在"; return 1; }
  MYSITE_ENV=testing MYSITE_RESULTS_DIR="$results" MYSITE_SCOPE="$scope" \
  MYSITE_APP_BASE="http://mysite.localhost:$(grep -E '^APP_PORT=' .runtime/env/testing.env | cut -d= -f2)" \
  MYSITE_CMS_BASE="http://cms.mysite.localhost:$(grep -E '^CMS_PORT=' .runtime/env/testing.env | cut -d= -f2)" \
    bash tests/e2e/run.sh
}

governance_step() {
  # 治理／流程類驗收（CLD／EXEC／GOV／DAT、INF-06 重建保存、INF-08 架構與資源、GOV-02 弱點掃描）；需在部署工具與 E2E 之後執行
  [[ -f tests/governance/run.sh ]] || { record governance runner-missing "" FAIL "tests/governance/run.sh 不存在"; return 1; }
  : > "$results/governance.jsonl"
  MYSITE_RESULTS_DIR="$results" bash tests/governance/run.sh
}

collect_step() {
  MYSITE_RESULTS_DIR="$results" MYSITE_SCOPE="$scope" MYSITE_EXECUTION_BACKEND="$backend" \
    bash scripts/ci/collect-evidence.sh --run-id="$run_id"
}

timeout 15 docker info >/dev/null 2>&1 || { want static && step static static_checks; ms_die "$EXIT_USE_ACTIONS" "Docker daemon 不可用；請改用 GitHub Actions sandbox.yml"; }

ms_info "verify：scope=${scope} run_id=${run_id} commit=${commit:0:12} backend=${backend}"
for f in "$results"/*.jsonl "$results"/junit.xml "$results"/phpunit-tests.xml; do [[ -e "$f" && "$f" != "$steps_log" ]] && rm -f "$f"; done

want static && step static static_checks
if want bootstrap; then step bootstrap ensure_testing_env; fi
bootstrap_ok=$(jq -s 'map(select(.step=="bootstrap"))|(length==0 or .[0].exit_code==0)' "$steps_log")
if [[ "$bootstrap_ok" != true ]]; then
  ms_err "testing 環境建立失敗；略過需要環境的步驟（記為 FAIL）"
  for s in idempotency doctor phpunit wp-contract e2e; do want "$s" && jq -cn --arg s "$s" --arg at "$(now)" '{step:$s, command:"skipped: bootstrap failed", started_at:$at, finished_at:$at, exit_code:99, status:"FAIL"}' >> "$steps_log"; done
  overall=1
else
  want idempotency && step idempotency idempotency
  want doctor && step doctor doctor_step
  want phpunit && step phpunit phpunit_step
  want wp-contract && step wp-contract wp_contract_step
  want e2e && step e2e e2e_step
fi
want deploy && step deploy deploy_step
if want governance; then
  if [[ "$bootstrap_ok" == true ]]; then step governance governance_step
  else jq -cn --arg at "$(now)" '{step:"governance", command:"skipped: bootstrap failed", started_at:$at, finished_at:$at, exit_code:99, status:"FAIL"}' >> "$steps_log"; overall=1; fi
fi
want collect && step collect collect_step

if (( overall )); then
  ms_err "verify（${scope}）有失敗步驟；明細：$steps_log"
  exit "$EXIT_TEST_FAIL"
fi
ms_info "verify（${scope}）全部步驟通過"
exit "$EXIT_OK"
