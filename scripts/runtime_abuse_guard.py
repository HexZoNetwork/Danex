#!/usr/bin/env python3
import collections
import json
import os
import signal
import subprocess
import sys
import time
from typing import Dict, List, Optional, Tuple

CONFIG_PATH = os.environ.get("DANN_CONFIG_PATH", "/pteroprotect/config.json")
STATE_DIR_DEFAULT = "/pteroprotect/runtime"


def log(msg: str) -> None:
    ts = time.strftime("%Y-%m-%d %H:%M:%S", time.gmtime())
    print(f"[{ts}] [abuse-guard] {msg}", flush=True)


def run(cmd: List[str], timeout: int = 5) -> Tuple[int, str, str]:
    try:
        p = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
        return p.returncode, p.stdout, p.stderr
    except Exception as exc:
        return 1, "", str(exc)


def load_config() -> dict:
    try:
        with open(CONFIG_PATH, "r", encoding="utf-8") as f:
            return json.load(f)
    except Exception as exc:
        log(f"config load failed ({CONFIG_PATH}): {exc}")
        return {}


def as_int(v, default: int) -> int:
    try:
        return int(v)
    except Exception:
        return default


def now_ms() -> int:
    return int(time.time() * 1000)


def list_runtime_pids(targets: List[str]) -> List[int]:
    pids: List[int] = []
    target_set = set(t.lower() for t in targets)
    for entry in os.listdir("/proc"):
        if not entry.isdigit():
            continue
        pid = int(entry)
        comm_path = f"/proc/{pid}/comm"
        cmdline_path = f"/proc/{pid}/cmdline"
        try:
            with open(comm_path, "r", encoding="utf-8", errors="ignore") as f:
                comm = f.read().strip().lower()
            with open(cmdline_path, "rb") as f:
                raw = f.read().replace(b"\x00", b" ").decode("utf-8", errors="ignore").lower()
            if comm in target_set or any(tok in raw for tok in target_set):
                pids.append(pid)
        except Exception:
            continue
    return pids


def pid_uid(pid: int) -> Optional[int]:
    try:
        with open(f"/proc/{pid}/status", "r", encoding="utf-8", errors="ignore") as f:
            for line in f:
                if line.startswith("Uid:"):
                    parts = line.split()
                    if len(parts) >= 2:
                        return int(parts[1])
    except Exception:
        return None
    return None


def socket_count_for_pid(pid: int, gateway_ports: List[int]) -> int:
    fd_dir = f"/proc/{pid}/fd"
    count = 0
    try:
        for fd in os.listdir(fd_dir):
            p = os.path.join(fd_dir, fd)
            try:
                target = os.readlink(p)
            except Exception:
                continue
            if not target.startswith("socket:"):
                continue
            count += 1
    except Exception:
        return 0

    if not gateway_ports:
        return count

    # tighter filter via ss when gateway ports configured
    rc, out, _ = run(["ss", "-H", "-ntp"], timeout=3)
    if rc != 0:
        return count
    filtered = 0
    pid_tag = f"pid={pid},"
    for line in out.splitlines():
        if pid_tag not in line:
            continue
        peer = line.split()[4] if len(line.split()) >= 5 else ""
        if ":" not in peer:
            continue
        try:
            port = int(peer.rsplit(":", 1)[1])
        except Exception:
            continue
        if port in gateway_ports:
            filtered += 1
    return filtered


def resolve_container_id(pid: int) -> Optional[str]:
    try:
        with open(f"/proc/{pid}/cgroup", "r", encoding="utf-8", errors="ignore") as f:
            lines = f.readlines()
    except Exception:
        return None

    for line in lines:
        # docker style cgroup id length 64
        for tok in line.strip().split("/"):
            if len(tok) >= 12 and all(c in "0123456789abcdef" for c in tok.lower()):
                return tok[:12]
    return None


def pause_container_temporarily(container_id: str, ttl_sec: int) -> None:
    rc, _, err = run(["docker", "pause", container_id], timeout=5)
    if rc != 0:
        log(f"container pause failed {container_id}: {err.strip()}")
        return
    log(f"container paused {container_id} for {ttl_sec}s")
    cmd = f"sleep {max(1, ttl_sec)}; docker unpause {container_id} >/dev/null 2>&1 || true"
    subprocess.Popen(["/bin/bash", "-lc", cmd])


def terminate_pid(pid: int, grace_ms: int, do_sigkill: bool) -> bool:
    try:
        os.kill(pid, signal.SIGTERM)
    except ProcessLookupError:
        return True
    except Exception as exc:
        log(f"SIGTERM failed pid={pid}: {exc}")
        return False

    time.sleep(max(0.1, grace_ms / 1000.0))
    try:
        os.kill(pid, 0)
    except ProcessLookupError:
        return True
    except Exception:
        return True

    if do_sigkill:
        try:
            os.kill(pid, signal.SIGKILL)
            return True
        except Exception as exc:
            log(f"SIGKILL failed pid={pid}: {exc}")
            return False
    return False


def write_self_ddos_event(state_dir: str, payload: dict) -> None:
    try:
        os.makedirs(state_dir, exist_ok=True)
        path = os.path.join(state_dir, "self_ddos_events.json")
        with open(path, "w", encoding="utf-8") as f:
            json.dump(payload, f, ensure_ascii=True)
    except Exception as exc:
        log(f"failed writing self-ddos event: {exc}")


def write_metrics(state_dir: str, metrics: Dict[str, int]) -> None:
    try:
        os.makedirs(state_dir, exist_ok=True)
        path = os.path.join(state_dir, "abuse_guard.prom")
        with open(path, "w", encoding="utf-8") as f:
            f.write("# HELP pteroprotect_abuse_guard_events_total Total abuse guard events by type.\n")
            f.write("# TYPE pteroprotect_abuse_guard_events_total counter\n")
            for key, value in metrics.items():
                f.write(f'pteroprotect_abuse_guard_events_total{{event=\"{key}\"}} {int(value)}\n')
    except Exception as exc:
        log(f"failed writing abuse metrics: {exc}")


def main() -> int:
    cfg = load_config()
    runtime = cfg.get("runtime", {}) if isinstance(cfg, dict) else {}
    abuse = cfg.get("abuse", {}) if isinstance(cfg, dict) else {}
    abuse_guard = cfg.get("abuse_guard", {}) if isinstance(cfg, dict) else {}

    state_dir = runtime.get("state_dir", STATE_DIR_DEFAULT)
    req_threshold = as_int(abuse.get("self_ddos_req_threshold", 100), 100)
    window_ms = as_int(abuse.get("window_ms", 500), 500)
    strike_window = as_int(abuse.get("strike_window_sec", 60), 60)
    max_strikes = as_int(abuse.get("max_strikes", 3), 3)
    grace_ms = as_int(abuse.get("sigterm_grace_ms", 1500), 1500)
    do_sigkill = bool(abuse.get("then_sigkill", True))
    escalation_ttl = as_int(abuse.get("escalation_ttl_sec", 45), 45)
    max_auto_bans = as_int(abuse_guard.get("max_auto_bans", 200), 200)

    allow_pids_raw = abuse_guard.get("allow_pids", [])
    deny_pids_raw = abuse_guard.get("deny_pids", [])
    allow_pids = set(as_int(v, -1) for v in allow_pids_raw) if isinstance(allow_pids_raw, list) else set()
    deny_pids = set(as_int(v, -1) for v in deny_pids_raw) if isinstance(deny_pids_raw, list) else set()
    allow_pids = {pid for pid in allow_pids if pid > 0}
    deny_pids = {pid for pid in deny_pids if pid > 0}

    targets = abuse.get("runtime_targets", ["node", "nodejs", "python", "python3"])
    if not isinstance(targets, list) or not targets:
        targets = ["node", "nodejs", "python", "python3"]

    gateway_ports_raw = abuse.get("gateway_ports", [80, 443, 18444])
    if isinstance(gateway_ports_raw, list):
        gateway_ports = [as_int(v, -1) for v in gateway_ports_raw]
        gateway_ports = [p for p in gateway_ports if p > 0]
    else:
        gateway_ports = [80, 443, 18444]

    # per-pid rolling counters
    prev_counts: Dict[int, int] = {}
    strikes: Dict[int, collections.deque] = {}
    escalations = collections.deque()  # unix seconds of active auto-bans
    metrics = {
        "events": 0,
        "killed": 0,
        "escalated": 0,
        "escalation_dropped_cap": 0,
        "allowlist_skipped": 0,
        "denylist_seen": 0,
    }

    log(
        f"started threshold={req_threshold}/{window_ms}ms targets={targets} ports={gateway_ports} "
        f"max_auto_bans={max_auto_bans}"
    )

    sleep_s = max(0.2, window_ms / 1000.0)
    while True:
        ts = time.time()
        pids = list_runtime_pids(targets)
        alive = set(pids)

        # cleanup stale pid states
        for pid in list(prev_counts.keys()):
            if pid not in alive:
                prev_counts.pop(pid, None)
                strikes.pop(pid, None)

        for pid in pids:
            if pid in allow_pids:
                metrics["allowlist_skipped"] += 1
                continue
            current = socket_count_for_pid(pid, gateway_ports)
            prev = prev_counts.get(pid, current)
            delta = max(0, current - prev)
            prev_counts[pid] = current

            threshold = req_threshold // 2 if pid in deny_pids else req_threshold
            if pid in deny_pids:
                metrics["denylist_seen"] += 1

            if delta < max(1, threshold):
                continue

            q = strikes.setdefault(pid, collections.deque())
            q.append(ts)
            while q and (ts - q[0]) > strike_window:
                q.popleft()

            uid = pid_uid(pid)
            log(f"self-ddos offender pid={pid} uid={uid} delta={delta} strikes={len(q)}/{max_strikes}")
            ok = terminate_pid(pid, grace_ms, do_sigkill)
            metrics["events"] += 1
            if ok:
                metrics["killed"] += 1

            event = {
                "ts": int(ts),
                "pid": pid,
                "uid": uid,
                "delta": delta,
                "window_ms": window_ms,
                "threshold": req_threshold,
                "strikes": len(q),
                "max_strikes": max_strikes,
                "action": "sigterm_sigkill" if do_sigkill else "sigterm",
                "killed": bool(ok),
                "no_lockdown": True,
            }

            if len(q) >= max_strikes:
                while escalations and (ts - escalations[0]) > max(60, escalation_ttl):
                    escalations.popleft()
                cid = resolve_container_id(pid)
                event["escalated"] = True
                event["container_id"] = cid
                if cid and len(escalations) < max_auto_bans:
                    pause_container_temporarily(cid, escalation_ttl)
                    event["escalation_action"] = "container_pause"
                    escalations.append(ts)
                    metrics["escalated"] += 1
                elif cid:
                    event["escalation_action"] = "cap_reached"
                    metrics["escalation_dropped_cap"] += 1
                else:
                    event["escalation_action"] = "none"
                q.clear()
            else:
                event["escalated"] = False

            write_self_ddos_event(state_dir, event)
            write_metrics(state_dir, metrics)

        write_metrics(state_dir, metrics)
        time.sleep(sleep_s)


if __name__ == "__main__":
    try:
        sys.exit(main())
    except KeyboardInterrupt:
        sys.exit(0)
