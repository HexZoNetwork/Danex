#!/usr/bin/env python3
import collections
import fcntl
import json
import os
import re
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


def host_mem_mb() -> int:
    try:
        with open("/proc/meminfo", "r", encoding="utf-8", errors="ignore") as f:
            for line in f:
                if line.startswith("MemTotal:"):
                    return max(1, int(line.split()[1]) // 1024)
    except Exception:
        pass
    return 8192


def mem_available_mb() -> int:
    try:
        with open("/proc/meminfo", "r", encoding="utf-8", errors="ignore") as f:
            for line in f:
                if line.startswith("MemAvailable:"):
                    return max(0, int(line.split()[1]) // 1024)
    except Exception:
        pass
    return 0


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


def proc_cmdline(pid: int) -> str:
    try:
        with open(f"/proc/{pid}/cmdline", "rb") as f:
            return f.read().replace(b"\x00", b" ").decode("utf-8", errors="ignore").strip()
    except Exception:
        return ""


def proc_comm(pid: int) -> str:
    try:
        with open(f"/proc/{pid}/comm", "r", encoding="utf-8", errors="ignore") as f:
            return f.read().strip()
    except Exception:
        return ""


def list_host_dd_zero_by_container() -> Dict[str, List[str]]:
    hits: Dict[str, List[str]] = {}
    for entry in os.listdir("/proc"):
        if not entry.isdigit():
            continue
        pid = int(entry)
        comm = proc_comm(pid).lower()
        cmd = proc_cmdline(pid)
        low = cmd.lower()
        if comm != "dd" and not low.startswith("dd ") and " dd " not in f" {low} ":
            continue
        if "if=/dev/zero" not in low and "if=\\/dev\\/zero" not in low:
            continue
        cid = resolve_container_id(pid)
        if not cid:
            continue
        hits.setdefault(cid, []).append(f"pid={pid} uid={pid_uid(pid)} cmd={cmd[:400]}")
    return hits


def top_memory_processes(limit: int = 8) -> List[str]:
    rows = []
    page_kb = os.sysconf("SC_PAGE_SIZE") // 1024
    for entry in os.listdir("/proc"):
        if not entry.isdigit():
            continue
        pid = int(entry)
        try:
            with open(f"/proc/{pid}/statm", "r", encoding="utf-8", errors="ignore") as f:
                parts = f.read().split()
            rss_kb = int(parts[1]) * page_kb if len(parts) > 1 else 0
            rows.append((rss_kb, pid, proc_comm(pid), proc_cmdline(pid)[:160]))
        except Exception:
            continue
    return [f"rss_mb={rss_kb // 1024} pid={pid} comm={comm} cmd={cmd}" for rss_kb, pid, comm, cmd in sorted(rows, reverse=True)[:limit]]


def pause_container_temporarily(container_id: str, ttl_sec: int) -> None:
    rc, _, err = run(["docker", "pause", container_id], timeout=5)
    if rc != 0:
        log(f"container pause failed {container_id}: {err.strip()}")
        return
    log(f"container paused {container_id} for {ttl_sec}s")
    cmd = f"sleep {max(1, ttl_sec)}; docker unpause {container_id} >/dev/null 2>&1 || true"
    subprocess.Popen(["/bin/bash", "-lc", cmd])


def pause_container(container_id: str) -> bool:
    rc, _, err = run(["docker", "pause", container_id], timeout=5)
    if rc == 0:
        log(f"container paused {container_id}")
        return True
    if "is already paused" in err.lower():
        return True
    log(f"container pause failed {container_id}: {err.strip()}")
    return False


def docker_kill_container(container_id: str, reason: str) -> bool:
    rc, _, err = run(["docker", "kill", container_id], timeout=8)
    if rc == 0:
        log(f"container docker-killed {container_id} reason={reason}")
        return True
    log(f"container docker-kill failed {container_id} reason={reason}: {err.strip()}")
    return False


def docker_stop_container(container_id: str, reason: str) -> bool:
    rc, _, err = run(["docker", "stop", "--time", "8", container_id], timeout=15)
    if rc == 0:
        log(f"container docker-stopped {container_id} reason={reason}")
        return True
    if "is already stopped" in err.lower() or "not running" in err.lower():
        return True
    log(f"container docker-stop failed {container_id} reason={reason}: {err.strip()}")
    return False


def is_pterodactyl_container(container_id: str) -> bool:
    rc, out, err = run(["docker", "inspect", "--format", "{{json .Config.Labels}} {{.Name}}", container_id], timeout=8)
    if rc != 0:
        log(f"docker inspect labels failed {container_id}: {err.strip()}")
        return False
    try:
        labels_raw, name = out.strip().split(" ", 1)
        labels = json.loads(labels_raw) if labels_raw and labels_raw != "<no value>" else {}
        return labels.get("Service") == "Pterodactyl" and labels.get("ContainerType") == "server_process" and is_uuid(name.strip().strip("/"))
    except Exception:
        return False


def docker_update_memory(container_id: str, memory_mb: int) -> bool:
    memory_mb = max(256, int(memory_mb))
    rc, _, err = run(
        ["docker", "update", "--memory", f"{memory_mb}m", "--memory-swap", f"{memory_mb}m", container_id],
        timeout=8,
    )
    if rc == 0:
        log(f"container memory clamped {container_id} memory={memory_mb}m swap={memory_mb}m")
        return True
    log(f"container memory clamp failed {container_id}: {err.strip()}")
    return False


def list_docker_container_ids() -> List[str]:
    rc, out, err = run(["docker", "ps", "--filter", "label=Service=Pterodactyl", "--format", "{{.ID}}"], timeout=8)
    if rc != 0:
        log(f"docker ps failed: {err.strip()}")
        return []
    return [line.strip() for line in out.splitlines() if line.strip()]


def container_memory_limit_bytes(container_id: str) -> int:
    rc, out, err = run(["docker", "inspect", "--format", "{{.HostConfig.Memory}}", container_id], timeout=8)
    if rc != 0:
        log(f"docker inspect memory failed {container_id}: {err.strip()}")
        return -1
    try:
        return int(out.strip())
    except Exception:
        return -1


def enforce_container_memory_limit(container_id: str, max_mb: int) -> bool:
    if max_mb <= 0:
        return False
    current = container_memory_limit_bytes(container_id)
    if current < 0:
        return False
    max_bytes = int(max_mb) * 1024 * 1024
    if current == 0 or current > max_bytes:
        return docker_update_memory(container_id, max_mb)
    return False


def container_process_text(container_id: str) -> str:
    rc, out, err = run(["docker", "top", container_id, "aux"], timeout=8)
    if rc != 0:
        log(f"docker top failed {container_id}: {err.strip()}")
        return ""
    return out.lower()


def container_process_text_raw(container_id: str) -> str:
    rc, out, err = run(["docker", "top", container_id, "aux"], timeout=8)
    if rc != 0:
        log(f"docker top failed {container_id}: {err.strip()}")
        return ""
    return out


def container_name(container_id: str) -> str:
    rc, out, err = run(["docker", "inspect", "--format", "{{.Name}}", container_id], timeout=8)
    if rc != 0:
        log(f"docker inspect name failed {container_id}: {err.strip()}")
        return ""
    return out.strip().strip("/")


def container_inspect_json(container_id: str) -> dict:
    rc, out, err = run(["docker", "inspect", container_id], timeout=8)
    if rc != 0:
        log(f"docker inspect failed {container_id}: {err.strip()}")
        return {}
    try:
        data = json.loads(out)
        if isinstance(data, list) and data and isinstance(data[0], dict):
            item = data[0]
            image_ref = item.get("Config", {}).get("Image", "")
            image_digests = []
            if image_ref:
                rc2, out2, _ = run(["docker", "image", "inspect", "--format", "{{json .RepoDigests}}", image_ref], timeout=8)
                if rc2 == 0 and out2.strip():
                    try:
                        parsed = json.loads(out2.strip())
                        if isinstance(parsed, list):
                            image_digests = parsed
                    except Exception:
                        image_digests = []
            return {
                "id": item.get("Id", ""),
                "name": str(item.get("Name", "")).strip("/"),
                "image": item.get("Image", ""),
                "image_ref": image_ref,
                "image_digests": image_digests,
                "created": item.get("Created", ""),
                "state": item.get("State", {}),
                "labels": item.get("Config", {}).get("Labels", {}),
                "mounts": item.get("Mounts", []),
            }
    except Exception as exc:
        log(f"docker inspect parse failed {container_id}: {exc}")
    return {}


def is_uuid(value: str) -> bool:
    parts = value.split("-")
    if len(parts) != 5:
        return False
    sizes = [8, 4, 4, 4, 12]
    return all(len(part) == size and all(c in "0123456789abcdefABCDEF" for c in part) for part, size in zip(parts, sizes))


def dangerous_dd_reason(process_text: str, dd_if_threshold: int) -> str:
    lines = [line for line in process_text.splitlines() if " dd " in f" {line} " or "\tdd " in line or line.strip().startswith("dd ")]
    zero_hits = sum(1 for line in lines if "dd if=/dev/zero" in line or "dd if=\\/dev\\/zero" in line)
    if zero_hits >= max(1, dd_if_threshold):
        return f"dd_zero_processes={zero_hits}"
    return ""


def dangerous_dd_seen(process_text: str) -> str:
    lines = [line for line in process_text.splitlines() if " dd " in f" {line} " or "\tdd " in line or line.strip().startswith("dd ")]
    zero_hits = sum(1 for line in lines if "dd if=/dev/zero" in line or "dd if=\\/dev\\/zero" in line)
    return f"dd_zero_processes={zero_hits}" if zero_hits > 0 else ""


def redact_process_text(text: str, limit_lines: int = 80) -> str:
    lines = []
    for line in text.splitlines()[:limit_lines]:
        line = re.sub(r"(?i)(token|password|passwd|secret|key)=\S+", r"\1=<redacted>", line)
        line = re.sub(r"(?i)(--(?:token|password|passwd|secret|key))\s+\S+", r"\1 <redacted>", line)
        line = re.sub(r"(?i)bearer\s+[A-Za-z0-9._~+/=-]+", "Bearer <redacted>", line)
        lines.append(line[:500])
    return "\n".join(lines)


def write_container_incident(state_dir: str, container_id: str, reason: str, process_text: str) -> None:
    try:
        os.makedirs(state_dir, exist_ok=True)
        path = os.path.join(state_dir, f"container_incident_{container_id}_{int(time.time())}.json")
        payload = {
            "ts": int(time.time()),
            "container_id": container_id,
            "name": container_name(container_id),
            "reason": reason,
            "inspect": container_inspect_json(container_id),
            "processes": redact_process_text(process_text),
        }
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, "w", encoding="utf-8") as f:
            json.dump(payload, f, ensure_ascii=True, indent=2)
    except Exception as exc:
        log(f"failed writing container incident: {exc}")


def write_quarantine_marker(state_dir: str, container_id: str, reason: str) -> None:
    try:
        os.makedirs(state_dir, exist_ok=True)
        name = container_name(container_id)
        key = name if is_uuid(name) else container_id
        path = os.path.join(state_dir, f"quarantine_{key}.json")
        payload = {
            "ts": int(time.time()),
            "container_id": container_id,
            "server_uuid": name if is_uuid(name) else "",
            "reason": reason,
        }
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, "w", encoding="utf-8") as f:
            json.dump(payload, f, ensure_ascii=True, indent=2)
    except Exception as exc:
        log(f"failed writing quarantine marker: {exc}")


def dangerous_strike_key(container_id: str) -> str:
    name = container_name(container_id)
    return name if is_uuid(name) else container_id


def load_dangerous_strikes(state_dir: str) -> dict:
    path = os.path.join(state_dir, "dangerous_container_strikes.json")
    try:
        with open(path, "r", encoding="utf-8") as f:
            data = json.load(f)
        return data if isinstance(data, dict) else {}
    except Exception:
        return {}


def save_dangerous_strikes(state_dir: str, data: dict) -> None:
    try:
        os.makedirs(state_dir, exist_ok=True)
        path = os.path.join(state_dir, "dangerous_container_strikes.json")
        tmp = f"{path}.{os.getpid()}.tmp"
        with open(tmp, "w", encoding="utf-8") as f:
            json.dump(data, f, ensure_ascii=True, indent=2, sort_keys=True)
            f.write("\n")
            f.flush()
            os.fsync(f.fileno())
        os.replace(tmp, path)
        try:
            dir_fd = os.open(state_dir, os.O_RDONLY)
            try:
                os.fsync(dir_fd)
            finally:
                os.close(dir_fd)
        except Exception:
            pass
    except Exception as exc:
        log(f"failed writing dangerous strikes: {exc}")


def register_dangerous_container_strike(state_dir: str, container_id: str, reason: str, window_sec: int = 86400) -> int:
    key = dangerous_strike_key(container_id)
    now = int(time.time())
    os.makedirs(state_dir, exist_ok=True)
    lock_path = os.path.join(state_dir, "dangerous_container_strikes.lock")
    with open(lock_path, "a+", encoding="utf-8") as lock_file:
        fcntl.flock(lock_file.fileno(), fcntl.LOCK_EX)
        data = load_dangerous_strikes(state_dir)
        items = data.setdefault("containers", {})
        if not isinstance(items, dict):
            items = {}
            data["containers"] = items
        rec = items.get(key, {}) if isinstance(items.get(key, {}), dict) else {}
        last_ts = int(rec.get("last_ts", 0) or 0)
        if last_ts and now - last_ts > max(60, window_sec):
            rec = {}
        rec["count"] = int(rec.get("count", 0)) + 1
        rec["first_ts"] = int(rec.get("first_ts", now) or now)
        rec["last_ts"] = now
        rec["last_reason"] = reason
        rec["container_id"] = container_id
        rec["server_uuid"] = key if is_uuid(key) else ""
        items[key] = rec
        data["updated_ts"] = now
        data["window_sec"] = max(60, int(window_sec))
        save_dangerous_strikes(state_dir, data)
        fcntl.flock(lock_file.fileno(), fcntl.LOCK_UN)
        return int(rec["count"])


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


def contain_dangerous_container(
    state_dir: str,
    db_cfg: dict,
    metrics: Dict[str, int],
    docker_killed: Set[str],
    cid: str,
    reason: str,
    process_text_raw: str,
    suspend_enabled: bool,
    stop_enabled: bool,
    suspend_after: int,
    strike_window_sec: int,
) -> None:
    if cid in docker_killed:
        return
    if not is_pterodactyl_container(cid):
        log(f"skip non-pterodactyl dangerous container cid={cid} reason={reason}")
        return
    write_container_incident(state_dir, cid, reason, process_text_raw)
    write_quarantine_marker(state_dir, cid, reason)
    strike_count = register_dangerous_container_strike(state_dir, cid, reason, strike_window_sec)
    paused = False
    stopped = False
    if stop_enabled:
        stopped = docker_stop_container(cid, "dangerous_process_marker")
    else:
        paused = pause_container(cid)
    suspended = False
    if suspend_enabled and strike_count >= suspend_after:
        suspended = suspend_server_for_container(
            cid,
            db_cfg,
            f"runtime repeated dangerous process strikes={strike_count}: {reason}",
        )
        if suspended:
            metrics["server_suspended"] += 1
    if not stop_enabled:
        write_self_ddos_event(
            state_dir,
            {
                "ts": int(time.time()),
                "container_id": cid,
                "reason": "dangerous_process_marker",
                "detail": reason,
                "action": "container_pause_quarantine",
                "paused": paused,
                "stopped": False,
                "server_suspended": suspended,
                "strike_count": strike_count,
                "suspend_after": suspend_after,
            },
        )
        return
    if stopped:
        docker_killed.add(cid)
        metrics["container_killed"] += 1
        metrics["container_dangerous_process"] += 1
        write_self_ddos_event(
            state_dir,
            {
                "ts": int(time.time()),
                "container_id": cid,
                "reason": "dangerous_process_marker",
                "detail": reason,
                "action": "container_pause_quarantine_stop" + ("_suspend" if suspended else ""),
                "paused": paused,
                "stopped": True,
                "server_suspended": suspended,
                "strike_count": strike_count,
                "suspend_after": suspend_after,
            },
        )


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
    dangerous_suspend_after = max(1, as_int(abuse_guard.get("dangerous_suspend_after", 5), 5))
    dangerous_strike_window_sec = max(60, as_int(abuse_guard.get("dangerous_strike_window_sec", 86400), 86400))
    docker_memory_clamp_enabled = bool(abuse_guard.get("docker_memory_clamp_enabled", True))
    docker_memory_max_mb = max(256, as_int(abuse_guard.get("docker_memory_max_mb", 0), 0))
    if as_int(abuse_guard.get("docker_memory_max_mb", 0), 0) <= 0:
        docker_memory_max_mb = max(768, min(2048, (host_mem_mb() - 3072) // 4))
    host_proc_dd_scan_enabled = bool(abuse_guard.get("host_proc_dd_scan_enabled", True))
    oom_risk_log_enabled = bool(abuse_guard.get("oom_risk_log_enabled", True))
    oom_risk_mem_available_mb = max(128, as_int(abuse_guard.get("oom_risk_mem_available_mb", 1024), 1024))
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
        "host_proc_dangerous_process": 0,
        "oom_risk": 0,
        "server_suspended": 0,
    }
    container_cpu_strikes: Dict[str, collections.deque] = {}
    docker_killed: Set[str] = set()
    docker_memory_clamped: Set[str] = set()
    last_docker_scan = 0.0
    last_oom_risk_log = 0.0

    log(
        f"started threshold={req_threshold}/{window_ms}ms targets={targets} ports={gateway_ports} "
        f"max_auto_bans={max_auto_bans} docker_kill={docker_kill_enabled} docker_cpu_kill_pct={docker_cpu_kill_pct} "
        f"docker_memory_clamp={docker_memory_clamp_enabled} docker_memory_max_mb={docker_memory_max_mb} "
        f"host_proc_dd_scan={host_proc_dd_scan_enabled} dangerous_suspend_after={dangerous_suspend_after} "
        f"dangerous_strike_window_sec={dangerous_strike_window_sec} "
        f"oom_risk_mem_available_mb={oom_risk_mem_available_mb}"
    )

    sleep_s = max(0.2, window_ms / 1000.0)
    while True:
        ts = time.time()
        if oom_risk_log_enabled and (ts - last_oom_risk_log) >= 30:
            avail = mem_available_mb()
            if 0 < avail < oom_risk_mem_available_mb:
                metrics["oom_risk"] += 1
                log(f"oom-risk mem_available_mb={avail} threshold_mb={oom_risk_mem_available_mb} top={top_memory_processes()}")
                last_oom_risk_log = ts
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
                        write_quarantine_marker(state_dir, cid, "self_ddos_socket_strikes")
                        stopped = docker_stop_container(cid, "self_ddos_socket_strikes")
                        event["escalation_action"] = "container_docker_stop"
                        event["container_stopped"] = stopped
                        docker_killed.add(cid)
                        if stopped:
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

        if (ts - last_docker_scan) >= docker_scan_interval_sec:
            last_docker_scan = ts
            if host_proc_dd_scan_enabled:
                for cid, lines in list_host_dd_zero_by_container().items():
                    if cid in docker_killed:
                        continue
                    if len(lines) < dangerous_dd_if_threshold:
                        continue
                    metrics["host_proc_dangerous_process"] += 1
                    contain_dangerous_container(
                        state_dir,
                        db_cfg,
                        metrics,
                        docker_killed,
                        cid,
                        f"host_proc_dd_zero_processes={len(lines)}",
                        "\n".join(lines),
                        docker_suspend_on_dangerous_process,
                        docker_kill_enabled,
                        dangerous_suspend_after,
                        dangerous_strike_window_sec,
                    )
            for cid in list_docker_container_ids():
                if cid in docker_killed:
                    continue
                if docker_memory_clamp_enabled and cid not in docker_memory_clamped:
                    if enforce_container_memory_limit(cid, docker_memory_max_mb):
                        docker_memory_clamped.add(cid)
                process_text_raw = container_process_text_raw(cid)
                process_text = process_text_raw.lower()
                dd_reason = dangerous_dd_reason(process_text, dangerous_dd_if_threshold)
                marker_reason = has_other_dangerous_marker(process_text, dangerous_proc_markers)
                dangerous_reason = dd_reason or (f"marker={marker_reason}" if marker_reason else "")
                if dangerous_reason:
                    contain_dangerous_container(
                        state_dir,
                        db_cfg,
                        metrics,
                        docker_killed,
                        cid,
                        dangerous_reason,
                        process_text_raw,
                        docker_suspend_on_dangerous_process,
                        docker_kill_enabled,
                        dangerous_suspend_after,
                        dangerous_strike_window_sec,
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
                    if not docker_kill_enabled:
                        write_self_ddos_event(
                            state_dir,
                            {
                                "ts": int(ts),
                                "container_id": cid,
                                "reason": "cpu_over_budget",
                                "cpu_pct": cpu_pct,
                                "threshold_pct": docker_cpu_kill_pct,
                                "action": "container_cpu_strike_no_auto_stop",
                                "stopped": False,
                            },
                        )
                        q.clear()
                        continue
                    if docker_stop_container(cid, f"cpu_over_budget_{cpu_pct:.1f}_pct"):
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
                                "action": "container_docker_stop",
                                "stopped": True,
                            },
                        )

        write_metrics(state_dir, metrics)
        time.sleep(sleep_s)


if __name__ == "__main__":
    try:
        sys.exit(main())
    except KeyboardInterrupt:
        sys.exit(0)
