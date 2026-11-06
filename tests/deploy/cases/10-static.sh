#!/usr/bin/env bash
# 靜態檢查：語法、--help、參數驗證、schema 文件、compose.prod.yml 契約、workflow、systemd unit。
# shellcheck shell=bash

DEPLOY_SCRIPTS=(
  scripts/deploy/preflight.sh scripts/deploy/bootstrap-host.sh scripts/deploy/pull-release.sh scripts/deploy/verify-release.sh
  scripts/deploy/release.sh scripts/deploy/rollback.sh scripts/deploy/smoke.sh
  scripts/ops/backup.sh scripts/ops/restore-check.sh scripts/ops/rotate-secrets.sh
  scripts/lib/release-ci.sh infra/systemd/libexec/mysite-certbot-renew.sh tests/deploy/run.sh
)

s01_syntax() {
  local f
  for f in "${DEPLOY_SCRIPTS[@]}" scripts/lib/deploy.sh tests/deploy/lib.sh tests/deploy/cases/*.sh tests/deploy/stubs/*; do
    bash -n "$T_REPO/$f" 2>&1 || fail "bash -n 失敗：$f" || return 1
    head -n 1 "$T_REPO/$f" | grep -q '^#!/usr/bin/env bash' || [[ "$f" == tests/deploy/cases/* || "$f" == scripts/lib/deploy.sh || "$f" == tests/deploy/lib.sh ]] \
      || fail "缺少 #!/usr/bin/env bash：$f" || return 1
  done
  for f in "${DEPLOY_SCRIPTS[@]}"; do
    [[ "$f" == tests/* ]] && continue
    grep -q 'set -Eeuo pipefail' "$T_REPO/$f" || fail "未使用 set -Eeuo pipefail：$f" || return 1
    ! grep -nE '(^|[^a-z_])eval[[:space:]]' "$T_REPO/$f" || fail "不得使用 eval：$f" || return 1
    ! grep -nE 'down -v|migrate:fresh|system prune|volume prune|image prune' "$T_REPO/$f" | grep -v '^[0-9]*:#' | grep -v '禁止\|不執行\|不得' \
      || fail "出現破壞性指令：$f" || return 1
  done
  python3 -I -c 'import ast,sys; ast.parse(open(sys.argv[1],encoding="utf-8").read())' "$T_REPO/scripts/lib/manifest.py" || fail "manifest.py 語法錯誤" || return 1
  echo "OK: ${#DEPLOY_SCRIPTS[@]} 支腳本＋共用檔 bash -n／python 語法通過，無 eval 與破壞性指令"
}

s02_help_and_args() {
  local f out rc
  for f in "${DEPLOY_SCRIPTS[@]}"; do
    out="$(bash "$T_REPO/$f" --help 2>&1)"; rc=$?
    assert_rc 0 "$rc" "$f --help" || return 1
    grep -q '用法' <<< "$out" || fail "$f --help 缺少用法說明" || return 1
    grep -q 'exit code' <<< "$out" || fail "$f --help 未說明 exit code" || return 1
  done
  python3 -I "$T_REPO/scripts/lib/manifest.py" --help >/dev/null || fail "manifest.py --help 失敗" || return 1
  # 環境 enum 與未知參數一律 exit 10
  for f in scripts/deploy/preflight.sh scripts/deploy/pull-release.sh scripts/ops/backup.sh; do
    bash "$T_REPO/$f" --env=prod --reason=x >/dev/null 2>&1; rc=$?
    assert_rc 10 "$rc" "$f --env=prod" || return 1
    bash "$T_REPO/$f" --bogus >/dev/null 2>&1; rc=$?
    assert_rc 10 "$rc" "$f 未知參數" || return 1
  done
  bash "$T_REPO/scripts/deploy/release.sh" --env=development --verified-envelope=/dev/null >/dev/null 2>&1; rc=$?
  assert_rc 10 "$rc" "release.sh 拒絕 development" || return 1
  bash "$T_REPO/scripts/ops/backup.sh" --env=staging '--reason=x;rm -rf /' >/dev/null 2>&1; rc=$?
  assert_rc 10 "$rc" "backup.sh 拒絕含 shell 字元的 reason" || return 1
  bash "$T_REPO/scripts/deploy/smoke.sh" --env=staging '--expected-release=$(id)' >/dev/null 2>&1; rc=$?
  assert_rc 10 "$rc" "smoke.sh 拒絕不合法 release id" || return 1
  echo "OK: ${#DEPLOY_SCRIPTS[@]} 支腳本 --help 可用，環境 enum／未知參數／注入字元皆 exit 10"
}

s03_schema_doc() {
  python3 -I -B - "$T_REPO" <<'PY' || return 1
import importlib.util, json, sys
sys.dont_write_bytecode = True   # 不在 scripts/lib 產生 __pycache__
root = sys.argv[1]
schema = json.load(open(f"{root}/docs/schemas/release-payload.schema.json", encoding="utf-8"))
spec = importlib.util.spec_from_file_location("manifest", f"{root}/scripts/lib/manifest.py")
m = importlib.util.module_from_spec(spec); spec.loader.exec_module(m)
def chk(cond, msg):
    if not cond:
        print("FAIL: " + msg); sys.exit(1)
chk(schema.get("additionalProperties") is False, "頂層必須 additionalProperties=false")
chk(sorted(schema["required"]) == sorted(m.PAYLOAD_FIELDS), "required 與 manifest.py PAYLOAD_FIELDS 不一致")
chk(sorted(schema["properties"]) == sorted(m.PAYLOAD_FIELDS), "properties 與 PAYLOAD_FIELDS 不一致")
img = schema["properties"]["images"]
chk(img.get("additionalProperties") is False and sorted(img["required"]) == sorted(m.IMAGE_NAMES), "images 必須恰為五個核准服務")
chk(schema["properties"]["infrastructure"].get("additionalProperties") is False, "infrastructure 必須禁止未知欄位")
chk(schema["properties"]["schema_change_class"]["enum"] == list(m.SCHEMA_CHANGE_CLASSES), "schema_change_class enum 不一致")
env = json.load(open(f"{root}/docs/schemas/release-payload.schema.json", encoding="utf-8"))["$defs"]["envelope"]
chk(sorted(env["required"]) == sorted(m.ENVELOPE_KEYS) and env.get("additionalProperties") is False, "envelope 定義與 ENVELOPE_KEYS 不一致")
try:
    import jsonschema
except ImportError:
    print("OK: schema 文件與 manifest.py 契約一致（環境無 jsonschema 套件，未做文件層實例驗證）"); sys.exit(0)
d = "a" * 64
good = {"schema_version": 1, "project": "Whenwhoengine/mysite", "environment": "staging", "channel": "staging", "sequence": 3,
        "release_id": "r-1", "source_commit": "b" * 40, "created_at": "2026-11-05T00:00:00Z", "expires_at": "2026-11-05T12:00:00Z",
        "platform_contract": 1, "images": {n: f"ghcr.io/whenwhoengine/mysite/{n}@sha256:{d}" for n in m.IMAGE_NAMES},
        "infrastructure": {"mysql_major": "8.4"}, "schema_change_class": "none", "migration_plan_ref": None,
        "verification_run_id": "1", "previous_verified_release": None, "rollback_target_release": None}
v = jsonschema.Draft202012Validator(schema)
chk(not list(v.iter_errors(good)), "合法 payload 未通過 schema 文件")
for bad in ({**good, "shell": "rm -rf /"}, {**good, "images": {**good["images"], "laravel": "ghcr.io/whenwhoengine/mysite/laravel:latest"}},
            {**good, "images": {**good["images"], "extra": "x"}}):
    chk(list(v.iter_errors(bad)), "不合法 payload 竟通過 schema 文件")
print("OK: schema 文件與 manifest.py 契約一致，jsonschema 實例驗證（合法通過、3 種不合法被拒）")
PY
}

s04_compose_prod() {
  command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1 || { fail "缺少 docker compose，無法驗證 compose.prod.yml"; return 1; }
  local d envf rc
  d="$T_ROOT/compose"; mkdir -p "$d"; envf="$d/compose.env"
  local dg; dg="$(rand_digest)"
  {
    echo "COMPOSE_PROJECT_NAME=mysite-deploytest-cfg"; echo "MYSITE_ENVIRONMENT=production"; echo "WP_ENVIRONMENT_TYPE=production"
    echo "APP_URL=https://www.example.test"; echo "WP_PUBLIC_URL=https://cms.example.test"; echo "APP_HOST=www.example.test"; echo "CMS_HOST=cms.example.test"
    echo "MYSITE_GATEWAY_MODE=bootstrap"; echo "DEPLOY_ENVIRONMENT=production"; echo "DEPLOY_CHANNEL=production"; echo "RELEASE_ID=r-cfg"
    for n in LARAVEL WORDPRESS APP_WEB WP_WEB GATEWAY; do
      lower="$(tr 'A-Z_' 'a-z-' <<< "$n")"; echo "MYSITE_IMAGE_${n}=ghcr.io/whenwhoengine/mysite/${lower}@sha256:${dg}"
    done
    echo "MYSITE_SECRETS_DIR=$d/secrets"; echo "MYSITE_TLS_DIR=$d/tls"; echo "MYSITE_ACME_DIR=$d/acme"
  } > "$envf"
  docker compose --project-name mysite-deploytest-cfg --project-directory "$T_REPO/infra" --env-file "$T_REPO/infra/versions.env" --env-file "$envf" \
    -f "$T_REPO/infra/compose.base.yml" -f "$T_REPO/infra/compose.prod.yml" config --format json > "$d/cfg.json" 2> "$d/err"; rc=$?
  assert_rc 0 "$rc" "docker compose config（$(head -c 200 "$d/err")）" || return 1
  python3 -I - "$d/cfg.json" "$T_REPO" "$d" <<'PY' || return 1
import json, re, sys
c = json.load(open(sys.argv[1])); repo = sys.argv[2]; tmp = sys.argv[3]
def chk(cond, msg):
    if not cond:
        print("FAIL: " + msg); sys.exit(1)
digest = re.compile(r"^ghcr\.io/whenwhoengine/mysite/[a-z-]+@sha256:[0-9a-f]{64}$")
for name, s in c["services"].items():
    chk("build" not in s, f"{name} 不得 build")
    img = s.get("image", "")
    if name == "mysql":
        chk(re.search(r"mysql:8\.4\.\d+@sha256:[0-9a-f]{64}$", img), "mysql 必須以平台 versions.env 的 digest 固定")
    else:
        chk(digest.match(img), f"{name} 映像必須是 digest：{img}")
        chk(s.get("pull_policy") == "never", f"{name} pull_policy 必須 never（release.sh 先拉齊並核對）")
    ports = s.get("ports", [])
    if name == "gateway":
        chk(sorted(str(p.get("published")) for p in ports) == ["443", "80"], "gateway 只能發布 80／443")
        env = s["environment"]
        chk(env["NGINX_ENVSUBST_TEMPLATE_DIR"] == "/etc/nginx/templates/bootstrap", "bootstrap 模式模板目錄錯誤")
        chk(env.get("MYSITE_HSTS_HEADER", "") == "", "HSTS 預設必須為空")
        vols = {v["target"]: v for v in s.get("volumes", [])}
        chk(vols.get("/etc/mysite-tls", {}).get("read_only") is True, "TLS 目錄必須唯讀掛載")
        chk(vols.get("/var/www/acme", {}).get("read_only") is True, "ACME webroot 必須唯讀掛載")
    else:
        chk(not ports, f"{name} 不得發布任何 port")
    for v in s.get("volumes", []):
        if v.get("type") == "bind":
            src = v["source"]
            chk(not src.startswith(f"{repo}/app") and not src.startswith(f"{repo}/wp"), f"{name} 不得掛載原始碼：{src}")
            chk(src.startswith(tmp) or src == f"{repo}/infra/mysql/init", f"{name} 出現未預期的 bind mount：{src}")
for svc in ("app-php", "app-worker", "app-scheduler"):
    e = c["services"][svc]["environment"]
    chk(e["APP_DEBUG"] == "false" and e["SESSION_SECURE_COOKIE"] == "true", f"{svc} 正式設定錯誤")
    chk(e["RELEASE_ID"] == "r-cfg" and e["DEPLOY_STATUS_TOKEN_FILE"] == "/run/secrets/deploy_status_token", f"{svc} release／部署狀態權杖設定錯誤")
    chk(any((x if isinstance(x, str) else x.get("source")) == "deploy_status_token" for x in c["services"][svc].get("secrets", [])), f"{svc} 缺 deploy_status_token 秘密")
# 金鑰圈（GAP-KEYRING-01）：只掛給需要的服務；事件與預覽用途分離；舊 APP_KEY 只給 Laravel
rings = {"content_event_hmac_keyring": "CONTENT_EVENT_HMAC_KEYRING_FILE", "preview_signing_keyring": "PREVIEW_SIGNING_KEYRING_FILE"}
def secret_names(svc):
    return {(x if isinstance(x, str) else x.get("source")) for x in c["services"][svc].get("secrets", [])}
for svc in ("app-php", "app-worker", "app-scheduler", "wp-php", "wp-jobs"):
    e = c["services"][svc]["environment"]
    for name, var in rings.items():
        chk(name in secret_names(svc), f"{svc} 缺 {name} 秘密")
        chk(e.get(var) == f"/run/secrets/{name}", f"{svc} 的 {var} 設定錯誤")
    is_app = svc.startswith("app-")
    chk(("app_previous_keys" in secret_names(svc)) == is_app, f"{svc} 的 app_previous_keys 掛載不正確（只給 Laravel）")
    chk((e.get("APP_PREVIOUS_KEYS_FILE") == "/run/secrets/app_previous_keys") == is_app, f"{svc} 的 APP_PREVIOUS_KEYS_FILE 設定錯誤")
for svc in ("mysql", "wp-web", "app-web", "gateway"):
    chk(not (secret_names(svc) & (set(rings) | {"app_previous_keys"})), f"{svc} 不應取得金鑰圈或舊 APP_KEY")
print("OK")
PY
  # https 模式切換模板；缺映像變數必須拒絕
  sed -i 's/^MYSITE_GATEWAY_MODE=.*/MYSITE_GATEWAY_MODE=https/' "$envf"
  docker compose --project-name mysite-deploytest-cfg --project-directory "$T_REPO/infra" --env-file "$T_REPO/infra/versions.env" --env-file "$envf" \
    -f "$T_REPO/infra/compose.base.yml" -f "$T_REPO/infra/compose.prod.yml" config --format json 2>/dev/null \
    | jq -e '.services.gateway.environment.NGINX_ENVSUBST_TEMPLATE_DIR == "/etc/nginx/templates/https"' >/dev/null \
    || fail "https 模式未選用 https 模板" || return 1
  sed -i '/^MYSITE_IMAGE_LARAVEL=/d' "$envf"
  docker compose --project-name mysite-deploytest-cfg --project-directory "$T_REPO/infra" --env-file "$T_REPO/infra/versions.env" --env-file "$envf" \
    -f "$T_REPO/infra/compose.base.yml" -f "$T_REPO/infra/compose.prod.yml" config >/dev/null 2>&1 \
    && { fail "缺 MYSITE_IMAGE_LARAVEL 時 compose 竟可解析"; return 1; }
  echo "OK: 只用 digest 映像、只發布 80／443、TLS／ACME 唯讀、無原始碼掛載、模板依模式切換、缺映像即拒絕"
}

s05_workflows() {
  local wf note=""
  for wf in release deploy-staging deploy-production dependency-audit; do
    [[ -f "$T_REPO/.github/workflows/$wf.yml" ]] || fail "缺少 .github/workflows/$wf.yml" || return 1
  done
  python3 -I - "$T_REPO" <<'PY' || return 1
import re, sys
root = sys.argv[1]
pins = set(re.findall(r"`([0-9a-f]{40})`", open(f"{root}/docs/版本查核.md", encoding="utf-8").read()))
def chk(cond, msg):
    if not cond:
        print("FAIL: " + msg); sys.exit(1)
try:
    import yaml
except ImportError:
    yaml = None
for wf in ("release", "deploy-staging", "deploy-production", "dependency-audit"):
    text = open(f"{root}/.github/workflows/{wf}.yml", encoding="utf-8").read()
    if yaml:
        doc = yaml.safe_load(text)
        chk(isinstance(doc, dict) and "jobs" in doc, f"{wf}.yml 無法解析")
        chk(doc.get("permissions") == {"contents": "read"}, f"{wf}.yml 頂層 permissions 必須只有 contents: read")
        for jn, job in doc["jobs"].items():
            chk("permissions" in job, f"{wf}.yml job {jn} 必須明列 permissions")
            perms = job["permissions"]
            if perms.get("packages") == "write":
                chk(wf == "release" and jn == "build", f"packages: write 只能給 release.yml 的 build job（{wf}/{jn}）")
            if wf.startswith("deploy-"):
                chk(job.get("environment") == wf.split("-", 1)[1], f"{wf}.yml 必須綁定 environment")
    chk("pull_request_target" not in text, f"{wf}.yml 不得使用 pull_request_target")
    for use in re.findall(r"uses:\s*(\S+)", text):
        m = re.fullmatch(r"[\w.-]+/[\w.-]+@([0-9a-f]{40})", use)
        chk(m, f"{wf}.yml 的 action 未以完整 commit SHA 固定：{use}")
        chk(m.group(1) in pins, f"{wf}.yml 使用未記錄於 docs/版本查核.md 的 SHA：{use}")
    if wf.startswith("deploy-"):
        for s in ("secrets.RELEASE_SIGNING_KEY_ED25519", "secrets.DEPLOY_STATUS_TOKEN", "DEPLOYMENT_UNCONFIRMED"):
            chk(s in text, f"{wf}.yml 缺少 {s}")
print("OK" + ("" if yaml else "（環境無 PyYAML，只做文字層檢查）"))
PY
  if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
    local ref; ref="$(grep -E '^ACTIONLINT_IMAGE_REF=' "$T_REPO/infra/versions.env" | cut -d= -f2-)"
    if [[ -n "$ref" ]] && { docker image inspect "docker.io/$ref" >/dev/null 2>&1 || timeout 120 docker pull -q "docker.io/$ref" >/dev/null 2>&1; }; then
      docker run --rm -v "$T_REPO:/repo:ro" -w /repo "docker.io/$ref" -no-color \
        .github/workflows/release.yml .github/workflows/deploy-staging.yml .github/workflows/deploy-production.yml .github/workflows/dependency-audit.yml \
        || fail "actionlint（含 shellcheck）回報問題" || return 1
      note="；actionlint ${ref%%@*}（含 shellcheck）通過"
    else
      note="；actionlint 映像無法取得，未執行"
    fi
  else
    note="；無 Docker，actionlint 未執行"
  fi
  echo "OK: 4 個 workflow 結構、最小權限、environment 綁定與 SHA 固定檢查通過${note}"
}

s06_systemd() {
  local d f
  python3 -I - "$T_REPO" <<'PY' || return 1
import glob, os, re, sys
root = sys.argv[1]
def chk(cond, msg):
    if not cond:
        print("FAIL: " + msg); sys.exit(1)
units = sorted(glob.glob(f"{root}/infra/systemd/*.service") + glob.glob(f"{root}/infra/systemd/*.timer"))
names = {os.path.basename(u) for u in units}
for want in ("mysite-pull-release@.service", "mysite-pull-release@.timer", "mysite-backup-wp@.timer", "mysite-backup-app@.timer", "mysite-certbot-renew.timer"):
    chk(want in names, f"缺少 {want}")
for u in units:
    text = open(u, encoding="utf-8").read()
    if u.endswith(".service"):
        m = re.search(r"^ExecStart=/usr/bin/bash /srv/mysite/platform/(\S+)", text, re.M)
        chk(m and os.path.isfile(f"{root}/{m.group(1)}"), f"{os.path.basename(u)} 的 ExecStart 指向不存在的腳本")
        chk("Type=oneshot" in text and "TimeoutStartSec=" in text, f"{os.path.basename(u)} 必須 oneshot 且有逾時")
    else:
        chk(re.search(r"^(OnCalendar|OnUnitActiveSec)=", text, re.M), f"{os.path.basename(u)} 沒有排程")
        chk("[Install]" in text, f"{os.path.basename(u)} 缺 [Install]")
print("OK")
PY
  if command -v systemd-analyze >/dev/null 2>&1; then
    d="$T_ROOT/units"; mkdir -p "$d"
    for f in "$T_REPO"/infra/systemd/*.service "$T_REPO"/infra/systemd/*.timer; do
      f="$(basename "$f")"; cp "$T_REPO/infra/systemd/$f" "$d/${f/@./@staging.}"
    done
    ( cd "$d" && timeout 60 systemd-analyze verify ./* ) > "$T_ROOT/units-verify.log" 2>&1 || { cat "$T_ROOT/units-verify.log"; fail "systemd-analyze verify 失敗"; return 1; }
    [[ ! -s "$T_ROOT/units-verify.log" ]] || { cat "$T_ROOT/units-verify.log"; fail "systemd-analyze verify 有警告"; return 1; }
    echo "OK: unit 結構檢查＋systemd-analyze verify（以 staging 實例）通過"
  else
    echo "OK: unit 結構檢查通過（環境無 systemd-analyze，未做 verify）"
  fi
}

s07_gateway_login_limit() {
  # gateway 登入限流必須涵蓋 Laravel 會路由到同一處理程式的路徑變體（結尾斜線、/index.php/ 前綴別名）；
  # http（development／testing）與 https（staging／production）兩份模板必須一致。執行期行為另由 doctor GW-LOGIN-LIMIT 實測。
  python3 -I - "$T_REPO" <<'PY' || return 1
import glob, re, sys
root = sys.argv[1]
def chk(cond, msg):
    if not cond:
        print("FAIL: " + msg); sys.exit(1)
# nginx 以正規化後的 URI 比對 location（解碼 %XX、解析 . 與 ..、merge_slashes 合併連續斜線），關掉合併會讓 //admin/login 繞過
for f in glob.glob(f"{root}/infra/nginx/**/*", recursive=True):
    if f.endswith((".conf", ".template")):
        chk(not re.search(r"^\s*merge_slashes\s+off", open(f, encoding="utf-8").read(), re.M), f"{f} 不得關閉 merge_slashes")
APP_LIMITED = ["/admin/login", "/admin/login/", "/admin/two-factor-challenge", "/admin/two-factor-challenge/",
               "/admin/forgot-password", "/admin/forgot-password/", "/admin/reset-password", "/admin/reset-password/"]
APP_FREE = ["/admin", "/admin/", "/admin/logout", "/admin/loginx", "/admin/login/x", "/x/admin/login", "/admin/user/two-factor-authentication"]
def limited_matchers(block):
    ms = []
    for m in re.finditer(r"^\s*location\s+(=|~\*?)\s+(\S+)\s*\{[^\n]*\blimit_req\s+zone=mysite_login\b", block, re.M):
        op, pat = m.groups()
        ms.append((lambda p, pat=pat: p == pat) if op == "=" else (lambda p, pat=pat, op=op: re.search(pat, p, re.I if op == "~*" else 0) is not None))
    return ms
lines = {}
for mode in ("http", "https"):
    text = open(f"{root}/infra/nginx/gateway/{mode}/gateway.conf.template", encoding="utf-8").read()
    blocks = re.findall(r"^server \{\n(.*?)^\}", text, re.M | re.S)
    app = [b for b in blocks if "http://app-web:8080" in b]
    cms = [b for b in blocks if "http://wp-web:8081" in b]
    chk(len(app) == 1 and len(cms) == 1, f"{mode}：找不到唯一的主站／CMS server 區塊")
    app, cms = app[0], cms[0]
    am = limited_matchers(app)
    for p in APP_LIMITED:
        chk(any(f(p) for f in am), f"{mode}：主站 POST {p} 未套用 mysite_login 限流")
    for p in APP_FREE:
        chk(not any(f(p) for f in am), f"{mode}：主站 {p} 不應套用登入限流")
    # /index.php/<路徑> 會被 Laravel 視為 <路徑>（前端控制器別名），必須在 gateway 直接 404，否則可繞過以路徑為準的規則
    chk(re.search(r"^\s*location \^~ /index\.php/ \{ return 404; \}", app, re.M), f"{mode}：主站缺少 location ^~ /index.php/ {{ return 404; }}")
    chk(any(f("/wp-login.php") for f in limited_matchers(cms)), f"{mode}：CMS POST /wp-login.php 未套用 mysite_login 限流")
    lines[mode] = sorted(l.strip() for l in app.splitlines() if "mysite_login" in l or "/index.php/" in l)
chk(lines["http"] == lines["https"], "http 與 https 模板的登入限流／index.php 規則不一致")
print("OK")
PY
  echo "OK: http／https gateway 模板的登入限流涵蓋結尾斜線與 /index.php/ 別名、兩份一致、未關閉 merge_slashes"
}

case_add S01_syntax_and_safety GOV-04 s01_syntax
case_add S02_help_and_argument_validation GOV-04 s02_help_and_args
case_add S03_release_payload_schema_doc OPS-03,GOV-04 s03_schema_doc
case_add S04_compose_prod_contract OPS-04,GOV-04 s04_compose_prod
case_add S05_workflows_pinned_least_privilege OPS-04,GOV-04 s05_workflows
case_add S06_systemd_units GOV-04 s06_systemd
case_add S07_gateway_login_limit_paths GOV-04 s07_gateway_login_limit
