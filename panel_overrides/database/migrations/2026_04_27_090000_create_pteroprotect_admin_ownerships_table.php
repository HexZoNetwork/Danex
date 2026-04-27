<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('pteroprotect_admin_ownerships')) {
            Schema::create('pteroprotect_admin_ownerships', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('resource_type', 16);
                $table->unsignedBigInteger('resource_id');
                $table->unsignedBigInteger('admin_user_id');
                $table->string('token_identifier', 191)->nullable();
                $table->timestamps();

                $table->unique(['resource_type', 'resource_id'], 'pp_admin_owner_unique');
                $table->index(['resource_type', 'admin_user_id'], 'pp_admin_owner_lookup');
            });
        }

        $legacyPath = (string) env('PTEROPROTECT_ADMIN_OWNERSHIP_FILE', storage_path('pteroprotect/admin_ownership.json'));
        if (!is_file($legacyPath)) {
            return;
        }

        $raw = @file_get_contents($legacyPath);
        if (!is_string($raw) || trim($raw) === '') {
            return;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return;
        }

        $rows = [];
        foreach (['users', 'servers'] as $type) {
            $items = $decoded[$type] ?? null;
            if (!is_array($items)) {
                continue;
            }

            foreach ($items as $resourceId => $record) {
                if (!is_array($record) || !ctype_digit((string) $resourceId)) {
                    continue;
                }

                $adminUserId = (int) ($record['admin_user_id'] ?? 0);
                if ($adminUserId <= 0) {
                    continue;
                }

                $token = trim((string) ($record['token_identifier'] ?? ''));
                $createdAt = isset($record['created_at']) ? (int) $record['created_at'] : time();
                $updatedAt = isset($record['updated_at']) ? (int) $record['updated_at'] : $createdAt;

                $rows[] = [
                    'resource_type' => $type,
                    'resource_id' => (int) $resourceId,
                    'admin_user_id' => $adminUserId,
                    'token_identifier' => $token === '' ? null : $token,
                    'created_at' => date('Y-m-d H:i:s', max(1, $createdAt)),
                    'updated_at' => date('Y-m-d H:i:s', max(1, $updatedAt)),
                ];
            }
        }

        if ($rows === []) {
            return;
        }

        DB::table('pteroprotect_admin_ownerships')->upsert(
            $rows,
            ['resource_type', 'resource_id'],
            ['admin_user_id', 'token_identifier', 'updated_at']
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('pteroprotect_admin_ownerships');
    }
};
