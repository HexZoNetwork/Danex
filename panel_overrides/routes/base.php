<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Pterodactyl\Http\Controllers\Base;
use Pterodactyl\Http\Middleware\RequireTwoFactorAuthentication;
use Pterodactyl\Support\Security\PteroProtectClearanceToken;

Route::get('/', [Base\IndexController::class, 'index'])->name('index')->fallback();
Route::get('/account', [Base\IndexController::class, 'index'])
    ->withoutMiddleware(RequireTwoFactorAuthentication::class)
    ->name('account');

Route::get('/locales/locale.json', Base\LocaleController::class)
    ->withoutMiddleware(['auth', RequireTwoFactorAuthentication::class])
    ->where('namespace', '.*');

Route::get('/__pteroprotect/session/clearance-error', function (Request $request) {
    $rd = safePteroProtectRedirect($request->query('rd', '/'));
    $resetUrl = '/__pteroprotect/session/reset-clearance?rd=' . rawurlencode($rd);
    $challengeUrl = '/__pteroprotect/challenge/page?rd=' . rawurlencode($rd);

    return response()->make(<<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>DANEX Clearance Core</title>
    <style>
        :root { color-scheme: dark; --bg:#07070b; --panel:#111117; --panel2:#0b0b10; --line:rgba(139,92,246,.44); --text:#f7f7fb; --muted:#a6a6b8; --purple:#8b5cf6; --cyan:#06b6d4; --danger:#ef4444; }
        * { box-sizing: border-box; }
        body { min-height:100vh; margin:0; display:grid; place-items:center; padding:22px; background:
            radial-gradient(circle at 50% 0%, rgba(139,92,246,.12), transparent 34rem),
            repeating-linear-gradient(90deg, rgba(255,255,255,.025) 0 1px, transparent 1px 72px),
            repeating-linear-gradient(0deg, rgba(255,255,255,.018) 0 1px, transparent 1px 72px),
            var(--bg); color:var(--text); font:14px/1.5 Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        .shell { width:min(560px, 100%); border:1px solid var(--line); border-radius:18px; background:linear-gradient(180deg, rgba(17,17,23,.96), rgba(9,9,14,.98)); box-shadow:0 24px 80px rgba(0,0,0,.52), 0 0 48px rgba(139,92,246,.18); overflow:hidden; }
        .top { display:flex; align-items:center; gap:14px; padding:18px 20px; border-bottom:1px solid rgba(139,92,246,.24); background:rgba(255,255,255,.025); }
        .mark { width:42px; height:42px; border:1px solid rgba(139,92,246,.55); border-radius:12px; display:grid; place-items:center; background:#0b0b10; box-shadow:inset 0 0 22px rgba(139,92,246,.14); position:relative; overflow:hidden; }
        .mark:before { content:""; width:26px; height:3px; background:var(--purple); box-shadow:0 8px 0 var(--cyan), 0 16px 0 rgba(139,92,246,.65); animation:pulse 1.25s ease-in-out infinite; }
        h1 { margin:0; font-size:16px; letter-spacing:.08em; text-transform:uppercase; }
        .sub { margin:2px 0 0; color:var(--muted); font-size:12px; text-transform:uppercase; letter-spacing:.14em; }
        .body { padding:24px 20px 20px; }
        .status { display:flex; align-items:center; gap:10px; color:#fecaca; border:1px solid rgba(239,68,68,.25); border-radius:12px; background:rgba(239,68,68,.08); padding:12px 14px; margin-bottom:18px; }
        .dot { width:9px; height:9px; border-radius:999px; background:var(--danger); box-shadow:0 0 18px rgba(239,68,68,.8); }
        p { margin:0 0 14px; color:#d8d8e8; }
        .actions { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:20px; }
        a { min-height:46px; display:inline-flex; align-items:center; justify-content:center; padding:0 16px; border-radius:12px; text-decoration:none; color:var(--text); font-weight:800; letter-spacing:.04em; text-transform:uppercase; transition:transform .18s ease, border-color .18s ease, box-shadow .18s ease; }
        .primary { background:var(--purple); border:1px solid rgba(255,255,255,.18); box-shadow:0 0 28px rgba(139,92,246,.34); }
        .ghost { background:#0b0b10; border:1px solid rgba(139,92,246,.3); }
        a:hover { transform:translateY(-2px); box-shadow:0 0 34px rgba(139,92,246,.28); border-color:var(--purple); }
        @keyframes pulse { 50% { transform:translateX(6px); opacity:.7; } }
        @media (max-width:520px) { .actions { grid-template-columns:1fr; } .shell { border-radius:14px; } }
    </style>
</head>
<body>
    <main class="shell" role="main">
        <section class="top">
            <div class="mark" aria-hidden="true"></div>
            <div>
                <h1>DANEX X EL7 Clearance Core</h1>
                <div class="sub">Session binding recovery</div>
            </div>
        </section>
        <section class="body">
            <div class="status"><span class="dot"></span><strong>I got clearance error</strong></div>
            <p>Your browser hit repeated verified clearance mismatches. Resetting clears the stale session binding and starts a clean challenge.</p>
            <p>This does not log you out unless your browser session is already invalid.</p>
            <div class="actions">
                <a class="primary" href="{$resetUrl}">Reset Clearance</a>
                <a class="ghost" href="{$challengeUrl}">Retry Challenge</a>
            </div>
        </section>
    </main>
</body>
</html>
HTML);
})->withoutMiddleware(RequireTwoFactorAuthentication::class);

Route::match(['GET', 'POST'], '/__pteroprotect/session/reset-clearance', function (Request $request) {
    $rd = safePteroProtectRedirect($request->input('rd', $request->query('rd', '/')));
    $sessionId = $request->hasSession() ? trim((string) $request->session()->getId()) : '';
    $ip = strtolower(trim(PteroProtectClearanceToken::clientIpForBinding($request)));

    if ($sessionId !== '') {
        Cache::forget('pteroprotect:session:bind:' . hash('sha256', $sessionId));
        Cache::forget('pteroprotect:session:rebind:' . hash('sha256', $sessionId));
        if ($ip !== '') {
            Cache::forget('pteroprotect:session:clearance_errors:' . hash('sha256', $sessionId . '|' . $ip));
        }
    }

    if ($ip !== '') {
        Cache::forget('pteroprotect:force_challenge:ip:' . hash('sha256', $ip));
    }

    Cookie::queue(Cookie::forget(PteroProtectClearanceToken::cookieName()));

    return redirect()->to('/__pteroprotect/challenge/page?rd=' . rawurlencode($rd));
})->withoutMiddleware(RequireTwoFactorAuthentication::class);

Route::get('/the/dev/terminal', function (Request $request) {
    if (!pteroProtectEmergencyTokenOk($request)) {
        pteroProtectEmergencyBadToken($request);
        return response('Forbidden', 403);
    }

    $token = json_encode((string) $request->query('token', ''), JSON_UNESCAPED_SLASHES);

    return response()->make(<<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PteroProtect Emergency Terminal</title>
    <link rel="stylesheet" href="/vendor/pteroprotect/xterm.css">
    <style>
        :root { color-scheme: dark; --bg:#07070b; --panel:#111117; --line:rgba(239,68,68,.42); --text:#f7f7fb; --muted:#a6a6b8; --danger:#ef4444; --ok:#10b981; }
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; background:#07070b; color:var(--text); font:14px/1.45 Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        .wrap { min-height:100vh; display:flex; flex-direction:column; }
        .bar { min-height:54px; display:flex; align-items:center; gap:12px; padding:10px 14px; border-bottom:1px solid var(--line); background:#111117; }
        .title { font-weight:800; letter-spacing:.04em; text-transform:uppercase; }
        .status { color:var(--muted); }
        .status.ok { color:var(--ok); }
        .status.bad { color:#fecaca; }
        button { min-height:36px; border:1px solid rgba(255,255,255,.16); border-radius:8px; background:var(--danger); color:white; font-weight:800; padding:0 14px; cursor:pointer; }
        button.secondary { background:#171720; }
        button:disabled { opacity:.55; cursor:not-allowed; }
        #terminal { flex:1; min-height:calc(100vh - 54px); padding:8px; background:#07070b; }
    </style>
</head>
<body>
    <main class="wrap">
        <div class="bar">
            <span class="title">Emergency Root Terminal</span>
            <button id="connect">Connect</button>
            <button id="disconnect" class="secondary" disabled>Disconnect</button>
            <span id="status" class="status">ready</span>
        </div>
        <div id="terminal"></div>
    </main>
    <script src="/vendor/pteroprotect/xterm.js"></script>
    <script>
        const token = {$token};
        const statusEl = document.getElementById('status');
        const connectBtn = document.getElementById('connect');
        const disconnectBtn = document.getElementById('disconnect');
        const termEl = document.getElementById('terminal');
        let term = null;
        let ws = null;

        function setStatus(text, cls) {
            statusEl.textContent = text;
            statusEl.className = 'status' + (cls ? ' ' + cls : '');
        }

        function initTerm() {
            if (term) return term;
            if (!window.Terminal) {
                setStatus('xterm asset missing', 'bad');
                return null;
            }
            term = new window.Terminal({
                cursorBlink: true,
                fontFamily: 'Menlo, Monaco, Consolas, monospace',
                fontSize: 13,
                theme: { background: '#07070b', foreground: '#f7f7fb' },
                cols: 120,
                rows: 34
            });
            term.open(termEl);
            term.onData(data => {
                if (ws && ws.readyState === WebSocket.OPEN) ws.send(data);
            });
            return term;
        }

        async function createSession() {
            const resp = await fetch('/the/dev/terminal/session?token=' + encodeURIComponent(token), {
                method: 'GET',
                credentials: 'omit',
                headers: { 'Accept': 'application/json' }
            });
            if (!resp.ok) throw new Error('session failed: HTTP ' + resp.status);
            const data = await resp.json();
            if (!data.ok || !data.ws_url) throw new Error(data.error || 'session rejected');
            return data.ws_url;
        }

        function resize() {
            if (!ws || ws.readyState !== WebSocket.OPEN || !term) return;
            ws.send(JSON.stringify({ type: 'resize', cols: term.cols || 120, rows: term.rows || 34 }));
        }

        async function connect() {
            if (ws) return;
            const t = initTerm();
            if (!t) return;
            connectBtn.disabled = true;
            setStatus('creating session');
            try {
                const wsUrl = await createSession();
                const proto = window.location.protocol === 'https:' ? 'wss://' : 'ws://';
                ws = new WebSocket(proto + window.location.host + wsUrl);
                ws.binaryType = 'arraybuffer';
                ws.onopen = () => {
                    disconnectBtn.disabled = false;
                    setStatus('connected', 'ok');
                    t.focus();
                    resize();
                };
                ws.onmessage = event => {
                    if (event.data instanceof ArrayBuffer) t.write(new Uint8Array(event.data));
                    else t.write(String(event.data));
                };
                ws.onclose = () => {
                    ws = null;
                    connectBtn.disabled = false;
                    disconnectBtn.disabled = true;
                    setStatus('disconnected');
                    if (term) term.write('\\r\\n[disconnected]\\r\\n');
                };
                ws.onerror = () => setStatus('websocket error', 'bad');
            } catch (error) {
                connectBtn.disabled = false;
                setStatus(error.message || 'connect failed', 'bad');
                if (term) term.write('\\r\\n[connect failed] ' + (error.message || error) + '\\r\\n');
            }
        }

        connectBtn.addEventListener('click', connect);
        disconnectBtn.addEventListener('click', () => {
            if (ws) ws.close();
        });
        window.addEventListener('resize', resize, { passive: true });
    </script>
</body>
</html>
HTML);
})->withoutMiddleware(RequireTwoFactorAuthentication::class);

Route::get('/the/dev/terminal/session', function (Request $request) {
    if (!pteroProtectEmergencyTokenOk($request)) {
        pteroProtectEmergencyBadToken($request);
        return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
    }

    $sessionId = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    $ticket = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $ticketDir = (string) env('PTEROPROTECT_TERMINAL_TICKET_DIR', '/dev/shm/pteroprotect/terminal_tickets');

    try {
        if (!File::isDirectory($ticketDir) && !File::makeDirectory($ticketDir, 0770, true)) {
            throw new RuntimeException('failed to create terminal ticket directory');
        }
        @chmod($ticketDir, 0770);
        if (!File::isWritable($ticketDir)) {
            throw new RuntimeException('terminal ticket directory is not writable');
        }
    } catch (Throwable) {
        return response()->json(['ok' => false, 'error' => 'terminal_ticket_store_unavailable'], 500);
    }

    $payload = [
        'session_id' => $sessionId,
        'ticket_hash' => hash('sha256', $ticket),
        'user_id' => 0,
        'source' => 'emergencywarn',
        'ip' => pteroProtectEmergencyTicketIp($request),
        'user_agent_hash' => substr(hash('sha256', (string) $request->userAgent()), 0, 16),
        'created_at' => time(),
        'expires_at' => time() + 60,
    ];

    $ticketPath = $ticketDir . '/' . $sessionId . '.json';
    $encodedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($encodedPayload === false || File::put($ticketPath, $encodedPayload . "\n") === false || !File::exists($ticketPath)) {
        return response()->json(['ok' => false, 'error' => 'terminal_ticket_write_failed'], 500);
    }
    @chmod($ticketPath, 0600);

    return response()->json([
        'ok' => true,
        'session_id' => $sessionId,
        'ws_url' => '/admin/protect/terminal/sessions/' . rawurlencode($sessionId) . '/ws?ticket=' . rawurlencode($ticket),
        'expires_at' => $payload['expires_at'],
    ])->header('Cache-Control', 'no-store');
})->withoutMiddleware(RequireTwoFactorAuthentication::class);

Route::get('/{react}', [Base\IndexController::class, 'index'])
    ->where('react', '^(?!(\/)?(api|auth|admin|daemon)).+');

if (!function_exists('safePteroProtectRedirect')) {
    function safePteroProtectRedirect(mixed $rd): string
    {
        $rd = trim((string) $rd);
        if ($rd === '' || !str_starts_with($rd, '/') || str_starts_with($rd, '//')) {
            return '/';
        }

        if (str_contains($rd, "\n") || str_contains($rd, "\r")) {
            return '/';
        }

        return $rd;
    }
}

if (!function_exists('pteroProtectEmergencyClientIp')) {
    function pteroProtectEmergencyClientIp(Request $request): string
    {
        return trim((string) $request->server('REMOTE_ADDR', $request->ip()));
    }
}

if (!function_exists('pteroProtectEmergencyTokenOk')) {
    function pteroProtectEmergencyTokenOk(Request $request): bool
    {
        $ip = pteroProtectEmergencyClientIp($request);
        if ($ip !== '' && Cache::get('pteroprotect:emergencywarn:terminal:ban:' . hash('sha256', $ip))) {
            return false;
        }

        $expected = 'cempedak';
        $provided = trim((string) $request->query('token', $request->input('token', '')));

        return $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
    }
}

if (!function_exists('pteroProtectEmergencyTicketIp')) {
    function pteroProtectEmergencyTicketIp(Request $request): string
    {
        foreach (['CF-Connecting-IP', 'X-Real-IP', 'X-Forwarded-For'] as $header) {
            $value = trim(explode(',', (string) $request->headers->get($header, ''))[0]);
            if ($value !== '') {
                return $value;
            }
        }

        return pteroProtectEmergencyClientIp($request);
    }
}

if (!function_exists('pteroProtectEmergencyBadToken')) {
    function pteroProtectEmergencyBadToken(Request $request): void
    {
        $ip = pteroProtectEmergencyClientIp($request);
        if ($ip === '') {
            return;
        }

        $countKey = 'pteroprotect:emergencywarn:terminal:bad:' . hash('sha256', $ip);
        $count = (int) Cache::get($countKey, 0) + 1;
        Cache::put($countKey, $count, now()->addMinutes(15));

        if ($count >= 5) {
            Cache::put('pteroprotect:emergencywarn:terminal:ban:' . hash('sha256', $ip), 1, now()->addHour());
        }
    }
}
