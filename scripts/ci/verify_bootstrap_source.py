#!/usr/bin/env python3
"""核對 sandbox.yml 的 bootstrap-source 成品並把結果記為 EXEC-03 證據。

檢查：
  - manifest.json 的 repository、run_id 與 --gh-run 相符，source_commit 是本地歷史中 HEAD 的祖先；
  - SHA256SUMS 與實際檔案一致，成品只含第一方 lockfile 白名單、manifest 與 SHA256SUMS；
  - 成品不含秘密樣式（沿用 scripts/ci/secret-scan.sh）；
  - 重新解析出的 lockfile 與受測 commit 中已提交的版本逐位元組相同（相同即代表 repo 內的 lockfile 可重現，無需另行取回）；
    不同時列出差異檔案，交由人工審閱後才可提交，不自動覆寫。
結果寫入 docs/evidence/bootstrap-source-<gh-run>/check.json，並更新 tests/acceptance/registry.json 的 EXEC-03。
用法：verify_bootstrap_source.py --dir=<解壓後的 artifact 目錄> --gh-run=<數字>
exit code：0 通過；10 參數錯；30 檢查失敗；40 來源不符
"""
import argparse
import hashlib
import json
import re
import shutil
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
ALLOWED = {"composer.json", "composer.lock", "package.json", "package-lock.json", "manifest.json", "SHA256SUMS"}
REPO_PATH = {"composer.json": "app/composer.json", "composer.lock": "app/composer.lock",
             "package.json": "app/package.json", "package-lock.json": "app/package-lock.json"}


def git(*args):
    return subprocess.run(["git", "-C", str(ROOT), *args], capture_output=True)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--dir", required=True)
    ap.add_argument("--gh-run", required=True)
    a = ap.parse_args()
    src = Path(a.dir)
    if not a.gh_run.isdigit() or not src.is_dir():
        print("參數錯誤", file=sys.stderr)
        sys.exit(10)
    problems = []
    files = sorted(p.name for p in src.iterdir() if p.is_file())
    extra = sorted(set(files) - ALLOWED)
    if extra or any(p.is_dir() for p in src.iterdir()):
        problems.append(f"成品含白名單外的檔案或目錄：{extra}")
    manifest = json.loads((src / "manifest.json").read_text(encoding="utf-8"))
    if manifest.get("repository") != "Whenwhoengine/mysite" or str(manifest.get("run_id")) != a.gh_run:
        print("manifest 的 repository 或 run_id 不符", file=sys.stderr)
        sys.exit(40)
    commit = manifest.get("source_commit", "")
    if not re.fullmatch(r"[0-9a-f]{40}", commit) or git("merge-base", "--is-ancestor", commit, "HEAD").returncode != 0:
        print("source_commit 不是目前 HEAD 的祖先", file=sys.stderr)
        sys.exit(40)
    sums = {}
    for line in (src / "SHA256SUMS").read_text(encoding="utf-8").splitlines():
        m = re.fullmatch(r"([0-9a-f]{64})\s+\*?\./(\S+)", line.strip())
        if m:
            sums[m.group(2)] = m.group(1)
    # sandbox.yml 先產生 SHA256SUMS（只涵蓋 lockfile 與 manifest 以外的成品）再寫 manifest.json，所以 manifest 不在清單內
    for name in REPO_PATH:
        if name not in sums:
            problems.append(f"SHA256SUMS 未列出 {name}")
    for name in files:
        if name in ("SHA256SUMS", "manifest.json"):
            continue
        digest = hashlib.sha256((src / name).read_bytes()).hexdigest()
        if sums.get(name) != digest:
            problems.append(f"{name} 與 SHA256SUMS 不符")
    if subprocess.run(["bash", str(ROOT / "scripts/ci/secret-scan.sh"), f"--path={src}"], capture_output=True).returncode != 0:
        problems.append("成品疑似含秘密")
    compare = {}
    for name, rel in REPO_PATH.items():
        committed = git("show", f"{commit}:{rel}").stdout
        regenerated = (src / name).read_bytes() if (src / name).is_file() else b""
        compare[rel] = "identical" if committed == regenerated else "different"
    differ = [k for k, v in compare.items() if v != "identical"]
    ok = not problems and not differ
    out_dir = ROOT / "docs/evidence" / f"bootstrap-source-{a.gh_run}"
    out_dir.mkdir(parents=True, exist_ok=True)
    for name in ("manifest.json", "SHA256SUMS"):
        shutil.copyfile(src / name, out_dir / name)
    result = {
        "schema_version": 1, "gh_run": a.gh_run, "source_commit": commit, "arch": manifest.get("arch"),
        "checked_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "files": files, "lockfile_comparison": compare, "problems": problems,
        "status": "PASS" if ok else "FAIL",
        "note": "lockfile 由 sandbox.yml 在乾淨 runner 以 PHP 8.5.12 與鎖定 Node 映像重新解析；逐位元組與已提交版本比對。",
    }
    (out_dir / "check.json").write_text(json.dumps(result, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    reg_path = ROOT / "tests/acceptance/registry.json"
    reg = json.loads(reg_path.read_text(encoding="utf-8"))
    for item in reg["items"]:
        if item["id"] == "EXEC-03":
            item["status"] = "PASS" if ok else "FAIL"
            item["evidence"] = [f"docs/evidence/bootstrap-source-{a.gh_run}/check.json"]
            item["tests"] = ["sandbox:bootstrap-source", "verify_bootstrap_source"]
            item["note"] = None if ok else "bootstrap-source 檢查失敗，見 check.json"
    reg_path.write_text(json.dumps(reg, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"bootstrap-source 檢查：{result['status']}；lockfile 比對 {compare}；問題 {problems}")
    sys.exit(0 if ok else 30)


if __name__ == "__main__":
    main()
