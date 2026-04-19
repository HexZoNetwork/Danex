<?php

namespace Pterodactyl\Services\PteroProtect;

use RuntimeException;

class AdminOwnershipService
{
    private string $storePath;

    public function __construct(?string $storePath = null)
    {
        $this->storePath = $storePath ?: (string) env('PTEROPROTECT_ADMIN_OWNERSHIP_FILE', storage_path('pteroprotect/admin_ownership.json'));
    }

    public function remember(string $resourceType, int $resourceId, int $adminUserId, ?string $tokenIdentifier = null): void
    {
        $type = $this->normalizeType($resourceType);
        $this->mutate(function (array $data) use ($type, $resourceId, $adminUserId, $tokenIdentifier): array {
            $data[$type][(string) $resourceId] = [
                'admin_user_id' => $adminUserId,
                'token_identifier' => trim((string) ($tokenIdentifier ?? '')),
                'created_at' => time(),
                'updated_at' => time(),
            ];

            return $data;
        });
    }

    public function forget(string $resourceType, int $resourceId): void
    {
        $type = $this->normalizeType($resourceType);
        $this->mutate(function (array $data) use ($type, $resourceId): array {
            unset($data[$type][(string) $resourceId]);
            return $data;
        });
    }

    public function isOwnedBy(string $resourceType, int $resourceId, int $adminUserId, ?string $tokenIdentifier = null): bool
    {
        $type = $this->normalizeType($resourceType);
        $data = $this->read();
        $record = $data[$type][(string) $resourceId] ?? null;
        if (!is_array($record)) {
            return false;
        }

        if ((int) ($record['admin_user_id'] ?? 0) !== $adminUserId) {
            return false;
        }

        $storedToken = trim((string) ($record['token_identifier'] ?? ''));
        if ($tokenIdentifier !== null && $storedToken !== '' && $storedToken !== trim($tokenIdentifier)) {
            return false;
        }

        return true;
    }

    /**
     * @return list<int>
     */
    public function ownedIdsFor(string $resourceType, int $adminUserId, ?string $tokenIdentifier = null): array
    {
        $type = $this->normalizeType($resourceType);
        $data = $this->read();

        $result = [];
        foreach (($data[$type] ?? []) as $id => $record) {
            if (!is_array($record)) {
                continue;
            }

            if ((int) ($record['admin_user_id'] ?? 0) !== $adminUserId) {
                continue;
            }

            $storedToken = trim((string) ($record['token_identifier'] ?? ''));
            if ($tokenIdentifier !== null && $storedToken !== '' && $storedToken !== trim($tokenIdentifier)) {
                continue;
            }

            if (ctype_digit((string) $id)) {
                $result[] = (int) $id;
            }
        }

        sort($result, SORT_NUMERIC);

        return $result;
    }

    private function normalizeType(string $resourceType): string
    {
        $value = strtolower(trim($resourceType));

        return match ($value) {
            'user', 'users' => 'users',
            'server', 'servers' => 'servers',
            default => throw new RuntimeException('Unsupported ownership resource type.'),
        };
    }

    private function ensureStoreExists(): void
    {
        $dir = dirname($this->storePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        if (!is_file($this->storePath)) {
            @file_put_contents($this->storePath, "{\n  \"users\": {},\n  \"servers\": {}\n}\n", LOCK_EX);
        }
    }

    private function read(): array
    {
        $this->ensureStoreExists();

        $fp = @fopen($this->storePath, 'c+');
        if ($fp === false) {
            return ['users' => [], 'servers' => []];
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                return ['users' => [], 'servers' => []];
            }

            rewind($fp);
            $raw = stream_get_contents($fp);
            $data = json_decode((string) $raw, true);
            if (!is_array($data)) {
                $data = [];
            }

            $data['users'] = is_array($data['users'] ?? null) ? $data['users'] : [];
            $data['servers'] = is_array($data['servers'] ?? null) ? $data['servers'] : [];

            flock($fp, LOCK_UN);

            return $data;
        } finally {
            fclose($fp);
        }
    }

    private function mutate(callable $callback): void
    {
        $this->ensureStoreExists();

        $fp = @fopen($this->storePath, 'c+');
        if ($fp === false) {
            return;
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                return;
            }

            rewind($fp);
            $raw = stream_get_contents($fp);
            $data = json_decode((string) $raw, true);
            if (!is_array($data)) {
                $data = [];
            }

            $data['users'] = is_array($data['users'] ?? null) ? $data['users'] : [];
            $data['servers'] = is_array($data['servers'] ?? null) ? $data['servers'] : [];

            $updated = $callback($data);
            if (!is_array($updated)) {
                $updated = $data;
            }

            $json = json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $json = "{\n  \"users\": {},\n  \"servers\": {}\n}";
            }

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, $json . "\n");
            fflush($fp);
            flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }
    }
}
