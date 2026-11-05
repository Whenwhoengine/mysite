#!/usr/bin/env bash
# 雲端與儲存庫預檢（唯讀；只寫 .runtime/evidence/）。
# 分別記錄：工具存在、daemon 可連線、映像可拉取、服務實際可啟動。
set -Eeuo pipefail
umask 077

usage() {
  cat <<'EOF'
用法：scripts/cloud/preflight.sh [--help]

診斷目前雲端 Linux 環境與儲存庫是否適合施工：
  - 儲存庫身分、分支、提交
  - CPU 架構、磁碟、記憶體
  - Docker CLI／daemon／Compose、基底映像可否拉取、容器可否實際啟動
  - 必要網路來源（套件、WordPress、ACF）
報告寫入 .runtime/evidence/cloud-preflight.txt，不輸出環境變數或憑證。

exit code：0 可用 Claude 雲端 Compose；20 缺外部前置；21 建議改用 GitHub Actions。
EOF
}

if [[ $# -gt 0 ]]; then
  case "$1" in
    -h|--help) usage; exit 0 ;;
    *) printf '未知參數：%s\n' "$1" >&2; usage >&2; exit 10 ;;
  esac
fi

# shellcheck source=../lib/common.sh
source "$(dirname "${BASH_SOURCE[0]}")/../lib/common.sh"
root="$(ms_root)" || exit "$EXIT_EXTERNAL"
cd "$root"
mkdir -p .runtime/evidence
report=".runtime/evidence/cloud-preflight.txt"
: > "$report"

rec() { printf '%s\n' "$*" >> "$report"; }

rec "utc=$(date -u +%FT%TZ)"
rec "os=$(uname -s) arch=$(uname -m)"
rec "commit=$(git rev-parse HEAD)"
rec "branch=$(git rev-parse --abbrev-ref HEAD)"
remote_url="$(git remote get-url origin 2>/dev/null | ms_redact || true)"
rec "remote=${remote_url}"
rec "dirty_files=$(git status --porcelain | wc -l | tr -d ' ')"
rec "cpus=$(nproc 2>/dev/null || echo unknown)"
rec "mem_total_kb=$(awk '/MemTotal/{print $2}' /proc/meminfo 2>/dev/null || echo unknown)"
rec "disk_avail_kb=$(df -Pk . | awk 'NR==2{print $4}')"
rec "in_claude_remote=${CLAUDE_CODE_REMOTE:-false}"

if [[ "$(uname -s)" != "Linux" ]]; then
  printf '%s\n' 'BLOCKED: 本案只在雲端 Linux 執行。' >&2
  exit "$EXIT_EXTERNAL"
fi

case "$remote_url" in
  *github.com*[Ww]henwhoengine/mysite*) rec "repo_identity=ok" ;;
  *) rec "repo_identity=mismatch"; printf '%s\n' 'BLOCKED: 目標儲存庫不是 Whenwhoengine/mysite，停止寫入。' >&2; exit "$EXIT_FORBIDDEN" ;;
esac

for tool in git docker php composer node npm curl jq openssl; do
  if command -v "$tool" >/dev/null 2>&1; then
    rec "tool=${tool} present=yes"
  else
    rec "tool=${tool} present=no"
  fi
done

if timeout 20 git ls-remote --heads origin >/dev/null 2>&1; then
  rec "github_read=ok"
else
  rec "github_read=failed"
fi

# 必要網路來源（只記 HTTP 狀態碼）。
for url in \
  https://repo.packagist.org/packages.json \
  https://api.wordpress.org/core/version-check/1.7/ \
  https://registry.npmjs.org/ \
  https://www.advancedcustomfields.com/ \
  https://deb.debian.org/debian/ ; do
  code="$(curl -s -o /dev/null -w '%{http_code}' -m 15 "$url" || echo 000)"
  rec "net ${url} http=${code}"
done

if ! command -v docker >/dev/null 2>&1; then
  rec 'docker_cli=missing'
  rec 'BACKEND=github-actions'
  printf '%s\n' 'USE_ACTIONS: 找不到 Docker CLI；請改用 GitHub Actions（sandbox.yml）。明細：.runtime/evidence/cloud-preflight.txt' >&2
  exit "$EXIT_USE_ACTIONS"
fi
rec "docker_cli=$(docker --version 2>/dev/null)"

if ! timeout 15 docker info >/dev/null 2>&1; then
  rec 'docker_daemon=unreachable'
  rec 'hint=可嘗試以 dockerd 啟動 daemon；仍失敗即改用 GitHub Actions'
  rec 'BACKEND=github-actions'
  printf '%s\n' 'USE_ACTIONS: Docker daemon 無法連線（15 秒內 docker info 失敗）；可先以 dockerd 啟動，仍失敗即改用 GitHub Actions（sandbox.yml）。明細：.runtime/evidence/cloud-preflight.txt' >&2
  exit "$EXIT_USE_ACTIONS"
fi
rec "docker_daemon=reachable server=$(docker version --format '{{.Server.Version}}' 2>/dev/null)"

if ! compose_v="$(docker compose version 2>&1)"; then
  rec 'docker_compose=missing'
  rec 'BACKEND=github-actions'
  printf '%s\n' 'USE_ACTIONS: 缺少 docker compose 外掛；請改用 GitHub Actions（sandbox.yml）。' >&2
  exit "$EXIT_USE_ACTIONS"
fi
rec "docker_compose=${compose_v}"

# 映像可拉取：以已鎖定 digest 的 MySQL 映像測試，Docker Hub 限流時改走 digest 相同的 ECR Public 鏡像。
ms_load_versions "$root"
pull_ok=no
for registry in "${BASE_IMAGE_REGISTRY:-docker.io/library}" "${BASE_IMAGE_REGISTRY_FALLBACK:-public.ecr.aws/docker/library}"; do
  ref="${registry}/${MYSQL_IMAGE_REF}"
  if docker image inspect "$ref" >/dev/null 2>&1 || timeout 180 docker pull -q "$ref" >/dev/null 2>&1; then
    rec "image_pull=ok registry=${registry}"
    pull_ok=yes
    probe_ref="$ref"
    break
  else
    rec "image_pull=failed registry=${registry}"
  fi
done
if [[ "$pull_ok" != yes ]]; then
  rec 'BACKEND=github-actions'
  printf '%s\n' 'USE_ACTIONS: Docker Hub 與 ECR Public 都無法取得鎖定的 MySQL 映像；請改用 GitHub Actions（sandbox.yml）。明細：.runtime/evidence/cloud-preflight.txt' >&2
  exit "$EXIT_USE_ACTIONS"
fi

# 服務實際可啟動：以該映像跑一次 mysqld --version。
if out="$(timeout 60 docker run --rm --network none "$probe_ref" mysqld --version 2>&1)"; then
  rec "container_start=ok probe=$(printf '%s' "$out" | head -1)"
else
  rec 'container_start=failed'
  rec 'BACKEND=github-actions'
  printf '%s\n' 'USE_ACTIONS: 測試容器無法啟動（mysqld --version 失敗）；請改用 GitHub Actions（sandbox.yml）。明細：.runtime/evidence/cloud-preflight.txt' >&2
  exit "$EXIT_USE_ACTIONS"
fi

rec 'BACKEND=claude-cloud-compose'
cat "$report"
printf '%s\n' '預檢完成；尚未代表網站或依賴已驗收。'
exit "$EXIT_OK"
