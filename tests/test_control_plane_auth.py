#!/usr/bin/env python3
"""Regression coverage for control-plane authentication fail-closed behavior."""
from __future__ import annotations

import http.client
import importlib.util
import json
import os
import pathlib
import tempfile
import threading


ROOT = pathlib.Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "pteroprotect_control_plane.py"


def load_module(config_path: pathlib.Path):
    old = os.environ.get("PTEROPROTECT_CONFIG_PATH")
    os.environ["PTEROPROTECT_CONFIG_PATH"] = str(config_path)
    try:
        spec = importlib.util.spec_from_file_location("control_plane_under_test", SCRIPT)
        if spec is None or spec.loader is None:
            raise RuntimeError("failed to load control plane")
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return module
    finally:
        if old is None:
            os.environ.pop("PTEROPROTECT_CONFIG_PATH", None)
        else:
            os.environ["PTEROPROTECT_CONFIG_PATH"] = old


def heartbeat(port: int, key: str = "") -> int:
    headers = {"Content-Type": "application/json"}
    if key:
        headers["X-PteroProtect-Node-Key"] = key
    connection = http.client.HTTPConnection("127.0.0.1", port, timeout=2)
    connection.request("POST", "/heartbeat", body=json.dumps({"node_id": "node-test"}), headers=headers)
    status = connection.getresponse().status
    connection.close()
    return status


def main() -> int:
    with tempfile.TemporaryDirectory() as tmp:
        config_path = pathlib.Path(tmp) / "config.json"
        config_path.write_text(json.dumps({"network": {}}), encoding="utf-8")
        module = load_module(config_path)
        server = module.ThreadingHTTPServer(("127.0.0.1", 0), module.H)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            port = server.server_address[1]
            if heartbeat(port) != 401:
                raise AssertionError("control plane accepted a heartbeat when node_auth_key is missing")

            config_path.write_text(json.dumps({"network": {"node_auth_key": "node-secret"}}), encoding="utf-8")
            if heartbeat(port) != 401:
                raise AssertionError("control plane accepted heartbeat without a node key")
            if heartbeat(port, "wrong-key") != 401:
                raise AssertionError("control plane accepted heartbeat with an invalid node key")
            if heartbeat(port, "node-secret") != 200:
                raise AssertionError("control plane rejected heartbeat with the configured node key")
        finally:
            server.shutdown()
            server.server_close()

    print("control plane auth tests ok")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
