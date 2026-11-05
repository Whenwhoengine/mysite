#!/usr/bin/env bash
# 下載並驗證 WordPress 核心、ACF、Two Factor 與 zh_TW 語系包（hash 依 infra/versions.env）。
# 成品放 .runtime/dist/（Git 忽略），供映像建置以 named context「dist」取用。
set -Eeuo pipefail
usage() {
  cat <<'USAGE'
用法：scripts/cloud/fetch-deps.sh [--help]
依 infra/versions.env 的來源與 SHA-256 取得 WordPress 建置所需 zip，不符即失敗（exit 30）。
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

fetch wordpress.zip "$WORDPRESS_ZIP_URL" "$WORDPRESS_ZIP_SHA256"
# WordPress 官方另提供 .sha1，再交叉核對一次
if command -v sha1sum >/dev/null; then
  printf '%s  %s\n' "$WORDPRESS_ZIP_SHA1" "$dist/wordpress.zip" | sha1sum -c --status - || ms_die "$EXIT_TEST_FAIL" "WordPress 官方 SHA-1 不符"
fi
fetch acf.zip "$ACF_SOURCE_URL" "$ACF_ZIP_SHA256"
fetch two-factor.zip "$TWO_FACTOR_ZIP_URL" "$TWO_FACTOR_ZIP_SHA256"
fetch zh_TW.zip "$WP_ZH_TW_LANGPACK_URL" "$WP_ZH_TW_LANGPACK_SHA256"
fetch wp-core-checksums.json "$WP_CORE_CHECKSUMS_URL" "$WP_CORE_CHECKSUMS_SHA256"
ms_info "WordPress 建置成品就緒：$dist"
