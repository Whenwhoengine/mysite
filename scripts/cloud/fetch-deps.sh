#!/usr/bin/env bash
# 下載並驗證 WordPress 核心、ACF、Two Factor；zh_TW 語系包改用本專案保存的已查核副本（hash 依 infra/versions.env）。
# 成品放 .runtime/dist/（Git 忽略），供映像建置以 named context「dist」取用。
set -Eeuo pipefail
usage() {
  cat <<'USAGE'
用法：scripts/cloud/fetch-deps.sh [--help]
依 infra/versions.env 的來源與 SHA-256 取得 WordPress 建置所需 zip，不符即失敗（exit 30）。
zh_TW 語系包不下載，取自儲存庫內的已查核副本（WP_ZH_TW_LANGPACK_FILE）。
已存在且 hash 相符的檔案不重新下載。
USAGE
}
[[ "${1:-}" == "-h" || "${1:-}" == "--help" ]] && { usage; exit 0; }
[[ $# -eq 0 ]] || { usage >&2; exit 10; }
# shellcheck source=../lib/common.sh
source "$(dirname "${BASH_SOURCE[0]}")/../lib/common.sh"
root="$(ms_root)"; cd "$root"; ms_load_versions "$root"
dist="${MYSITE_DIST_DIR:-$root/.runtime/dist}"
mkdir -p "$dist"

fetch() {
  local name="$1" url="$2" sha="$3"
  local target="$dist/$name"
  [[ -n "$sha" ]] || ms_die "$EXIT_USAGE" "$name 缺少鎖定 SHA-256"
  if [[ -f "$target" ]] && printf '%s  %s\n' "$sha" "$target" | sha256sum -c --status -; then
    ms_info "已存在且相符：$name"; return 0
  fi
  local tmp="$target.part"
  curl -fsSL --proto '=https' --tlsv1.2 --max-time 300 --retry 3 --retry-delay 2 -o "$tmp" "$url" \
    || ms_die "$EXIT_EXTERNAL" "下載失敗：$name（$url）"
  if ! printf '%s  %s\n' "$sha" "$tmp" | sha256sum -c --status -; then
    rm -f "$tmp"
    ms_die "$EXIT_TEST_FAIL" "SHA-256 不符：$name；拒絕使用"
  fi
  mv "$tmp" "$target"
  ms_info "已下載並驗證：$name"
}

# 本專案保存的已查核副本：上游網址的內容會變（例如 WordPress.org 語系包隨譯者更新重新產生），
# 所以不下載，從儲存庫複製並核對同一個 SHA-256。更新方式見 infra/third-party/README.md。
use_vendored() {
  local name="$1" rel="$2" sha="$3"
  local target="$dist/$name" src="$root/$rel"
  [[ -n "$sha" ]] || ms_die "$EXIT_USAGE" "$name 缺少鎖定 SHA-256"
  [[ -n "$rel" && -f "$src" ]] || ms_die "$EXIT_USAGE" "$name 的保存副本不存在：${rel:-（未設定）}"
  if ! printf '%s  %s\n' "$sha" "$src" | sha256sum -c --status -; then
    ms_die "$EXIT_TEST_FAIL" "SHA-256 不符：$name 的保存副本（$rel）；拒絕使用"
  fi
  if [[ -f "$target" ]] && cmp -s "$src" "$target"; then
    ms_info "已存在且相符：$name（保存副本）"; return 0
  fi
  cp "$src" "$target.part" && mv "$target.part" "$target"
  ms_info "已使用保存副本並驗證：$name（$rel）"
}

fetch wordpress.zip "$WORDPRESS_ZIP_URL" "$WORDPRESS_ZIP_SHA256"
# WordPress 官方另提供 .sha1，再交叉核對一次
if command -v sha1sum >/dev/null; then
  printf '%s  %s\n' "$WORDPRESS_ZIP_SHA1" "$dist/wordpress.zip" | sha1sum -c --status - || ms_die "$EXIT_TEST_FAIL" "WordPress 官方 SHA-1 不符"
fi
fetch acf.zip "$ACF_SOURCE_URL" "$ACF_ZIP_SHA256"
fetch two-factor.zip "$TWO_FACTOR_ZIP_URL" "$TWO_FACTOR_ZIP_SHA256"
use_vendored zh_TW.zip "${WP_ZH_TW_LANGPACK_FILE:-}" "$WP_ZH_TW_LANGPACK_SHA256"
fetch wp-core-checksums.json "$WP_CORE_CHECKSUMS_URL" "$WP_CORE_CHECKSUMS_SHA256"
ms_info "WordPress 建置成品就緒：$dist"
