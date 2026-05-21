#!/usr/bin/env python3
import importlib.util
import pathlib
import tempfile


ROOT = pathlib.Path(__file__).resolve().parents[1]
MOD_PATH = ROOT / "scripts" / "runtime_abuse_guard.py"


spec = importlib.util.spec_from_file_location("runtime_abuse_guard", MOD_PATH)
mod = importlib.util.module_from_spec(spec)
assert spec and spec.loader
spec.loader.exec_module(mod)


def test_parse_docker_cpu():
    assert mod.parse_docker_cpu("312.5%") == 312.5
    assert mod.parse_docker_cpu("bad") == 0.0


def test_host_cpu_threshold_reserves_one_core(monkeypatch=None):
    original = mod.os.cpu_count
    try:
        mod.os.cpu_count = lambda: 4
        assert mod.host_cpu_kill_threshold(1, 80) == 300
        mod.os.cpu_count = lambda: 8
        assert mod.host_cpu_kill_threshold(1, 80) == 700
        mod.os.cpu_count = lambda: 1
        assert mod.host_cpu_kill_threshold(1, 80) == 100
    finally:
        mod.os.cpu_count = original


def test_dangerous_dd_reason():
    assert mod.dangerous_dd_reason("root 1 dd if=/dev/zero of=/tmp/x", 1) == "dd_zero_processes=1"
    assert mod.dangerous_dd_reason("root 1 dd if=/dev/zero of=/tmp/x", 3) == ""
    text = "\n".join(["u dd if=/dev/random of=/tmp/a", "u dd if=/tmp/a of=/tmp/b", "u dd if=/tmp/c of=/tmp/d"])
    assert mod.dangerous_dd_reason(text, 3) == ""
    assert mod.dangerous_dd_reason("u dd if=/tmp/a of=/tmp/b", 3) == ""


def test_is_uuid():
    assert mod.is_uuid("ed40c6ef-7a07-481e-8ce3-e3472ecc611d")
    assert not mod.is_uuid("not-a-container")


def test_dangerous_strikes_increment_by_server_uuid():
    original = mod.container_name
    try:
        mod.container_name = lambda cid: "ed40c6ef-7a07-481e-8ce3-e3472ecc611d"
        with tempfile.TemporaryDirectory() as td:
            assert mod.register_dangerous_container_strike(td, "abc123", "dd") == 1
            assert mod.register_dangerous_container_strike(td, "abc123", "dd") == 2
            data = mod.load_dangerous_strikes(td)
            rec = data["containers"]["ed40c6ef-7a07-481e-8ce3-e3472ecc611d"]
            assert rec["count"] == 2
            assert rec["server_uuid"] == "ed40c6ef-7a07-481e-8ce3-e3472ecc611d"
    finally:
        mod.container_name = original


def test_containment_suspends_only_after_threshold():
    calls = {"suspend": 0, "stop": 0, "pause": 0}
    originals = {
        "is_pterodactyl_container": mod.is_pterodactyl_container,
        "write_container_incident": mod.write_container_incident,
        "write_quarantine_marker": mod.write_quarantine_marker,
        "container_name": mod.container_name,
        "pause_container": mod.pause_container,
        "docker_stop_container": mod.docker_stop_container,
        "suspend_server_for_container": mod.suspend_server_for_container,
        "write_self_ddos_event": mod.write_self_ddos_event,
    }
    try:
        mod.is_pterodactyl_container = lambda cid: True
        mod.write_container_incident = lambda *args, **kwargs: None
        mod.write_quarantine_marker = lambda *args, **kwargs: None
        mod.container_name = lambda cid: "ed40c6ef-7a07-481e-8ce3-e3472ecc611d"
        mod.pause_container = lambda cid: calls.__setitem__("pause", calls["pause"] + 1) or True
        mod.docker_stop_container = lambda cid, reason: calls.__setitem__("stop", calls["stop"] + 1) or True
        mod.suspend_server_for_container = lambda *args, **kwargs: calls.__setitem__("suspend", calls["suspend"] + 1) or True
        mod.write_self_ddos_event = lambda *args, **kwargs: None
        metrics = {"container_killed": 0, "container_dangerous_process": 0, "server_suspended": 0}
        with tempfile.TemporaryDirectory() as td:
            killed = set()
            for i in range(4):
                killed.clear()
                mod.contain_dangerous_container(td, {}, metrics, killed, "abc123", "dd", "", True, True, 5, 86400)
            assert calls["stop"] == 4
            assert calls["suspend"] == 0
            killed.clear()
            mod.contain_dangerous_container(td, {}, metrics, killed, "abc123", "dd", "", True, True, 5, 86400)
            assert calls["stop"] == 5
            assert calls["suspend"] == 1
            assert metrics["server_suspended"] == 1
    finally:
        for name, value in originals.items():
            setattr(mod, name, value)


if __name__ == "__main__":
    test_parse_docker_cpu()
    test_host_cpu_threshold_reserves_one_core()
    test_dangerous_dd_reason()
    test_is_uuid()
    test_dangerous_strikes_increment_by_server_uuid()
    test_containment_suspends_only_after_threshold()
    print("runtime abuse guard tests ok")
