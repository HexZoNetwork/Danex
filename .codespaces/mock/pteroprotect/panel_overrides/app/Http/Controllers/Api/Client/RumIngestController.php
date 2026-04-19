<?php

namespace Pterodactyl\Http\Controllers\Api\Client;

use Illuminate\Support\Arr;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class RumIngestController extends ClientApiController
{
    /**
     * Ingest lightweight RUM events from authenticated panel sessions.
     */
    public function __invoke(ClientApiRequest $request): JsonResponse
    {
        if (!Schema::hasTable('panel_rum_events')) {
            return new JsonResponse(['ok' => true, 'ingested' => 0, 'skipped' => 'missing_table'], 202);
        }

        $validated = $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:50'],
            'events.*.metric' => ['required', 'string', 'max:48'],
            'events.*.value' => ['nullable', 'numeric'],
            'events.*.route' => ['nullable', 'string', 'max:255'],
            'events.*.rating' => ['nullable', 'string', 'max:16'],
            'events.*.delta' => ['nullable', 'numeric'],
            'events.*.ttfb' => ['nullable', 'numeric'],
            'events.*.status' => ['nullable', 'integer'],
            'events.*.api_path' => ['nullable', 'string', 'max:255'],
            'events.*.meta' => ['nullable', 'array'],
            'events.*.at' => ['nullable', 'integer'],
        ]);

        $allowedMetrics = [
            'LCP',
            'CLS',
            'INP',
            'FCP',
            'TTFB',
            'API_LATENCY',
            'JS_ERROR',
            'UNHANDLED_REJECTION',
        ];

        $now = now();
        $rows = [];
        $userId = (int) $request->user()->id;

        foreach ($validated['events'] as $event) {
            $metric = strtoupper(str_replace('-', '_', (string) ($event['metric'] ?? '')));
            if (!in_array($metric, $allowedMetrics, true)) {
                continue;
            }

            $route = $this->normalizePath((string) ($event['route'] ?? ''));
            $apiPath = $this->normalizePath((string) ($event['api_path'] ?? ''));
            $rating = $this->normalizeRating((string) ($event['rating'] ?? ''));
            $occurredAt = $this->normalizeOccurredAt($event['at'] ?? null, $now);

            $rows[] = [
                'user_id' => $userId,
                'metric' => $metric,
                'value' => is_numeric($event['value'] ?? null) ? (float) $event['value'] : null,
                'route' => $route,
                'rating' => $rating,
                'delta' => is_numeric($event['delta'] ?? null) ? (float) $event['delta'] : null,
                'ttfb' => is_numeric($event['ttfb'] ?? null) ? (float) $event['ttfb'] : null,
                'status' => is_numeric($event['status'] ?? null) ? (int) $event['status'] : null,
                'api_path' => $apiPath,
                'meta' => json_encode($this->normalizeMeta((array) ($event['meta'] ?? [])), JSON_UNESCAPED_SLASHES),
                'occurred_at' => $occurredAt->toDateTimeString(),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (!empty($rows)) {
            DB::table('panel_rum_events')->insert($rows);
        }

        return new JsonResponse(['ok' => true, 'ingested' => count($rows)]);
    }

    private function normalizePath(string $raw): string
    {
        $path = trim($raw);
        if ($path === '') {
            return '';
        }
        if (str_contains($path, '?')) {
            $path = (string) strstr($path, '?', true);
        }
        if (strlen($path) > 255) {
            $path = substr($path, 0, 255);
        }

        return $path;
    }

    private function normalizeRating(string $raw): string
    {
        $rating = strtolower(trim($raw));
        if (!in_array($rating, ['good', 'needs-improvement', 'poor'], true)) {
            return '';
        }

        return $rating;
    }

    private function normalizeOccurredAt(mixed $rawAt, \Illuminate\Support\Carbon $now): \Illuminate\Support\Carbon
    {
        if (!is_numeric($rawAt)) {
            return $now;
        }

        $ts = (int) $rawAt;
        if ($ts <= 0) {
            return $now;
        }

        $at = \Illuminate\Support\Carbon::createFromTimestamp($ts);
        if ($at->lt($now->copy()->subDays(2))) {
            return $now->copy()->subDays(2);
        }
        if ($at->gt($now->copy()->addMinutes(5))) {
            return $now;
        }

        return $at;
    }

    private function normalizeMeta(array $meta): array
    {
        $allowed = Arr::only($meta, ['name', 'message', 'source']);
        $out = [];
        foreach ($allowed as $key => $value) {
            $text = trim((string) $value);
            if ($text === '') {
                continue;
            }
            // Redact obvious credential leakage patterns.
            $text = preg_replace('/(bearer\s+)[a-z0-9\-_\.]+/i', '$1[redacted]', $text) ?? $text;
            $text = preg_replace('/(token|password|passwd|secret)\s*[:=]\s*[^\s]+/i', '$1=[redacted]', $text) ?? $text;
            if (strlen($text) > 300) {
                $text = substr($text, 0, 300);
            }
            $out[$key] = $text;
        }

        return $out;
    }
}
