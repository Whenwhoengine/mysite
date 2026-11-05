#!/usr/bin/env bash
# Compose 呼叫包裝：固定 project 名稱、env-file 與秘密目錄，避免拿未驗證輸入組命令。
# shellcheck shell=bash

# ms_compose_env <root> <env>：輸出（export）該環境 Compose 需要的變數
ms_compose_prepare() {
  local root="$1" env="$2"
  export COMPOSE_PROJECT_NAME; COMPOSE_PROJECT_NAME="$(ms_compose_project "$env")"
  export MYSITE_ENVIRONMENT="$env"
  export MYSITE_DIST_DIR="${MYSITE_DIST_DIR:-$root/.runtime/dist}"
  export MYSITE_SECRETS_DIR="${MYSITE_SECRETS_DIR:-$root/.runtime/secrets/$env}"
  export MYSITE_SOURCE_COMMIT; MYSITE_SOURCE_COMMIT="$(git -C "$root" rev-parse HEAD 2>/dev/null || echo unknown)"
  if ms_has_proxy_ca; then
    export COMPOSER_PREFER="${COMPOSER_PREFER:-source}"
  fi
  MS_COMPOSE_ENV_FILE="$root/.runtime/env/$env.env"
  [[ -f "$MS_COMPOSE_ENV_FILE" ]] || ms_die "$EXIT_EXTERNAL" "缺少 $MS_COMPOSE_ENV_FILE；請先執行 scripts/cloud/bootstrap.sh --env=$env"
  # 環境檔可覆寫 registry（Claude VM 使用 digest 相同的 ECR Public）
  export MYSITE_EFFECTIVE_REGISTRY
  MYSITE_EFFECTIVE_REGISTRY="$(grep -E '^BASE_IMAGE_REGISTRY=' "$MS_COMPOSE_ENV_FILE" | tail -1 | cut -d= -f2-)"
  MYSITE_EFFECTIVE_REGISTRY="${MYSITE_EFFECTIVE_REGISTRY:-$BASE_IMAGE_REGISTRY}"
  # shellcheck disable=SC2034
  MS_COMPOSE_ARGS=(--env-file "$root/infra/versions.env" --env-file "$MS_COMPOSE_ENV_FILE")
  local f
  for f in $(ms_compose_files "$root" "$env"); do
    [[ "$f" == "-f" ]] && continue
    MS_COMPOSE_ARGS+=(-f "$f")
  done
}

ms_compose() {
  docker compose "${MS_COMPOSE_ARGS[@]}" "$@"
}
