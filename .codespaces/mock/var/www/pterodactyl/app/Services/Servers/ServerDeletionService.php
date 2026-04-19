<?php

namespace Pterodactyl\Services\Servers;

use Illuminate\Http\Response;
use Pterodactyl\Models\Server;
use Illuminate\Support\Facades\Log;
use Throwable;
use Illuminate\Database\ConnectionInterface;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Services\Databases\DatabaseManagementService;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;

class ServerDeletionService
{
    protected bool $force = false;

    /**
     * ServerDeletionService constructor.
     */
    public function __construct(
        private ConnectionInterface $connection,
        private DaemonServerRepository $daemonServerRepository,
        private DatabaseManagementService $databaseManagementService,
    ) {
    }

    /**
     * Set if the server should be forcibly deleted from the panel (ignoring daemon errors) or not.
     */
    public function withForce(bool $bool = true): self
    {
        $this->force = $bool;

        return $this;
    }

    /**
     * Delete a server from the panel, clear any allocation notes, and remove any associated databases from hosts.
     *
     * @throws \Throwable
     * @throws \Pterodactyl\Exceptions\DisplayException
     */
    public function handle(Server $server): void
    {
        try {
            $this->daemonServerRepository->setServer($server)->delete();
        } catch (Throwable $exception) {
            $status = $this->resolveStatusCode($exception);
            $isSafeNotFound = $status === Response::HTTP_NOT_FOUND;

            // Keep legacy behavior: 404 on daemon is safe to ignore.
            // When force delete is enabled, continue deletion even if daemon call fails
            // (for example when server state is already missing or daemon is inconsistent).
            if (!$this->force && !$isSafeNotFound) {
                throw $exception;
            }

            Log::warning('Ignoring daemon deletion failure and continuing panel delete.', [
                'server_id' => (int) $server->id,
                'server_uuid' => (string) $server->uuid,
                'force' => $this->force,
                'status' => $status,
                'error' => $exception->getMessage(),
            ]);
        }

        $this->connection->transaction(function () use ($server) {
            foreach ($server->databases as $database) {
                try {
                    $this->databaseManagementService->delete($database);
                } catch (\Exception $exception) {
                    if (!$this->force) {
                        throw $exception;
                    }

                    // Oh well, just try to delete the database entry we have from the database
                    // so that the server itself can be deleted. This will leave it dangling on
                    // the host instance, but we couldn't delete it anyways so not sure how we would
                    // handle this better anyways.
                    //
                    // @see https://github.com/pterodactyl/panel/issues/2085
                    $database->delete();

                    Log::warning($exception);
                }
            }

            // clear any allocation notes for the server
            $server->allocations()->update(['notes' => null]);


            $server->delete();
        });
    }

    private function resolveStatusCode(Throwable $exception): ?int
    {
        if ($exception instanceof DaemonConnectionException) {
            return $exception->getStatusCode();
        }

        if (method_exists($exception, 'getStatusCode')) {
            try {
                return (int) $exception->getStatusCode();
            } catch (Throwable) {
                // no-op
            }
        }

        if (method_exists($exception, 'getResponse')) {
            try {
                $response = $exception->getResponse();
                if ($response && method_exists($response, 'getStatusCode')) {
                    return (int) $response->getStatusCode();
                }
            } catch (Throwable) {
                // no-op
            }
        }

        return null;
    }
}
