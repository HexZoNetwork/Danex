<?php

namespace Pterodactyl\Http\Controllers\Api\Client;

use Illuminate\Http\JsonResponse;
use Pterodactyl\Services\PteroProtect\AdsService;

class AdsController extends ClientApiController
{
    public function __construct(private AdsService $ads)
    {
        parent::__construct();
    }

    public function index(): JsonResponse
    {
        $serviceEnabled = $this->ads->serviceEnabled();
        if (!$serviceEnabled) {
            return new JsonResponse([
                'data' => [
                    'service_enabled' => false,
                    'banners' => [],
                    'popup' => null,
                ],
            ]);
        }

        $allEnabled = array_values(array_filter($this->ads->all(), fn (array $item) => (bool) ($item['enabled'] ?? false)));
        $rotatingBanner = $this->pickRoundRobinItem($allEnabled, 600);
        $banners = is_array($rotatingBanner) ? [$rotatingBanner] : [];

        return new JsonResponse([
            'data' => [
                'service_enabled' => true,
                'banners' => array_values(array_map(fn (array $item) => $this->transform($item), $banners)),
                'popup' => null,
            ],
        ]);
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>|null
     */
    private function pickRoundRobinItem(array $items, int $windowSeconds): ?array
    {
        if ($items === []) {
            return null;
        }
        usort($items, fn (array $a, array $b) => (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0));
        $window = max(60, $windowSeconds);
        $bucket = (int) floor(time() / $window);
        $idx = (int) ($bucket % count($items));
        return $items[$idx] ?? null;
    }

    private function transform(array $item): array
    {
        return [
            'id' => (int) ($item['id'] ?? 0),
            'media_url' => (string) ($item['media_url'] ?? ''),
            'link_url' => (string) ($item['link_url'] ?? ''),
            'text' => (string) ($item['text'] ?? ''),
            'is_popup' => (bool) ($item['is_popup'] ?? false),
            'enabled' => (bool) ($item['enabled'] ?? false),
            'weight' => (int) ($item['weight'] ?? 1),
            'media_kind' => $this->ads->mediaKind((string) ($item['media_url'] ?? '')),
        ];
    }
}
