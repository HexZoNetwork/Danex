<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Pterodactyl\Models\ApiKey;

if ($argc < 2) {
    fwrite(STDOUT, "invalid\n");
    exit(1);
}

$token = trim((string) $argv[1]);
if ($token === '' || !preg_match('/^ptl[ac]_[A-Za-z0-9_]+$/', $token)) {
    fwrite(STDOUT, "invalid\n");
    exit(1);
}

require '/var/www/pterodactyl/vendor/autoload.php';
$app = require '/var/www/pterodactyl/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$apiKey = ApiKey::findToken($token);
if ($apiKey === null) {
    fwrite(STDOUT, "invalid\n");
    exit(1);
}

fwrite(STDOUT, "valid\n");
