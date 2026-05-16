<?php

namespace Pterodactyl\Http\Middleware\Security;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Support\Security\PteroProtectClearanceToken;
use Symfony\Component\HttpFoundation\Response;

class PteroProtectSessionBinding
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldBypass($request)) {
            return $next($request);
        }

        if (!$request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();
        $sessionId = trim((string) $session->getId());
        if ($sessionId === '') {
            return $next($request);
        }

        $ipChallengeKey = $this->ipChallengeKey($request);
        if ($ipChallengeKey !== null && (bool) Cache::get($ipChallengeKey, false)) {
            if (!$this->hasClearanceCookie($request)) {
                return $this->challengeResponse($request);
            }

            Cache::forget($ipChallengeKey);
        }

        $user = $request->user();
        $userId = $user ? (int) $user->id : 0;
        $cacheKey = 'pteroprotect:session:bind:' . hash('sha256', $sessionId);
        $rebindKey = 'pteroprotect:session:rebind:' . hash('sha256', $sessionId);

        // If this session is marked for rebind, force challenge until clearance cookie exists.
        if ((bool) Cache::get($rebindKey, false)) {
            if (!$this->hasClearanceCookie($request)) {
                return $this->challengeResponse($request);
            }

            Cache::put($cacheKey, $this->fingerprintPayload($request, $userId), $this->ttlSeconds());
            Cache::forget($rebindKey);
            if ($ipChallengeKey !== null) {
                Cache::forget($ipChallengeKey);
            }

            return $next($request);
        }

        $bound = Cache::get($cacheKey);
        if (!is_array($bound)) {
            // Only bind once an authenticated identity exists.
            if ($userId <= 0) {
                return $next($request);
            }

            Cache::put($cacheKey, $this->fingerprintPayload($request, $userId), $this->ttlSeconds());
            return $next($request);
        }

        $current = $this->fingerprintPayload($request, $userId);
        $boundUserId = (int) ($bound['user_id'] ?? 0);
        if ($boundUserId > 0 && $userId > 0 && $boundUserId !== $userId) {
            $this->auditMismatch($request, 'user_mismatch', $bound, $current);
            Cache::put($rebindKey, true, $this->ttlSeconds());
            Cache::forget($cacheKey);
            if ($ipChallengeKey !== null) {
                Cache::put($ipChallengeKey, true, $this->ttlSeconds());
            }

            return $this->challengeResponse($request);
        }

        $boundFp = (string) ($bound['fp'] ?? '');
        $currentFp = (string) ($current['fp'] ?? '');
        $mismatch = ($boundFp === '' || $currentFp === '' || !hash_equals($boundFp, $currentFp));
        if (!$mismatch) {
            Cache::put($cacheKey, $bound, $this->ttlSeconds());
            return $next($request);
        }

        $clearance = $this->clearanceStatus($request);
        if ((bool) $clearance['ok']) {
            $this->auditMismatch($request, 'rebound_with_clearance', $bound, $current, $clearance['reason']);
            Cache::put($cacheKey, $current, $this->ttlSeconds());
            Cache::forget($rebindKey);
            if ($ipChallengeKey !== null) {
                Cache::forget($ipChallengeKey);
            }

            return $next($request);
        }

        $this->auditMismatch($request, 'clearance_required', $bound, $current, $clearance['reason']);
        Cache::put($rebindKey, true, $this->ttlSeconds());
        Cache::forget($cacheKey);
        if ($ipChallengeKey !== null) {
            Cache::put($ipChallengeKey, true, $this->ttlSeconds());
        }

        return $this->challengeResponse($request, $clearance['reason']);
    }

    private function shouldBypass(Request $request): bool
    {
        $path = ltrim((string) $request->path(), '/');
        if (
            $path === '' ||
            str_starts_with($path, '__pteroprotect/challenge') ||
            str_starts_with($path, '__pteroprotect/session')
        ) {
            return true;
        }

        // Wings <-> Panel application API must stay machine-to-machine and must
        // never be challenged by browser session binding.
        if (str_starts_with($path, 'api/application/')) {
            return true;
        }

        // Ads payload is non-sensitive UI content and can be polled frequently.
        // Excluding it prevents noisy session-binding loops that redirect to
        // challenge page with rd=/api/client/ads.
        if ($path === 'api/client/ads' || str_starts_with($path, 'api/client/ads/')) {
            return true;
        }

        if (
            $request->isMethod('GET') &&
            preg_match('#^api/client/servers/[^/]+/(resources|activity)(?:/|$)#i', $path) === 1
        ) {
            return true;
        }

        return false;
    }

    /**
     * @return array{fp:string,user_id:int}
     */
    private function fingerprintPayload(Request $request, int $userId): array
    {
        $rawIp = $this->clientIpForBinding($request);
        $ua = $this->normalizedUserAgentForBinding((string) $request->userAgent());
        $ip = $this->normalizeIpForBinding($rawIp, $ua);

        return [
            'fp' => hash('sha256', strtolower($ip) . '|' . $ua),
            'user_id' => $userId,
        ];
    }

    private function normalizeIpForBinding(string $ip, string $ua): string
    {
        unset($ua);
        $prefix = PteroProtectClearanceToken::ipPrefixForBinding($ip);
        return $prefix !== '' ? $prefix : strtolower(trim($ip));
    }

    private function isMobileUserAgent(string $ua): bool
    {
        $ua = strtolower(trim($ua));
        return $ua !== '' && (
            str_contains($ua, 'mobile') ||
            str_contains($ua, 'android') ||
            str_contains($ua, 'iphone') ||
            str_contains($ua, 'ipad') ||
            str_contains($ua, 'ipod')
        );
    }

    private function isInAppUserAgent(string $ua): bool
    {
        $ua = strtolower(trim($ua));
        if ($ua === '') {
            return false;
        }

        $hints = [
            ' wv)', '; wv', 'telegram', 'fb_iab', 'fban', 'fbav', 'instagram',
            'line/', 'micromessenger', 'gsa/', 'okhttp', 'vivo', 'miuibrowser',
        ];

        foreach ($hints as $hint) {
            if (str_contains($ua, $hint)) {
                return true;
            }
        }

        return false;
    }

    private function usesRelaxedBinding(string $ua): bool
    {
        return $this->isMobileUserAgent($ua) || $this->isInAppUserAgent($ua);
    }

    private function normalizedUserAgentForBinding(string $uaRaw): string
    {
        $ua = strtolower(trim($uaRaw));
        if ($ua === '') {
            return '';
        }

        if (strlen($ua) > 512) {
            $ua = substr($ua, 0, 512);
        }

        if (!$this->usesRelaxedBinding($ua)) {
            return $ua;
        }

        $platform = 'mobile';
        if (str_contains($ua, 'android')) {
            $platform = 'android';
        } elseif (str_contains($ua, 'iphone') || str_contains($ua, 'ipad') || str_contains($ua, 'ipod')) {
            $platform = 'ios';
        } elseif ($this->isInAppUserAgent($ua)) {
            $platform = 'inapp';
        }

        $browser = 'other';
        $major = '0';
        $rules = [
            ['edg/', 'edge'],
            ['opr/', 'opera'],
            ['firefox/', 'firefox'],
            ['fxios/', 'firefox'],
            ['crios/', 'chrome'],
            ['chrome/', 'chrome'],
            ['version/', 'safari'],
        ];

        foreach ($rules as [$needle, $name]) {
            if (str_contains($ua, $needle)) {
                $browser = $name;
                if (preg_match('/' . preg_quote($needle, '/') . '([0-9]+)/', $ua, $matches) === 1) {
                    $major = (string) ($matches[1] ?? '0');
                }
                break;
            }
        }

        unset($major);
        return PteroProtectClearanceToken::uaBindingMaterial($uaRaw);
    }

    private function ttlSeconds(): int
    {
        $minutes = (int) config('session.lifetime', 120);
        return max(300, $minutes * 60);
    }

    private function challengeUrl(Request $request): string
    {
        $rd = '/' . ltrim((string) $request->path(), '/');
        $query = trim((string) $request->server('QUERY_STRING', ''));
        if ($query !== '') {
            $rd .= '?' . $query;
        }

        return '/__pteroprotect/challenge/page?rd=' . rawurlencode($rd);
    }

    private function challengeResponse(Request $request, string $reason = 'missing_cookie'): Response
    {
        $challengeUrl = $this->challengeUrl($request);
        $attempts = $this->recordClearanceAttempt($request);
        $showReset = $attempts >= 3;
        $resetUrl = $this->clearanceResetUrl($request);
        $errorUrl = $this->clearanceErrorUrl($request);

        if ($request->expectsJson() || str_starts_with((string) $request->path(), 'api/')) {
            return new JsonResponse([
                'error' => 'session_binding_mismatch',
                'reason' => $reason,
                'challenge_url' => $challengeUrl,
                'clearance_reset_url' => $resetUrl,
                'clearance_error_url' => $errorUrl,
                'clearance_attempts' => $attempts,
                'show_clearance_reset' => $showReset,
            ], 403);
        }

        if ($showReset) {
            return redirect()->to($errorUrl);
        }

        return redirect()->to($challengeUrl);
    }

    private function clearanceResetUrl(Request $request): string
    {
        return '/__pteroprotect/session/reset-clearance?rd=' . rawurlencode($this->redirectPath($request));
    }

    private function clearanceErrorUrl(Request $request): string
    {
        return '/__pteroprotect/session/clearance-error?rd=' . rawurlencode($this->redirectPath($request));
    }

    private function redirectPath(Request $request): string
    {
        $rd = '/' . ltrim((string) $request->path(), '/');
        $query = trim((string) $request->server('QUERY_STRING', ''));
        if ($query !== '') {
            $rd .= '?' . $query;
        }

        return $rd;
    }

    private function recordClearanceAttempt(Request $request): int
    {
        $key = $this->clearanceAttemptKey($request);
        if ($key === null) {
            return 1;
        }

        Cache::add($key, 0, 1800);
        $attempts = Cache::increment($key);

        return is_numeric($attempts) ? (int) $attempts : 1;
    }

    private function clearanceAttemptKey(Request $request): ?string
    {
        if (!$request->hasSession()) {
            return null;
        }

        $sessionId = trim((string) $request->session()->getId());
        $ip = strtolower(trim($this->clientIpForBinding($request)));
        if ($sessionId === '' || $ip === '') {
            return null;
        }

        return 'pteroprotect:session:clearance_errors:' . hash('sha256', $sessionId . '|' . $ip);
    }

    private function hasClearanceCookie(Request $request): bool
    {
        return (bool) $this->clearanceStatus($request)['ok'];
    }

    /**
     * @return array{ok:bool,reason:string}
     */
    private function clearanceStatus(Request $request): array
    {
        $cookieName = PteroProtectClearanceToken::cookieName();
        $token = trim((string) $request->cookie($cookieName, ''));
        $result = PteroProtectClearanceToken::validate($request, $token, $cookieName);

        return [
            'ok' => (bool) ($result['ok'] ?? false),
            'reason' => (string) ($result['reason'] ?? 'invalid_clearance'),
        ];
    }

    private function ipChallengeKey(Request $request): ?string
    {
        $ip = $this->clientIpForBinding($request);
        if ($ip === '') {
            return null;
        }

        return 'pteroprotect:force_challenge:ip:' . hash('sha256', strtolower($ip));
    }

    private function clientIpForBinding(Request $request): string
    {
        return PteroProtectClearanceToken::clientIpForBinding($request);
    }

    private function auditMismatch(Request $request, string $action, array $bound, array $current, string $reason = ''): void
    {
        try {
            Log::warning('pteroprotect.session_binding_mismatch', [
                'action' => $action,
                'reason' => $reason,
                'path' => '/' . ltrim((string) $request->path(), '/'),
                'ip' => $this->clientIpForBinding($request),
                'bound_fp' => substr((string) ($bound['fp'] ?? ''), 0, 12),
                'current_fp' => substr((string) ($current['fp'] ?? ''), 0, 12),
                'bound_user_id' => (int) ($bound['user_id'] ?? 0),
                'current_user_id' => (int) ($current['user_id'] ?? 0),
            ]);
        } catch (\Throwable) {
            // Logging must never block the request path.
        }
    }
}
