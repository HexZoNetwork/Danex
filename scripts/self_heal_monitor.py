#!/usr/bin/env python3
import json
import os
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from typing import Dict, Optional, Tuple

CONFIG_PATH = os.environ.get("DANN_CONFIG_PATH", "/pteroprotect/config.json")
SHM_RUNTIME_DIR = os.environ.get("PTEROPROTECT_RUNTIME_DIR", "/dev/shm/pteroprotect")
PANEL_RUNTIME_DIR = os.environ.get("PTEROPROTECT_PANEL_RUNTIME_DIR", "/pteroprotect/runtime")
DEFAULT_CHALLENGE_PATH = "/__pteroprotect/challenge/page"


def is_placeholder_url(value: str) -> bool:
    try:
        host = urllib.parse.urlparse(str(value).strip()).hostname or ""
    except Exception:
        return True
    host = host.lower().strip(".")
    return host in {"", "example.com", "www.example.com", "example.net", "example.org"}


def resolve_external_url(cfg: dict, monitor: dict) -> str:
    monitor_url = str(monitor.get("external_url", "")).strip()
    ptlc_url = str(cfg.get("ptlc", {}).get("url", "")).strip() if isinstance(cfg, dict) else ""
    if monitor_url and not is_placeholder_url(monitor_url):
        return monitor_url.rstrip("/")
    if ptlc_url and not is_placeholder_url(ptlc_url):
        return ptlc_url.rstrip("/")
    return monitor_url.rstrip("/")


def log(msg: str) -> None:
    ts = time.strftime("%Y-%m-%d %H:%M:%S", time.gmtime())
    print(f"[{ts}] [self-heal] {msg}", flush=True)


def run(cmd, timeout=8) -> Tuple[int, str, str]:
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
        log(f"config load failed: {exc}")
        return {}


def as_int(v, d: int) -> int:
    try:
        return int(v)
    except Exception:
        return d


def as_float(v, d: float) -> float:
    try:
        return float(v)
    except Exception:
        return d


def http_probe(url: str, timeout_sec: float = 6.0) -> Tuple[bool, int, float, str]:
    if not url:
        return False, 0, 0.0, "empty-url"
    req = urllib.request.Request(url, method="GET", headers={"User-Agent": "DanexSelfHeal/1.0"})
    start = time.time()
    try:
        with urllib.request.urlopen(req, timeout=timeout_sec) as resp:
            body = resp.read(256)
            elapsed = (time.time() - start) * 1000.0
            code = int(resp.status)
            return True, code, elapsed, body.decode("utf-8", errors="ignore")
    except urllib.error.HTTPError as exc:
        elapsed = (time.time() - start) * 1000.0
        try:
            body = exc.read(256)
        except Exception:
            body = b""
        return True, int(exc.code), elapsed, body.decode("utf-8", errors="ignore")
    except Exception as exc:
        elapsed = (time.time() - start) * 1000.0
        return False, 0, elapsed, str(exc)


def checkhost_probe(url: str, max_nodes: int = 8, zero_threshold: int = 3) -> Tuple[bool, float, str]:
    if not url:
        return False, 0.0, "empty-url"
    try:
        req_init = urllib.request.Request(
            f"https://check-host.net/check-http?{urllib.parse.urlencode({'host': url, 'max_nodes': str(max(1, max_nodes))})}",
            headers={"Accept": "application/json", "User-Agent": "DanexSelfHeal/1.0"},
            method="GET",
        )
        with urllib.request.urlopen(req_init, timeout=5) as resp:
            data = json.loads(resp.read().decode("utf-8", errors="ignore"))

        if as_int(data.get("ok", 0), 0) != 1:
            return False, 0.0, "checkhost-init-not-ok"
        req_id = data.get("request_id", "")
        nodes = data.get("nodes", {})
        if not req_id or not isinstance(nodes, dict) or not nodes:
            return False, 0.0, "checkhost-init-invalid"
        node_ids = list(nodes.keys())

        # wait/poll for result propagation
        res = {}
        for _ in range(3):
            time.sleep(1.0)
            req_res = urllib.request.Request(
                f"https://check-host.net/check-result/{req_id}",
                headers={"Accept": "application/json", "User-Agent": "DanexSelfHeal/1.0"},
                method="GET",
            )
            with urllib.request.urlopen(req_res, timeout=5) as resp:
                res = json.loads(resp.read().decode("utf-8", errors="ignore"))
            if any(res.get(n) is not None for n in node_ids):
                break

        completed_nodes = 0
        ok_nodes = 0
        zero_nodes = 0
        latencies_ms = []
        last_summary = "unknown"

        for nid in node_ids:
            node_payload = res.get(nid)
            if node_payload is None:
                continue

            first = None
            if isinstance(node_payload, list) and node_payload:
                first = node_payload[0]
                if isinstance(first, list) and first and isinstance(first[0], list):
                    first = first[0]
            if not isinstance(first, list) or len(first) < 2:
                continue

            completed_nodes += 1
            ok_flag = first[0]
            latency_sec = as_float(first[1], 0.0)
            status_text = str(first[2]) if len(first) > 2 and first[2] is not None else ""
            status_code = str(first[3]) if len(first) > 3 and first[3] is not None else ""

            ok = False
            if isinstance(ok_flag, bool):
                ok = ok_flag
            else:
                ok = as_int(ok_flag, 0) == 1
            is_zero_node = False
            if status_code.isdigit():
                code_num = int(status_code)
                ok = (code_num == 200)
                if code_num == 0:
                    is_zero_node = True
            elif as_int(ok_flag, 1) == 0:
                is_zero_node = True

            if ok:
                ok_nodes += 1
                latencies_ms.append(max(0.0, latency_sec * 1000.0))

            status_l = status_text.lower()
            if "timed out" in status_l or "timeout" in status_l:
                is_zero_node = True

            if is_zero_node:
                zero_nodes += 1

            last_summary = status_code if status_code else (status_text or "unknown")

        if completed_nodes == 0:
            return False, 0.0, "checkhost-result-missing"

        if zero_nodes >= max(1, zero_threshold):
            latency = sum(latencies_ms) / len(latencies_ms) if latencies_ms else 0.0
            return False, latency, f"zero_nodes={zero_nodes}/{completed_nodes}"

        latency = sum(latencies_ms) / len(latencies_ms) if latencies_ms else 0.0
        success = ok_nodes > 0
        return success, latency, f"ok_nodes={ok_nodes}/{completed_nodes};last={last_summary}"
    except Exception as exc:
        return False, 0.0, str(exc)


def systemd_active(name: str) -> bool:
    rc, out, _ = run(["systemctl", "is-active", name], timeout=4)
    return rc == 0 and out.strip() == "active"


def write_file(path: str, payload: str) -> None:
    try:
        os.makedirs(os.path.dirname(path), exist_ok=True)
        with open(path, "w", encoding="utf-8") as f:
            f.write(payload + "\n")
    except Exception as exc:
        log(f"write failed path={path}: {exc}")


def write_metrics(base_dir: str, values: Dict[str, float]) -> None:
    lines = [
        "# HELP pteroprotect_selfheal External and local health monitor metrics.",
        "# TYPE pteroprotect_selfheal gauge",
    ]
    for key, value in values.items():
        lines.append(f"pteroprotect_selfheal{{metric=\"{key}\"}} {value}")
    write_file(os.path.join(base_dir, "self_heal.prom"), "\n".join(lines))


def set_mode(mode: str) -> None:
    now = int(time.time())
    payload = json.dumps({"mode": mode, "updated_at": now}, ensure_ascii=True)
    write_file(os.path.join(SHM_RUNTIME_DIR, "mode.flag"), payload)
    write_file(os.path.join(PANEL_RUNTIME_DIR, "mode.json"), payload)


def set_lockdown(enabled: bool, ttl_sec: int = 0, reason: str = "") -> None:
    now = int(time.time())
    if enabled:
        until = now + max(30, ttl_sec)
        shm_payload = json.dumps({"enabled": True, "reason": reason, "until": until, "updated_at": now}, ensure_ascii=True)
        panel_payload = shm_payload
    else:
        shm_payload = json.dumps({"enabled": False, "updated_at": now}, ensure_ascii=True)
        panel_payload = shm_payload
    write_file(os.path.join(SHM_RUNTIME_DIR, "strict_lockdown.flag"), shm_payload)
    write_file(os.path.join(PANEL_RUNTIME_DIR, "lockdown.json"), panel_payload)


def read_recent_self_ddos(state_dir: str, max_age_sec: int = 30) -> bool:
    p = os.path.join(state_dir, "self_ddos_events.json")
    try:
        with open(p, "r", encoding="utf-8") as f:
            data = json.load(f)
        ts = as_int(data.get("ts", 0), 0)
        return ts > 0 and (time.time() - ts) <= max_age_sec
    except Exception:
        return False


def trigger_self_heal() -> None:
    # phased self-heal: php-fpm -> nginx -> wings
    services = ["php8.3-fpm", "nginx", "wings"]
    for svc in services:
        if systemd_active(svc):
            continue
        log(f"service {svc} not active, restarting")
        run(["systemctl", "restart", svc], timeout=12)
    # always reload nginx to apply transient mitigations
    run(["systemctl", "reload", "nginx"], timeout=8)


def main() -> int:
    cfg = load_config()
    runtime = cfg.get("runtime", {}) if isinstance(cfg, dict) else {}
    monitor = cfg.get("monitor", {}) if isinstance(cfg, dict) else {}

    state_dir = runtime.get("state_dir", "/pteroprotect/runtime")
    checkhost_enabled = bool(monitor.get("checkhost_enabled", True))
    checkhost_max_nodes = max(1, as_int(monitor.get("checkhost_max_nodes", 8), 8))
    checkhost_zero_node_threshold = max(1, as_int(monitor.get("checkhost_zero_node_threshold", 3), 3))
    base_url = resolve_external_url(cfg, monitor)
    challenge_path = str(monitor.get("challenge_path", DEFAULT_CHALLENGE_PATH))
    local_health_url = str(monitor.get("local_health_url", "http://127.0.0.1:18080/api/system"))

    normal_sec = max(2, as_int(monitor.get("check_interval_normal_sec", 5), 5))
    anomaly_sec = max(2, as_int(monitor.get("check_interval_anomaly_sec", 2), 2))
    lockdown_ttl_sec = max(60, as_int(monitor.get("lockdown_ttl_sec", 180), 180))

    p95_threshold_ms = as_float(monitor.get("latency_p95_ms_threshold", 10000), 10000)
    error_rate_threshold = as_float(monitor.get("error_rate_threshold", 0.5), 0.5)
    external_fail_streak_threshold = max(2, as_int(monitor.get("external_fail_streak_threshold", 3), 3))

    window = []  # list[(ts, ok, latency_ms)]
    external_fail_streak = 0
    mode = "normal"

    log(f"started url={base_url} checkhost_primary={checkhost_enabled}")

    while True:
        ts = time.time()
        external_ok = False
        external_latency = 0.0
        external_src = ""

        challenge_url = (base_url + challenge_path) if base_url else ""

        if checkhost_enabled and challenge_url:
            ok, lat, src = checkhost_probe(
                challenge_url,
                max_nodes=checkhost_max_nodes,
                zero_threshold=checkhost_zero_node_threshold,
            )
            external_ok, external_latency, external_src = ok, lat, src
            if not external_ok:
                # fallback local immediately
                ok2, code2, lat2, _ = http_probe(local_health_url, timeout_sec=5.0)
                external_ok = ok2 and code2 in (200, 401, 403)
                external_latency = lat2
                external_src = f"local-fallback:{code2}:{src}"
        else:
            ok2, code2, lat2, _ = http_probe(local_health_url, timeout_sec=5.0)
            external_ok = ok2 and code2 in (200, 401, 403)
            external_latency = lat2
            external_src = f"local-primary:{code2}"

        challenge_ok = True
        if challenge_url:
            okc, codec, _, _ = http_probe(challenge_url, timeout_sec=6.0)
            challenge_ok = okc and codec == 200

        window.append((ts, external_ok and challenge_ok, external_latency))
        window = [x for x in window if (ts - x[0]) <= 30]

        sample_count = len(window)
        ok_count = sum(1 for x in window if x[1])
        err_count = sample_count - ok_count
        error_rate = (err_count / sample_count) if sample_count else 0.0
        latencies = sorted(x[2] for x in window if x[2] > 0)
        p95 = latencies[int(0.95 * (len(latencies) - 1))] if latencies else 0.0

        if external_ok and challenge_ok:
            external_fail_streak = 0
        else:
            external_fail_streak += 1

        cond_latency = p95 > p95_threshold_ms
        cond_error = error_rate > error_rate_threshold
        cond_external = external_fail_streak >= external_fail_streak_threshold
        signals = sum(1 for x in (cond_latency, cond_error, cond_external) if x)

        self_ddos_recent = read_recent_self_ddos(state_dir, max_age_sec=30)

        if signals >= 2:
            trigger_self_heal()
            if self_ddos_recent:
                if mode != "elevated":
                    set_mode("elevated")
                    set_lockdown(False)
                    mode = "elevated"
                log(f"self-ddos recent, skip lockdown (signals={signals}, src={external_src})")
            else:
                set_mode("emergency")
                set_lockdown(True, lockdown_ttl_sec, reason=f"signals={signals};src={external_src}")
                mode = "lockdown"
                log(f"lockdown enabled signals={signals} p95={p95:.1f}ms error_rate={error_rate:.2f} fail_streak={external_fail_streak}")
        elif signals == 1:
            if mode != "elevated":
                set_mode("aggressive")
                set_lockdown(False)
                mode = "elevated"
            log(f"elevated mode signals=1 p95={p95:.1f}ms error_rate={error_rate:.2f} fail_streak={external_fail_streak}")
        else:
            if mode != "normal":
                set_mode("normal")
                set_lockdown(False)
                mode = "normal"

        write_metrics(PANEL_RUNTIME_DIR, {
            "signals": float(signals),
            "p95_ms": float(p95),
            "error_rate": float(error_rate),
            "external_fail_streak": float(external_fail_streak),
            "challenge_ok": 1.0 if challenge_ok else 0.0,
            "external_ok": 1.0 if external_ok else 0.0,
            "self_ddos_recent": 1.0 if self_ddos_recent else 0.0,
        })

        sleep_sec = anomaly_sec if signals >= 1 else normal_sec
        time.sleep(sleep_sec)


if __name__ == "__main__":
    try:
        sys.exit(main())
    except KeyboardInterrupt:
        sys.exit(0)
