#!/usr/bin/env bash
# 規則式秘密掃描：Git 追蹤（或即將提交）的檔案與指定證據目錄。無法掃描＝未完成，不是無秘密。
set -Euo pipefail
usage() { echo "用法：scripts/ci/secret-scan.sh [--staged] [--path=<目錄>]...（exit 0 無發現、30 有發現、10 參數錯）"; }
mode=tracked; paths=()
for a in "$@"; do
  case "$a" in
    --staged) mode=staged ;;
    --path=*) paths+=("${a#--path=}") ;;
    -h|--help) usage; exit 0 ;;
    *) usage >&2; exit 10 ;;
  esac
done
root="$(git rev-parse --show-toplevel)"; cd "$root"
if [[ "$mode" == staged ]]; then mapfile -t files < <(git diff --cached --name-only --diff-filter=ACM)
else mapfile -t files < <(git ls-files); fi
for p in "${paths[@]}"; do [[ -d "$p" ]] && mapfile -t -O "${#files[@]}" files < <(find "$p" -type f); done
patterns=(
  '-----BEGIN ([A-Z]+ )?PRIVATE KEY-----'
  'AKIA[0-9A-Z]{16}'
  'gh[pousr]_[A-Za-z0-9]{30,}'
  'github_pat_[A-Za-z0-9_]{40,}'
  'xox[baprs]-[A-Za-z0-9-]{10,}'
  'sk-ant-[A-Za-z0-9_-]{20,}'
  'base64:[A-Za-z0-9+/]{40,}={0,2}'
  '(PASSWORD|SECRET|TOKEN|HMAC_KEY|SIGNING_KEY)[A-Z_]*=[^[:space:]$<{/"\x27(][^[:space:]]{11,}'
  '[A-Za-z0-9]{4} [A-Za-z0-9]{4} [A-Za-z0-9]{4} [A-Za-z0-9]{4} [A-Za-z0-9]{4} [A-Za-z0-9]{4}'
)
allow_file="$root/scripts/ci/secret-scan.allow"
[[ -f "$allow_file" ]] || allow_file=/dev/null
found=0
for f in "${files[@]}"; do
  [[ -f "$f" ]] || continue
  case "$f" in *.lock|*package-lock.json|docs/規劃書v5.md|docs/執行契約.md|scripts/ci/secret-scan.sh|scripts/ci/secret-scan.allow) continue ;; esac
  file -b --mime "$f" 2>/dev/null | grep -q 'charset=binary' && continue
  for re in "${patterns[@]}"; do
    if grep -nE -- "$re" "$f" | grep -vFf <(grep -vE '^(#|$)' "$allow_file") | grep -q .; then
      echo "可能的秘密：$f（規則：${re:0:40}…）"; found=1
    fi
  done
done
(( found )) && exit 30
echo "secret-scan：${#files[@]} 個檔案無發現（規則式；不等於保證無秘密）"
