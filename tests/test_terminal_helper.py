#!/usr/bin/env python3
from __future__ import annotations

import hashlib
import importlib.util
import json
import pathlib
import socket
import struct
import sys
import tempfile
import time


ROOT = pathlib.Path(__file__).resolve().parents[1]
HELPER_PATH = ROOT / "scripts" / "pteroprotect_terminal_helper.py"


def load_helper():
    spec = importlib.util.spec_from_file_location("pteroprotect_terminal_helper", HELPER_PATH)
    if spec is None or spec.loader is None:
        raise RuntimeError("failed to load terminal helper")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def write_ticket(helper, session_id: str, ticket: str, ip: str, user_agent: str, expires_at: int) -> None:
    helper.TICKET_DIR.mkdir(parents=True, exist_ok=True)
    payload = {
        "session_id": session_id,
        "ticket_hash": helper.ticket_hash(ticket),
        "user_id": 1,
        "ip": ip,
        "user_agent_hash": hashlib.sha256(user_agent.encode("utf-8")).hexdigest()[:16],
        "created_at": int(time.time()),
        "expires_at": expires_at,
    }
    (helper.TICKET_DIR / f"{session_id}.json").write_text(json.dumps(payload), encoding="utf-8")


def headers(session_id: str, ticket: str, ip: str = "203.0.113.10", user_agent: str = "terminal-test") -> dict[str, str]:
    return {
        "cookie": f"pp_term_{session_id}={ticket}",
        "x-real-ip": ip,
        "user-agent": user_agent,
    }


def ws_headers(session_id: str, ticket: str, ip: str = "203.0.113.10", user_agent: str = "terminal-test") -> dict[str, str]:
    result = headers(session_id, ticket, ip, user_agent)
    result.update({
        "upgrade": "websocket",
        "connection": "keep-alive, Upgrade",
        "sec-websocket-version": "13",
        "sec-websocket-key": "dGhlIHNhbXBsZSBub25jZQ==",
    })
    return result


def masked_frame(payload: bytes, opcode: int = 2) -> bytes:
    mask = b"\x01\x02\x03\x04"
    masked = bytes(byte ^ mask[index % 4] for index, byte in enumerate(payload))
    if len(payload) < 126:
        header = bytes([0x80 | opcode, 0x80 | len(payload)])
    elif len(payload) < 65536:
        header = bytes([0x80 | opcode, 0x80 | 126]) + struct.pack("!H", len(payload))
    else:
        header = bytes([0x80 | opcode, 0x80 | 127]) + struct.pack("!Q", len(payload))
    return header + mask + masked


def request_without_upgrade(session_id: str, ticket: str) -> bytes:
    return (
        f"GET /admin/protect/terminal/sessions/{session_id}/ws HTTP/1.1\r\n"
        f"Cookie: pp_term_{session_id}={ticket}\r\n"
        "X-Real-IP: 203.0.113.10\r\n"
        "User-Agent: terminal-test\r\n"
        "\r\n"
    ).encode()


def assert_true(value, message: str) -> None:
    if not value:
        raise AssertionError(message)


def assert_false(value, message: str) -> None:
    if value:
        raise AssertionError(message)


def main() -> int:
    emergency_panel = (ROOT / "scripts" / "pteroprotect_emergency_panel.py").read_text(encoding="utf-8")
    assert_false("cdn.jsdelivr.net" in emergency_panel, "emergency terminal must not load privileged assets from a CDN")

    helper = load_helper()
    with tempfile.TemporaryDirectory() as tmp:
        root = pathlib.Path(tmp)
        helper.TICKET_DIR = root / "tickets"
        helper.REPLAY_DIR = root / "replay"
        helper.BIND_IP = True
        helper.BIND_UA = True

        session_id = "abcdefghijklmnop"
        ticket = "secret-ticket"
        write_ticket(helper, session_id, ticket, "203.0.113.10", "terminal-test", int(time.time()) + 60)
        assert_true(helper.verify_ticket(session_id, headers(session_id, ticket)), "valid ticket should pass")
        assert_false(helper.verify_ticket(session_id, headers(session_id, ticket)), "replayed ticket should fail")

        session_id = "qrstuvwxyzabcdef"
        write_ticket(helper, session_id, ticket, "203.0.113.10", "terminal-test", int(time.time()) - 1)
        assert_false(helper.verify_ticket(session_id, headers(session_id, ticket)), "expired ticket should fail")

        session_id = "ghijklmnopqrstuv"
        write_ticket(helper, session_id, ticket, "203.0.113.10", "terminal-test", int(time.time()) + 60)
        assert_false(helper.verify_ticket(session_id, headers(session_id, ticket, ip="203.0.113.11")), "IP mismatch should fail")

        session_id = "wxyzabcdefghijkl"
        write_ticket(helper, session_id, ticket, "203.0.113.10", "terminal-test", int(time.time()) + 60)
        assert_false(helper.verify_ticket(session_id, headers(session_id, ticket, user_agent="other-agent")), "UA mismatch should fail")

        for marker in helper.REPLAY_DIR.glob("*.json"):
            marker.unlink()

        session_id = "malformedupgrade1"
        write_ticket(helper, session_id, ticket, "203.0.113.10", "terminal-test", int(time.time()) + 60)
        client, server = socket.socketpair()
        try:
            client.sendall(request_without_upgrade(session_id, ticket))
            client.shutdown(socket.SHUT_WR)
            helper.handle(server)
            response = client.recv(4096)
            assert_true(b"426" in response, "malformed handshake should be rejected before ticket use")
            assert_true((helper.TICKET_DIR / f"{session_id}.json").exists(), "malformed handshake should not delete ticket")
            assert_false(any(helper.REPLAY_DIR.glob("*.json")), "malformed handshake should not create replay marker")
        finally:
            client.close()
            server.close()

        assert_true(helper.validate_ws_request("GET /x HTTP/1.1", ws_headers("s", "t")), "valid websocket headers should pass")
        assert_false(helper.validate_ws_request("POST /x HTTP/1.1", ws_headers("s", "t")), "non-GET websocket should fail")
        bad = ws_headers("s", "t")
        bad["sec-websocket-key"] = "not-base64"
        assert_false(helper.validate_ws_request("GET /x HTTP/1.1", bad), "invalid websocket key should fail")

        left, right = socket.socketpair()
        try:
            left.sendall(masked_frame(b"hello"))
            assert_true(helper.ws_recv(right) == (2, b"hello"), "valid masked frame should decode")
        finally:
            left.close()
            right.close()

        left, right = socket.socketpair()
        try:
            left.sendall(b"\x82\x05hello")
            assert_false(helper.ws_recv(right), "unmasked client frame should fail")
        finally:
            left.close()
            right.close()

        left, right = socket.socketpair()
        try:
            left.sendall(bytes([0x82, 0x80 | 127]) + struct.pack("!Q", helper.MAX_WS_FRAME + 1))
            assert_false(helper.ws_recv(right), "oversized client frame should fail")
        finally:
            left.close()
            right.close()

    print("terminal_helper tests ok")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
