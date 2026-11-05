#!/usr/bin/env bash
# 首次建立與冪等補缺：秘密、映像、MySQL 雙庫、WordPress 安裝、Laravel migrations、合成資料、啟動與驗證。
# 重跑不重建資料、不重設管理員、不換 APP_KEY／salts、不重複 seed（CLD-05）。
set -Eeuo pipefail
umask 027

usage() {
  cat <<'EOF'
用法：scripts/cloud/bootstrap.sh --env=development|testing [--skip-build] [--no-seed] [--help]

  --env=development   雲端開發環境（原始碼 bind mount，入口 127.0.0.1:8080／8081）
  --env=testing       乾淨測試環境（使用建置好的映像，入口 127.0.0.1:18080／18081）
  --skip-build        不重新建置映像（映像必須已存在）
  --no-seed           不建立合成示例資料

正式環境（staging／production）不使用本腳本；請用 scripts/deploy/bootstrap-host.sh。
exit code：0 成功；10 參數錯；20 缺外部前置或秘密需修復；21 建議改用 Actions；30 驗證失敗。
EOF
}

env_name=""; skip_build=0; seed=1
for arg in "$@"; do
  case "$arg" in
    --env=*) env_name="${arg#--env=}" ;;
    --skip-build) skip_build=1 ;;
    --no-seed) seed=0 ;;
    -h|--help) usage; exit 0 ;;
    *) printf '未知參數：%s\n' "$arg" >&2; usage >&2; exit 10 ;;
  esac
done

# shellcheck source=../lib/common.sh
source "$(dirname "${BASH_SOURCE[0]}")/../lib/common.sh"
# shellcheck source=../lib/compose.sh
source "$(dirname "${BASH_SOURCE[0]}")/../lib/compose.sh"
# shellcheck source=../lib/build.sh
source "$(dirname "${BASH_SOURCE[0]}")/../lib/build.sh"

[[ -n "$env_name" ]] || { usage >&2; exit "$EXIT_USAGE"; }
ms_require_env_enum "$env_name" development testing

root="$(ms_root)"
cd "$root"
ms_load_versions "$root"

case "$(git remote get-url origin 2>/dev/null)" in
  *[Ww]henwhoengine/mysite*) ;;
  *) ms_die "$EXIT_FORBIDDEN" "目標儲存庫不是 Whenwhoengine/mysite，拒絕執行。" ;;
esac
command -v docker >/dev/null && timeout 15 docker info >/dev/null 2>&1 \
  || ms_die "$EXIT_USE_ACTIONS" "Docker daemon 不可用；請改走 GitHub Actions（sandbox.yml）。"

secrets_dir="$root/.runtime/secrets/$env_name"
env_file="$root/.runtime/env/$env_name.env"
state_dir="$root/.runtime/state/$env_name"
evidence_dir="$root/.runtime/evidence"
mkdir -p "$root/.runtime/env" "$secrets_dir/ca" "$state_dir" "$evidence_dir" "$root/.runtime/dist"
chmod 0700 "$root/.runtime/secrets" "$secrets_dir/ca"
chmod 0750 "$secrets_dir"
ms_chgrp_33 "$secrets_dir" || ms_die "$EXIT_EXTERNAL" "無法把秘密目錄群組設為 33（需要 root 或免密碼 sudo）；容器將讀不到秘密檔"

project="$(ms_compose_project "$env_name")"
volume_exists() { docker volume inspect "${project}_$1" >/dev/null 2>&1; }

# ---------------------------------------------------------------------------
# 1. 環境設定檔（只在缺少時建立；既有檔案保留）
# ---------------------------------------------------------------------------
if [[ ! -f "$env_file" ]]; then
  if [[ "$env_name" == development ]]; then
    app_port=8080; cms_port=8081; debug=true; subnet=30; log_level=debug
  else
    app_port=18080; cms_port=18081; debug=false; subnet=31; log_level=info
  fi
  registry="${MYSITE_BASE_IMAGE_REGISTRY:-}"
  if [[ -z "$registry" ]] && ms_has_proxy_ca; then
    registry="$BASE_IMAGE_REGISTRY_FALLBACK"   # Claude VM：Docker Hub 限流，改用 digest 相同的 ECR Public
  fi
  {
    printf '# 由 scripts/cloud/bootstrap.sh 產生（%s）；非秘密。\n' "$(date -u +%FT%TZ)"
    printf 'MYSITE_ENVIRONMENT=%s\n' "$env_name"
    printf 'WP_ENVIRONMENT_TYPE=development\n'
    printf 'APP_DEBUG=%s\nLOG_LEVEL=%s\n' "$debug" "$log_level"
    printf 'APP_URL=http://mysite.localhost:%s\nWP_PUBLIC_URL=http://cms.mysite.localhost:%s\n' "$app_port" "$cms_port"
    printf 'APP_PORT=%s\nCMS_PORT=%s\n' "$app_port" "$cms_port"
    printf 'APP_LOCALE=zh_TW\nAPP_TIMEZONE=Asia/Taipei\n'
    printf 'COMMERCE_MODE=%s\n' "${MYSITE_COMMERCE_MODE:-disabled}"
    printf 'APP_TEST_DB_NAME=mysite_app_test\n'
    printf 'EDGE_SUBNET=172.%s.10.0/24\nBACKEND_SUBNET=172.%s.20.0/24\nDB_SUBNET=172.%s.30.0/24\n' "$subnet" "$subnet" "$subnet"
    printf 'MAIL_MAILER=log\nSESSION_SECURE_COOKIE=false\n'
    [[ -n "$registry" ]] && printf 'BASE_IMAGE_REGISTRY=%s\n' "$registry"
    if [[ "$env_name" == testing ]]; then
      printf 'MYSITE_TOOLS_IMAGE=mysite/laravel-test:local\nMYSITE_TOOLS_TARGET=app-test\n'
      printf 'WP_JOBS_INTERVAL_SECONDS=10\n'
      printf 'MYSITE_LOGIN_RATE=120r/m\n'   # 測試環境大量自動登入；正式維持 10r/m
    fi
  } > "$env_file"
  chmod 0640 "$env_file"
  ms_info "已建立環境設定 $env_file"
fi

# ---------------------------------------------------------------------------
# 2. 秘密（缺少才產生；資料卷已存在而秘密遺失 → 拒絕，標記需修復）
# ---------------------------------------------------------------------------
rand_b64() { openssl rand -base64 "$1" | tr -d '\n=+/' | cut -c1-"$2"; }
write_secret() {
  local name="$1" value="$2" path="$secrets_dir/$1"
  ( umask 077; printf '%s\n' "$value" > "$path.tmp" )
  ms_chgrp_33 "$path.tmp" || ms_die "$EXIT_EXTERNAL" "無法把秘密檔群組設為 33（需要 root 或免密碼 sudo）"
  chmod 0640 "$path.tmp"
  mv "$path.tmp" "$path"
}
need_secret() {
  # need_secret <name> <依賴的資料卷或空字串>
  local name="$1" vol="$2"
  [[ -s "$secrets_dir/$name" ]] && return 1
  if [[ -n "$vol" ]] && volume_exists "$vol"; then
    printf '%s\n' "SECRET_MISSING_WITH_DATA:$name" >> "$state_dir/repair-needed"
    ms_die "$EXIT_EXTERNAL" "資料卷 ${project}_${vol} 已存在但秘密 $name 遺失；不得隨機換密碼假恢復。請以 scripts/ops/rotate-secrets.sh 受控處理或確認後重建測試資料卷。"
  fi
  return 0
}

need_secret mysql_root_password mysql-data && write_secret mysql_root_password "$(rand_b64 48 40)"
need_secret wp_db_password mysql-data && write_secret wp_db_password "$(rand_b64 48 40)"
need_secret app_db_password mysql-data && write_secret app_db_password "$(rand_b64 48 40)"
need_secret app_key mysql-data && write_secret app_key "base64:$(openssl rand -base64 32 | tr -d '\n')"
if need_secret wp_salts mysql-data; then
  salts=""
  for n in AUTH_KEY SECURE_AUTH_KEY LOGGED_IN_KEY NONCE_KEY AUTH_SALT SECURE_AUTH_SALT LOGGED_IN_SALT NONCE_SALT; do
    salts+="$n=$(rand_b64 96 64)"$'\n'
  done
  ( umask 077; printf '%s' "$salts" > "$secrets_dir/wp_salts.tmp" ); ms_chgrp_33 "$secrets_dir/wp_salts.tmp" || ms_die "$EXIT_EXTERNAL" "無法設定 wp_salts 群組"
  chmod 0640 "$secrets_dir/wp_salts.tmp"; mv "$secrets_dir/wp_salts.tmp" "$secrets_dir/wp_salts"
fi
need_secret content_event_hmac_key "" && write_secret content_event_hmac_key "$(openssl rand -hex 32)"
need_secret preview_signing_key "" && write_secret preview_signing_key "$(openssl rand -hex 32)"
need_secret diagnostics_token "" && write_secret diagnostics_token "$(openssl rand -hex 24)"
need_secret deploy_status_token "" && write_secret deploy_status_token "$(openssl rand -hex 24)"
need_secret wp_admin_password mysql-data && write_secret wp_admin_password "$(rand_b64 48 32)"
need_secret wp_editor_password mysql-data && write_secret wp_editor_password "$(rand_b64 48 32)"
need_secret app_admin_password mysql-data && write_secret app_admin_password "$(rand_b64 48 32)"

# 內部測試 CA 與 wp-web 伺服器憑證（只供容器內 HTTPS；Laravel 只信任此 CA）
if [[ ! -s "$secrets_dir/ca/ca.key" || ! -s "$secrets_dir/ca/ca.crt" ]]; then
  ( umask 077
    openssl genpkey -algorithm EC -pkeyopt ec_paramgen_curve:P-256 -out "$secrets_dir/ca/ca.key" 2>/dev/null
    openssl req -x509 -new -key "$secrets_dir/ca/ca.key" -sha256 -days 825 -subj "/CN=mysite ${env_name} internal CA" \
      -addext "basicConstraints=critical,CA:TRUE" -addext "keyUsage=critical,keyCertSign,cRLSign" -out "$secrets_dir/ca/ca.crt" )
  rm -f "$secrets_dir/wp_internal_tls_cert"
fi
cp "$secrets_dir/ca/ca.crt" "$secrets_dir/internal_ca_cert.tmp"; ms_chgrp_33 "$secrets_dir/internal_ca_cert.tmp" || ms_die "$EXIT_EXTERNAL" "無法設定 internal_ca_cert 群組"
chmod 0640 "$secrets_dir/internal_ca_cert.tmp"; mv "$secrets_dir/internal_ca_cert.tmp" "$secrets_dir/internal_ca_cert"
if [[ ! -s "$secrets_dir/wp_internal_tls_cert" ]] || ! openssl x509 -checkend $((30*86400)) -noout -in "$secrets_dir/wp_internal_tls_cert" >/dev/null 2>&1; then
  ( umask 077
    openssl genpkey -algorithm EC -pkeyopt ec_paramgen_curve:P-256 -out "$secrets_dir/wp_internal_tls_key" 2>/dev/null
    openssl req -new -key "$secrets_dir/wp_internal_tls_key" -subj "/CN=wp-web" -out "$secrets_dir/wp.csr"
    printf 'subjectAltName=DNS:wp-web\nextendedKeyUsage=serverAuth\nkeyUsage=critical,digitalSignature\n' > "$secrets_dir/wp.ext"
    openssl x509 -req -in "$secrets_dir/wp.csr" -CA "$secrets_dir/ca/ca.crt" -CAkey "$secrets_dir/ca/ca.key" -CAcreateserial \
      -days 397 -sha256 -extfile "$secrets_dir/wp.ext" -out "$secrets_dir/wp_internal_tls_cert" 2>/dev/null
    rm -f "$secrets_dir/wp.csr" "$secrets_dir/wp.ext" "$secrets_dir/ca/ca.srl" )
  ms_chgrp_33 "$secrets_dir/wp_internal_tls_cert" "$secrets_dir/wp_internal_tls_key" || ms_die "$EXIT_EXTERNAL" "無法設定 wp-web TLS 檔群組"
  chmod 0640 "$secrets_dir/wp_internal_tls_cert" "$secrets_dir/wp_internal_tls_key"
fi
# 服務帳號 Application Password 在 WP 安裝後建立；先放不可用的佔位值，避免 Compose 因缺檔拒絕載入。
if [[ ! -s "$secrets_dir/wp_service_app_password" ]]; then
  write_secret wp_service_app_password "PENDING-BOOTSTRAP-$(openssl rand -hex 8)"
fi

# ---------------------------------------------------------------------------
# 3. 相依成品與映像
# ---------------------------------------------------------------------------
bash "$root/scripts/cloud/fetch-deps.sh"
ms_compose_prepare "$root" "$env_name"

if (( ! skip_build )); then
  ms_info "建置映像（$env_name）…"
  ms_build_images "$root" "$env_name"
fi

# development：以 bind mount 執行，需在 app/ 依 lockfile 安裝相依與建置前端
if [[ "$env_name" == development ]]; then
  if [[ ! -f "$root/app/vendor/autoload.php" || "$root/app/composer.lock" -nt "$root/app/vendor/composer/installed.json" ]]; then
    ms_info "依 composer.lock 安裝 PHP 相依…"
    bash "$root/scripts/cloud/composer.sh" install --no-interaction --no-scripts
    bash "$root/scripts/cloud/composer.sh" dump-autoload
  fi
  if [[ ! -d "$root/app/node_modules" || "$root/app/package-lock.json" -nt "$root/app/node_modules/.package-lock.json" ]]; then
    ms_info "依 package-lock.json 安裝前端相依…"
    docker run --rm --network host -v "$root/app:/app" -w /app \
      ${NODE_EXTRA_CA_CERTS:+-e NODE_EXTRA_CA_CERTS=/tmp/ca.pem -v "$NODE_EXTRA_CA_CERTS:/tmp/ca.pem:ro"} \
      "${BASE_IMAGE_REGISTRY_FALLBACK}/${NODE_IMAGE_REF}" npm ci --no-audit --no-fund
  fi
  ms_info "建置前端資產…"
  docker run --rm -v "$root/app:/app" -w /app "${BASE_IMAGE_REGISTRY_FALLBACK}/${NODE_IMAGE_REF}" npm run build >/dev/null
  # 開發用空白 .env（不含任何設定或秘密；設定由 Compose 注入），避免 dotenv 讀取不存在檔案的警告
  [[ -f "$root/app/.env" ]] || printf '# 開發用：設定由 Compose 環境變數注入，秘密由 *_FILE 讀取；請勿在此填入任何值。\n' > "$root/app/.env"
  mkdir -p "$root/app/storage/framework/"{cache/data,sessions,views,testing} "$root/app/storage/logs" "$root/app/bootstrap/cache"
  ms_chgrp_33 -R "$root/app/storage" "$root/app/bootstrap/cache" || ms_die "$EXIT_EXTERNAL" "無法設定 storage 群組"; chmod -R g+rwX "$root/app/storage" "$root/app/bootstrap/cache"
fi

# ---------------------------------------------------------------------------
# 4. MySQL（空資料卷才初始化雙庫雙帳號）
# ---------------------------------------------------------------------------
ms_compose up -d mysql
ms_wait_until 240 3 ms_compose exec -T mysql mysqladmin ping -h 127.0.0.1 --silent \
  || ms_die "$EXIT_TEST_FAIL" "MySQL 未在時限內就緒"
# 初始化腳本可能仍在執行：等到兩個應用帳號都能登入
ms_wait_until 180 3 ms_compose exec -T -e MYSQL_PWD="$(cat "$secrets_dir/wp_db_password")" mysql mysql -h 127.0.0.1 -umysite_wp -e 'SELECT 1' mysite_wp \
  || ms_die "$EXIT_TEST_FAIL" "WordPress 資料庫帳號無法登入（初始化未完成或秘密不符）"

# ---------------------------------------------------------------------------
# 5. WordPress（未安裝才安裝；已安裝只補缺）
# ---------------------------------------------------------------------------
ms_compose up -d wp-php
ms_wait_until 120 2 ms_compose exec -T wp-php php -r 'exit(@fsockopen("127.0.0.1",9000)?0:1);' || ms_die "$EXIT_TEST_FAIL" "wp-php 未就緒"
wp() { ms_compose exec -T -u www-data wp-php wp "$@"; }

fresh_install=0
if ! wp core is-installed >/dev/null 2>&1; then
  fresh_install=1
  ms_info "安裝 WordPress ${WORDPRESS_VERSION}…"
  wp_url="$(grep -E '^WP_PUBLIC_URL=' "$env_file" | cut -d= -f2-)"
  wp core install --url="$wp_url" --title="mysite" --admin_user=mysite-admin \
    --admin_email=admin@mysite.invalid --skip-email --locale=zh_TW --prompt=admin_password \
    < "$secrets_dir/wp_admin_password" >/dev/null
fi
ms_compose exec -T wp-php php /opt/mysite/verify-core.php /var/www/wp /opt/mysite/wp-core-checksums.json >/dev/null \
  || ms_die "$EXIT_TEST_FAIL" "WordPress 核心 checksum 不符"
wp plugin activate advanced-custom-fields two-factor site-content --quiet
wp site-content setup
wp site switch-language zh_TW --quiet 2>/dev/null || wp language core activate zh_TW --quiet
wp option update timezone_string 'Asia/Taipei' --quiet
wp option update blog_public 0 --quiet   # CMS 前臺不供索引；canonical 屬主站
wp rewrite structure '/%postname%/' --quiet
wp rewrite flush --quiet

if ! wp user get mysite-editor --field=ID >/dev/null 2>&1; then
  wp user create mysite-editor editor@mysite.invalid --role=editor --display_name="示例編輯" --porcelain --prompt=user_pass \
    < "$secrets_dir/wp_editor_password" >/dev/null
fi
if ! wp user get mysite-service --field=ID >/dev/null 2>&1; then
  wp user create mysite-service service@mysite.invalid --role=mysite_service --display_name="mysite 服務帳號" \
    --user_pass="$(openssl rand -hex 32)" --porcelain >/dev/null
fi
current_pw="$(cat "$secrets_dir/wp_service_app_password")"
if [[ "$current_pw" == PENDING-BOOTSTRAP-* ]] || (( fresh_install )); then
  # 只在首次安裝或尚未建立時發行；既有環境的輪替走 scripts/ops/rotate-secrets.sh
  for uuid in $(wp user application-password list mysite-service --name=mysite-laravel --field=uuid 2>/dev/null); do
    wp user application-password delete mysite-service "$uuid" --quiet
  done
  write_secret wp_service_app_password "$(wp user application-password create mysite-service mysite-laravel --porcelain | tr -d '\r\n ')"
  ms_info "已發行 WordPress 服務帳號 Application Password（只寫入秘密檔）"
fi

# ---------------------------------------------------------------------------
# 6. Laravel migrations 與服務
# ---------------------------------------------------------------------------
ms_compose up -d app-php
ms_wait_until 120 2 ms_compose exec -T app-php php -r 'exit(@fsockopen("127.0.0.1",9000)?0:1);' || ms_die "$EXIT_TEST_FAIL" "app-php 未就緒"
artisan() { ms_compose exec -T -u www-data app-php php artisan "$@"; }
artisan migrate --force --no-interaction

# 首位 Laravel 管理員（development／testing）：密碼經 stdin 寫入容器內 0600 暫存檔，命令列不出現明文；已存在則 no-op
ms_compose exec -T -u www-data app-php sh -c 'umask 077; f=$(mktemp); cat > "$f"; php artisan mysite:admin:create --email=admin@mysite.invalid --name="示例管理員" --role=admin --password-file="$f" --no-interaction; rc=$?; rm -f "$f"; exit $rc' \
  < "$secrets_dir/app_admin_password" || ms_die "$EXIT_TEST_FAIL" "建立 Laravel 管理員失敗"

# ---------------------------------------------------------------------------
# 7. 合成示例資料（只限 development／testing；可重跑不重複）
# ---------------------------------------------------------------------------
if (( seed )); then
  if wp help site-content seed >/dev/null 2>&1; then
    wp site-content seed
  else
    ms_warn "site-content seed 指令尚未實作；略過 WP 示例資料"
  fi
  # WordPress 安裝時附帶的英文預設文章／頁面不屬本站內容：改為草稿（不刪除）
  for default_slug in hello-world sample-page; do
    for id in $(wp post list --post_type=post,page --name="$default_slug" --post_status=publish --field=ID 2>/dev/null); do
      wp post update "$id" --post_status=draft --quiet
    done
  done
  if ms_compose exec -T app-php test -f database/seeders/DevelopmentSeeder.php; then
    artisan db:seed --class='Database\Seeders\DevelopmentSeeder' --force --no-interaction
  fi
fi

# ---------------------------------------------------------------------------
# 8. 全部服務啟動並等待就緒
# ---------------------------------------------------------------------------
ms_compose up -d --wait --wait-timeout 300 || ms_die "$EXIT_TEST_FAIL" "服務未在時限內全部就緒"

# ---------------------------------------------------------------------------
# 9. 冪等性指紋（只存 hash，不存秘密）：第二次執行必須一致
# ---------------------------------------------------------------------------
fp_now="$state_dir/fingerprint.json"
admin_fp="$(wp user get mysite-admin --fields=ID,user_registered --format=json)"
{
  printf '{\n'
  printf '  "app_key_sha256": "%s",\n' "$(sha256sum "$secrets_dir/app_key" | cut -d' ' -f1)"
  printf '  "wp_salts_sha256": "%s",\n' "$(sha256sum "$secrets_dir/wp_salts" | cut -d' ' -f1)"
  printf '  "db_passwords_sha256": "%s",\n' "$(cat "$secrets_dir/mysql_root_password" "$secrets_dir/wp_db_password" "$secrets_dir/app_db_password" | sha256sum | cut -d' ' -f1)"
  printf '  "wp_admin": %s,\n' "$admin_fp"
  printf '  "wp_post_count": %s,\n' "$(wp post list --post_type=any --post_status=any --format=count)"
  printf '  "app_migrations": %s\n' "$(ms_compose exec -T -e MYSQL_PWD="$(cat "$secrets_dir/app_db_password")" mysql mysql -h 127.0.0.1 -umysite_app -N -e 'SELECT COUNT(*) FROM migrations' mysite_app)"
  printf '}\n'
} > "$fp_now.new"
if [[ -f "$fp_now" ]]; then
  if ! diff -q <(jq -S 'del(.wp_post_count, .app_migrations)' "$fp_now") <(jq -S 'del(.wp_post_count, .app_migrations)' "$fp_now.new") >/dev/null; then
    ms_die "$EXIT_TEST_FAIL" "CLD-05：重跑 bootstrap 改變了金鑰、salts、DB 密碼或管理員（指紋不一致）"
  fi
  if (( $(jq .wp_post_count "$fp_now.new") != $(jq .wp_post_count "$fp_now") )) && (( seed )); then
    ms_warn "WordPress 內容數量與上次不同（可能有人工編輯）；seed 本身不重複建立"
  fi
  ms_info "CLD-05 冪等指紋與上次一致"
fi
mv "$fp_now.new" "$fp_now"

bash "$root/scripts/cloud/doctor.sh" --env="$env_name"
ms_info "bootstrap 完成（$env_name）。主站：$(grep -E '^APP_URL=' "$env_file" | cut -d= -f2-)　CMS：$(grep -E '^WP_PUBLIC_URL=' "$env_file" | cut -d= -f2-)"
