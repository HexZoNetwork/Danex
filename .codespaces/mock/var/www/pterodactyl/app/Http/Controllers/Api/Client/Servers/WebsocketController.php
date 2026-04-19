<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Throwable;
use Carbon\CarbonImmutable;
use Pterodactyl\Models\Server;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Models\Permission;
use Pterodactyl\Services\Nodes\NodeJWTService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Pterodactyl\Exceptions\Http\HttpForbiddenException;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;
use Pterodactyl\Services\Servers\GetUserPermissionsService;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;

class WebsocketController extends ClientApiController
{
    /**
     * WebsocketController constructor.
     */
    public function __construct(
        private NodeJWTService $jwtService,
        private GetUserPermissionsService $permissionsService,
        private DaemonServerRepository $daemonServerRepository,
    ) {
        parent::__construct();
    }

    /**
     * Generates a one-time token that is sent along in every websocket call to the Daemon.
     * This is a signed JWT that the Daemon then uses to verify the user's identity, and
     * allows us to continually renew this token and avoid users maintaining sessions wrongly,
     * as well as ensure that user's only perform actions they're allowed to.
     */
    public function __invoke(ClientApiRequest $request, Server $server): JsonResponse
    {
        $user = $request->user();
        if ($user->cannot(Permission::ACTION_WEBSOCKET_CONNECT, $server)) {
            throw new HttpForbiddenException('You do not have permission to connect to this server\'s websocket.');
        }

        // Prevent endless browser websocket reconnect loops when panel and wings are out of sync.
        try {
            $this->daemonServerRepository->setServer($server)->getDetails();
        } catch (Throwable) {
            throw new HttpException(503, 'Node is offline or this server is not synchronized to Wings yet.');
        }

        $permissions = $this->permissionsService->handle($server, $user);

        $node = $server->node;
        if (!is_null($server->transfer)) {
            // Check if the user has permissions to receive transfer logs.
            if (!in_array('admin.websocket.transfer', $permissions)) {
                throw new HttpForbiddenException('You do not have permission to view server transfer logs.');
            }

            // Redirect the websocket request to the new node if the server has been archived.
            if ($server->transfer->archived) {
                $node = $server->transfer->newNode;
            }
        }

        $token = $this->jwtService
            ->setExpiresAt(CarbonImmutable::now()->addMinutes(10))
            ->setUser($request->user())
            ->setClaims([
                'server_uuid' => $server->uuid,
                'permissions' => $permissions,
            ])
            ->handle($node, $user->id . $server->uuid);

        $socket = str_replace(['https://', 'http://'], ['wss://', 'ws://'], $node->getConnectionAddress());

        return new JsonResponse([
            'data' => [
                'token' => $token->toString(),
                'socket' => $socket . sprintf('/api/servers/%s/ws', $server->uuid),
            ],
        ]);
    }
}
