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
use Pterodactyl\Exceptions\Service\Deployment\NoViableAllocationException;
use Pterodactyl\Exceptions\Service\Deployment\NoViableNodeException;
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

        $allowedEggIds = $this->allowedCreatePanelEggIds();
        $ramOptions = $this->createPanelRamOptions();

        $eggs = Egg::query()
            ->when($allowedEggIds !== [], fn ($query) => $query->whereIn('id', $allowedEggIds))
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
            'ram_options' => $ramOptions,
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

        $ramOptions = $this->createPanelRamOptions();
        $maxRam = max($ramOptions ?: [0]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'egg_id' => ['required', 'integer', 'exists:eggs,id'],
            'ram' => ['required', 'integer', 'min:512', 'max:' . $maxRam],
        ]);

        $allowedEggIds = $this->allowedCreatePanelEggIds();
        if ($allowedEggIds !== [] && !in_array((int) $validated['egg_id'], $allowedEggIds, true)) {
            return new JsonResponse(['error' => 'Egg tidak diizinkan untuk Create Panel.'], 422);
        }

        if (!in_array((int) $validated['ram'], $ramOptions, true)) {
            return new JsonResponse(['error' => 'Pilihan RAM tidak diizinkan.'], 422);
        }

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
        $disk = max(1024, $ram * 2);
        $requestUserId = (int) $request->user()->id;
        $result = DB::transaction(function () use ($requestUserId, $ram, $disk) {
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
                ->select(['allocations.id', 'allocations.node_id'])
                ->join('nodes', 'nodes.id', '=', 'allocations.node_id')
                ->whereNull('allocations.server_id')
                ->where('nodes.public', true)
                ->where('nodes.maintenance_mode', false)
                ->whereRaw(
                    '(SELECT COALESCE(SUM(servers.memory), 0) FROM servers WHERE servers.node_id = nodes.id) + ? <= (nodes.memory * (1 + (nodes.memory_overallocate / 100)))',
                    [$ram]
                )
                ->whereRaw(
                    '(SELECT COALESCE(SUM(servers.disk), 0) FROM servers WHERE servers.node_id = nodes.id) + ? <= (nodes.disk * (1 + (nodes.disk_overallocate / 100)))',
                    [$disk]
                )
                ->orderBy('allocations.id')
                ->lockForUpdate()
                ->first();

            if (!$allocation) {
                return ['error' => 'Tidak ada node/allocation kosong yang cukup untuk RAM dan disk pilihan ini.', 'status' => 422];
            }

            // Reserve one-time slot inside the transaction to prevent parallel creates.
            $user->forceFill([
                'madeinweb_panel_created_at' => now(),
            ])->saveOrFail();

            return [
                'allocation_id' => (int) $allocation->id,
                'node_id' => (int) $allocation->node_id,
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
                'node_id' => (int) $result['node_id'],
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
        } catch (NoViableNodeException|NoViableAllocationException $exception) {
            report($exception);
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
                'error' => 'Tidak ada node/allocation yang viable untuk pilihan ini. Coba RAM lebih kecil atau tambah allocation kosong.',
            ], 422);
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

        return (bool) ($network['create_panel_web_enabled'] ?? false);
    }

    private function isCreatePanelAutoSuspendEnabled(): bool
    {
        $network = $this->networkConfig();

        return (bool) ($network['create_panel_auto_suspend_enabled'] ?? true);
    }

    /**
     * @return array<int,int>
     */
    private function allowedCreatePanelEggIds(): array
    {
        $raw = $this->networkConfig()['create_panel_allowed_egg_ids'] ?? [];
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(static fn ($id) => (int) $id, $raw), static fn ($id) => $id > 0)));
    }

    /**
     * @return array<int,int>
     */
    private function createPanelRamOptions(): array
    {
        $network = $this->networkConfig();
        $raw = $network['create_panel_ram_options_mb'] ?? [1024, 2048, 4096, 8192, 16384, 32768];
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (!is_array($raw)) {
            $raw = [];
        }

        $max = (int) ($network['create_panel_max_ram_mb'] ?? 32768);
        $max = max(512, min(32768, $max));
        $options = array_map(static fn ($value) => (int) $value, $raw);
        $options = array_filter($options, static fn ($value) => $value >= 512 && $value <= $max);
        sort($options);

        return array_values(array_unique($options));
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
