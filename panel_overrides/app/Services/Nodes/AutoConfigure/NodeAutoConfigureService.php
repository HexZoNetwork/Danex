<?php

namespace Pterodactyl\Services\Nodes\AutoConfigure;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pterodactyl\Jobs\Nodes\ExecuteNodeAutoConfigureJob;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\NodeAutoConfigRun;
use Pterodactyl\Models\User;

class NodeAutoConfigureService
{
    public function __construct(private RemoteProvisioner $provisioner)
    {
    }

    public function start(Node $node, User $user, array $input): NodeAutoConfigRun
    {
        return DB::transaction(function () use ($node, $user, $input) {
            $globalRunning = NodeAutoConfigRun::query()
                ->whereIn('status', [NodeAutoConfigRun::STATUS_PENDING, NodeAutoConfigRun::STATUS_RUNNING])
                ->lockForUpdate()
                ->count();
            $maxParallel = max(1, (int) config('pteroprotect_autoconfigure.max_parallel_runs', 3));
            if ($globalRunning >= $maxParallel) {
                abort(429, 'Auto configure concurrency limit reached. Please retry shortly.');
            }

            $running = NodeAutoConfigRun::query()
                ->where('node_id', (int) $node->id)
                ->whereIn('status', [NodeAutoConfigRun::STATUS_PENDING, NodeAutoConfigRun::STATUS_RUNNING])
                ->lockForUpdate()
                ->count();

            if ($running > 0) {
                abort(409, 'Auto configure already running for this node.');
            }

            $keys = $this->provisioner->generateEphemeralKeyPair();
            $run = NodeAutoConfigRun::query()->create([
                'node_id' => (int) $node->id,
                'user_id' => (int) $user->id,
                'status' => NodeAutoConfigRun::STATUS_PENDING,
                'target_host' => (string) $input['target_host'],
                'target_port' => (int) ($input['target_port'] ?? 22),
                'target_username' => (string) ($input['target_username'] ?? 'root'),
                'bootstrap_auth_type' => 'password_ephemeral_key',
                'encrypted_password' => Crypt::encryptString((string) $input['bootstrap_password']),
                'encrypted_private_key' => Crypt::encryptString((string) $keys['private']),
                'wings_port' => (int) ($input['wings_port'] ?? 8080),
                'fallback_port_range' => (string) ($input['fallback_port_range'] ?? config('pteroprotect_autoconfigure.allowed_wings_port_range', '8081-8099')),
                'host_key_policy' => (string) ($input['host_key_policy'] ?? config('pteroprotect_autoconfigure.host_key_policy', 'strict_tofu')),
                'max_ssh_timeout_sec' => (int) config('pteroprotect_autoconfigure.ssh_timeout_sec', 30),
                'correlation_id' => (string) Str::uuid(),
                'requested_payload' => [
                    'reconfigure_mode' => (string) ($input['reconfigure_mode'] ?? 'reconfigure'),
                    'firewall_mode' => (string) ($input['firewall_mode'] ?? 'auto'),
                    'ephemeral_public_key' => (string) $keys['public'],
                    'expected_host_fingerprint' => trim((string) ($input['host_fingerprint'] ?? '')),
                ],
            ]);

            ExecuteNodeAutoConfigureJob::dispatch((int) $run->id)
                ->onQueue((string) config('pteroprotect_autoconfigure.queue', 'standard'));

            return $run;
        });
    }
}
