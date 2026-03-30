<?php

namespace Pterodactyl\Http\Middleware\Security;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
        $boundFp = (string) ($bound['fp'] ?? '');
        $currentFp = (string) ($current['fp'] ?? '');
        $boundUserId = (int) ($bound['user_id'] ?? 0);

        $mismatch = ($boundFp === '' || $currentFp === '' || !hash_equals($boundFp, $currentFp));
        $userMismatch = ($boundUserId > 0 && $userId > 0 && $boundUserId !== $userId);

        if (!$mismatch && !$userMismatch) {
            Cache::put($cacheKey, $bound, $this->ttlSeconds());
            return $next($request);
        }

        // Do not logout; force challenge and rebind only after clearance is present.
        Cache::put($rebindKey, true, $this->ttlSeconds());
        Cache::forget($cacheKey);

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
        $ip = trim((string) $request->ip());
        $ua = strtolower(trim((string) $request->userAgent()));
        if (strlen($ua) > 512) {
            $ua = substr($ua, 0, 512);
        }

        return [
            'fp' => hash('sha256', strtolower($ip) . '|' . $ua),
            'user_id' => $userId,
        ];
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
        $cookieName = trim((string) config('pteroprotect.waf.challenge_cookie_name', 'pp_clearance'));
        if ($cookieName === '') {
            $cookieName = 'pp_clearance';
        }

        return trim((string) $request->cookie($cookieName, '')) !== '';
    }
}
