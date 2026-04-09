<?php

namespace Pterodactyl\Http\Controllers\Api\Client;

use Illuminate\Support\Arr;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class RumIngestController extends ClientApiController
{
    /**
     * Ingest lightweight RUM events from authenticated panel sessions.
     */
    public function __invoke(ClientApiRequest $request): JsonResponse
    {
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
            'api_latency',
            'js_error',
            'unhandled_rejection',
        ];

        $now = now();
        $rows = [];
        $userId = (int) $request->user()->id;

        foreach ($validated['events'] as $event) {
            $metric = strtoupper((string) ($event['metric'] ?? ''));
            if (!in_array($metric, $allowedMetrics, true)) {
                continue;
            }

            $rows[] = [
                'user_id' => $userId,
                'metric' => $metric,
                'value' => is_numeric($event['value'] ?? null) ? (float) $event['value'] : null,
                'route' => substr((string) ($event['route'] ?? ''), 0, 255),
                'rating' => substr((string) ($event['rating'] ?? ''), 0, 16),
                'delta' => is_numeric($event['delta'] ?? null) ? (float) $event['delta'] : null,
                'ttfb' => is_numeric($event['ttfb'] ?? null) ? (float) $event['ttfb'] : null,
                'status' => is_numeric($event['status'] ?? null) ? (int) $event['status'] : null,
                'api_path' => substr((string) ($event['api_path'] ?? ''), 0, 255),
                'meta' => json_encode(Arr::only((array) ($event['meta'] ?? []), ['name', 'message', 'source']), JSON_UNESCAPED_SLASHES),
                'occurred_at' => !empty($event['at']) ? date('Y-m-d H:i:s', (int) $event['at']) : $now->toDateTimeString(),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (!empty($rows)) {
            DB::table('panel_rum_events')->insert($rows);
        }

        return new JsonResponse(['ok' => true, 'ingested' => count($rows)]);
    }
}
