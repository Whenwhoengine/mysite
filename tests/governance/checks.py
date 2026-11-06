#!/usr/bin/env python3
"""治理與流程類驗收的實際檢查（CLD／EXEC／GOV／DAT 等無法由功能測試涵蓋的項目）。

每項檢查都讀實際檔案、Git 歷史、workflow 定義或執行一次腳本，結果寫成 JSONL（格式同 scripts/ci/verify.sh 的 record）。
只在檢查的前提成立時才記錄；前提不成立（例如不在 GitHub Actions）就不記錄，該 ID 維持 NOT_RUN，不以推測代替。
用法：tests/governance/checks.py [--results=<dir>]（exit 0 全部通過；30 有失敗；10 參數錯）
"""
import argparse
import hashlib
import json
import os
import platform
import re
import shutil
import subprocess
import sys
import tempfile
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
INITIAL_COMMIT = "66f8e54bde101111389045a6e64cfa52d716278e"  # 委託人建立 repo 時的第一個提交（只含 README.md）
RESULTS = []


def now():
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def record(test, ids, ok, message=""):
    RESULTS.append({"suite": "governance", "test": test, "ids": ids, "status": "PASS" if ok else "FAIL",
                    "message": message[:500], "started_at": now(), "finished_at": now()})
    print(f"  {'PASS' if ok else 'FAIL'}  {test:<40} {','.join(ids)}  {message[:160]}")


def git(*args, cwd=ROOT):
    return subprocess.run(["git", "-C", str(cwd), *args], capture_output=True, text=True)


def tracked(*patterns):
    out = git("ls-files", "-z", "--", *patterns).stdout
    return [ROOT / p for p in out.split("\0") if p]


def read(p):
    return Path(p).read_text(encoding="utf-8", errors="replace")


def load_yaml(p):
    import yaml

    class L(yaml.SafeLoader):
        pass

    L.add_multi_constructor("!", lambda loader, suffix, node: None)
    return yaml.load(read(p), Loader=L)


def steps():
    f = Path(os.environ.get("MYSITE_RESULTS_DIR", ROOT / ".runtime/results")) / "steps.jsonl"
    if not f.is_file():
        return {}
    out = {}
    for line in read(f).splitlines():
        try:
            d = json.loads(line)
            out[d["step"]] = d
        except (ValueError, KeyError):
            continue
    return out


def fresh_clone():
    """以本機 repo 建立全新 clone（模擬新會話／全新 runner 的工作目錄；不含 .runtime 與未追蹤檔）。"""
    tmp = Path(tempfile.mkdtemp(prefix="mysite-gov-"))
    dest = tmp / "repo"
    r = subprocess.run(["git", "clone", "-q", "--no-hardlinks", str(ROOT), str(dest)], capture_output=True, text=True, timeout=120)
    if r.returncode != 0:
        shutil.rmtree(tmp, ignore_errors=True)
        raise RuntimeError("無法建立暫存 clone：" + r.stderr[-200:])
    git("checkout", "-q", git("rev-parse", "HEAD").stdout.strip(), cwd=dest)
    origin = git("remote", "get-url", "origin").stdout.strip()
    if origin:  # 讓預檢的 repo 身分檢查看到與原工作目錄相同的遠端
        git("remote", "set-url", "origin", origin, cwd=dest)
    return tmp, dest


# ---------------------------------------------------------------------------
WINDOWS_PATTERNS = [
    (r"(?<![A-Za-z0-9])[A-Za-z]:\\\\[A-Za-z]", "Windows 磁碟路徑"),
    (r"\.ps1\b", "PowerShell 腳本"),
    (r"(?i)\bpowershell(\.exe)?\b", "PowerShell"),
    (r"(?i)\bcmd(\.exe)?\s+/c\b", "cmd.exe"),
    (r"(?i)\b[\w-]+\.bat\b", "批次檔"),
    (r"(?i)\bwsl(\.exe)?\s", "WSL"),
    (r"(?i)docker desktop", "Docker Desktop"),
    (r"(?i)\b(choco|winget)\s+install", "Windows 套件管理"),
]
CODE_GLOBS = ["*.sh", "*.py", "*.mjs", "*.js", "*.yml", "*.yaml", "*Dockerfile*", "*.conf", "*.template", "*.ini", "*.json", "*.php"]
CODE_EXCLUDE = ("docs/", "app/public/build/", "tests/governance/checks.py")


def check_cld01():
    """CLD-01：建置命令在 Linux 執行，程式與設定不依賴 Windows 路徑或工具。"""
    st = steps()
    if "bootstrap" not in st:
        return  # 本次沒有實際建置，不記錄
    hits = []
    for f in tracked(*CODE_GLOBS):
        rel = str(f.relative_to(ROOT))
        if rel.startswith(CODE_EXCLUDE) or "/vendor/" in rel or "node_modules" in rel or rel.endswith("package-lock.json") or rel.endswith("composer.lock"):
            continue
        text = read(f)
        for pat, label in WINDOWS_PATTERNS:
            m = re.search(pat, text)
            if m:
                hits.append(f"{rel}：{label}")
    ok = platform.system() == "Linux" and st["bootstrap"].get("exit_code") == 0 and not hits
    record("linux-only-build", ["CLD-01"], ok,
           f"系統 {platform.system()}；本次 bootstrap exit {st['bootstrap'].get('exit_code')}；Windows 依賴 {len(hits)} 處" + (f"：{hits[:5]}" if hits else ""))


def check_cld02():
    """CLD-02：保留委託人原 README 與 Git 歷史（初始提交仍是 HEAD 的祖先）。"""
    if git("rev-parse", "--is-shallow-repository").stdout.strip() == "true":
        record("readme-and-history-preserved", ["CLD-02"], False, "淺複製無法驗證完整歷史（CI 需 fetch-depth: 0）")
        return
    problems = []
    if git("cat-file", "-e", INITIAL_COMMIT + "^{commit}").returncode != 0:
        problems.append("找不到初始提交")
    elif git("merge-base", "--is-ancestor", INITIAL_COMMIT, "HEAD").returncode != 0:
        problems.append("初始提交不是 HEAD 的祖先（歷史被改寫或重建）")
    else:
        roots = git("rev-list", "--max-parents=0", "HEAD").stdout.split()
        if roots != [INITIAL_COMMIT]:
            problems.append(f"根提交異常：{roots}")
        original = git("show", f"{INITIAL_COMMIT}:README.md").stdout
        current = read(ROOT / "README.md") if (ROOT / "README.md").is_file() else ""
        if not original.strip() or not current.startswith(original.rstrip("\n")):
            problems.append("README.md 開頭不再保留原內容")
    count = git("rev-list", "--count", "HEAD").stdout.strip()
    record("readme-and-history-preserved", ["CLD-02"], not problems, "；".join(problems) or f"初始提交 {INITIAL_COMMIT[:12]} 為祖先；README 原文保留；歷史 {count} 個提交")


def check_cld03():
    """CLD-03：程式與狀態檔已在遠端；本 run 由遠端同一 commit 觸發，另一個會話可讀到。"""
    if os.environ.get("GITHUB_ACTIONS") != "true":
        return
    head = git("rev-parse", "HEAD").stdout.strip()
    sha = os.environ.get("GITHUB_SHA", "")
    problems = []
    if os.environ.get("GITHUB_EVENT_NAME") == "push" and sha != head:
        problems.append(f"checkout {head[:12]} 與觸發 commit {sha[:12]} 不同")
    for rel in ("docs/state/status.json", "docs/state/tasks.json", "docs/state/blockers.md", "docs/state/next-step.md"):
        if git("cat-file", "-e", f"HEAD:{rel}").returncode != 0:
            problems.append(f"遠端 commit 缺 {rel}")
    try:
        status = json.loads(read(ROOT / "docs/state/status.json"))
        ref = os.environ.get("GITHUB_REF_NAME", "")
        # 工作分支合併進預設分支後，main 上的 state 仍記錄原工作分支，屬正常；其他分支則必須一致
        if os.environ.get("GITHUB_EVENT_NAME") == "push" and ref and status.get("working_branch") not in (ref, "main") and ref != "main":
            problems.append(f"status.json working_branch={status.get('working_branch')} 與遠端分支 {ref} 不同")
    except ValueError:
        problems.append("status.json 無法解析")
    record("pushed-state-readable", ["CLD-03"], not problems, "；".join(problems) or f"遠端 {os.environ.get('GITHUB_REF_NAME')}@{head[:12]} 含程式與 state 檔")


def check_cld06():
    """CLD-06：全新 GitHub-hosted runner 依 lockfile 重建；不依賴未宣告的快取。"""
    if os.environ.get("GITHUB_ACTIONS") != "true" or os.environ.get("RUNNER_ENVIRONMENT", "github-hosted") != "github-hosted":
        return
    st = steps()
    if "bootstrap" not in st:
        return
    problems = []
    if st["bootstrap"].get("exit_code") != 0:
        problems.append("bootstrap 失敗")
    for rel in ("app/composer.lock", "app/package-lock.json", "tests/e2e/package-lock.json"):
        if git("ls-files", "--error-unmatch", rel).returncode != 0:
            problems.append(f"{rel} 未版控")
    docker = read(ROOT / "infra/images/php/Dockerfile")
    code = "\n".join(line for line in docker.splitlines() if not line.lstrip().startswith("#"))
    if re.search(r"composer\s+update", code):
        problems.append("Dockerfile 使用 composer update（未依 lockfile）")
    if re.search(r"\bnpm\s+(install|i)\b", code):
        problems.append("Dockerfile 使用 npm install（應為 npm ci）")
    if "composer install" not in code or "npm ci" not in code:
        problems.append("Dockerfile 未見 composer install／npm ci")
    for wf in tracked(".github/workflows/*.yml"):
        text = read(wf)
        if re.search(r"uses:\s*actions/cache@", text) or re.search(r"^\s+cache:\s*\S", text, re.M):
            problems.append(f"{wf.name} 使用快取")
    composer = json.loads(read(ROOT / "app/composer.json"))
    if not (composer.get("config", {}).get("platform", {}).get("php")):
        problems.append("composer.json 未固定 platform.php")
    record("fresh-runner-lockfile-rebuild", ["CLD-06"], not problems, "；".join(problems) or f"GitHub-hosted runner（{os.environ.get('RUNNER_OS')}／{os.environ.get('RUNNER_ARCH')}）依 lockfile 重建成功；無快取")


def run_script(dest, args, env_extra=None, timeout=90):
    env = dict(os.environ)
    env.update(env_extra or {})
    return subprocess.run(["bash", *args], cwd=dest, capture_output=True, text=True, timeout=timeout, env=env)


def check_session_scripts():
    """CLD-07、EXEC-01、EXEC-02、CLD-09、CLD-08：在全新 clone 上實際執行會話啟動與預檢。"""
    try:
        tmp, dest = fresh_clone()
    except (RuntimeError, subprocess.TimeoutExpired) as e:
        for t, ids in (("state-resume-in-fresh-clone", ["CLD-07"]), ("first-run-no-missing-files", ["EXEC-02"])):
            record(t, ids, False, str(e))
        return
    try:
        status = json.loads(read(dest / "docs/state/status.json"))
        tasks = {t["id"]: t for t in json.loads(read(dest / "docs/state/tasks.json"))["tasks"]}
        # --- CLD-07：新會話依 state 可接續正確未完成任務
        r = run_script(dest, ["scripts/cloud/session-start.sh"])
        problems = []
        if r.returncode != 0:
            problems.append(f"session-start exit {r.returncode}")
        active = status.get("active_task_id")
        if not active or active not in tasks:
            problems.append(f"active_task_id {active} 不在 tasks.json")
        elif tasks[active]["status"] in ("VERIFIED",):
            problems.append(f"active_task {active} 已是 VERIFIED（不是未完成任務）")
        if active and f"active_task: {active}" not in r.stdout:
            problems.append("session-start 未輸出 active_task")
        if status.get("next_action") and status["next_action"][:20] not in r.stdout:
            problems.append("session-start 未輸出 next_action")
        nxt = read(dest / "docs/state/next-step.md") if (dest / "docs/state/next-step.md").is_file() else ""
        if active and active not in nxt:
            problems.append("next-step.md 未提到 active_task_id")
        chk = subprocess.run([sys.executable, "scripts/ci/check-state.py"], cwd=dest, capture_output=True, text=True, timeout=60)
        if chk.returncode != 0:
            problems.append("check-state 失敗：" + chk.stdout[-200:])
        record("state-resume-in-fresh-clone", ["CLD-07"], not problems, "；".join(problems) or f"全新 clone 執行 session-start 正確輸出 active_task {active} 與 next_action；check-state 通過")

        # --- EXEC-02：首次雲端腳本不依賴尚未存在的檔案、不形成 setup 失敗循環
        problems = []
        if r.returncode != 0:
            problems.append(f"全新 clone 的 session-start exit {r.returncode}")
        if "尚未 bootstrap" not in r.stdout and "改走 GitHub Actions" not in r.stdout:
            problems.append("全新 clone 未給出 bootstrap／Actions 指引")
        settings = json.loads(read(dest / ".claude/settings.json"))
        for group in settings.get("hooks", {}).get("SessionStart", []):
            for h in group.get("hooks", []):
                cmd = h.get("command", "")
                m = re.search(r"scripts/[\w/.-]+\.sh", cmd)
                if not m or not (dest / m.group(0)).is_file():
                    problems.append(f"SessionStart hook 指向不存在的腳本：{cmd}")
                if not isinstance(h.get("timeout"), int) or h["timeout"] > 300:
                    problems.append("SessionStart hook 缺少或過長的 timeout")
                if "|| true" in cmd:
                    problems.append("SessionStart hook 以 || true 吞錯")
        for ln in read(dest / "scripts/cloud/session-start.sh").splitlines():
            if "bootstrap.sh" in ln and "echo" not in ln and not ln.lstrip().startswith("#"):
                problems.append("session-start 直接呼叫 bootstrap（hook 內不應建置）")
        pre = run_script(dest, ["scripts/cloud/preflight.sh", "--help"])
        if pre.returncode != 0:
            problems.append("preflight --help 失敗")
        record("first-run-no-missing-files", ["EXEC-02"], not problems, "；".join(problems) or "全新 clone（無 .runtime）執行 SessionStart 腳本 exit 0 並指引 bootstrap；hook 腳本存在且有 timeout")

        # --- EXEC-01：v5 原文完整性；缺原文時誠實記錄、不偽造
        problems = []
        plan_rel = status.get("source_plan_file")
        if not plan_rel or not (dest / plan_rel).is_file():
            problems.append("repo 中沒有規劃書原文")
        else:
            # ADR-015：原件可帶行內修訂註記；去掉註記後的雜湊必須等於委託人原件（scripts/lib/plan_digest.py）。
            dg = subprocess.run([sys.executable, "scripts/lib/plan_digest.py", plan_rel], cwd=dest, capture_output=True, text=True, timeout=30)
            if dg.returncode != 0 or dg.stdout.strip() != status.get("plan_sha256"):
                problems.append("規劃書去掉修訂註記後的 SHA-256 與紀錄不符")
            if "（SHA-256 與紀錄相符）" not in r.stdout:
                problems.append("session-start 未回報規劃書完整性")
            plan_orig = (dest / plan_rel).read_bytes()
            # 只在暫存 clone 內：多加一則合規註記仍通過；註記外多一個字、引用不存在的 ADR、
            # 或用 Markdown／HTML 符號藏字（例如 <!--）都必須失敗。
            cases = [
                ("〔ADR-015 修訂註記（2026-11-06）：治理測試，只在暫存 clone〕", True, "合規的行內修訂註記"),
                ("竄", False, "註記以外改一個字"),
                ("〔ADR-999 修訂註記（2026-11-06）：引用不存在的決策〕", False, "引用不存在的 ADR"),
                ("〔ADR-015 修訂註記（2026-11-06）：<!--〕", False, "註記內含 <!-- 可藏住後文"),
            ]
            for suffix, should_pass, label in cases:
                (dest / plan_rel).write_bytes(plan_orig + suffix.encode("utf-8"))
                chk_case = subprocess.run([sys.executable, "scripts/ci/check-state.py"], cwd=dest, capture_output=True, text=True, timeout=60)
                if (chk_case.returncode == 0) != should_pass:
                    problems.append(f"{label}：check-state {'失敗' if should_pass else '卻通過'}")
            (dest / plan_rel).write_bytes(plan_orig)
            (dest / plan_rel).unlink()  # 只在暫存 clone 內模擬「repo 缺 v5」
            r2 = run_script(dest, ["scripts/cloud/session-start.sh"])
            if r2.returncode != 0 or "規劃書原文不在 repo" not in r2.stdout or "docs/執行契約.md" not in r2.stdout:
                problems.append("缺 v5 時 session-start 未誠實說明並指向內嵌契約")
            chk2 = subprocess.run([sys.executable, "scripts/ci/check-state.py"], cwd=dest, capture_output=True, text=True, timeout=60)
            if chk2.returncode == 0:
                problems.append("缺 v5 但 status.json 仍宣稱有原文，check-state 卻通過（未誠實記錄）")
            if not (dest / "docs/執行契約.md").is_file():
                problems.append("缺少內嵌契約 docs/執行契約.md")
        record("plan-integrity-and-missing-plan-path", ["EXEC-01"], not problems, "；".join(problems) or "v5 原文（去掉行內修訂註記後）SHA-256 相符；合規註記通過，註記外竄改一字、引用不存在的 ADR、註記內藏 <!-- 皆失敗；模擬缺 v5 時 session-start 誠實說明並指向執行契約，check-state 拒絕把缺原文當成有原文")

        # --- CLD-09、CLD-08：Docker 不可用時給出確切錯誤並導向 Actions，不吞錯
        nodocker = {"DOCKER_HOST": "unix:///nonexistent/mysite-gov-docker.sock"}
        v = run_script(dest, ["scripts/ci/verify.sh", "--scope=content", "--steps=collect"], nodocker, timeout=120)
        p = run_script(dest, ["scripts/cloud/preflight.sh"], nodocker, timeout=120)
        problems = []
        if v.returncode != 21:
            problems.append(f"verify.sh 在 Docker 不可用時 exit {v.returncode}（應為 21）")
        if "sandbox.yml" not in (v.stdout + v.stderr):
            problems.append("verify.sh 未指出改用 Actions sandbox.yml")
        if p.returncode not in (20, 21):
            problems.append(f"preflight 在 Docker 不可用時 exit {p.returncode}（應為 20／21）")
        if "Docker" not in (p.stdout + p.stderr):
            problems.append("preflight 未說明 Docker 不可用")
        record("docker-unavailable-routes-to-actions", ["CLD-08", "CLD-09"], not problems,
               "；".join(problems) or "Docker 不可用時 verify exit 21 並指向 sandbox.yml、preflight 回報確切原因")
    finally:
        shutil.rmtree(tmp, ignore_errors=True)


INSTALL_RE = re.compile(r"(apt-get\s+(-\S+\s+)*install|apt\s+install|composer\s+(install|require)|npm\s+(ci|install)|pip3?\s+install|docker\s+(build|pull)|pecl\s+install|phpize|make\s+install|curl\s[^|]*-o\s|sha256sum\s+-c|playwright\s+install)")


def check_cld09_static():
    """CLD-09：必要安裝與下載步驟不得以 || true 吞錯。"""
    hits = []
    files = tracked("scripts/**/*.sh", "scripts/*.sh", "infra/**/*.sh", "infra/**/Dockerfile", ".github/workflows/*.yml", "tests/**/run.sh")
    for f in files:
        lines = read(f).splitlines()
        joined, buf = [], ""
        for ln in lines:  # 合併以反斜線續行的指令
            buf += ln.rstrip("\\") + " "
            if not ln.rstrip().endswith("\\"):
                joined.append(buf)
                buf = ""
        for cmd in joined:
            if cmd.lstrip().startswith("#"):
                continue
            if "%{http_code}" in cmd:  # HTTP 狀態探測：結果碼由後續程式判斷，不是安裝或下載
                continue
            if INSTALL_RE.search(cmd) and re.search(r"\|\|\s*(true|:)\b", cmd):
                hits.append(f"{f.relative_to(ROOT)}：{cmd.strip()[:80]}")
    record("no-swallowed-install-errors", ["CLD-09"], not hits, f"{len(files)} 個腳本／Dockerfile／workflow；吞錯 {len(hits)} 處" + (f"：{hits[:3]}" if hits else ""))


def check_cld10():
    """CLD-10：PR／fork 觸發的 workflow 拿不到正式秘密、簽章或部署權限。"""
    problems = []
    for wf in tracked(".github/workflows/*.yml"):
        d = load_yaml(wf)
        on = d.get(True, d.get("on")) or {}
        triggers = set(on) if isinstance(on, dict) else ({on} if isinstance(on, str) else set(on))
        if "pull_request_target" in triggers:
            problems.append(f"{wf.name} 使用 pull_request_target")
        pr = "pull_request" in triggers
        top_perm = d.get("permissions")
        for name, job in (d.get("jobs") or {}).items():
            text = json.dumps(job, ensure_ascii=False)
            secrets = sorted(set(re.findall(r"secrets\.([A-Z0-9_]+)", text)) - {"GITHUB_TOKEN"})
            perm = job.get("permissions", top_perm) or {}
            writes = sorted(k for k, v in (perm.items() if isinstance(perm, dict) else []) if v == "write") if isinstance(perm, dict) else (["all"] if perm == "write-all" else [])
            env = job.get("environment")
            env_name = env.get("name") if isinstance(env, dict) else env
            if secrets and env_name not in ("staging", "production"):
                problems.append(f"{wf.name}:{name} 在受保護 environment 外使用秘密 {secrets}")
            if pr and (secrets or writes or env_name):
                problems.append(f"{wf.name}:{name} 可由 pull_request 觸發卻有秘密／寫入權限／environment")
            if env_name in ("staging", "production") and not triggers <= {"workflow_dispatch"}:
                problems.append(f"{wf.name}:{name} 部署 job 可被 {sorted(triggers)} 觸發")
            if "id-token" in (perm if isinstance(perm, dict) else {}) and pr:
                problems.append(f"{wf.name}:{name} PR 可取得 OIDC token")
        if pr and top_perm not in ({"contents": "read"}, "read-all", {}):
            problems.append(f"{wf.name} PR workflow 預設權限過寬：{top_perm}")
    record("workflow-secret-isolation", ["CLD-10"], not problems, "；".join(problems[:5]) or "PR／fork 只跑唯讀 CI；秘密只在 workflow_dispatch＋受保護 environment 的部署 job")


def md_tables(text):
    rows = []
    for ln in text.splitlines():
        if ln.startswith("|") and not re.match(r"^\|[\s:|-]+\|$", ln):
            rows.append([c.strip() for c in ln.strip().strip("|").split("|")])
    return rows


def check_dat01():
    """DAT-01：資料清單涵蓋每張自建資料表，且每列都有目的、欄位、保存、接收者與存取權。"""
    doc = ROOT / "docs/資料清單.md"
    if not doc.is_file():
        record("data-inventory-complete", ["DAT-01"], False, "缺 docs/資料清單.md")
        return
    text = read(doc)
    problems = []
    tables = set()
    for f in tracked("app/database/migrations/*.php"):
        tables |= set(re.findall(r"Schema::create\('([a-z_]+)'", read(f)))
    for t in sorted(tables):
        if f"`{t}`" not in text:
            problems.append(f"未列 Laravel 表 {t}")
    setup = ROOT / "wp/plugins/site-content/includes/setup.php"
    for t in sorted(set(re.findall(r"self::table\(\s*'([a-z_]+)'\s*\)", read(setup)))):
        if f"wp_site_content_{t}" not in text:
            problems.append(f"未列 WordPress 表 wp_site_content_{t}")
    header = None
    checked = 0
    for row in md_tables(text):
        if "目的" in row and any("保存" in c for c in row) and "接收者" in row and "存取權" in row:
            header = row
            continue
        if header and len(row) == len(header):
            checked += 1
            for col, cell in zip(header, row):
                if col in ("目的", "接收者", "存取權") or "保存" in col or col == "欄位":
                    if not cell or cell in ("-", "—", "TBD"):
                        problems.append(f"「{row[0]}」缺 {col}")
        elif header and row and len(row) != len(header):
            header = None if not any("目的" in c for c in row) else header
    if checked < len(tables):
        problems.append(f"資料列 {checked} 少於自建表 {len(tables)}")
    record("data-inventory-complete", ["DAT-01"], not problems, "；".join(problems[:6]) or f"{len(tables)} 張 Laravel 表與 WordPress 自建表皆列入；{checked} 列五要素齊全")


def check_dat03():
    """DAT-03：查詢／刪除申請依類型分流期限，不一律 30 天；法規生效狀態標待核對。"""
    text = read(ROOT / "docs/資料清單.md") if (ROOT / "docs/資料清單.md").is_file() else ""
    m = re.search(r"##[^\n]*DAT-03[^\n]*\n(.*?)(?=\n## |\Z)", text, re.S)
    problems = []
    if not m:
        problems.append("資料清單沒有 DAT-03 申請流程章節")
    else:
        sec = m.group(1)
        if not re.search(r"(查詢|閱覽|複製本)[^\n]{0,40}15\s*日", sec):
            problems.append("未載明查詢／閱覽／複製本 15 日")
        if not re.search(r"(更正|停止|刪除)[^\n]{0,40}30\s*日", sec):
            problems.append("未載明更正／停止／刪除 30 日")
        if re.search(r"一律[^\n]{0,6}30\s*[天日]", sec.replace("不套用單一天數", "")):
            problems.append("出現一律 30 天的寫法")
        if "待核對" not in sec:
            problems.append("未標示法規生效狀態待核對")
    record("rights-request-deadlines", ["DAT-03"], not problems, "；".join(problems) or "查詢類 15 日、更正／刪除類 30 日分流；法規生效狀態標待核對")


def check_exec04():
    """EXEC-04：已匯入的 CI 證據可追溯 run ID、受測 SHA、lockfile 與結果；報告提交與受測提交分開。"""
    summaries = [p for p in tracked("docs/evidence/*/summary.json")]
    if not summaries:
        return
    if git("rev-parse", "--is-shallow-repository").stdout.strip() == "true":
        record("imported-evidence-traceable", ["EXEC-04"], False, "淺複製無法追溯受測 commit")
        return
    problems = []
    for s in summaries:
        rel = str(s.relative_to(ROOT))
        d = json.loads(read(s))
        run_dir = s.parent.name
        commit = d.get("source_commit", "")
        if d.get("run_id") != run_dir:
            problems.append(f"{rel} run_id 與目錄不符")
        if run_dir.startswith("gha-") and str((d.get("github") or {}).get("GITHUB_RUN_ID")) != run_dir.split("-")[1]:
            problems.append(f"{rel} GITHUB_RUN_ID 不符")
        if not re.fullmatch(r"[0-9a-f]{40}", commit) or git("merge-base", "--is-ancestor", commit, "HEAD").returncode != 0:
            problems.append(f"{rel} 受測 commit 不是 HEAD 的祖先")
            continue
        for lock in ("app/composer.lock", "app/package-lock.json", "tests/e2e/package-lock.json"):
            if git("cat-file", "-e", f"{commit}:{lock}").returncode != 0:
                problems.append(f"{rel} 受測 commit 缺 {lock}")
        added = git("log", "--diff-filter=A", "--format=%H", "--", rel).stdout.split()
        if not added:
            problems.append(f"{rel} 找不到加入它的提交")
        elif commit in added:
            problems.append(f"{rel} 與受測程式在同一提交（報告未分開）")
        if not (s.parent / "results.jsonl").is_file() or not d.get("acceptance") or not d.get("steps"):
            problems.append(f"{rel} 缺測試結果")
        locks = d.get("lockfiles") or {}
        for lock, digest in locks.items():
            blob = subprocess.run(["git", "-C", str(ROOT), "show", f"{commit}:{lock}"], capture_output=True).stdout
            if hashlib.sha256(blob).hexdigest() != digest:
                problems.append(f"{rel} 的 {lock} hash 與受測 commit 不符")
    record("imported-evidence-traceable", ["EXEC-04"], not problems, "；".join(problems[:5]) or f"{len(summaries)} 份匯入證據皆可追溯 run、受測 commit 與 lockfile，且報告提交與受測提交分開")


def check_exec05():
    """EXEC-05：缺主機只阻擋 M9／M10；內容、商店模擬、部署工具與 CI 測試照常執行。"""
    st = steps()
    if not st:
        return
    problems = []
    status = json.loads(read(ROOT / "docs/state/status.json"))
    for m, s in status.get("milestones", {}).items():
        if s == "BLOCKED_EXTERNAL" and not m.startswith(("M9", "M10")):
            problems.append(f"{m} 被標為外部阻擋")
    reg = json.loads(read(ROOT / "tests/acceptance/registry.json"))
    for it in reg["items"]:
        if (it["status"] == "BLOCKED_EXTERNAL" or it.get("blocker_id")) and it.get("verification_venue") == "ci":
            problems.append(f"{it['id']}（CI 可驗）被標外部阻擋")
    for t in json.loads(read(ROOT / "docs/state/tasks.json"))["tasks"]:
        if t["status"] == "BLOCKED_EXTERNAL" and not t["milestone"].startswith(("M9", "M10")):
            problems.append(f"任務 {t['id']}（{t['milestone']}）被標外部阻擋")
    for name in ("bootstrap", "phpunit", "wp-contract", "deploy", "e2e"):
        s = st.get(name)
        if not s or str(s.get("command", "")).startswith("skipped"):
            problems.append(f"本次未執行 {name}")
    record("external-blockers-scoped", ["EXEC-05"], not problems, "；".join(problems[:5]) or "外部阻擋只落在 M9／M10 與 staging／production 驗收；本次 bootstrap、PHPUnit、WP 契約、部署工具、E2E 皆實際執行")


def check_exec06():
    """EXEC-06：trace／artifact／bootstrap-source 不帶 Cookie、票券或金鑰。"""
    problems = []
    cfg = read(ROOT / "tests/e2e/playwright.config.mjs")
    if not re.search(r"trace:\s*'off'", cfg) or not re.search(r"video:\s*'off'", cfg):
        problems.append("Playwright trace／video 未關閉")
    # 實際比對：本次 testing 環境的每個秘密值不得出現在任何結果、證據或截圖檔（逐位元組搜尋）
    secret_dir = ROOT / ".runtime/secrets/testing"
    values = []
    if secret_dir.is_dir():
        for f in sorted(secret_dir.iterdir()):
            if not f.is_file():
                continue
            v = f.read_bytes().strip()
            if v.startswith(b"-----BEGIN"):  # PEM：以最長的一行 base64 內容比對
                body = [ln.strip() for ln in v.splitlines() if ln.strip() and not ln.startswith(b"-----")]
                v = max(body, key=len) if body else b""
            if len(v) >= 12:
                values.append((f.name, v))
    scanned = 0
    leak = []
    results_dir = Path(os.environ.get("MYSITE_RESULTS_DIR", ROOT / ".runtime/results"))
    for base in (results_dir, ROOT / ".runtime/evidence", ROOT / "docs/evidence", ROOT / "tests/e2e/screenshots", ROOT / "tests/e2e/test-results"):
        if not base.is_dir():
            continue
        for f in base.rglob("*"):
            if f.is_file() and f.stat().st_size < 50_000_000:
                data = f.read_bytes()
                scanned += 1
                for name, v in values:
                    if v and v in data:
                        leak.append(f"{f.relative_to(ROOT)} 含 {name}")
    if not values:
        problems.append("找不到 testing 秘密可比對（未在 testing 環境執行）")
    problems += leak
    # artifact 上傳路徑不得包含秘密目錄或 env 檔
    for wf in tracked(".github/workflows/*.yml"):
        for m in re.finditer(r"uses:\s*actions/upload-artifact@\S+[\s\S]*?path:\s*([|>]?[^\n]*(?:\n\s{10,}[^\n]+)*)", read(wf)):
            if re.search(r"secrets|\.env\b|\.runtime/env", m.group(1)):
                problems.append(f"{wf.name} 上傳路徑含秘密或 env：{m.group(1).strip()[:60]}")
    sb = read(ROOT / ".github/workflows/sandbox.yml")
    m = re.search(r"cp ([^\n]+) bootstrap-source/", sb)
    if not m or set(m.group(1).split()) - {"app/composer.json", "app/composer.lock", "app/package.json", "app/package-lock.json"}:
        problems.append("bootstrap-source 成品不只含第一方 lockfile")
    if "SHA256SUMS" not in sb or "source_commit" not in sb:
        problems.append("bootstrap-source 缺來源 commit 或 hash 清單")
    if re.search(r"secrets\.", sb):
        problems.append("sandbox.yml 使用秘密")
    record("artifact-secret-hygiene", ["EXEC-06", "DAT-02"], not problems,
           "；".join(problems[:5]) or f"{len(values)} 個 testing 秘密值逐位元組比對 {scanned} 個結果／證據／截圖檔：0 命中；trace／video 關閉；bootstrap-source 只含 lockfile＋SHA256SUMS＋來源 commit")


def check_gov05():
    """GOV-05：版本、授權、法律與費用未知項明標待查；登錄表 PASS 都有存在的證據。"""
    problems = []
    versions = read(ROOT / "infra/versions.env")
    doc = read(ROOT / "docs/版本查核.md")
    for k, v in re.findall(r"^([A-Z0-9_]+_VERSION)=(\S+)$", versions, re.M):
        if v not in doc:
            problems.append(f"{k}={v} 未記查核來源")
    for k, v in re.findall(r"^([A-Z0-9_]+_IMAGE_REF)=(\S+)$", versions, re.M):
        digest = v.split("@sha256:")[-1]
        if "@sha256:" not in v or digest[:8] not in doc:
            problems.append(f"{k} digest 未記查核來源")
    header = None
    for row in md_tables(doc):
        if "授權" in row:
            header = row
            continue
        if header and len(row) == len(header):
            lic = row[header.index("授權")]
            if not lic:
                problems.append(f"版本查核「{row[0]}」授權欄空白（未知應寫待查）")
    reg = json.loads(read(ROOT / "tests/acceptance/registry.json"))
    for it in reg["items"]:
        if it["status"] == "PASS":
            ev = it.get("evidence") or []
            if not ev or not all((ROOT / e).is_file() for e in ev):
                problems.append(f"{it['id']} 標 PASS 但證據檔不存在")
        if it["status"] == "NOT_APPLICABLE" and not it.get("note"):
            problems.append(f"{it['id']} 標不適用但無理由")
    blockers = read(ROOT / "docs/state/blockers.md")
    if not re.search(r"費用|月費", blockers):
        problems.append("外部阻擋未標示費用待核准")
    if "待核對" not in read(ROOT / "docs/資料清單.md"):
        problems.append("法規生效狀態未標待核對")
    record("unknowns-marked-no-fabrication", ["GOV-05"], not problems, "；".join(problems[:5]) or "全部版本與 digest 有查核來源與授權欄；費用與法規未定項標待核准／待核對；登錄表 PASS 皆有存在的證據檔")


def main():
    ap = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    ap.add_argument("--results", default=os.environ.get("MYSITE_RESULTS_DIR", str(ROOT / ".runtime/results")))
    args = ap.parse_args()
    out = Path(args.results)
    out.mkdir(parents=True, exist_ok=True)
    print("governance checks：")
    for fn in (check_cld01, check_cld02, check_cld03, check_cld06, check_session_scripts, check_cld09_static, check_cld10,
               check_dat01, check_dat03, check_exec04, check_exec05, check_exec06, check_gov05):
        try:
            fn()
        except Exception as e:  # 檢查程式本身出錯也要留下確切錯誤，不當成通過
            record(fn.__name__.replace("check_", "") + "-error", [], False, f"{type(e).__name__}: {e}")
    with (out / "governance.jsonl").open("a", encoding="utf-8") as fh:
        for r in RESULTS:
            fh.write(json.dumps(r, ensure_ascii=False) + "\n")
    failed = [r for r in RESULTS if r["status"] == "FAIL"]
    print(f"governance：{len(RESULTS) - len(failed)} PASS、{len(failed)} FAIL")
    sys.exit(30 if failed else 0)


if __name__ == "__main__":
    main()
