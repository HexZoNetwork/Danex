<?php

namespace Pterodactyl\Console\Commands\Server;

use Throwable;
use Pterodactyl\Models\Server;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Services\Servers\SuspensionService;

class GuardSuspensionCommand extends Command
{
    protected $signature = 'p:server:guard-suspension
                            {server : The numeric server ID to suspend or unsuspend.}
                            {--action=suspend : The action to perform (suspend or unsuspend).}
                            {--reason= : Optional reason for audit logging.}';

    protected $description = 'Toggle server suspension for local guard automation and sync the state to Wings.';

    public function __construct(private SuspensionService $suspensionService)
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

        $server = Server::query()->with(['node', 'transfer'])->find($serverId);
        if (is_null($server)) {
            $this->components->error("Server {$serverId} was not found.");

            return self::FAILURE;
        }

        try {
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
            Http::asForm()
                ->timeout(8)
                ->post("https://api.telegram.org/bot{$config['token']}/sendMessage", [
                    'chat_id' => $config['chat_id'],
                    'text' => $text,
                    'disable_web_page_preview' => 'true',
                ]);
        } catch (Throwable $e) {
            Log::warning('Failed sending guard suspension telegram notice.', [
                'error' => $e->getMessage(),
                'server_id' => (int) $server->id,
            ]);
        }
    }

    /**
     * @return array{token:string,chat_id:string}|null
     */
    private function loadTelegramConfig(): ?array
    {
        $paths = [
            base_path('config.json'),
            '/root/porn/config.json',
        ];

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
            $chatId = trim((string) data_get($decoded, 'telegram.chat_id', ''));
            if ($token !== '' && $chatId !== '') {
                return ['token' => $token, 'chat_id' => $chatId];
            }
        }

        return null;
    }
}
