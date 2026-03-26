<?php

namespace Pterodactyl\Http\Controllers\Api\Client;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\EggVariable;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Servers\ServerCreationService;

class CreatePanelController extends ClientApiController
{
    public function __construct(private ServerCreationService $serverCreationService)
    {
        parent::__construct();
    }

    public function options(Request $request): JsonResponse
    {
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
        if (!Schema::hasColumn('users', 'madeinweb_panel_created_at')) {
            return new JsonResponse(['error' => 'Kolom panel khusus belum tersedia. Jalankan migration terlebih dahulu.'], 409);
        }

        $user = $request->user()->fresh();
        if (!$this->isMadeInWeb($user?->name_last)) {
            return new JsonResponse(['error' => 'Fitur ini khusus akun madeinweb.'], 403);
        }
        $hasOwnedServer = Server::query()->where('owner_id', (int) $user->id)->exists();
        if ($user?->madeinweb_panel_created_at !== null && $hasOwnedServer) {
            return new JsonResponse(['error' => 'Create Panel hanya bisa digunakan satu kali.'], 422);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'egg_id' => ['required', 'integer', 'exists:eggs,id'],
            'ram' => ['required', 'integer', 'min:512', 'max:262144'],
        ]);

        /** @var Egg $egg */
        $egg = Egg::query()->findOrFail((int) $validated['egg_id']);

        $allocation = Allocation::query()
            ->select('allocations.id')
            ->join('nodes', 'nodes.id', '=', 'allocations.node_id')
            ->whereNull('allocations.server_id')
            ->where('nodes.maintenance_mode', false)
            ->orderBy('allocations.id')
            ->first();

        if (!$allocation) {
            return new JsonResponse(['error' => 'Tidak ada allocation kosong saat ini.'], 422);
        }

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
        $server = $this->serverCreationService->handle([
            'name' => (string) $validated['name'],
            'description' => 'Created via madeinweb Create Panel',
            'owner_id' => (int) $user->id,
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
            'allocation_id' => (int) $allocation->id,
            'database_limit' => 0,
            'allocation_limit' => 0,
            'backup_limit' => 0,
            'skip_scripts' => false,
            'start_on_completion' => true,
        ]);

        $user->forceFill([
            'madeinweb_panel_created_at' => now(),
        ])->saveOrFail();

        return new JsonResponse([
            'data' => [
                'server_id' => (int) $server->id,
                'server_uuid' => (string) $server->uuid,
                'server_identifier' => (string) $server->uuidShort,
            ],
        ], 201);
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
}
