<?php

namespace Pterodactyl\Http\Controllers\Api\Application\Servers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\PteroProtect\AdminOwnershipService;
use Pterodactyl\Services\Servers\ServerCreationService;
use Pterodactyl\Services\Servers\ServerDeletionService;
use Pterodactyl\Transformers\Api\Application\ServerTransformer;
use Pterodactyl\Http\Controllers\Api\Application\ApplicationApiController;
use Pterodactyl\Http\Requests\Api\Application\Servers\GetServerRequest;
use Pterodactyl\Http\Requests\Api\Application\Servers\GetServersRequest;
use Pterodactyl\Http\Requests\Api\Application\Servers\ServerWriteRequest;
use Pterodactyl\Http\Requests\Api\Application\Servers\StoreServerRequest;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ServerController extends ApplicationApiController
{
    public function __construct(
        private ServerCreationService $creationService,
        private ServerDeletionService $deletionService,
        private AdminOwnershipService $ownership,
    ) {
        parent::__construct();
    }

    public function index(GetServersRequest $request): array
    {
        if ((int) $request->user()->id === 1) {
            $servers = QueryBuilder::for(Server::query())
                ->allowedFilters(['uuid', 'uuidShort', 'name', 'description', 'image', 'external_id'])
                ->allowedSorts(['id', 'uuid'])
                ->paginate($request->query('per_page') ?? 50);

            return $this->fractal->collection($servers)
                ->transformWith($this->getTransformer(ServerTransformer::class))
                ->toArray();
        }

        $owned = $request->attributes->get('pteroprotect_owned_server_ids');
        if (!is_array($owned)) {
            $owned = $this->ownership->ownedIdsFor('servers', (int) $request->user()->id, $this->tokenIdentifier($request));
        }

        $query = Server::query();
        if ($owned === []) {
            $query->whereRaw('1 = 0');
        } else {
            $query->whereIn('id', $owned);
        }

        $servers = QueryBuilder::for($query)
            ->allowedFilters(['uuid', 'uuidShort', 'name', 'description', 'image', 'external_id'])
            ->allowedSorts(['id', 'uuid'])
            ->paginate($request->query('per_page') ?? 50);

        return $this->fractal->collection($servers)
            ->transformWith($this->getTransformer(ServerTransformer::class))
            ->toArray();
    }

    public function store(StoreServerRequest $request): JsonResponse
    {
        $ownerId = (int) $request->input('user', 0);
        if ((int) $request->user()->id !== 1 && $ownerId === 1) {
            throw new AccessDeniedHttpException('Cannot create or modify resources owned by primary admin.');
        }
        if ($ownerId <= 0) {
            throw new AccessDeniedHttpException('Invalid server owner.');
        }
        $server = $this->creationService->handle($request->validated(), $request->getDeploymentObject());
        $this->ownership->remember('servers', (int) $server->id, (int) $request->user()->id, $this->tokenIdentifier($request));

        return $this->fractal->item($server)
            ->transformWith($this->getTransformer(ServerTransformer::class))
            ->respond(201);
    }

    public function view(GetServerRequest $request, Server $server): array
    {
        $this->denyIfNotOwned($request, $server);

        return $this->fractal->item($server)
            ->transformWith($this->getTransformer(ServerTransformer::class))
            ->toArray();
    }

    public function delete(ServerWriteRequest $request, Server $server, string $force = ''): Response
    {
        $this->denyIfNotOwned($request, $server);

        $this->deletionService->withForce($force === 'force')->handle($server);
        $this->ownership->forget('servers', (int) $server->id);

        return $this->returnNoContent();
    }

    private function tokenIdentifier(GetServersRequest|StoreServerRequest|GetServerRequest|ServerWriteRequest $request): ?string
    {
        $token = $request->user()?->currentAccessToken();
        if (!is_object($token) || !property_exists($token, 'identifier')) {
            return null;
        }

        $identifier = trim((string) $token->identifier);

        return $identifier === '' ? null : $identifier;
    }

    private function denyIfNotOwned(GetServerRequest|ServerWriteRequest $request, Server $server): void
    {
        if ((int) $request->user()->id === 1) {
            return;
        }
        if ((int) $server->owner_id === 1) {
            throw new AccessDeniedHttpException('Primary admin resources cannot be modified.');
        }
        if (!$this->ownership->isOwnedBy('servers', (int) $server->id, (int) $request->user()->id, $this->tokenIdentifier($request))) {
            throw new AccessDeniedHttpException('You do not own this server resource.');
        }
    }
}
