#!/usr/bin/env python3
"""檢查 docs/state 與驗收登錄表的一致性（GOV-03、v5 附錄 C.2／E.2）。

規則：任務 ID 不重複、相依存在且無循環、acceptance ID 都在登錄表、
登錄表恰含 v5 的 90 個 ID＋EXEC 6 個且不重號、狀態值合法、status.json 必要欄位齊全。
exit 0 通過；30 有違規；10 檔案缺失或格式錯誤。
"""
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts/lib"))
from plan_digest import adr_numbers, original_digest  # noqa: E402
MILESTONE_STATES = {"NOT_STARTED", "IN_PROGRESS", "IMPLEMENTED", "VERIFIED", "BLOCKED_EXTERNAL", "FAILED"}
TEST_STATES = {"NOT_RUN", "PASS", "FAIL", "BLOCKED_EXTERNAL", "NOT_APPLICABLE"}
DELIVERY_STATES = {"IMPLEMENTATION_IN_PROGRESS", "CI_VERIFIED", "READY_FOR_STAGING", "STAGING_VERIFIED", "READY_FOR_PRODUCTION", "PRODUCTION_VERIFIED"}
STATUS_FIELDS = ["project", "plan_version", "plan_sha256", "updated_at", "execution_backend", "working_branch", "source_commit",
                 "delivery_state", "milestones", "active_task_id", "last_verification_ref", "production_release_id",
                 "external_blocker_ids", "next_action"]
PREFIX_COUNTS = {"CLD": 10, "INF": 8, "WP": 10, "API": 16, "UI": 8, "COM": 12, "OPS": 14, "GOV": 6, "DAT": 6, "EXEC": 6}


def load(rel):
    p = ROOT / rel
    try:
        return json.loads(p.read_text(encoding="utf-8"))
    except FileNotFoundError:
        print(f"缺少檔案：{rel}")
        sys.exit(10)
    except json.JSONDecodeError as e:
        print(f"JSON 格式錯誤：{rel}：{e}")
        sys.exit(10)


def main():
    errors = []
    reg = load("tests/acceptance/registry.json")
    ids = [i["id"] for i in reg["items"]]
    if len(ids) != len(set(ids)):
        errors.append("驗收登錄表有重號")
    for prefix, n in PREFIX_COUNTS.items():
        got = sorted(i for i in ids if i.split("-")[0] == prefix)
        want = [f"{prefix}-{k:02d}" for k in range(1, n + 1)]
        if got != want:
            errors.append(f"{prefix} 系列不完整或有幽靈 ID：{sorted(set(got) ^ set(want))}")
    if len(ids) != 96:
        errors.append(f"驗收登錄表應有 96 項，實際 {len(ids)}")
    for item in reg["items"]:
        if item["status"] not in TEST_STATES:
            errors.append(f"{item['id']} 狀態不合法：{item['status']}")
        if item["status"] == "NOT_APPLICABLE" and not item.get("note"):
            errors.append(f"{item['id']} 標 NOT_APPLICABLE 但沒有理由")
        if item["status"] == "PASS" and not item.get("evidence"):
            errors.append(f"{item['id']} 標 PASS 但沒有證據參照")

    tasks = load("docs/state/tasks.json")["tasks"]
    tids = [t["id"] for t in tasks]
    if len(tids) != len(set(tids)):
        errors.append("tasks.json 有重複任務 ID")
    tset = set(tids)
    idset = set(ids)
    for t in tasks:
        if t["status"] not in MILESTONE_STATES:
            errors.append(f"任務 {t['id']} 狀態不合法：{t['status']}")
        for d in t["depends_on"]:
            if d not in tset:
                errors.append(f"任務 {t['id']} 依賴不存在的 {d}")
        for a in t["acceptance_ids"]:
            if a not in idset:
                errors.append(f"任務 {t['id']} 引用幽靈驗收 ID {a}")
        if t["status"] == "BLOCKED_EXTERNAL" and not t.get("blocker_id"):
            errors.append(f"任務 {t['id']} 外部阻擋但沒有 blocker_id")
    # 循環檢查
    graph = {t["id"]: t["depends_on"] for t in tasks}
    state = {}

    def visit(n, path):
        if state.get(n) == 1:
            errors.append("任務相依循環：" + " → ".join(path + [n]))
            return
        if state.get(n) == 2:
            return
        state[n] = 1
        for d in graph.get(n, []):
            visit(d, path + [n])
        state[n] = 2

    for n in graph:
        visit(n, [])
    covered = {a for t in tasks for a in t["acceptance_ids"]}
    missing = sorted(idset - covered)
    if missing:
        errors.append(f"下列驗收 ID 沒有任何任務負責：{missing}")

    status = load("docs/state/status.json")
    for f in STATUS_FIELDS:
        if f not in status:
            errors.append(f"status.json 缺欄位 {f}")
    if status.get("delivery_state") not in DELIVERY_STATES:
        errors.append("status.json delivery_state 不合法")
    for m, s in status.get("milestones", {}).items():
        if s not in MILESTONE_STATES:
            errors.append(f"里程碑 {m} 狀態不合法：{s}")
    # EXEC-01：規劃書原文必須與紀錄的 SHA-256 相符；不在 repo 時須誠實記為未取得（source_plan_file=null），不得以其他內容冒充。
    plan_rel = status.get("source_plan_file")
    if plan_rel:
        plan = ROOT / plan_rel
        if not plan.is_file():
            errors.append(f"status.json 記錄的規劃書 {plan_rel} 不存在；原文未取得時應把 source_plan_file 設為 null 並依 docs/執行契約.md 開工")
        else:
            # ADR-015：原件可帶行內修訂註記；去掉註記後必須與委託人原件逐位元組相同（scripts/lib/plan_digest.py）。
            digest = original_digest(plan.read_bytes())
            if digest != status.get("plan_sha256"):
                errors.append(f"規劃書 {plan_rel} 的 SHA-256 與 status.json 紀錄不符（可能被改寫或不是委託人提供的原件）")
    elif not (ROOT / "docs/執行契約.md").is_file():
        errors.append("規劃書原文未取得，且缺少 docs/執行契約.md（無法依內嵌契約開工）")
    # ADR-015：執行契約（施工 prompt 原件）同樣只能加行內修訂註記；去掉註記後須等於 prompt_sha256
    contract = ROOT / "docs/執行契約.md"
    if status.get("prompt_sha256") and contract.is_file():
        if original_digest(contract.read_bytes()) != status.get("prompt_sha256"):
            errors.append("docs/執行契約.md 去掉修訂註記後的 SHA-256 與 status.json 的 prompt_sha256 不符（原件被改寫）")
    # 修訂註記引用的 ADR 必須真的存在於 docs/架構決策.md
    adr_doc = ROOT / "docs/架構決策.md"
    adr_text = adr_doc.read_text(encoding="utf-8") if adr_doc.is_file() else ""
    for rel in filter(None, [plan_rel, "docs/執行契約.md"]):
        f = ROOT / rel
        if f.is_file():
            for num in sorted(set(adr_numbers(f.read_bytes()))):
                if f"## ADR-{num} " not in adr_text:
                    errors.append(f"{rel} 的修訂註記引用 ADR-{num}，但 docs/架構決策.md 沒有這條決策")

    if errors:
        print("state 檢查失敗：")
        for e in errors:
            print("  - " + e)
        sys.exit(30)
    print(f"state 檢查通過：任務 {len(tasks)} 項、驗收 ID {len(ids)} 項、皆有負責任務。")


if __name__ == "__main__":
    main()
