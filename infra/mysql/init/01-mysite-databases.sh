#!/bin/bash
# 只在「空的」MySQL 資料卷首次初始化時執行（官方映像 docker-entrypoint-initdb.d 語意）。
# 建立 WordPress 與 Laravel 兩個資料庫、兩組受限帳號；應用不使用 root（INF-02）。
set -Eeuo pipefail

read_secret() {
  local f="$1"
  [[ -s "$f" ]] || { echo "mysite-init: 秘密檔 $f 缺失或為空" >&2; exit 1; }
  local v; v="$(cat "$f")"; v="${v%$'\n'}"
  printf '%s' "$v"
}

valid_ident() { [[ "$1" =~ ^[a-z][a-z0-9_]{1,31}$ ]] || { echo "mysite-init: 不合法識別字 $1" >&2; exit 1; }; }

wp_db="${MYSITE_WP_DB_NAME:?}"; wp_user="${MYSITE_WP_DB_USER:?}"
app_db="${MYSITE_APP_DB_NAME:?}"; app_user="${MYSITE_APP_DB_USER:?}"
app_test_db="${MYSITE_APP_TEST_DB_NAME:-}"
for i in "$wp_db" "$wp_user" "$app_db" "$app_user"; do valid_ident "$i"; done
[[ -z "$app_test_db" ]] || valid_ident "$app_test_db"

wp_pass="$(read_secret /run/secrets/wp_db_password)"
app_pass="$(read_secret /run/secrets/app_db_password)"
sql_escape() { printf '%s' "$1" | sed "s/\\\\/\\\\\\\\/g; s/'/''/g"; }

{
  echo "CREATE DATABASE IF NOT EXISTS \`${wp_db}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
  echo "CREATE DATABASE IF NOT EXISTS \`${app_db}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
  echo "CREATE USER IF NOT EXISTS '${wp_user}'@'%' IDENTIFIED BY '$(sql_escape "$wp_pass")';"
  echo "CREATE USER IF NOT EXISTS '${app_user}'@'%' IDENTIFIED BY '$(sql_escape "$app_pass")';"
  echo "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, CREATE TEMPORARY TABLES, LOCK TABLES, REFERENCES ON \`${wp_db}\`.* TO '${wp_user}'@'%';"
  echo "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, CREATE TEMPORARY TABLES, LOCK TABLES, REFERENCES, CREATE VIEW, SHOW VIEW, TRIGGER ON \`${app_db}\`.* TO '${app_user}'@'%';"
  if [[ -n "$app_test_db" ]]; then
    echo "CREATE DATABASE IF NOT EXISTS \`${app_test_db}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
    echo "GRANT ALL PRIVILEGES ON \`${app_test_db}\`.* TO '${app_user}'@'%';"
  fi
  echo "FLUSH PRIVILEGES;"
} | MYSQL_PWD="$(read_secret /run/secrets/mysql_root_password)" mysql --protocol=socket -uroot

echo "mysite-init: 已建立 ${wp_db}／${app_db}（測試庫：${app_test_db:-無}）與受限帳號。"
