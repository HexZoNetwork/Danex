<?php

namespace Pterodactyl\Http\Middleware\Security;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Pterodactyl\Support\Security\PteroProtectClearanceToken;
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
        $trustedIp = $this->isTrustedIp($ip, $config);

        if ($this->isSuspiciousRequest($request, $config, $userAgent, $path, $queryString, $contentLength)) {
            $this->logDecision($config, 'deny', 'signature', $ip, $path, $userAgent);
            return $this->blockedResponse($request, 403, 'Blocked by PteroProtect WAF.');
        }

        if ($this->isLikelyHeadlessStealth($request, $userAgent, $category, $config)) {
            $this->logDecision($config, 'deny', 'headless-stealth', $ip, $path, $userAgent);
            return $this->blockedResponse($request, 403, 'Automated browser traffic is not allowed.');
        }

        if ($this->shouldBypassRequest($request, $category, $path)) {
            return $next($request);
        }

        // Trusted sources still pass signature checks above, but bypass anti-flood controls below.
        if ($trustedIp) {
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

        if ($this->isRateLimitedByFingerprintCluster($request, $category, $lockdown, $mode, $config, $decay)) {
            $this->logDecision($config, 'deny', "fingerprint-cluster:{$category}:{$mode}", $ip, $path, $userAgent);
            return $this->blockedResponse($request, 429, 'Too many similar automated requests detected.');
        }

        if ($this->isRateLimitedByClearance($request, $category, $lockdown, $mode, $config, $decay)) {
            $this->logDecision($config, 'deny', "clearance:{$category}:{$mode}", $ip, $path, $userAgent);
            return $this->blockedResponse($request, 429, 'Too many requests from this clearance session.');
        }

        return $next($request);
    }

    private function categoryForPath(string $path): string
    {
        if (preg_match('#^auth(?:/|$)#i', $path) === 1) {
            return 'auth';
        }

        if (preg_match('#^api/client/servers/[^/]+/websocket(?:/|$)#i', $path) === 1) {
            return 'websocket';
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
        if ($category !== 'api') {
            return false;
        }

        $ip = trim((string) $request->ip());
        $authHeader = trim((string) $request->header('Authorization', ''));

        if ($lockdown || $mode === 'emergency') {
            return false;
        }

        if (!($config['allow_header_bypass'] ?? false)) {
            return false;
        }

        // Never allow public clients to bypass API throttling only by presenting headers.
        if (!$this->isTrustedIp($ip, $config)) {
            return false;
        }

        if ($authHeader !== '' && preg_match('/^Bearer\s+\S+$/i', $authHeader) === 1) {
            return true;
        }

        return false;
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
            'websocket' => [
                (int) ($lockdown ? ($config['lockdown_websocket_per_ip_limit'] ?? 24) : ($config['websocket_per_ip_limit'] ?? 120)),
                (int) ($lockdown ? ($config['lockdown_websocket_global_limit'] ?? 160) : ($config['websocket_global_limit'] ?? 900)),
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

    private function isRateLimitedByClearance(
        Request $request,
        string $category,
        bool $lockdown,
        string $mode,
        array $config,
        int $decay
    ): bool {
        if ($category !== 'resource' && $category !== 'api') {
            return false;
        }

        $cookieName = trim((string) ($config['challenge_cookie_name'] ?? 'pp_clearance'));
        if ($cookieName === '') {
            $cookieName = 'pp_clearance';
        }

        $clearance = trim((string) $request->cookie($cookieName, ''));
        if ($clearance === '') {
            return false;
        }
        if (!PteroProtectClearanceToken::isValid($request, $clearance, $cookieName)) {
            return false;
        }

        $limit = $this->clearanceLimitForCategory($category, $lockdown, $mode, $config);
        if ($limit <= 0) {
            return false;
        }

        $ip = trim((string) $request->ip());
        $ua = strtolower((string) $request->userAgent());
        $keyData = hash('sha256', $category . '|' . $ip . '|' . $ua . '|' . $clearance);
        return $this->isRateLimited("pteroprotect:waf:clearance:{$category}:{$keyData}", $limit, $decay);
    }

    private function clearanceLimitForCategory(string $category, bool $lockdown, string $mode, array $config): int
    {
        $limit = match ($category) {
            'resource' => (int) ($lockdown
                ? ($config['lockdown_resource_clearance_limit'] ?? 6)
                : ($config['resource_clearance_limit'] ?? 14)),
            'api' => (int) ($lockdown
                ? ($config['lockdown_api_clearance_limit'] ?? 12)
                : ($config['api_clearance_limit'] ?? 40)),
            default => 0,
        };

        $multiplier = $this->modeMultiplier($mode, $config);
        return max(1, (int) ceil($limit * $multiplier));
    }

    private function isLikelyHeadlessStealth(Request $request, string $userAgent, string $category, array $config): bool
    {
        if ($category !== 'web' && $category !== 'api' && $category !== 'resource' && $category !== 'websocket') {
            return false;
        }

        if (!($config['block_headless_stealth'] ?? true)) {
            return false;
        }

        $score = 0;
        $ua = strtolower($userAgent);
        $secChUa = strtolower(trim((string) $request->header('sec-ch-ua', '')));
        $acceptLanguage = trim((string) $request->header('accept-language', ''));
        $accept = trim((string) $request->header('accept', ''));

        foreach (['headlesschrome', 'phantomjs', 'selenium', 'playwright', 'puppeteer', 'cypress'] as $needle) {
            if (str_contains($ua, $needle)) {
                $score += 3;
                break;
            }
        }

        if (str_contains($secChUa, 'headless')) {
            $score += 3;
        }

        $looksBrowser = str_contains($ua, 'mozilla/') || str_contains($ua, 'applewebkit/') || str_contains($ua, 'gecko/');
        if ($looksBrowser && $acceptLanguage === '') {
            $score += 1;
        }
        if ($looksBrowser && $accept === '') {
            $score += 1;
        }
        if ($secChUa !== '' && !$looksBrowser) {
            $score += 2;
        }

        return $score >= 3;
    }

    private function isRateLimitedByFingerprintCluster(
        Request $request,
        string $category,
        bool $lockdown,
        string $mode,
        array $config,
        int $decay
    ): bool {
        if ($category !== 'api' && $category !== 'resource' && $category !== 'websocket' && $category !== 'web') {
            return false;
        }

        if (!($config['fingerprint_cluster_limit_enabled'] ?? true)) {
            return false;
        }

        $limit = $this->fingerprintClusterLimitForCategory($category, $lockdown, $mode, $config);
        if ($limit <= 0) {
            return false;
        }

        $path = $this->normalizePathForFingerprint(ltrim((string) $request->path(), '/'));
        $ua = strtolower(trim((string) $request->userAgent()));
        $acceptLanguage = strtolower(trim((string) $request->header('accept-language', '')));
        $acceptEncoding = strtolower(trim((string) $request->header('accept-encoding', '')));
        $secChUa = strtolower(trim((string) $request->header('sec-ch-ua', '')));
        $secChUaPlatform = strtolower(trim((string) $request->header('sec-ch-ua-platform', '')));
        $secFetchSite = strtolower(trim((string) $request->header('sec-fetch-site', '')));
        $secFetchMode = strtolower(trim((string) $request->header('sec-fetch-mode', '')));
        $dnt = trim((string) $request->header('dnt', ''));
        $upgrade = trim((string) $request->header('upgrade-insecure-requests', ''));
        $method = strtoupper($request->method());

        $fingerprintData = implode('|', [
            $method,
            $path,
            $ua,
            $acceptLanguage,
            $acceptEncoding,
            $secChUa,
            $secChUaPlatform,
            $secFetchSite,
            $secFetchMode,
            $dnt,
            $upgrade,
        ]);
        $fp = hash('sha256', $fingerprintData);

        return $this->isRateLimited("pteroprotect:waf:fpcluster:{$category}:{$fp}", $limit, $decay);
    }

    private function fingerprintClusterLimitForCategory(string $category, bool $lockdown, string $mode, array $config): int
    {
        $limit = match ($category) {
            'resource' => (int) ($lockdown
                ? ($config['lockdown_resource_fingerprint_cluster_limit'] ?? 60)
                : ($config['resource_fingerprint_cluster_limit'] ?? 120)),
            'api' => (int) ($lockdown
                ? ($config['lockdown_api_fingerprint_cluster_limit'] ?? 80)
                : ($config['api_fingerprint_cluster_limit'] ?? 160)),
            'websocket' => (int) ($lockdown
                ? ($config['lockdown_websocket_fingerprint_cluster_limit'] ?? 120)
                : ($config['websocket_fingerprint_cluster_limit'] ?? 320)),
            'web' => (int) ($lockdown
                ? ($config['lockdown_web_fingerprint_cluster_limit'] ?? 90)
                : ($config['web_fingerprint_cluster_limit'] ?? 180)),
            default => 0,
        };

        $multiplier = $this->modeMultiplier($mode, $config);
        return max(1, (int) ceil($limit * $multiplier));
    }

    private function normalizePathForFingerprint(string $path): string
    {
        $norm = strtolower(trim($path));
        if ($norm === '') {
            return '/';
        }

        $norm = preg_replace('#[0-9a-f]{8,}#i', '{id}', $norm) ?? $norm;
        $norm = preg_replace('#\b\d{3,}\b#', '{n}', $norm) ?? $norm;

        return $norm;
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

        if (($config['block_client_ip_spoof_headers'] ?? true) && $this->hasClientIpSpoofHeaders($request)) {
            return true;
        }

        if (($config['block_malformed_host_header'] ?? true) && $this->hasMalformedHostHeader($request)) {
            return true;
        }

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

        if (($config['block_query_pipe_equals_pattern'] ?? true) && str_contains($queryString, '|=')) {
            return true;
        }

        $maxQueryPairs = (int) ($config['max_query_pairs'] ?? 30);
        if ($maxQueryPairs > 0 && $queryString !== '' && substr_count($queryString, '&') + 1 > $maxQueryPairs) {
            return true;
        }

        return $contentLength > (int) ($config['max_content_length'] ?? 1048576);
    }

    private function hasClientIpSpoofHeaders(Request $request): bool
    {
        $remoteAddr = trim((string) $request->server('REMOTE_ADDR', ''));
        $xffRaw = trim((string) $request->header('x-forwarded-for', ''));
        $xRealIp = trim((string) $request->header('x-real-ip', ''));
        $cfConnectingIp = trim((string) $request->header('cf-connecting-ip', ''));
        $trueClientIp = trim((string) $request->header('true-client-ip', ''));
        $xClientIp = trim((string) $request->header('x-client-ip', ''));
        $forwarded = trim((string) $request->header('forwarded', ''));

        if ($xffRaw === '' && $xRealIp === '' && $cfConnectingIp === '' && $trueClientIp === '' && $xClientIp === '' && $forwarded === '') {
            return false;
        }

        if ($xClientIp !== '' && filter_var($xClientIp, FILTER_VALIDATE_IP) === false) {
            return true;
        }

        // "Forwarded" header with multiple for= hops from direct clients is suspicious.
        if ($forwarded !== '' && preg_match('/for=.*,/i', $forwarded) === 1 && !$this->isProxySourceAddress($remoteAddr)) {
            return true;
        }

        $xffValues = [];
        if ($xffRaw !== '') {
            foreach (explode(',', $xffRaw) as $part) {
                $candidate = trim($part);
                if ($candidate === '') {
                    continue;
                }
                if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                    return true;
                }
                $xffValues[] = strtolower($candidate);
            }

            // Very long proxy chains are atypical for legitimate panel traffic.
            if (count($xffValues) > 6) {
                return true;
            }
        }

        $namedForwardedIps = [];
        foreach ([$xRealIp, $cfConnectingIp, $trueClientIp] as $value) {
            if ($value === '') {
                continue;
            }
            if (filter_var($value, FILTER_VALIDATE_IP) === false) {
                return true;
            }
            $namedForwardedIps[] = strtolower($value);
        }

        if (count(array_unique($namedForwardedIps)) > 1) {
            return true;
        }

        $primaryForwardedIp = $xffValues[0] ?? ($namedForwardedIps[0] ?? '');
        if ($primaryForwardedIp !== '' && !empty($namedForwardedIps) && $primaryForwardedIp !== $namedForwardedIps[0]) {
            return true;
        }

        // If request does not originate from a proxy edge and forwarding headers disagree
        // with REMOTE_ADDR, treat as spoof attempt.
        if ($primaryForwardedIp !== '' && $remoteAddr !== '' && filter_var($remoteAddr, FILTER_VALIDATE_IP) !== false) {
            if (!$this->isProxySourceAddress($remoteAddr) && strtolower($remoteAddr) !== $primaryForwardedIp) {
                return true;
            }
        }

        return false;
    }

    private function isProxySourceAddress(string $ip): bool
    {
        if ($ip === '127.0.0.1' || $ip === '::1' || $ip === '::ffff:127.0.0.1') {
            return true;
        }

        if ($ip === '') {
            return false;
        }

        return $this->isPrivateOrReservedIp($ip);
    }

    private function hasMalformedHostHeader(Request $request): bool
    {
        $host = trim((string) $request->header('Host', ''));
        if ($host === '') {
            return false;
        }

        if (str_contains($host, ',') || str_contains($host, ' ') || str_contains($host, "\t")) {
            return true;
        }

        if (str_ends_with($host, '.')) {
            return true;
        }

        // Reject duplicated dots and obvious host header corruption.
        return str_contains($host, '..');
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
