<?php

namespace Pterodactyl\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Schema;
use Pterodactyl\Models\ProtectCleanupRun;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Services\Servers\ServerDeletionService;
use Pterodactyl\Services\Users\UserDeletionService;
use Throwable;

class ExecuteProtectCleanupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 3600;

    public function __construct(private int $runId)
    {
    }

    public function handle(
        DaemonServerRepository $daemonServerRepository,
        ServerDeletionService $serverDeletionService,
        UserDeletionService $userDeletionService,
    ): void {
        $run = ProtectCleanupRun::query()->find($this->runId);
        if (!$run || $run->status !== ProtectCleanupRun::STATUS_PENDING) {
            return;
        }

        $run->forceFill([
            'status' => ProtectCleanupRun::STATUS_RUNNING,
            'started_at' => now(),
            'messages' => ['Cleanup started on server worker.'],
        ])->save();

        $ownerIds = [];
        $failures = [];

        try {
            Server::query()->with('user')->orderBy('id')->chunkById(25, function ($servers) use ($daemonServerRepository, $serverDeletionService, &$ownerIds, &$failures, $run) {
                foreach ($servers as $server) {
                    $run->increment('checked_servers');
                    $state = null;

                    try {
                        $details = $daemonServerRepository->setServer($server)->getDetails();
                        $state = strtolower((string) ($details['state'] ?? ''));
                    } catch (Throwable $exception) {
                        report($exception);
                    }

                    $isOffline = $state === 'offline' || (string) $server->status === Server::STATUS_SUSPENDED;
                    if (!$isOffline) {
                        $run->increment($state === null || $state === '' ? 'skipped_unverified' : 'skipped_online');
                        continue;
                    }

                    try {
                        $ownerIds[(int) $server->owner_id] = true;
                        $serverDeletionService->withForce(true)->handle($server);
                        $run->increment('deleted_servers');
                    } catch (Throwable $exception) {
                        report($exception);
                        $failures[] = sprintf('server #%d: %s', (int) $server->id, $exception->getMessage());
                        $run->increment('failed_count');
                        $this->flushMessages($run, $failures);
                    }
                }
            });

            $this->cleanupOwners($run, array_keys($ownerIds), $userDeletionService, $failures);

            $run->forceFill([
                'status' => $failures === [] ? ProtectCleanupRun::STATUS_SUCCESS : ProtectCleanupRun::STATUS_FAILED,
                'last_error_message' => $failures === [] ? null : implode(' | ', array_slice($failures, 0, 5)),
                'finished_at' => now(),
            ])->save();
            $this->flushMessages($run, $failures, true);
        } catch (Throwable $exception) {
            report($exception);
            $run->forceFill([
                'status' => ProtectCleanupRun::STATUS_FAILED,
                'last_error_message' => $exception->getMessage(),
                'finished_at' => now(),
            ])->save();
            throw $exception;
        }
    }

    /**
     * @param array<int,int> $ownerIds
     * @param array<int,string> $failures
     */
    private function cleanupOwners(ProtectCleanupRun $run, array $ownerIds, UserDeletionService $userDeletionService, array &$failures): void
    {
        if ($ownerIds !== []) {
            $adminOwners = User::query()
                ->whereIn('id', $ownerIds)
                ->where('root_admin', true)
                ->get();

            foreach ($adminOwners as $user) {
                $freshUser = User::query()->find((int) $user->id);
                if (!$freshUser) {
                    continue;
                }

                $run->increment('skipped_admins');
                if (Schema::hasColumn('users', 'madeinweb_panel_created_at') && $freshUser->madeinweb_panel_created_at !== null) {
                    $freshUser->forceFill(['madeinweb_panel_created_at' => null])->save();
                    $run->increment('reset_admin_markers');
                }
            }

            $skippedUsers = User::query()
                ->whereIn('id', $ownerIds)
                ->where('root_admin', false)
                ->whereExists(function ($query) {
                    $query->selectRaw('1')
                        ->from('servers')
                        ->whereColumn('servers.owner_id', 'users.id');
                })
                ->count();
            $run->increment('skipped_users', $skippedUsers);
        }

        User::query()
            ->where('root_admin', false)
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('servers')
                    ->whereColumn('servers.owner_id', 'users.id');
            })
            ->orderBy('id')
            ->chunkById(50, function ($users) use ($userDeletionService, &$failures, $run) {
                foreach ($users as $user) {
                    try {
                        $userDeletionService->handle($user);
                        $run->increment('deleted_users');
                    } catch (Throwable $exception) {
                        report($exception);
                        $failures[] = sprintf('user #%d: %s', (int) $user->id, $exception->getMessage());
                        $run->increment('failed_count');
                        $this->flushMessages($run, $failures);
                    }
                }
            });
    }

    /** @param array<int,string> $failures */
    private function flushMessages(ProtectCleanupRun $run, array $failures, bool $final = false): void
    {
        $messages = array_slice($failures, -8);
        if ($final) {
            array_unshift($messages, 'Cleanup finished.');
        }
        $run->forceFill(['messages' => $messages])->save();
    }
}
