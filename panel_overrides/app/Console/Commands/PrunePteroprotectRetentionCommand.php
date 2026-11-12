<?php

namespace Pterodactyl\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PrunePteroprotectRetentionCommand extends Command
{
    protected $signature = 'pteroprotect:prune-retention {--dry-run : Report rows without deleting them}';

    protected $description = 'Prune expired PteroProtect RUM, chat, call, presence, and audit retention data.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $now = now();
        $limit = 2000;

        $targets = [
            ['panel_rum_events', 'occurred_at', $this->daysSince($now, 'PTEROPROTECT_RUM_RETENTION_DAYS', 14, 3, 365)],
            ['public_chat_messages', 'created_at', $this->daysSince($now, 'PTEROPROTECT_CHAT_RETENTION_DAYS', 90, 7, 3650)],
            ['chat_notifications', 'created_at', $this->daysSince($now, 'PTEROPROTECT_NOTIFICATION_RETENTION_DAYS', 60, 7, 3650)],
            ['chat_call_sessions', 'ended_at', $this->daysSince($now, 'PTEROPROTECT_CALL_RETENTION_DAYS', 7, 1, 365)],
            ['protect_cleanup_runs', 'created_at', $this->daysSince($now, 'PTEROPROTECT_CLEANUP_RUN_RETENTION_DAYS', 180, 30, 3650)],
            ['node_auto_config_logs', 'created_at', $this->daysSince($now, 'PTEROPROTECT_NODE_LOG_RETENTION_DAYS', 30, 7, 3650)],
        ];

        $mode = $dryRun ? 'dry-run' : 'delete';
        $this->info(sprintf('PteroProtect retention pruning started (%s mode).', $mode));

        foreach ($targets as [$table, $column, $cutoff]) {
            $count = $this->prune($table, $column, $cutoff, $dryRun, $limit);
            $this->line(sprintf('%-32s %-16s %8d', $table, $column, $count));
        }

        $presenceCutoff = $now->copy()->subHours($this->intEnv('PTEROPROTECT_PRESENCE_RETENTION_HOURS', 24, 1, 168));
        $presence = $this->prune('chat_user_presence', 'last_seen_at', $presenceCutoff, $dryRun, $limit);
        $this->line(sprintf('%-32s %-16s %8d', 'chat_user_presence', 'last_seen_at', $presence));

        $staleCalls = $this->pruneStaleCalls($this->daysSince($now, 'PTEROPROTECT_CALL_RETENTION_DAYS', 7, 1, 365), $dryRun, $limit);
        $this->line(sprintf('%-32s %-16s %8d', 'chat_call_sessions', 'stale_open', $staleCalls));

        $this->info('PteroProtect retention pruning finished.');
        return self::SUCCESS;
    }

    private function daysSince(Carbon $now, string $env, int $default, int $min, int $max): Carbon
    {
        return $now->copy()->subDays($this->intEnv($env, $default, $min, $max));
    }

    private function intEnv(string $name, int $default, int $min, int $max): int
    {
        $raw = env($name, $default);
        if (is_string($raw) && !is_numeric($raw)) {
            return $default;
        }
        return max($min, min($max, (int) $raw));
    }

    private function prune(string $table, string $column, Carbon $cutoff, bool $dryRun, int $limit): int
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return 0;
        }

        $query = DB::table($table)->where($column, '<', $cutoff->toDateTimeString());
        if ($dryRun) {
            return (int) $query->count();
        }

        $total = 0;
        for ($i = 0; $i < 500; $i++) {
            $deleted = (int) DB::table($table)
                ->where($column, '<', $cutoff->toDateTimeString())
                ->limit($limit)
                ->delete();
            $total += $deleted;
            if ($deleted < $limit) {
                break;
            }
        }

        return $total;
    }

    private function pruneStaleCalls(Carbon $cutoff, bool $dryRun, int $limit): int
    {
        if (!Schema::hasTable('chat_call_sessions') || !Schema::hasColumn('chat_call_sessions', 'ended_at')) {
            return 0;
        }

        $query = DB::table('chat_call_sessions')
            ->whereNull('ended_at')
            ->where('created_at', '<', $cutoff->toDateTimeString());
        if ($dryRun) {
            return (int) $query->count();
        }

        $total = 0;
        for ($i = 0; $i < 500; $i++) {
            $deleted = (int) DB::table('chat_call_sessions')
                ->whereNull('ended_at')
                ->where('created_at', '<', $cutoff->toDateTimeString())
                ->limit($limit)
                ->delete();
            $total += $deleted;
            if ($deleted < $limit) {
                break;
            }
        }

        return $total;
    }
}
