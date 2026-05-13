<?php

namespace Pterodactyl\Jobs\Nodes;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\NodeAutoConfigRun;
use Pterodactyl\Services\Nodes\AutoConfigure\RemoteProvisioner;
use Pterodactyl\Services\Nodes\AutoConfigure\RemoteScriptBuilder;
use Pterodactyl\Services\Nodes\AutoConfigure\RunLogger;
use Throwable;

class ExecuteNodeAutoConfigureJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private int $runId)
    {
    }

    public function handle(RemoteProvisioner $provisioner, RemoteScriptBuilder $builder, RunLogger $logger): void
    {
        $run = NodeAutoConfigRun::query()->find($this->runId);
        if (!$run || $run->status === NodeAutoConfigRun::STATUS_CANCELED) {
            return;
        }

        $run->status = NodeAutoConfigRun::STATUS_RUNNING;
        $run->started_at = now();
        $run->save();

        $logger->log($run, 'info', 'preflight', 'Starting auto-configure run.', ['target' => $run->target_host], 'run_started');

        $publicKey = (string) ($run->requested_payload['ephemeral_public_key'] ?? '');
        $password = Crypt::decryptString((string) $run->encrypted_password);
        $privateKey = Crypt::decryptString((string) $run->encrypted_private_key);

        try {
            $bootstrap = $provisioner->bootstrapWithPassword(
                (string) $run->target_host,
                (int) $run->target_port,
                (string) $run->target_username,
                $password,
                $publicKey,
                (int) $run->max_ssh_timeout_sec
            );
            $run->host_fingerprint = (string) ($bootstrap['host_fingerprint'] ?? '');
            $run->save();

            $hostKeyPolicy = (string) $run->host_key_policy;
            $expectedFingerprint = (string) ($run->requested_payload['expected_host_fingerprint'] ?? '');
            if ($hostKeyPolicy === 'strict_pinned') {
                $provisioner->verifyPinnedFingerprint((string) $run->host_fingerprint, $expectedFingerprint);
                $logger->log($run, 'info', 'bootstrap', 'Pinned host fingerprint validated.', [], 'bootstrap_fingerprint_ok');
            }

            $logger->log($run, 'info', 'bootstrap', 'Password bootstrap completed, ephemeral key installed.', [], 'bootstrap_ok');

            $script = $builder->render(
                (int) $run->wings_port,
                (string) $run->fallback_port_range,
                (string) ($run->requested_payload['firewall_mode'] ?? 'auto'),
                (string) ($run->requested_payload['node_yaml_b64'] ?? ''),
                (bool) ($run->requested_payload['protected_mode'] ?? true)
            );

            $exec = $provisioner->runWithPrivateKey(
                (string) $run->target_host,
                (int) $run->target_port,
                (string) $run->target_username,
                $privateKey,
                $script,
                (int) $run->max_ssh_timeout_sec
            );

            $logger->log($run, 'info', 'remote_install', 'Remote installer executed.', [
                'exit_code' => $exec['exit_code'] ?? null,
                'output' => mb_substr((string) ($exec['output'] ?? ''), 0, 6000),
            ], 'remote_exec_done');

            if (($exec['exit_code'] ?? 1) !== 0 && ($exec['exit_code'] ?? 1) !== null) {
                throw new \RuntimeException('remote_installer_failed');
            }

            $output = (string) ($exec['output'] ?? '');
            if (preg_match('/SELECTED_WINGS_PORT=(\d{1,5})/', $output, $matches) === 1) {
                $selectedPort = (int) $matches[1];
                if ($selectedPort >= 1 && $selectedPort <= 65535) {
                    $run->wings_port = $selectedPort;
                    $run->save();

                    $node = Node::query()->find((int) $run->node_id);
                    if ($node && (int) $node->daemonListen !== $selectedPort) {
                        $node->daemonListen = $selectedPort;
                        $node->save();
                        $logger->log($run, 'info', 'panel_sync', 'Updated panel daemonListen to selected public daemon port.', [
                            'daemonListen' => $selectedPort,
                        ], 'panel_daemon_listen_updated');
                    }
                }
            }

            $provisioner->revokeEphemeralKey(
                (string) $run->target_host,
                (int) $run->target_port,
                (string) $run->target_username,
                $privateKey,
                $publicKey,
                (int) $run->max_ssh_timeout_sec
            );

            $run->status = NodeAutoConfigRun::STATUS_SUCCESS;
            $run->finished_at = now();
            $run->save();
            $logger->log($run, 'info', 'finalize', 'Auto-configure finished successfully.', [], 'run_success');
        } catch (Throwable $e) {
            $run->status = NodeAutoConfigRun::STATUS_FAILED;
            $run->last_error_code = 'auto_config_failed';
            $run->last_error_message = $e->getMessage();
            $run->finished_at = now();
            $run->save();
            $logger->log($run, 'error', 'failed', 'Auto-configure failed.', ['error' => $e->getMessage()], 'run_failed');
            throw $e;
        }
    }
}
