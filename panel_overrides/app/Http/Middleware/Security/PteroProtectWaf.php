<?php

namespace Pterodactyl\Http\Middleware\Security;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class PteroProtectWaf
{
    public function handle(Request $request, Closure $next): Response
    {
        $config = config('pteroprotect.waf', []);
        if (!($config['enabled'] ?? false)) {
            return $next($request);
        }

        $ip = trim((string) $request->ip());
        $path = ltrim($request->path(), '/');
        $userAgent = strtolower((string) $request->userAgent());
        $queryString = (string) $request->server('QUERY_STRING', '');
        $contentLength = (int) $request->server('CONTENT_LENGTH', 0);
        $lockdown = $this->isLockdownActive((string) ($config['lockdown_flag'] ?? ''));
        $mode = $this->currentMode((string) ($config['mode_flag'] ?? ''));
        $category = $this->categoryForPath($path);

        if ($this->isTrustedIp($ip, $config)) {
            return $next($request);
        }

        if ($this->isSuspiciousRequest($request, $config, $userAgent, $path, $queryString, $contentLength)) {
            $this->logDecision($config, 'deny', 'signature', $ip, $path, $userAgent);
            return $this->blockedResponse($request, 403, 'Blocked by PteroProtect WAF.');
        }

        if ($this->shouldBypassRequest($request, $category, $path)) {
            return $next($request);
        }

        if ($this->shouldBypassApiRateLimit($request, $category, $lockdown, $mode, $config)) {
            return $next($request);
        }

        $blockInEmergency = (bool) ($config['block_paths_in_emergency'] ?? false);
        if ($mode === 'emergency' && $blockInEmergency && $this->shouldBlockInEmergency($path, $config)) {
            $this->logDecision($config, 'deny', 'emergency-path', $ip, $path, $userAgent);
            return $this->blockedResponse($request, 429, 'Emergency protection mode is active.');
        }

        if ($lockdown && $this->shouldBlockDuringLockdown($path, $config)) {
            $this->logDecision($config, 'deny', 'lockdown-path', $ip, $path, $userAgent);
            return $this->blockedResponse($request, 429, 'Temporary protection mode is active.');
        }

        [$perIpLimit, $globalLimit, $decay] = $this->limitsForCategory($category, $lockdown, $mode, $config);

        if ($this->isRateLimited("pteroprotect:waf:ip:{$category}:{$ip}", $perIpLimit, $decay)) {
            $this->logDecision($config, 'deny', "per-ip:{$category}:{$mode}", $ip, $path, $userAgent);
            return $this->blockedResponse($request, 429, 'Too many requests.');
        }

        if ($this->isRateLimited("pteroprotect:waf:global:{$category}", $globalLimit, $decay)) {
            $this->logDecision($config, 'deny', "global:{$category}:{$mode}", $ip, $path, $userAgent);
            return $this->blockedResponse($request, 429, 'Server is under heavy load.');
        }

        return $next($request);
    }

    private function categoryForPath(string $path): string
    {
        if (preg_match('#^auth(?:/|$)#i', $path) === 1) {
            return 'auth';
        }

        if (preg_match('#^api/client/servers/[^/]+/(resources|websocket|files|network)(?:/|$)#i', $path) === 1) {
            return 'resource';
        }

        if (preg_match('#^api(?:/|$)#i', $path) === 1) {
            return 'api';
        }

        return 'web';
    }

    private function shouldBypassRequest(Request $request, string $category, string $path): bool
    {
        if ($this->isStaticAssetPath($path)) {
            return true;
        }

        if (preg_match('#^api/remote(?:/|$)#i', $path) === 1) {
            return true;
        }

        if (preg_match('#^api/client/servers/[^/]+/websocket(?:/|$)#i', $path) === 1) {
            return true;
        }

        return false;
    }

    private function isTrustedIp(string $ip, array $config): bool
    {
        if ($ip === '127.0.0.1' || $ip === '::1') {
            return true;
        }

        if ($ip === '') {
            return false;
        }

        if (($config['trust_private_ranges'] ?? false) && $this->isPrivateOrReservedIp($ip)) {
            return true;
        }

        $trusted = $config['trusted_ips'] ?? [];
        if (!is_array($trusted)) {
            return false;
        }

        $trusted = array_map(static fn ($value) => trim((string) $value), $trusted);
        foreach ($trusted as $entry) {
            if ($entry === '') {
                continue;
            }

            if ($entry === $ip || $this->ipInCidr($ip, $entry)) {
                return true;
            }
        }

        return false;
    }

    private function isStaticAssetPath(string $path): bool
    {
        return preg_match('#\.(?:css|js|mjs|map|png|jpe?g|gif|svg|ico|webp|woff2?|ttf|eot|txt|xml)$#i', $path) === 1;
    }

    private function shouldBypassApiRateLimit(Request $request, string $category, bool $lockdown, string $mode, array $config): bool
    {
        if ($category !== 'api' && $category !== 'resource') {
            return false;
        }

        $path = ltrim($request->path(), '/');
        $authHeader = trim((string) $request->header('Authorization', ''));

        if ($lockdown || $mode === 'emergency') {
            return false;
        }

        if (!($config['allow_header_bypass'] ?? false)) {
            return false;
        }

        if ($authHeader !== '' && preg_match('/^Bearer\s+\S+$/i', $authHeader) === 1) {
            return true;
        }

        return trim((string) $request->header('X-Api-Key', '')) !== '';
    }

    private function limitsForCategory(string $category, bool $lockdown, string $mode, array $config): array
    {
        $decay = (int) ($config['global_decay_seconds'] ?? 10);
        $limits = match ($category) {
            'auth' => [
                (int) ($config['auth_per_ip_limit'] ?? 8),
                (int) ($config['auth_global_limit'] ?? 24),
                60,
            ],
            'resource' => [
                (int) ($lockdown ? ($config['lockdown_resource_per_ip_limit'] ?? 3) : ($config['resource_per_ip_limit'] ?? 12)),
                (int) ($lockdown ? ($config['lockdown_resource_global_limit'] ?? 12) : ($config['resource_global_limit'] ?? 50)),
                $decay,
            ],
            'api' => [
                (int) ($lockdown ? ($config['lockdown_api_per_ip_limit'] ?? 8) : ($config['api_per_ip_limit'] ?? 30)),
                (int) ($lockdown ? ($config['lockdown_api_global_limit'] ?? 30) : ($config['api_global_limit'] ?? 140)),
                $decay,
            ],
            default => [
                (int) ($config['web_per_ip_limit'] ?? 60),
                (int) ($config['web_global_limit'] ?? 200),
                $decay,
            ],
        };

        $multiplier = $this->modeMultiplier($mode, $config);
        $limits[0] = max(1, (int) ceil($limits[0] * $multiplier));
        $limits[1] = max(1, (int) ceil($limits[1] * $multiplier));

        return $limits;
    }

    private function isRateLimited(string $key, int $limit, int $decay): bool
    {
        if ($limit <= 0) {
            return false;
        }

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return true;
        }

        RateLimiter::hit($key, $decay);

        return false;
    }

    private function isSuspiciousRequest(
        Request $request,
        array $config,
        string $userAgent,
        string $path,
        string $queryString,
        int $contentLength
    ): bool {
        $fullTarget = $path . ($queryString !== '' ? '?' . $queryString : '');

        if (($config['block_empty_agent_on_api'] ?? false) && $userAgent === '' && preg_match('#^api/(client|application)(?:/|$)#i', $path) === 1) {
            return true;
        }

        foreach (($config['suspicious_user_agents'] ?? []) as $needle) {
            if ($needle !== '' && str_contains($userAgent, strtolower((string) $needle))) {
                return true;
            }
        }

        foreach (($config['suspicious_path_patterns'] ?? []) as $pattern) {
            if (@preg_match($pattern, $fullTarget) === 1) {
                return true;
            }
        }

        if (strlen($queryString) > (int) ($config['max_query_length'] ?? 2048)) {
            return true;
        }

        return $contentLength > (int) ($config['max_content_length'] ?? 1048576);
    }

    private function shouldBlockDuringLockdown(string $path, array $config): bool
    {
        foreach (($config['strict_lockdown_block_patterns'] ?? []) as $pattern) {
            if (@preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    private function shouldBlockInEmergency(string $path, array $config): bool
    {
        foreach (($config['emergency_block_patterns'] ?? []) as $pattern) {
            if (@preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isLockdownActive(string $flagFile): bool
    {
        if ($flagFile === '' || !is_file($flagFile)) {
            return false;
        }

        $raw = @file_get_contents($flagFile);
        if (!is_string($raw) || $raw === '') {
            return false;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return false;
        }

        $until = (int) ($data['until'] ?? 0);
        if ($until < time()) {
            @unlink($flagFile);
            return false;
        }

        return true;
    }

    private function currentMode(string $modeFile): string
    {
        if ($modeFile === '' || !is_file($modeFile)) {
            return 'normal';
        }

        $raw = @file_get_contents($modeFile);
        if (!is_string($raw) || $raw === '') {
            return 'normal';
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return 'normal';
        }

        $mode = strtolower((string) ($data['mode'] ?? 'normal'));

        return in_array($mode, ['normal', 'aggressive', 'emergency'], true) ? $mode : 'normal';
    }

    private function modeMultiplier(string $mode, array $config): float
    {
        $multipliers = $config['mode_multipliers'] ?? [];
        $value = $multipliers[$mode] ?? 1.0;

        return max(0.1, (float) $value);
    }

    private function blockedResponse(Request $request, int $status, string $message): Response
    {
        if ($request->expectsJson() || str_starts_with($request->path(), 'api/')) {
            return new JsonResponse(['error' => $message], $status);
        }

        return response($message, $status);
    }

    private function logDecision(array $config, string $action, string $reason, string $ip, string $path, string $userAgent): void
    {
        $file = (string) ($config['log_file'] ?? '');
        if ($file === '') {
            return;
        }

        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $line = sprintf(
            "[%s] action=%s reason=%s ip=%s path=%s ua=%s\n",
            gmdate('Y-m-d H:i:s'),
            $action,
            $reason,
            $ip,
            $path,
            substr($userAgent, 0, 180)
        );

        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    private function isPrivateOrReservedIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return false;
        }

        [$network, $prefixRaw] = explode('/', $cidr, 2);
        $network = trim($network);
        $prefix = (int) trim($prefixRaw);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false &&
            filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            if ($prefix < 0 || $prefix > 32) {
                return false;
            }

            $ipLong = ip2long($ip);
            $netLong = ip2long($network);
            if ($ipLong === false || $netLong === false) {
                return false;
            }

            $mask = $prefix === 0 ? 0 : (-1 << (32 - $prefix));
            return (($ipLong & $mask) === ($netLong & $mask));
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false &&
            filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            if ($prefix < 0 || $prefix > 128) {
                return false;
            }

            $ipBin = inet_pton($ip);
            $networkBin = inet_pton($network);
            if ($ipBin === false || $networkBin === false) {
                return false;
            }

            $bytes = intdiv($prefix, 8);
            $bits = $prefix % 8;

            if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($networkBin, 0, $bytes)) {
                return false;
            }

            if ($bits === 0) {
                return true;
            }

            $mask = ((0xFF << (8 - $bits)) & 0xFF);
            return ((ord($ipBin[$bytes]) & $mask) === (ord($networkBin[$bytes]) & $mask));
        }

        return false;
    }
}
