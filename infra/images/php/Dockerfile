# mysite 多目標映像（建置 context＝儲存庫根目錄；WordPress 成品另以 named context「dist」提供）。
# 基底映像一律 tag@digest 固定；registry 可在 docker.io/library 與 public.ecr.aws/docker/library 間切換（digest 相同）。
# 代理與 CA：只在需要的 RUN 以 BuildKit secret「build_ca」暫時合併，不寫入映像層。

ARG BASE_IMAGE_REGISTRY=docker.io/library
ARG PHP_FPM_IMAGE_REF=php:8.5.12-fpm-trixie@sha256:c34bcf437ed61bd5e4c1114494eaf1ffd54253bce2b95d263bfa18b350ee8432
ARG NGINX_IMAGE_REF=nginx:1.30.6-trixie@sha256:66b50d24288dba67f3d000b31223daefc4868c58d29aaacb6a4ff9771597b5eb
ARG COMPOSER_IMAGE_REF=composer:2.11.1@sha256:5190d9b445add2fa26090108273cb1ef1e1af98175201c737b85b3c4f90fbf7c
ARG NODE_IMAGE_REF=node:26.13.0-trixie-slim@sha256:e4db4c914bf8ad241bd24804fc25d272d89bad97e68cdc8a1414862790a02c39
ARG WPCLI_IMAGE_REF=wordpress:cli-2.12.0-php8.5@sha256:8175cf3199f08662ccace7a5c7b95cf3af165ac46c10039a9af58c7854084aff

FROM ${BASE_IMAGE_REGISTRY}/${COMPOSER_IMAGE_REF} AS composer-bin
FROM ${BASE_IMAGE_REGISTRY}/${WPCLI_IMAGE_REF} AS wpcli-bin

# ---------------------------------------------------------------------------
# php-base：Laravel 與 WordPress 共用的 PHP-FPM 執行期（不含編譯器）
# ---------------------------------------------------------------------------
FROM ${BASE_IMAGE_REGISTRY}/${PHP_FPM_IMAGE_REF} AS php-base
RUN --mount=type=secret,id=build_ca,required=false \
    set -eux; \
    sed -i 's#http://deb.debian.org#https://deb.debian.org#g' /etc/apt/sources.list.d/debian.sources; \
    cp /etc/ssl/certs/ca-certificates.crt /tmp/build-ca.pem; \
    if [ -s /run/secrets/build_ca ]; then cat /run/secrets/build_ca >> /tmp/build-ca.pem; fi; \
    export SSL_CERT_FILE=/tmp/build-ca.pem; \
    APT="apt-get -o Acquire::https::CAInfo=/tmp/build-ca.pem"; \
    savedAptMark="$(apt-mark showmanual)"; \
    $APT update; \
    $APT install -y --no-install-recommends $PHPIZE_DEPS libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli intl zip gd exif pcntl; \
    apt-mark auto '.*' > /dev/null; \
    apt-mark manual $savedAptMark > /dev/null; \
    find /usr/local -type f -executable -exec ldd '{}' ';' 2>/dev/null \
      | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) { next }; gsub("^/(usr/)?", "", so); printf "*%s\n", so }' \
      | sort -u | xargs -r dpkg-query --search | grep -v '^diversion' | cut -d: -f1 | sort -u | xargs -r apt-mark manual; \
    $APT purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    $APT install -y --no-install-recommends ca-certificates; \
    rm -rf /var/lib/apt/lists/* /tmp/build-ca.pem /usr/src/php.tar.*; \
    php -m | grep -qi '^pdo_mysql$'; php -m | grep -qi '^intl$'; php -m | grep -qi '^gd$'; php -m | grep -qi '^mysqli$'; php -m | grep -qi 'opcache'

ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS=0 PHP_FPM_MAX_CHILDREN=10
COPY infra/php/security.ini /usr/local/etc/php/conf.d/zz-security.ini
COPY infra/php/runtime.ini /usr/local/etc/php/conf.d/zz-runtime.ini
COPY infra/php/www.conf /usr/local/etc/php-fpm.d/zz-www.conf
RUN set -eux; \
    rm -f /usr/local/etc/php-fpm.d/www.conf.default; \
    mv /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini; \
    php-fpm -t
STOPSIGNAL SIGQUIT
EXPOSE 9000

# ---------------------------------------------------------------------------
# php-tools：開發／測試工具鏈（Composer、git、pcov），不用於正式執行期
# ---------------------------------------------------------------------------
FROM php-base AS php-tools
ARG PCOV_VERSION
ARG PCOV_TGZ_URL
ARG PCOV_TGZ_SHA256
COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer
RUN --mount=type=secret,id=build_ca,required=false \
    set -eux; \
    cp /etc/ssl/certs/ca-certificates.crt /tmp/build-ca.pem; \
    if [ -s /run/secrets/build_ca ]; then cat /run/secrets/build_ca >> /tmp/build-ca.pem; fi; \
    export SSL_CERT_FILE=/tmp/build-ca.pem; \
    APT="apt-get -o Acquire::https::CAInfo=/tmp/build-ca.pem"; \
    $APT update; \
    $APT install -y --no-install-recommends git unzip default-mysql-client $PHPIZE_DEPS; \
    curl -fsSL --proto '=https' -o /tmp/pcov.tgz "$PCOV_TGZ_URL"; \
    echo "$PCOV_TGZ_SHA256  /tmp/pcov.tgz" | sha256sum -c -; \
    mkdir -p /tmp/pcov-src; tar -xzf /tmp/pcov.tgz -C /tmp/pcov-src --strip-components=1 "pcov-${PCOV_VERSION}"; \
    (cd /tmp/pcov-src && phpize && ./configure --enable-pcov && make -j"$(nproc)" && make install); \
    docker-php-ext-enable pcov; \
    rm -rf /tmp/pcov.tgz /tmp/pcov-src; \
    echo 'pcov.enabled=0' > /usr/local/etc/php/conf.d/zz-pcov-off.ini; \
    rm -rf /var/lib/apt/lists/* /tmp/pear /tmp/build-ca.pem; \
    composer --version; php -m | grep -qi pcov
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_HOME=/tmp/composer-home
WORKDIR /var/www/app

# ---------------------------------------------------------------------------
# app-dev：開發用（原始碼以 bind mount 掛入；opcache 每次檢查時間戳）
# ---------------------------------------------------------------------------
FROM php-tools AS app-dev
ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS=1
COPY infra/images/laravel/entrypoint.sh /usr/local/bin/mysite-app-entrypoint
RUN chmod 0755 /usr/local/bin/mysite-app-entrypoint
ENTRYPOINT ["mysite-app-entrypoint"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# app-vendor：依 lockfile 安裝正式相依（no-dev）
# ---------------------------------------------------------------------------
FROM php-tools AS app-vendor
WORKDIR /var/www/app
COPY app/composer.json app/composer.lock ./
ARG COMPOSER_PREFER=dist
RUN --mount=type=secret,id=build_ca,required=false \
    --mount=type=cache,target=/tmp/composer-home/cache \
    set -eux; \
    cp /etc/ssl/certs/ca-certificates.crt /tmp/build-ca.pem; \
    if [ -s /run/secrets/build_ca ]; then cat /run/secrets/build_ca >> /tmp/build-ca.pem; fi; \
    export SSL_CERT_FILE=/tmp/build-ca.pem GIT_SSL_CAINFO=/tmp/build-ca.pem; \
    composer validate --strict --no-check-publish; \
    composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-"${COMPOSER_PREFER}"; \
    composer check-platform-reqs --no-dev; \
    rm -f /tmp/build-ca.pem
COPY app/ ./
RUN set -eux; \
    rm -rf .env tests node_modules storage/logs/* bootstrap/cache/*.php; \
    export MYSITE_SKIP_CONFIG_VALIDATION=1; \
    composer dump-autoload --no-dev --optimize --classmap-authoritative; \
    php artisan package:discover --ansi; \
    find vendor -name '.git' -type d -prune -exec rm -rf '{}' +

# ---------------------------------------------------------------------------
# app-assets：Vite 建置前端資產（Node 只存在於此建置階段）
# ---------------------------------------------------------------------------
FROM ${BASE_IMAGE_REGISTRY}/${NODE_IMAGE_REF} AS app-assets
WORKDIR /var/www/app
COPY app/package.json app/package-lock.json ./
RUN --mount=type=secret,id=build_ca,required=false \
    set -eux; \
    if [ -s /run/secrets/build_ca ]; then export NODE_EXTRA_CA_CERTS=/run/secrets/build_ca; fi; \
    npm ci --no-audit --no-fund
COPY app/vite.config.js ./
COPY app/resources ./resources
COPY app/public ./public
RUN npm run build && test -f public/build/manifest.json

# ---------------------------------------------------------------------------
# app-runtime：Laravel PHP-FPM／worker／scheduler 共用映像（程式唯讀）
# ---------------------------------------------------------------------------
FROM php-base AS app-runtime
WORKDIR /var/www/app
COPY --from=app-vendor --chown=root:root /var/www/app /var/www/app
COPY --from=app-assets --chown=root:root /var/www/app/public/build /var/www/app/public/build
COPY infra/images/laravel/entrypoint.sh /usr/local/bin/mysite-app-entrypoint
RUN set -eux; \
    chmod 0755 /usr/local/bin/mysite-app-entrypoint; \
    mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/framework/testing storage/logs bootstrap/cache; \
    chown -R www-data:www-data storage bootstrap/cache; \
    chmod -R a-w app config database routes resources vendor public; \
    test ! -e .env
ARG SOURCE_COMMIT=unknown
ARG RELEASE_ID=unreleased
LABEL org.opencontainers.image.source="https://github.com/Whenwhoengine/mysite" \
      org.opencontainers.image.revision="${SOURCE_COMMIT}" \
      org.opencontainers.image.title="mysite-laravel"
ENV MYSITE_SOURCE_COMMIT=${SOURCE_COMMIT}
ENTRYPOINT ["mysite-app-entrypoint"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# app-test：CI 測試映像（含 dev 相依與測試程式）
# ---------------------------------------------------------------------------
FROM php-tools AS app-test
WORKDIR /var/www/app
COPY app/composer.json app/composer.lock ./
ARG COMPOSER_PREFER=dist
RUN --mount=type=secret,id=build_ca,required=false \
    --mount=type=cache,target=/tmp/composer-home/cache \
    set -eux; \
    cp /etc/ssl/certs/ca-certificates.crt /tmp/build-ca.pem; \
    if [ -s /run/secrets/build_ca ]; then cat /run/secrets/build_ca >> /tmp/build-ca.pem; fi; \
    export SSL_CERT_FILE=/tmp/build-ca.pem GIT_SSL_CAINFO=/tmp/build-ca.pem; \
    composer install --no-interaction --no-scripts --no-autoloader --prefer-"${COMPOSER_PREFER}"; \
    rm -f /tmp/build-ca.pem
COPY app/ ./
COPY --from=app-assets /var/www/app/public/build /var/www/app/public/build
RUN set -eux; printf '# 測試映像：設定全由環境變數注入；此檔只避免 dotenv 讀取不存在的檔案。\n' > .env; export MYSITE_SKIP_CONFIG_VALIDATION=1; composer dump-autoload --optimize; php artisan package:discover --ansi; \
    chown -R www-data:www-data storage bootstrap/cache

# ---------------------------------------------------------------------------
# wp-core：驗證並展開 WordPress 核心、語系包與核准外掛（只信任 hash 相符的 zip）
# ---------------------------------------------------------------------------
FROM php-tools AS wp-core
ARG WORDPRESS_VERSION
ARG WORDPRESS_ZIP_SHA256
ARG ACF_ZIP_SHA256
ARG TWO_FACTOR_ZIP_SHA256
ARG WP_ZH_TW_LANGPACK_SHA256
ARG WP_CORE_CHECKSUMS_SHA256
COPY --from=dist wordpress.zip acf.zip two-factor.zip zh_TW.zip wp-core-checksums.json /tmp/dist/
RUN set -eux; \
    cd /tmp/dist; \
    printf '%s  %s\n' "$WORDPRESS_ZIP_SHA256" wordpress.zip "$ACF_ZIP_SHA256" acf.zip "$TWO_FACTOR_ZIP_SHA256" two-factor.zip "$WP_ZH_TW_LANGPACK_SHA256" zh_TW.zip "$WP_CORE_CHECKSUMS_SHA256" wp-core-checksums.json | sha256sum -c -; \
    mkdir -p /opt/mysite; cp wp-core-checksums.json /opt/mysite/wp-core-checksums.json; \
    mkdir -p /var/www; unzip -q wordpress.zip -d /var/www; mv /var/www/wordpress /var/www/wp; \
    grep -q "\$wp_version = '${WORDPRESS_VERSION}'" /var/www/wp/wp-includes/version.php; \
    rm -rf /var/www/wp/wp-content/plugins/akismet /var/www/wp/wp-content/plugins/hello.php; \
    unzip -q acf.zip -d /var/www/wp/wp-content/plugins; \
    unzip -q two-factor.zip -d /var/www/wp/wp-content/plugins; \
    mkdir -p /var/www/wp/wp-content/languages; unzip -q zh_TW.zip -d /var/www/wp/wp-content/languages; \
    test -f /var/www/wp/wp-content/plugins/advanced-custom-fields/acf.php; \
    test -f /var/www/wp/wp-content/plugins/two-factor/two-factor.php; \
    rm -rf /tmp/dist
COPY wp/plugins/site-content /var/www/wp/wp-content/plugins/site-content
COPY wp/mu-plugins /var/www/wp/wp-content/mu-plugins
COPY wp/acf-json /var/www/wp/wp-content/acf-json
COPY wp/config/wp-config.template.php /var/www/wp/wp-config.php
RUN set -eux; \
    find /var/www/wp/wp-content -name '.git*' -prune -exec rm -rf '{}' +; \
    mkdir -p /var/www/wp/wp-content/uploads /var/www/wp/wp-content/upgrade

# ---------------------------------------------------------------------------
# wp-runtime：WordPress PHP-FPM 與 wp-jobs（核心與外掛唯讀；只有 uploads 可寫）
# ---------------------------------------------------------------------------
FROM php-base AS wp-runtime
COPY --from=wpcli-bin /usr/local/bin/wp /usr/local/bin/wp
COPY --from=wp-core --chown=root:root /var/www/wp /var/www/wp
COPY --from=wp-core /opt/mysite/wp-core-checksums.json /opt/mysite/wp-core-checksums.json
COPY infra/images/wordpress/verify-core.php /opt/mysite/verify-core.php
COPY infra/images/wordpress/entrypoint.sh /usr/local/bin/mysite-wp-entrypoint
COPY infra/images/wordpress/jobs.sh /usr/local/bin/mysite-wp-jobs
RUN set -eux; \
    chmod 0755 /usr/local/bin/mysite-wp-entrypoint /usr/local/bin/mysite-wp-jobs /usr/local/bin/wp; \
    chown www-data:www-data /var/www/wp/wp-content/uploads /var/www/wp/wp-content/upgrade; \
    chmod -R a-w /var/www/wp/wp-admin /var/www/wp/wp-includes /var/www/wp/wp-content/plugins /var/www/wp/wp-content/mu-plugins /var/www/wp/wp-content/acf-json /var/www/wp/wp-content/languages; \
    php -l /var/www/wp/wp-config.php
ARG SOURCE_COMMIT=unknown
LABEL org.opencontainers.image.source="https://github.com/Whenwhoengine/mysite" \
      org.opencontainers.image.revision="${SOURCE_COMMIT}" \
      org.opencontainers.image.title="mysite-wordpress"
ENV MYSITE_SOURCE_COMMIT=${SOURCE_COMMIT}
WORKDIR /var/www/wp
ENTRYPOINT ["mysite-wp-entrypoint"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# Web 層：Nginx（Laravel 靜態入口、WP 靜態入口、gateway）
# ---------------------------------------------------------------------------
FROM ${BASE_IMAGE_REGISTRY}/${NGINX_IMAGE_REF} AS web-base
ARG SOURCE_COMMIT=unknown
LABEL org.opencontainers.image.source="https://github.com/Whenwhoengine/mysite" \
      org.opencontainers.image.revision="${SOURCE_COMMIT}"
RUN rm -f /etc/nginx/conf.d/default.conf
COPY infra/nginx/common/ /etc/nginx/snippets/

FROM web-base AS app-web-dev
COPY infra/nginx/app-web.conf /etc/nginx/conf.d/app-web.conf

FROM web-base AS app-web
COPY --from=app-runtime /var/www/app/public /var/www/app/public
COPY infra/nginx/app-web.conf /etc/nginx/conf.d/app-web.conf
RUN nginx -t

FROM web-base AS wp-web
COPY --from=wp-core /var/www/wp /var/www/wp
COPY infra/nginx/wp-web.conf.template /etc/nginx/templates/wp-web.conf.template

FROM web-base AS gateway
COPY infra/nginx/gateway/ /etc/nginx/templates/
COPY infra/nginx/maintenance/ /var/www/maintenance/
RUN mkdir -p /var/www/acme/.well-known/acme-challenge
