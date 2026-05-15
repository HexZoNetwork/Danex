<?php

namespace Pterodactyl\Http\Controllers\Api\Client;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Services\PteroProtect\DanexCTelemetryService;

class DanexCController extends ClientApiController
{
    public function __construct(private DanexCTelemetryService $telemetry)
    {
    }

    public function overview(Request $request): JsonResponse
    {
        $window = max(5, min(240, (int) $request->query('window', 60)));
        return new JsonResponse($this->telemetry->buildOverview($window));
    }

    public function timeline(Request $request): JsonResponse
    {
        $window = max(5, min(240, (int) $request->query('window', 60)));
        return new JsonResponse([
            'timeline' => $this->telemetry->buildTimeline($window),
            'meta' => [
                'window_minutes' => $window,
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function feed(Request $request): JsonResponse
    {
        $limit = max(10, min(100, (int) $request->query('limit', 40)));
        return new JsonResponse([
            'live_feed' => $this->telemetry->buildLiveFeed($limit),
            'meta' => [
                'limit' => $limit,
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function threats(Request $request): JsonResponse
    {
        $window = max(5, min(240, (int) $request->query('window', 60)));
        $overview = $this->telemetry->buildOverview($window);

        return new JsonResponse([
            'threat' => $overview['threat'] ?? ['level' => 'low', 'score' => 0, 'reason_codes' => []],
            'most_targeted_paths' => $overview['most_targeted_paths'] ?? [],
            'live_feed' => $overview['live_feed'] ?? [],
            'meta' => [
                'window_minutes' => $window,
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function config(Request $request): JsonResponse
    {
        $window = max(5, min(240, (int) $request->query('window', 60)));
        $overview = $this->telemetry->buildOverview($window);

        return new JsonResponse([
            'system_config' => $overview['system_config'] ?? [],
            'meta' => [
                'window_minutes' => $window,
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }
}
