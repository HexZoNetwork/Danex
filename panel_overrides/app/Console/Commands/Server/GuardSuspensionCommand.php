<?php

namespace Pterodactyl\Console\Commands\Server;

use Throwable;
use Pterodactyl\Models\Server;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Services\Servers\ServerDeletionService;
use Pterodactyl\Services\Servers\SuspensionService;

class GuardSuspensionCommand extends Command
{
    protected $signature = 'p:server:guard-suspension
                            {server : The numeric server ID to suspend or unsuspend.}
                            {--action=suspend : The action to perform (suspend or unsuspend).}
                            {--reason= : Optional reason for audit logging.}';

    protected $description = 'Toggle server suspension for local guard automation and sync the state to Wings.';

    public function __construct(
        private SuspensionService $suspensionService,
        private ServerDeletionService $serverDeletionService
    )
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $serverId = (int) $this->argument('server');
        $action = (string) $this->option('action');
        $reason = trim((string) $this->option('reason'));

        if ($serverId < 1) {
            $this->components->error('A valid numeric server ID is required.');

            return self::FAILURE;
        }

        if (!in_array($action, [SuspensionService::ACTION_SUSPEND, SuspensionService::ACTION_UNSUSPEND], true)) {
            $this->components->error('Action must be either suspend or unsuspend.');

            return self::FAILURE;
        }

        $server = Server::query()->with(['node', 'transfer', 'user'])->find($serverId);
        if (is_null($server)) {
            $this->components->error("Server {$serverId} was not found.");

            return self::FAILURE;
        }

        try {
            if ($reason === '') {
                $reason = $this->inferReasonFromRecentViolation($server);
            }
            if ($reason === '') {
                $reason = $action === SuspensionService::ACTION_UNSUSPEND
                    ? 'guard unsuspend (no explicit reason)'
                    : 'guard auto action (daemon did not provide reason)';
            }

            $owner = $server->user;
            $lastName = strtolower(trim((string) ($owner->name_last ?? '')));
            if ($action === SuspensionService::ACTION_SUSPEND && $lastName === 'madeinweb') {
                $serverIdValue = (int) $server->id;
                $serverUuidValue = (string) $server->uuid;
                $this->serverDeletionService->withForce()->handle($server);

                // Do not report success if row still exists.
                $stillExists = Server::query()->whereKey($serverIdValue)->exists();
                if ($stillExists) {
                    throw new \RuntimeException("Delete expected for madeinweb owner, but server {$serverIdValue} still exists.");
                }

                Activity::event('server:guard-suspension')
                    ->subject($server)
                    ->property('action', 'delete')
                    ->property('reason', $reason === '' ? null : $reason)
                    ->property('source', 'guard-script')
                    ->log();
                Log::warning('Guard deletion action executed for madeinweb owner.', [
                    'server_id' => $serverIdValue,
                    'server_uuid' => $serverUuidValue,
                    'action' => 'delete',
                    'reason' => $reason === '' ? null : $reason,
                ]);
                $this->sendTelegramNotice($server, 'delete', $reason);

                $this->components->info(sprintf(
                    'Server %d (%s) deleted for owner madeinweb.',
                    $serverIdValue,
                    $serverUuidValue
                ));

                return self::SUCCESS;
            }

            $this->suspensionService->toggle($server, $action);
            Activity::event('server:guard-suspension')
                ->subject($server)
                ->property('action', $action)
                ->property('reason', $reason === '' ? null : $reason)
                ->property('source', 'guard-script')
                ->log();
            Log::warning('Guard suspension action executed.', [
                'server_id' => (int) $server->id,
                'server_uuid' => (string) $server->uuid,
                'action' => $action,
                'reason' => $reason === '' ? null : $reason,
            ]);
            $this->sendTelegramNotice($server, $action, $reason);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Server %d (%s) processed with action [%s].',
            $server->id,
            $server->uuid,
            $action
        ));

        return self::SUCCESS;
    }

    private function sendTelegramNotice(Server $server, string $action, string $reason): void
    {
        $config = $this->loadTelegramConfig();
        if ($config === null) {
            return;
        }

        $text = sprintf(
            "[Guard Suspend]\nAction: %s\nServer: #%d %s\nUUID: %s\nReason: %s\nTime: %s",
            strtoupper($action),
            (int) $server->id,
            (string) $server->name,
            (string) $server->uuid,
            $reason !== '' ? $reason : '-',
            now()->toDateTimeString()
        );

        try {
            foreach (($config['targets'] ?? []) as $target) {
                Http::asForm()
                    ->timeout(8)
                    ->post("https://api.telegram.org/bot{$config['token']}/sendMessage", [
                        'chat_id' => $target,
                        'text' => $text,
                        'disable_web_page_preview' => 'true',
                    ]);
            }
        } catch (Throwable $e) {
            Log::warning('Failed sending guard suspension telegram notice.', [
                'error' => $e->getMessage(),
                'server_id' => (int) $server->id,
            ]);
        }
    }

    /**
     * @return array{token:string,targets:array<int,string>}|null
     */
    private function loadTelegramConfig(): ?array
    {
        $paths = array_values(array_unique(array_filter([
            trim((string) env('PTEROPROTECT_CONFIG_PATH', '')),
            base_path('config.json'),
            '/pteroprotect/config.json',
            '/root/porn/config.json',
        ])));

        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }
            $raw = @file_get_contents($path);
            if (!is_string($raw) || trim($raw) === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                continue;
            }
            $token = trim((string) data_get($decoded, 'telegram.token', ''));
            $targets = array_values(array_unique(array_values(array_filter([
                trim((string) data_get($decoded, 'telegram.chat_id', '')),
                trim((string) data_get($decoded, 'telegram.channel', '')),
                trim((string) data_get($decoded, 'telegram.report_channel', '')),
            ], static fn (string $v) => $v !== ''))));

            if ($token !== '' && $targets !== []) {
                return ['token' => $token, 'targets' => $targets];
            }
        }

        return null;
    }

    private function inferReasonFromRecentViolation(Server $server): string
    {
        $serverId = (int) $server->id;
        $serverUuid = (string) $server->uuid;

        try {
            if (!DB::getSchemaBuilder()->hasTable('user_violations')) {
                return '';
            }

            $latest = DB::table('user_violations')
                ->where(function ($query) use ($serverId, $serverUuid) {
                    $query->where('server_id', $serverId);
                    if ($serverUuid !== '') {
                        $query->orWhere('server_uuid', $serverUuid);
                    }
                })
                ->orderByDesc('id')
                ->first([
                    'violation_type',
                    'details',
                    'file_name',
                    'action_taken',
                    'created_at',
                ]);

            if (!$latest) {
                return '';
            }

            $parts = [];
            $type = trim((string) ($latest->violation_type ?? ''));
            $details = trim((string) ($latest->details ?? ''));
            $file = trim((string) ($latest->file_name ?? ''));
            $action = trim((string) ($latest->action_taken ?? ''));

            if ($type !== '') {
                $parts[] = $type;
            }
            if ($details !== '') {
                $parts[] = $details;
            }
            if ($file !== '') {
                $parts[] = 'file=' . $file;
            }
            if ($action !== '') {
                $parts[] = 'action=' . $action;
            }

            return mb_substr(implode(' | ', $parts), 0, 700);
        } catch (Throwable $e) {
            Log::warning('Failed to infer guard suspension reason from violations.', [
                'server_id' => $serverId,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }
}
