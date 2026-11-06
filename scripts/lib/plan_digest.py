#!/usr/bin/env python3
"""委託人原件（規劃書v5、執行契約）的「原文雜湊」。

ADR-015（2026-11-06，委託人裁定 D1）要求在原件被取代的句子旁加修訂註記、原文不刪。
註記一律寫成行內標記 `〔ADR-nnn 修訂註記（日期）：…〕`（標記內不含 `〕` 與換行），
去掉全部標記後，必須與委託人提供的原件逐位元組相同。本工具輸出去掉標記後的 SHA-256，
供 session-start、check-state 與治理檢查比對 status.json 的 plan_sha256。

用法：python3 scripts/lib/plan_digest.py <檔案>（exit 0 輸出雜湊；10 參數錯誤；30 檔案讀不到）
"""
import hashlib
import re
import sys

# 以字元（不是位元組）比對：〕 的 UTF-8 位元組也出現在其他中文字裡。
MARK = re.compile("〔ADR-[0-9]{3} 修訂註記[^〕\n]*〕")


def original_bytes(data: bytes) -> bytes:
    return MARK.sub("", data.decode("utf-8")).encode("utf-8")


def original_digest(data: bytes) -> str:
    return hashlib.sha256(original_bytes(data)).hexdigest()


def main(argv: list[str]) -> int:
    if len(argv) == 2 and argv[1] in ("-h", "--help"):
        print(__doc__.strip())
        return 0
    if len(argv) != 2:
        print("用法：python3 scripts/lib/plan_digest.py <檔案>", file=sys.stderr)
        return 10
    try:
        with open(argv[1], "rb") as fh:
            data = fh.read()
    except OSError as exc:
        print(f"讀不到 {argv[1]}：{exc}", file=sys.stderr)
        return 30
    print(original_digest(data))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
