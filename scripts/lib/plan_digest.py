#!/usr/bin/env python3
"""委託人原件（規劃書v5、執行契約）的「原文雜湊」。

ADR-015（2026-11-06，委託人裁定 D1）要求在原件被取代的句子旁加修訂註記、原文不刪。
註記一律寫成行內標記 `〔ADR-nnn 修訂註記（YYYY-MM-DD）：說明〕`，去掉全部標記後，
必須與委託人提供的原件逐位元組相同。本工具輸出去掉標記後的 SHA-256，
供 session-start、check-state 與治理檢查比對 status.json 的 plan_sha256、prompt_sha256。

標記格式刻意收窄，避免拿註記藏住原文或偽裝成原文：說明只允許白名單字元——中文字
（U+4E00～U+9FFF）、半形英數、半形空白、. / - 以及全形、（），；：。
換行與其他控制字元、零寬與雙向控制字元、Unicode 行分隔字元、不換行空白、任何其他括號
（含與〕相似的﹞︺〙】］等）與 Markdown／HTML 符號一律不收；不合格式的標記不會被去掉，
雜湊就對不上。檔案不是合法 UTF-8 時丟出 ValueError（呼叫端判失敗）。
標記引用的 ADR 編號必須真的存在於 docs/架構決策.md（由 check-state 檢查）。

用法：python3 scripts/lib/plan_digest.py <檔案>（exit 0 輸出雜湊；10 參數錯誤；30 檔案讀不到）
"""
import hashlib
import re
import sys

# 以字元（不是位元組）比對：〕 的 UTF-8 位元組也出現在其他中文字裡。
# 說明文字採白名單（見檔首說明）；「-」放在字元類別最後，當作字面字元。
NOTE_CHARS = "一-鿿A-Za-z0-9 ./、（），；：。-"
MARK = re.compile("〔ADR-([0-9]{3}) 修訂註記（[0-9]{4}-[0-9]{2}-[0-9]{2}）：[" + NOTE_CHARS + "]*〕")


def _text(data: bytes) -> str:
    try:
        return data.decode("utf-8")
    except UnicodeDecodeError as exc:
        raise ValueError(f"不是合法的 UTF-8：{exc}") from exc


def original_bytes(data: bytes) -> bytes:
    return MARK.sub("", _text(data)).encode("utf-8")


def adr_numbers(data: bytes) -> list[str]:
    """檔案內所有修訂註記引用的 ADR 編號（例如 "015"），依出現順序。"""
    return MARK.findall(_text(data))


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
