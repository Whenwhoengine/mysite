#!/usr/bin/env bash
# 阻擋 Blade 不跳脫輸出（ADR-010、API-05）：只有 rich-content 元件可以使用 `{!! !!}`。
# exit code（規劃書 v5 附錄 D.4）：0 通過；10 參數錯誤；30 發現違規。
set -Eeuo pipefail

usage() {
  cat <<'USAGE'
用法：scripts/ci/check-blade-raw.sh [--views-dir=<目錄>] [--help]

掃描 Blade 樣板中的不跳脫輸出 `{!! ... !!}`。
唯一允許的位置：components/rich-content.blade.php（只輸出 App\Support\Html\SafeContent）。

選項：
  --views-dir=<目錄>  要掃描的樣板根目錄（預設：app/resources/views）
  -h, --help          顯示本說明

結束碼：0 未發現違規；10 參數錯誤或目錄不存在；30 發現違規（列出檔名與行號）。
USAGE
}

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
views="$root/app/resources/views"
for arg in "$@"; do
  case "$arg" in
    -h|--help) usage; exit 0 ;;
    --views-dir=*) views="${arg#--views-dir=}" ;;
    *) printf '未知參數：%s\n' "$arg" >&2; usage >&2; exit 10 ;;
  esac
done
[[ -d "$views" ]] || { printf '找不到樣板目錄：%s\n' "$views" >&2; exit 10; }
views="$(cd "$views" && pwd)"

allowed="components/rich-content.blade.php"
violations=0
while IFS= read -r -d '' file; do
  rel="${file#"$views"/}"
  # 只允許 rich-content 元件內的唯一一處 {!! $content->html() !!}
  if [[ "$rel" == "$allowed" ]]; then
    count="$(grep -o '{!!' "$file" | wc -l | tr -d ' ')"
    if [[ "$count" -ne 1 ]] || ! grep -Eq '\{!![[:space:]]*\$content->html\(\)[[:space:]]*!!\}' "$file"; then
      printf '違規：%s 只能有一處 {!! $content->html() !!}\n' "$rel"
      violations=$((violations + 1))
    fi
    continue
  fi
  while IFS= read -r line; do
    printf '違規：%s:%s 使用了不跳脫輸出 {!! !!}\n' "$rel" "${line%%:*}"
    violations=$((violations + 1))
  done < <(grep -n '{!!' "$file" || true)
  # @php echo／<?php echo 亦視為繞過跳脫
  while IFS= read -r line; do
    printf '違規：%s:%s 以 PHP echo 直接輸出\n' "$rel" "${line%%:*}"
    violations=$((violations + 1))
  done < <(grep -nE '<\?(php|=)|@php[[:space:]]*\(?[[:space:]]*echo|\becho[[:space:]]' "$file" | grep -vE '^\s*[0-9]+:\s*\{\{--' || true)
done < <(find "$views" -type f -name '*.blade.php' -print0 | sort -z)

if [[ "$violations" -gt 0 ]]; then
  printf 'FAIL：發現 %d 處不跳脫輸出；請改用 {{ }} 或 <x-rich-content :content="$safeContent" />。\n' "$violations" >&2
  exit 30
fi
printf 'PASS：Blade 不跳脫輸出只出現在 %s。\n' "$allowed"
