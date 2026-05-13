<?php

namespace Pterodactyl\Services\PteroProtect;

use DateTimeImmutable;
use Illuminate\Support\Facades\File;

class DanexCTelemetryService
{
    private const DEFAULT_CONFIG_PATH = '/pteroprotect/config.json';
    private const DEFAULT_ACCESS_LOG = '/var/log/nginx/pteroprotect.access.log';
    private const DEFAULT_RUNTIME_DIR = '/dev/shm/pteroprotect';
    private const DEFAULT_PANEL_RUNTIME_DIR = '/pteroprotect/runtime';

    public function buildOverview(int $windowMinutes = 60): array
    {
        $windowMinutes = max(5, min(240, $windowMinutes));
        $accessRows = $this->parseAccessRows($windowMinutes);
        $timeline = $this->buildTimelineFromRows($accessRows, $windowMinutes);
        $paths = $this->buildTargetedPaths($accessRows);
        $metrics = $this->buildMetrics($accessRows);
        $feed = $this->buildLiveFeed(40);
        $threat = $this->buildThreat($metrics, $feed);

        return [
            'metrics' => $metrics,
            'most_targeted_paths' => $paths,
            'system_config' => $this->buildSystemConfig(),
            'timeline' => $timeline,
            'threat' => $threat,
            'live_feed' => $feed,
            'meta' => [
                'window_minutes' => $windowMinutes,
                'generated_at' => now()->toIso8601String(),
                'source_health' => [
                    'access_log_present' => File::exists($this->accessLogPath()),
                    'runtime_present' => File::isDirectory($this->runtimeDir()),
                ],
            ],
        ];
    }

    public function buildTimeline(int $windowMinutes = 60): array
    {
        $windowMinutes = max(5, min(240, $windowMinutes));
        return $this->buildTimelineFromRows($this->parseAccessRows($windowMinutes), $windowMinutes);
    }

    public function buildLiveFeed(int $limit = 40): array
    {
        $limit = max(10, min(100, $limit));
        $events = [];
        $latest = $this->readLastLines($this->runtimeDir() . '/ddos_host.latest', 120);

        foreach (array_reverse($latest) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $severity = 'info';
            if (stripos($line, 'lockdown') !== false || stripos($line, 'blocked') !== false) {
                $severity = 'danger';
            } elseif (stripos($line, 'mode') !== false || stripos($line, 'warn') !== false) {
                $severity = 'warning';
            }
            $events[] = [
                'timestamp' => now()->toIso8601String(),
                'severity' => $severity,
                'message' => $this->sanitizeText($line, 180),
            ];
            if (count($events) >= $limit) {
                break;
            }
        }

        $mode = $this->readJsonFile($this->panelRuntimeDir() . '/mode.json');
        if (is_array($mode) && !empty($mode['mode'])) {
            array_unshift($events, [
                'timestamp' => $this->safeIsoFromUnix($mode['updated_at'] ?? null),
                'severity' => $mode['mode'] === 'emergency' ? 'danger' : 'warning',
                'message' => 'Protection mode: ' . $this->sanitizeText((string) $mode['mode'], 30),
            ]);
        }

        $lockdown = $this->readJsonFile($this->panelRuntimeDir() . '/lockdown.json');
        if (is_array($lockdown) && ($lockdown['enabled'] ?? false)) {
            array_unshift($events, [
                'timestamp' => $this->safeIsoFromUnix($lockdown['updated_at'] ?? null),
                'severity' => 'danger',
                'message' => 'Lockdown active',
            ]);
        }

        return array_values(array_slice($events, 0, $limit));
    }

    private function buildSystemConfig(): array
    {
        $cfg = $this->readConfig();
        $network = is_array($cfg['network'] ?? null) ? $cfg['network'] : [];
        $rate = is_array($cfg['rate_limits'] ?? null) ? $cfg['rate_limits'] : [];
        $mode = $this->readJsonFile($this->panelRuntimeDir() . '/mode.json');
        $lockdown = $this->readJsonFile($this->panelRuntimeDir() . '/lockdown.json');

        $rateLabel = 'policy-based';
        if (is_array($rate['api_client'] ?? null)) {
            $rateLabel = trim((string) (($rate['api_client']['rate'] ?? 'policy') . ' / burst ' . ($rate['api_client']['burst'] ?? '?')));
        }

        return [
            'rate_limit' => $this->sanitizeText($rateLabel, 40),
            'active_rules' => (int) ($network['waf_crs_inbound_threshold'] ?? 0) + (int) ($network['waf_crs_outbound_threshold'] ?? 0),
            'detection_sensitivity' => $this->resolveSensitivity((int) ($network['waf_crs_paranoia_level'] ?? 1)),
            'auto_ban_duration' => $this->formatDuration((int) ($network['blackhole_ttl_sec'] ?? 600)),
            'whitelist_count' => is_array($network['trusted_hosts'] ?? null) ? count($network['trusted_hosts']) : 0,
            'blacklist_count' => $this->estimateBlacklistCount(),
            'protection_mode' => $this->resolveProtectionMode($mode, $lockdown),
            'uptime' => $this->buildUptimeLabel(),
        ];
    }

    private function buildMetrics(array $rows): array
    {
        $total = count($rows);
        $denied = 0;
        $bypassed = 0;

        foreach ($rows as $row) {
            if (($row['denied'] ?? false) === true) {
                $denied++;
            }
            if (($row['bypassed'] ?? false) === true) {
                $bypassed++;
            }
        }

        $allowed = max(0, $total - $denied);
        return [
            'total_requests' => $total,
            'denied_requests' => $denied,
            'allowed_requests' => $allowed,
            'bypassed_requests' => $bypassed,
            'denied_percentage' => $this->ratio($denied, $total),
            'allowed_percentage' => $this->ratio($allowed, $total),
            'bypassed_percentage' => $this->ratio($bypassed, $total),
        ];
    }

    private function buildTargetedPaths(array $rows): array
    {
        $bucket = [];
        foreach ($rows as $row) {
            $path = (string) ($row['path'] ?? '/');
            if (!isset($bucket[$path])) {
                $bucket[$path] = ['count' => 0, 'denied' => 0];
            }
            $bucket[$path]['count']++;
            if (($row['denied'] ?? false) === true) {
                $bucket[$path]['denied']++;
            }
        }

        arsort($bucket);
        $total = max(1, count($rows));
        $items = [];
        foreach ($bucket as $path => $stats) {
            $count = (int) $stats['count'];
            $denied = (int) $stats['denied'];
            $allowed = max(0, $count - $denied);
            $items[] = [
                'path' => $this->sanitizePath($path),
                'count' => $count,
                'denied' => $denied,
                'allowed' => $allowed,
                'percentage' => $this->ratio($count, $total),
                'denied_ratio' => $this->ratio($denied, $count),
            ];
            if (count($items) >= 15) {
                break;
            }
        }

        return $items;
    }

    private function buildTimelineFromRows(array $rows, int $windowMinutes): array
    {
        $windowMinutes = max(5, min(240, $windowMinutes));
        $bucketMinutes = max(1, (int) floor($windowMinutes / 12));
        $bucketSeconds = $bucketMinutes * 60;
        $now = time();
        $start = $now - ($windowMinutes * 60);
        $buckets = [];

        for ($ts = $start; $ts <= $now; $ts += $bucketSeconds) {
            $key = (int) floor(($ts - $start) / $bucketSeconds);
            $buckets[$key] = ['allowed' => 0, 'denied' => 0, 'bypassed' => 0, 'timestamp' => gmdate('c', $ts)];
        }

        foreach ($rows as $row) {
            $rowTs = (int) ($row['ts'] ?? 0);
            if ($rowTs < $start || $rowTs > $now) {
                continue;
            }
            $key = (int) floor(($rowTs - $start) / $bucketSeconds);
            if (!isset($buckets[$key])) {
                continue;
            }
            if (($row['denied'] ?? false) === true) {
                $buckets[$key]['denied']++;
            } else {
                $buckets[$key]['allowed']++;
            }
            if (($row['bypassed'] ?? false) === true) {
                $buckets[$key]['bypassed']++;
            }
        }

        return array_values($buckets);
    }

    private function buildThreat(array $metrics, array $feed): array
    {
        $score = 12;
        $score += (int) round((float) ($metrics['denied_percentage'] ?? 0) * 0.6);
        $score += (int) round((float) ($metrics['bypassed_percentage'] ?? 0) * 1.4);
        if (($metrics['bypassed_requests'] ?? 0) > 0) {
            $score += 15;
        }
        if (($metrics['denied_requests'] ?? 0) > 100) {
            $score += 12;
        }

        $reasons = [];
        foreach (array_slice($feed, 0, 12) as $item) {
            $msg = strtolower((string) ($item['message'] ?? ''));
            if (str_contains($msg, 'lockdown')) {
                $score += 20;
                $reasons[] = 'lockdown_active';
            } elseif (str_contains($msg, 'emergency')) {
                $score += 15;
                $reasons[] = 'emergency_mode';
            } elseif (str_contains($msg, 'blocked')) {
                $score += 8;
                $reasons[] = 'dynamic_blocks';
            }
        }

        $score = max(0, min(100, $score));
        $level = 'low';
        if ($score >= 70) {
            $level = 'high';
        } elseif ($score >= 40) {
            $level = 'medium';
        }

        if (($metrics['bypassed_requests'] ?? 0) > 0) {
            $reasons[] = 'waf_bypass_detected';
        }
        if (($metrics['denied_percentage'] ?? 0) >= 35.0) {
            $reasons[] = 'high_denied_ratio';
        }

        return [
            'level' => $level,
            'score' => $score,
            'reason_codes' => array_values(array_unique($reasons)),
        ];
    }

    private function parseAccessRows(int $windowMinutes): array
    {
        $lines = $this->readLastLines($this->accessLogPath(), 20000);
        $cutoff = time() - ($windowMinutes * 60);
        $rows = [];

        foreach ($lines as $line) {
            $parsed = $this->parseAccessLine($line);
            if ($parsed === null) {
                continue;
            }
            if (($parsed['ts'] ?? 0) < $cutoff) {
                continue;
            }
            $rows[] = $parsed;
        }

        return $rows;
    }

    private function parseAccessLine(string $line): ?array
    {
        if (!preg_match('/\[(?<date>[^\]]+)\]/', $line, $dm)) {
            return null;
        }
        if (!preg_match('/"\w+\s+(?<target>\S+)\s+HTTP\/[0-9.]+"\s+(?<status>\d{3})/', $line, $rm)) {
            return null;
        }
        $dt = DateTimeImmutable::createFromFormat('d/M/Y:H:i:s O', $dm['date']);
        if (!$dt) {
            return null;
        }
        $target = (string) ($rm['target'] ?? '/');
        $path = parse_url($target, PHP_URL_PATH);
        $status = (int) ($rm['status'] ?? 0);
        $rawLower = strtolower($line);

        return [
            'ts' => $dt->getTimestamp(),
            'path' => $this->sanitizePath((string) ($path ?: '/')),
            'status' => $status,
            'denied' => in_array($status, [401, 403, 429, 444, 503], true),
            'bypassed' => str_contains($rawLower, 'bypass'),
        ];
    }

    private function sanitizePath(string $path): string
    {
        $value = preg_replace('/[^a-zA-Z0-9\/._\-]/', '', $path) ?: '/';
        if ($value === '') {
            $value = '/';
        }
        return substr($value, 0, 120);
    }

    private function sanitizeText(string $text, int $maxLen = 160): string
    {
        $text = preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '[redacted-ipv4]', $text) ?? $text;
        $text = preg_replace('/\b[0-9a-f]{1,4}(?::[0-9a-f]{1,4}){2,}\b/i', '[redacted-ipv6]', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', trim($text)) ?? trim($text);
        return substr($text, 0, $maxLen);
    }

    private function ratio(int $a, int $b): float
    {
        if ($b <= 0) {
            return 0.0;
        }
        return round(($a / $b) * 100, 2);
    }

    private function buildUptimeLabel(): string
    {
        $uptimePath = '/proc/uptime';
        if (!File::exists($uptimePath) || !is_readable($uptimePath)) {
            return 'n/a';
        }
        $raw = trim((string) @file_get_contents($uptimePath));
        if ($raw === '') {
            return 'n/a';
        }
        $first = (float) explode(' ', $raw)[0];
        $days = (int) floor($first / 86400);
        $hours = (int) floor(($first % 86400) / 3600);
        return sprintf('%dd %dh', $days, $hours);
    }

    private function estimateBlacklistCount(): int
    {
        $path = $this->runtimeDir() . '/block_history.tsv';
        if (!File::exists($path)) {
            return 0;
        }
        return count($this->readLastLines($path, 5000));
    }

    private function resolveSensitivity(int $paranoia): string
    {
        if ($paranoia >= 3) {
            return 'High';
        }
        if ($paranoia === 2) {
            return 'Medium';
        }
        return 'Low';
    }

    private function resolveProtectionMode(array|null $modeData, array|null $lockdownData): string
    {
        if (is_array($lockdownData) && ($lockdownData['enabled'] ?? false)) {
            return 'Aggressive';
        }
        $mode = strtolower((string) ($modeData['mode'] ?? 'normal'));
        if ($mode === 'emergency' || $mode === 'strict') {
            return 'Aggressive';
        }
        if ($mode === 'elevated' || $mode === 'constrained') {
            return 'Active';
        }
        return 'Learning';
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds >= 86400) {
            return (string) round($seconds / 86400) . 'd';
        }
        if ($seconds >= 3600) {
            return (string) round($seconds / 3600) . 'h';
        }
        if ($seconds >= 60) {
            return (string) round($seconds / 60) . 'm';
        }
        return $seconds . 's';
    }

    private function safeIsoFromUnix(mixed $unix): string
    {
        $ts = (int) $unix;
        if ($ts <= 0) {
            return now()->toIso8601String();
        }
        return gmdate('c', $ts);
    }

    private function readConfig(): array
    {
        $path = env('PTEROPROTECT_CONFIG_PATH', self::DEFAULT_CONFIG_PATH);
        if (!is_string($path) || $path === '' || !File::exists($path) || !is_readable($path)) {
            $repoDefault = base_path('config.json');
            if (is_string($repoDefault) && File::exists($repoDefault) && is_readable($repoDefault)) {
                $path = $repoDefault;
            }
        }
        return $this->readJsonFile((string) $path);
    }

    private function readJsonFile(string $path): array
    {
        if ($path === '' || !File::exists($path) || !is_readable($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function accessLogPath(): string
    {
        return (string) env('PTEROPROTECT_ACCESS_LOG_PATH', self::DEFAULT_ACCESS_LOG);
    }

    private function runtimeDir(): string
    {
        return (string) env('PTEROPROTECT_RUNTIME_DIR', self::DEFAULT_RUNTIME_DIR);
    }

    private function panelRuntimeDir(): string
    {
        return (string) env('PTEROPROTECT_PANEL_RUNTIME_DIR', self::DEFAULT_PANEL_RUNTIME_DIR);
    }

    private function readLastLines(string $path, int $maxLines): array
    {
        if ($path === '' || !File::exists($path) || !is_readable($path) || $maxLines <= 0) {
            return [];
        }

        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            return [];
        }

        $chunkSize = 65536;
        $buffer = '';
        $lines = [];
        $position = @fseek($handle, 0, SEEK_END) === 0 ? @ftell($handle) : false;
        if (!is_int($position)) {
            @fclose($handle);
            return [];
        }

        while ($position > 0 && count($lines) <= $maxLines) {
            $readSize = min($chunkSize, $position);
            $position -= $readSize;
            if (@fseek($handle, $position) !== 0) {
                break;
            }
            $chunk = @fread($handle, $readSize);
            if (!is_string($chunk) || $chunk === '') {
                break;
            }

            $buffer = $chunk . $buffer;
            $lines = preg_split('/\r\n|\n|\r/', $buffer);
            if (!is_array($lines)) {
                $lines = [];
                break;
            }
        }

        @fclose($handle);

        if ($lines === []) {
            return [];
        }

        if (end($lines) === '') {
            array_pop($lines);
        }

        return array_slice($lines, -$maxLines);
    }
}
