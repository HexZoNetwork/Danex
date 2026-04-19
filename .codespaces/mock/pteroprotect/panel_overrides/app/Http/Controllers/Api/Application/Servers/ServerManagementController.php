<?php

namespace Pterodactyl\Http\Controllers\Api\Application\Servers;

use Illuminate\Http\Response;
use Pterodactyl\Models\Server;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Services\PteroProtect\AdminOwnershipService;
use Pterodactyl\Services\Servers\SuspensionService;
use Pterodactyl\Services\Servers\ReinstallServerService;
use Pterodactyl\Http\Requests\Api\Application\Servers\ServerWriteRequest;
use Pterodactyl\Http\Controllers\Api\Application\ApplicationApiController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ServerManagementController extends ApplicationApiController
{
    /**
     * ServerManagementController constructor.
     */
    public function __construct(
        private ReinstallServerService $reinstallServerService,
        private SuspensionService $suspensionService,
        private AdminOwnershipService $ownership,
    ) {
        parent::__construct();
    }

    /**
     * Suspend a server on the Panel.
     *
     * @throws \Throwable
     */
    public function suspend(ServerWriteRequest $request, Server $server): Response
    {
        $this->denyIfNotOwned($request, $server);

        $this->suspensionService->toggle($server);
        Activity::event('server:application.suspend')
            ->subject($server)
            ->withRequestMetadata()
            ->property('source', 'application-api')
            ->log();

        return $this->returnNoContent();
    }

    /**
     * Unsuspend a server on the Panel.
     *
     * @throws \Throwable
     */
    public function unsuspend(ServerWriteRequest $request, Server $server): Response
    {
        $this->denyIfNotOwned($request, $server);

        $this->suspensionService->toggle($server, SuspensionService::ACTION_UNSUSPEND);
        Activity::event('server:application.unsuspend')
            ->subject($server)
            ->withRequestMetadata()
            ->property('source', 'application-api')
            ->log();

        return $this->returnNoContent();
    }

    /**
     * Mark a server as needing to be reinstalled.
     *
     * @throws \Pterodactyl\Exceptions\DisplayException
     * @throws \Pterodactyl\Exceptions\Model\DataValidationException
     * @throws \Pterodactyl\Exceptions\Repository\RecordNotFoundException
     */
    public function reinstall(ServerWriteRequest $request, Server $server): Response
    {
        $this->denyIfNotOwned($request, $server);

        $this->reinstallServerService->handle($server);

        return $this->returnNoContent();
    }

    private function denyIfNotOwned(ServerWriteRequest $request, Server $server): void
    {
        $adminId = (int) $request->user()->id;
        if ($adminId === 1) {
            return;
        }
        if ((int) $server->owner_id === 1) {
            throw new AccessDeniedHttpException('Primary admin resources cannot be modified.');
        }
        if (!$this->ownership->isOwnedBy('servers', (int) $server->id, $adminId, $this->tokenIdentifier($request))) {
            throw new AccessDeniedHttpException('You do not own this server resource.');
        }
    }

    private function tokenIdentifier(ServerWriteRequest $request): ?string
    {
        $token = $request->user()?->currentAccessToken();
        if (!is_object($token) || !property_exists($token, 'identifier')) {
            return null;
        }

        $identifier = trim((string) $token->identifier);

        return $identifier === '' ? null : $identifier;
    }
}
