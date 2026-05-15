#!/usr/bin/env python3
"""PteroProtect break-glass PTY websocket helper.

Runs as a local root service on 127.0.0.1 and accepts only one-time tickets
created by the Laravel admin panel in /dev/shm/pteroprotect/terminal_tickets.
"""

from __future__ import annotations

import base64
import errno
import fcntl
import hashlib
import hmac
import json
import os
import pathlib
import pty
import re
import selectors
import signal
import socket
import struct
import subprocess
import termios
import time
import urllib.parse


HOST = os.environ.get("PTEROPROTECT_TERMINAL_HOST", "127.0.0.1")
PORT = int(os.environ.get("PTEROPROTECT_TERMINAL_PORT", "18445"))
TICKET_DIR = pathlib.Path(os.environ.get("PTEROPROTECT_TERMINAL_TICKET_DIR", "/dev/shm/pteroprotect/terminal_tickets"))
AUDIT_DIR = pathlib.Path(os.environ.get("PTEROPROTECT_TERMINAL_AUDIT_DIR", "/var/log/pteroprotect/terminal"))
IDLE_TIMEOUT = int(os.environ.get("PTEROPROTECT_TERMINAL_IDLE_TIMEOUT", "900"))
SESSION_RE = re.compile(r"^/admin/protect/terminal/sessions/([A-Za-z0-9_-]{16,80})/ws$")
GUID = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11"


def ticket_hash(ticket: str) -> str:
    return hashlib.sha256(ticket.encode("utf-8")).hexdigest()


def read_headers(conn: socket.socket) -> tuple[str, dict[str, str], bytes]:
    data = b""
    while b"\r\n\r\n" not in data and len(data) < 16384:
        chunk = conn.recv(4096)
        if not chunk:
            break
        data += chunk
    head, _, rest = data.partition(b"\r\n\r\n")
    lines = head.decode("iso-8859-1", "replace").split("\r\n")
    request_line = lines[0] if lines else ""
    headers: dict[str, str] = {}
    for line in lines[1:]:
        if ":" not in line:
            continue
        key, value = line.split(":", 1)
        headers[key.strip().lower()] = value.strip()
    return request_line, headers, rest


def cookie_value(headers: dict[str, str], name: str) -> str:
    for part in headers.get("cookie", "").split(";"):
        part = part.strip()
        if not part or "=" not in part:
            continue
        key, value = part.split("=", 1)
        if key.strip() == name:
            return urllib.parse.unquote(value.strip())
    return ""


def verify_ticket(session_id: str, headers: dict[str, str]) -> dict[str, object] | None:
    path = TICKET_DIR / f"{session_id}.json"
    try:
        raw = path.read_text(encoding="utf-8")
        info = json.loads(raw)
    except Exception:
        return None
    ticket = cookie_value(headers, f"pp_term_{session_id}")
    if not ticket:
        return None
    if int(info.get("expires_at", 0)) < int(time.time()):
        with contextlib_suppress():
            path.unlink()
        return None
    if not hmac_compare(str(info.get("ticket_hash", "")), ticket_hash(ticket)):
        return None
    with contextlib_suppress():
        path.unlink()
    return info


def hmac_compare(a: str, b: str) -> bool:
    return hmac.compare_digest(a, b)


class contextlib_suppress:
    def __enter__(self):
        return self

    def __exit__(self, *_exc):
        return True


def send_http(conn: socket.socket, code: int, text: str) -> None:
    body = json.dumps({"ok": False, "error": text}).encode()
    conn.sendall(
        f"HTTP/1.1 {code} {text}\r\nConnection: close\r\nContent-Type: application/json\r\nContent-Length: {len(body)}\r\n\r\n".encode()
        + body
    )


def accept_ws(conn: socket.socket, headers: dict[str, str]) -> bool:
    key = headers.get("sec-websocket-key", "")
    if not key:
        return False
    accept = base64.b64encode(hashlib.sha1((key + GUID).encode()).digest()).decode()
    conn.sendall(
        (
            "HTTP/1.1 101 Switching Protocols\r\n"
            "Upgrade: websocket\r\n"
            "Connection: Upgrade\r\n"
            f"Sec-WebSocket-Accept: {accept}\r\n"
            "\r\n"
        ).encode()
    )
    return True


def ws_send(conn: socket.socket, payload: bytes, opcode: int = 2) -> None:
    header = bytearray([0x80 | opcode])
    size = len(payload)
    if size < 126:
        header.append(size)
    elif size < 65536:
        header.extend([126, (size >> 8) & 0xFF, size & 0xFF])
    else:
        header.append(127)
        header.extend(struct.pack("!Q", size))
    conn.sendall(bytes(header) + payload)


def ws_recv(conn: socket.socket) -> tuple[int, bytes] | None:
    first = conn.recv(2)
    if len(first) < 2:
        return None
    opcode = first[0] & 0x0F
    masked = bool(first[1] & 0x80)
    size = first[1] & 0x7F
    if size == 126:
        size = struct.unpack("!H", conn.recv(2))[0]
    elif size == 127:
        size = struct.unpack("!Q", conn.recv(8))[0]
    mask = conn.recv(4) if masked else b""
    payload = b""
    while len(payload) < size:
        chunk = conn.recv(size - len(payload))
        if not chunk:
            return None
        payload += chunk
    if masked:
        payload = bytes(b ^ mask[i % 4] for i, b in enumerate(payload))
    return opcode, payload


def resize(fd: int, cols: int, rows: int) -> None:
    cols = max(20, min(300, cols))
    rows = max(5, min(120, rows))
    fcntl.ioctl(fd, termios.TIOCSWINSZ, struct.pack("HHHH", rows, cols, 0, 0))


def append_audit(session_id: str, info: dict[str, object], event: str) -> None:
    AUDIT_DIR.mkdir(parents=True, exist_ok=True)
    line = json.dumps({"ts": int(time.time()), "session_id": session_id, "event": event, "user_id": info.get("user_id"), "ip": info.get("ip")})
    with open(AUDIT_DIR / "sessions.jsonl", "a", encoding="utf-8") as fh:
        fh.write(line + "\n")


def pty_session(conn: socket.socket, session_id: str, info: dict[str, object]) -> None:
    append_audit(session_id, info, "start")
    pid, fd = pty.fork()
    if pid == 0:
        os.environ.clear()
        os.environ.update({"TERM": "xterm-256color", "HOME": "/root", "USER": "root", "LOGNAME": "root", "PATH": "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"})
        os.chdir("/root")
        os.execl("/bin/bash", "bash", "-l")
    resize(fd, 120, 32)
    sel = selectors.DefaultSelector()
    conn.setblocking(False)
    os.set_blocking(fd, False)
    sel.register(conn, selectors.EVENT_READ, "ws")
    sel.register(fd, selectors.EVENT_READ, "pty")
    last = time.time()
    try:
        while True:
            if time.time() - last > IDLE_TIMEOUT:
                ws_send(conn, b"\r\n[PteroProtect] idle timeout\r\n", 1)
                break
            for key, _ in sel.select(timeout=1):
                if key.data == "pty":
                    try:
                        data = os.read(fd, 8192)
                    except OSError as exc:
                        if exc.errno in {errno.EIO, errno.EBADF}:
                            return
                        raise
                    if not data:
                        return
                    ws_send(conn, data)
                else:
                    msg = ws_recv(conn)
                    if msg is None:
                        return
                    opcode, payload = msg
                    last = time.time()
                    if opcode == 8:
                        return
                    if opcode == 9:
                        ws_send(conn, payload, 10)
                        continue
                    if opcode in {1, 2}:
                        try:
                            obj = json.loads(payload.decode("utf-8"))
                            if isinstance(obj, dict) and obj.get("type") == "resize":
                                resize(fd, int(obj.get("cols", 120)), int(obj.get("rows", 32)))
                                continue
                        except Exception:
                            pass
                        os.write(fd, payload)
    finally:
        append_audit(session_id, info, "stop")
        with contextlib_suppress():
            os.kill(pid, signal.SIGHUP)
        with contextlib_suppress():
            os.close(fd)


def handle(conn: socket.socket) -> None:
    try:
        request_line, headers, _ = read_headers(conn)
        parts = request_line.split()
        if len(parts) < 2:
            return send_http(conn, 400, "bad_request")
        path = urllib.parse.urlparse(parts[1]).path
        match = SESSION_RE.match(path)
        if not match:
            return send_http(conn, 404, "not_found")
        session_id = match.group(1)
        info = verify_ticket(session_id, headers)
        if info is None:
            return send_http(conn, 403, "ticket_invalid")
        if not accept_ws(conn, headers):
            return send_http(conn, 426, "upgrade_required")
        pty_session(conn, session_id, info)
    finally:
        with contextlib_suppress():
            conn.close()


def main() -> int:
    TICKET_DIR.mkdir(parents=True, exist_ok=True)
    os.chmod(TICKET_DIR, 0o700)
    sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    sock.bind((HOST, PORT))
    sock.listen(64)
    while True:
        conn, _ = sock.accept()
        pid = os.fork()
        if pid == 0:
            sock.close()
            handle(conn)
            os._exit(0)
        conn.close()
        with contextlib_suppress():
            while os.waitpid(-1, os.WNOHANG)[0] > 0:
                pass


if __name__ == "__main__":
    raise SystemExit(main())
