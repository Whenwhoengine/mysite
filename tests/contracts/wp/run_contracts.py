#!/usr/bin/env python3
"""WordPress（site-content）契約測試入口。用法見 run.sh --help。

結果：終端輸出＋每個測試一行 JSON 寫入 ${MYSITE_RESULTS_DIR:-.runtime/results}/wp-contract.jsonl（每次執行先清空）。
exit code：0 全部通過；10 參數錯；20 環境未就緒；30 有測試失敗。
"""

from __future__ import annotations

import argparse
import json
import sys
import time
import traceback
from pathlib import Path

sys.dont_write_bytecode = True
sys.path.insert(0, str(Path(__file__).resolve().parent))

import wpct_lib as L  # noqa: E402
import test_api  # noqa: E402,F401
import test_security  # noqa: E402,F401
import test_editor  # noqa: E402,F401
import test_events  # noqa: E402,F401
import test_preview  # noqa: E402,F401
import test_schedule  # noqa: E402,F401

GROUP_ORDER = ["lint", "api", "security", "roles", "editor", "events", "preview", "schedule"]


@L.contract("php_syntax_lint", ["WP-02"], "lint")
def php_lint(ctx: L.Context) -> str | None:
    script = ("cd /var/www/wp/wp-content && find plugins/site-content mu-plugins -name '*.php' -print | sort | "
              "while read -r f; do php -l \"$f\" >/dev/null 2>&1 || echo \"LINT-FAIL $f\"; echo \"CHECKED $f\"; done")
    res = L.compose_exec("wp-php", script)
    failed = [l for l in res.stdout.splitlines() if l.startswith("LINT-FAIL")]
    checked = [l for l in res.stdout.splitlines() if l.startswith("CHECKED")]
    L.check(len(checked) >= 10, f"檢查的 PHP 檔太少：{len(checked)}")
    L.check(not failed, f"PHP 語法錯誤：{failed}")
    return f"PHP {L.wp_eval('echo PHP_VERSION;').strip()}；{len(checked)} 個檔案"


@L.contract("php_unit_selftest", ["API-11"], "lint")
def php_selftest(ctx: L.Context) -> str | None:
    code = (L.ROOT / "wp" / "tests" / "selftest.php").read_bytes()
    res = L.wp("eval-file", "-", stdin=code, check_ok=False)
    lines = [l for l in res.stdout.splitlines() if l.startswith("{")]
    L.check(bool(lines), f"selftest 沒有輸出（exit {res.code}）")
    out = json.loads(lines[-1])
    L.check(res.code == 0 and not out["failed"], f"selftest 失敗：{out['failed']}")
    return f"{out['passed']} 項單元檢查（票券驗證、YouTube 解析、音訊來源、slug、選單網址、事件種類、退避）"


def registry_ids() -> set[str]:
    data = json.loads((L.ROOT / "tests" / "acceptance" / "registry.json").read_text(encoding="utf-8"))
    return {item["id"] for item in data["items"]}


def cleanup(ctx: L.Context) -> list[str]:
    notes: list[str] = []
    try:
        test_events.stop_receiver()
    except Exception as exc:  # noqa: BLE001
        notes.append(f"停止測試接收端失敗：{exc}")
    ids = [str(i) for i in sorted(set(ctx.created_posts), reverse=True)]
    if ids:
        # 已被測試本身刪除的 ID 會產生警告，忽略即可（只刪本次測試建立的內容與附件）。
        L.wp("post", "delete", *ids, "--force", check_ok=False)
        left = L.wp("eval", "echo implode(',', array_filter(array(" + ",".join(ids) + "), 'get_post'));", check_ok=False).stdout.strip()
        if left:
            notes.append(f"仍有測試內容未刪除：{left}")
    for taxonomy, term_id in ctx.created_terms:
        L.wp("term", "delete", taxonomy, str(term_id), check_ok=False)
    for role, cap in ctx.caps_added:
        L.wp("cap", "remove", role, cap, check_ok=False)
    for option in set(ctx.options_set) | {"site_content_simulate_outbox_failure"}:
        L.wp("option", "delete", option, check_ok=False)
    L.release_jobs()
    return notes


def main() -> int:
    parser = argparse.ArgumentParser(add_help=False)
    parser.add_argument("--only", default="")
    parser.add_argument("--list", action="store_true")
    parser.add_argument("--skip", default="")
    parser.add_argument("-h", "--help", action="store_true")
    args, unknown = parser.parse_known_args()
    if args.help:
        print(__doc__)
        return 0
    if unknown:
        print(f"未知參數：{' '.join(unknown)}", file=sys.stderr)
        return 10

    known = registry_ids()
    bad = [(t.name, i) for t in L.REGISTRY for i in t.ids if i not in known]
    if bad:
        print(f"測試標註了 registry 不存在的驗收 ID：{bad}", file=sys.stderr)
        return 10
    tests = sorted(L.REGISTRY, key=lambda t: GROUP_ORDER.index(t.group))
    if args.list:
        for t in tests:
            print(f"{t.name}\t{','.join(t.ids)}\t{t.group}")
        return 0
    only = {x for x in args.only.split(",") if x}
    skip = {x for x in args.skip.split(",") if x}
    names = {t.name for t in tests} | {t.group for t in tests}
    if (only | skip) - names:
        print(f"不認得的測試或群組：{sorted((only | skip) - names)}", file=sys.stderr)
        return 10
    if only:
        tests = [t for t in tests if t.name in only or t.group in only]
    tests = [t for t in tests if t.name not in skip and t.group not in skip]

    L.RESULTS_DIR.mkdir(parents=True, exist_ok=True)
    L.RESULTS_FILE.write_text("", encoding="utf-8")

    try:
        health = L.public_get("/health")
        if health.status != 200:
            print(f"環境未就緒：/health HTTP {health.status}（MYSITE_ENV={L.ENV}，CMS={L.CMS_BASE}）", file=sys.stderr)
            return 20
        ctx = L.Context()
        ctx.cache["app_url"] = L.app_url()
    except Exception as exc:  # noqa: BLE001
        print(L.redact(f"環境未就緒：{exc}"), file=sys.stderr)
        return 20

    print(f"WordPress 契約測試：MYSITE_ENV={L.ENV}　CMS={L.CMS_BASE}（Host {L.cms_host()}）　主站設定={ctx.cache['app_url']}　共 {len(tests)} 項")
    results = []
    try:
        for test in tests:
            L.hold_jobs(900)
            started = L.now_utc()
            t0 = time.time()
            status, message = "PASS", ""
            try:
                note = test.func(ctx)
                message = note or ""
            except L.TestFailure as exc:
                status, message = "FAIL", str(exc)
            except Exception as exc:  # noqa: BLE001
                status = "FAIL"
                message = f"{type(exc).__name__}: {exc} @ {traceback.extract_tb(exc.__traceback__)[-1].lineno}"
            message = L.redact(message)
            finished = L.now_utc()
            line = {"suite": "wp-contract", "test": test.name, "ids": test.ids, "status": status, "message": message,
                    "started_at": started, "finished_at": finished}
            with L.RESULTS_FILE.open("a", encoding="utf-8") as fh:
                fh.write(json.dumps(line, ensure_ascii=False) + "\n")
            results.append(line)
            print(f"{status}  {test.name}  [{','.join(test.ids)}]  {time.time() - t0:.1f}s" + (f"\n      {message}" if message else ""), flush=True)
    finally:
        notes = cleanup(ctx)
        for note in notes:
            print(f"清理警告：{note}", file=sys.stderr)

    failed = [r for r in results if r["status"] != "PASS"]
    print(f"\n合計 {len(results)} 項：PASS {len(results) - len(failed)}、FAIL {len(failed)}；結果檔 {L.RESULTS_FILE}")
    return 30 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
