#!/usr/bin/env bash
# INF-08：映像及主機架構一致，儲存與記憶體用量有量測（testing 環境實測，只讀不改）。
#   - 主機：uname -m 與 docker info 架構一致
#   - 映像：testing 使用的每個映像（compose config --images，含 test profile 的 tools）與執行中容器的映像架構＝主機架構
#   - 量測：映像大小、執行中容器記憶體、testing 專案 volume 大小、Docker root 所在磁碟剩餘
# 結果：${MYSITE_RESULTS_DIR:-.runtime/results}/governance.jsonl（附加）；摘要：.runtime/evidence/resources-testing.json
set -Euo pipefail

usage() {
  cat <<'EOF'
用法：tests/governance/resources.sh [--help]

對 testing 環境（Compose 專案 mysite-test）驗收 INF-08「映像及主機架構一致，儲存與記憶體用量有量測」：
  - uname -m、docker info 架構與每個映像（compose config --images，含 test profile）及執行中容器映像的架構一致
    （x86_64↔amd64、aarch64↔arm64）
  - 量測映像大小、容器記憶體（docker stats --no-stream）、testing 專案 volume 大小（du；失敗時改用 docker system df -v）、
    Docker root 所在磁碟剩餘（df -P）
只讀取與量測，不建立、不刪除任何容器、映像、volume 或資料；環境固定為 testing。

輸出：
  ${MYSITE_RESULTS_DIR:-.runtime/results}/governance.jsonl   每項檢查一行（suite=governance，ids=["INF-08"]）
  .runtime/evidence/resources-testing.json                   架構與量測明細
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
readonly ACCEPTANCE_ID=INF-08
readonly CMD_TIMEOUT=60      # 一般 docker 查詢上限（秒）（暫定）
readonly DU_TIMEOUT=300      # 單一 volume du 上限（秒）（暫定）
readonly DF_TIMEOUT=300      # docker system df -v 後備量測上限（秒）（暫定）

for c in docker jq timeout df du uname awk; do
  command -v "$c" >/dev/null 2>&1 || ms_die "$EXIT_EXTERNAL" "缺少必要指令：$c"
done
root="$(ms_root)" || exit "$EXIT_EXTERNAL"
cd "$root"

results_dir="${MYSITE_RESULTS_DIR:-$root/.runtime/results}"
mkdir -p "$results_dir" "$root/.runtime/evidence" || ms_die "$EXIT_EXTERNAL" "無法建立結果目錄"
jsonl="$results_dir/governance.jsonl"
evidence="$root/.runtime/evidence/resources-$ENV_NAME.json"

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
  printf '  %-4s  %-30s %s\n' "$st" "$t" "$msg"
  [[ "$st" == PASS ]] || fail=1
}
# 前置不足：記一行 FAIL（不把未執行當通過），exit 20
prereq_fail() {
  record prerequisite-testing-env FAIL "$1" "$(now)"
  ms_die "$EXIT_EXTERNAL" "$1"
}

timeout 15 docker info >/dev/null 2>&1 || prereq_fail "Docker daemon 不可用"
[[ -f "$root/.runtime/env/$ENV_NAME.env" ]] || prereq_fail "缺少 .runtime/env/$ENV_NAME.env；請先執行 scripts/cloud/bootstrap.sh --env=$ENV_NAME"
ms_load_versions "$root"
ms_compose_prepare "$root" "$ENV_NAME"
project="$COMPOSE_PROJECT_NAME"

dc() { timeout "$CMD_TIMEOUT" docker compose "${MS_COMPOSE_ARGS[@]}" "$@" < /dev/null; }
dk() { timeout "$CMD_TIMEOUT" docker "$@" < /dev/null; }

readonly EXPECTED_SERVICES="mysql wp-php wp-jobs wp-web app-php app-worker app-scheduler app-web gateway"
ps_json="$(dc ps --format json 2>/dev/null || true)"
not_running=""
for s in $EXPECTED_SERVICES; do
  printf '%s\n' "$ps_json" | jq -e --arg s "$s" 'select(.Service==$s and .State=="running")' >/dev/null 2>&1 || not_running+=" $s"
done
[[ -z "$not_running" ]] || prereq_fail "testing 環境未啟動或服務未執行：${not_running# }；請先執行 scripts/cloud/bootstrap.sh --env=$ENV_NAME"

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

# Docker 架構名正規化：uname（x86_64、aarch64…）與 Docker／OCI（amd64、arm64…）對應到同一名稱
norm_arch() {
  case "$1" in
    x86_64|amd64) echo amd64 ;;
    aarch64|arm64) echo arm64 ;;
    armv7l|armv7|armhf|arm) echo arm ;;
    i386|i686|386) echo 386 ;;
    ppc64le|s390x|riscv64) echo "$1" ;;
    *) echo "unknown:$1" ;;
  esac
}

# jq：把 docker stats 的「12.3MiB」「1.2GB」轉成位元組
readonly JQ_TOBYTES='def tobytes:
  (capture("^(?<n>[0-9.]+)\\s*(?<u>[A-Za-z]*)$")? // null) as $c
  | if $c == null then null else
      ({"B":1,"":1,"KiB":1024,"MiB":1048576,"GiB":1073741824,"TiB":1099511627776,
        "kB":1000,"KB":1000,"MB":1000000,"GB":1000000000,"TB":1000000000000}[$c.u]) as $m
      | if $m == null then null else (($c.n|tonumber) * $m | floor) end
    end;'

printf 'resources（%s／%s）：\n' "$ENV_NAME" "$project"

# ---------------------------------------------------------------------------
# 1. 主機架構
# ---------------------------------------------------------------------------
started="$(now)"
uname_m="$(uname -m)"
docker_info="$(dk info --format '{{json .}}' 2>"$tmp/info.err" || true)"
jq -e 'type == "object"' <<< "$docker_info" >/dev/null 2>&1 || docker_info='{}'
docker_arch="$(jq -r '.Architecture // empty' <<< "$docker_info" 2>/dev/null)"
docker_root="$(jq -r '.DockerRootDir // empty' <<< "$docker_info" 2>/dev/null)"
host_arch="$(norm_arch "$uname_m")"
docker_arch_norm="$(norm_arch "${docker_arch:-none}")"
if [[ -n "$docker_arch" && "$host_arch" == "$docker_arch_norm" && "$host_arch" != unknown:* ]]; then
  record host-arch-consistent PASS "uname -m=${uname_m}、docker info=${docker_arch}，皆為 ${host_arch}" "$started"
else
  record host-arch-consistent FAIL "uname -m=${uname_m}（${host_arch}）與 docker info=${docker_arch:-讀取失敗}（${docker_arch_norm}）不一致" "$started"
fi

# ---------------------------------------------------------------------------
# 2. 映像架構與大小（compose config --images 為必要範圍；test profile 的 tools 映像存在時一併檢查）
# ---------------------------------------------------------------------------
started="$(now)"
default_images="$(dc config --images 2>"$tmp/config.err" | sort -u)"
profile_images="$(dc --profile test config --images 2>>"$tmp/config.err" | sort -u)"
all_images="$(printf '%s\n%s\n' "$default_images" "$profile_images" | sed '/^$/d' | sort -u)"
images_json='[]'
img_missing=""; img_mismatch=""; optional_absent=""
if [[ -z "$default_images" ]]; then
  img_missing="compose config --images 無輸出：$(tail -2 "$tmp/config.err" | tr '\n' ' ' | ms_redact)"
fi
while read -r ref; do
  [[ -z "$ref" ]] && continue
  in_default=false
  grep -qxF "$ref" <<< "$default_images" && in_default=true
  if info="$(dk image inspect --format '{{json .}}' "$ref" 2>/dev/null)"; then
    entry="$(jq -c --arg ref "$ref" --argjson d "$in_default" \
      '{ref:$ref, id:.Id, architecture:.Architecture, variant:(.Variant // ""), os:.Os, size_bytes:.Size, required:$d}' <<< "$info")"
    a="$(jq -r '.architecture' <<< "$entry")"
    match=false; [[ "$(norm_arch "$a")" == "$host_arch" ]] && match=true
    [[ "$match" == true ]] || img_mismatch+=" ${ref}=${a}"
    entry="$(jq -c --argjson m "$match" '. + {present:true, arch_match:$m}' <<< "$entry")"
  elif [[ "$in_default" == true ]]; then
    img_missing+=" ${ref}"
    entry="$(jq -cn --arg ref "$ref" '{ref:$ref, required:true, present:false, id:null, architecture:null, size_bytes:null, arch_match:false, error:"本機無此映像"}')"
  else
    # 只屬 test profile（tools，PHPUnit 用）且本機未建置：不屬執行中服務，記錄但不判失敗
    optional_absent+=" ${ref}"
    entry="$(jq -cn --arg ref "$ref" '{ref:$ref, required:false, present:false, id:null, architecture:null, size_bytes:null, arch_match:null, note:"test profile 映像，本機未建置"}')"
  fi
  images_json="$(jq -c --argjson e "$entry" '. + [$e]' <<< "$images_json")"
done <<< "$all_images"
n_images="$(jq '[.[] | select(.present)] | length' <<< "$images_json")"

# 執行中容器實際使用的映像（以容器的 image ID 檢查，涵蓋 tag 已被重建但容器尚未換版的情形）
containers_json='[]'
ctr_mismatch=""; ctr_err=""
while read -r row; do
  [[ -z "$row" ]] && continue
  cid="$(jq -r '.ID' <<< "$row")"; cname="$(jq -r '.Name' <<< "$row")"; svc="$(jq -r '.Service' <<< "$row")"; cref="$(jq -r '.Image' <<< "$row")"
  image_id="$(dk inspect --format '{{.Image}}' "$cid" 2>/dev/null || true)"
  carch=""
  [[ -n "$image_id" ]] && carch="$(dk image inspect --format '{{.Architecture}}' "$image_id" 2>/dev/null || true)"
  tag_id="$(dk image inspect --format '{{.Id}}' "$cref" 2>/dev/null || true)"
  [[ -n "$carch" ]] || ctr_err+=" ${svc}"
  cmatch=false; [[ -n "$carch" && "$(norm_arch "$carch")" == "$host_arch" ]] && cmatch=true
  [[ "$cmatch" == true || -z "$carch" ]] || ctr_mismatch+=" ${svc}=${carch}"
  containers_json="$(jq -c --arg id "$cid" --arg n "$cname" --arg s "$svc" --arg ref "$cref" --arg iid "$image_id" --arg a "$carch" \
      --argjson m "$cmatch" --argjson cur "$([[ -n "$tag_id" && "$tag_id" == "$image_id" ]] && echo true || echo false)" \
      '. + [{service:$s, name:$n, id:$id, image_ref:$ref, image_id:$iid, image_architecture:$a, arch_match:$m, running_current_tag:$cur}]' <<< "$containers_json")"
done < <(printf '%s\n' "$ps_json" | jq -c 'select(.State=="running") | {ID, Name, Service, Image}')
n_ctr="$(jq 'length' <<< "$containers_json")"

if [[ -z "$img_missing" && -z "$img_mismatch" && -z "$ctr_mismatch" && -z "$ctr_err" && "$n_images" -gt 0 && "$n_ctr" -gt 0 ]]; then
  record image-arch-consistent PASS "${n_images} 個映像與 ${n_ctr} 個執行中容器的映像架構皆為 ${host_arch}（與主機一致）${optional_absent:+；test profile 映像未建置：${optional_absent# }}" "$started"
else
  record image-arch-consistent FAIL "缺映像：${img_missing:-無}；映像架構不符：${img_mismatch:-無}；容器映像架構不符：${ctr_mismatch:-無}；容器映像讀取失敗：${ctr_err:-無}" "$started"
fi

started="$(now)"
bad_sizes="$(jq -r '[.[] | select(.present or .required) | select((.size_bytes|type) != "number" or .size_bytes <= 0) | .ref] | join(",")' <<< "$images_json")"
if [[ "$n_images" -gt 0 && -z "$bad_sizes" ]]; then
  record image-size-measured PASS "${n_images} 個映像大小已量測，合計 $(jq '[.[].size_bytes] | add' <<< "$images_json") bytes" "$started"
else
  record image-size-measured FAIL "映像大小量測失敗：${bad_sizes:-無映像}" "$started"
fi

# ---------------------------------------------------------------------------
# 3. 容器記憶體（docker stats --no-stream，只取 testing 專案容器）
# ---------------------------------------------------------------------------
started="$(now)"
mapfile -t cids < <(jq -r '.[].id' <<< "$containers_json")
stats_json='[]'
if (( ${#cids[@]} > 0 )) && dk stats --no-stream --format json "${cids[@]}" > "$tmp/stats.jsonl" 2>"$tmp/stats.err"; then
  stats_json="$(jq -s "$JQ_TOBYTES"' map({id:.ID, name:.Name, mem_usage_raw:.MemUsage, mem_percent:.MemPerc, pids:.PIDs,
        mem_usage_bytes:((.MemUsage|split(" / ")[0]) | tobytes), mem_limit_bytes:((.MemUsage|split(" / ")[1] // "") | tobytes)})' "$tmp/stats.jsonl" 2>>"$tmp/stats.err" || echo '[]')"
fi
# 以容器名稱合併到 containers_json
containers_json="$(jq -c --argjson st "$stats_json" 'map(. as $c | ($st | map(select(.name == $c.name)) | .[0]) as $s
    | $c + {mem_usage_bytes:($s.mem_usage_bytes // null), mem_limit_bytes:($s.mem_limit_bytes // null), mem_usage_raw:($s.mem_usage_raw // null), mem_percent:($s.mem_percent // null), pids:($s.pids // null)})' <<< "$containers_json")"
mem_missing="$(jq -r '[.[] | select((.mem_usage_bytes|type) != "number") | .service] | join(",")' <<< "$containers_json")"
if [[ "$n_ctr" -gt 0 && -z "$mem_missing" ]]; then
  record container-memory-measured PASS "${n_ctr} 個容器記憶體已量測，合計 $(jq '[.[].mem_usage_bytes] | add' <<< "$containers_json") bytes" "$started"
else
  record container-memory-measured FAIL "容器記憶體量測失敗：${mem_missing:-無容器} $(tail -2 "$tmp/stats.err" 2>/dev/null | tr '\n' ' ' | ms_redact)" "$started"
fi

# ---------------------------------------------------------------------------
# 4. testing 專案 volume 大小（du -sb 掛載點；失敗時改用 docker system df -v）
# ---------------------------------------------------------------------------
started="$(now)"
volumes_json='[]'
sysdf=""
while read -r v; do
  [[ -z "$v" ]] && continue
  mp="$(dk volume inspect --format '{{.Mountpoint}}' "$v" 2>/dev/null || true)"
  logical="$(dk volume inspect --format '{{index .Labels "com.docker.compose.volume"}}' "$v" 2>/dev/null || true)"
  size=""; method=""
  if [[ -n "$mp" && -d "$mp" ]]; then
    size="$(timeout "$DU_TIMEOUT" du -sb "$mp" 2>/dev/null | awk '{print $1}')"
    [[ "$size" =~ ^[0-9]+$ ]] && method="du -sb"
  fi
  if [[ -z "$method" ]]; then
    [[ -n "$sysdf" ]] || sysdf="$(timeout "$DF_TIMEOUT" docker system df -v --format json < /dev/null 2>/dev/null || echo '{}')"
    raw="$(jq -r --arg v "$v" '[.Volumes[]? | select(.Name == $v) | .Size][0] // empty' <<< "$sysdf" 2>/dev/null)"
    size="$(jq -rn "$JQ_TOBYTES"' $r | tobytes // empty' --arg r "$raw" 2>/dev/null)"
    [[ "$size" =~ ^[0-9]+$ ]] && method="docker system df -v（${raw}）"
  fi
  volumes_json="$(jq -c --arg n "$v" --arg l "$logical" --arg s "$size" --arg m "$method" \
    '. + [{name:$n, volume:$l, size_bytes:(if ($s|test("^[0-9]+$")) then ($s|tonumber) else null end), method:(if $m == "" then null else $m end)}]' <<< "$volumes_json")"
done < <(dk volume ls -q --filter "label=com.docker.compose.project=${project}" 2>/dev/null | sort)
n_vol="$(jq 'length' <<< "$volumes_json")"
vol_missing="$(jq -r '[.[] | select((.size_bytes|type) != "number") | .name] | join(",")' <<< "$volumes_json")"
if [[ "$n_vol" -gt 0 && -z "$vol_missing" ]]; then
  record volume-size-measured PASS "${n_vol} 個 volume 已量測：$(jq -r 'map("\(.volume // .name)=\(.size_bytes)B") | join("，")' <<< "$volumes_json")" "$started"
else
  record volume-size-measured FAIL "volume 大小量測失敗：${vol_missing:-找不到 ${project} 的 volume}" "$started"
fi

# ---------------------------------------------------------------------------
# 5. Docker root 所在磁碟剩餘
# ---------------------------------------------------------------------------
started="$(now)"
disk_json='null'
if [[ -n "$docker_root" ]] && df_line="$(timeout "$CMD_TIMEOUT" df -P -B1 "$docker_root" 2>"$tmp/df.err" | awk 'NR==2')" && [[ -n "$df_line" ]]; then
  disk_json="$(awk -v p="$docker_root" '{gsub("%","",$5); printf "{\"path\":\"%s\",\"filesystem\":\"%s\",\"total_bytes\":%s,\"used_bytes\":%s,\"available_bytes\":%s,\"use_percent\":%s,\"mounted_on\":\"%s\"}", p, $1, $2, $3, $4, $5, $6}' <<< "$df_line" | jq -c . 2>/dev/null || echo null)"
fi
if [[ "$disk_json" != null ]] && jq -e '(.available_bytes|type)=="number" and (.total_bytes|type)=="number"' <<< "$disk_json" >/dev/null 2>&1; then
  record host-disk-measured PASS "Docker root ${docker_root} 所在磁碟剩餘 $(jq '.available_bytes' <<< "$disk_json") bytes（已用 $(jq '.use_percent' <<< "$disk_json")%）" "$started"
else
  record host-disk-measured FAIL "df -P 無法量測 Docker root（${docker_root:-未知}）：$(tail -1 "$tmp/df.err" 2>/dev/null | ms_redact)" "$started"
fi

# ---------------------------------------------------------------------------
# 摘要
# ---------------------------------------------------------------------------
printf '%s\n' "${records[@]}" | jq -s \
  --arg env "$ENV_NAME" --arg project "$project" --arg commit "$MYSITE_SOURCE_COMMIT" --arg at "$(now)" \
  --arg uname_m "$uname_m" --arg docker_arch "$docker_arch" --arg host_arch "$host_arch" --arg docker_root "$docker_root" \
  --argjson info "$(jq -c '{server_version:.ServerVersion, os_type:.OSType, operating_system:.OperatingSystem, kernel_version:.KernelVersion, ncpu:.NCPU, mem_total_bytes:.MemTotal, storage_driver:.Driver}' <<< "$docker_info" 2>/dev/null || echo '{}')" \
  --argjson images "$images_json" --argjson containers "$containers_json" --argjson volumes "$volumes_json" --argjson disk "$disk_json" \
  '{
    acceptance_id: "INF-08", environment: $env, project: $project, source_commit: $commit, measured_at: $at,
    host: ({uname_m: $uname_m, docker_architecture: $docker_arch, normalized_architecture: $host_arch, docker_root_dir: $docker_root} + $info),
    images: $images,
    images_total_bytes: ([$images[].size_bytes | numbers] | add // 0),
    containers: $containers,
    containers_memory_total_bytes: ([$containers[].mem_usage_bytes | numbers] | add // 0),
    volumes: $volumes,
    volumes_total_bytes: ([$volumes[].size_bytes | numbers] | add // 0),
    disk: $disk,
    results: .
  }' > "$evidence"

if (( fail )); then
  ms_err "INF-08 resources 有失敗項目；結果：$jsonl；摘要：$evidence"
  exit "$EXIT_TEST_FAIL"
fi
ms_info "INF-08 resources 全部通過；結果：$jsonl；摘要：$evidence"
exit "$EXIT_OK"
