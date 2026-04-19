#!/usr/bin/env python3
import json
from urllib.parse import parse_qs, urlparse
from http.server import BaseHTTPRequestHandler, HTTPServer


class Handler(BaseHTTPRequestHandler):
    server_version = "WingsMock/1.0"
    servers = {}

    def _cors(self):
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Headers", "Authorization, Content-Type")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, DELETE, OPTIONS")

    def _send_json(self, status: int, payload: dict):
        body = json.dumps(payload).encode("utf-8")
        self.send_response(status)
        self._cors()
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _server_uuid_from_path(self):
        parts = self.path.split("/api/servers/", 1)
        if len(parts) != 2:
            return ""
        return parts[1].split("/", 1)[0].strip()

    def _parsed(self):
        return urlparse(self.path)

    def do_OPTIONS(self):
        self.send_response(204)
        self._cors()
        self.end_headers()

    def do_GET(self):
        parsed = self._parsed()
        path = parsed.path

        if self.path.startswith("/api/system"):
            self._send_json(200, {
                "version": "1.0.0-codespaces-mock",
                "name": "wings-mock",
                "state": "running",
            })
            return

        if path.startswith("/api/servers/") and path.endswith("/files/list-directory"):
            # Wings normally returns a JSON array.
            self._send_json(200, [
                {
                    "name": ".",
                    "mode": "rw",
                    "mode_bits": "0644",
                    "size": 0,
                    "is_file": False,
                    "is_symlink": False,
                    "mimetype": "inode/directory",
                    "created_at": "2026-04-19T00:00:00+00:00",
                    "modified_at": "2026-04-19T00:00:00+00:00",
                }
            ])
            return

        if path.startswith("/api/servers/") and path.endswith("/files/contents"):
            self.send_response(200)
            self._cors()
            self.send_header("Content-Type", "text/plain; charset=utf-8")
            self.end_headers()
            self.wfile.write(b"# wings mock file\n")
            return

        if path.startswith("/api/servers/") and path.endswith("/ws"):
            # HTTP fallback for websocket probe paths.
            self._send_json(200, {"status": "ok", "mode": "mock-no-websocket"})
            return

        if path.startswith("/api/servers/"):
            uuid = self._server_uuid_from_path()
            data = self.servers.get(uuid, {"uuid": uuid, "state": "running"})
            data.setdefault("utilization", {
                "memory_bytes": 0,
                "cpu_absolute": 0,
                "disk_bytes": 0,
                "network": {"rx_bytes": 0, "tx_bytes": 0},
                "uptime": 0,
            })
            data.setdefault("is_suspended", False)
            self._send_json(200, data)
            return

        self._send_json(404, {"error": "not found"})

    def do_POST(self):
        parsed = self._parsed()
        path = parsed.path

        if path == "/api/servers":
            length = int(self.headers.get("Content-Length", "0") or "0")
            raw = self.rfile.read(length) if length > 0 else b"{}"
            try:
                payload = json.loads(raw.decode("utf-8"))
            except Exception:
                payload = {}
            uuid = str(payload.get("uuid", "")).strip() or "unknown"
            self.servers[uuid] = {
                "uuid": uuid,
                "state": "installing",
                "start_on_completion": bool(payload.get("start_on_completion", False)),
            }
            self._send_json(202, {"status": "accepted", "uuid": uuid})
            return

        if path.startswith("/api/servers/") and path.endswith("/sync"):
            uuid = self._server_uuid_from_path()
            current = self.servers.get(uuid, {"uuid": uuid})
            current["state"] = "running"
            self.servers[uuid] = current
            self._send_json(200, {"status": "synced", "uuid": uuid})
            return

        if path.startswith("/api/servers/") and path.endswith("/power"):
            uuid = self._server_uuid_from_path()
            state = self.servers.get(uuid, {"uuid": uuid, "state": "offline"})
            try:
                length = int(self.headers.get("Content-Length", "0") or "0")
                raw = self.rfile.read(length) if length > 0 else b"{}"
                payload = json.loads(raw.decode("utf-8"))
            except Exception:
                payload = {}
            signal = str(payload.get("signal", "")).lower().strip()
            if signal in {"start", "restart"}:
                state["state"] = "running"
            elif signal in {"stop", "kill"}:
                state["state"] = "offline"
            self.servers[uuid] = state
            self._send_json(200, {"status": "ok", "signal": signal or "noop"})
            return

        if path.startswith("/api/servers/") and "/files/" in path:
            self._send_json(200, {"status": "ok"})
            return

        if path.startswith("/api/servers/") and path.endswith("/commands"):
            self._send_json(200, {"status": "queued"})
            return

        self._send_json(404, {"error": "not found"})

    def do_DELETE(self):
        if self.path.startswith("/api/servers/"):
            uuid = self.path.split("/api/servers/", 1)[1].split("/", 1)[0].strip()
            if uuid in self.servers:
                del self.servers[uuid]
            self._send_json(200, {"status": "deleted", "uuid": uuid})
            return

        self._send_json(404, {"error": "not found"})


def main():
    httpd = HTTPServer(("0.0.0.0", 8081), Handler)
    httpd.serve_forever()


if __name__ == "__main__":
    main()
