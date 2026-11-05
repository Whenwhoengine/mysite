"""WordPress 看到的來源 IP（wp-web real_ip）：只信任 gateway 所在 edge 子網送來的 X-Forwarded-For。

gateway 以 `$proxy_add_x_forwarded_for` 在既有標頭後附上自己看到的直接連線位址；wp-web 的 8081 只接受 edge 子網、
`real_ip_recursive off` 只取最後一個位址，所以 WordPress 的 REMOTE_ADDR 是「gateway 看到的客戶端」，
不是 gateway 容器自己，也不能由用戶端偽造的 X-Forwarded-For 指定。驗證用 WordPress 登入 session 紀錄的 IP。
"""

from __future__ import annotations

import json
import subprocess
import urllib.parse
import uuid

from wpct_lib import CmsSession, Context, check, cms_host, contract, dev, secret, wp

SPOOFED = "203.0.113.77"


def _gateway_edge() -> tuple[str, str]:
    """回傳 (gateway 容器在 edge 網路的 IP, edge 網路的 bridge 閘道位址)。"""
    cid = dev("compose", "ps", "-q", "gateway").stdout.strip().splitlines()
    check(bool(cid), "找不到執行中的 gateway 容器")
    out = subprocess.run(["docker", "inspect", "-f", "{{json .NetworkSettings.Networks}}", cid[0]],
                         capture_output=True, text=True, timeout=30)
    check(out.returncode == 0, f"docker inspect gateway 失敗：{out.stderr[-200:]}")
    nets = json.loads(out.stdout)
    edge = [v for k, v in nets.items() if k.endswith("_edge")]
    check(len(edge) == 1, f"gateway 應恰好在一個 edge 網路：{sorted(nets)}")
    return edge[0].get("IPAddress", ""), edge[0].get("Gateway", "")


@contract("wp_real_ip_from_gateway_not_spoofable", ["OPS-02"], "security")
def real_ip(ctx: Context) -> str | None:
    gateway_ip, bridge_ip = _gateway_edge()
    check(bool(gateway_ip) and bool(bridge_ip), f"無法取得 gateway／edge 閘道位址：{gateway_ip!r} {bridge_ip!r}")
    ua = f"mysite-wp-contract/realip-{uuid.uuid4().hex[:12]}"
    session = CmsSession()
    session.cookies["wordpress_test_cookie"] = "WP%20Cookie%20check"
    form = urllib.parse.urlencode({
        "log": "mysite-editor", "pwd": secret("wp_editor_password"), "wp-submit": "登入", "testcookie": "1",
        "redirect_to": f"http://{cms_host()}/wp-admin/",
    }).encode()
    resp = session.request("POST", "/wp-login.php", {
        "Content-Type": "application/x-www-form-urlencoded", "User-Agent": ua,
        "X-Forwarded-For": SPOOFED, "X-Real-IP": SPOOFED, "Forwarded": f"for={SPOOFED}",
    }, form)
    check(resp.status in (302, 303) and any(k.startswith("wordpress_logged_in_") for k in session.cookies), f"登入失敗（HTTP {resp.status}）")
    tokens = json.loads(wp("user", "meta", "get", "mysite-editor", "session_tokens", "--format=json").stdout.strip() or "{}")
    mine = [t for t in (tokens or {}).values() if isinstance(t, dict) and t.get("ua") == ua]
    check(len(mine) == 1, f"找不到本次登入的 session（{len(mine)} 筆）")
    ip = str(mine[0].get("ip", ""))
    check(ip != SPOOFED, "用戶端偽造的 X-Forwarded-For 被 WordPress 採用")
    check(ip != gateway_ip, f"WordPress 記到的是 gateway 自己的位址 {gateway_ip}（real_ip 未生效）")
    check(ip == bridge_ip, f"WordPress 應記到 gateway 看到的客戶端位址 {bridge_ip}（測試從主機經發布埠連入），實際 {ip}")
    return f"登入 session 記錄的 IP＝gateway 看到的客戶端（{ip}），不是 gateway（{gateway_ip}），偽造的 X-Forwarded-For／X-Real-IP／Forwarded 無效"
