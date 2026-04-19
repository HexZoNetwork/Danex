<?php

namespace Pterodactyl\Http\Middleware\Security;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class PteroProtectAvatarUploadRateLimit
{
    private const LIMIT_PER_HOUR = 3;
    private const WINDOW_SECONDS = 3600;

    public function handle(Request $request, Closure $next): Response
    {
        $userId = (int) optional($request->user())->id;
        if ($userId <= 0) {
            return $next($request);
        }

        $key = sprintf('pp:avatar-upload:u%d', $userId);
        if (RateLimiter::tooManyAttempts($key, self::LIMIT_PER_HOUR)) {
            return new JsonResponse([
                'error' => 'Upload avatar dibatasi maksimal 3 kali per jam.',
            ], 429);
        }

        RateLimiter::hit($key, self::WINDOW_SECONDS);

        return $next($request);
    }
}
