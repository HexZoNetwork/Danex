<?php

namespace Pterodactyl\Console\Commands\Server;

use Throwable;
use Pterodactyl\Models\Server;
use Illuminate\Console\Command;
use Pterodactyl\Services\Servers\SuspensionService;

class GuardSuspensionCommand extends Command
{
    protected $signature = 'p:server:guard-suspension
                            {server : The numeric server ID to suspend or unsuspend.}
                            {--action=suspend : The action to perform (suspend or unsuspend).}';

    protected $description = 'Toggle server suspension for local guard automation and sync the state to Wings.';

    public function __construct(private SuspensionService $suspensionService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $serverId = (int) $this->argument('server');
        $action = (string) $this->option('action');

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
}
