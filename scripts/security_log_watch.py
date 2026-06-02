#!/usr/bin/env python3
import ipaddress
import json
import os
import re
import subprocess
import time
from collections import deque
from typing import Dict, Optional


def is_valid_ip(ip: str) -> bool:
    try:
        ipaddress.ip_address(ip)
        return True
    except ValueError:
        return False


def parse_client_ip(line: str) -> Optional[str]:
    m = IP_RE.match(line)
    if not m:
        return None

    raw = m.group(1).strip('[] ,')
    ip = raw.split(',', 1)[0].strip('[] ')
    return ip if is_valid_ip(ip) else None


CONFIG_PATH = os.environ.get("DANN_CONFIG_PATH", "/pteroprotect/config.json")
ACCESS_LOG = "/var/log/nginx/pteroprotect.access.log"
STATE_DIR = "/pteroprotect/runtime"

IP_RE = re.compile(r"^(\S+)")
BAD_STATUS = {"401", "403", "429", "444", "461", "463", "500", "502", "503", "504"}
METRICS_INTERVAL_SEC = 5
FIREWALL_MANAGER = os.environ.get("PTEROPROTECT_FIREWALL_MANAGER", "/pteroprotect/scripts/pteroprotect_firewall_manager.sh")
FALLBACK_IPSET4 = os.environ.get("PTEROPROTECT_DYNAMIC_BLOCK_SET4", "pteroprotect_block_v4")
FALLBACK_IPSET6 = os.environ.get("PTEROPROTECT_DYNAMIC_BLOCK_SET6", "pteroprotect_block_v6")


def run(cmd):
    try:
        p = subprocess.run(cmd, capture_output=True, text=True, timeout=10)
        return p.returncode == 0
    except Exception:
        return False


def fallback_ipset_ban(ip: str, ttl_sec: int) -> bool:
    set_name = FALLBACK_IPSET6 if ":" in ip else FALLBACK_IPSET4
    return run(["ipset", "add", set_name, ip, "timeout", str(max(60, ttl_sec)), "-exist"])


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
    if not is_valid_ip(ip):
        return False
    ttl = str(max(60, ttl_sec))
    if os.path.exists(FIREWALL_MANAGER) and os.access(FIREWALL_MANAGER, os.X_OK):
        if run([FIREWALL_MANAGER, "ban", ip, ttl]):
            return True
    return fallback_ipset_ban(ip, ttl_sec)


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
    last_metrics_write = 0

    def write_metrics(now: int, force: bool = False) -> None:
        nonlocal last_metrics_write
        if not force and (now - last_metrics_write) < METRICS_INTERVAL_SEC:
            return
        last_metrics_write = now
        with open(metrics_path, "w", encoding="utf-8") as mf:
            mf.write("# HELP pteroprotect_security_log_watch gauge metrics for log watcher\n")
            mf.write("# TYPE pteroprotect_security_log_watch gauge\n")
            mf.write(f"pteroprotect_security_log_watch{{metric=\"active_bans\"}} {len(active_bans)}\n")
            mf.write(f"pteroprotect_security_log_watch{{metric=\"tracked_ips\"}} {len(counters)}\n")

    with open(ACCESS_LOG, "r", encoding="utf-8", errors="ignore") as f:
        f.seek(0, os.SEEK_END)
        while True:
            line = f.readline()
            if not line:
                time.sleep(0.2)
                continue

            ip = parse_client_ip(line)
            if not ip:
                continue
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

            write_metrics(now)


if __name__ == "__main__":
    raise SystemExit(main())
