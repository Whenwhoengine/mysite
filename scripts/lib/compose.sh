#!/usr/bin/env bash
# Compose 呼叫包裝：固定 project 名稱、env-file 與秘密目錄，避免拿未驗證輸入組命令。
# shellcheck shell=bash

# ms_ensure_derived_secrets <秘密目錄>：冪等補建 Compose 需要掛載的衍生秘密檔（GAP-KEYRING-01）。
#   content_event_hmac_keyring／preview_signing_keyring ← 由既有單一金鑰組成一行 `k1 <金鑰> active`
#   app_previous_keys ← 空檔（只有 APP_KEY 輪替的過渡期才有內容）
# 已存在的檔案一律不動；不產生新金鑰、不改既有金鑰值、不輸出秘密。秘密目錄或單一金鑰不存在時不做任何事
# （由 bootstrap 首次產生）。這不是重設：金鑰圈內容與單一金鑰相同，應用行為不變。
ms_ensure_derived_secrets() {
  local dir="$1" pair single ring key tmp
  [[ -n "$dir" && -d "$dir" && -w "$dir" ]] || return 0
  for pair in content_event_hmac_key:content_event_hmac_keyring preview_signing_key:preview_signing_keyring; do
    single="${pair%%:*}"; ring="${pair##*:}"
    [[ -e "$dir/$ring" || ! -s "$dir/$single" ]] && continue
    key="$(tr -d '\r\n' < "$dir/$single")"
    if [[ ! "$key" =~ ^[0-9a-f]{64}$ ]]; then
      ms_warn "$single 不是 64 字元小寫 hex，無法自動建立 $ring；請依 docs/runbooks/秘密輪替.md 處理"
      continue
    fi
    tmp="$(umask 077; mktemp "$dir/.${ring}.XXXXXX")"
    printf 'k1 %s active\n' "$key" > "$tmp"
    ms_chgrp_33 "$tmp" 2>/dev/null || ms_warn "無法把 $ring 群組設為 33；容器可能讀不到"
    chmod 0640 "$tmp"
    mv -n -- "$tmp" "$dir/$ring"; rm -f -- "$tmp"
    ms_info "已由既有單一金鑰補建 $ring（k1 active；金鑰值不變）"
  done
  if [[ ! -e "$dir/app_previous_keys" ]]; then
    tmp="$(umask 077; mktemp "$dir/.app_previous_keys.XXXXXX")"
    ms_chgrp_33 "$tmp" 2>/dev/null || ms_warn "無法把 app_previous_keys 群組設為 33；容器可能讀不到"
    chmod 0640 "$tmp"
    mv -n -- "$tmp" "$dir/app_previous_keys"; rm -f -- "$tmp"
  fi
}

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
  # Compose 掛載金鑰圈與 app_previous_keys；既有環境（bootstrap 早於金鑰圈支援）在此冪等補建，已存在不動
  ms_ensure_derived_secrets "$MYSITE_SECRETS_DIR"
  # 環境檔可覆寫 registry（Claude VM 使用 digest 相同的 ECR Public）
  export MYSITE_EFFECTIVE_REGISTRY
  MYSITE_EFFECTIVE_REGISTRY="$(grep -E '^BASE_IMAGE_REGISTRY=' "$MS_COMPOSE_ENV_FILE" | tail -1 | cut -d= -f2-)"
  MYSITE_EFFECTIVE_REGISTRY="${MYSITE_EFFECTIVE_REGISTRY:-$BASE_IMAGE_REGISTRY}"
  # gateway 固定位址與 edge 動態分配範圍：由 EDGE_SUBNET（x.y.z.0/24）推導，環境檔明列時以環境檔為準
  local edge base
  edge="$(grep -E '^EDGE_SUBNET=' "$MS_COMPOSE_ENV_FILE" | tail -1 | cut -d= -f2-)"
  if [[ -n "$edge" ]]; then
    [[ "$edge" =~ ^(172\.(1[6-9]|2[0-9]|3[01])\.[0-9]{1,3})\.0/24$ ]] || ms_die "$EXIT_USAGE" "EDGE_SUBNET 必須是 172.16–31 範圍的 /24：$edge"
    base="${BASH_REMATCH[1]}"
    grep -qE '^GATEWAY_EDGE_IP=' "$MS_COMPOSE_ENV_FILE" || export GATEWAY_EDGE_IP="${base}.10"
    grep -qE '^EDGE_IP_RANGE=' "$MS_COMPOSE_ENV_FILE" || export EDGE_IP_RANGE="${base}.128/25"
  fi
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
