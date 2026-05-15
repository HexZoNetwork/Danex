<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
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
            <p>Your browser passed through the same IP clearance loop three times within 30 minutes. Resetting clears the stale session binding and starts a clean challenge.</p>
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
