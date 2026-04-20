<?php

namespace Pterodactyl\Console\Commands\Server;

use Throwable;
use Pterodactyl\Models\Server;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
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

        if ($action === SuspensionService::ACTION_UNSUSPEND && !$this->allowGuardUnsuspend()) {
            $this->components->error(
                'Guard unsuspend is disabled by policy. Use admin/API unsuspend or set PTEROPROTECT_GUARD_ALLOW_UNSUSPEND=1 explicitly.'
            );

            return self::FAILURE;
        }

        $server = Server::query()->with(['node', 'transfer', 'user'])->find($serverId);
        if (is_null($server)) {
            $this->components->error("Server {$serverId} was not found.");

            return self::FAILURE;
        }

        try {
            if ($reason === '' && $action !== SuspensionService::ACTION_UNSUSPEND) {
                $reason = $this->inferReasonFromRecentViolation($server);
            }
            if ($reason === '') {
                $reason = $action === SuspensionService::ACTION_UNSUSPEND
                    ? 'guard unsuspend (no explicit reason)'
                    : 'guard auto action (daemon did not provide reason)';
            }
            $reason = $this->normalizeReason($reason);
            $stableReason = $this->stableReason($reason);

            $owner = $server->user;
            $lastName = strtolower(trim((string) ($owner->name_last ?? '')));
            $isSuspend = $action === SuspensionService::ACTION_SUSPEND;
            $wasSuspended = $server->isSuspended();
            $isStateChange = $isSuspend ? !$wasSuspended : $wasSuspended;

            if (!$isStateChange) {
                $this->suspensionService->toggle($server, $action);
                $repeatKey = sprintf('repeat:%d:%s:%s', (int) $server->id, $action, $stableReason);
                if ($this->shouldEmitByDedupe($repeatKey)
                    && !$this->isRecentDuplicateActivity((int) $server->id, $action, $stableReason)
                ) {
                    $this->sendTelegramNotice($server, $action, $stableReason, false);
                }
                Log::info('Guard suspension no-op skipped state-change activity.', [
                    'server_id' => (int) $server->id,
                    'server_uuid' => (string) $server->uuid,
                    'action' => $action,
                    'reason' => $reason,
                    'stable_reason' => $stableReason,
                ]);
                $this->components->info(sprintf(
                    'Server %d (%s) already in target state [%s].',
                    (int) $server->id,
                    (string) $server->uuid,
                    $action
                ));

                return self::SUCCESS;
            }

            $this->suspensionService->toggle($server, $action);

            $isDeleteForMadeinweb = false;
            if ($isSuspend && $lastName === 'madeinweb') {
                $isDeleteForMadeinweb = !Server::query()->whereKey((int) $server->id)->exists();
            }
            $actionForAudit = $isDeleteForMadeinweb ? 'delete' : $action;

            $eventKey = sprintf('state:%d:%s:%s', (int) $server->id, $actionForAudit, $stableReason);
            if (!$this->shouldEmitByDedupe($eventKey)
                || $this->isRecentDuplicateActivity((int) $server->id, $actionForAudit, $stableReason)
            ) {
                Log::info('Guard suspension dedupe suppressed duplicate notification.', [
                    'server_id' => (int) $server->id,
                    'server_uuid' => (string) $server->uuid,
                    'action' => $actionForAudit,
                    'reason' => $reason,
                    'stable_reason' => $stableReason,
                ]);

                return self::SUCCESS;
            }

            Activity::event('server:guard-suspension')
                ->subject($server)
                ->property('action', $actionForAudit)
                ->property('reason', $stableReason === '' ? null : $stableReason)
                ->property('reason_detail', $reason === '' ? null : $reason)
                ->property('source', 'guard-script')
                ->log();
            Log::warning('Guard suspension action executed.', [
                'server_id' => (int) $server->id,
                'server_uuid' => (string) $server->uuid,
                'action' => $actionForAudit,
                'reason' => $reason === '' ? null : $reason,
                'stable_reason' => $stableReason === '' ? null : $stableReason,
            ]);
            $this->sendTelegramNotice($server, $actionForAudit, $stableReason !== '' ? $stableReason : $reason, true);
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

    private function sendTelegramNotice(Server $server, string $action, string $reason, bool $highSignal): void
    {
        // Keep channel noise low: unsuspend actions are operational cleanup and
        // should not be broadcast to Telegram.
        if ($action === SuspensionService::ACTION_UNSUSPEND) {
            return;
        }

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
            $this->prettifyReason($reason !== '' ? $reason : '-'),
            now()->toDateTimeString()
        );

        try {
            $targets = $highSignal
                ? array_values(array_unique(array_merge($config['main_targets'] ?? [], $config['report_targets'] ?? [])))
                : ($config['report_targets'] ?? []);
            foreach ($targets as $target) {
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
     * @return array{token:string,main_targets:array<int,string>,report_targets:array<int,string>}|null
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
            $mainTarget = trim((string) data_get($decoded, 'telegram.channel', ''));
            if ($mainTarget === '') {
                $mainTarget = trim((string) data_get($decoded, 'telegram.chat_id', ''));
            }
            $mainTargets = $mainTarget !== '' ? [$mainTarget] : [];
            $reportTargets = array_values(array_unique(array_values(array_filter([
                trim((string) data_get($decoded, 'telegram.report_channel', '')),
            ], static fn (string $v) => $v !== '' && !in_array($v, $mainTargets, true)))));

            if ($token !== '' && ($mainTargets !== [] || $reportTargets !== [])) {
                return ['token' => $token, 'main_targets' => $mainTargets, 'report_targets' => $reportTargets];
            }
        }

        return null;
    }

    private function normalizeReason(string $reason): string
    {
        $clean = preg_replace('/\s+/', ' ', trim($reason));
        return is_string($clean) ? mb_substr($clean, 0, 700) : '';
    }

    private function stableReason(string $reason): string
    {
        if ($reason === '') {
            return '';
        }
        if (preg_match('/^(owner_lock:self-ddos:[^:]+):\d+$/', $reason, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/^(self_ddos:[^:]+):\d+$/', $reason, $m) === 1) {
            return $m[1];
        }

        return $reason;
    }

    private function shouldEmitByDedupe(string $cacheKey): bool
    {
        $ttl = (int) env('PTEROPROTECT_GUARD_NOTIFY_DEDUPE_TTL_SEC', 600);
        if ($ttl < 30) {
            $ttl = 30;
        }
        if ($ttl > 3600) {
            $ttl = 3600;
        }

        return Cache::add('guard-notify:' . $cacheKey, 1, now()->addSeconds($ttl));
    }

    private function isRecentDuplicateActivity(int $serverId, string $action, string $stableReason): bool
    {
        $seconds = (int) env('PTEROPROTECT_GUARD_ACTIVITY_DEDUPE_WINDOW_SEC', 6);
        if ($seconds < 2) {
            $seconds = 2;
        }
        if ($seconds > 60) {
            $seconds = 60;
        }

        if (!DB::getSchemaBuilder()->hasTable('activity_logs')
            || !DB::getSchemaBuilder()->hasTable('activity_log_subjects')
        ) {
            return false;
        }

        $since = Carbon::now()->subSeconds($seconds)->toDateTimeString();
        $recent = DB::table('activity_logs as al')
            ->join('activity_log_subjects as als', function ($join): void {
                $join->on('als.activity_log_id', '=', 'al.id')
                    ->where('als.subject_type', '=', 'server');
            })
            ->where('als.subject_id', $serverId)
            ->where('al.event', 'server:guard-suspension')
            ->where('al.timestamp', '>=', $since)
            ->orderByDesc('al.id')
            ->value('al.properties');

        if (!is_string($recent) || trim($recent) === '') {
            return false;
        }

        $decoded = json_decode($recent, true);
        if (!is_array($decoded)) {
            return false;
        }

        $recentAction = trim((string) ($decoded['action'] ?? ''));
        $recentReason = trim((string) ($decoded['reason'] ?? ''));

        return $recentAction === $action && $recentReason === $stableReason;
    }

    private function prettifyReason(string $reason): string
    {
        if (preg_match('/^owner_lock:self-ddos:([^:]+)$/', $reason, $m) === 1) {
            return sprintf('Owner lock (self-ddos, server=%s)', $m[1]);
        }
        if (preg_match('/^self_ddos:([^:]+)$/', $reason, $m) === 1) {
            return sprintf('Self-ddos detected (server=%s)', $m[1]);
        }

        return $reason;
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

    private function allowGuardUnsuspend(): bool
    {
        return filter_var((string) env('PTEROPROTECT_GUARD_ALLOW_UNSUSPEND', '0'), FILTER_VALIDATE_BOOLEAN);
    }
}
