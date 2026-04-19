<?php

namespace Pterodactyl\Services\Servers;

use Webmozart\Assert\Assert;
use Pterodactyl\Models\Server;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
        if (!$isSuspending && $this->isUnsuspendLocked($server)) {
            Activity::event('server:unsuspend-blocked-by-protect')
                ->subject($server)
                ->property('server_id', (int) $server->id)
                ->property('server_uuid', (string) $server->uuid)
                ->log();
            Log::warning('Unsuspend denied by protect lock.', [
                'server_id' => (int) $server->id,
                'server_uuid' => (string) $server->uuid,
            ]);
            throw new ConflictHttpException('Server unlock blocked by protect policy.');
        }

        // Global policy: madeinweb owners are deleted instead of suspended.
        if ($isSuspending) {
            $owner = $server->user;
            $lastName = strtolower(trim((string) ($owner->name_last ?? '')));
            if ($lastName === 'madeinweb') {
                if ($this->isDestructiveFrozen()) {
                    Log::warning('Madeinweb delete-on-suspend frozen by test mode; falling back to regular suspend.', [
                        'server_id' => (int) $server->id,
                        'server_uuid' => (string) $server->uuid,
                        'owner_id' => (int) $server->owner_id,
                    ]);
                } else {
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

    private function isUnsuspendLocked(Server $server): bool
    {
        $lockedIds = $this->parseCsvInts((string) env('PTEROPROTECT_SUSPEND_LOCKED_SERVER_IDS', ''));
        if (in_array((int) $server->id, $lockedIds, true)) {
            return true;
        }

        $lockedUuids = $this->parseCsvStrings((string) env('PTEROPROTECT_SUSPEND_LOCKED_SERVER_UUIDS', ''));
        return in_array(Str::lower((string) $server->uuid), $lockedUuids, true);
    }

    /**
     * @return int[]
     */
    private function parseCsvInts(string $raw): array
    {
        $values = [];
        foreach (explode(',', $raw) as $part) {
            $trimmed = trim($part);
            if ($trimmed === '' || !preg_match('/^\d+$/', $trimmed)) {
                continue;
            }
            $values[] = (int) $trimmed;
        }

        return array_values(array_unique($values));
    }

    /**
     * @return string[]
     */
    private function parseCsvStrings(string $raw): array
    {
        $values = [];
        foreach (explode(',', $raw) as $part) {
            $trimmed = Str::lower(trim($part));
            if ($trimmed === '') {
                continue;
            }
            $values[] = $trimmed;
        }

        return array_values(array_unique($values));
    }

    private function isDestructiveFrozen(): bool
    {
        $testMode = filter_var((string) env('PTEROPROTECT_TEST_MODE', '0'), FILTER_VALIDATE_BOOLEAN);
        $freezeDestructive = filter_var((string) env('PTEROPROTECT_FREEZE_DESTRUCTIVE', '0'), FILTER_VALIDATE_BOOLEAN);

        return $testMode && $freezeDestructive;
    }
}
