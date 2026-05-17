import socket
#!/usr/bin/env python3
import json
import os

def is_valid_ip(ip):
    try:
        socket.inet_pton(socket.AF_INET, ip)
        return True
    except socket.error:
        try:
            socket.inet_pton(socket.AF_INET6, ip)
            return True
        except socket.error:
            return False
import re
import subprocess
import time
from collections import deque
from typing import Dict

CONFIG_PATH = os.environ.get("DANN_CONFIG_PATH", "/pteroprotect/config.json")
ACCESS_LOG = "/var/log/nginx/pteroprotect.access.log"
STATE_DIR = "/pteroprotect/runtime"

IP_RE = re.compile(r"^(\d+\.\d+\.\d+\.\d+)")
BAD_STATUS = {"401", "403", "429", "444", "461", "463", "500", "502", "503", "504"}


def run(cmd):
    try:
        p = subprocess.run(cmd, capture_output=True, text=True, timeout=5)
        return p.returncode == 0
    except Exception:
        return False


def load_cfg() -> Dict:
    try:
        with open(CONFIG_PATH, "r", encoding="utf-8") as f:
            return json.load(f)
    except Exception:
        return {}


def should_count(line: str) -> bool:
    if '"/auth/' in line or '"POST /auth/' in line:
        return True
    if '"/api/' in line and any(f" {s} " in line for s in BAD_STATUS):
        return True
    return False


def ban_ip(ip: str, ttl_sec: int) -> bool:
    return run(["ipset", "add", "pteroprotect_block_v4", ip, "timeout", str(max(60, ttl_sec)), "-exist"])


def main() -> int:
    cfg = load_cfg()
    abuse_guard = cfg.get("abuse_guard", {}) if isinstance(cfg, dict) else {}

    max_auto_bans = int(abuse_guard.get("max_auto_bans", 200)) if isinstance(abuse_guard, dict) else 200
    window_sec = int(abuse_guard.get("window_sec", 120)) if isinstance(abuse_guard, dict) else 120
    threshold = int(abuse_guard.get("request_threshold", 40)) if isinstance(abuse_guard, dict) else 40
    ban_ttl_sec = int(abuse_guard.get("ban_ttl_sec", 600)) if isinstance(abuse_guard, dict) else 600
    allowlist = set(str(x).strip() for x in abuse_guard.get("allowlist_ips", []) if str(x).strip()) if isinstance(abuse_guard, dict) else set()

    os.makedirs(STATE_DIR, exist_ok=True)
    metrics_path = os.path.join(STATE_DIR, "security_log_watch.prom")

    counters: Dict[str, deque] = {}
    active_bans: Dict[str, int] = {}

    with open(ACCESS_LOG, "r", encoding="utf-8", errors="ignore") as f:
        f.seek(0, os.SEEK_END)
        while True:
            line = f.readline()
            if not line:
                time.sleep(0.2)
                continue

            m = IP_RE.match(line)
            if not m:
                continue
            ip = m.group(1)
            now = int(time.time())

            # expire old tracked bans
            for k in list(active_bans.keys()):
                if active_bans[k] <= now:
                    active_bans.pop(k, None)

            if ip in allowlist or not should_count(line):
                continue

            q = counters.setdefault(ip, deque())
            q.append(now)
            while q and (now - q[0]) > window_sec:
                q.popleft()

            if len(q) >= threshold and ip not in active_bans and len(active_bans) < max_auto_bans:
                if ban_ip(ip, ban_ttl_sec):
                    active_bans[ip] = now + ban_ttl_sec
                    q.clear()

            with open(metrics_path, "w", encoding="utf-8") as mf:
                mf.write("# HELP pteroprotect_security_log_watch gauge metrics for log watcher\\n")
                mf.write("# TYPE pteroprotect_security_log_watch gauge\\n")
                mf.write(f"pteroprotect_security_log_watch{{metric=\"active_bans\"}} {len(active_bans)}\\n")
                mf.write(f"pteroprotect_security_log_watch{{metric=\"tracked_ips\"}} {len(counters)}\\n")


if __name__ == "__main__":
    raise SystemExit(main())
