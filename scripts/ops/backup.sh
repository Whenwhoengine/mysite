#!/usr/bin/env bash
# 一致邏輯備份＋加密異地儲存（v5 第 15 章，OPS-07）：
#   加鎖 → mysqldump --single-transaction（各庫用自己的帳號）→ WP uploads 打包 → 部署狀態與刪除紀錄 →
#   backup-manifest.json（大小與 SHA-256）→ restic backup（加密 repository）→ restic check＋抽回比對 →
#   才記錄成功 snapshot（只記 ID、時間、大小、筆數，不輸出 dump）→ 依保留策略（預設只預覽）。
set -Eeuo pipefail
umask 077

usage() {
  cat <<'EOF'
用法：scripts/ops/backup.sh --env=staging|production --reason=<原因> [--scope=all|wp|app] [--verify=standard|deep] [--help]

  --reason=<原因>    例如 routine、pre-release-seq-12、manual-before-plugin-update（小寫英數與 ._-）
  --scope=all        WP DB＋uploads＋Laravel DB＋刪除紀錄＋部署狀態（預設；部署前備份用）
  --scope=wp         WP DB＋uploads＋部署狀態（每日 timer）
  --scope=app        Laravel DB＋刪除紀錄＋部署狀態（每小時 timer）
  --verify=standard  restic check（結構）＋抽回 backup-manifest.json 比對（預設）
  --verify=deep      另加 restic check --read-data-subset=10%（每週 timer）

秘密（environments/<env>/secrets/，只以檔案提供）：restic_password、restic_repository、restic_env（選用，雲端憑證白名單鍵）
成功紀錄：environments/<env>/state/backups.jsonl、last-backup-<scope>.json
exit code：0 成功；10 參數錯；20 缺 restic／備份目的地／資料庫容器；50 備份未完成（任何一步失敗都不記成功）。
EOF
}

env_name=""; reason=""; scope="all"; verify="standard"
for arg in "$@"; do
  case "$arg" in
    --env=*) env_name="${arg#--env=}" ;;
    --reason=*) reason="${arg#--reason=}" ;;
    --scope=*) scope="${arg#--scope=}" ;;
    --verify=*) verify="${arg#--verify=}" ;;
    -h|--help) usage; exit 0 ;;
    *) printf '未知參數：%s\n' "$arg" >&2; usage >&2; exit 10 ;;
  esac
done

# shellcheck source=../lib/deploy.sh
source "$(dirname "${BASH_SOURCE[0]}")/../lib/deploy.sh"
MD_SCRIPT_NAME=backup
[[ -n "$env_name" && -n "$reason" ]] || { usage >&2; exit "$EXIT_USAGE"; }
ms_require_env_enum "$env_name" staging production
[[ "$reason" =~ $MD_RE_REASON ]] || ms_die "$EXIT_USAGE" "--reason 只允許小寫英數與 ._-（最長 64）"
case "$scope" in all|wp|app) ;; *) ms_die "$EXIT_USAGE" "--scope 只允許 all／wp／app" ;; esac
case "$verify" in standard|deep) ;; *) ms_die "$EXIT_USAGE" "--verify 只允許 standard／deep" ;; esac
md_require_cmd python3 docker flock sha256sum
md_resolve_host_root
md_env_paths "$env_name"
md_load_config "$env_name"
MD_PROJECT="$(md_project_name "$env_name")"
md_restic_ready || ms_die "$EXIT_EXTERNAL" "BLOCKED_EXTERNAL：${MD_RESTIC_MISSING}"
md_docker_ok || ms_die "$EXIT_EXTERNAL" "Docker daemon 不可用"

readonly WP_DB=mysite_wp WP_USER=mysite_wp APP_DB=mysite_app APP_USER=mysite_app
mkdir -p "$MD_BACKUP_STAGING" "$MD_STATE_DIR"
chmod 0700 "$MD_BACKUP_STAGING"

md_lock "$MD_STATE_DIR/backup.lock" "${MD_BACKUP_LOCK_WAIT:-600}" \
  || ms_die "$EXIT_DEPLOY_INCOMPLETE" "BACKUP_LOCK_BUSY：另一個備份仍在執行（等待逾時）"

# 暫存目錄：固定名稱（讓 restic 以相同路徑做增量與保留分組），只含本腳本產生的檔案
run_dir="$MD_BACKUP_STAGING/snapshot-${scope}"
case "$run_dir" in
  "$MD_HOST_ROOT/backup-staging/$env_name/snapshot-all"|"$MD_HOST_ROOT/backup-staging/$env_name/snapshot-wp"|"$MD_HOST_ROOT/backup-staging/$env_name/snapshot-app") ;;
  *) ms_die "$EXIT_FORBIDDEN" "暫存路徑異常" ;;
esac
cleanup() { if [[ -d "$run_dir" ]]; then rm -rf -- "$run_dir"; fi; }
trap cleanup EXIT
cleanup
mkdir -m 0700 "$run_dir" "$run_dir/db" "$run_dir/deploy"
started_at="$(md_now)"

fail() { md_event ERROR backup_failed "$1"; ms_die "$EXIT_DEPLOY_INCOMPLETE" "BACKUP_FAILED：$1"; }

mysql_cid="$(md_container mysql)"
[[ -n "$mysql_cid" ]] || ms_die "$EXIT_EXTERNAL" "找不到執行中的 MySQL 容器（project ${MD_PROJECT}）"

# mysql_as <秘密檔名> <使用者> <資料庫> <SQL>：在 MySQL 容器內以應用帳號查詢（密碼由容器內秘密檔讀取）
mysql_as() {
  timeout 120 docker exec -i "$mysql_cid" sh -c 'MYSQL_PWD="$(cat "/run/secrets/$1")" exec mysql -h 127.0.0.1 -N -B -u "$2" "$3" -e "$4"' \
    sh "$1" "$2" "$3" "$4"
}

# dump_db <秘密檔名> <使用者> <資料庫> <輸出檔> [資料表...]
dump_db() {
  local secret="$1" user="$2" db="$3" out="$4"; shift 4
  timeout "${MD_DUMP_TIMEOUT:-1800}" docker exec -i "$mysql_cid" sh -c \
    'secret="$1"; shift; MYSQL_PWD="$(cat "/run/secrets/$secret")" exec mysqldump --single-transaction --routines --triggers --no-tablespaces --hex-blob --set-gtid-purged=OFF --default-character-set=utf8mb4 -h 127.0.0.1 "$@"' \
    sh "$secret" -u "$user" "$db" "$@" > "$out" || return 1
  [[ -s "$out" ]] && tail -n 1 "$out" | grep -q '^-- Dump completed'
}

declare -A db_stats=()
collect_stats() {
  local secret="$1" user="$2" db="$3"
  db_stats[$db]="$(mysql_as "$secret" "$user" "$db" "SELECT COUNT(*), COALESCE(SUM(TABLE_ROWS),0) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'" | tr '\t' ' ')" \
    || fail "無法讀取 ${db} 的資料表統計"
}

deletion_log="absent"
wp_counts_json=""
wp_status_counts() {
  mysql_as wp_db_password "$WP_USER" "$WP_DB" "SELECT post_status, COUNT(*) FROM wp_posts GROUP BY post_status ORDER BY post_status" 2>/dev/null \
    | python3 -I -c 'import json,sys; print(json.dumps({l.split("\t")[0]: int(l.split("\t")[1]) for l in sys.stdin if "\t" in l}, sort_keys=True))'
}
if [[ "$scope" == all || "$scope" == wp ]]; then
  # 備份前後各取一次文章狀態筆數；一致才寫入 manifest，供還原演練比對（不含內容）
  before="$(wp_status_counts || true)"
  dump_db wp_db_password "$WP_USER" "$WP_DB" "$run_dir/db/wp.sql" || fail "WordPress 資料庫 dump 失敗或不完整"
  after="$(wp_status_counts || true)"
  [[ -n "$before" && "$before" == "$after" ]] && wp_counts_json="$before"
  collect_stats wp_db_password "$WP_USER" "$WP_DB"
  wp_cid="$(md_container wp-php)"
  [[ -n "$wp_cid" ]] || fail "找不到執行中的 wp-php 容器，無法打包 uploads"
  timeout "${MD_DUMP_TIMEOUT:-1800}" docker exec "$wp_cid" tar -C /var/www/wp/wp-content/uploads -cf - . > "$run_dir/uploads.tar" \
    || fail "WordPress uploads 打包失敗"
  tar -tf "$run_dir/uploads.tar" >/dev/null 2>&1 || fail "uploads.tar 無法讀回"
fi
if [[ "$scope" == all || "$scope" == app ]]; then
  dump_db app_db_password "$APP_USER" "$APP_DB" "$run_dir/db/app.sql" || fail "Laravel 資料庫 dump 失敗或不完整"
  collect_stats app_db_password "$APP_USER" "$APP_DB"
  if [[ "$(mysql_as app_db_password "$APP_USER" "$APP_DB" "SHOW TABLES LIKE 'data_deletion_log'")" == data_deletion_log ]]; then
    # 刪除紀錄另存一份：還原較舊的備份時，以最新這份重套（DAT-06）
    dump_db app_db_password "$APP_USER" "$APP_DB" "$run_dir/db/deletion-log.sql" data_deletion_log || fail "刪除紀錄 dump 失敗"
    deletion_log="present"
  fi
fi
# 部署狀態（非秘密）：發布狀態、歷史、Compose 插值檔、主機設定、信任公鑰
for f in release-state.json history.jsonl compose.env backups.jsonl; do
  [[ -f "$MD_STATE_DIR/$f" ]] && cp -p "$MD_STATE_DIR/$f" "$run_dir/deploy/"
done
[[ -f "$MD_CONFIG_DIR/deploy.env" ]] && cp -p "$MD_CONFIG_DIR/deploy.env" "$run_dir/deploy/"
[[ -d "$MD_TRUST_DIR" ]] && cp -rp "$MD_TRUST_DIR" "$run_dir/deploy/trusted-keys"

active_release="$(md_state_get active.release_id 2>/dev/null || true)"
python3 -I - "$run_dir" "$env_name" "$scope" "$reason" "$started_at" "${active_release:-}" "$deletion_log" \
  "${db_stats[$WP_DB]:-}" "${db_stats[$APP_DB]:-}" "$wp_counts_json" <<'PY' || fail "無法產生 backup-manifest.json"
import hashlib, json, os, sys
run, env, scope, reason, started, release, dlog, wp_stats, app_stats, wp_counts = sys.argv[1:11]
files = []
for base, _dirs, names in os.walk(run):
    for n in sorted(names):
        p = os.path.join(base, n)
        rel = os.path.relpath(p, run)
        h = hashlib.sha256()
        with open(p, "rb") as fh:
            for chunk in iter(lambda: fh.read(1 << 20), b""):
                h.update(chunk)
        files.append({"path": rel, "bytes": os.path.getsize(p), "sha256": h.hexdigest()})
def stats(s):
    if not s.strip():
        return None
    t, r = s.split()
    return {"tables": int(t), "rows_estimate": int(r)}
dbs = {k: v for k, v in (("mysite_wp", stats(wp_stats)), ("mysite_app", stats(app_stats))) if v}
manifest = {"schema_version": 1, "environment": env, "scope": scope, "reason": reason, "created_at": started,
            "active_release": release or None, "deletion_log": dlog, "databases": dbs, "files": sorted(files, key=lambda f: f["path"]),
            "content_counts": {"wp_posts_by_status": json.loads(wp_counts) if wp_counts else None}}
with open(os.path.join(run, "backup-manifest.json"), "w", encoding="utf-8") as fh:
    json.dump(manifest, fh, ensure_ascii=False, indent=2, sort_keys=True)
PY

# ---------------------------------------------------------------------------
# restic：加密 repository；完成＋檢查通過才記成功
# ---------------------------------------------------------------------------
mkdir -p "$MD_BACKUP_STAGING/.restic-cache"
md_restic backup --host "mysite-${env_name}" --tag "env:${env_name}" --tag "scope:${scope}" --tag "reason:${reason}" \
  --json "$run_dir" > "$run_dir.restic-out.json" 2>"$run_dir.restic-err.log" || {
  ms_redact < "$run_dir.restic-err.log" | tail -n 5 >&2; rm -f "$run_dir.restic-out.json" "$run_dir.restic-err.log"
  fail "restic backup 失敗（目的地、憑證或網路）"; }
snapshot_id="$(python3 -I -c '
import json,sys
sid=None
for line in open(sys.argv[1],encoding="utf-8"):
    line=line.strip()
    if not line.startswith("{"): continue
    try: d=json.loads(line)
    except ValueError: continue
    if d.get("message_type")=="summary": sid=d.get("snapshot_id")
print(sid or "")' "$run_dir.restic-out.json")"
rm -f "$run_dir.restic-out.json" "$run_dir.restic-err.log"
[[ "$snapshot_id" =~ ^[0-9a-f]{64}$ ]] || fail "restic 未回報 snapshot ID"

check_args=(check)
[[ "$verify" == deep ]] && check_args+=(--read-data-subset=10%)   # 暫定：每週抽樣 10% 資料
md_restic "${check_args[@]}" >/dev/null 2>&1 || fail "restic check 失敗（snapshot ${snapshot_id:0:8} 不記為成功）"
md_restic dump "$snapshot_id" "$run_dir/backup-manifest.json" > "$run_dir.readback" 2>/dev/null \
  && cmp -s "$run_dir.readback" "$run_dir/backup-manifest.json" || { rm -f "$run_dir.readback"; fail "抽回 backup-manifest.json 與本機不一致"; }
rm -f "$run_dir.readback"

finished_at="$(md_now)"
python3 -I - "$run_dir/backup-manifest.json" "$snapshot_id" "$finished_at" "$verify" "$MD_STATE_DIR" <<'PY' || fail "無法寫入成功紀錄"
import json, os, sys
m = json.load(open(sys.argv[1], encoding="utf-8"))
rec = {"snapshot_id": sys.argv[2], "environment": m["environment"], "scope": m["scope"], "reason": m["reason"],
       "started_at": m["created_at"], "finished_at": sys.argv[3], "verify": sys.argv[4],
       "bytes": sum(f["bytes"] for f in m["files"]), "files": len(m["files"]), "databases": m["databases"],
       "deletion_log": m["deletion_log"], "active_release": m["active_release"], "result": "SUCCESS"}
state = sys.argv[5]
with open(os.path.join(state, "backups.jsonl"), "a", encoding="utf-8") as fh:
    fh.write(json.dumps(rec, ensure_ascii=False, sort_keys=True) + "\n")
tmp = os.path.join(state, f".last-backup-{m['scope']}.json.tmp")
with open(tmp, "w", encoding="utf-8") as fh:
    json.dump(rec, fh, ensure_ascii=False, indent=2, sort_keys=True)
os.replace(tmp, os.path.join(state, f"last-backup-{m['scope']}.json"))
print(json.dumps({k: rec[k] for k in ("snapshot_id", "scope", "finished_at", "bytes", "files", "databases")}, ensure_ascii=False, sort_keys=True))
PY
md_event INFO backup_success "snapshot ${snapshot_id:0:8}（scope=${scope}，reason=${reason}）已完成並通過 ${verify} 檢查"

# ---------------------------------------------------------------------------
# 保留策略：只在成功後執行；只限本環境 host＋scope；預設只預覽（BACKUP_RETENTION_MODE=apply 才真正清理）
# ---------------------------------------------------------------------------
case "$scope" in
  app) keep=(--keep-hourly "${MD_CFG[BACKUP_KEEP_HOURLY]}" --keep-daily "${MD_CFG[BACKUP_KEEP_DAILY]}") ;;
  wp) keep=(--keep-daily "${MD_CFG[BACKUP_KEEP_DAILY]}" --keep-monthly "${MD_CFG[BACKUP_KEEP_MONTHLY]}") ;;
  all) keep=(--keep-daily "${MD_CFG[BACKUP_KEEP_DAILY]}") ;;
esac
forget=(forget --host "mysite-${env_name}" --tag "scope:${scope}" --group-by host,paths "${keep[@]}")
if [[ "${MD_CFG[BACKUP_RETENTION_MODE]}" == apply ]]; then
  md_restic "${forget[@]}" --prune >/dev/null 2>&1 || md_event WARN retention_failed "保留策略清理失敗（備份本身已成功）"
else
  md_restic "${forget[@]}" --dry-run >/dev/null 2>&1 || md_event WARN retention_preview_failed "保留策略預覽失敗（備份本身已成功）"
fi
exit "$EXIT_OK"
