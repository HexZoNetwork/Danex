<?php

namespace Pterodactyl\Http\Middleware\Security;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpFoundation\Response;

class PteroProtectNodeFileShield
{
    /**
     * @var array<string,int>
     */
    private const PER_MINUTE_LIMITS = [
        'list' => 180,
        'contents' => 180,
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

    private const DEFAULT_LIMIT = 60;
    private const WINDOW_SECONDS = 60;
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
        $bucket = (int) floor(time() / self::WINDOW_SECONDS);
        $key = sprintf('pp:nodefs:rate:u%d:s%s:a%s:w%d', $userId, $serverKey, $action, $bucket);

        $current = (int) Cache::get($key, 0);
        if ($current >= $limit) {
            return $this->reject('File operation is temporarily rate-limited. Please slow down.', 429);
        }

        if (!Cache::has($key)) {
            Cache::put($key, 1, now()->addSeconds(self::WINDOW_SECONDS + 2));
        } else {
            Cache::increment($key);
        }

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
        $used = (int) Cache::get($key, 0);
        if ($used + $bytes > self::MAX_WRITE_BYTES_PER_MINUTE) {
            return $this->reject('Write throughput limit reached. Please wait a moment.', 429);
        }

        if (!Cache::has($key)) {
            Cache::put($key, $bytes, now()->addSeconds(self::WINDOW_SECONDS + 2));
        } else {
            Cache::increment($key, $bytes);
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
