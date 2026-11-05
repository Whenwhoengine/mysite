#!/usr/bin/env bash
# CLD-04／DAT-05：以合成秘密驗證 .gitignore 與 .dockerignore，並檢查已追蹤檔案沒有秘密樣式。
set -Euo pipefail
usage() { echo "用法：tests/security/ignore-rules.sh [--help]  （exit 0 通過、30 失敗）"; }
[[ "${1:-}" == "-h" || "${1:-}" == "--help" ]] && { usage; exit 0; }
root="$(git rev-parse --show-toplevel)"; cd "$root"
fail=0
must_ignore=(
  ".env" "app/.env" ".env.production" ".runtime/secrets/development/app_key" "backup/site.sql" "dump.sql.gz"
  "app/vendor/autoload.php" "app/node_modules/vite/package.json" "wp/uploads/2026/11/a.jpg" "secrets/key.pem"
  "id_ed25519" "tests/e2e/.auth/state.json" "storageState.json" "auth.json" "release.envelope.key" "site.sqlite"
  "app/storage/logs/laravel.log" "wp/core/wp-load.php" "podcast.mp3"
)
must_track=(
  ".env.example" "app/.env.example" "app/composer.lock" "app/package-lock.json" "wp/plugins/site-content/site-content.php"
  "wp/acf-json/group_example.json" "app/database/migrations/2026_01_01_000000_example.php" "tests/fixtures/media/sample.wav"
)
for p in "${must_ignore[@]}"; do
  if ! git check-ignore -q --no-index "$p"; then echo "FAIL：應被忽略但未被忽略：$p"; fail=1; fi
done
for p in "${must_track[@]}"; do
  if git check-ignore -q --no-index "$p"; then echo "FAIL：應提交但被忽略：$p"; fail=1; fi
done
# .dockerignore：關鍵樣式存在
for pat in '.runtime' '**/.env' '**/secrets' '.git' 'app/vendor' '**/node_modules'; do
  grep -qxF "$pat" .dockerignore || { echo "FAIL：.dockerignore 缺少 $pat"; fail=1; }
done
# 已追蹤的秘密不會因新增 ignore 自動消失：檢查目前追蹤檔案中沒有秘密檔
tracked_bad="$(git ls-files | grep -E '(^|/)\.env$|\.pem$|\.key$|(^|/)secrets/|\.sql(\.gz)?$|\.dump$|^\.runtime/' | grep -v 'test-ca-public.crt' || true)"
[[ -z "$tracked_bad" ]] || { echo "FAIL：已追蹤的敏感檔：$tracked_bad"; fail=1; }
# 合成秘密：建立後確認 git status 看不到
tmp=".runtime/secrets/ignore-test"; mkdir -p "$tmp"; printf 'synthetic-secret\n' > "$tmp/probe"; printf 'APP_KEY=base64:synthetic\n' > app/.env.ignore-probe
if git status --porcelain --ignored=no | grep -qE 'ignore-test|\.env\.ignore-probe'; then echo "FAIL：合成秘密出現在 git status"; fail=1; fi
rm -rf "$tmp" app/.env.ignore-probe
if (( fail )); then exit 30; fi
echo "PASS：忽略規則（${#must_ignore[@]} 個應忽略、${#must_track[@]} 個應追蹤）與已追蹤檔案檢查"
