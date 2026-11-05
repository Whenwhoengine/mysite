#!/usr/bin/env bash
# INF-06：服務重建後內容、媒體、Session 必要狀態按契約保存（testing 環境實測）。
#   1. 建立狀態：WordPress 草稿文章、媒體（PNG）、Laravel Session（/admin/login 的 CSRF token）
#   2. 重建：docker compose up -d --force-recreate --wait（不 down、不刪 volume、不 prune）
#   3. 驗證：草稿仍為 draft 且內容 hash 相同；媒體經 gateway 仍 200 且 hash 相同；同一 cookie jar 取回相同 CSRF token
#   4. 收尾：不刪除任何文章、媒體或資料（測試內容以草稿留在 testing 環境）
# 結果：${MYSITE_RESULTS_DIR:-.runtime/results}/governance.jsonl（附加）；摘要：.runtime/evidence/persistence-testing.json
set -Euo pipefail

usage() {
  cat <<'EOF'
用法：tests/governance/persistence.sh [--help]

對 testing 環境（Compose 專案 mysite-test）驗收 INF-06「服務重建後內容、媒體、Session 必要狀態按契約保存」：
  - 以 wp-cli 建立草稿文章與匯入 PNG 媒體，以 curl 取得 Laravel Session 與 CSRF token
  - docker compose up -d --force-recreate --wait --wait-timeout 300 重建全部服務（不 down、不刪 volume）
  - 驗證草稿、媒體（磁碟與 HTTP）、Session（CSRF token 相同）都保留
測試內容不刪除（草稿不公開）；環境固定為 testing，不接受其他環境。

輸出：
  ${MYSITE_RESULTS_DIR:-.runtime/results}/governance.jsonl   每項檢查一行（suite=governance，ids=["INF-06"]）
  .runtime/evidence/persistence-testing.json                 重建耗時、保留的 volume、各項比對結果（不含秘密）
exit code：0 全部通過；10 參數錯；20 testing 環境未啟動或缺前置；30 任一檢查失敗
EOF
}

for arg in "$@"; do
  case "$arg" in
    -h|--help) usage; exit 0 ;;
    *) printf '未知參數：%s\n' "$arg" >&2; usage >&2; exit 10 ;;
  esac
done

# shellcheck source=../../scripts/lib/common.sh
source "$(dirname "${BASH_SOURCE[0]}")/../../scripts/lib/common.sh"
# shellcheck source=../../scripts/lib/compose.sh
source "$(dirname "${BASH_SOURCE[0]}")/../../scripts/lib/compose.sh"

readonly ENV_NAME=testing
readonly ACCEPTANCE_ID=INF-06
readonly CMD_TIMEOUT=120          # 單一 wp-cli／exec 指令上限（秒）（暫定）
readonly HTTP_TIMEOUT=15          # 單一 HTTP 請求上限（秒）（暫定）
readonly RECREATE_WAIT=300        # compose --wait-timeout（秒）
readonly RECREATE_TIMEOUT=480     # 整個重建指令的外層上限（秒）（暫定）
readonly READY_TIMEOUT=120        # 重建後 HTTP 就緒的等待上限（秒）（暫定）
readonly LOGIN_PATH=/admin/login  # Fortify prefix=admin（app/config/fortify.php）；/login 在本站為 404

for c in docker jq curl sha256sum python3 timeout openssl; do
  command -v "$c" >/dev/null 2>&1 || ms_die "$EXIT_EXTERNAL" "缺少必要指令：$c"
done
root="$(ms_root)" || exit "$EXIT_EXTERNAL"
cd "$root"

results_dir="${MYSITE_RESULTS_DIR:-$root/.runtime/results}"
mkdir -p "$results_dir" "$root/.runtime/evidence" || ms_die "$EXIT_EXTERNAL" "無法建立結果目錄"
jsonl="$results_dir/governance.jsonl"
evidence="$root/.runtime/evidence/persistence-$ENV_NAME.json"

now() { date -u +%FT%TZ; }
fail=0
records=()
# record <test> <PASS|FAIL> <message> <started_at>：格式與 scripts/ci/verify.sh 的 record() 相同
record() {
  local t="$1" st="$2" msg started="$4" line
  msg="$(printf '%s' "$3" | ms_redact)"
  line="$(jq -cn --arg suite governance --arg t "$t" --arg ids "$ACCEPTANCE_ID" --arg st "$st" --arg m "$msg" --arg sa "$started" --arg fa "$(now)" \
    '{suite:$suite, test:$t, ids:($ids|split(",")|map(select(length>0))), status:$st, message:$m, started_at:$sa, finished_at:$fa}')"
  printf '%s\n' "$line" >> "$jsonl"
  records+=("$line")
  printf '  %-4s  %-34s %s\n' "$st" "$t" "$msg"
  [[ "$st" == PASS ]] || fail=1
}
# 前置不足：記一行 FAIL（不把未執行當通過），exit 20
prereq_fail() {
  record prerequisite-testing-env FAIL "$1" "$(now)"
  ms_die "$EXIT_EXTERNAL" "$1"
}

timeout 15 docker info >/dev/null 2>&1 || prereq_fail "Docker daemon 不可用"
env_file="$root/.runtime/env/$ENV_NAME.env"
[[ -f "$env_file" ]] || prereq_fail "缺少 .runtime/env/$ENV_NAME.env；請先執行 scripts/cloud/bootstrap.sh --env=$ENV_NAME"
ms_load_versions "$root"
ms_compose_prepare "$root" "$ENV_NAME"
project="$COMPOSE_PROJECT_NAME"
app_port="$(grep -E '^APP_PORT=' "$env_file" | tail -1 | cut -d= -f2)"
cms_port="$(grep -E '^CMS_PORT=' "$env_file" | tail -1 | cut -d= -f2)"
[[ "$app_port" =~ ^[0-9]+$ && "$cms_port" =~ ^[0-9]+$ ]] || prereq_fail ".runtime/env/$ENV_NAME.env 缺少 APP_PORT／CMS_PORT"
app_host="mysite.localhost"
app_base="http://${app_host}:${app_port}"
cms_base="http://127.0.0.1:${cms_port}"

readonly EXPECTED_SERVICES="mysql wp-php wp-jobs wp-web app-php app-worker app-scheduler app-web gateway"
not_running=""
ps_json="$(timeout 60 docker compose "${MS_COMPOSE_ARGS[@]}" ps --format json < /dev/null 2>/dev/null || true)"
for s in $EXPECTED_SERVICES; do
  printf '%s\n' "$ps_json" | jq -e --arg s "$s" 'select(.Service==$s and .State=="running")' >/dev/null 2>&1 || not_running+=" $s"
done
[[ -z "$not_running" ]] || prereq_fail "testing 環境未啟動或服務未執行：${not_running# }；請先執行 scripts/cloud/bootstrap.sh --env=$ENV_NAME"

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
jar="$tmp/cookies.txt"

# 不需要 stdin 的指令一律接 /dev/null（timeout 會把子程序移出前景，讀終端機會被停住）
dc() { timeout "$CMD_TIMEOUT" docker compose "${MS_COMPOSE_ARGS[@]}" "$@" < /dev/null; }
dc_stdin() { timeout "$CMD_TIMEOUT" docker compose "${MS_COMPOSE_ARGS[@]}" "$@"; }
wpcli() { dc exec -T -u www-data wp-php wp "$@"; }
errtail() { tail -3 "$1" 2>/dev/null | tr '\n' ' ' | ms_redact | cut -c1-300; }
sha_of() { sha256sum "$1" | cut -d' ' -f1; }

# 取 CSRF token（只存在記憶體，不輸出）：<body 檔>
csrf_from() { grep -oE 'name="_token" value="[^"]+"' "$1" | head -1 | sed -E 's/.*value="([^"]+)"/\1/'; }

# GET /admin/login（同一 cookie jar）：設定 login_code、login_token、login_cookie_set
login_get() {
  local tag="$1" hdr="$tmp/login-$1.headers" body="$tmp/login-$1.body"
  login_code="$(curl -sS --max-time "$HTTP_TIMEOUT" --resolve "${app_host}:${app_port}:127.0.0.1" \
    -c "$jar" -b "$jar" -D "$hdr" -o "$body" -w '%{http_code}' "${app_base}${LOGIN_PATH}" 2>"$tmp/curl-$tag.err")" || login_code="000"
  login_token="$(csrf_from "$body" || true)"
  login_cookie_set=false
  grep -qiE "^set-cookie:[[:space:]]*${session_cookie}=" "$hdr" 2>/dev/null && login_cookie_set=true
}

# 媒體 HTTP 下載：設定 media_code、media_http_sha
media_get() {
  local out="$tmp/media-$1.bin"
  media_code="$(curl -sS --max-time "$HTTP_TIMEOUT" -o "$out" -w '%{http_code}' "${cms_base}/wp-content/uploads/${media_rel}" 2>"$tmp/media-$1.err")" || media_code="000"
  media_http_sha=""
  [[ -s "$out" ]] && media_http_sha="$(sha_of "$out")"
}

container_ids() { dc ps --format json 2>/dev/null | jq -s 'map({(.Service): .ID}) | add // {}'; }
volume_snapshot() {
  local v
  for v in $(timeout 60 docker volume ls -q --filter "label=com.docker.compose.project=${project}" | sort); do
    timeout 30 docker volume inspect --format '{{json .}}' "$v" | jq -c '{name:.Name, created_at:.CreatedAt, volume:(.Labels["com.docker.compose.volume"] // "")}'
  done | jq -s '.'
}

session_cookie="$(dc config --format json 2>/dev/null | jq -r '.services["app-php"].environment.SESSION_COOKIE // empty')"
[[ "$session_cookie" =~ ^[A-Za-z0-9_-]+$ ]] || session_cookie="mysite_app_session"

marker="$(openssl rand -hex 6)"
post_title="INF-06 persistence ${marker}"
post_content="<p>INF-06 持久化驗收草稿（${marker}）：服務重建後內容必須保留。</p>"
media_name="inf06-persistence-${marker}.png"
media_title="INF-06 persistence media ${marker}"

printf 'persistence（%s／%s）：標記 %s\n' "$ENV_NAME" "$project" "$marker"

# ---------------------------------------------------------------------------
# A. 建立狀態
# ---------------------------------------------------------------------------
post_id=""; content_sha_before=""; status_before=""
started="$(now)"
if post_id="$(wpcli post create --post_type=post --post_status=draft --post_title="$post_title" --post_content="$post_content" --porcelain 2>"$tmp/post.err" | tr -d '[:space:]')" \
   && [[ "$post_id" =~ ^[0-9]+$ ]]; then
  status_before="$(wpcli post get "$post_id" --field=post_status 2>>"$tmp/post.err" | tr -d '[:space:]')"
  wpcli post get "$post_id" --field=post_content > "$tmp/post-before.txt" 2>>"$tmp/post.err"
  content_sha_before="$(sha_of "$tmp/post-before.txt")"
  if [[ "$status_before" == draft && -s "$tmp/post-before.txt" ]] && grep -qF "$marker" "$tmp/post-before.txt"; then
    record state-create-draft-post PASS "草稿文章 ID=${post_id} 已建立（status=draft，內容 sha256=${content_sha_before:0:16}…）" "$started"
  else
    record state-create-draft-post FAIL "文章 ID=${post_id} 狀態=${status_before:-未知} 或內容讀回失敗：$(errtail "$tmp/post.err")" "$started"
  fi
else
  post_id=""
  record state-create-draft-post FAIL "wp post create 失敗：$(errtail "$tmp/post.err")" "$started"
fi

attachment_id=""; media_rel=""; media_src_sha=""; media_disk_sha_before=""; media_code_before=""; media_http_sha_before=""
started="$(now)"
python3 -I -c '
import os, struct, sys, zlib
w = h = 16
raw = b"".join(b"\x00" + os.urandom(w * 3) for _ in range(h))
def chunk(t, d):
    return struct.pack(">I", len(d)) + t + d + struct.pack(">I", zlib.crc32(t + d) & 0xFFFFFFFF)
png = (b"\x89PNG\r\n\x1a\n"
       + chunk(b"IHDR", struct.pack(">IIBBBBB", w, h, 8, 2, 0, 0, 0))
       + chunk(b"tEXt", b"Comment\x00" + sys.argv[2].encode())
       + chunk(b"IDAT", zlib.compress(raw, 9))
       + chunk(b"IEND", b""))
open(sys.argv[1], "wb").write(png)
' "$tmp/$media_name" "$media_title" 2>"$tmp/png.err"
if [[ -s "$tmp/$media_name" ]]; then
  media_src_sha="$(sha_of "$tmp/$media_name")"
  # 容器內暫存目錄由本腳本建立，匯入後只清掉該暫存目錄；uploads 內的媒體保留
  if attachment_id="$(dc_stdin exec -T -u www-data wp-php sh -c 'd="$(mktemp -d)" || exit 1; trap "rm -rf \"\$d\"" EXIT; cat > "$d/$1" && wp media import "$d/$1" --title="$2" --porcelain' \
        sh "$media_name" "$media_title" < "$tmp/$media_name" 2>"$tmp/media.err" | tr -d '[:space:]')" \
     && [[ "$attachment_id" =~ ^[0-9]+$ ]]; then
    media_rel="$(wpcli post meta get "$attachment_id" _wp_attached_file 2>>"$tmp/media.err" | tr -d '[:space:]')"
    if [[ "$media_rel" =~ ^[A-Za-z0-9/_.-]+$ && "$media_rel" != *..* ]]; then
      media_disk_sha_before="$(dc exec -T wp-php sha256sum "/var/www/wp/wp-content/uploads/${media_rel}" 2>>"$tmp/media.err" | cut -d' ' -f1)"
    fi
  else
    attachment_id=""
  fi
fi
if [[ -n "$attachment_id" && -n "$media_disk_sha_before" ]]; then
  record state-import-media PASS "媒體 attachment ID=${attachment_id}（uploads/${media_rel}，sha256=${media_disk_sha_before:0:16}…，與來源檔$([[ "$media_disk_sha_before" == "$media_src_sha" ]] && echo 相同 || echo 不同)）" "$started"
else
  record state-import-media FAIL "wp media import 或讀回失敗：$(errtail "$tmp/media.err") $(errtail "$tmp/png.err")" "$started"
fi

started="$(now)"
if [[ -n "$media_rel" ]]; then
  media_get before
  media_code_before="$media_code"; media_http_sha_before="$media_http_sha"
  if [[ "$media_code_before" == 200 && "$media_http_sha_before" == "$media_disk_sha_before" ]]; then
    record state-media-http PASS "經 gateway 下載 /wp-content/uploads/${media_rel} 回 200，sha256 與磁碟相同" "$started"
  else
    record state-media-http FAIL "經 gateway 下載回 ${media_code_before}，sha256 與磁碟$([[ "$media_http_sha_before" == "$media_disk_sha_before" ]] && echo 相同 || echo 不同)：$(errtail "$tmp/media-before.err")" "$started"
  fi
else
  record state-media-http FAIL "未執行：媒體匯入失敗" "$started"
fi

token_before=""; login_code_before=""; cookie_set_before=false
started="$(now)"
login_get before
login_code_before="$login_code"; token_before="$login_token"; cookie_set_before="$login_cookie_set"
jar_has_session=false
grep -qE "[[:space:]]${session_cookie}[[:space:]]" "$jar" 2>/dev/null && jar_has_session=true
if [[ "$login_code_before" == 200 && -n "$token_before" && "$cookie_set_before" == true && "$jar_has_session" == true ]]; then
  record state-session-start PASS "GET ${LOGIN_PATH} 回 200；Set-Cookie 含 ${session_cookie}；已取得 CSRF token（值不記錄）" "$started"
else
  record state-session-start FAIL "GET ${LOGIN_PATH} 回 ${login_code_before}；session cookie=${cookie_set_before}；cookie jar=${jar_has_session}；CSRF token $([[ -n "$token_before" ]] && echo 已取得 || echo 未取得)：$(errtail "$tmp/curl-before.err")" "$started"
fi

state_ok=1
[[ -n "$post_id" && -n "$content_sha_before" && -n "$media_disk_sha_before" && -n "$token_before" ]] || state_ok=0

# ---------------------------------------------------------------------------
# B. 重建（不 down、不刪 volume、不 prune）
# ---------------------------------------------------------------------------
recreate_rc=-1; recreate_ms=0; recreate_started=""; recreate_finished=""
ids_before='{}'; ids_after='{}'; vols_before='[]'; vols_after='[]'
recreated='[]'; not_recreated='[]'
started="$(now)"
if (( state_ok )); then
  ids_before="$(container_ids)"
  vols_before="$(volume_snapshot)"
  recreate_started="$(now)"
  t0="$(date +%s%N)"
  timeout "$RECREATE_TIMEOUT" docker compose "${MS_COMPOSE_ARGS[@]}" up -d --force-recreate --wait --wait-timeout "$RECREATE_WAIT" < /dev/null > "$tmp/recreate.log" 2>&1
  recreate_rc=$?
  recreate_ms=$(( ( $(date +%s%N) - t0 ) / 1000000 ))
  recreate_finished="$(now)"
  ids_after="$(container_ids)"
  vols_after="$(volume_snapshot)"
  recreated="$(jq -cn --argjson b "$ids_before" --argjson a "$ids_after" '[$b|keys[] as $k | select(($a[$k] // "") != "" and $a[$k] != $b[$k]) | $k]')"
  not_recreated="$(jq -cn --argjson b "$ids_before" --argjson a "$ids_after" '[$b|keys[] as $k | select(($a[$k] // "") == "" or $a[$k] == $b[$k]) | $k]')"
  if (( recreate_rc == 0 )) && [[ "$(jq 'length' <<< "$not_recreated")" == 0 && "$(jq 'length' <<< "$recreated")" -gt 0 ]]; then
    record rebuild-force-recreate PASS "up -d --force-recreate --wait 完成，耗時 $(awk -v m="$recreate_ms" 'BEGIN{printf "%.1f", m/1000}') 秒；重建容器 $(jq -r 'join(",")' <<< "$recreated")" "$started"
  else
    record rebuild-force-recreate FAIL "重建 exit ${recreate_rc}；未重建：$(jq -r 'join(",")' <<< "$not_recreated")；$(errtail "$tmp/recreate.log")" "$started"
  fi

  started="$(now)"
  vol_diff="$(jq -cn --argjson b "$vols_before" --argjson a "$vols_after" '{missing:[$b[] | select(. as $v | ($a | index($v)) == null) | .name], count_before:($b|length), count_after:($a|length)}')"
  if [[ "$(jq '.missing|length' <<< "$vol_diff")" == 0 && "$(jq '.count_before' <<< "$vol_diff")" -gt 0 && "$(jq '.count_before == .count_after' <<< "$vol_diff")" == true ]]; then
    record rebuild-volumes-preserved PASS "volume 名稱與建立時間不變：$(jq -r 'map(.name)|join(",")' <<< "$vols_after")" "$started"
  else
    record rebuild-volumes-preserved FAIL "volume 變動：$(jq -c . <<< "$vol_diff")" "$started"
  fi

  started="$(now)"
  ready_check() {
    curl -fsS -o /dev/null --max-time 5 "http://127.0.0.1:${app_port}/readyz" \
      && curl -fsS -o /dev/null --max-time 5 "${cms_base}/wp-json/site-content/v1/health"
  }
  if ms_wait_until "$READY_TIMEOUT" 2 ready_check; then
    record rebuild-services-ready PASS "重建後主站 /readyz 與 CMS health 皆 200" "$started"
  else
    record rebuild-services-ready FAIL "重建後 ${READY_TIMEOUT} 秒內主站 /readyz 或 CMS health 未回 200" "$started"
  fi
else
  record rebuild-force-recreate FAIL "未執行：建立狀態失敗，不進行重建" "$started"
fi

# ---------------------------------------------------------------------------
# C. 驗證
# ---------------------------------------------------------------------------
status_after=""; content_sha_after=""; title_after_same=false
media_disk_sha_after=""; media_code_after=""; media_http_sha_after=""; attached_after=""
token_same=false; login_code_after=""; control_code=""; control_differs=false

started="$(now)"
if (( state_ok )) && (( recreate_rc == 0 )); then
  status_after="$(wpcli post get "$post_id" --field=post_status 2>"$tmp/post-after.err" | tr -d '[:space:]')"
  wpcli post get "$post_id" --field=post_content > "$tmp/post-after.txt" 2>>"$tmp/post-after.err"
  content_sha_after="$(sha_of "$tmp/post-after.txt")"
  [[ "$(wpcli post get "$post_id" --field=post_title 2>>"$tmp/post-after.err")" == "$post_title" ]] && title_after_same=true
  if [[ "$status_after" == draft && "$content_sha_after" == "$content_sha_before" && "$title_after_same" == true ]]; then
    record verify-draft-post PASS "文章 ID=${post_id} 仍為 draft，標題相同，內容 sha256 與重建前相同" "$started"
  else
    record verify-draft-post FAIL "文章 ID=${post_id} 狀態=${status_after:-不存在}；內容 sha256 $([[ "$content_sha_after" == "$content_sha_before" ]] && echo 相同 || echo 不同)；標題相同=${title_after_same}：$(errtail "$tmp/post-after.err")" "$started"
  fi

  started="$(now)"
  attached_after="$(wpcli post meta get "$attachment_id" _wp_attached_file 2>"$tmp/media-after.err" | tr -d '[:space:]')"
  media_disk_sha_after="$(dc exec -T wp-php sha256sum "/var/www/wp/wp-content/uploads/${media_rel}" 2>>"$tmp/media-after.err" | cut -d' ' -f1)"
  media_get after
  media_code_after="$media_code"; media_http_sha_after="$media_http_sha"
  if [[ "$attached_after" == "$media_rel" && "$media_disk_sha_after" == "$media_disk_sha_before" && "$media_code_after" == 200 && "$media_http_sha_after" == "$media_disk_sha_before" ]]; then
    record verify-media PASS "attachment ID=${attachment_id} 仍在；uploads/${media_rel} 磁碟 sha256 相同；經 gateway 200 且 sha256 相同" "$started"
  else
    record verify-media FAIL "attachment 路徑$([[ "$attached_after" == "$media_rel" ]] && echo 相同 || echo 不同)；磁碟 sha256 $([[ "$media_disk_sha_after" == "$media_disk_sha_before" ]] && echo 相同 || echo 不同)；HTTP ${media_code_after}，sha256 $([[ "$media_http_sha_after" == "$media_disk_sha_before" ]] && echo 相同 || echo 不同)：$(errtail "$tmp/media-after.err")" "$started"
  fi

  started="$(now)"
  login_get after
  login_code_after="$login_code"
  [[ -n "$login_token" && "$login_token" == "$token_before" ]] && token_same=true
  login_token=""
  # 對照組：不帶 cookie 的新請求必須拿到不同 token，證明 token 綁定 Session 而非固定值
  control_body="$tmp/login-control.body"
  control_code="$(curl -sS --max-time "$HTTP_TIMEOUT" --resolve "${app_host}:${app_port}:127.0.0.1" -o "$control_body" -w '%{http_code}' \
    "${app_base}${LOGIN_PATH}" 2>"$tmp/curl-control.err")" || control_code="000"
  control_token="$(csrf_from "$control_body" || true)"
  [[ "$control_code" == 200 && -n "$control_token" && "$control_token" != "$token_before" ]] && control_differs=true
  control_token=""
  if [[ "$login_code_after" == 200 && "$token_same" == true && "$control_differs" == true ]]; then
    record verify-session PASS "同一 cookie jar 再 GET ${LOGIN_PATH} 回 200；CSRF token 與重建前相同（Session 由 DB 保存）；對照組新 Session 的 token 不同" "$started"
  else
    record verify-session FAIL "同一 cookie jar 再 GET ${LOGIN_PATH} 回 ${login_code_after}；CSRF token 與重建前$([[ "$token_same" == true ]] && echo 相同 || echo 不同或未取得)；對照組（無 cookie）回 ${control_code}，token 與原 Session $([[ "$control_differs" == true ]] && echo 不同 || echo 相同或未取得)：$(errtail "$tmp/curl-after.err")" "$started"
  fi
else
  for t in verify-draft-post verify-media verify-session; do
    record "$t" FAIL "未執行：建立狀態或重建失敗" "$started"
  done
fi
token_before=""

# ---------------------------------------------------------------------------
# D. 收尾：不刪除任何內容；測試文章維持草稿（本腳本不曾把任何內容設為公開）
# ---------------------------------------------------------------------------
if [[ -n "$post_id" ]]; then
  final_status="$(wpcli post get "$post_id" --field=post_status 2>/dev/null | tr -d '[:space:]')"
  if [[ -n "$final_status" && "$final_status" != draft ]]; then
    wpcli post update "$post_id" --post_status=draft --quiet >/dev/null 2>&1 || true
    ms_warn "測試文章 ID=${post_id} 狀態為 ${final_status}，已改回草稿"
  fi
fi

printf '%s\n' "${records[@]}" | jq -s \
  --arg env "$ENV_NAME" --arg project "$project" --arg commit "$MYSITE_SOURCE_COMMIT" --arg at "$(now)" --arg marker "$marker" \
  --argjson recreate_rc "$recreate_rc" --argjson recreate_ms "$recreate_ms" --arg rs "$recreate_started" --arg rf "$recreate_finished" \
  --argjson recreated "$recreated" --argjson not_recreated "$not_recreated" --argjson vols_before "$vols_before" --argjson vols_after "$vols_after" \
  --arg post_id "$post_id" --arg sb "$status_before" --arg sa "$status_after" --arg cb "$content_sha_before" --arg ca "$content_sha_after" --argjson title_same "$title_after_same" \
  --arg att "$attachment_id" --arg rel "$media_rel" --arg src "$media_src_sha" --arg db "$media_disk_sha_before" --arg da "$media_disk_sha_after" \
  --arg hcb "$media_code_before" --arg hca "$media_code_after" --arg hsb "$media_http_sha_before" --arg hsa "$media_http_sha_after" \
  --arg login_path "$LOGIN_PATH" --arg cookie "$session_cookie" --argjson cookie_before "$cookie_set_before" \
  --arg lcb "$login_code_before" --arg lca "$login_code_after" --argjson token_same "$token_same" \
  --arg ccode "$control_code" --argjson control_differs "$control_differs" \
  '{
    acceptance_id: "INF-06", environment: $env, project: $project, source_commit: $commit, checked_at: $at, marker: $marker,
    rebuild: {
      command: "docker compose up -d --force-recreate --wait --wait-timeout 300",
      forbidden_not_used: ["down", "down -v", "volume rm", "prune"],
      exit_code: $recreate_rc, duration_ms: $recreate_ms, duration_seconds: ($recreate_ms / 1000),
      started_at: $rs, finished_at: $rf, services_recreated: $recreated, services_not_recreated: $not_recreated
    },
    volumes: {
      before: $vols_before, after: $vols_after,
      preserved: [$vols_before[] | select(. as $v | ($vols_after | index($v)) != null) | .name]
    },
    content: {post_id: $post_id, post_type: "post", status_before: $sb, status_after: $sa,
              content_sha256_before: $cb, content_sha256_after: $ca, content_same: ($cb != "" and $cb == $ca), title_same: $title_same,
              left_in_place: "測試草稿保留於 testing 環境（未公開、未刪除）"},
    media: {attachment_id: $att, relative_path: $rel, source_sha256: $src, disk_sha256_before: $db, disk_sha256_after: $da,
            http_status_before: $hcb, http_status_after: $hca, http_sha256_same_before: ($hsb != "" and $hsb == $db),
            http_sha256_same_after: ($hsa != "" and $hsa == $db), disk_same: ($db != "" and $db == $da)},
    session: {path: $login_path, cookie_name: $cookie, driver: "database", set_cookie_before: $cookie_before,
              http_status_before: $lcb, http_status_after: $lca, csrf_token_same: $token_same,
              control_without_cookie: {http_status: $ccode, csrf_token_differs: $control_differs},
              note: "cookie 與 CSRF token 的值不記錄，只記相同／不同"},
    results: .
  }' > "$evidence"

if (( fail )); then
  ms_err "INF-06 persistence 有失敗項目；結果：$jsonl；摘要：$evidence"
  exit "$EXIT_TEST_FAIL"
fi
ms_info "INF-06 persistence 全部通過；結果：$jsonl；摘要：$evidence"
exit "$EXIT_OK"
