#!/usr/bin/env bash
# GitHub Actions 發布／部署 workflow 的共用步驟（release.yml、deploy-staging.yml、deploy-production.yml）。
# 讓 workflow YAML 保持精簡，並可在本機以臨時金鑰測試同一邏輯。不輸出任何秘密；簽章私鑰只以檔案路徑傳入。
set -Eeuo pipefail
umask 077

usage() {
  cat <<'EOF'
用法：scripts/lib/release-ci.sh <子命令> [參數]

  build --tag=<tag>                                   以 scripts/lib/build.sh 建置 release 目標映像
  push-images --tag=<tag> --commit=<sha> --out=<images.json>
                                                      推送 5 個執行期映像到 GHCR，輸出 name@sha256 digest
  candidate --images=<images.json> --commit=<sha> --run-id=<id> --out=<candidate.json>
                                                      產生未簽章候選版（與環境無關）
  current-channel --env=<環境> --public-key=<pem> --out=<current.json> [--repo=<owner/repo>]
                                                      讀目前 channel envelope（以公鑰驗簽）；不存在回 sequence 0
  schema-guard --from=<commit|空> --to=<commit> --class=<none|expand|contract|breaking>
                                                      宣告 none 但 migration／WP 外掛 schema 有變動 → 失敗
  make-envelope --env=<環境> --candidate=<json> --sequence=<n> --class=<…> [--plan-ref=] [--previous=] [--rollback-target=]
                --key-file=<私鑰> --key-id=<id> --public-key=<pem> --out=<envelope.json>
  publish --env=<環境> --envelope=<json> --sequence=<n> --commit=<sha> [--repo=<owner/repo>]
                                                      更新 channel-<env> 的 release-envelope.json，並建立不可變紀錄 deploy-<env>-seq-<n>
  wait-status --url=<https URL> --release-id=<id> --timeout=<秒>
                                                      以 DEPLOY_STATUS_TOKEN（環境變數）輪詢主機部署狀態；逾時 exit 50 DEPLOYMENT_UNCONFIRMED
  external-smoke --app-host=<host> --cms-host=<host>  公開 HTTPS 冒煙（不跳過 TLS 驗證）
exit code：0 成功；10 參數錯；20 缺外部前置；30 檢查失敗；40 拒絕；50 未確認。
EOF
}

# shellcheck source=common.sh
source "$(dirname "${BASH_SOURCE[0]}")/common.sh"
LIB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
ROOT="$(cd "$LIB_DIR/../.." && pwd -P)"
REPO_DEFAULT="Whenwhoengine/mysite"
IMAGE_REPOSITORY="ghcr.io/whenwhoengine/mysite"
IMAGE_NAMES=(laravel wordpress app-web wp-web gateway)
manifest() { python3 -I "$LIB_DIR/manifest.py" "$@"; }

cmd="${1:-}"; [[ -n "$cmd" ]] || { usage >&2; exit "$EXIT_USAGE"; }
shift
[[ "$cmd" == -h || "$cmd" == --help ]] && { usage; exit 0; }
declare -A A=()
for arg in "$@"; do
  case "$arg" in
    -h|--help) usage; exit 0 ;;
    --*=*) k="${arg%%=*}"; k="${k#--}"; [[ "$k" =~ ^[a-z][a-z-]*$ ]] || ms_die "$EXIT_USAGE" "參數名稱不合法：$k"; A[$k]="${arg#*=}" ;;
    *) ms_die "$EXIT_USAGE" "未知參數：$arg" ;;
  esac
done
req() { local k; for k in "$@"; do [[ -n "${A[$k]:-}" ]] || ms_die "$EXIT_USAGE" "缺少 --$k"; done; }
env_ok() { case "$1" in staging|production) ;; *) ms_die "$EXIT_USAGE" "--env 只允許 staging／production" ;; esac; }
sha_ok() { [[ "$1" =~ ^[0-9a-f]{40}$ ]] || ms_die "$EXIT_USAGE" "commit 必須是 40 位小寫 hex"; }
repo() { local r="${A[repo]:-$REPO_DEFAULT}"; [[ "$r" == "$REPO_DEFAULT" ]] || ms_die "$EXIT_FORBIDDEN" "只允許 $REPO_DEFAULT"; printf '%s\n' "$r"; }

case "$cmd" in
build)
  req tag
  [[ "${A[tag]}" =~ ^[a-z0-9][a-z0-9._-]{0,63}$ ]] || ms_die "$EXIT_USAGE" "--tag 格式不合法"
  # shellcheck source=build.sh
  source "$LIB_DIR/build.sh"
  ms_load_versions "$ROOT"
  export MYSITE_EFFECTIVE_REGISTRY="${MYSITE_BASE_IMAGE_REGISTRY:-$BASE_IMAGE_REGISTRY}"
  export MYSITE_SOURCE_COMMIT; MYSITE_SOURCE_COMMIT="$(git -C "$ROOT" rev-parse HEAD)"
  mkdir -p "$ROOT/.runtime"
  bash "$ROOT/scripts/cloud/fetch-deps.sh"
  ms_build_images "$ROOT" release "${A[tag]}"
  ;;

push-images)
  req tag commit out; sha_ok "${A[commit]}"
  tmp="$(mktemp)"; printf '{' > "$tmp"; sep=""
  for name in "${IMAGE_NAMES[@]}"; do
    src="mysite/${name}:${A[tag]}"; dst="${IMAGE_REPOSITORY}/${name}:sha-${A[commit]}"
    docker image inspect "$src" >/dev/null 2>&1 || ms_die "$EXIT_TEST_FAIL" "找不到建置映像 $src"
    docker tag "$src" "$dst"
    timeout 1200 docker push --quiet "$dst" >/dev/null || ms_die "$EXIT_EXTERNAL" "推送失敗：$dst"
    ref="$(docker image inspect --format '{{range .RepoDigests}}{{println .}}{{end}}' "$dst" | grep -E "^${IMAGE_REPOSITORY}/${name}@sha256:[0-9a-f]{64}$" | head -n 1)"
    [[ -n "$ref" ]] || ms_die "$EXIT_TEST_FAIL" "無法取得 $name 的 registry digest"
    printf '%s"%s":"%s"' "$sep" "$name" "$ref" >> "$tmp"; sep=","
  done
  printf '}\n' >> "$tmp"
  manifest validate-images --images-json "$tmp" >/dev/null || ms_die "$EXIT_TEST_FAIL" "images 格式驗證失敗"
  install -m 0644 "$tmp" "${A[out]}"; rm -f -- "$tmp"
  ;;

candidate)
  req images commit run-id out; sha_ok "${A[commit]}"
  [[ "${A[run-id]}" =~ ^[0-9]{1,20}(-[0-9]{1,4})?$ ]] || ms_die "$EXIT_USAGE" "--run-id 格式不合法"
  manifest validate-images --images-json "${A[images]}" >/dev/null || ms_die "$EXIT_TEST_FAIL" "images 不合法"
  ms_load_versions "$ROOT"
  [[ "$MYSQL_VERSION" =~ ^([0-9]+\.[0-9]+)\. ]] || ms_die "$EXIT_USAGE" "versions.env 缺 MYSQL_VERSION"
  python3 -I - "${A[images]}" "${A[commit]}" "${A[run-id]}" "${BASH_REMATCH[1]}" "${A[out]}" <<'PY'
import datetime, json, sys
images, commit, run_id, mysql_major, out = sys.argv[1:6]
now = datetime.datetime.now(datetime.timezone.utc)
cand = {"schema_version": 1, "kind": "mysite-release-candidate", "source_commit": commit,
        "release_id": f"r{now:%Y%m%d}-{commit[:12]}-{run_id.split('-')[0]}",
        "images": json.load(open(images)), "verification_run_id": run_id, "mysql_major": mysql_major,
        "built_at": now.strftime("%Y-%m-%dT%H:%M:%SZ")}
json.dump(cand, open(out, "w"), indent=2, sort_keys=True)
print(cand["release_id"])
PY
  ;;

current-channel)
  req env public-key out; env_ok "${A[env]}"
  r="$(repo)"; d="$(mktemp -d)"
  if gh release download "channel-${A[env]}" --repo "$r" --pattern release-envelope.json --dir "$d" >/dev/null 2>&1; then
    seq="$(manifest read-verified --envelope "$d/release-envelope.json" --public-key "${A[public-key]}" --field sequence)" \
      || ms_die "$EXIT_FORBIDDEN" "目前 channel 的 envelope 無法以環境公鑰驗證（金鑰設定錯誤或 channel 被竄改）"
    payload="$(manifest read-verified --envelope "$d/release-envelope.json" --public-key "${A[public-key]}" --field images)"
    rid="$(manifest read-verified --envelope "$d/release-envelope.json" --public-key "${A[public-key]}" --field release_id)"
    commit="$(manifest read-verified --envelope "$d/release-envelope.json" --public-key "${A[public-key]}" --field source_commit)"
    python3 -I -c 'import json,sys; json.dump({"sequence":int(sys.argv[1]),"release_id":sys.argv[2],"source_commit":sys.argv[3],"images":json.loads(sys.argv[4])},open(sys.argv[5],"w"),indent=2,sort_keys=True)' \
      "$seq" "$rid" "$commit" "$payload" "${A[out]}"
  else
    printf '{"sequence": 0, "release_id": null, "source_commit": null, "images": null}\n' > "${A[out]}"
  fi
  rm -rf -- "$d"
  ;;

schema-guard)
  req to class; sha_ok "${A[to]}"
  case "${A[class]}" in none|expand|contract|breaking) ;; *) ms_die "$EXIT_USAGE" "--class 不合法" ;; esac
  from="${A[from]:-}"
  if [[ -z "$from" ]]; then ms_info "首次發布：無上一版可比對"; exit 0; fi
  sha_ok "$from"
  changed="$(git -C "$ROOT" diff --name-only "$from" "${A[to]}" -- app/database/migrations wp/plugins/site-content/includes 2>/dev/null)" \
    || ms_die "$EXIT_EXTERNAL" "無法比對 commit（請以 fetch-depth: 0 checkout）"
  mig="$(git -C "$ROOT" diff --name-only "$from" "${A[to]}" -- app/database/migrations | wc -l | tr -d ' ')"
  if [[ "${A[class]}" == none && "$mig" != 0 ]]; then
    ms_die "$EXIT_TEST_FAIL" "宣告 schema_change_class=none，但 ${mig} 個 migration 檔有變動；請重新宣告（expand／contract／breaking）"
  fi
  [[ -n "$changed" ]] && ms_info "schema 相關變動：$(printf '%s' "$changed" | tr '\n' ' ' | cut -c1-400)"
  exit 0
  ;;

make-envelope)
  req env candidate sequence class key-file key-id public-key out; env_ok "${A[env]}"
  [[ "${A[sequence]}" =~ ^[1-9][0-9]{0,15}$ ]] || ms_die "$EXIT_USAGE" "--sequence 必須是正整數"
  [[ "${A[key-id]}" == "${A[env]}"-* ]] || ms_die "$EXIT_FORBIDDEN" "key_id 必須以 ${A[env]}- 開頭（環境金鑰不得共用）"
  d="$(mktemp -d)"; trap 'rm -rf -- "$d"' EXIT
  python3 -I -c 'import json,sys
c=json.load(open(sys.argv[1]))
assert c.get("kind")=="mysite-release-candidate"
json.dump(c["images"],open(sys.argv[2],"w"))
print(c["release_id"]); print(c["source_commit"]); print(c["verification_run_id"]); print(c["mysql_major"])' \
    "${A[candidate]}" "$d/images.json" > "$d/fields" || ms_die "$EXIT_USAGE" "候選版檔案格式不合法"
  mapfile -t f < "$d/fields"
  rid="${f[0]}"
  [[ -n "${A[rollback-target]:-}" ]] && rid="${f[0]}-rb${A[sequence]}"
  manifest make-payload --environment "${A[env]}" --channel "${A[env]}" --sequence "${A[sequence]}" --release-id "$rid" \
    --source-commit "${f[1]}" --images-json "$d/images.json" --mysql-major "${f[3]}" --schema-change-class "${A[class]}" \
    --migration-plan-ref "${A[plan-ref]:-}" --verification-run-id "${f[2]}" --previous-verified-release "${A[previous]:-}" \
    --rollback-target-release "${A[rollback-target]:-}" --out "$d/payload.json" > "$d/mk.json" \
    || ms_die "$EXIT_FORBIDDEN" "payload 不合法：$(cat "$d/mk.json")"
  manifest sign --payload "$d/payload.json" --private-key "${A[key-file]}" --key-id "${A[key-id]}" \
    --expect-public-key "${A[public-key]}" --out "${A[out]}" || ms_die "$EXIT_FORBIDDEN" "簽章失敗或私鑰與環境公鑰不符"
  chmod 0644 "${A[out]}"
  ms_info "已簽署 ${A[env]} sequence=${A[sequence]} release=${rid}"
  ;;

publish)
  req env envelope sequence commit; env_ok "${A[env]}"; sha_ok "${A[commit]}"
  [[ "${A[sequence]}" =~ ^[1-9][0-9]{0,15}$ ]] || ms_die "$EXIT_USAGE" "--sequence 必須是正整數"
  r="$(repo)"; tag="channel-${A[env]}"; d="$(mktemp -d)"; trap 'rm -rf -- "$d"' EXIT
  cp "${A[envelope]}" "$d/release-envelope.json"
  # 不可變紀錄：每次核准的清單另存一個獨立 release（channel 只是部署指標）
  hist="deploy-${A[env]}-seq-${A[sequence]}"
  gh release view "$hist" --repo "$r" >/dev/null 2>&1 && ms_die "$EXIT_FORBIDDEN" "$hist 已存在：序號不得重用"
  gh release create "$hist" "$d/release-envelope.json" --repo "$r" --target "${A[commit]}" --prerelease \
    --title "${A[env]} 部署清單 #${A[sequence]}" --notes "已簽章部署清單（${A[env]}，sequence ${A[sequence]}）。不含秘密；上傳不代表部署成功。" >/dev/null \
    || ms_die "$EXIT_EXTERNAL" "無法建立 $hist"
  if ! gh release view "$tag" --repo "$r" >/dev/null 2>&1; then
    gh release create "$tag" --repo "$r" --target "${A[commit]}" --prerelease --title "${A[env]} 部署 channel" \
      --notes "主機只讀這個 channel 的 release-envelope.json；請勿手動修改。" >/dev/null || ms_die "$EXIT_EXTERNAL" "無法建立 channel $tag"
  fi
  gh release upload "$tag" "$d/release-envelope.json" --repo "$r" --clobber >/dev/null || ms_die "$EXIT_EXTERNAL" "無法上傳 channel envelope"
  ms_info "已發布到 ${tag}（sequence ${A[sequence]}）；主機拉取與換版需另行確認"
  ;;

wait-status)
  req url release-id timeout
  [[ "${A[url]}" =~ ^https://([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}/internal/deploy-status$ ]] || ms_die "$EXIT_USAGE" "--url 必須是核准主機的 https://<host>/internal/deploy-status"
  [[ "${A[timeout]}" =~ ^[0-9]{1,5}$ ]] || ms_die "$EXIT_USAGE" "--timeout 必須是秒數"
  [[ -n "${DEPLOY_STATUS_TOKEN:-}" ]] || { ms_err "DEPLOYMENT_UNCONFIRMED：未設定 DEPLOY_STATUS_TOKEN（environment secret）"; exit "$EXIT_DEPLOY_INCOMPLETE"; }
  wait_s="${A[timeout]}"; deadline=$(( $(date +%s) + wait_s )); last="unreachable"; out="$(mktemp)"
  while (( $(date +%s) < deadline )); do
    code="$(printf 'header = "Authorization: Bearer %s"\n' "$DEPLOY_STATUS_TOKEN" | curl -sS --proto '=https' --tlsv1.2 --max-redirs 0 \
      --max-time 15 -K - -o "$out" -w '%{http_code}' "${A[url]}" 2>/dev/null)" || code="000"
    if [[ "$code" == 200 ]]; then
      last="$(python3 -I -c 'import json,sys
d=json.load(open(sys.argv[1]))
print("match" if d.get("release_id")==sys.argv[2] and d.get("status")=="active" else "other:"+str(d.get("release_id"))[:80])' "$out" "${A[release-id]}" 2>/dev/null || echo invalid)"
      [[ "$last" == match ]] && { rm -f -- "$out"; ms_info "主機已回報 release ${A[release-id]}（status=active）"; exit 0; }
    else
      last="http_${code}"
    fi
    sleep 30
  done
  rm -f -- "$out"
  ms_err "DEPLOYMENT_UNCONFIRMED：${A[timeout]} 秒內主機未回報 ${A[release-id]}（最後狀態：${last}）"
  exit "$EXIT_DEPLOY_INCOMPLETE"
  ;;

external-smoke)
  req app-host cms-host
  re='^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$'
  [[ "${A[app-host]}" =~ $re && "${A[cms-host]}" =~ $re ]] || ms_die "$EXIT_USAGE" "主機名稱不合法"
  fails=0
  chk() { local url="$1" want="$2" code; code="$(curl -sS --proto '=https' --tlsv1.2 --max-redirs 0 --max-time 15 -o /dev/null -w '%{http_code}' "$url" 2>/dev/null)" || code=000
    if [[ "$code" == "$want" ]]; then ms_info "✓ $url → $code"; else ms_err "✗ $url → $code（預期 $want）"; fails=1; fi; }
  chk "https://${A[app-host]}/healthz" 200
  chk "https://${A[app-host]}/" 200
  chk "https://${A[cms-host]}/wp-json/site-content/v1/health" 200
  chk "https://${A[app-host]}/internal/diagnostics/php" 404
  (( fails == 0 )) || exit "$EXIT_TEST_FAIL"
  ;;

*) usage >&2; exit "$EXIT_USAGE" ;;
esac
