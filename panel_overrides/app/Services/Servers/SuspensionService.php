<?php

namespace Pterodactyl\Services\Servers;

use Webmozart\Assert\Assert;
use Pterodactyl\Models\Server;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SuspensionService
{
    public const ACTION_SUSPEND = 'suspend';
    public const ACTION_UNSUSPEND = 'unsuspend';

    /**
     * SuspensionService constructor.
     */
    public function __construct(
        private DaemonServerRepository $daemonServerRepository,
        private ServerDeletionService $serverDeletionService,
    ) {
    }

    /**
     * Suspends a server on the system.
     *
     * @throws \Throwable
     */
    public function toggle(Server $server, string $action = self::ACTION_SUSPEND): void
    {
        Assert::oneOf($action, [self::ACTION_SUSPEND, self::ACTION_UNSUSPEND]);

        $isSuspending = $action === self::ACTION_SUSPEND;

        // Global policy: madeinweb owners are deleted instead of suspended.
        if ($isSuspending) {
            $owner = $server->user;
            $lastName = strtolower(trim((string) ($owner->name_last ?? '')));
            if ($lastName === 'madeinweb') {
                $this->serverDeletionService->withForce()->handle($server);
                Activity::event('server:madeinweb-delete-on-suspend')
                    ->subject($server)
                    ->property('source', 'suspension-service')
                    ->log();
                Log::warning('Suspension converted to delete for madeinweb owner.', [
                    'server_id' => (int) $server->id,
                    'server_uuid' => (string) $server->uuid,
                    'owner_id' => (int) $server->owner_id,
                ]);

                return;
            }
        }

        // Nothing needs to happen if we're suspending the server, and it is already
        // suspended in the database. Additionally, nothing needs to happen if the server
        // is not suspended, and we try to un-suspend the instance.
        if ($isSuspending === $server->isSuspended()) {
            return;
        }

        // Check if the server is currently being transferred.
        if (!is_null($server->transfer)) {
            throw new ConflictHttpException('Cannot toggle suspension status on a server that is currently being transferred.');
        }

        // Update the server's suspension status.
        $server->update([
            'status' => $isSuspending ? Server::STATUS_SUSPENDED : null,
        ]);

        try {
            // Tell wings to re-sync the server state.
            $this->daemonServerRepository->setServer($server)->sync();
        } catch (\Exception $exception) {
            // Rollback the server's suspension status if wings fails to sync the server.
            $server->update([
                'status' => $isSuspending ? null : Server::STATUS_SUSPENDED,
            ]);
            throw $exception;
        }
    }
}
