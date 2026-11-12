#!/usr/bin/env python3
import socket
import base64
import hashlib
import hmac
import ipaddress
import json
import os
import sys

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
import random
import secrets
import threading
import time
from http import HTTPStatus
from http.cookies import SimpleCookie
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlencode, urlparse

CONFIG_PATH = os.environ.get("PTEROPROTECT_CONFIG_PATH", "/pteroprotect/config.json")

_cfg_cache = {"at": 0.0, "data": {}}
_nonce_lock = threading.Lock()
_nonces = {}
_ephemeral_secret = secrets.token_urlsafe(32)
_rate_lock = threading.Lock()
_rate_windows = {}
MAX_NONCES = 20000
MAX_RATE_WINDOWS = 50000

WEAK_SECRET_PLACEHOLDERS = {
    "", "replace_me", "replace-me", "change_me", "change-me", "changeme",
    "waf_challenge_secret", "unblock_portal_token", "your_secret",
    "your-secret", "secret", "password", "default", "example", "todo",
}


def _b64u(data: bytes) -> str:
    return base64.urlsafe_b64encode(data).decode().rstrip("=")


def _ub64u(data: str) -> bytes:
    pad = "=" * (-len(data) % 4)
    return base64.urlsafe_b64decode(data + pad)


def load_cfg():
    now = time.time()
    if now - _cfg_cache["at"] < 2.0 and _cfg_cache["data"]:
        return _cfg_cache["data"]
    data = {}
    try:
        with open(CONFIG_PATH, "r", encoding="utf-8") as f:
            raw = json.load(f)
            if isinstance(raw, dict):
                data = raw
    except Exception:
        data = {}
    _cfg_cache["at"] = now
    _cfg_cache["data"] = data
    return data


def net_setting(name, default):
    cfg = load_cfg()
    net = cfg.get("network") if isinstance(cfg, dict) else {}
    if not isinstance(net, dict):
        return default
    return net.get(name, default)


def challenge_secret():
    secret = str(net_setting("waf_challenge_secret", "") or "").strip()
    if secret:
        return secret
    env_secret = str(os.environ.get("PTEROPROTECT_WAF_CHALLENGE_SECRET", "") or "").strip()
    if env_secret:
        return env_secret
    allow_legacy = str(net_setting("waf_challenge_legacy_secret_fallback", False)).strip().lower() in {"1", "true", "yes", "on"}
    if allow_legacy:
        fallback = str(net_setting("unblock_portal_token", "") or "").strip()
        if fallback:
            return fallback
        env_fallback = str(os.environ.get("PTEROPROTECT_UNBLOCK_PORTAL_TOKEN", "") or "").strip()
        if env_fallback:
            return env_fallback
    return _ephemeral_secret


def challenge_secret_is_weak(secret):
    value = str(secret or "").strip()
    if len(value) < 24:
        return True
    lowered = value.lower()
    if lowered in WEAK_SECRET_PLACEHOLDERS:
        return True
    if "replace_me" in lowered or "change_me" in lowered or "changeme" in lowered:
        return True
    classes = 0
    classes += 1 if any(ch.isupper() for ch in value) else 0
    classes += 1 if any(ch.islower() for ch in value) else 0
    classes += 1 if any(ch.isdigit() for ch in value) else 0
    classes += 1 if any(not ch.isalnum() for ch in value) else 0
    return classes < 3


def challenge_pow_bits():
    try:
        raw = int(net_setting("waf_pow_bits", 16))
    except Exception:
        raw = 16
    raw = max(16, min(20, raw))
    return raw + ((4 - (raw % 4)) % 4)


def verify_pow(nonce, salt, bits, counter, client_hash):
    if not isinstance(counter, int) or counter < 0 or counter > 0xFFFFFFFF:
        return False
    try:
        expected = hashlib.sha256(f"{nonce}:{salt}:{counter}".encode("utf-8")).hexdigest()
    except Exception:
        return False
    prefix = "0" * max(1, int(bits) // 4)
    if not expected.startswith(prefix):
        return False
    return hmac.compare_digest(expected, str(client_hash or "").strip().lower())


def challenge_ttl():
    try:
        ttl = int(net_setting("waf_challenge_ttl_sec", 1800))
    except Exception:
        ttl = 1800
    return max(60, min(86400, ttl))


def cookie_name():
    return str(net_setting("waf_challenge_cookie_name", "pp_clearance") or "pp_clearance")


def is_enabled():
    val = str(net_setting("waf_challenge_enabled", True)).strip().lower()
    return val not in ("0", "false", "off", "no")


def client_ip(handler):
    def _first_ip(raw: str) -> str:
        candidate = str(raw or "").split(",", 1)[0].strip()
        if not candidate:
            return ""
        try:
            ipaddress.ip_address(candidate)
            return candidate
        except Exception:
            return ""

    peer = (handler.client_address[0] or "").strip()
    allow_forwarded = peer in ("127.0.0.1", "::1", "::ffff:127.0.0.1")
    if allow_forwarded:
        real_ip = _first_ip(handler.headers.get("X-Real-IP", ""))
        if real_ip:
            return real_ip
        xff_ip = _first_ip(handler.headers.get("X-Forwarded-For", ""))
        if xff_ip:
            return xff_ip

    resolved_peer = _first_ip(peer)
    return resolved_peer or "127.0.0.1"


def header_truthy(value: str) -> bool:
    return str(value or "").strip().lower() in {"1", "true", "yes", "on"}


def provider_api_allowed(handler) -> bool:
    if not header_truthy(handler.headers.get("X-PteroProtect-Provider-Token-Block", "")):
        return True
    return header_truthy(handler.headers.get("X-PteroProtect-Has-Token", ""))


def ua_fingerprint(handler):
    ua = (handler.headers.get("User-Agent") or "").strip().encode()
    return hashlib.sha256(ua).hexdigest()[:24]


def sign_payload(raw_payload: bytes) -> str:
    sig = hmac.new(challenge_secret().encode(), raw_payload, hashlib.sha256).digest()
    return _b64u(sig)


def issue_token(ip: str, ua_fp: str):
    payload = {
        "ip": ip,
        "ua": ua_fp,
        "exp": int(time.time()) + challenge_ttl(),
    }
    raw = json.dumps(payload, separators=(",", ":"), ensure_ascii=True).encode()
    b = _b64u(raw)
    return f"{b}.{sign_payload(raw)}"


def verify_token(token: str, ip: str, ua_fp: str) -> bool:
    if "." not in token:
        return False
    b, sig = token.split(".", 1)
    try:
        raw = _ub64u(b)
    except Exception:
        return False
    expected = sign_payload(raw)
    if not hmac.compare_digest(expected, sig):
        return False
    try:
        payload = json.loads(raw.decode("utf-8"))
    except Exception:
        return False
    if not isinstance(payload, dict):
        return False
    if int(payload.get("exp", 0)) < int(time.time()):
        return False
    if str(payload.get("ip", "")) != ip:
        return False
    if str(payload.get("ua", "")) != ua_fp:
        return False
    return True


def cleanup_nonces():
    now = time.time()
    with _nonce_lock:
        expired = [k for k, v in _nonces.items() if float(v.get("exp", 0)) <= now]
        for k in expired:
            _nonces.pop(k, None)
        if len(_nonces) > MAX_NONCES:
            oldest = sorted(_nonces.items(), key=lambda kv: float(kv[1].get("exp", 0)))
            for key, _ in oldest[: len(_nonces) - MAX_NONCES]:
                _nonces.pop(key, None)


def normalize_redirect_path(raw: str) -> str:
    raw_value = str(raw or "")
    if "\n" in raw_value or "\r" in raw_value or "\x00" in raw_value:
        return "/"

    try:
        parsed = urlparse(raw_value)
    except Exception:
        return "/"

    path = parsed.path or "/"
    if not path.startswith("/"):
        return "/"

    sanitized = path
    if parsed.query:
        sanitized += "?" + parsed.query
    if parsed.fragment:
        sanitized += "#" + parsed.fragment

    if "\n" in sanitized or "\r" in sanitized or "\x00" in sanitized:
        return "/"

    if len(sanitized) > 2048:
        return "/"

    return sanitized


def rate_allow(ip: str, bucket: str, limit: int, window_sec: int) -> bool:
    now = int(time.time())
    key = f"{bucket}:{ip}"
    with _rate_lock:
        if len(_rate_windows) > MAX_RATE_WINDOWS:
            stale = [k for k, v in _rate_windows.items() if now - int(v.get("start", 0)) >= max(window_sec, 3600)]
            for k in stale:
                _rate_windows.pop(k, None)
            while len(_rate_windows) > MAX_RATE_WINDOWS:
                _rate_windows.pop(next(iter(_rate_windows)), None)
        rec = _rate_windows.get(key)
        if not rec or now - int(rec.get("start", 0)) >= window_sec:
            rec = {"start": now, "count": 0}
        rec["count"] = int(rec.get("count", 0)) + 1
        _rate_windows[key] = rec
        if int(rec["count"]) > limit:
            return False
    return True


class Handler(BaseHTTPRequestHandler):
    server_version = "PteroProtectChallenge/1.0"

    def log_message(self, fmt, *args):
        return

    def _json(self, status: int, obj: dict, extra_headers=None):
        body = json.dumps(obj, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Cache-Control", "no-store")
        self.send_header("X-Content-Type-Options", "nosniff")
        self.send_header("X-Frame-Options", "DENY")
        self.send_header("Referrer-Policy", "no-referrer")
        self.send_header("Content-Length", str(len(body)))
        if extra_headers:
            for k, v in extra_headers.items():
                self.send_header(k, v)
        self.end_headers()
        self.wfile.write(body)

    def _html(self, status: int, html: str):
        body = html.encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.send_header("Cache-Control", "no-store")
        self.send_header("X-Content-Type-Options", "nosniff")
        self.send_header("X-Frame-Options", "DENY")
        self.send_header("Referrer-Policy", "no-referrer")
        self.send_header("Content-Security-Policy", "default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src 'self'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _parse_cookie(self):
        c = SimpleCookie()
        try:
            c.load(self.headers.get("Cookie") or "")
        except Exception:
            return {}
        out = {}
        for k in c:
            out[k] = c[k].value
        return out

    def _read_json(self):
        try:
            ln = int(self.headers.get("Content-Length", "0"))
        except Exception:
            ln = 0
        if ln <= 0 or ln > 64 * 1024:
            return {}
        raw = self.rfile.read(ln)
        try:
            data = json.loads(raw.decode("utf-8"))
        except Exception:
            return {}
        return data if isinstance(data, dict) else {}

    def do_GET(self):
        parsed = urlparse(self.path)
        path = parsed.path
        q = parse_qs(parsed.query)
        ip = client_ip(self)
        ua = ua_fingerprint(self)

        if path == "/health":
            return self._json(200, {"ok": True, "enabled": is_enabled()})

        if path == "/check-provider-api":
            self.send_response(HTTPStatus.NO_CONTENT if provider_api_allowed(self) else HTTPStatus.UNAUTHORIZED)
            self.send_header("Cache-Control", "no-store")
            self.end_headers()
            return

        if path == "/check":
            if not is_enabled():
                self.send_response(HTTPStatus.NO_CONTENT)
                self.send_header("Cache-Control", "no-store")
                self.end_headers()
                return
            ck = self._parse_cookie()
            tok = ck.get(cookie_name(), "")
            if tok and verify_token(tok, ip, ua):
                self.send_response(HTTPStatus.NO_CONTENT)
                self.send_header("Cache-Control", "no-store")
                self.end_headers()
                return
            self.send_response(HTTPStatus.UNAUTHORIZED)
            self.send_header("Cache-Control", "no-store")
            self.end_headers()
            return

        if path == "/new":
            if not is_enabled():
                return self._json(200, {"ok": True, "disabled": True})
            if not rate_allow(ip, "challenge_new", 40, 60):
                return self._json(429, {"ok": False, "error": "rate_limited"})
            cleanup_nonces()
            bits = challenge_pow_bits()
            nonce = secrets.token_urlsafe(18)
            salt = secrets.token_hex(16)
            with _nonce_lock:
                if len(_nonces) >= MAX_NONCES:
                    return self._json(503, {"ok": False, "error": "busy"})
                _nonces[nonce] = {
                    "salt": salt,
                    "bits": bits,
                    "ip": ip,
                    "ua": ua,
                    "exp": time.time() + 180,
                }
            return self._json(200, {
                "ok": True,
                "nonce": nonce,
                "question": "Solve the proof-of-work challenge",
                "pow_salt": salt,
                "pow_bits": bits,
            })

        if path == "/page":
            if not rate_allow(ip, "challenge_page", 60, 60):
                return self._json(429, {"ok": False, "error": "rate_limited"})
            rd = normalize_redirect_path(q.get("rd", ["/"])[0])
            rd_js = json.dumps(rd)
            html = """<!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>PteroProtect Challenge</title>
<style>
body{background:#0f1b2b;color:#d6e9ff;font-family:system-ui;margin:0;display:grid;place-items:center;height:100vh}
.c{width:min(420px,92vw);background:#11273f;border:1px solid #27507a;border-radius:12px;padding:18px}
h2{margin:0 0 8px;color:#9fd2ff} p{color:#b8d6f2} button{width:100%;padding:10px;border-radius:8px;border:1px solid #2d7a86;background:#1f6feb;color:#d6e9ff;cursor:pointer;margin-top:10px}
.e{color:#ff8f8f;min-height:18px}
</style></head><body><div class="c">
<h2>Security Check</h2><p>Buktikan kamu bukan flood bot. Clearance berlaku 30 menit.</p>
<p id="q">Loading challenge...</p><button id="b">Retry</button><div class="e" id="e"></div>
</div><script>
const RD=__RD__;
const elQ=document.getElementById("q"),elB=document.getElementById("b"),elE=document.getElementById("e");
let nonce="",salt="",bits=16;
async function sha256hex(s){const d=await crypto.subtle.digest("SHA-256",new TextEncoder().encode(s));return Array.from(new Uint8Array(d)).map(b=>b.toString(16).padStart(2,"0")).join("");}
async function solve(){const prefix="0".repeat(Math.max(1,bits>>2));let c=0;for(;;){const h=await sha256hex(nonce+":"+salt+":"+c);if(h.startsWith(prefix))return {counter:c,hash:h};c++;if((c&2047)===0)await new Promise(r=>setTimeout(r,0));}}
async function start(){elE.textContent="";const r=await fetch("/__pteroprotect/challenge/new",{cache:"no-store"});const j=await r.json();if(!j.ok)throw new Error("challenge unavailable");nonce=j.nonce;salt=j.pow_salt;bits=Number(j.pow_bits)||16;elQ.textContent="Computing proof of work ("+bits+" bits)...";const c=await solve();elQ.textContent="Verifying...";const v=await fetch("/__pteroprotect/challenge/solve",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({nonce:nonce,pow_counter:c.counter,pow_hash:c.hash,rd:RD})});const out=await v.json();if(!out.ok)throw new Error(out.error||"failed");location.href=out.redirect||RD;}
elB.onclick=()=>start().catch(e=>elE.textContent=String(e.message||e));
start().catch(e=>elE.textContent=String(e.message||e));
</script></body></html>""".replace("__RD__", rd_js)
            return self._html(200, html)

        return self._json(404, {"ok": False, "error": "not_found"})

    def do_HEAD(self):
        parsed = urlparse(self.path)
        path = parsed.path
        ip = client_ip(self)
        ua = ua_fingerprint(self)

        if path == "/check":
            if not is_enabled():
                self.send_response(HTTPStatus.NO_CONTENT)
                self.send_header("Cache-Control", "no-store")
                self.end_headers()
                return
            ck = self._parse_cookie()
            tok = ck.get(cookie_name(), "")
            if tok and verify_token(tok, ip, ua):
                self.send_response(HTTPStatus.NO_CONTENT)
            else:
                self.send_response(HTTPStatus.UNAUTHORIZED)
            self.send_header("Cache-Control", "no-store")
            self.end_headers()
            return

        if path in ("/health", "/page", "/new"):
            self.send_response(HTTPStatus.OK)
            self.send_header("Cache-Control", "no-store")
            self.end_headers()
            return

        if path == "/check-provider-api":
            self.send_response(HTTPStatus.NO_CONTENT if provider_api_allowed(self) else HTTPStatus.UNAUTHORIZED)
            self.send_header("Cache-Control", "no-store")
            self.end_headers()
            return

        self.send_response(HTTPStatus.NOT_FOUND)
        self.end_headers()

    def do_POST(self):
        parsed = urlparse(self.path)
        path = parsed.path
        ip = client_ip(self)
        ua = ua_fingerprint(self)

        if path != "/solve":
            return self._json(404, {"ok": False, "error": "not_found"})

        if not is_enabled():
            return self._json(200, {"ok": True, "redirect": "/"})
        if not rate_allow(ip, "challenge_solve", 80, 60):
            return self._json(429, {"ok": False, "error": "rate_limited"})

        body = self._read_json()
        nonce = str(body.get("nonce", "")).strip()
        rd = normalize_redirect_path(str(body.get("rd", "/")).strip())
        try:
            counter = int(body.get("pow_counter", -1))
        except Exception:
            counter = -1
        client_hash = str(body.get("pow_hash", "")).strip().lower()

        with _nonce_lock:
            rec = _nonces.pop(nonce, None)
        if not rec:
            return self._json(401, {"ok": False, "error": "nonce_invalid"})
        if rec.get("exp", 0) < time.time():
            return self._json(401, {"ok": False, "error": "nonce_expired"})
        if rec.get("ip", "") != ip or rec.get("ua", "") != ua:
            return self._json(401, {"ok": False, "error": "fingerprint_mismatch"})
        if not verify_pow(nonce, str(rec.get("salt", "")), int(rec.get("bits", 0)), counter, client_hash):
            return self._json(401, {"ok": False, "error": "pow_invalid"})

        tok = issue_token(ip, ua)
        max_age = challenge_ttl()
        headers = {
            "Set-Cookie": f"{cookie_name()}={tok}; Path=/; Max-Age={max_age}; HttpOnly; Secure; SameSite=Lax",
        }
        return self._json(200, {"ok": True, "redirect": rd}, extra_headers=headers)


def main():
    secret = challenge_secret()
    allow_weak = str(net_setting("waf_challenge_allow_weak_secret", False)).strip().lower() in {"1", "true", "yes", "on"}
    if challenge_secret_is_weak(secret):
        if not allow_weak:
            print("pteroprotect_challenge_api: refusing to start with a weak waf_challenge_secret; "
                  "configure a 32+ character high-entropy secret or set "
                  "network.waf_challenge_allow_weak_secret=true", file=sys.stderr)
            return 1
        print("pteroprotect_challenge_api: WARNING weak waf_challenge_secret accepted by configuration", file=sys.stderr)
    bind = str(net_setting("waf_challenge_bind", "127.0.0.1"))
    try:
        port = int(net_setting("waf_challenge_port", 18444))
    except Exception:
        port = 18444
    srv = ThreadingHTTPServer((bind, port), Handler)
    srv.serve_forever()


if __name__ == "__main__":
    raise SystemExit(main())
