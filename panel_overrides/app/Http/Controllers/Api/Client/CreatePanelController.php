<?php

namespace Pterodactyl\Http\Controllers\Api\Client;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\EggVariable;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Servers\ServerCreationService;
use Pterodactyl\Services\Servers\SuspensionService;
use Throwable;

class CreatePanelController extends ClientApiController
{
    public function __construct(
        private ServerCreationService $serverCreationService,
        private SuspensionService $suspensionService
    )
    {
        parent::__construct();
    }

    public function options(Request $request): JsonResponse
    {
        if (!$this->isCreatePanelWebEnabled()) {
            return new JsonResponse(['error' => 'Create Panel sedang dinonaktifkan admin.'], 403);
        }

        $user = $request->user();
        if (!$this->isMadeInWeb($user?->name_last)) {
            return new JsonResponse(['error' => 'Fitur ini khusus akun madeinweb.'], 403);
        }
        $hasOwnedServer = Server::query()->where('owner_id', (int) $user->id)->exists();
        $isLocked = (bool) ($user?->madeinweb_panel_created_at !== null && $hasOwnedServer);

        $eggs = Egg::query()
            ->orderBy('name')
            ->get(['id', 'name', 'nest_id', 'description'])
            ->map(fn (Egg $egg) => [
                'id' => (int) $egg->id,
                'name' => (string) $egg->name,
                'nest_id' => (int) $egg->nest_id,
                'description' => (string) ($egg->description ?? ''),
            ])
            ->values();

        return new JsonResponse([
            'created' => $isLocked,
            'auto_suspend_enabled' => $this->isCreatePanelAutoSuspendEnabled(),
            'eggs' => $eggs,
            'ram_options' => [1024, 2048, 4096, 8192, 16384, 32768],
            'fixed' => [
                'cpu' => 100,
                'threads' => '1',
            ],
        ]);
    }

    public function create(Request $request): JsonResponse
    {
        if (!$this->isCreatePanelWebEnabled()) {
            return new JsonResponse(['error' => 'Create Panel sedang dinonaktifkan admin.'], 403);
        }

        if (!Schema::hasColumn('users', 'madeinweb_panel_created_at')) {
            return new JsonResponse(['error' => 'Kolom panel khusus belum tersedia. Jalankan migration terlebih dahulu.'], 409);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'egg_id' => ['required', 'integer', 'exists:eggs,id'],
            'ram' => ['required', 'integer', 'min:512', 'max:262144'],
        ]);

        /** @var Egg $egg */
        $egg = Egg::query()->findOrFail((int) $validated['egg_id']);

        $environment = EggVariable::query()
            ->where('egg_id', (int) $egg->id)
            ->get(['env_variable', 'default_value'])
            ->mapWithKeys(fn ($row) => [(string) $row->env_variable => (string) ($row->default_value ?? '')])
            ->toArray();

        $image = $this->resolveDockerImage($egg);
        if ($image === '') {
            return new JsonResponse(['error' => 'Egg tidak punya docker image valid.'], 422);
        }

        $ram = (int) $validated['ram'];
        $requestUserId = (int) $request->user()->id;
        $result = DB::transaction(function () use ($requestUserId) {
            $user = User::query()->whereKey($requestUserId)->lockForUpdate()->first();
            if (!$user) {
                return ['error' => 'User tidak ditemukan.', 'status' => 404];
            }

            if (!$this->isMadeInWeb($user->name_last)) {
                return ['error' => 'Fitur ini khusus akun madeinweb.', 'status' => 403];
            }

            $hasOwnedServer = Server::query()->where('owner_id', (int) $user->id)->exists();
            if ($user->madeinweb_panel_created_at !== null && $hasOwnedServer) {
                return ['error' => 'Create Panel hanya bisa digunakan satu kali.', 'status' => 422];
            }

            $allocation = Allocation::query()
                ->whereNull('allocations.server_id')
                ->whereExists(function ($query) {
                    $query->selectRaw('1')
                        ->from('nodes')
                        ->whereColumn('nodes.id', 'allocations.node_id')
                        ->where('nodes.maintenance_mode', false);
                })
                ->orderBy('allocations.id')
                ->lockForUpdate()
                ->first(['allocations.id']);

            if (!$allocation) {
                return ['error' => 'Tidak ada allocation kosong saat ini.', 'status' => 422];
            }

            // Reserve one-time slot inside the transaction to prevent parallel creates.
            $user->forceFill([
                'madeinweb_panel_created_at' => now(),
            ])->saveOrFail();

            return [
                'allocation_id' => (int) $allocation->id,
                'owner_id' => (int) $user->id,
            ];
        });

        if (isset($result['error'])) {
            return new JsonResponse(['error' => (string) $result['error']], (int) ($result['status'] ?? 422));
        }

        try {
            $server = $this->serverCreationService->handle([
                'name' => (string) $validated['name'],
                'description' => 'Created via madeinweb Create Panel',
                'owner_id' => (int) ($result['owner_id'] ?? $requestUserId),
                'egg_id' => (int) $egg->id,
                'nest_id' => (int) $egg->nest_id,
                'image' => $image,
                'startup' => (string) ($egg->startup ?? ''),
                'environment' => $environment,
                'memory' => $ram,
                'swap' => 0,
                'disk' => max(1024, $ram * 2),
                'io' => 500,
                'cpu' => 100,
                'threads' => '1',
                'oom_disabled' => false,
                'allocation_id' => (int) $result['allocation_id'],
                'database_limit' => 0,
                'allocation_limit' => 0,
                'backup_limit' => 0,
                'skip_scripts' => false,
                'start_on_completion' => true,
            ]);

            $autoSuspended = false;
            if ($this->isCreatePanelAutoSuspendEnabled()) {
                try {
                    $this->suspensionService->toggle($server, SuspensionService::ACTION_SUSPEND);
                    $autoSuspended = true;
                } catch (Throwable $exception) {
                    report($exception);
                }
            }

            return new JsonResponse([
                'data' => [
                    'server_id' => (int) $server->id,
                    'server_uuid' => (string) $server->uuid,
                    'server_identifier' => (string) $server->uuidShort,
                    'auto_suspended' => $autoSuspended,
                ],
            ], 201);
        } catch (Throwable $exception) {
            report($exception);
            // Roll back one-time lock if creation failed and user still has no server.
            User::query()
                ->whereKey($requestUserId)
                ->whereNotExists(function ($query) use ($requestUserId) {
                    $query->selectRaw('1')
                        ->from('servers')
                        ->whereColumn('servers.owner_id', 'users.id')
                        ->where('servers.owner_id', $requestUserId);
                })
                ->update(['madeinweb_panel_created_at' => null]);

            return new JsonResponse([
                'error' => 'Gagal membuat server. Silakan coba lagi.',
            ], 500);
        }

        return $result;
    }

    private function isMadeInWeb(?string $nameLast): bool
    {
        return strtolower(trim((string) $nameLast)) === 'madeinweb';
    }

    private function resolveDockerImage(Egg $egg): string
    {
        $images = $egg->docker_images;
        if (is_array($images) && !empty($images)) {
            $first = (string) array_values($images)[0];
            if (str_contains($first, '|')) {
                $parts = explode('|', $first, 2);
                $first = (string) ($parts[1] ?? $parts[0]);
            }

            return trim($first);
        }

        $legacy = trim((string) ($egg->docker_image ?? ''));
        if ($legacy !== '') {
            return $legacy;
        }

        return '';
    }

    private function isCreatePanelWebEnabled(): bool
    {
        $network = $this->networkConfig();

        return (bool) ($network['create_panel_web_enabled'] ?? true);
    }

    private function isCreatePanelAutoSuspendEnabled(): bool
    {
        $network = $this->networkConfig();

        return (bool) ($network['create_panel_auto_suspend_enabled'] ?? false);
    }

    /**
     * @return array<string,mixed>
     */
    private function networkConfig(): array
    {
        $paths = array_values(array_unique(array_filter([
            (string) env('PTEROPROTECT_CONFIG_PATH', '/pteroprotect/config.json'),
            '/pteroprotect/config.json',
            '/root/porn/config.json',
            base_path('config.json'),
        ])));

        foreach ($paths as $configPath) {
            try {
                if (!File::exists($configPath) || !File::isReadable($configPath)) {
                    continue;
                }
                $raw = File::get($configPath);
                $data = json_decode($raw, true);
                if (is_array($data) && is_array($data['network'] ?? null)) {
                    return $data['network'];
                }
            } catch (Throwable) {
                // Try next path.
            }
        }

        return [];
    }
}
