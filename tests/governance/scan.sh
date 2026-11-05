#!/usr/bin/env bash
# GOV-02：映像、依賴與 WordPress 核心／外掛弱點掃描；高風險（HIGH／CRITICAL）未處理項阻擋。
set -Euo pipefail

usage() {
  cat <<'EOF'
用法：tests/governance/scan.sh [--help]

對 testing 環境實際使用的映像與鎖定依賴做弱點掃描，結果寫入 .runtime/results/governance.jsonl（GOV-02）與 .runtime/results/scan/：
  - 映像：Trivy（infra/versions.env 的 TRIVY_IMAGE_REF），HIGH／CRITICAL、只計已有修補版者，發現即 FAIL
  - Composer：composer audit --locked --no-dev（依 app/composer.lock）
  - npm：npm audit --omit=dev --audit-level=high（依 app/package-lock.json）
  - WordPress 核心與外掛：查 WPVulnerability 公開 API（不需金鑰）；受影響且 CVSS 高／重大或未標嚴重度者 FAIL
任一來源無法取得結果也記 FAIL（沒有結果不等於沒有弱點）。
exit code：0 全部通過；10 參數錯；20 testing 環境或 Docker 不可用；30 有失敗
EOF
}
for a in "$@"; do
  case "$a" in
    -h|--help) usage; exit 0 ;;
    *) printf '未知參數：%s\n' "$a" >&2; usage >&2; exit 10 ;;
  esac
done
# shellcheck source=../../scripts/lib/common.sh
source "$(dirname "${BASH_SOURCE[0]}")/../../scripts/lib/common.sh"
# shellcheck source=../../scripts/lib/compose.sh
source "$(dirname "${BASH_SOURCE[0]}")/../../scripts/lib/compose.sh"
root="$(ms_root)"; cd "$root"; ms_load_versions "$root"
timeout 15 docker info >/dev/null 2>&1 || ms_die "$EXIT_EXTERNAL" "Docker daemon 不可用"
[[ -f .runtime/env/testing.env ]] || ms_die "$EXIT_EXTERNAL" "testing 環境尚未 bootstrap"
ms_compose_prepare "$root" testing

results="${MYSITE_RESULTS_DIR:-$root/.runtime/results}"
out="$results/scan"
mkdir -p "$out"
registry="${MYSITE_EFFECTIVE_REGISTRY:-$BASE_IMAGE_REGISTRY}"
fail=0
record() {
  # record <test> <PASS|FAIL> <message>
  local at; at="$(date -u +%FT%TZ)"
  jq -cn --arg t "$1" --arg st "$2" --arg m "$3" --arg at "$at" \
    '{suite:"governance", test:$t, ids:["GOV-02"], status:$st, message:$m, started_at:$at, finished_at:$at}' >> "$results/governance.jsonl"
  printf '  %-4s  %-28s %s\n' "$2" "$1" "$3"
  [[ "$2" == PASS ]] || fail=1
}
proxy_args=()
if ms_has_proxy_ca; then
  # 代理只聽在主機 loopback：容器需用 host 網路才連得到（只在 Claude VM 這類代理環境）
  proxy_args=(--network host -e "HTTPS_PROXY=$HTTPS_PROXY" -e "https_proxy=$HTTPS_PROXY" -v /root/.ccr/ca-bundle.crt:/etc/ssl/certs/mysite-proxy-ca.crt:ro
              -e SSL_CERT_FILE=/etc/ssl/certs/mysite-proxy-ca.crt -e NODE_EXTRA_CA_CERTS=/etc/ssl/certs/mysite-proxy-ca.crt)
fi

# 取得掃描工具映像：先用本機已有的；否則依序向各來源下載（每個來源最多 3 次、間隔遞增，因 registry 偶有限流）。
# 成功時輸出完整參照；全部失敗回傳 1（呼叫端記 FAIL，不當成沒有弱點）。
pull_image() {
  local ref="$1"; shift
  local reg attempt
  for reg in "$@"; do
    docker image inspect "${reg}/${ref}" >/dev/null 2>&1 && { printf '%s\n' "${reg}/${ref}"; return 0; }
  done
  for reg in "$@"; do
    for attempt in 1 2 3; do
      if timeout 300 docker pull -q "${reg}/${ref}" >/dev/null 2>>"$out/pull.err"; then
        printf '%s\n' "${reg}/${ref}"; return 0
      fi
      sleep $(( attempt * 15 ))
    done
  done
  return 1
}

printf 'GOV-02 弱點掃描：\n'
# --- 映像（Trivy）
mapfile -t images < <(ms_compose config --images 2>/dev/null | grep -E '^mysite/' | sort -u)
trivy_ref="$(pull_image "$TRIVY_IMAGE_REF" docker.io)" || trivy_ref=""
if (( ${#images[@]} == 0 )); then
  record image-scan FAIL "找不到 testing 映像"
elif [[ -z "$trivy_ref" ]]; then
  record image-scan FAIL "無法取得 Trivy 映像（重試後仍失敗，明細 .runtime/results/scan/pull.err）；映像未掃描"
else
  bad=() scanned=0
  for img in "${images[@]}"; do
    name="${img#mysite/}"; name="${name%%:*}"
    rm -f "$out/trivy-${name}.json"
    timeout 900 docker run --rm -v /var/run/docker.sock:/var/run/docker.sock -v mysite-trivy-cache:/root/.cache/ -v "$out:/out" "${proxy_args[@]}" \
      "$trivy_ref" image --scanners vuln --severity HIGH,CRITICAL --ignore-unfixed --exit-code 1 \
      --no-progress --format json --output "/out/trivy-${name}.json" "$img" >"$out/trivy-${name}.log" 2>&1
    rc=$?
    if [[ -s "$out/trivy-${name}.json" ]] && jq -e '.Results' "$out/trivy-${name}.json" >/dev/null 2>&1; then
      scanned=$((scanned + 1))
      n="$(jq '[.Results[]?.Vulnerabilities[]?] | length' "$out/trivy-${name}.json")"
      (( n > 0 )) && bad+=("${name}（${n} 筆）")
    else
      bad+=("${name}（掃描失敗 exit ${rc}）")
    fi
  done
  if (( ${#bad[@]} == 0 )); then
    record image-scan PASS "Trivy ${TRIVY_IMAGE_REF%%@*}：${scanned} 個映像無已可修補的 HIGH／CRITICAL"
  else
    record image-scan FAIL "Trivy 發現或無法掃描：${bad[*]}（明細 .runtime/results/scan/trivy-*.log）"
  fi
fi

# --- Composer（只看正式依賴；dev 依賴另由 dependency-audit.yml 每週報告）
# 優先用本次已建置、內含同版 Composer 的 tools 映像，避免再向 registry 下載而遇到限流
composer_run=()
prefix="${MYSITE_IMAGE_PREFIX:-mysite}" tag="${MYSITE_IMAGE_TAG:-local}"
for img in "${prefix}/laravel-test:${tag}" "${prefix}/laravel-dev:${tag}"; do
  if docker image inspect "$img" >/dev/null 2>&1; then composer_run=(--entrypoint composer "$img"); break; fi
done
if (( ${#composer_run[@]} == 0 )); then
  ref="$(pull_image "$COMPOSER_IMAGE_REF" "$registry" docker.io/library)" && composer_run=("$ref")
fi
if (( ${#composer_run[@]} == 0 )); then
  record composer-audit FAIL "無法取得含 Composer 的映像（重試後仍失敗）；Composer 依賴未稽核"
elif timeout 300 docker run --rm -v "$root/app:/app:ro" -w /app -e COMPOSER_HOME=/tmp/c "${proxy_args[@]}" \
    "${composer_run[@]}" audit --locked --no-dev --format=json >"$out/composer-audit.json" 2>"$out/composer-audit.err"; then
  record composer-audit PASS "composer audit --locked --no-dev：0 筆公告"
else
  rc=$?
  n="$(jq -r '[.advisories // {} | to_entries[] | .value | length] | add // 0' "$out/composer-audit.json" 2>/dev/null || echo '?')"
  record composer-audit FAIL "composer audit exit ${rc}；公告 ${n} 筆（明細 .runtime/results/scan/composer-audit.*）"
fi

# --- npm（前端建置依賴；正式產物只有編譯後的靜態檔）
node_ref="$(pull_image "$NODE_IMAGE_REF" "$registry" docker.io/library)" || node_ref=""
if [[ -z "$node_ref" ]]; then
  record npm-audit FAIL "無法取得 Node 映像（重試後仍失敗）；npm 依賴未稽核"
elif timeout 300 docker run --rm -v "$root/app:/app:ro" -w /app "${proxy_args[@]}" "$node_ref" \
    npm audit --omit=dev --audit-level=high --json >"$out/npm-audit.json" 2>"$out/npm-audit.err"; then
  record npm-audit PASS "npm audit --omit=dev：無 high 以上"
else
  rc=$?
  n="$(jq -r '.metadata.vulnerabilities // {} | (.high // 0) + (.critical // 0)' "$out/npm-audit.json" 2>/dev/null || echo '?')"
  record npm-audit FAIL "npm audit exit ${rc}；high＋critical ${n}（明細 .runtime/results/scan/npm-audit.*）"
fi

# --- WordPress 核心與外掛（WPVulnerability 公開 API）
wp_fetch() { curl -fsS --max-time 30 "https://www.wpvulnerability.net/$1/" -o "$2"; }
ok=1
wp_fetch "core/${WORDPRESS_VERSION}" "$out/wpvuln-core.json" || ok=0
wp_fetch "plugin/advanced-custom-fields" "$out/wpvuln-acf.json" || ok=0
wp_fetch "plugin/two-factor" "$out/wpvuln-two-factor.json" || ok=0
if (( ! ok )); then
  record wp-plugin-scan FAIL "無法取得 WPVulnerability 資料（網路或服務異常）；外掛弱點未掃描"
else
  verdict="$(python3 -I - "$out" "$WORDPRESS_VERSION" "$ACF_VERSION" "$TWO_FACTOR_VERSION" <<'PY'
import json, re, sys
out, core_v, acf_v, tf_v = sys.argv[1:5]
def ver(s):
    return tuple(int(x) for x in re.findall(r"\d+", s or "0")[:4]) or (0,)
def cmp_ok(v, op, ref):
    if not ref:
        return True
    a, b = ver(v), ver(ref)
    return {"lt": a < b, "le": a <= b, "gt": a > b, "ge": a >= b, "eq": a == b}.get(op or "", False)
rows, blocking, minor = [], [], []
for name, f, v in (("WordPress 核心", "wpvuln-core.json", core_v), ("ACF", "wpvuln-acf.json", acf_v), ("Two Factor", "wpvuln-two-factor.json", tf_v)):
    d = json.load(open(f"{out}/{f}", encoding="utf-8"))
    if d.get("error") not in (0, None):
        print(f"FAIL|{name} 查詢錯誤：{d.get('message')}")
        sys.exit()
    vulns = (d.get("data") or {}).get("vulnerability") or []
    hit = 0
    for x in vulns:
        op = x.get("operator") or {}
        affected = cmp_ok(v, op.get("min_operator"), op.get("min_version")) and (op.get("unfixed") == "1" or cmp_ok(v, op.get("max_operator"), op.get("max_version")))
        if not affected:
            continue
        hit += 1
        sev = (((x.get("impact") or {}).get("cvss") or {}).get("severity") or "").lower()
        (blocking if sev in ("h", "c", "") else minor).append(f"{name} {v}：{x.get('name')}（嚴重度 {sev or '未標示'}）")
    rows.append(f"{name} {v}：{len(vulns)} 筆歷史公告、影響本版 {hit} 筆")
status = "FAIL" if blocking else "PASS"
msg = "；".join(rows)
if blocking:
    msg += "；阻擋：" + "；".join(blocking[:3])
if minor:
    msg += "；中低風險待處理：" + "；".join(minor[:3])
print(f"{status}|{msg}")
PY
)"
  record wp-plugin-scan "${verdict%%|*}" "${verdict#*|}"
fi

(( fail )) && exit "$EXIT_TEST_FAIL"
exit "$EXIT_OK"
