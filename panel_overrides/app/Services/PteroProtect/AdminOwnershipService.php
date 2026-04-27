<?php

namespace Pterodactyl\Services\PteroProtect;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class AdminOwnershipService
{
    public function remember(string $resourceType, int $resourceId, int $adminUserId, ?string $tokenIdentifier = null): void
    {
        $type = $this->normalizeType($resourceType);
        $now = now();
        $token = trim((string) ($tokenIdentifier ?? ''));

        DB::table('pteroprotect_admin_ownerships')->updateOrInsert(
            [
                'resource_type' => $type,
                'resource_id' => $resourceId,
            ],
            [
                'admin_user_id' => $adminUserId,
                'token_identifier' => ($token === '' ? null : $token),
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
    }

    public function forget(string $resourceType, int $resourceId): void
    {
        $type = $this->normalizeType($resourceType);
        DB::table('pteroprotect_admin_ownerships')
            ->where('resource_type', $type)
            ->where('resource_id', $resourceId)
            ->delete();
    }

    public function isOwnedBy(string $resourceType, int $resourceId, int $adminUserId, ?string $tokenIdentifier = null): bool
    {
        $type = $this->normalizeType($resourceType);
        $record = DB::table('pteroprotect_admin_ownerships')
            ->select(['admin_user_id', 'token_identifier'])
            ->where('resource_type', $type)
            ->where('resource_id', $resourceId)
            ->first();
        if (!$record) {
            return false;
        }

        if ((int) ($record->admin_user_id ?? 0) !== $adminUserId) {
            return false;
        }

        $storedToken = trim((string) ($record->token_identifier ?? ''));
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
        $query = DB::table('pteroprotect_admin_ownerships')
            ->select(['resource_id', 'token_identifier'])
            ->where('resource_type', $type)
            ->where('admin_user_id', $adminUserId);

        $token = ($tokenIdentifier !== null) ? trim($tokenIdentifier) : null;
        if ($token !== null) {
            $query->where(function ($builder) use ($token) {
                $builder->whereNull('token_identifier')
                    ->orWhere('token_identifier', '')
                    ->orWhere('token_identifier', $token);
            });
        }

        $rows = $query->orderBy('resource_id')->get();
        $result = [];
        foreach ($rows as $row) {
            $result[] = (int) $row->resource_id;
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
}
