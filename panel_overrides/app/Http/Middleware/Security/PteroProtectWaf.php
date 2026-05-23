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

        if ($this->isRemoteApiPath($path) || $this->isApplicationApiPath($path)) {
            return $next($request);
        }

        $userAgent = strtolower((string) $request->userAgent());
        $queryString = (string) $request->server('QUERY_STRING', '');
        $contentLength = (int) $request->server('CONTENT_LENGTH', 0);
        $lockdown = $this->isLockdownActive((string) ($config['lockdown_flag'] ?? ''));
        $mode = $this->currentMode((string) ($config['mode_flag'] ?? ''));
        $category = $this->categoryForPath($path);
        $trustedIp = $this->isTrustedIp($ip, $config);
        $resilienceConfig = config('pteroprotect.resilience', []);
        $resilienceState = $this->loadResilienceState($resilienceConfig);
        $stage = strtolower((string) ($resilienceState['stage'] ?? 'normal'));
        $danexcStatusPath = $this->isDanexcStatusPath($path);
        $danexcSoftAllow = $this->shouldSoftAllowDanexcStatus($danexcStatusPath, $stage, $lockdown, $mode, $config);
        $rumPath = $this->isRumPath($path);
        $featureFlags = is_array($resilienceState['features'] ?? null) ? $resilienceState['features'] : [];
        $governorBudgets = is_array($resilienceState['resource_governor']['budgets'] ?? null)
            ? $resilienceState['resource_governor']['budgets']
            : [];
        $replayConfig = is_array($resilienceState['replay'] ?? null) ? $resilienceState['replay'] : [];
        $circuits = is_array($resilienceState['circuit_breakers'] ?? null) ? $resilienceState['circuit_breakers'] : [];
        $poisonConfig = $this->loadPoisonFingerprintConfig($resilienceConfig);

        if ($this->isSuspiciousRequest($request, $config, $userAgent, $path, $queryString, $contentLength)) {
            $this->logDecision($config, 'deny', 'signature', $ip, $path, $userAgent);
            $this->logResilienceEvent($resilienceConfig, 'l7', 'waf', 'deny_signature', 0.99, 0.95, 'global', ['path' => $path, 'ip' => $ip]);
            return $this->blockedResponse($request, 403, 'Blocked by PteroProtect WAF.');
        }

        if ($this->isLikelyHeadlessStealth($request, $userAgent, $category, $config)) {
            $this->logDecision($config, 'deny', 'headless-stealth', $ip, $path, $userAgent);
            $this->logResilienceEvent($resilienceConfig, 'l7', 'waf', 'deny_headless', 0.9, 0.9, 'global', ['path' => $path, 'ip' => $ip]);
            return $this->blockedResponse($request, 403, 'Automated browser traffic is not allowed.');
        }

        if (!$danexcSoftAllow && $this->isHardDroppedByPoisonFingerprint($request, $path, $poisonConfig)) {
            $this->logDecision($config, 'deny', 'poison-fingerprint', $ip, $path, $userAgent);
            $this->logResilienceEvent($resilienceConfig, 'l7', 'waf', 'deny_poison_hard_drop', 1.0, 0.98, 'global', ['path' => $path, 'ip' => $ip]);
            return $this->blockedResponse($request, 429, 'Request pattern temporarily quarantined.');
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

        if ($this->shouldApplyWebFloodGuard($request, $path, $category, $mode, $stage, $lockdown, $config) && $this->isRateLimitedWebFlood($request, $path, $mode, $config)) {
            $this->logDecision($config, 'deny', "web-flood:{$mode}", $ip, $path, $userAgent);
            $this->logResilienceEvent(
                $resilienceConfig,
                'l7',
                'waf',
                'deny_web_flood',
                0.96,
                0.92,
                'global',
                ['path' => $path, 'mode' => $mode, 'stage' => $stage]
            );

            return $this->blockedResponse($request, 429, 'Traffic spike protection is active.');
        }

        if ($this->shouldCapRumDuringAttack($rumPath, $mode, $stage, $lockdown, $config) && $this->isRateLimitedRumDuringAttack($request, $mode, $config)) {
            $this->logDecision($config, 'deny', "rum-attack-cap:{$mode}", $ip, $path, $userAgent);
            $this->logResilienceEvent(
                $resilienceConfig,
                'app',
                'waf',
                'deny_rum_attack_cap',
                0.9,
                0.9,
                'global',
                ['path' => $path, 'mode' => $mode, 'stage' => $stage]
            );

            return $this->blockedResponse($request, 429, 'Telemetry traffic is temporarily limited.');
        }

        if (!$danexcSoftAllow && !$rumPath && $this->shouldShedByFeature($path, $featureFlags, $stage) && !$this->isCoreRoute($request, $path)) {
            if ($this->shouldQueueReplay($request, $path, $stage, $replayConfig)) {
                $ticket = $this->queueReplayTicket($request, $path, $replayConfig, $resilienceConfig);
                $this->logResilienceEvent(
                    $resilienceConfig,
                    'app',
                    'waf',
                    'replay_queued',
                    0.7,
                    0.8,
                    'global',
                    ['path' => $path, 'ticket_id' => $ticket]
                );

                if ($request->expectsJson() || str_starts_with($request->path(), 'api/')) {
                    return new JsonResponse([
                        'error' => 'Request deferred while service is recovering.',
                        'replay_ticket' => $ticket,
                        'stage' => $stage,
                    ], 202);
                }

                return response('Request deferred while service is recovering.', 202);
            }

            $this->logDecision($config, 'deny', 'feature-shed', $ip, $path, $userAgent);
            $this->logResilienceEvent(
                $resilienceConfig,
                'app',
                'waf',
                'feature_shed',
                0.85,
                0.8,
                'global',
                ['path' => $path, 'stage' => $stage]
            );

            return $this->blockedResponse($request, 503, 'Service is temporarily degraded while recovering.');
        }

        if (!$danexcSoftAllow && $this->shouldDegradeFromCircuit($request, $path, $circuits, $stage) && !$this->isCoreRoute($request, $path)) {
            $this->logDecision($config, 'deny', 'dependency-circuit', $ip, $path, $userAgent);
            $this->logResilienceEvent(
                $resilienceConfig,
                'app',
                'waf',
                'dependency_degraded',
                0.8,
                0.75,
                'global',
                ['path' => $path, 'stage' => $stage]
            );

            return $this->blockedResponse($request, 503, 'Dependent service is recovering. Try again shortly.');
        }

        $blockInEmergency = (bool) ($config['block_paths_in_emergency'] ?? false);
        if ($mode === 'emergency' && $blockInEmergency && $this->shouldBlockInEmergency($path, $config)) {
            $this->logDecision($config, 'deny', 'emergency-path', $ip, $path, $userAgent);
            $this->logResilienceEvent($resilienceConfig, 'l7', 'waf', 'deny_emergency_path', 0.9, 0.8, 'global', ['path' => $path, 'ip' => $ip]);
            return $this->blockedResponse($request, 429, 'Emergency protection mode is active.');
        }

        if ($lockdown && $this->shouldBlockDuringLockdown($path, $config)) {
            $this->logDecision($config, 'deny', 'lockdown-path', $ip, $path, $userAgent);
            $this->logResilienceEvent($resilienceConfig, 'l7', 'waf', 'deny_lockdown_path', 0.85, 0.8, 'global', ['path' => $path, 'ip' => $ip]);
            return $this->blockedResponse($request, 429, 'Temporary protection mode is active.');
        }

        if ($danexcSoftAllow) {
            if ($this->isRateLimitedDanexcStatus($request, $mode, $config)) {
                $this->logDecision($config, 'deny', "danexc-status-soft-cap:{$mode}", $ip, $path, $userAgent);
                return $this->blockedResponse($request, 429, 'Too many status polling requests.');
            }

            return $next($request);
        }

        [$perIpLimit, $globalLimit, $decay] = $this->limitsForCategory($category, $lockdown, $mode, $config);

        if ($this->isRateLimitedByAdaptiveBudget($request, $category, $governorBudgets)) {
            $this->logDecision($config, 'deny', "adaptive-budget:{$category}:{$stage}", $ip, $path, $userAgent);
            $this->logResilienceEvent(
                $resilienceConfig,
                'app',
                'waf',
                'adaptive_budget_drop',
                0.75,
                0.7,
                'global',
                ['path' => $path, 'category' => $category, 'stage' => $stage]
            );
            return $this->blockedResponse($request, 429, 'Server is limiting non-critical concurrency.');
        }

        if ($this->isRateLimitedBySubject($request, $category, $lockdown, $mode, $config, $decay)) {
            $this->logDecision($config, 'deny', "subject:{$category}:{$mode}", $ip, $path, $userAgent);
            return $this->blockedResponse($request, 429, 'Too many requests from this session.');
        }

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

    private function loadResilienceState(array $resilienceConfig): array
    {
        $stateFile = trim((string) ($resilienceConfig['state_file'] ?? '/pteroprotect/runtime/resilience_state.json'));
        if ($stateFile === '' || !is_file($stateFile)) {
            return [];
        }

        $raw = @file_get_contents($stateFile);
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function loadPoisonFingerprintConfig(array $resilienceConfig): array
    {
        $file = trim((string) ($resilienceConfig['poison_file'] ?? '/pteroprotect/runtime/poison_fingerprints.json'));
        if ($file === '' || !is_file($file)) {
            return [];
        }

        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function isHardDroppedByPoisonFingerprint(Request $request, string $path, array $poisonMap): bool
    {
        if ($this->isPoisonHardDropBypassPath($request, $path)) {
            return false;
        }

        if (empty($poisonMap)) {
            return false;
        }

        $method = strtoupper($request->method());
        $uaFamily = $this->uaFamily((string) $request->userAgent());
        if ($uaFamily === 'browser') {
            return false;
        }

        $prefix = sprintf('%s|%s|%s|', $method, $this->normalizePath('/' . $path), $uaFamily);

        foreach ($poisonMap as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $signature = (string) ($entry['signature'] ?? '');
            if ($signature === '' || !str_starts_with($signature, $prefix)) {
                continue;
            }

            $expiresAt = (int) ($entry['expires_at'] ?? 0);
            if ($expiresAt > 0 && $expiresAt < time()) {
                continue;
            }

            if (strtolower((string) ($entry['action'] ?? '')) === 'hard_drop') {
                return true;
            }
        }

        return false;
    }

    private function isPoisonHardDropBypassPath(Request $request, string $path): bool
    {
        $method = strtoupper($request->method());
        if ($method !== 'GET') {
            return false;
        }

        // Poison fingerprints are learned from recent error traffic. Never let a
        // transient incident quarantine normal browser navigation routes.
        if ($this->isCoreBrowserNavigationPath($path)) {
            return true;
        }

        // Server resource polling is first-party panel traffic and can be
        // emitted by axios/fetch without a browser-looking User-Agent. If a
        // transient upstream failure teaches poison fingerprints here, innocent
        // sessions lose the console even after services recover.
        if (preg_match('#^api/client/servers/[^/]+/(resources|activity|websocket)(?:/|$)#i', $path) === 1) {
            return true;
        }

        // DanexC dashboard telemetry endpoints are high-volume read-only requests
        // from legitimate browser sessions and prone to poisoning false positives.
        return $this->isDanexcStatusPath($path);
    }

    private function isDanexcStatusPath(string $path): bool
    {
        return preg_match('#^api/client/danexc/(overview|timeline|feed)(?:\d+)?(?:/|$)#i', $path) === 1;
    }

    private function isRumPath(string $path): bool
    {
        return preg_match('#^api/client/rum(?:/|$)#i', $path) === 1;
    }

    private function shouldSoftAllowDanexcStatus(bool $isDanexcStatusPath, string $stage, bool $lockdown, string $mode, array $config): bool
    {
        if (!$isDanexcStatusPath) {
            return false;
        }

        if (!($config['danexc_status_soft_allow_enabled'] ?? true)) {
            return false;
        }

        if ($lockdown || $mode === 'emergency') {
            return false;
        }

        return $stage === 'normal' || $stage === 'elevated';
    }

    private function isRateLimitedDanexcStatus(Request $request, string $mode, array $config): bool
    {
        $decay = max(1, (int) ($config['danexc_status_decay_seconds'] ?? ($config['global_decay_seconds'] ?? 10)));
        $perIpLimit = max(1, (int) ($config['danexc_status_soft_limit_per_ip'] ?? 240));
        $perSessionLimit = max(1, (int) ($config['danexc_status_soft_limit_per_session'] ?? 480));
        $multiplier = $this->modeMultiplier($mode, $config);
        $perIpLimit = max(1, (int) ceil($perIpLimit * $multiplier));
        $perSessionLimit = max(1, (int) ceil($perSessionLimit * $multiplier));

        $ip = trim((string) $request->ip());
        if ($ip === '') {
            $ip = 'unknown';
        }
        if ($this->isRateLimited("pteroprotect:waf:danexc-status:ip:{$ip}", $perIpLimit, $decay)) {
            return true;
        }

        $subject = (string) ($request->user()?->id ?? '');
        if (trim($subject) === '') {
            $subject = trim((string) $request->cookie((string) ($config['challenge_cookie_name'] ?? 'pp_clearance'), ''));
        }
        if (trim($subject) === '') {
            $subject = hash('sha256', strtolower(trim((string) $request->userAgent())));
        }

        return $this->isRateLimited("pteroprotect:waf:danexc-status:subject:{$subject}", $perSessionLimit, $decay);
    }

    private function shouldApplyWebFloodGuard(Request $request, string $path, string $category, string $mode, string $stage, bool $lockdown, array $config): bool
    {
        if (!($config['web_flood_guard_enabled'] ?? true)) {
            return false;
        }

        if ($this->isDanexcPagePath($path)) {
            return false;
        }

        if ($lockdown) {
            return true;
        }

        if (!in_array($mode, ['aggressive', 'emergency'], true) && !in_array($stage, ['constrained', 'emergency'], true)) {
            return false;
        }

        if ($category !== 'web') {
            return false;
        }

        return strtoupper($request->method()) === 'GET';
    }

    private function isDanexcPagePath(string $path): bool
    {
        return preg_match('#^danexc(?:/|$)#i', trim($path, '/')) === 1;
    }

    private function isRateLimitedWebFlood(Request $request, string $path, string $mode, array $config): bool
    {
        $decay = max(1, (int) ($config['web_flood_decay_seconds'] ?? 5));
        $isRoot = trim($path, '/') === '' || strtolower(trim($path, '/')) === 'index.php';
        $perIpLimit = $isRoot
            ? max(1, (int) ($config['web_flood_root_per_ip_limit'] ?? 10))
            : max(1, (int) ($config['web_flood_per_ip_limit'] ?? 20));
        $globalLimit = $isRoot
            ? max(1, (int) ($config['web_flood_root_global_limit'] ?? 120))
            : max(1, (int) ($config['web_flood_global_limit'] ?? 240));

        $multiplier = $this->modeMultiplier($mode, $config);
        $perIpLimit = max(1, (int) ceil($perIpLimit * $multiplier));
        $globalLimit = max(1, (int) ceil($globalLimit * $multiplier));

        $ip = trim((string) $request->ip());
        if ($ip === '') {
            $ip = 'unknown';
        }
        $bucket = $isRoot ? 'root' : 'web';
        if ($this->isRateLimited("pteroprotect:waf:web-flood:ip:{$bucket}:{$ip}", $perIpLimit, $decay)) {
            return true;
        }

        return $this->isRateLimited("pteroprotect:waf:web-flood:global:{$bucket}", $globalLimit, $decay);
    }

    private function shouldCapRumDuringAttack(bool $rumPath, string $mode, string $stage, bool $lockdown, array $config): bool
    {
        if (!$rumPath || !($config['rum_attack_cap_enabled'] ?? true)) {
            return false;
        }

        if ($lockdown) {
            return true;
        }

        return in_array($mode, ['aggressive', 'emergency'], true) || in_array($stage, ['constrained', 'emergency'], true);
    }

    private function isRateLimitedRumDuringAttack(Request $request, string $mode, array $config): bool
    {
        $decay = max(1, (int) ($config['rum_attack_decay_seconds'] ?? 5));
        $perIpLimit = max(1, (int) ($config['rum_attack_per_ip_limit'] ?? 4));
        $globalLimit = max(1, (int) ($config['rum_attack_global_limit'] ?? 48));
        $multiplier = $this->modeMultiplier($mode, $config);
        $perIpLimit = max(1, (int) ceil($perIpLimit * $multiplier));
        $globalLimit = max(1, (int) ceil($globalLimit * $multiplier));

        $ip = trim((string) $request->ip());
        if ($ip === '') {
            $ip = 'unknown';
        }
        if ($this->isRateLimited("pteroprotect:waf:rum-attack:ip:{$ip}", $perIpLimit, $decay)) {
            return true;
        }

        return $this->isRateLimited("pteroprotect:waf:rum-attack:global", $globalLimit, $decay);
    }

    private function isCoreBrowserNavigationPath(string $path): bool
    {
        $normalized = trim($path, '/');
        if ($normalized === '') {
            return true;
        }

        if (preg_match('#^(auth|login|register|account|admin|server|servers|dashboard|danexc|danexcoin)(?:/|$)#i', $normalized) === 1) {
            return true;
        }

        // Pterodactyl SPA routes are commonly extensionless. Static assets and
        // API paths keep their dedicated checks.
        if (!str_starts_with(strtolower($normalized), 'api/') && !str_contains(basename($normalized), '.')) {
            return true;
        }

        return false;
    }

    private function uaFamily(string $ua): string
    {
        $value = strtolower(trim($ua));
        if ($value === '') {
            return 'empty';
        }

        foreach (['headless', 'curl', 'python', 'wget', 'bot', 'spider', 'scanner', 'sqlmap'] as $needle) {
            if (str_contains($value, $needle)) {
                return 'automation';
            }
        }

        if (str_contains($value, 'mozilla') || str_contains($value, 'chrome') || str_contains($value, 'safari') || str_contains($value, 'firefox')) {
            return 'browser';
        }

        return 'other';
    }

    private function normalizePath(string $path): string
    {
        $norm = strtolower(trim($path));
        $norm = preg_replace('#/[0-9a-f]{8,}(?=/|$)#', '/{id}', $norm) ?? $norm;
        $norm = preg_replace('#/\d{2,}(?=/|$)#', '/{n}', $norm) ?? $norm;
        return $norm;
    }

    private function shouldShedByFeature(string $path, array $featureFlags, string $stage): bool
    {
        if ($stage === 'normal') {
            return false;
        }

        $check = [
            'chat' => '#^api/client/chat(?:/|$)#i',
            'ads' => '#^api/client/ads(?:/|$)#i',
            'create_panel' => '#^api/client/create-panel(?:/|$)#i',
            'heavy_files' => '#^api/client/servers/[^/]+/(files|backups)(?:/|$)#i',
            'noncritical_api' => '#^api/client/(?:rum|danexcoin)(?:/|$)#i',
            'websocket' => '#^api/client/servers/[^/]+/websocket(?:/|$)#i',
            'polling' => '#^api/client/servers/[^/]+/(resources|activity)(?:/|$)#i',
        ];

        foreach ($check as $flag => $pattern) {
            if (($featureFlags[$flag] ?? false) && preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isCoreRoute(Request $request, string $path): bool
    {
        if (preg_match('#^auth/(login|logout|password|register)(?:/|$)#i', $path) === 1) {
            return true;
        }

        if ($this->isRemoteApiPath($path)) {
            return true;
        }

        if (preg_match('#^api/client/servers/[^/]+/(power|command)(?:/|$)#i', $path) === 1) {
            return true;
        }

        // State-changing core auth/profile operations should remain available when possible.
        return strtoupper($request->method()) !== 'GET'
            && preg_match('#^api/client/account/(password|profile|email)(?:/|$)#i', $path) === 1;
    }

    private function shouldDegradeFromCircuit(Request $request, string $path, array $circuits, string $stage): bool
    {
        if ($stage === 'normal') {
            return false;
        }

        $deps = [];
        if (preg_match('#^auth(?:/|$)#i', $path) === 1 || preg_match('#^api(?:/|$)#i', $path) === 1) {
            $deps[] = 'db';
        }
        if (preg_match('#^api/client/chat(?:/|$)#i', $path) === 1 || preg_match('#^api/client/servers/[^/]+/websocket(?:/|$)#i', $path) === 1) {
            $deps[] = 'redis';
        }
        if (preg_match('#^api/client/servers/[^/]+/(resources|websocket|files|backups|network|power|command)(?:/|$)#i', $path) === 1) {
            $deps[] = 'wings';
        }
        if (empty($deps)) {
            return false;
        }

        foreach (array_unique($deps) as $dep) {
            $entry = $circuits[$dep] ?? null;
            if (!is_array($entry)) {
                continue;
            }
            $state = strtolower((string) ($entry['state'] ?? 'closed'));
            if ($state === 'open') {
                return true;
            }
            if ($state === 'half_open' && strtoupper($request->method()) !== 'GET') {
                return true;
            }
        }

        return false;
    }

    private function isRateLimitedByAdaptiveBudget(Request $request, string $category, array $budgets): bool
    {
        if (empty($budgets)) {
            return false;
        }

        $budget = (int) ($budgets[$category] ?? 0);
        if ($budget <= 0) {
            return false;
        }

        $ip = trim((string) $request->ip());
        $key = sprintf('pteroprotect:waf:adaptive:%s:%s', $category, $ip);
        $decay = 1;
        if (RateLimiter::tooManyAttempts($key, $budget)) {
            return true;
        }

        RateLimiter::hit($key, $decay);
        return false;
    }

    private function shouldQueueReplay(Request $request, string $path, string $stage, array $replayConfig): bool
    {
        if ($stage !== 'constrained' && $stage !== 'emergency') {
            return false;
        }

        if (!($replayConfig['enabled'] ?? false)) {
            return false;
        }

        $method = strtoupper($request->method());
        if ($method === 'GET') {
            return true;
        }

        if (!in_array($method, ['POST'], true)) {
            return false;
        }

        $allowPostPaths = $replayConfig['allowed_post_paths'] ?? [];
        if (!is_array($allowPostPaths)) {
            return false;
        }

        foreach ($allowPostPaths as $allowed) {
            $candidate = trim((string) $allowed);
            if ($candidate !== '' && str_starts_with('/' . ltrim($path, '/'), $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function queueReplayTicket(Request $request, string $path, array $replayConfig, array $resilienceConfig): string
    {
        $file = trim((string) ($resilienceConfig['replay_queue_file'] ?? '/pteroprotect/runtime/replay_queue.jsonl'));
        $maxQueue = max(100, (int) ($replayConfig['max_queue'] ?? 2000));
        $ttlSec = max(30, (int) ($replayConfig['ttl_sec'] ?? 600));
        $secret = (string) ($replayConfig['hmac_secret'] ?? '');
        if ($secret === '') {
            $secret = substr(hash('sha256', (string) config('app.key', 'fallback')), 0, 32);
        }

        $now = time();
        $ticketId = bin2hex(random_bytes(12));
        $subject = trim((string) $request->user()?->id);
        if ($subject === '') {
            $subject = trim((string) $request->ip());
        }

        $method = strtoupper($request->method());
        $payloadHash = hash('sha256', json_encode($request->all(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        $sig = hash_hmac('sha256', implode('|', [$ticketId, $subject, $method, $path, (string) $now]), $secret);

        $record = [
            'ticket_id' => $ticketId,
            'subject' => $subject,
            'method' => $method,
            'path' => '/' . ltrim($path, '/'),
            'payload_hash' => $payloadHash,
            'queued_at' => $now,
            'expires_at' => $now + $ttlSec,
            'signature' => $sig,
        ];

        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        @file_put_contents($file, json_encode($record, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
        $this->trimReplayQueue($file, $maxQueue);

        return $ticketId;
    }

    private function trimReplayQueue(string $file, int $maxQueue): void
    {
        $raw = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($raw)) {
            return;
        }

        if (count($raw) <= $maxQueue) {
            return;
        }

        $slice = array_slice($raw, -1 * $maxQueue);
        @file_put_contents($file, implode("\n", $slice) . "\n", LOCK_EX);
    }

    private function logResilienceEvent(
        array $resilienceConfig,
        string $layer,
        string $service,
        string $decision,
        float $score,
        float $confidence,
        string $tenantScope,
        array $extra = []
    ): void {
        $file = trim((string) ($resilienceConfig['events_file'] ?? '/pteroprotect/runtime/resilience_events.jsonl'));
        if ($file === '') {
            return;
        }

        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $payload = array_merge([
            'ts' => time(),
            'layer' => $layer,
            'service' => $service,
            'decision' => $decision,
            'score' => max(0.0, min(1.0, $score)),
            'confidence' => max(0.0, min(1.0, $confidence)),
            'tenant_scope' => $tenantScope,
            'expiry' => time() + 120,
        ], $extra);

        @file_put_contents($file, json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
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

        if ($this->isRemoteApiPath($path)) {
            return true;
        }

        if ($this->isApplicationApiPath($path)) {
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
        if ($path === 'assets/manifest.json' || $path === 'favicons/manifest.json') {
            return true;
        }

        return preg_match('#\.(?:css|js|mjs|map|png|jpe?g|gif|svg|ico|webp|woff2?|ttf|eot|txt|xml)$#i', $path) === 1;
    }

    private function isRemoteApiPath(string $path): bool
    {
        return preg_match('#^api/remote(?:/|$)#i', $path) === 1;
    }

    private function isApplicationApiPath(string $path): bool
    {
        return preg_match('#^api/application(?:/|$)#i', $path) === 1;
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

    private function isRateLimitedBySubject(
        Request $request,
        string $category,
        bool $lockdown,
        string $mode,
        array $config,
        int $decay
    ): bool {
        if (!($config['subject_limit_enabled'] ?? true)) {
            return false;
        }

        $subject = $this->subjectRateKey($request, $config);
        if ($subject === '') {
            return false;
        }

        $limit = $this->subjectLimitForCategory($category, $lockdown, $mode, $config);
        if ($limit <= 0) {
            return false;
        }

        return $this->isRateLimited("pteroprotect:waf:subject:{$category}:{$subject}", $limit, $decay);
    }

    private function subjectRateKey(Request $request, array $config): string
    {
        $userId = trim((string) ($request->user()?->id ?? ''));
        if ($userId !== '') {
            return 'u:' . hash('sha256', $userId);
        }

        $cookieName = trim((string) ($config['challenge_cookie_name'] ?? 'pp_clearance')) ?: 'pp_clearance';
        $clearance = trim((string) $request->cookie($cookieName, ''));
        if ($clearance !== '' && PteroProtectClearanceToken::isValid($request, $clearance, $cookieName)) {
            return 'c:' . hash('sha256', $clearance);
        }

        $sessionCookie = trim((string) $request->cookie(config('session.cookie', 'laravel_session'), ''));
        if ($sessionCookie !== '') {
            return 's:' . hash('sha256', $sessionCookie);
        }

        return '';
    }

    private function subjectLimitForCategory(string $category, bool $lockdown, string $mode, array $config): int
    {
        $limit = match ($category) {
            'resource' => (int) ($lockdown
                ? ($config['lockdown_resource_subject_limit'] ?? 180)
                : ($config['resource_subject_limit'] ?? 900)),
            'websocket' => (int) ($lockdown
                ? ($config['lockdown_websocket_subject_limit'] ?? 70)
                : ($config['websocket_subject_limit'] ?? 300)),
            'api' => (int) ($lockdown
                ? ($config['lockdown_api_subject_limit'] ?? 35)
                : ($config['api_subject_limit'] ?? 120)),
            'web' => (int) ($config['web_subject_limit'] ?? 180),
            default => 0,
        };

        $multiplier = $this->modeMultiplier($mode, $config);
        return max(1, (int) ceil($limit * $multiplier));
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

        if (!$this->isAllowedMethod($request, $config)) {
            return true;
        }

        if (($config['block_client_ip_spoof_headers'] ?? true) && $this->hasClientIpSpoofHeaders($request, $config)) {
            return true;
        }

        if ($this->hasExcessiveHeaders($request, $config) || $this->hasExcessiveCookies($request, $config)) {
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

    private function isAllowedMethod(Request $request, array $config): bool
    {
        $allowed = $config['allowed_methods'] ?? ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
        if (!is_array($allowed)) {
            return true;
        }

        $method = strtoupper($request->method());
        foreach ($allowed as $candidate) {
            if ($method === strtoupper(trim((string) $candidate))) {
                return true;
            }
        }

        return false;
    }

    private function hasExcessiveHeaders(Request $request, array $config): bool
    {
        $maxCount = max(1, (int) ($config['max_header_count'] ?? 80));
        $maxNameLength = max(16, (int) ($config['max_header_name_length'] ?? 80));
        $maxValueLength = max(256, (int) ($config['max_header_value_length'] ?? 8192));
        $headers = $request->headers->all();
        if (count($headers) > $maxCount) {
            return true;
        }

        foreach ($headers as $name => $values) {
            if (strlen((string) $name) > $maxNameLength) {
                return true;
            }
            foreach ((array) $values as $value) {
                if (strlen((string) $value) > $maxValueLength) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasExcessiveCookies(Request $request, array $config): bool
    {
        $cookies = $request->cookies->all();
        if (count($cookies) > max(1, (int) ($config['max_cookie_count'] ?? 40))) {
            return true;
        }

        $rawCookie = trim((string) $request->headers->get('cookie', ''));
        return $rawCookie !== '' && strlen($rawCookie) > max(512, (int) ($config['max_cookie_bytes'] ?? 8192));
    }

    private function hasClientIpSpoofHeaders(Request $request, array $config): bool
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
        if ($forwarded !== '' && preg_match('/for=.*,/i', $forwarded) === 1 && !$this->isProxySourceAddress($remoteAddr, $config)) {
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
            if (!$this->isProxySourceAddress($remoteAddr, $config) && strtolower($remoteAddr) !== $primaryForwardedIp) {
                return true;
            }
        }

        return false;
    }

    private function isProxySourceAddress(string $ip, array $config = []): bool
    {
        if ($ip === '127.0.0.1' || $ip === '::1' || $ip === '::ffff:127.0.0.1') {
            return true;
        }

        if ($ip === '') {
            return false;
        }

        foreach ($this->configuredProxyCidrs($config) as $cidr) {
            if ($ip === $cidr || $this->ipInCidr($ip, $cidr)) {
                return true;
            }
        }

        if (($config['trust_private_proxy_ranges'] ?? false) && $this->isPrivateOrReservedIp($ip)) {
            return true;
        }

        return $this->isKnownCdnProxyAddress($ip);
    }

    private function configuredProxyCidrs(array $config): array
    {
        $raw = $config['trusted_proxy_cidrs'] ?? [];
        if (is_string($raw)) {
            $raw = preg_split('/[\s,]+/', $raw) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn ($value) => trim((string) $value), $raw)));
    }

    private function isKnownCdnProxyAddress(string $ip): bool
    {
        $cidrs = [
            '173.245.48.0/20',
            '103.21.244.0/22',
            '103.22.200.0/22',
            '103.31.4.0/22',
            '141.101.64.0/18',
            '108.162.192.0/18',
            '190.93.240.0/20',
            '188.114.96.0/20',
            '197.234.240.0/22',
            '198.41.128.0/17',
            '162.158.0.0/15',
            '104.16.0.0/13',
            '104.24.0.0/14',
            '172.64.0.0/13',
            '131.0.72.0/22',
            '2400:cb00::/32',
            '2606:4700::/32',
            '2803:f800::/32',
            '2405:b500::/32',
            '2405:8100::/32',
            '2a06:98c0::/29',
            '2c0f:f248::/32',
        ];

        foreach ($cidrs as $cidr) {
            if ($this->ipInCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
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
