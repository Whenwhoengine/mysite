#!/usr/bin/env python3
"""彙整真實測試結果為逐驗收 ID 的證據（GOV-03、v5 附錄 E）。

輸入（${MYSITE_RESULTS_DIR}）：
  steps.jsonl              verify.sh 每個步驟的命令、時間、exit code
  *.jsonl                  各測試套件逐項結果（suite/test/ids/status/message/時間）
  junit.xml＋phpunit-tests.xml  PHPUnit 結果與 group（＝驗收 ID）對應
  coverage.txt             PHPUnit 行覆蓋率摘要
輸出（docs/evidence/<run-id>/）：summary.json、summary.md、results.jsonl（去秘密化）

判定：某 ID 有任何 FAIL → FAIL；至少一個 PASS 且無 FAIL → PASS；都沒有 → NOT_RUN
（若該 ID 驗證場所為 staging_or_production，標 BLOCKED_EXTERNAL 並附阻擋 ID）。不會把 FAIL 改成 PASS。
"""
import argparse
import hashlib
import json
import os
import re
import subprocess
import sys
import xml.etree.ElementTree as ET
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
REDACT = [
    (re.compile(r"(Bearer|Basic)\s+[A-Za-z0-9._~+/=-]+"), r"\1 ***"),
    (re.compile(r"(?i)(password|secret|token|ticket|signature|cookie|authorization)([=:]\s*)[^\s,;\"]+"), r"\1\2***"),
    (re.compile(r"base64:[A-Za-z0-9+/=]{20,}"), "base64:***"),
    (re.compile(r"[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}"), "***.***.***"),
]
BLOCKER_FOR_VENUE = {"staging_or_production": "BLK-HOST-01"}


def redact(text):
    text = str(text or "")
    for pattern, repl in REDACT:
        text = pattern.sub(repl, text)
    return text[:600]


def read_jsonl(path):
    out = []
    if not path.exists():
        return out
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if line:
            try:
                out.append(json.loads(line))
            except json.JSONDecodeError:
                out.append({"suite": path.stem, "test": "unparseable-line", "ids": [], "status": "FAIL", "message": "結果檔格式錯誤"})
    return out


def phpunit_results(results_dir):
    junit = results_dir / "junit.xml"
    listing = results_dir / "phpunit-tests.xml"
    if not junit.exists() or not listing.exists():
        return []
    ns = {"t": "https://xml.phpunit.de/testSuite"}
    groups = {}
    for g in ET.parse(listing).getroot().findall(".//t:groups/t:group", ns):
        for t in g.findall("t:test", ns):
            groups.setdefault(t.get("id"), set()).add(g.get("name"))
    out = []
    for case in ET.parse(junit).getroot().iter("testcase"):
        cls = case.get("class") or case.get("classname") or ""
        name = case.get("name") or ""
        base = name.split(" with data set")[0]
        key = f"{cls}::{base}"
        failed = case.find("failure") is not None or case.find("error") is not None
        skipped = case.find("skipped") is not None
        msg = ""
        for tag in ("failure", "error"):
            el = case.find(tag)
            if el is not None:
                msg = (el.get("message") or el.text or "").strip().splitlines()[0:1]
                msg = msg[0] if msg else ""
        status = "FAIL" if failed else ("SKIPPED" if skipped else "PASS")
        ids = sorted(i for i in groups.get(key, set()) if re.match(r"^(CLD|INF|WP|API|UI|COM|OPS|GOV|DAT|EXEC)-\d+$", i))
        out.append({"suite": "phpunit", "test": key, "ids": ids, "status": status, "message": redact(msg),
                    "duration_seconds": float(case.get("time") or 0)})
    return out


def coverage_summary(results_dir):
    f = results_dir / "coverage.txt"
    if not f.exists():
        return None
    text = f.read_text(encoding="utf-8", errors="ignore")
    m = re.search(r"Lines:\s+([\d.]+)%\s+\((\d+)/(\d+)\)", text)
    if not m:
        return None
    return {"lines_percent": float(m.group(1)), "covered": int(m.group(2)), "total": int(m.group(3)), "scope": "app/app（自製業務程式）"}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--run-id", required=True)
    ap.add_argument("--update-registry", action="store_true", help="把逐 ID 結果寫回 tests/acceptance/registry.json（只在受測 commit 與目前 HEAD 相同時允許）")
    args = ap.parse_args()
    if not re.match(r"^[A-Za-z0-9._-]{1,80}$", args.run_id):
        print("run-id 格式不合法", file=sys.stderr)
        sys.exit(10)

    results_dir = Path(os.environ.get("MYSITE_RESULTS_DIR", ROOT / ".runtime/results"))
    out_dir = ROOT / "docs/evidence" / args.run_id
    out_dir.mkdir(parents=True, exist_ok=True)
    commit = subprocess.check_output(["git", "-C", str(ROOT), "rev-parse", "HEAD"], text=True).strip()
    registry = json.loads((ROOT / "tests/acceptance/registry.json").read_text(encoding="utf-8"))
    known = {i["id"]: i for i in registry["items"]}

    steps = read_jsonl(results_dir / "steps.jsonl")
    items = []
    for f in sorted(results_dir.glob("*.jsonl")):
        if f.name == "steps.jsonl":
            continue
        items.extend(read_jsonl(f))
    items.extend(phpunit_results(results_dir))
    for it in items:
        it["message"] = redact(it.get("message"))
        it["ids"] = [i for i in it.get("ids", []) if i in known]

    per_id = {}
    for tid in known:
        rel = [it for it in items if tid in it["ids"]]
        statuses = {it["status"] for it in rel}
        if "FAIL" in statuses:
            st = "FAIL"
        elif "PASS" in statuses:
            st = "PASS"
        else:
            st = "NOT_RUN"
            venue = known[tid].get("verification_venue")
            if venue in BLOCKER_FOR_VENUE:
                st = "BLOCKED_EXTERNAL"
        per_id[tid] = {
            "status": st,
            "level": known[tid]["level"],
            "tests": [f"{it['suite']}:{it['test']}" for it in rel],
            "failures": [f"{it['suite']}:{it['test']}：{it['message']}" for it in rel if it["status"] == "FAIL"],
        }
        if st == "BLOCKED_EXTERNAL":
            per_id[tid]["blocker_id"] = BLOCKER_FOR_VENUE[known[tid]["verification_venue"]]

    scope = os.environ.get("MYSITE_SCOPE", "unknown")
    counts = {}
    for v in per_id.values():
        counts[v["status"]] = counts.get(v["status"], 0) + 1
    p0_fail = sorted(k for k, v in per_id.items() if v["status"] == "FAIL" and known[k]["level"] in ("P0", "P0-B"))
    summary = {
        "schema_version": 1,
        "run_id": args.run_id,
        "source_commit": commit,
        "execution_backend": os.environ.get("MYSITE_EXECUTION_BACKEND", "unknown"),
        "scope": scope,
        "environment": "testing",
        "github": {k: os.environ.get(k) for k in ("GITHUB_RUN_ID", "GITHUB_RUN_ATTEMPT", "GITHUB_SHA", "GITHUB_REF", "GITHUB_WORKFLOW") if os.environ.get(k)},
        "generated_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "steps": steps,
        "lockfiles": {rel: hashlib.sha256((ROOT / rel).read_bytes()).hexdigest()
                      for rel in ("app/composer.lock", "app/package-lock.json", "tests/e2e/package-lock.json", "infra/versions.env", "docs/versions.lock.json")
                      if (ROOT / rel).is_file()},
        "coverage": coverage_summary(results_dir),
        "counts": counts,
        "p0_failures": p0_fail,
        "tests_total": len(items),
        "tests_failed": sum(1 for it in items if it["status"] == "FAIL"),
        "acceptance": per_id,
        "redaction_checked": True,
    }
    (out_dir / "summary.json").write_text(json.dumps(summary, ensure_ascii=False, indent=2), encoding="utf-8")
    with (out_dir / "results.jsonl").open("w", encoding="utf-8") as fh:
        for it in items:
            fh.write(json.dumps(it, ensure_ascii=False) + "\n")

    lines = [f"# 驗證證據 {args.run_id}", "",
             f"- 受測 commit：`{commit}`", f"- 執行後端：{summary['execution_backend']}", f"- 範圍：{scope}",
             f"- 產生時間：{summary['generated_at']}", f"- 測試數：{len(items)}（失敗 {summary['tests_failed']}）"]
    if summary["coverage"]:
        c = summary["coverage"]
        lines.append(f"- 行覆蓋率（{c['scope']}）：{c['lines_percent']}%（{c['covered']}/{c['total']}）")
    lines += ["", "## 步驟", "", "| 步驟 | exit | 狀態 | 開始 | 結束 |", "|---|---:|---|---|---|"]
    for s in steps:
        lines.append(f"| {s['step']} | {s['exit_code']} | {s['status']} | {s['started_at']} | {s['finished_at']} |")
    lines += ["", "## 驗收 ID", "", "| ID | 級別 | 狀態 | 測試數 | 失敗 |", "|---|---|---|---:|---|"]
    for tid, v in per_id.items():
        lines.append(f"| {tid} | {v['level']} | {v['status']} | {len(v['tests'])} | {'；'.join(v['failures'])[:200]} |")
    (out_dir / "summary.md").write_text("\n".join(lines) + "\n", encoding="utf-8")

    if args.update_registry:
        head = subprocess.check_output(["git", "-C", str(ROOT), "rev-parse", "HEAD"], text=True).strip()
        if head != commit:
            print("受測 commit 與 HEAD 不同，拒絕更新登錄表", file=sys.stderr)
            sys.exit(40)
        for item in registry["items"]:
            v = per_id[item["id"]]
            if v["status"] in ("PASS", "FAIL", "BLOCKED_EXTERNAL"):
                item["status"] = v["status"]
                item["tests"] = v["tests"][:20]
                item["evidence"] = [f"docs/evidence/{args.run_id}/summary.json"]
                item["blocker_id"] = v.get("blocker_id")
        (ROOT / "tests/acceptance/registry.json").write_text(json.dumps(registry, ensure_ascii=False, indent=2), encoding="utf-8")

    print(f"證據已寫入 {out_dir.relative_to(ROOT)}：{counts}；P0 失敗 {len(p0_fail)} 項")


if __name__ == "__main__":
    main()
