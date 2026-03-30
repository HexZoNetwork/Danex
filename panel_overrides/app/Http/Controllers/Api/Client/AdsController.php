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
        $bannerPool = array_values(array_filter($allEnabled, fn (array $item) => !(bool) ($item['is_popup'] ?? false)));
        if ($bannerPool === []) {
            $bannerPool = $allEnabled;
        }

        $rotatingBanner = $this->pickRotatingItem($bannerPool, 600, 'banner');
        $banners = is_array($rotatingBanner) ? [$rotatingBanner] : [];
        $popup = $this->ads->randomPopup();

        return new JsonResponse([
            'data' => [
                'service_enabled' => true,
                'banners' => array_values(array_map(fn (array $item) => $this->transform($item), $banners)),
                'popup' => is_array($popup) ? $this->transform($popup) : null,
            ],
        ]);
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>|null
     */
    private function pickRotatingItem(array $items, int $windowSeconds, string $salt): ?array
    {
        if ($items === []) {
            return null;
        }

        $window = max(60, $windowSeconds);
        $bucket = (int) floor(time() / $window);
        $seed = (string) config('app.key', 'pteroprotect') . '|' . $salt . '|' . (string) $bucket;
        $idx = (int) (abs(crc32($seed)) % count($items));

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
