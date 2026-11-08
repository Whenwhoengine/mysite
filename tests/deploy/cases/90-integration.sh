#!/usr/bin/env bash
# 整合測試（OPS-07、DAT-06 資料庫部分）：以專屬 mysite-deploytest-* project 起真實 MySQL（平台 versions.env 的 digest）
# 與真實 restic（官方發行檔，SHA-256 固定），實際執行 backup.sh → restore-check.sh（隔離 project）。
# 只清理本測試建立的容器與 volume（以 com.docker.compose.project label 精確篩選），不做任何全域 prune。
# shellcheck shell=bash

# 由 run.sh 的 EXIT trap 呼叫（後援清理）
it_cleanup() {
  local p f
  [[ -f "$T_ROOT/it-projects" ]] || return 0
  while IFS= read -r p; do
    [[ "$p" =~ ^mysite-deploytest-[a-z0-9]{4,16}(-restorecheck-[0-9a-f]{8})?$ ]] || continue
    mapfile -t f < <(docker ps -aq --filter "label=com.docker.compose.project=$p" 2>/dev/null)
    (( ${#f[@]} )) && docker rm -f -v "${f[@]}" >/dev/null 2>&1
    mapfile -t f < <(docker volume ls -q --filter "label=com.docker.compose.project=$p" 2>/dev/null)
    (( ${#f[@]} )) && docker volume rm "${f[@]}" >/dev/null 2>&1
  done < "$T_ROOT/it-projects"
  return 0
}

it_restic() {
  # 取得 restic：MYSITE_TEST_RESTIC_BIN 或官方 GitHub Release（SHA-256 固定，快取於 .runtime/cache）
  if [[ -n "${MYSITE_TEST_RESTIC_BIN:-}" ]]; then printf '%s\n' "$MYSITE_TEST_RESTIC_BIN"; return 0; fi
  local ver sha cache
  ver="$(grep -E '^RESTIC_VERSION=' "$T_REPO/infra/versions.env" | cut -d= -f2)"; ver="${ver:-0.19.1}"
  sha="$(grep -E '^RESTIC_LINUX_AMD64_SHA256=' "$T_REPO/infra/versions.env" | cut -d= -f2)"
  sha="${sha:-f415415624dcc452f2a02b8c33641791a8c6d6d3b65bbb3543fcf9a25151585c}"
  cache="$T_REPO/.runtime/cache/restic-$ver"; mkdir -p "$cache"
  if ! printf '%s  %s\n' "$sha" "$cache/restic.bz2" | sha256sum -c --status - 2>/dev/null; then
    curl -fsSL --proto '=https' --tlsv1.2 --max-time 180 --retry 3 --retry-delay 2 -o "$cache/restic.bz2.part" \
      "https://github.com/restic/restic/releases/download/v${ver}/restic_${ver}_linux_amd64.bz2" || return 1
    printf '%s  %s\n' "$sha" "$cache/restic.bz2.part" | sha256sum -c --status - || { rm -f "$cache/restic.bz2.part"; return 1; }
    mv "$cache/restic.bz2.part" "$cache/restic.bz2"
  fi
  bunzip2 -c "$cache/restic.bz2" > "$T_ROOT/restic" && chmod 0755 "$T_ROOT/restic" && printf '%s\n' "$T_ROOT/restic"
}

i01_mysql_backup_restore() {
  (( ${integration:-1} )) || { echo "SKIP: --no-integration"; return 77; }
  command -v docker >/dev/null 2>&1 && timeout 20 docker info >/dev/null 2>&1 || { echo "SKIP: 無 Docker daemon"; return 77; }
  local free; free="$(df -Pm "$T_ROOT" | awk 'NR==2{print $4}')"
  (( free >= 300 )) || { echo "SKIP: 臨時目錄可用空間 ${free} MB 不足 300 MB"; return 77; }   # 暫定：資料庫放 tmpfs，磁碟只放 dump 與 restic repo
  local P; P="mysite-deploytest-$(openssl rand -hex 4)"; echo "$P" >> "$T_ROOT/it-projects"
  trap 'it_cleanup' EXIT   # 本函式在獨立子 shell 執行，結束即清理本測試建立的資源
  local ref reg image=""
  ref="$(grep -E '^MYSQL_IMAGE_REF=' "$T_REPO/infra/versions.env" | cut -d= -f2)"
  for reg in ${MYSITE_BASE_IMAGE_REGISTRY:-} public.ecr.aws/docker/library docker.io/library; do
    if docker image inspect "$reg/$ref" >/dev/null 2>&1 || timeout 300 docker pull -q "$reg/$ref" >/dev/null 2>&1; then image="$reg/$ref"; break; fi
  done
  [[ -n "$image" ]] || { fail "無法取得 MySQL 映像 $ref"; return 1; }
  local restic; restic="$(it_restic)" || { fail "無法取得並驗證 restic 發行檔"; return 1; }
  "$restic" version | grep -q '^restic ' || { fail "restic 無法執行"; return 1; }

  # --- 來源環境（模擬 staging 主機上的 project）---
  H="$T_ROOT/it/srv"; HE="$H/environments/staging"; HENV=staging
  mkdir -p "$HE"/{config,secrets,state,releases} "$H/backup-staging/staging"; chmod 0750 "$HE/secrets"; chmod 0700 "$H/backup-staging" "$H/backup-staging/staging"
  printf 'DEPLOY_ENVIRONMENT=staging\nDEPLOY_CHANNEL=staging\nRESTIC_BIN=%s\nBASE_IMAGE_REGISTRY=%s\nMIN_FREE_DISK_MB=1\n' "$restic" "${image%%/mysql:*}" > "$HE/config/deploy.env"
  ( umask 027; openssl rand -hex 24 > "$HE/secrets/restic_password"; printf '%s\n' "$T_ROOT/it/restic-repo" > "$HE/secrets/restic_repository" )
  RESTIC_PASSWORD_FILE="$HE/secrets/restic_password" RESTIC_REPOSITORY="$T_ROOT/it/restic-repo" RESTIC_CACHE_DIR="$T_ROOT/it/cache" \
    "$restic" init >/dev/null 2>&1 || { fail "restic init 失敗"; return 1; }
  local dbs="$T_ROOT/it/dbsecrets"; mkdir -p "$dbs"; chmod 0755 "$dbs"
  local s; for s in mysql_root_password wp_db_password app_db_password; do ( umask 022; openssl rand -hex 20 > "$dbs/$s" ); done
  # 測試資料庫與 uploads 放在記憶體 tmpfs volume（不占主機磁碟；只屬於本測試 project）
  docker volume create --driver local --opt type=tmpfs --opt device=tmpfs --opt o=size=600m --label "com.docker.compose.project=$P" "${P}_mysql-data" >/dev/null
  docker volume create --driver local --opt type=tmpfs --opt device=tmpfs --opt o=size=16m --label "com.docker.compose.project=$P" "${P}_wp-uploads" >/dev/null
  docker run -d --name "${P}-mysql" --network none --label "com.docker.compose.project=$P" --label com.docker.compose.service=mysql \
    -v "${P}_mysql-data:/var/lib/mysql" -v "$dbs:/run/secrets:ro" -v "$T_REPO/infra/mysql/init:/docker-entrypoint-initdb.d:ro" \
    -e MYSQL_ROOT_PASSWORD_FILE=/run/secrets/mysql_root_password -e MYSITE_WP_DB_NAME=mysite_wp -e MYSITE_WP_DB_USER=mysite_wp \
    -e MYSITE_APP_DB_NAME=mysite_app -e MYSITE_APP_DB_USER=mysite_app \
    "$image" --character-set-server=utf8mb4 --collation-server=utf8mb4_0900_ai_ci --skip-name-resolve --innodb-flush-method=fsync >/dev/null || { fail "無法啟動測試 MySQL"; return 1; }
  docker run -d --name "${P}-wp" --network none --label "com.docker.compose.project=$P" --label com.docker.compose.service=wp-php \
    -v "${P}_wp-uploads:/var/www/wp/wp-content/uploads" --entrypoint sleep "$image" infinity >/dev/null || { fail "無法啟動 uploads 容器"; return 1; }
  sq() { docker exec -i "${P}-mysql" sh -c 'MYSQL_PWD="$(cat "/run/secrets/$1")" exec mysql -h 127.0.0.1 -N -B -u "$2" "$3"' sh "$@"; }
  local i; for i in $(seq 1 80); do echo 'SELECT 1' | sq app_db_password mysite_app mysite_app >/dev/null 2>&1 && break; sleep 3; done
  echo 'SELECT 1' | sq app_db_password mysite_app mysite_app >/dev/null 2>&1 || { docker logs --tail 20 "${P}-mysql"; fail "測試 MySQL 未就緒"; return 1; }
  sq wp_db_password mysite_wp mysite_wp <<'SQL' || { fail "WP 合成資料建立失敗"; return 1; }
CREATE TABLE wp_posts (ID bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, post_title text, post_status varchar(20) NOT NULL DEFAULT 'publish',
  post_type varchar(20) NOT NULL DEFAULT 'post', post_password varchar(255) NOT NULL DEFAULT '') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO wp_posts (post_title, post_status, post_type) VALUES ('合成公開文章','publish','post'),('合成頁面','publish','page'),('合成草稿','draft','post'),('合成圖片','inherit','attachment');
CREATE TABLE wp_postmeta (meta_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, post_id bigint unsigned NOT NULL, meta_key varchar(255), meta_value longtext) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (4,'_wp_attached_file','2026/11/synthetic.jpg');
SQL
  sq app_db_password mysite_app mysite_app <<'SQL' || { fail "Laravel 合成資料建立失敗"; return 1; }
CREATE TABLE migrations (id int unsigned AUTO_INCREMENT PRIMARY KEY, migration varchar(255) NOT NULL, batch int NOT NULL);
INSERT INTO migrations (migration, batch) VALUES ('2026_11_05_200300_create_orders_table', 1), ('2026_11_05_200700_create_data_deletion_log_table', 1);
CREATE TABLE products (id bigint unsigned AUTO_INCREMENT PRIMARY KEY, name varchar(100) NOT NULL, stock int NOT NULL DEFAULT 0);
INSERT INTO products (name, stock) VALUES ('合成商品 A', 5), ('合成商品 B', 0);
CREATE TABLE orders (id bigint unsigned AUTO_INCREMENT PRIMARY KEY, total_minor int NOT NULL, contact_name varchar(100) NOT NULL);
INSERT INTO orders (total_minor, contact_name) VALUES (1200, '合成買家');
CREATE TABLE data_deletion_log (id bigint unsigned AUTO_INCREMENT PRIMARY KEY, subject_type varchar(32) NOT NULL, subject_id bigint unsigned NULL,
  action varchar(16) NOT NULL, reason varchar(32) NOT NULL, affected_rows int unsigned NOT NULL DEFAULT 0, executed_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP);
INSERT INTO data_deletion_log (subject_type, subject_id, action, reason, affected_rows) VALUES ('order', 99, 'anonymize', 'retention', 1);
SQL
  docker exec "${P}-wp" sh -c 'mkdir -p /var/www/wp/wp-content/uploads/2026/11 && printf synthetic > /var/www/wp/wp-content/uploads/2026/11/synthetic.jpg' \
    || { fail "uploads 合成檔建立失敗"; return 1; }

  # --- 1. 完整備份（真實 mysqldump＋restic）---
  local out="$T_ROOT/it/out"; mkdir -p "$out"
  itrun() { env MYSITE_HOST_ROOT="$H" MYSITE_PROJECT_OVERRIDE="$P" MYSITE_RESTORECHECK_PREFIX="$P" "$@"; }
  itrun bash "$T_REPO/scripts/ops/backup.sh" --env=staging --reason=it-full --scope=all > "$out/b1.out" 2>&1
  assert_rc 0 $? "backup.sh（真實 MySQL＋restic）" || { tail -n 20 "$out/b1.out"; return 1; }
  local snap1; snap1="$(jq -r .snapshot_id "$HE/state/last-backup-all.json")"
  [[ "$snap1" =~ ^[0-9a-f]{64}$ ]] || { fail "未取得 snapshot ID"; return 1; }
  ! grep -q '合成買家' "$out/b1.out" || { fail "備份輸出含資料內容"; return 1; }

  # --- 2. 備份後又有新刪除紀錄與新訂單，再做 app 備份（刪除紀錄只增不減）---
  sq app_db_password mysite_app mysite_app <<'SQL' || { fail "追加資料失敗"; return 1; }
INSERT INTO data_deletion_log (subject_type, subject_id, action, reason, affected_rows) VALUES ('order', 1, 'anonymize', 'data-subject-request', 1);
INSERT INTO orders (total_minor, contact_name) VALUES (800, '合成買家二');
SQL
  itrun bash "$T_REPO/scripts/ops/backup.sh" --env=staging --reason=it-app --scope=app > "$out/b2.out" 2>&1
  assert_rc 0 $? "第二次 app 備份" || { tail -n 20 "$out/b2.out"; return 1; }

  # --- 3. 還原較舊的 snapshot 到全新隔離 project，並載入最新刪除紀錄 ---
  itrun bash "$T_REPO/scripts/ops/restore-check.sh" --env=staging --snapshot="$snap1" --target=isolated --level=db --tmpfs-mb=600 > "$out/r1.out" 2>&1
  assert_rc 0 $? "restore-check（舊 snapshot）" || { tail -n 25 "$out/r1.out"; return 1; }
  local rep="$out/r1.json"; grep '^{' "$out/r1.out" | tail -n 1 > "$rep"
  jq -e '.verdict == "PASS" and (.isolated_project | test("^mysite-deploytest-[a-z0-9]+-restorecheck-[0-9a-f]{8}$"))' "$rep" >/dev/null || { cat "$rep"; fail "還原判定或隔離 project 名稱不符"; return 1; }
  jq -e '.checks.integrity == "PASS" and .checks.import_wp == "PASS" and .checks.import_app == "PASS"' "$rep" >/dev/null || { cat "$rep"; fail "完整性或匯入未通過"; return 1; }
  jq -e '.checks.wp_posts == "PASS: 已發布 2、未公開 1" and (.checks.drafts_hidden | startswith("PASS")) and (.checks.media | startswith("PASS: 1 個"))' "$rep" >/dev/null \
    || { cat "$rep"; fail "文章／草稿／媒體檢查不符"; return 1; }
  jq -e '.checks.table_orders == "PASS: 1 筆可讀" and .checks.table_products == "PASS: 2 筆可讀"' "$rep" >/dev/null || { cat "$rep"; fail "應還原到舊時間點的訂單與商品"; return 1; }
  jq -e '(.checks.deletion_log | test("2 筆")) and (.rto_seconds | type == "number") and (.rpo_seconds | type == "number")' "$rep" >/dev/null \
    || { cat "$rep"; fail "應載入最新刪除紀錄（2 筆）並量測 RTO／RPO"; return 1; }
  local rc_project; rc_project="$(jq -r .isolated_project "$rep")"; echo "$rc_project" >> "$T_ROOT/it-projects"
  [[ -z "$(docker ps -aq --filter "label=com.docker.compose.project=$rc_project")" && -z "$(docker volume ls -q --filter "label=com.docker.compose.project=$rc_project")" ]] \
    || { fail "隔離 project 未清理乾淨"; return 1; }
  ! grep -q '合成買家' "$out/r1.out" "$HE/state/restore-checks.jsonl" || { fail "還原報告含資料內容"; return 1; }
  docker ps -q --filter "name=^${P}-mysql$" | grep -q . || { fail "來源 MySQL 不應被還原流程影響"; return 1; }

  # --- 4. latest：WP 取 all、Laravel 取最新 app snapshot ---
  itrun bash "$T_REPO/scripts/ops/restore-check.sh" --env=staging --snapshot=latest --target=isolated --level=db --tmpfs-mb=600 > "$out/r2.out" 2>&1
  assert_rc 0 $? "restore-check（latest）" || { tail -n 25 "$out/r2.out"; return 1; }
  grep '^{' "$out/r2.out" | tail -n 1 > "$out/r2.json"
  jq -e '.verdict == "PASS" and .checks.table_orders == "PASS: 2 筆可讀" and (.snapshots | length == 2)' "$out/r2.json" >/dev/null \
    || { cat "$out/r2.json"; fail "latest 應組合 WP 與 Laravel 各自最新 snapshot"; return 1; }
  jq -r .isolated_project "$out/r2.json" >> "$T_ROOT/it-projects"
  echo "OK: 真實 MySQL $(sed 's/.*mysql:\([0-9.]*\)@.*/\1/' <<< "$image")＋restic $("$restic" version | awk '{print $2}')：備份→舊 snapshot 隔離還原（文章 2／草稿 1／媒體 1／訂單 1、最新刪除紀錄 2 筆、RTO $(jq .rto_seconds "$rep") 秒）→ latest 組合還原，隔離資源皆清除"
}

case_add I01_real_mysql_backup_isolated_restore OPS-07,DAT-06 i01_mysql_backup_restore
