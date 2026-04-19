<?php

namespace Pterodactyl\Http\Middleware\Security;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

        if ($this->hasClearanceCookie($request)) {
            Cache::put($cacheKey, $current, $this->ttlSeconds());
            Cache::forget($rebindKey);
            if ($ipChallengeKey !== null) {
                Cache::forget($ipChallengeKey);
            }

            return $next($request);
        }

        Cache::put($rebindKey, true, $this->ttlSeconds());
        Cache::forget($cacheKey);
        if ($ipChallengeKey !== null) {
            Cache::put($ipChallengeKey, true, $this->ttlSeconds());
        }

        return $this->challengeResponse($request);
    }

    private function shouldBypass(Request $request): bool
    {
        $path = ltrim((string) $request->path(), '/');
        if ($path === '' || str_starts_with($path, '__pteroprotect/challenge')) {
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
        $ip = strtolower(trim($ip));
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return $ip;
        }

        // Mobile carriers often rotate subscriber IP quickly.
        // Keep strict full-IP binding for desktop, and prefix binding for mobile.
        if (!$this->isMobileUserAgent($ua)) {
            return $ip;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $parts = explode('.', $ip);
            if (count($parts) === 4) {
                $parts[3] = '0';
                return implode('.', $parts) . '/24';
            }

            return $ip;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = @inet_pton($ip);
            if ($packed === false) {
                return $ip;
            }

            $bytes = unpack('C*', $packed);
            if (!is_array($bytes)) {
                return $ip;
            }

            for ($i = 9; $i <= 16; $i++) {
                $bytes[$i] = 0;
            }

            $masked = '';
            for ($i = 1; $i <= 16; $i++) {
                $masked .= chr((int) ($bytes[$i] ?? 0));
            }

            $normalized = @inet_ntop($masked);
            return strtolower(($normalized !== false ? $normalized : $ip) . '/64');
        }

        return $ip;
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

    private function normalizedUserAgentForBinding(string $uaRaw): string
    {
        $ua = strtolower(trim($uaRaw));
        if ($ua === '') {
            return '';
        }

        if (strlen($ua) > 512) {
            $ua = substr($ua, 0, 512);
        }

        if (!$this->isMobileUserAgent($ua)) {
            return $ua;
        }

        $platform = 'mobile';
        if (str_contains($ua, 'android')) {
            $platform = 'android';
        } elseif (str_contains($ua, 'iphone') || str_contains($ua, 'ipad') || str_contains($ua, 'ipod')) {
            $platform = 'ios';
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

        return "mobile|{$platform}|{$browser}|{$major}";
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

    private function challengeResponse(Request $request): Response
    {
        $challengeUrl = $this->challengeUrl($request);
        if ($request->expectsJson() || str_starts_with((string) $request->path(), 'api/')) {
            return new JsonResponse([
                'error' => 'session_binding_mismatch',
                'challenge_url' => $challengeUrl,
            ], 403);
        }

        return redirect()->to($challengeUrl);
    }

    private function hasClearanceCookie(Request $request): bool
    {
        $cookieName = PteroProtectClearanceToken::cookieName();
        $token = trim((string) $request->cookie($cookieName, ''));

        return PteroProtectClearanceToken::isValid($request, $token, $cookieName);
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
        // Prefer Laravel trusted-proxy-resolved IP first.
        $requestIp = trim((string) $request->ip());
        if ($requestIp !== '' && filter_var($requestIp, FILTER_VALIDATE_IP) !== false) {
            return strtolower($requestIp);
        }

        // Fallback to server REMOTE_ADDR.
        $remoteAddr = trim((string) $request->server('REMOTE_ADDR', ''));
        if ($remoteAddr !== '' && filter_var($remoteAddr, FILTER_VALIDATE_IP) !== false) {
            return strtolower($remoteAddr);
        }

        // Final fallback: use first valid forwarding header value if available.
        $xForwardedFor = trim((string) $request->headers->get('X-Forwarded-For', ''));
        if ($xForwardedFor !== '') {
            foreach (explode(',', $xForwardedFor) as $part) {
                $candidate = trim($part);
                if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                    return strtolower($candidate);
                }
            }
        }

        $xRealIp = trim((string) $request->headers->get('X-Real-IP', ''));
        if ($xRealIp !== '' && filter_var($xRealIp, FILTER_VALIDATE_IP) !== false) {
            return strtolower($xRealIp);
        }

        $cfConnectingIp = trim((string) $request->headers->get('CF-Connecting-IP', ''));
        if ($cfConnectingIp !== '' && filter_var($cfConnectingIp, FILTER_VALIDATE_IP) !== false) {
            return strtolower($cfConnectingIp);
        }

        return '';
    }
}
