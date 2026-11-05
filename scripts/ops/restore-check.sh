#!/usr/bin/env bash
# 隔離還原演練（v5 §15.4，OPS-07／DAT-06）：永遠還原到「全新、隨機命名」的 restorecheck project，
# 拒絕任何 staging／production 目標。關閉外部連線（MySQL 不接任何外部網路）、量測 RTO／RPO，
# 結束後只清理本次建立的容器、volume、網路與暫存（以 Compose project label 精確篩選）。
set -Eeuo pipefail
umask 077

usage() {
  cat <<'EOF'
用法：scripts/ops/restore-check.sh --env=staging|production --snapshot=<ID|latest> --target=isolated [--level=db|full] [--help]

  --env=<環境>        讀取哪個環境的 restic repository（只讀；不會寫入該環境任何 project）
  --snapshot=latest   取 WP（scope wp／all）與 Laravel（scope app／all）各自最新的 snapshot
  --snapshot=<ID>     只還原指定 snapshot（8 或 64 位 hex）
  --target=isolated   唯一允許值；其他任何值（含 staging、production）一律拒絕（exit 40）
  --level=db          完整性＋隔離 MySQL 匯入＋SQL 檢查（文章／草稿／媒體引用／訂單／庫存／刪除紀錄）
  --level=full        另以還原當時的 Laravel 映像在無外網的內部網路重套刪除紀錄（mysite:deletion-log:reapply）

結果：environments/<env>/state/restore-checks.jsonl（只含筆數、時間、snapshot ID 與判定，不含資料內容）
exit code：0 PASS；10 參數錯；20 缺 restic／目的地；30 資料檢查失敗；40 非隔離目標；50 還原未完成（完整性或匯入失敗）。
EOF
}

env_name=""; snapshot=""; target=""; level="db"
for arg in "$@"; do
  case "$arg" in
    --env=*) env_name="${arg#--env=}" ;;
    --snapshot=*) snapshot="${arg#--snapshot=}" ;;
    --target=*) target="${arg#--target=}" ;;
    --level=*) level="${arg#--level=}" ;;
    -h|--help) usage; exit 0 ;;
    *) printf '未知參數：%s\n' "$arg" >&2; usage >&2; exit 10 ;;
  esac
done

# shellcheck source=../lib/deploy.sh
source "$(dirname "${BASH_SOURCE[0]}")/../lib/deploy.sh"
MD_SCRIPT_NAME=restore-check
[[ -n "$target" ]] || { usage >&2; exit "$EXIT_USAGE"; }
[[ "$target" == isolated ]] || ms_die "$EXIT_FORBIDDEN" "拒絕：--target 只能是 isolated（不得把 staging／production 或任何既有環境當還原目標）"
[[ -n "$env_name" && -n "$snapshot" ]] || { usage >&2; exit "$EXIT_USAGE"; }
ms_require_env_enum "$env_name" staging production
[[ "$snapshot" =~ $MD_RE_SNAPSHOT ]] || ms_die "$EXIT_USAGE" "--snapshot 必須是 latest 或 8／64 位 hex"
case "$level" in db|full) ;; *) ms_die "$EXIT_USAGE" "--level 只允許 db／full" ;; esac
md_require_cmd python3 docker tar sha256sum
md_resolve_host_root
md_env_paths "$env_name"
md_load_config "$env_name"
md_restic_ready || ms_die "$EXIT_EXTERNAL" "BLOCKED_EXTERNAL：${MD_RESTIC_MISSING}"
md_docker_ok || ms_die "$EXIT_EXTERNAL" "Docker daemon 不可用"

# ---------------------------------------------------------------------------
# 隔離 project：<前綴>-restorecheck-<8 位隨機>；前綴預設 mysite，測試只允許 mysite-deploytest-*
# ---------------------------------------------------------------------------
prefix="${MYSITE_RESTORECHECK_PREFIX:-mysite}"
[[ "$prefix" == mysite || "$prefix" =~ $MD_RE_TEST_PROJECT ]] || ms_die "$EXIT_FORBIDDEN" "MYSITE_RESTORECHECK_PREFIX 只允許 mysite-deploytest-*"
project="${prefix}-restorecheck-$(openssl rand -hex 4)"
[[ "$project" =~ ^mysite(-deploytest-[a-z0-9]{4,16})?-restorecheck-[0-9a-f]{8}$ ]] || ms_die "$EXIT_FORBIDDEN" "隔離 project 名稱異常"
case "$project" in mysite-staging*|mysite-production*|mysite-dev*) ms_die "$EXIT_FORBIDDEN" "隔離 project 名稱與既有環境衝突" ;; esac
if [[ -n "$(docker ps -aq --filter "label=com.docker.compose.project=$project")" || -n "$(docker volume ls -q --filter "label=com.docker.compose.project=$project")" ]]; then
  ms_die "$EXIT_FORBIDDEN" "隔離 project 已存在（隨機名稱碰撞），拒絕沿用"
fi
mkdir -p "$MD_BACKUP_STAGING"
work="$(mktemp -d "$MD_BACKUP_STAGING/restorecheck.XXXXXXXX")"
created_resources=0

cleanup() {
  local rc=$? ids=()
  set +e
  if (( created_resources )); then
    mapfile -t ids < <(docker ps -aq --filter "label=com.docker.compose.project=$project" 2>/dev/null)
    (( ${#ids[@]} )) && docker rm -f -v "${ids[@]}" >/dev/null 2>&1
    mapfile -t ids < <(docker volume ls -q --filter "label=com.docker.compose.project=$project" 2>/dev/null)
    (( ${#ids[@]} )) && docker volume rm "${ids[@]}" >/dev/null 2>&1
    mapfile -t ids < <(docker network ls -q --filter "label=com.docker.compose.project=$project" 2>/dev/null)
    (( ${#ids[@]} )) && docker network rm "${ids[@]}" >/dev/null 2>&1
    if [[ -n "$(docker ps -aq --filter "label=com.docker.compose.project=$project" 2>/dev/null)$(docker volume ls -q --filter "label=com.docker.compose.project=$project" 2>/dev/null)" ]]; then
      ms_err "隔離資源未完全清除：project ${project}（請以 label 精確清理）"
    fi
  fi
  [[ -n "${work:-}" && "$work" == "$MD_BACKUP_STAGING"/restorecheck.* ]] && rm -rf -- "$work"
  return "$rc"
}
trap cleanup EXIT

t_start="$(md_epoch)"; started_at="$(md_now)"
declare -A check=()
verdict_fail() { check[$1]="FAIL: $2"; ms_err "$1：$2"; }

# ---------------------------------------------------------------------------
# 1. 選定 snapshot（latest＝WP 與 Laravel 各取最新）
# ---------------------------------------------------------------------------
md_restic snapshots --host "mysite-${env_name}" --json > "$work/snapshots.json" 2>/dev/null \
  || ms_die "$EXIT_DEPLOY_INCOMPLETE" "無法讀取 restic snapshots（目的地、憑證或密碼）"
python3 -I - "$work/snapshots.json" "$snapshot" > "$work/plan.env" <<'PY' || ms_die "$EXIT_DEPLOY_INCOMPLETE" "找不到符合的 snapshot"
import json, sys
snaps = json.load(open(sys.argv[1], encoding="utf-8")) or []
want = sys.argv[2]
def scope(s):
    for t in s.get("tags") or []:
        if t.startswith("scope:"):
            return t[6:]
    return ""
snaps.sort(key=lambda s: s["time"])
def latest(scopes):
    c = [s for s in snaps if scope(s) in scopes]
    return c[-1] if c else None
if want == "latest":
    wp, app = latest(("wp", "all")), latest(("app", "all"))
    chosen = {s["id"]: s for s in (wp, app) if s}
else:
    m = [s for s in snaps if s["id"] == want or s.get("short_id") == want or s["id"].startswith(want)]
    if len(m) != 1:
        sys.exit(1)
    chosen = {m[0]["id"]: m[0]}
if not chosen:
    sys.exit(1)
dl = latest(("app", "all"))
i = 0
for sid, s in chosen.items():
    print(f"SNAP_{i}={sid}|{s['time'][:19]}Z|{scope(s)}|{s['paths'][0]}")
    i += 1
if dl:
    print(f"DELETION_LOG_SNAP={dl['id']}|{dl['time'][:19]}Z|{scope(dl)}|{dl['paths'][0]}")
PY

declare -a snaps=()
dl_snap=""
while IFS= read -r line; do
  [[ "$line" =~ ^(SNAP_[0-9]|DELETION_LOG_SNAP)=([0-9a-f]{64})\|([0-9T:-]{19}Z)\|(all|wp|app)\|(/[A-Za-z0-9._/-]+)$ ]] \
    || ms_die "$EXIT_FORBIDDEN" "snapshot 資訊格式異常"
  if [[ "${BASH_REMATCH[1]}" == DELETION_LOG_SNAP ]]; then dl_snap="${BASH_REMATCH[2]}|${BASH_REMATCH[5]}"
  else snaps+=("${BASH_REMATCH[2]}|${BASH_REMATCH[3]}|${BASH_REMATCH[4]}|${BASH_REMATCH[5]}"); fi
done < "$work/plan.env"
(( ${#snaps[@]} > 0 )) || ms_die "$EXIT_DEPLOY_INCOMPLETE" "沒有可還原的 snapshot"

# ---------------------------------------------------------------------------
# 2. 取回並驗證完整性（解密失敗、檔案缺損直接判失敗）
# ---------------------------------------------------------------------------
wp_sql=""; app_sql=""; uploads=""; oldest_snapshot_epoch=""; snap_report=""
restore_snap() {
  # restore_snap <id> <快照內路徑> <輸出目錄>
  local id="$1" path="$2" out="$3"
  mkdir -p "$out"
  md_restic restore "${id}:${path}" --target "$out" >/dev/null 2>&1 || return 1
  python3 -I - "$out" <<'PY'
import hashlib, json, os, sys
root = sys.argv[1]
m = json.load(open(os.path.join(root, "backup-manifest.json"), encoding="utf-8"))
for f in m["files"]:
    p = os.path.join(root, f["path"])
    if not os.path.isfile(p) or os.path.getsize(p) != f["bytes"]:
        sys.exit(f"缺檔或大小不符：{f['path']}")
    h = hashlib.sha256()
    with open(p, "rb") as fh:
        for chunk in iter(lambda: fh.read(1 << 20), b""):
            h.update(chunk)
    if h.hexdigest() != f["sha256"]:
        sys.exit(f"SHA-256 不符：{f['path']}")
PY
}
i=0
for entry in "${snaps[@]}"; do
  IFS='|' read -r sid stime sscope spath <<< "$entry"
  out="$work/snap-$i"
  restore_snap "$sid" "$spath" "$out" || { check[integrity]="FAIL: snapshot ${sid:0:8} 取回或完整性驗證失敗"; break; }
  [[ -f "$out/db/wp.sql" ]] && wp_sql="$out/db/wp.sql"
  [[ -f "$out/uploads.tar" ]] && uploads="$out/uploads.tar"
  [[ -f "$out/db/app.sql" ]] && app_sql="$out/db/app.sql"
  e="$(date -u -d "$stime" +%s)"
  [[ -z "$oldest_snapshot_epoch" || "$e" -lt "$oldest_snapshot_epoch" ]] && oldest_snapshot_epoch="$e"
  snap_report+="${sid:0:8}:${sscope}:${stime},"
  i=$((i + 1))
done
check[integrity]="${check[integrity]:-PASS}"
deletion_sql=""
if [[ "${check[integrity]}" == PASS && -n "$dl_snap" ]]; then
  IFS='|' read -r dl_id dl_path <<< "$dl_snap"
  if restore_snap "$dl_id" "$dl_path" "$work/deletion-log"; then
    [[ -f "$work/deletion-log/db/deletion-log.sql" ]] && deletion_sql="$work/deletion-log/db/deletion-log.sql"
  else
    check[integrity]="FAIL: 最新刪除紀錄 snapshot 取回失敗"
  fi
fi

report() {
  local verdict="$1" rc="$2" k t_end
  t_end="$(md_epoch)"
  : > "$work/checks.tsv"
  for k in "${!check[@]}"; do printf '%s\t%s\n' "$k" "${check[$k]//$'\t'/ }" >> "$work/checks.tsv"; done
  python3 -I - "$MD_STATE_DIR" "$env_name" "$project" "$level" "$started_at" "$(md_now)" "$((t_end - t_start))" \
    "${oldest_snapshot_epoch:-}" "$t_end" "${snap_report%,}" "$verdict" "$work/checks.tsv" <<'PY'
import json, os, sys
state, env, project, level, started, finished, rto, oldest, now, snaps, verdict, tsv = sys.argv[1:13]
checks = {}
with open(tsv, encoding="utf-8") as fh:
    for line in fh:
        k, _, v = line.rstrip("\n").partition("\t")
        if k:
            checks[k] = v
rec = {"environment": env, "isolated_project": project, "level": level, "started_at": started, "finished_at": finished,
       "rto_seconds": int(rto), "rpo_seconds": (int(now) - int(oldest)) if oldest else None,
       "snapshots": [s for s in snaps.split(",") if s], "checks": dict(sorted(checks.items())), "verdict": verdict}
os.makedirs(state, exist_ok=True)
with open(os.path.join(state, "restore-checks.jsonl"), "a", encoding="utf-8") as fh:
    fh.write(json.dumps(rec, ensure_ascii=False, sort_keys=True) + "\n")
print(json.dumps(rec, ensure_ascii=False, sort_keys=True))
PY
  md_event "$([[ "$verdict" == PASS ]] && echo INFO || echo ERROR)" restore_check "還原演練 ${verdict}（${project}，RTO $((t_end - t_start)) 秒）"
  exit "$rc"
}

[[ "${check[integrity]}" == PASS ]] || report INCOMPLETE "$EXIT_DEPLOY_INCOMPLETE"

# ---------------------------------------------------------------------------
# 3. 隔離 MySQL：全新 volume、隨機密碼、--network none（無任何外部連線）
# ---------------------------------------------------------------------------
mysql_image="${MD_CFG[BASE_IMAGE_REGISTRY]}/$(md_version_value MYSQL_IMAGE_REF)"
secrets="$work/secrets"
mkdir -m 0755 "$secrets"
for s in mysql_root_password wp_db_password app_db_password; do
  ( umask 022; md_rand_alnum 40 > "$secrets/$s" )
done
created_resources=1
docker volume create --label "com.docker.compose.project=$project" --label mysite.restorecheck=1 "${project}_mysql-data" >/dev/null
cid="$(docker run -d --name "${project}-mysql" --network none \
  --label "com.docker.compose.project=$project" --label com.docker.compose.service=mysql --label mysite.restorecheck=1 \
  -v "${project}_mysql-data:/var/lib/mysql" -v "$secrets:/run/secrets:ro" \
  -v "$MD_PLATFORM_DIR/infra/mysql/init:/docker-entrypoint-initdb.d:ro" \
  -e MYSQL_ROOT_PASSWORD_FILE=/run/secrets/mysql_root_password \
  -e MYSITE_WP_DB_NAME=mysite_wp -e MYSITE_WP_DB_USER=mysite_wp -e MYSITE_APP_DB_NAME=mysite_app -e MYSITE_APP_DB_USER=mysite_app \
  "$mysql_image" --character-set-server=utf8mb4 --collation-server=utf8mb4_0900_ai_ci --default-time-zone=+00:00 --skip-name-resolve)" \
  || { check[mysql]="FAIL: 無法啟動隔離 MySQL"; report INCOMPLETE "$EXIT_DEPLOY_INCOMPLETE"; }

q() { # q <秘密> <使用者> <庫> <SQL>
  timeout 120 docker exec -i "$cid" sh -c 'MYSQL_PWD="$(cat "/run/secrets/$1")" exec mysql -h 127.0.0.1 -N -B -u "$2" "$3" -e "$4"' sh "$@"
}
import_sql() { # import_sql <秘密> <使用者> <庫> <檔案>
  timeout "${MD_IMPORT_TIMEOUT:-1800}" docker exec -i "$cid" sh -c 'MYSQL_PWD="$(cat "/run/secrets/$1")" exec mysql -h 127.0.0.1 -u "$2" "$3"' sh "$1" "$2" "$3" < "$4"
}
ms_wait_until "${MD_MYSQL_READY_TIMEOUT:-240}" 3 q app_db_password mysite_app mysite_app "SELECT 1" \
  || { check[mysql]="FAIL: 隔離 MySQL 未在時限內就緒"; report INCOMPLETE "$EXIT_DEPLOY_INCOMPLETE"; }
check[mysql]=PASS

if [[ -n "$wp_sql" ]]; then
  import_sql wp_db_password mysite_wp mysite_wp "$wp_sql" >/dev/null || { check[import_wp]="FAIL: WordPress dump 匯入失敗"; report INCOMPLETE "$EXIT_DEPLOY_INCOMPLETE"; }
  check[import_wp]=PASS
fi
if [[ -n "$app_sql" ]]; then
  import_sql app_db_password mysite_app mysite_app "$app_sql" >/dev/null || { check[import_app]="FAIL: Laravel dump 匯入失敗"; report INCOMPLETE "$EXIT_DEPLOY_INCOMPLETE"; }
  check[import_app]=PASS
  if [[ -n "$deletion_sql" ]]; then
    # 以最新的刪除紀錄覆蓋（紀錄只增不減，最新份是舊份的超集合）
    import_sql app_db_password mysite_app mysite_app "$deletion_sql" >/dev/null || { check[deletion_log]="FAIL: 最新刪除紀錄匯入失敗"; report INCOMPLETE "$EXIT_DEPLOY_INCOMPLETE"; }
    check[deletion_log]="PASS: 已載入最新刪除紀錄（$(q app_db_password mysite_app mysite_app 'SELECT COUNT(*) FROM data_deletion_log') 筆）"
  else
    check[deletion_log]="ABSENT: 備份中沒有 data_deletion_log"
  fi
fi

# ---------------------------------------------------------------------------
# 4. 資料檢查（只輸出筆數）
# ---------------------------------------------------------------------------
failed=0
if [[ -n "$wp_sql" ]]; then
  published="$(q wp_db_password mysite_wp mysite_wp "SELECT COUNT(*) FROM wp_posts WHERE post_status='publish' AND post_type NOT IN ('attachment','revision','nav_menu_item')")"
  drafts="$(q wp_db_password mysite_wp mysite_wp "SELECT COUNT(*) FROM wp_posts WHERE post_status IN ('draft','pending','private','future')")"
  check[wp_posts]="PASS: 已發布 ${published}、未公開 ${drafts}"
  # 草稿不可見（資料層）：各狀態筆數必須與備份當下（前後一致時才記錄）的筆數相同，未公開文章不能變成公開
  q wp_db_password mysite_wp mysite_wp "SELECT post_status, COUNT(*) FROM wp_posts GROUP BY post_status ORDER BY post_status" > "$work/wp-status.tsv"
  wp_manifest="$(dirname "$(dirname "$wp_sql")")/backup-manifest.json"
  cmp_out="$(python3 -I - "$wp_manifest" "$work/wp-status.tsv" <<'PY'
import json, sys
m = json.load(open(sys.argv[1], encoding="utf-8"))
expected = (m.get("content_counts") or {}).get("wp_posts_by_status")
actual = {}
for line in open(sys.argv[2], encoding="utf-8"):
    st, _, n = line.rstrip("\n").partition("\t")
    if st:
        actual[st] = int(n)
if expected is None:
    print("UNVERIFIED: 備份當下筆數不穩定或未記錄，只能確認狀態欄位存在")
elif expected == actual:
    print("PASS: 各狀態筆數與備份當下一致（未公開文章仍未公開）")
else:
    diff = sorted(set(expected) | set(actual))
    print("FAIL: 狀態筆數不一致：" + ", ".join(f"{k} {expected.get(k,0)}→{actual.get(k,0)}" for k in diff if expected.get(k,0) != actual.get(k,0)))
PY
)"
  check[drafts_hidden]="$cmp_out"
  [[ "$cmp_out" == FAIL* ]] && failed=1
  if [[ -n "$uploads" ]]; then
    tar -tf "$uploads" | sed -e 's#^\./##' | sort -u > "$work/uploads.list"
    q wp_db_password mysite_wp mysite_wp "SELECT meta_value FROM wp_postmeta WHERE meta_key='_wp_attached_file'" | sort -u > "$work/attached.list"
    missing="$(comm -23 "$work/attached.list" "$work/uploads.list" | wc -l | tr -d ' ')"
    total="$(wc -l < "$work/attached.list" | tr -d ' ')"
    if [[ "$missing" == 0 ]]; then check[media]="PASS: ${total} 個媒體引用皆在 uploads 中"
    else verdict_fail media "${missing}／${total} 個媒體引用在 uploads 中找不到"; failed=1; fi
  else
    check[media]="SKIPPED: snapshot 不含 uploads"
  fi
fi
if [[ -n "$app_sql" ]]; then
  migrations="$(q app_db_password mysite_app mysite_app "SELECT COUNT(*) FROM migrations" 2>/dev/null)" \
    || { verdict_fail app_schema "Laravel migrations 表不可讀"; failed=1; }
  check[app_schema]="${check[app_schema]:-PASS: ${migrations} 筆 migration 紀錄}"
  for t in orders order_items products product_variants inventory_mutations; do
    if [[ "$(q app_db_password mysite_app mysite_app "SHOW TABLES LIKE '${t}'")" == "$t" ]]; then
      n="$(q app_db_password mysite_app mysite_app "SELECT COUNT(*) FROM \`${t}\`")" || { verdict_fail "table_${t}" "無法讀取"; failed=1; continue; }
      check["table_${t}"]="PASS: ${n} 筆可讀"
    else
      check["table_${t}"]="ABSENT: 此版本尚無此表"
    fi
  done
fi

# ---------------------------------------------------------------------------
# 5. full：以還原當時的 Laravel 映像在無外網內部網路重套刪除紀錄（DAT-06）
# ---------------------------------------------------------------------------
if [[ "$level" == full ]]; then
  laravel_image="$(python3 -I -c '
import json,sys,glob
for p in glob.glob(sys.argv[1]+"/snap-*/deploy/release-state.json"):
    a=(json.load(open(p)).get("active") or {})
    if a.get("images",{}).get("laravel"): print(a["images"]["laravel"]); break' "$work" 2>/dev/null || true)"
  if [[ ! "$laravel_image" =~ ^ghcr\.io/[a-z0-9._/-]+@sha256:[0-9a-f]{64}$ || -z "$app_sql" ]]; then
    check[deletion_reapply]="FAIL: snapshot 缺少 Laravel 映像紀錄或資料，無法重套刪除紀錄"; failed=1
  else
    net="${project}_db"
    docker network create --internal --label "com.docker.compose.project=$project" "$net" >/dev/null
    docker network connect "$net" "$cid"
    app_secrets="$work/app-secrets"; mkdir -m 0755 "$app_secrets"
    cp "$secrets/app_db_password" "$app_secrets/app_db_password"
    # APP_KEY 須與原環境相同才能讀加密欄位；其餘整合秘密一律以隨機值替代（內部網路無外連）
    if md_secret_ok app_key; then install -m 0644 "$MD_SECRETS_DIR/app_key" "$app_secrets/app_key"
    else printf 'base64:%s\n' "$(openssl rand -base64 32)" > "$app_secrets/app_key"; fi
    for s in content_event_hmac_key preview_signing_key diagnostics_token deploy_status_token wp_service_app_password; do
      ( umask 022; md_rand_alnum 48 > "$app_secrets/$s" )
    done
    cp "$MD_SECRETS_DIR/internal_ca_cert" "$app_secrets/internal_ca_cert" 2>/dev/null || ( umask 022; : > "$app_secrets/internal_ca_cert" )
    chmod 0644 "$app_secrets"/*
    if timeout 600 docker run --rm --network "$net" --label "com.docker.compose.project=$project" \
        -v "$app_secrets:/run/secrets:ro" -e APP_ENV=testing -e MYSITE_ROLE=cli -e MAIL_MAILER=log \
        -e DB_CONNECTION=mysql -e DB_HOST="${project}-mysql" -e DB_PORT=3306 -e DB_DATABASE=mysite_app -e DB_USERNAME=mysite_app \
        -e DB_PASSWORD_FILE=/run/secrets/app_db_password -e APP_KEY_FILE=/run/secrets/app_key \
        -e CONTENT_EVENT_HMAC_KEY_FILE=/run/secrets/content_event_hmac_key -e PREVIEW_SIGNING_KEY_FILE=/run/secrets/preview_signing_key \
        -e DIAGNOSTICS_TOKEN_FILE=/run/secrets/diagnostics_token -e DEPLOY_STATUS_TOKEN_FILE=/run/secrets/deploy_status_token \
        -e CONTENT_SERVICE_APP_PASSWORD_FILE=/run/secrets/wp_service_app_password -e CONTENT_PRIVATE_API_CA_FILE=/run/secrets/internal_ca_cert \
        -e APP_URL=http://restorecheck.invalid -e WP_PUBLIC_URL=http://cms.restorecheck.invalid -e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync \
        "$laravel_image" php artisan mysite:deletion-log:reapply --no-interaction > "$work/reapply.out" 2>&1; then
      check[deletion_reapply]="PASS: $(tail -n 1 "$work/reapply.out" | ms_redact | cut -c1-200)"
    else
      check[deletion_reapply]="FAIL: mysite:deletion-log:reapply 執行失敗（映像未含此指令或設定不足）"; failed=1
    fi
  fi
else
  check[deletion_reapply]="NOT_RUN: db 層級不啟動 Laravel；DAT-06 重套需 --level=full"
fi

if (( failed )); then report FAIL "$EXIT_TEST_FAIL"; fi
report PASS "$EXIT_OK"
