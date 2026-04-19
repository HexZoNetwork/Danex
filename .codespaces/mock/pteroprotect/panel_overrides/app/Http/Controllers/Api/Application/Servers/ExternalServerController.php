<?php

namespace Pterodactyl\Http\Controllers\Api\Application\Servers;

use Pterodactyl\Models\Server;
use Pterodactyl\Services\PteroProtect\AdminOwnershipService;
use Pterodactyl\Transformers\Api\Application\ServerTransformer;
use Pterodactyl\Http\Controllers\Api\Application\ApplicationApiController;
use Pterodactyl\Http\Requests\Api\Application\Servers\GetExternalServerRequest;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ExternalServerController extends ApplicationApiController
{
    public function __construct(private AdminOwnershipService $ownership)
    {
        parent::__construct();
    }

    public function index(GetExternalServerRequest $request, string $external_id): array
    {
        $server = Server::query()->where('external_id', $external_id)->firstOrFail();
        if ((int) $request->user()->id !== 1 && (int) $server->owner_id === 1) {
            throw new AccessDeniedHttpException('Primary admin resources cannot be modified.');
        }

        if ((int) $request->user()->id !== 1 && !$this->ownership->isOwnedBy('servers', (int) $server->id, (int) $request->user()->id, $this->tokenIdentifier($request))) {
            throw new AccessDeniedHttpException('You do not own this server resource.');
        }

        return $this->fractal->item($server)
            ->transformWith($this->getTransformer(ServerTransformer::class))
            ->toArray();
    }

    private function tokenIdentifier(GetExternalServerRequest $request): ?string
    {
        $token = $request->user()?->currentAccessToken();
        if (!is_object($token) || !property_exists($token, 'identifier')) {
            return null;
        }

        $identifier = trim((string) $token->identifier);

        return $identifier === '' ? null : $identifier;
    }
}
