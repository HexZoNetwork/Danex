<?php

namespace Pterodactyl\Services\PteroProtect;

class AdsService
{
    private string $storePath;

    public function __construct(?string $storePath = null)
    {
        $this->storePath = $storePath ?: (string) env('PTEROPROTECT_ADS_FILE', storage_path('pteroprotect/ads.json'));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        $data = $this->read();
        $items = $data['items'] ?? [];
        if (!is_array($items)) {
            return [];
        }

        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $normalized = $this->normalizeItem($item);
            if ($normalized !== null) {
                $result[] = $normalized;
            }
        }

        usort($result, function (array $a, array $b) {
            $byPopup = (int) $a['is_popup'] <=> (int) $b['is_popup'];
            if ($byPopup !== 0) {
                return $byPopup;
            }

            $byEnabled = (int) $b['enabled'] <=> (int) $a['enabled'];
            if ($byEnabled !== 0) {
                return $byEnabled;
            }

            $byUpdated = strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? ''));
            if ($byUpdated !== 0) {
                return $byUpdated;
            }

            return (int) $a['id'] <=> (int) $b['id'];
        });

        return $result;
    }

    public function serviceEnabled(): bool
    {
        $data = $this->read();
        if (array_key_exists('service_enabled', $data)) {
            return (bool) $data['service_enabled'];
        }

        return true;
    }

    public function setServiceEnabled(bool $enabled): void
    {
        $this->ensureStoreExists();
        $this->mutate(function (array $data) use ($enabled): array {
            $data['service_enabled'] = $enabled;

            return $data;
        });
    }

    public function find(int $id): ?array
    {
        foreach ($this->all() as $item) {
            if ((int) ($item['id'] ?? 0) === $id) {
                return $item;
            }
        }

        return null;
    }

    public function create(array $input): array
    {
        $this->ensureStoreExists();

        return $this->mutate(function (array $data) use ($input): array {
            $items = is_array($data['items'] ?? null) ? $data['items'] : [];
            $nextId = 1;
            foreach ($items as $item) {
                if (is_array($item) && is_numeric($item['id'] ?? null)) {
                    $nextId = max($nextId, (int) $item['id'] + 1);
                }
            }

            $now = date('c');
            $item = $this->normalizeItem(array_merge($input, [
                'id' => $nextId,
                'created_at' => $now,
                'updated_at' => $now,
            ]));

            if ($item !== null) {
                $items[] = $item;
            }

            $data['items'] = array_values($items);

            return $data;
        })['item'] ?? [];
    }

    public function update(int $id, array $input): ?array
    {
        $this->ensureStoreExists();

        $result = $this->mutate(function (array $data) use ($id, $input): array {
            $items = is_array($data['items'] ?? null) ? $data['items'] : [];
            $updatedItem = null;
            $now = date('c');

            foreach ($items as $idx => $item) {
                if (!is_array($item) || (int) ($item['id'] ?? 0) !== $id) {
                    continue;
                }

                $merged = array_merge($item, $input, [
                    'id' => $id,
                    'updated_at' => $now,
                ]);
                if (!isset($merged['created_at'])) {
                    $merged['created_at'] = $now;
                }

                $normalized = $this->normalizeItem($merged);
                if ($normalized !== null) {
                    $items[$idx] = $normalized;
                    $updatedItem = $normalized;
                }
                break;
            }

            $data['items'] = array_values($items);
            $data['_updated_item'] = $updatedItem;

            return $data;
        });

        $item = $result['item'] ?? null;

        return is_array($item) ? $item : null;
    }

    public function delete(int $id): bool
    {
        $this->ensureStoreExists();

        $result = $this->mutate(function (array $data) use ($id): array {
            $items = is_array($data['items'] ?? null) ? $data['items'] : [];
            $before = count($items);
            $items = array_values(array_filter($items, fn ($item) => !is_array($item) || (int) ($item['id'] ?? 0) !== $id));
            $data['items'] = $items;
            $data['_deleted'] = count($items) < $before;

            return $data;
        });

        return (bool) ($result['deleted'] ?? false);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function randomBanners(int $limit = 2): array
    {
        if (!$this->serviceEnabled()) {
            return [];
        }

        $limit = max(1, min(6, $limit));
        $items = array_values(array_filter($this->all(), fn (array $item) => $item['enabled']));

        return $this->pickWeighted($items, $limit);
    }

    public function randomPopup(): ?array
    {
        if (!$this->serviceEnabled()) {
            return null;
        }

        $allEnabled = array_values(array_filter($this->all(), fn (array $item) => $item['enabled']));
        if ($allEnabled === []) {
            return null;
        }

        $popupTagged = array_values(array_filter($allEnabled, fn (array $item) => $item['is_popup']));
        $items = $popupTagged !== [] ? $popupTagged : $allEnabled;
        $picked = $this->pickWeighted($items, 1);

        return $picked[0] ?? null;
    }

    public function mediaKind(string $mediaUrl): string
    {
        $path = strtolower((string) parse_url($mediaUrl, PHP_URL_PATH));
        if (preg_match('/\.(mp4|webm|ogg|mov|m4v)$/', $path) === 1) {
            return 'video';
        }

        return 'image';
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private function pickWeighted(array $items, int $limit): array
    {
        if ($items === []) {
            return [];
        }

        $pool = [];
        foreach ($items as $item) {
            $weight = max(1, min(100, (int) ($item['weight'] ?? 1)));
            $pool[] = ['item' => $item, 'weight' => $weight];
        }

        $selected = [];
        for ($i = 0; $i < $limit && $pool !== []; $i++) {
            $total = array_sum(array_map(fn (array $row) => (int) $row['weight'], $pool));
            if ($total <= 0) {
                break;
            }

            $rand = random_int(1, $total);
            $running = 0;
            foreach ($pool as $idx => $row) {
                $running += (int) $row['weight'];
                if ($rand <= $running) {
                    $selected[] = $row['item'];
                    unset($pool[$idx]);
                    $pool = array_values($pool);
                    break;
                }
            }
        }

        return $selected;
    }

    private function normalizeItem(array $item): ?array
    {
        $id = (int) ($item['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        $mediaUrl = trim((string) ($item['media_url'] ?? ''));
        if (!$this->isSafeHttpUrl($mediaUrl)) {
            return null;
        }

        $linkUrl = trim((string) ($item['link_url'] ?? ''));
        if ($linkUrl !== '' && !$this->isSafeHttpUrl($linkUrl)) {
            $linkUrl = '';
        }

        $createdAt = trim((string) ($item['created_at'] ?? ''));
        $updatedAt = trim((string) ($item['updated_at'] ?? ''));

        return [
            'id' => $id,
            'media_url' => $mediaUrl,
            'link_url' => $linkUrl,
            'text' => mb_substr(trim((string) ($item['text'] ?? '')), 0, 255),
            'is_popup' => (bool) ($item['is_popup'] ?? false),
            'enabled' => (bool) ($item['enabled'] ?? true),
            'weight' => max(1, min(100, (int) ($item['weight'] ?? 1))),
            'created_at' => $createdAt !== '' ? $createdAt : date('c'),
            'updated_at' => $updatedAt !== '' ? $updatedAt : date('c'),
        ];
    }

    private function isSafeHttpUrl(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return false;
        }

        $parts = parse_url($value);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = trim((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return false;
        }

        return true;
    }

    private function ensureStoreExists(): void
    {
        $dir = dirname($this->storePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        if (!is_file($this->storePath)) {
            @file_put_contents($this->storePath, "{\n  \"items\": []\n}\n", LOCK_EX);
        }
    }

    private function read(): array
    {
        $this->ensureStoreExists();

        $raw = @file_get_contents($this->storePath);
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            $data = [];
        }

        $data['items'] = is_array($data['items'] ?? null) ? $data['items'] : [];
        $data['service_enabled'] = array_key_exists('service_enabled', $data) ? (bool) $data['service_enabled'] : true;

        return $data;
    }

    /**
     * @return array{item?:array<string,mixed>,deleted?:bool}
     */
    private function mutate(callable $callback): array
    {
        $this->ensureStoreExists();

        $fp = @fopen($this->storePath, 'c+');
        if ($fp === false) {
            return [];
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                return [];
            }

            rewind($fp);
            $raw = stream_get_contents($fp);
            $data = json_decode((string) $raw, true);
            if (!is_array($data)) {
                $data = [];
            }
            $data['items'] = is_array($data['items'] ?? null) ? $data['items'] : [];
            $data['service_enabled'] = array_key_exists('service_enabled', $data) ? (bool) $data['service_enabled'] : true;

            $updated = $callback($data);
            if (!is_array($updated)) {
                $updated = $data;
            }

            $item = $updated['_updated_item'] ?? null;
            $deleted = (bool) ($updated['_deleted'] ?? false);
            unset($updated['_updated_item'], $updated['_deleted']);
            $updated['items'] = is_array($updated['items'] ?? null) ? $updated['items'] : [];
            $updated['service_enabled'] = array_key_exists('service_enabled', $updated) ? (bool) $updated['service_enabled'] : true;

            $json = json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $json = "{\n  \"items\": []\n}";
            }

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, $json . "\n");
            fflush($fp);
            flock($fp, LOCK_UN);

            $result = [];
            if (is_array($item)) {
                $result['item'] = $item;
            }
            if ($deleted) {
                $result['deleted'] = true;
            }

            return $result;
        } finally {
            fclose($fp);
        }
    }
}
