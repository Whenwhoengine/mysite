#!/usr/bin/env bash
# 映像建置唯一入口（Compose 只引用映像，不自行建置）。CI、Claude VM、release 共用。
# shellcheck shell=bash

# ms_build_targets <env>：輸出「target image」清單
ms_build_targets() {
  case "$1" in
    development) printf '%s\n' "app-dev laravel-dev" "app-web-dev app-web-dev" "wp-runtime wordpress" "wp-web wp-web" "gateway gateway" ;;
    testing|release) printf '%s\n' "app-runtime laravel" "app-test laravel-test" "app-web app-web" "wp-runtime wordpress" "wp-web wp-web" "gateway gateway" ;;
    *) ms_die "$EXIT_USAGE" "未知建置環境：$1" ;;
  esac
}

# ms_build_images <root> <env> [<tag>]
ms_build_images() {
  local root="$1" env="$2" tag="${3:-${MYSITE_IMAGE_TAG:-local}}" prefix="${MYSITE_IMAGE_PREFIX:-mysite}"
  local registry="${MYSITE_EFFECTIVE_REGISTRY:-$BASE_IMAGE_REGISTRY}"
  local -a common=(
    -f "$root/infra/images/php/Dockerfile"
    --build-context "dist=${MYSITE_DIST_DIR:-$root/.runtime/dist}"
    --build-arg "BASE_IMAGE_REGISTRY=$registry"
    --build-arg "PHP_FPM_IMAGE_REF=$PHP_FPM_IMAGE_REF"
    --build-arg "NGINX_IMAGE_REF=$NGINX_IMAGE_REF"
    --build-arg "COMPOSER_IMAGE_REF=$COMPOSER_IMAGE_REF"
    --build-arg "NODE_IMAGE_REF=$NODE_IMAGE_REF"
    --build-arg "WPCLI_IMAGE_REF=$WPCLI_IMAGE_REF"
    --build-arg "WORDPRESS_VERSION=$WORDPRESS_VERSION"
    --build-arg "WORDPRESS_ZIP_SHA256=$WORDPRESS_ZIP_SHA256"
    --build-arg "ACF_ZIP_SHA256=$ACF_ZIP_SHA256"
    --build-arg "TWO_FACTOR_ZIP_SHA256=$TWO_FACTOR_ZIP_SHA256"
    --build-arg "WP_ZH_TW_LANGPACK_SHA256=$WP_ZH_TW_LANGPACK_SHA256"
    --build-arg "WP_CORE_CHECKSUMS_SHA256=$WP_CORE_CHECKSUMS_SHA256"
    --build-arg "PCOV_VERSION=$PCOV_VERSION"
    --build-arg "PCOV_TGZ_URL=$PCOV_TGZ_URL"
    --build-arg "PCOV_TGZ_SHA256=$PCOV_TGZ_SHA256"
    --build-arg "COMPOSER_PREFER=${COMPOSER_PREFER:-dist}"
    --build-arg "SOURCE_COMMIT=${MYSITE_SOURCE_COMMIT:-unknown}"
  )
  if ms_has_proxy_ca; then
    common+=(--network host --build-arg "https_proxy=$HTTPS_PROXY" --build-arg "HTTPS_PROXY=$HTTPS_PROXY"
             --secret "id=build_ca,src=/root/.ccr/ca-bundle.crt")
  fi
  local line target name log
  while read -r target name; do
    log="$root/.runtime/build-${name}.log"
    ms_info "建置 ${prefix}/${name}:${tag}（target ${target}）"
    if ! docker build "${common[@]}" --target "$target" -t "${prefix}/${name}:${tag}" "$root" > "$log" 2>&1; then
      tail -30 "$log" | ms_redact >&2
      ms_die "$EXIT_TEST_FAIL" "映像建置失敗：${name}（完整 log：$log）"
    fi
  done < <(ms_build_targets "$env")
}
