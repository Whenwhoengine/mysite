#!/usr/bin/env python3
"""把 GitHub Actions 產生的證據（summary.json）帶回儲存庫並更新驗收登錄表（EXEC-04、GOV-03）。

安全與一致性檢查：
  - summary.json 的 run_id 必須與 --run-id 相同，github.GITHUB_RUN_ID 必須與 --gh-run 相同；
  - 受測 commit（source_commit）必須存在於本地歷史，且是目前 HEAD 的祖先（報告 commit 與受測 commit 分開記錄）；
  - 只接受固定欄位；狀態只能是 PASS／FAIL／NOT_RUN／BLOCKED_EXTERNAL；
  - 同一 ID 在多份證據中任一 FAIL 即為 FAIL（不以較新的 PASS 蓋掉另一範圍的 FAIL）。
用法：import_evidence.py --summary=<path> --run-id=<id> --gh-run=<數字> [--summary=... --run-id=... --gh-run=...]
"""
import argparse
import json
import re
import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
VALID = {"PASS", "FAIL", "NOT_RUN", "BLOCKED_EXTERNAL"}
AUTO_NOTE_PROD = "CI 已驗證機制（見證據）；本項須在真實 staging／production 驗收，尚未執行。"
AUTO_NOTE_STAGING = "PASS 只涵蓋 CI 段；staging 段尚未執行（需常駐主機，見 BLK-HOST-01）。"
VENUE_BLOCKER = {"WP-10": "BLK-APPROVAL-01"}


def git(*args):
    return subprocess.run(["git", "-C", str(ROOT), *args], capture_output=True, text=True)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--summary", action="append", required=True)
    ap.add_argument("--run-id", action="append", required=True)
    ap.add_argument("--gh-run", action="append", required=True)
    args = ap.parse_args()
    if not (len(args.summary) == len(args.run_id) == len(args.gh_run)):
        print("--summary、--run-id、--gh-run 數量必須一致", file=sys.stderr)
        sys.exit(10)

    registry_path = ROOT / "tests/acceptance/registry.json"
    registry = json.loads(registry_path.read_text(encoding="utf-8"))
    merged = {}
    imported = []
    for summary_path, run_id, gh_run in zip(args.summary, args.run_id, args.gh_run):
        if not re.match(r"^[A-Za-z0-9._-]{1,80}$", run_id) or not gh_run.isdigit():
            print("run-id 或 gh-run 格式錯誤", file=sys.stderr)
            sys.exit(10)
        data = json.loads(Path(summary_path).read_text(encoding="utf-8"))
        if data.get("run_id") != run_id:
            print(f"run_id 不符：{data.get('run_id')} ≠ {run_id}", file=sys.stderr)
            sys.exit(40)
        if str((data.get("github") or {}).get("GITHUB_RUN_ID")) != gh_run:
            print("GITHUB_RUN_ID 不符，拒絕匯入", file=sys.stderr)
            sys.exit(40)
        commit = data.get("source_commit", "")
        if not re.match(r"^[0-9a-f]{40}$", commit) or git("cat-file", "-e", commit + "^{commit}").returncode != 0:
            print("受測 commit 不在本地歷史", file=sys.stderr)
            sys.exit(40)
        if git("merge-base", "--is-ancestor", commit, "HEAD").returncode != 0:
            print("受測 commit 不是目前 HEAD 的祖先", file=sys.stderr)
            sys.exit(40)
        dest = ROOT / "docs/evidence" / run_id
        dest.mkdir(parents=True, exist_ok=True)
        src_dir = Path(summary_path).parent
        for name in ("summary.json", "summary.md", "results.jsonl"):
            if (src_dir / name).exists():
                shutil.copyfile(src_dir / name, dest / name)
        ref = f"docs/evidence/{run_id}/summary.json"
        imported.append({"run_id": run_id, "gh_run": gh_run, "commit": commit, "scope": data.get("scope")})
        for tid, v in (data.get("acceptance") or {}).items():
            st = v.get("status")
            if st not in VALID:
                print(f"{tid} 狀態不合法：{st}", file=sys.stderr)
                sys.exit(40)
            cur = merged.setdefault(tid, {"status": "NOT_RUN", "evidence": [], "tests": [], "blocker_id": None})
            order = {"FAIL": 3, "PASS": 2, "BLOCKED_EXTERNAL": 1, "NOT_RUN": 0}
            if st == "FAIL" or (order[st] > order[cur["status"]] and cur["status"] != "FAIL"):
                cur["status"] = st
            if st != "NOT_RUN":
                cur["evidence"].append(ref)
                cur["tests"].extend(v.get("tests", [])[:10])
            if v.get("blocker_id"):
                cur["blocker_id"] = v["blocker_id"]

    for item in registry["items"]:
        m = merged.get(item["id"])
        if not m:
            continue
        if m["status"] == "NOT_RUN" and item.get("status") == "NOT_APPLICABLE" and item.get("note"):
            continue  # 已附理由的不適用項不被「本次未執行」覆蓋
        item["status"] = m["status"]
        item["evidence"] = sorted(set(m["evidence"]))
        item["tests"] = m["tests"][:20]
        item["blocker_id"] = m["blocker_id"]
        venue = item.get("verification_venue")
        # CI 通過不能代替真實環境驗收（v5 §12、§17.5）：只能在真實環境驗的項目維持外部阻擋並保留 CI 機制證據；
        # CI＋staging 兩段的項目標明 PASS 只涵蓋 CI 段。
        if venue == "staging_or_production" and m["status"] == "PASS":
            item["status"] = "BLOCKED_EXTERNAL"
            item["blocker_id"] = item.get("blocker_id") or VENUE_BLOCKER.get(item["id"], "BLK-HOST-01")
            item["note"] = AUTO_NOTE_PROD
        elif venue == "ci_and_staging" and m["status"] == "PASS":
            item["note"] = AUTO_NOTE_STAGING
        elif item.get("note") in (AUTO_NOTE_PROD, AUTO_NOTE_STAGING):
            item["note"] = None
    registry["last_import"] = imported
    registry_path.write_text(json.dumps(registry, ensure_ascii=False, indent=2), encoding="utf-8")
    counts = {}
    for item in registry["items"]:
        counts[item["status"]] = counts.get(item["status"], 0) + 1
    print(f"已匯入 {len(imported)} 份證據；登錄表狀態：{counts}")


if __name__ == "__main__":
    main()
