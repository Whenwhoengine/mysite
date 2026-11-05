#!/usr/bin/env bash
# E2E 執行入口：真實瀏覽器（Playwright 1.63.0，版本鎖定）對 testing／development 環境測試。
set -Euo pipefail
usage() {
  cat <<'USAGE'
用法：tests/e2e/run.sh [--help] [playwright 參數...]
環境變數：
  MYSITE_ENV         development（預設）或 testing；決定讀取哪個秘密目錄
  MYSITE_APP_BASE    主站網址（預設 http://mysite.localhost:8080）
  MYSITE_CMS_BASE    CMS 網址（預設 http://cms.mysite.localhost:8081）
  MYSITE_SCOPE       content（預設）或 simulation；決定商店測試的預期
  MYSITE_RESULTS_DIR 結果 JSONL 目錄（預設 .runtime/results）
截圖輸出：tests/e2e/screenshots/（僅合成資料；trace／video 關閉以免外洩 cookie）
exit：0 全部通過；30 有失敗；20 缺前置（未安裝相依或秘密）
USAGE
}
[[ "${1:-}" == "-h" || "${1:-}" == "--help" ]] && { usage; exit 0; }
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root="$(git -C "$here" rev-parse --show-toplevel)"
export MYSITE_ENV="${MYSITE_ENV:-development}"
case "$MYSITE_ENV" in development|testing) ;; *) echo "MYSITE_ENV 不合法" >&2; exit 10 ;; esac
export MYSITE_SECRETS_DIR="$root/.runtime/secrets/$MYSITE_ENV"
export MYSITE_RESULTS_DIR="${MYSITE_RESULTS_DIR:-$root/.runtime/results}"
mkdir -p "$MYSITE_RESULTS_DIR" "$here/screenshots"
[[ -d "$here/node_modules/@playwright/test" ]] || { echo "缺少 E2E 相依：請在 tests/e2e 執行 npm ci" >&2; exit 20; }
[[ -s "$MYSITE_SECRETS_DIR/wp_editor_password" ]] || { echo "缺少 $MYSITE_ENV 環境秘密（請先 bootstrap）" >&2; exit 20; }
# Node 的 getaddrinfo 不解析 *.localhost（瀏覽器會）；需要 /etc/hosts 對應
if ! getent hosts mysite.localhost >/dev/null 2>&1 || ! getent hosts cms.mysite.localhost >/dev/null 2>&1; then
  echo "缺少 /etc/hosts 對應：127.0.0.1 mysite.localhost cms.mysite.localhost（CI 由 workflow 加入）" >&2
  exit 20
fi
cd "$here"
if npx playwright test "$@"; then exit 0; else exit 30; fi
