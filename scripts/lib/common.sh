#!/usr/bin/env bash
# mysite 腳本共用函式庫。
# exit code 契約（規劃書 v5 附錄 D.4）：
#   0 實際成功；10 參數／設定錯；20 缺外部前置；21 建議切 Actions；
#   30 測試或功能失敗；40 禁止的安全／授權操作；50 部署或還原未完成。
# shellcheck shell=bash

readonly EXIT_OK=0
readonly EXIT_USAGE=10
readonly EXIT_EXTERNAL=20
readonly EXIT_USE_ACTIONS=21
readonly EXIT_TEST_FAIL=30
readonly EXIT_FORBIDDEN=40
readonly EXIT_DEPLOY_INCOMPLETE=50

ms_root() {
  local r
  r="$(git -C "${BASH_SOURCE[0]%/*}" rev-parse --show-toplevel 2>/dev/null)" || {
    printf '%s\n' 'BLOCKED: 找不到 Git 工作目錄。' >&2
    return "$EXIT_EXTERNAL"
  }
  printf '%s\n' "$r"
}

ms_log() {
  # 結構化簡單日誌：時間、等級、訊息（不得帶秘密）。
  local level="$1"; shift
  printf '%s [%s] %s\n' "$(date -u +%FT%TZ)" "$level" "$*" >&2
}
ms_info() { ms_log INFO "$@"; }
ms_warn() { ms_log WARN "$@"; }
ms_err() { ms_log ERROR "$@"; }

ms_die() {
  # ms_die <exit_code> <message>
  local code="$1"; shift
  ms_err "$*"
  exit "$code"
}

# 只允許固定 enum 的環境名稱，避免拿未驗證輸入組 volume／SQL／路徑。
ms_require_env_enum() {
  local value="$1"; shift
  local allowed
  for allowed in "$@"; do
    [[ "$value" == "$allowed" ]] && return 0
  done
  ms_die "$EXIT_USAGE" "不合法的環境：'${value}'；允許值：$*"
}

# 去秘密化：把常見秘密樣式遮罩後再輸出。
ms_redact() {
  sed -E \
    -e 's#(https?://)[^/@[:space:]]+:[^/@[:space:]]+@#\1***:***@#g' \
    -e 's#((PASSWORD|PASS|SECRET|TOKEN|KEY|SALT|AUTHORIZATION|COOKIE)[A-Z_]*[=:][[:space:]]*)[^[:space:]]+#\1***#Ig' \
    -e 's#(Bearer|Basic)[[:space:]]+[A-Za-z0-9._~+/=-]+#\1 ***#g' \
    -e 's#base64:[A-Za-z0-9+/=]{20,}#base64:***#g'
}

# 有界等待：ms_wait_until <timeout_seconds> <interval_seconds> <command...>
ms_wait_until() {
  local timeout="$1" interval="$2"; shift 2
  local deadline=$(( $(date +%s) + timeout ))
  while true; do
    if "$@" >/dev/null 2>&1; then return 0; fi
    if (( $(date +%s) >= deadline )); then return 1; fi
    sleep "$interval"
  done
}

# 讀取 infra/versions.env（只含非秘密的版本與 digest）。
ms_load_versions() {
  local root="$1"
  # shellcheck disable=SC1091
  set -a; source "$root/infra/versions.env"; set +a
}

# Compose 專案名稱：固定前綴＋環境，禁止任意輸入。
ms_compose_project() {
  local env="$1"
  case "$env" in
    development) printf '%s\n' "mysite-dev" ;;
    testing) printf '%s\n' "mysite-test${MYSITE_TEST_SUFFIX:+-$MYSITE_TEST_SUFFIX}" ;;
    staging) printf '%s\n' "mysite-staging" ;;
    production) printf '%s\n' "mysite-production" ;;
    *) ms_die "$EXIT_USAGE" "未知環境：$env" ;;
  esac
}

ms_compose_files() {
  local root="$1" env="$2"
  case "$env" in
    development) printf -- '-f %s -f %s' "$root/infra/compose.base.yml" "$root/infra/compose.dev.yml" ;;
    testing) printf -- '-f %s -f %s' "$root/infra/compose.base.yml" "$root/infra/compose.ci.yml" ;;
    staging|production) printf -- '-f %s -f %s' "$root/infra/compose.base.yml" "$root/infra/compose.prod.yml" ;;
    *) ms_die "$EXIT_USAGE" "未知環境：$env" ;;
  esac
}

ms_has_proxy_ca() {
  [[ -n "${HTTPS_PROXY:-}" && -s /root/.ccr/ca-bundle.crt ]]
}

# 把檔案／目錄群組設為 33（容器內 www-data；MySQL 容器以 999:33 執行），讓容器讀得到 0640 秘密檔。
# root 直接 chgrp；非 root（例如 GitHub-hosted runner）改用免密碼 sudo；兩者皆不可用時回傳失敗。
ms_chgrp_33() {
  if [[ "$(id -u)" == 0 ]]; then
    chgrp "$@" 33 2>/dev/null || chgrp 33 "$@"
  elif sudo -n true >/dev/null 2>&1; then
    sudo -n chgrp 33 "$@"
  else
    return 1
  fi
}
