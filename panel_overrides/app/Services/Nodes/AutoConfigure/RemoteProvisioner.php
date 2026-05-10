<?php

namespace Pterodactyl\Services\Nodes\AutoConfigure;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use phpseclib3\Net\SSH2;
use RuntimeException;

class RemoteProvisioner
{
    public function generateEphemeralKeyPair(): array
    {
        $private = RSA::createKey(4096);

        return [
            'private' => $private->toString('PKCS8'),
            'public' => $private->getPublicKey()->toString('OpenSSH'),
        ];
    }

    public function bootstrapWithPassword(string $host, int $port, string $username, string $password, string $publicKey, int $timeout): array
    {
        $ssh = new SSH2($host, $port, $timeout);
        if (!$ssh->login($username, $password)) {
            throw new RuntimeException('ssh_password_login_failed');
        }

        $fingerprint = $ssh->getServerPublicHostKey();
        $safeKey = str_replace("'", "'\\''", trim($publicKey));
        $ssh->exec("mkdir -p ~/.ssh && chmod 700 ~/.ssh && touch ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys");
        $ssh->exec("grep -qF '$safeKey' ~/.ssh/authorized_keys || echo '$safeKey' >> ~/.ssh/authorized_keys");

        return [
            'host_fingerprint' => is_string($fingerprint) ? base64_encode($fingerprint) : null,
        ];
    }

    public function verifyPinnedFingerprint(string $actualFingerprintBase64, string $expectedFingerprint): void
    {
        $expected = trim($expectedFingerprint);
        if ($expected === '') {
            throw new RuntimeException('host_fingerprint_missing');
        }

        if (!hash_equals($expected, trim($actualFingerprintBase64))) {
            throw new RuntimeException('host_fingerprint_mismatch');
        }
    }

    public function runWithPrivateKey(string $host, int $port, string $username, string $privateKeyPem, string $script, int $timeout): array
    {
        $ssh = new SSH2($host, $port, $timeout);
        $key = PublicKeyLoader::loadPrivateKey($privateKeyPem);
        if (!$ssh->login($username, $key)) {
            throw new RuntimeException('ssh_key_login_failed');
        }

        $cmd = "bash -s <<'__PTERO_SCRIPT__'\n" . $script . "\n__PTERO_SCRIPT__\n";
        $output = (string) $ssh->exec($cmd);
        $exitCode = $ssh->getExitStatus();

        return [
            'output' => $output,
            'exit_code' => $exitCode,
        ];
    }

    public function revokeEphemeralKey(string $host, int $port, string $username, string $privateKeyPem, string $publicKey, int $timeout): void
    {
        $ssh = new SSH2($host, $port, $timeout);
        $key = PublicKeyLoader::loadPrivateKey($privateKeyPem);
        if (!$ssh->login($username, $key)) {
            return;
        }

        $safeKey = str_replace("'", "'\\''", trim($publicKey));
        $ssh->exec("if [ -f ~/.ssh/authorized_keys ]; then grep -vF '$safeKey' ~/.ssh/authorized_keys > ~/.ssh/authorized_keys.tmp && mv ~/.ssh/authorized_keys.tmp ~/.ssh/authorized_keys; fi");
    }
}
