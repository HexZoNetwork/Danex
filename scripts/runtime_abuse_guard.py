#!/usr/bin/env python3
import collections
import json
import os
import signal
import socket
import subprocess
import sys
import time
from typing import Dict, List, Optional, Set, Tuple

CONFIG_PATH = os.environ.get("DANN_CONFIG_PATH", "/pteroprotect/config.json")
STATE_DIR_DEFAULT = "/pteroprotect/runtime"


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


def shell_quote(value: str) -> str:
    return "'" + str(value).replace("'", "'\\''") + "'"


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


def docker_kill_container(container_id: str, reason: str) -> bool:
    rc, _, err = run(["docker", "kill", container_id], timeout=8)
    if rc == 0:
        log(f"container docker-killed {container_id} reason={reason}")
        return True
    log(f"container docker-kill failed {container_id} reason={reason}: {err.strip()}")
    return False


def list_docker_container_ids() -> List[str]:
    rc, out, err = run(["docker", "ps", "--filter", "label=Service=Pterodactyl", "--format", "{{.ID}}"], timeout=8)
    if rc != 0:
        log(f"docker ps failed: {err.strip()}")
        return []
    return [line.strip() for line in out.splitlines() if line.strip()]


def container_process_text(container_id: str) -> str:
    rc, out, err = run(["docker", "top", container_id, "aux"], timeout=8)
    if rc != 0:
        log(f"docker top failed {container_id}: {err.strip()}")
        return ""
    return out.lower()


def container_name(container_id: str) -> str:
    rc, out, err = run(["docker", "inspect", "--format", "{{.Name}}", container_id], timeout=8)
    if rc != 0:
        log(f"docker inspect name failed {container_id}: {err.strip()}")
        return ""
    return out.strip().strip("/")


def is_uuid(value: str) -> bool:
    parts = value.split("-")
    if len(parts) != 5:
        return False
    sizes = [8, 4, 4, 4, 12]
    return all(len(part) == size and all(c in "0123456789abcdefABCDEF" for c in part) for part, size in zip(parts, sizes))


def dangerous_dd_reason(process_text: str, dd_if_threshold: int) -> str:
    lines = [line for line in process_text.splitlines() if " dd " in f" {line} " or "\tdd " in line or line.strip().startswith("dd ")]
    zero_hits = sum(1 for line in lines if "dd if=/dev/zero" in line or "dd if=\\/dev\\/zero" in line)
    if zero_hits > 0:
        return f"dd_zero_processes={zero_hits}"
    return ""


def has_other_dangerous_marker(process_text: str, markers: List[str]) -> str:
    for marker in markers:
        if marker in {"dd if=/dev/zero", "dd if="}:
            continue
        if marker and marker in process_text:
            return marker
    return ""


def mysql_scalar(db_cfg: dict, sql: str) -> str:
    host = str(db_cfg.get("host", "127.0.0.1"))
    user = str(db_cfg.get("user", ""))
    password = str(db_cfg.get("password", ""))
    name = str(db_cfg.get("name", ""))
    if not user or not name:
        return ""
    env = os.environ.copy()
    env["MYSQL_PWD"] = password
    cmd = ["mysql", "-N", "-B", "-h", host, "-u", user, name, "-e", sql]
    try:
        p = subprocess.run(cmd, capture_output=True, text=True, timeout=8, env=env)
        if p.returncode != 0:
            log(f"mysql query failed: {p.stderr.strip()}")
            return ""
        return p.stdout.strip().splitlines()[0].strip() if p.stdout.strip() else ""
    except Exception as exc:
        log(f"mysql query exception: {exc}")
        return ""


def suspend_server_for_container(container_id: str, db_cfg: dict, reason: str) -> bool:
    uuid = container_name(container_id)
    if not is_uuid(uuid):
        log(f"cannot suspend container {container_id}: container name is not server uuid ({uuid})")
        return False

    server_id = mysql_scalar(db_cfg, f"SELECT id FROM servers WHERE uuid={shell_quote(uuid)} LIMIT 1")
    if not server_id.isdigit():
        log(f"cannot suspend container {container_id}: server uuid not found ({uuid})")
        return False

    artisan = "/var/www/pterodactyl/artisan"
    if os.path.exists(artisan):
        cmd = [
            "php",
            artisan,
            "p:server:guard-suspension",
            server_id,
            "--action=suspend",
            f"--reason={reason}",
            "--no-interaction",
        ]
        rc, _, err = run(cmd, timeout=20)
        if rc == 0:
            log(f"server suspended via artisan server_id={server_id} uuid={uuid} reason={reason}")
            return True
        log(f"artisan suspend failed server_id={server_id} uuid={uuid}: {err.strip()}")

    updated = mysql_scalar(
        db_cfg,
        "UPDATE servers SET status='suspended', updated_at=NOW() "
        f"WHERE id={server_id} AND (status IS NULL OR status != 'suspended'); SELECT ROW_COUNT();",
    )
    ok = updated.isdigit()
    if ok:
        log(f"server suspended via db fallback server_id={server_id} uuid={uuid} reason={reason}")
    return ok


def parse_docker_cpu(value: str) -> float:
    try:
        return float(str(value).strip().rstrip("%"))
    except Exception:
        return 0.0


def docker_cpu_percent(container_id: str) -> float:
    rc, out, err = run(["docker", "stats", "--no-stream", "--format", "{{.CPUPerc}}", container_id], timeout=8)
    if rc != 0:
        log(f"docker stats failed {container_id}: {err.strip()}")
        return 0.0
    return parse_docker_cpu(out.strip().splitlines()[0] if out.strip() else "0")


def host_cpu_kill_threshold(reserved_cores: int, min_threshold_pct: int) -> int:
    cores = max(1, os.cpu_count() or 1)
    usable = max(1, cores - max(1, reserved_cores))
    return max(min_threshold_pct, usable * 100)


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
    db_cfg = cfg.get("database", {}) if isinstance(cfg, dict) and isinstance(cfg.get("database"), dict) else {}
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
    docker_kill_enabled = bool(abuse_guard.get("docker_kill_enabled", True))
    docker_cpu_reserved_cores = as_int(abuse_guard.get("docker_cpu_reserved_cores", 1), 1)
    docker_cpu_min_threshold_pct = as_int(abuse_guard.get("docker_cpu_min_threshold_pct", 80), 80)
    docker_cpu_strikes_required = max(1, as_int(abuse_guard.get("docker_cpu_strikes_required", 2), 2))
    docker_scan_interval_sec = max(1, as_int(abuse_guard.get("docker_scan_interval_sec", 2), 2))
    docker_suspend_on_dangerous_process = bool(abuse_guard.get("docker_suspend_on_dangerous_process", True))
    dangerous_dd_if_threshold = max(1, as_int(abuse_guard.get("dangerous_dd_if_threshold", 3), 3))
    docker_cpu_kill_pct = as_int(
        abuse_guard.get("docker_cpu_kill_pct", host_cpu_kill_threshold(docker_cpu_reserved_cores, docker_cpu_min_threshold_pct)),
        host_cpu_kill_threshold(docker_cpu_reserved_cores, docker_cpu_min_threshold_pct),
    )
    dangerous_proc_markers_raw = abuse_guard.get(
        "dangerous_process_markers",
        ["dd if=/dev/zero", "dd if=", "stress-ng", "stress --cpu", "yes >", "yes>>"],
    )
    dangerous_proc_markers = (
        [str(x).strip().lower() for x in dangerous_proc_markers_raw if str(x).strip()]
        if isinstance(dangerous_proc_markers_raw, list)
        else ["dd if=/dev/zero", "dd if=", "stress-ng", "stress --cpu"]
    )

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
        "container_killed": 0,
        "container_cpu_strike": 0,
        "container_dangerous_process": 0,
        "server_suspended": 0,
    }
    container_cpu_strikes: Dict[str, collections.deque] = {}
    docker_killed: Set[str] = set()
    last_docker_scan = 0.0

    log(
        f"started threshold={req_threshold}/{window_ms}ms targets={targets} ports={gateway_ports} "
        f"max_auto_bans={max_auto_bans} docker_kill={docker_kill_enabled} docker_cpu_kill_pct={docker_cpu_kill_pct}"
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
                    if docker_kill_enabled and cid not in docker_killed:
                        killed = docker_kill_container(cid, "self_ddos_socket_strikes")
                        event["escalation_action"] = "container_docker_kill"
                        event["container_killed"] = killed
                        docker_killed.add(cid)
                        if killed:
                            metrics["container_killed"] += 1
                    else:
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

        if docker_kill_enabled and (ts - last_docker_scan) >= docker_scan_interval_sec:
            last_docker_scan = ts
            for cid in list_docker_container_ids():
                if cid in docker_killed:
                    continue
                process_text = container_process_text(cid)
                dd_reason = dangerous_dd_reason(process_text, dangerous_dd_if_threshold)
                marker_reason = has_other_dangerous_marker(process_text, dangerous_proc_markers)
                dangerous_reason = dd_reason or (f"marker={marker_reason}" if marker_reason else "")
                if dangerous_reason:
                    if docker_kill_container(cid, "dangerous_process_marker"):
                        docker_killed.add(cid)
                        metrics["container_killed"] += 1
                        metrics["container_dangerous_process"] += 1
                        suspended = False
                        if docker_suspend_on_dangerous_process:
                            suspended = suspend_server_for_container(
                                cid,
                                db_cfg,
                                f"runtime investigate dangerous process: {dangerous_reason}",
                            )
                            if suspended:
                                metrics["server_suspended"] += 1
                        write_self_ddos_event(
                            state_dir,
                            {
                                "ts": int(ts),
                                "container_id": cid,
                                "reason": "dangerous_process_marker",
                                "detail": dangerous_reason,
                                "action": "container_docker_kill",
                                "killed": True,
                                "server_suspended": suspended,
                            },
                        )
                    continue

                cpu_pct = docker_cpu_percent(cid)
                q = container_cpu_strikes.setdefault(cid, collections.deque())
                if cpu_pct >= docker_cpu_kill_pct:
                    q.append(ts)
                    metrics["container_cpu_strike"] += 1
                    log(f"container cpu strike cid={cid} cpu={cpu_pct:.1f}% threshold={docker_cpu_kill_pct}% strikes={len(q)}/{docker_cpu_strikes_required}")
                else:
                    q.clear()
                while q and (ts - q[0]) > strike_window:
                    q.popleft()
                if len(q) >= docker_cpu_strikes_required:
                    if docker_kill_container(cid, f"cpu_over_budget_{cpu_pct:.1f}_pct"):
                        docker_killed.add(cid)
                        metrics["container_killed"] += 1
                        write_self_ddos_event(
                            state_dir,
                            {
                                "ts": int(ts),
                                "container_id": cid,
                                "reason": "cpu_over_budget",
                                "cpu_pct": cpu_pct,
                                "threshold_pct": docker_cpu_kill_pct,
                                "action": "container_docker_kill",
                                "killed": True,
                            },
                        )

        write_metrics(state_dir, metrics)
        time.sleep(sleep_s)


if __name__ == "__main__":
    try:
        sys.exit(main())
    except KeyboardInterrupt:
        sys.exit(0)
