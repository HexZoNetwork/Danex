<?php

namespace Pterodactyl\Http\Middleware\Security;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

        $bucket = (int) floor(time() / self::WINDOW_SECONDS);
        $key = sprintf('pp:avatar-upload:u%d:h%d', $userId, $bucket);
        $count = (int) Cache::get($key, 0);

        if ($count >= self::LIMIT_PER_HOUR) {
            return new JsonResponse([
                'error' => 'Upload avatar dibatasi maksimal 3 kali per jam.',
            ], 429);
        }

        if (!Cache::has($key)) {
            Cache::put($key, 1, now()->addSeconds(self::WINDOW_SECONDS + 5));
        } else {
            Cache::increment($key);
        }

        return $next($request);
    }
}
