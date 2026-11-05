#!/usr/bin/env bash
# 交付檔秘密衛生（DAT-05）：本工作包要提交的腳本、unit、workflow、schema、runbook 與測試檔，不得含私鑰、權杖或寫死的秘密值，
# 也不得把秘密放進命令列參數（只能以檔案、stdin 或 curl -K - 傳遞）。
# 分工：本檔做「提交內容」的靜態檢查；95-leak.sh 在全部測試結束後掃描「執行期輸出與紀錄」。
# shellcheck shell=bash

l00_repo_hygiene() {
  local files=() f n=0
  mapfile -t files < <(cd "$T_REPO" && ls -1 scripts/deploy/*.sh scripts/ops/backup.sh scripts/ops/restore-check.sh scripts/ops/rotate-secrets.sh \
    scripts/lib/deploy.sh scripts/lib/manifest.py scripts/lib/release-ci.sh infra/compose.prod.yml infra/systemd/* infra/systemd/libexec/* \
    .github/workflows/release.yml .github/workflows/deploy-staging.yml .github/workflows/deploy-production.yml .github/workflows/dependency-audit.yml \
    docs/schemas/release-payload.schema.json docs/runbooks/*.md tests/deploy/*.sh tests/deploy/cases/*.sh tests/deploy/stubs/* 2>/dev/null)
  (( ${#files[@]} > 30 )) || { fail "交付檔清單不足（${#files[@]}）"; return 1; }
  # 與 scripts/ci/secret-scan.sh 相同類型的規則：私鑰、雲端／GitHub 權杖、Laravel APP_KEY、寫死的 *_PASSWORD／*_TOKEN 值
  local patterns=(
    '-----BEGIN ([A-Z]+ )?PRIVATE KEY-----'
    'AKIA[0-9A-Z]{16}'
    'gh[pousr]_[A-Za-z0-9]{30,}'
    'github_pat_[A-Za-z0-9_]{40,}'
    'base64:[A-Za-z0-9+/]{40,}={0,2}'
    '(PASSWORD|SECRET|TOKEN|HMAC_KEY|SIGNING_KEY)[A-Z_]*=[^[:space:]$<{/"'"'"'(][^[:space:]]{11,}'
  )
  local re
  for f in "${files[@]}"; do
    [[ -f "$T_REPO/$f" && ! -d "$T_REPO/$f" ]] || continue
    n=$((n + 1))
    for re in "${patterns[@]}"; do
      ! grep -nEq -- "$re" "$T_REPO/$f" || { fail "疑似秘密：$f（規則 ${re:0:24}…）"; return 1; }
    done
  done
  # 秘密不得出現在命令列參數：禁止 --password=、-p<值>、--user_pass=<值>、Authorization 標頭以 -H 傳遞
  for f in scripts/deploy/*.sh scripts/ops/backup.sh scripts/ops/restore-check.sh scripts/ops/rotate-secrets.sh scripts/lib/deploy.sh scripts/lib/release-ci.sh; do
    ! grep -nE -- "--password=|mysql[^|]* -p[^ ]|--user_pass=|-H ['\"]Authorization" "$T_REPO/$f" \
      || { fail "$f 以命令列參數傳遞秘密"; return 1; }
  done
  # 測試用金鑰只能在臨時目錄產生，儲存庫內不得有任何 PEM／金鑰檔
  ! (cd "$T_REPO" && git ls-files --others --cached --exclude-standard -- tests/deploy scripts/deploy scripts/ops infra/systemd 2>/dev/null) \
    | grep -E '\.(pem|key|p12|pfx)$' || { fail "交付路徑中有金鑰檔"; return 1; }
  echo "OK: ${n} 個交付檔無私鑰／權杖／寫死秘密，秘密不經命令列參數傳遞，交付路徑無金鑰檔"
}

case_add L00_deliverables_contain_no_secrets DAT-05 l00_repo_hygiene
