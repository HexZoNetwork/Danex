#!/usr/bin/env python3
import importlib.util
import pathlib


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
    assert mod.dangerous_dd_reason("root 1 dd if=/dev/zero of=/tmp/x", 3) == "dd_zero_processes=1"
    text = "\n".join(["u dd if=/dev/random of=/tmp/a", "u dd if=/tmp/a of=/tmp/b", "u dd if=/tmp/c of=/tmp/d"])
    assert mod.dangerous_dd_reason(text, 3) == ""
    assert mod.dangerous_dd_reason("u dd if=/tmp/a of=/tmp/b", 3) == ""


def test_is_uuid():
    assert mod.is_uuid("ed40c6ef-7a07-481e-8ce3-e3472ecc611d")
    assert not mod.is_uuid("not-a-container")


if __name__ == "__main__":
    test_parse_docker_cpu()
    test_host_cpu_threshold_reserves_one_core()
    test_dangerous_dd_reason()
    test_is_uuid()
    print("runtime abuse guard tests ok")
