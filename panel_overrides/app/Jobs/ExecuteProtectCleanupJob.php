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

        $requester = User::query()->find((int) $run->user_id);
        if (!$requester || !$requester->root_admin) {
            $run->forceFill([
                'status' => ProtectCleanupRun::STATUS_FAILED,
                'last_error_message' => 'Cleanup requires a root administrator request.',
                'finished_at' => now(),
                'messages' => ['Refused cleanup: requester is not a root administrator.'],
            ])->save();
            return;
        }

        $dryRun = $run->dry_run === null ? true : (bool) $run->dry_run;
        $allowUserDeletion = (bool) ($run->allow_user_deletion ?? false);
        $graceSeconds = 900;

        $run->forceFill([
            'status' => ProtectCleanupRun::STATUS_RUNNING,
            'started_at' => now(),
            'messages' => [sprintf(
                'Cleanup started on server worker (%s mode).',
                $dryRun ? 'dry-run' : 'destructive'
            )],
        ])->save();

        $ownerIds = [];
        $failures = [];
        $notes = [];

        try {
            Server::query()->with('user')->orderBy('id')->chunkById(25, function ($servers) use ($daemonServerRepository, $serverDeletionService, &$ownerIds, &$failures, &$notes, $run, $dryRun, $graceSeconds) {
                foreach ($servers as $server) {
                    $run->increment('checked_servers');
                    $state = null;

                    try {
                        $details = $daemonServerRepository->setServer($server)->getDetails();
                        $state = strtolower((string) ($details['state'] ?? ''));
                    } catch (Throwable $exception) {
                        report($exception);
                    }

                    $isPanelCreated = str_contains((string) $server->description, 'Created via madeinweb Create Panel');
                    $owner = $server->user;
                    $isPanelOwner = $owner !== null && $owner->madeinweb_panel_created_at !== null;
                    $isRootOwner = $owner !== null && (bool) $owner->root_admin;
                    $isRecent = $server->created_at !== null
                        && $server->created_at->greaterThan(now()->subSeconds($graceSeconds));
                    $isOffline = $state === 'offline';
                    $isSuspended = (string) $server->status === Server::STATUS_SUSPENDED;

                    // The description marker is user-editable, so never delete a
                    // server unless its owner is also panel-created and non-admin.
                    if (!$isPanelCreated || !$isPanelOwner || $isRootOwner) {
                        $run->increment('skipped_unverified');
                        $notes[] = sprintf('server #%d skipped: marker/owner not eligible', (int) $server->id);
                        $this->flushMessages($run, $failures, $notes);
                        continue;
                    }
                    if ($isRecent) {
                        $run->increment('skipped_unverified');
                        $notes[] = sprintf('server #%d skipped: within %d second grace window', (int) $server->id, $graceSeconds);
                        $this->flushMessages($run, $failures, $notes);
                        continue;
                    }
                    if (!$isOffline || !$isSuspended) {
                        $run->increment($state === null || $state === '' ? 'skipped_unverified' : 'skipped_online');
                        continue;
                    }

                    if ($dryRun) {
                        $notes[] = sprintf(
                            'dry-run: would delete server #%d (owner #%d, state %s)',
                            (int) $server->id,
                            (int) $server->owner_id,
                            $state
                        );
                        $this->flushMessages($run, $failures, $notes);
                        continue;
                    }

                    try {
                        $ownerIds[(int) $server->owner_id] = true;
                        $serverDeletionService->withForce(true)->handle($server);
                        $run->increment('deleted_servers');
                        $notes[] = sprintf('deleted server #%d (owner #%d)', (int) $server->id, (int) $server->owner_id);
                    } catch (Throwable $exception) {
                        report($exception);
                        $failures[] = sprintf('server #%d: %s', (int) $server->id, $exception->getMessage());
                        $run->increment('failed_count');
                        $this->flushMessages($run, $failures, $notes);
                    }
                }
            });

            if ($allowUserDeletion && !$dryRun) {
                $this->cleanupOwners($run, array_keys($ownerIds), $userDeletionService, $failures, $notes);
            } else {
                $notes[] = $allowUserDeletion
                    ? 'dry-run: user deletion not executed'
                    : 'user deletion disabled for this run';
            }

            $run->forceFill([
                'status' => $failures === [] ? ProtectCleanupRun::STATUS_SUCCESS : ProtectCleanupRun::STATUS_FAILED,
                'last_error_message' => $failures === [] ? null : implode(' | ', array_slice($failures, 0, 5)),
                'finished_at' => now(),
            ])->save();
            $this->flushMessages($run, $failures, $notes, true);
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
    private function cleanupOwners(ProtectCleanupRun $run, array $ownerIds, UserDeletionService $userDeletionService, array &$failures, array &$notes): void
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

        if (!Schema::hasColumn('users', 'madeinweb_panel_created_at')) {
            return;
        }

        $orphanQuery = User::query()
            ->where('id', '!=', 1)
            ->where('root_admin', false)
            ->whereNotNull('madeinweb_panel_created_at')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('servers')
                    ->whereColumn('servers.owner_id', 'users.id');
            });

        if (Schema::hasTable('subusers')) {
            $orphanQuery->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('subusers')
                    ->whereColumn('subusers.user_id', 'users.id');
            });
        }

        $orphanQuery->orderBy('id')
            ->chunkById(50, function ($users) use ($userDeletionService, &$failures, &$notes, $run) {
                foreach ($users as $user) {
                    try {
                        $userDeletionService->handle($user);
                        $run->increment('deleted_users');
                        $notes[] = sprintf('deleted user #%d', (int) $user->id);
                    } catch (Throwable $exception) {
                        report($exception);
                        $failures[] = sprintf('user #%d: %s', (int) $user->id, $exception->getMessage());
                        $run->increment('failed_count');
                        $this->flushMessages($run, $failures, $notes);
                    }
                }
            });
    }

    /**
     * @param array<int,string> $failures
     * @param array<int,string> $notes
     */
    private function flushMessages(ProtectCleanupRun $run, array $failures, array $notes = [], bool $final = false): void
    {
        $messages = array_slice(array_merge($notes, $failures), -8);
        if ($final) {
            array_unshift($messages, 'Cleanup finished.');
        }
        $run->forceFill(['messages' => $messages])->save();
    }
}
