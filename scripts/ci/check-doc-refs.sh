#!/usr/bin/env bash
# GOV-04：README、CLAUDE.md、docs/ 內提到的腳本與檔案路徑必須真的存在（避免只在文件宣稱可執行）。
set -Euo pipefail
usage() { echo "用法：scripts/ci/check-doc-refs.sh [--help]（exit 0 通過；30 有不存在的參照）"; }
[[ "${1:-}" == "-h" || "${1:-}" == "--help" ]] && { usage; exit 0; }
[[ $# -eq 0 ]] || { usage >&2; exit 10; }
root="$(git rev-parse --show-toplevel)"; cd "$root"
missing=0; checked=0
docs=(README.md CLAUDE.md AGENTS.md $(git ls-files 'docs/*.md' 'docs/**/*.md' | grep -vE '^docs/(規劃書v5|執行契約)\.md$'))
while IFS= read -r ref; do
  checked=$((checked + 1))
  [[ -e "$ref" ]] || { echo "不存在的參照：$ref"; missing=1; }
done < <(grep -ohE '(scripts|tests|infra|wp|app|docs)/[A-Za-z0-9_./@{},-]+\.(sh|py|php|yml|json|md|conf|template|ini|mjs)' "${docs[@]}" 2>/dev/null \
          | grep -vE '[{}*]|<' | sort -u)
(( missing )) && exit 30
echo "PASS：文件中的 ${checked} 個檔案參照皆存在"
