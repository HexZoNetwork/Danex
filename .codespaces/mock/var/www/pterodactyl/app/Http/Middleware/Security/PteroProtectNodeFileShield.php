<?php

namespace Pterodactyl\Http\Middleware\Security;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpFoundation\Response;

class PteroProtectNodeFileShield
{
    /**
     * @var array<string,int>
     */
    private const PER_MINUTE_LIMITS = [
        'list' => 120,
        'contents' => 120,
        'download' => 120,
        'write' => 40,
        'rename' => 40,
        'copy' => 60,
        'compress' => 20,
        'decompress' => 12,
        'delete' => 30,
        'create-folder' => 40,
        'chmod' => 30,
        'pull' => 10,
        'upload' => 35,
    ];

    /**
     * Global per-server cap to prevent many users/tokens from overloading one node.
     *
     * @var array<string,int>
     */
    private const SERVER_PER_MINUTE_LIMITS = [
        'list' => 420,
        'contents' => 360,
        'download' => 240,
        'write' => 120,
    ];

    /**
     * Per-IP cap to prevent many stolen tokens/accounts from one source abusing node file endpoints.
     *
     * @var array<string,int>
     */
    private const IP_PER_MINUTE_LIMITS = [
        'list' => 70,
        'contents' => 60,
        'download' => 50,
        'write' => 20,
    ];

    /**
     * Short window burst limits (open/close abuse protection).
     *
     * @var array<string,int>
     */
    private const BURST_LIMITS = [
        'list' => 18,
        'contents' => 14,
        'download' => 12,
    ];

    private const DEFAULT_LIMIT = 60;
    private const WINDOW_SECONDS = 60;
    private const BURST_WINDOW_SECONDS = 5;
    private const BURST_BLOCK_SECONDS = 30;
    private const MAX_WRITE_BYTES_PER_REQUEST = 1_500_000; // 1.5 MB per write call
    private const MAX_WRITE_BYTES_PER_MINUTE = 6_000_000; // 6 MB/minute per user per server
    private const MAX_FILES_PER_BATCH = 120;

    public function handle(Request $request, Closure $next): Response
    {
        $action = $this->resolveAction($request);
        if ($action === null) {
            return $next($request);
        }

        $userId = (int) optional($request->user())->id;
        if ($userId <= 0) {
            return $next($request);
        }

        $serverKey = $this->resolveServerKey($request);
        if ($serverKey === null) {
            return $next($request);
        }

        $throttle = $this->guardRateLimit($userId, $serverKey, $action);
        if ($throttle !== null) {
            return $throttle;
        }

        $ipThrottle = $this->guardIpRateLimit($request, $serverKey, $action);
        if ($ipThrottle !== null) {
            return $ipThrottle;
        }

        $serverThrottle = $this->guardServerRateLimit($serverKey, $action);
        if ($serverThrottle !== null) {
            return $serverThrottle;
        }

        $burstGuard = $this->guardBurstRateLimit($userId, $serverKey, $action);
        if ($burstGuard !== null) {
            return $burstGuard;
        }

        $batchGuard = $this->guardBatchOperation($request, $action);
        if ($batchGuard !== null) {
            return $batchGuard;
        }

        $writeGuard = $this->guardWritePayload($request, $userId, $serverKey, $action);
        if ($writeGuard !== null) {
            return $writeGuard;
        }

        return $next($request);
    }

    private function resolveAction(Request $request): ?string
    {
        $path = trim((string) $request->path(), '/');
        if (!str_starts_with($path, 'api/client/servers/')) {
            return null;
        }

        if (preg_match('#^api/client/servers/[^/]+/files(?:/([^/]+))?$#i', $path, $matches) !== 1) {
            return null;
        }

        $action = strtolower(trim((string) ($matches[1] ?? 'list')));
        if ($action === '') {
            $action = 'list';
        }

        return $action;
    }

    private function resolveServerKey(Request $request): ?string
    {
        $routeServer = $request->route('server');
        if ($routeServer instanceof Server) {
            $id = (int) $routeServer->id;
            if ($id > 0) {
                return 'id' . $id;
            }

            if (!empty($routeServer->uuid)) {
                return 'uuid:' . strtolower((string) $routeServer->uuid);
            }
        }

        if (is_string($routeServer) && $routeServer !== '') {
            return 'raw:' . strtolower($routeServer);
        }

        return null;
    }

    private function guardRateLimit(int $userId, string $serverKey, string $action): ?JsonResponse
    {
        $limit = self::PER_MINUTE_LIMITS[$action] ?? self::DEFAULT_LIMIT;
        $key = sprintf('pp:nodefs:rate:u%d:s%s:a%s', $userId, $serverKey, $action);

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return $this->reject('File operation is temporarily rate-limited. Please slow down.', 429);
        }

        RateLimiter::hit($key, self::WINDOW_SECONDS);

        return null;
    }

    private function guardServerRateLimit(string $serverKey, string $action): ?JsonResponse
    {
        $limit = self::SERVER_PER_MINUTE_LIMITS[$action] ?? 0;
        if ($limit <= 0) {
            return null;
        }

        $key = sprintf('pp:nodefs:srvrate:s%s:a%s', $serverKey, $action);

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return $this->reject('File operation is globally rate-limited for this server. Please retry shortly.', 429);
        }

        RateLimiter::hit($key, self::WINDOW_SECONDS);

        return null;
    }

    private function guardIpRateLimit(Request $request, string $serverKey, string $action): ?JsonResponse
    {
        $limit = self::IP_PER_MINUTE_LIMITS[$action] ?? 0;
        if ($limit <= 0) {
            return null;
        }

        $ip = trim((string) $request->ip());
        if ($ip === '') {
            $ip = 'unknown';
        }

        $ua = strtolower(trim((string) $request->userAgent()));
        $fingerprint = hash('sha256', $ip . '|' . $ua);
        $key = sprintf('pp:nodefs:iprate:s%s:a%s:f%s', $serverKey, $action, $fingerprint);

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return $this->reject('File endpoint rate-limit reached for your connection fingerprint.', 429);
        }

        RateLimiter::hit($key, self::WINDOW_SECONDS);

        return null;
    }

    private function guardBurstRateLimit(int $userId, string $serverKey, string $action): ?JsonResponse
    {
        $limit = self::BURST_LIMITS[$action] ?? 0;
        if ($limit <= 0) {
            return null;
        }

        $blockedKey = sprintf('pp:nodefs:burst:block:u%d:s%s:a%s', $userId, $serverKey, $action);
        if (Cache::has($blockedKey)) {
            return $this->reject('Burst access detected on file endpoint. Slow down and retry in a few seconds.', 429);
        }

        $key = sprintf('pp:nodefs:burst:u%d:s%s:a%s', $userId, $serverKey, $action);
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            Cache::put($blockedKey, 1, now()->addSeconds(self::BURST_BLOCK_SECONDS));

            return $this->reject('Burst access detected on file endpoint. Slow down and retry in a few seconds.', 429);
        }

        RateLimiter::hit($key, self::BURST_WINDOW_SECONDS);

        return null;
    }

    private function guardBatchOperation(Request $request, string $action): ?JsonResponse
    {
        if (!in_array($action, ['rename', 'compress', 'delete', 'chmod'], true)) {
            return null;
        }

        $files = $request->input('files');
        if (!is_array($files)) {
            return null;
        }

        if (count($files) > self::MAX_FILES_PER_BATCH) {
            return $this->reject('Too many files in one request. Split into smaller batches.', 422);
        }

        return null;
    }

    private function guardWritePayload(Request $request, int $userId, string $serverKey, string $action): ?JsonResponse
    {
        if ($action !== 'write') {
            return null;
        }

        $rawBody = (string) $request->getContent();
        $bytes = strlen($rawBody);
        if ($bytes > self::MAX_WRITE_BYTES_PER_REQUEST) {
            return $this->reject('Write payload too large for one request.', 413);
        }

        $bucket = (int) floor(time() / self::WINDOW_SECONDS);
        $key = sprintf('pp:nodefs:bytes:u%d:s%s:w%d', $userId, $serverKey, $bucket);
        if (!Cache::add($key, $bytes, now()->addSeconds(self::WINDOW_SECONDS + 2))) {
            $updated = Cache::increment($key, $bytes);
            $used = is_numeric($updated) ? (int) $updated : (int) Cache::get($key, 0);
            if ($used > self::MAX_WRITE_BYTES_PER_MINUTE) {
                return $this->reject('Write throughput limit reached. Please wait a moment.', 429);
            }
        } elseif ($bytes > self::MAX_WRITE_BYTES_PER_MINUTE) {
            return $this->reject('Write throughput limit reached. Please wait a moment.', 429);
        }

        return null;
    }

    private function reject(string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'error' => $message,
        ], $status);
    }
}
