<?php

namespace Pterodactyl\Services\Nodes\AutoConfigure;

use Illuminate\Support\Facades\Log;
use Pterodactyl\Models\NodeAutoConfigLog;
use Pterodactyl\Models\NodeAutoConfigRun;

class RunLogger
{
    public function log(NodeAutoConfigRun $run, string $level, string $step, string $message, array $context = [], ?string $event = null): void
    {
        NodeAutoConfigLog::query()->create([
            'run_id' => (int) $run->id,
            'level' => $level,
            'step' => $step,
            'event' => $event,
            'message' => $message,
            'context' => $context,
        ]);

        Log::channel(config('logging.default'))->log($level, '[node-auto-configure] ' . $message, array_merge([
            'run_id' => (int) $run->id,
            'node_id' => (int) $run->node_id,
            'correlation_id' => (string) $run->correlation_id,
            'step' => $step,
        ], $context));
    }
}
