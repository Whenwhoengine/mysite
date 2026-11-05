#!/usr/bin/env bash
# tests/deploy 共用測試夾具：臨時主機根目錄、臨時 Ed25519 金鑰、payload／envelope 產生器、斷言。
# 所有金鑰與秘密只在 mktemp -d 目錄產生，run.sh 結束時整個刪除。
# shellcheck shell=bash

T_REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
T_STUBS="$T_REPO/tests/deploy/stubs"
MANIFEST=(python3 -I "$T_REPO/scripts/lib/manifest.py")

# ---------------------------------------------------------------------------
# 斷言（失敗時印出原因並回傳 1）
# ---------------------------------------------------------------------------
fail() { printf 'FAIL: %s\n' "$*"; return 1; }
assert_rc() { [[ "$2" == "$1" ]] || fail "$3：預期 exit $1，實際 $2"; }
assert_file_grep() { grep -Eq -- "$1" "$2" 2>/dev/null || fail "$3（找不到 /$1/）"; }
assert_file_no_grep() { ! grep -Eq -- "$1" "$2" 2>/dev/null || fail "$3（不應出現 /$1/）"; }
json_get() { python3 -I -c 'import json,sys
v=json.load(open(sys.argv[1]))
for p in sys.argv[2].split("."):
    v = v.get(p) if isinstance(v, dict) else None
print("" if v is None else (json.dumps(v) if isinstance(v,(dict,list)) else v))' "$1" "$2"; }

# ---------------------------------------------------------------------------
# 金鑰與映像
# ---------------------------------------------------------------------------
keys_init() {
  KEYS="$T_ROOT/keys"; mkdir -p "$KEYS"; chmod 0700 "$KEYS"
  local k
  for k in staging-k1 staging-k2 production-k1 attacker; do
    openssl genpkey -algorithm ED25519 -out "$KEYS/$k.pem" 2>/dev/null
    openssl pkey -in "$KEYS/$k.pem" -pubout -out "$KEYS/$k.pub.pem" 2>/dev/null
  done
}
rand_digest() { openssl rand -hex 32; }
images_json() { # images_json <out> [digest]
  local d="${2:-$(rand_digest)}"
  python3 -I -c 'import json,sys
names=["laravel","wordpress","app-web","wp-web","gateway"]
json.dump({n:f"ghcr.io/whenwhoengine/mysite/{n}@sha256:{sys.argv[2]}" for n in names},open(sys.argv[1],"w"))' "$1" "$d"
}

# payload <out> <env> <seq> <images.json> [key=JSON …]（值為 JSON；__delete__ 代表刪除欄位）
payload() {
  local out="$1" env="$2" seq="$3" imgs="$4"; shift 4
  python3 -I - "$out" "$env" "$seq" "$imgs" "$@" <<'PY'
import json, sys, time, datetime
out, env, seq, imgs, *over = sys.argv[1:]
fmt = lambda t: datetime.datetime.fromtimestamp(t, datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
now = int(time.time())
p = {"schema_version": 1, "project": "Whenwhoengine/mysite", "environment": env, "channel": env, "sequence": int(seq),
     "release_id": f"r-test-{seq}", "source_commit": "a" * 40, "created_at": fmt(now - 60), "expires_at": fmt(now + 3600),
     "platform_contract": 1, "images": json.load(open(imgs)), "infrastructure": {"mysql_major": "8.4"},
     "schema_change_class": "none", "migration_plan_ref": None, "verification_run_id": "12345-1",
     "previous_verified_release": None, "rollback_target_release": None}
for o in over:
    k, _, v = o.partition("=")
    tgt = p
    parts = k.split(".")
    for part in parts[:-1]:
        tgt = tgt[part]
    if v == "__delete__":
        tgt.pop(parts[-1], None)
    else:
        tgt[parts[-1]] = json.loads(v)
open(out, "w").write(json.dumps(p, ensure_ascii=False, sort_keys=True) + "\n")
PY
}
# sign <payload> <私鑰名稱> <key_id> <out>（--skip-schema：允許簽署不合法 payload 以測試拒絕）
sign() { "${MANIFEST[@]}" sign --skip-schema --payload "$1" --private-key "$KEYS/$2.pem" --key-id "$3" --out "$4"; }
# envelope <out> <env> <seq> <images.json> [overrides…]：以 <env>-k1 簽署
envelope() {
  local out="$1" env="$2"
  payload "$out.payload" "$2" "$3" "$4" "${@:5}"
  sign "$out.payload" "${env}-k1" "${env}-k1" "$out"
}

# ---------------------------------------------------------------------------
# 臨時主機（MYSITE_HOST_ROOT）
# ---------------------------------------------------------------------------
# new_host <名稱> <env>：設定 H、HE（環境目錄）、STUB_DIR、STUB_LOG
new_host() {
  local name="$1" env="$2"
  H="$T_ROOT/hosts/$name"; HE="$H/environments/$env"; HENV="$env"
  mkdir -p "$HE"/{config,secrets,releases/incoming,releases/verified,state} "$H/tls/acme" "$H/tls/letsencrypt" "$H/backup-staging/$env"
  chmod 0750 "$HE/secrets"; chmod 0700 "$H/backup-staging" "$H/backup-staging/$env"
  mkdir -p "$HE/config/trusted-keys"; chmod 0755 "$HE/config/trusted-keys"
  install -m 0644 "$KEYS/${env}-k1.pub.pem" "$HE/config/trusted-keys/${env}-k1.pem"
  cat > "$HE/config/deploy.env" <<EOF
DEPLOY_ENVIRONMENT=$env
DEPLOY_CHANNEL=$env
APP_HOST=www.${env}.example.test
CMS_HOST=cms.${env}.example.test
MYSITE_GATEWAY_MODE=https
SMOKE_TIMEOUT_SECONDS=2
RESTIC_BIN=$T_STUBS/restic
MIN_FREE_DISK_MB=1
EOF
  local h
  for h in "www.${env}.example.test" "cms.${env}.example.test"; do
    mkdir -p "$H/tls/letsencrypt/live/$h"; printf 'stub\n' > "$H/tls/letsencrypt/live/$h/fullchain.pem"; printf 'stub\n' > "$H/tls/letsencrypt/live/$h/privkey.pem"
  done
  local s
  for s in deploy_status_token restic_password app_db_password wp_db_password mysql_root_password wp_salts wp_service_app_password; do
    ( umask 027; openssl rand -hex 24 > "$HE/secrets/$s" )
  done
  printf '%s\n' "$H/restic-repo-placeholder" > "$HE/secrets/restic_repository"; chmod 0640 "$HE/secrets/restic_repository"
  STUB_DIR="$H/stub"; STUB_LOG="$H/stub.log"; mkdir -p "$STUB_DIR/running" "$STUB_DIR/volumes"; : > "$STUB_LOG"
}

# 讓主機看起來「已有一版在執行」：容器執行中、資料卷存在
host_running() {
  local s
  for s in mysql wp-php app-php app-web gateway; do touch "$STUB_DIR/running/$s"; done
  for s in mysql-data wp-uploads app-storage; do touch "$STUB_DIR/volumes/mysite-${HENV}_$s"; done
}

# run_host <命令…>：以替身 PATH 與臨時主機執行；輸出存 $H/last.out，回傳 exit code
run_host() {
  env MYSITE_HOST_ROOT="$H" PATH="$T_STUBS:$PATH" STUB_DIR="$STUB_DIR" STUB_LOG="$STUB_LOG" STUB_HOST_ENV_DIR="$HE" \
    MD_SMOKE_INTERVAL=0 MD_BACKUP_LOCK_WAIT=5 "$@" > "$H/last.out" 2>&1
}
