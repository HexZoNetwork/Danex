<?php

function uno_panel_env_path() {
    $path = realpath(__DIR__ . '/../../../.env');

    if ($path === false || !is_readable($path)) {
        http_response_code(500);
        exit('Pterodactyl database configuration is not readable.');
    }

    return $path;
}

function uno_env_value($key, $default = '') {
    static $env = null;

    if ($env === null) {
        $env = [];
        foreach (file(uno_panel_env_path(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }

            [$name, $value] = array_map('trim', explode('=', $line, 2));
            if ($value !== '' && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
                $value = substr($value, 1, -1);
            }
            $env[$name] = $value;
        }
    }

    return $env[$key] ?? $default;
}

$serverHost = uno_env_value('DB_HOST', '127.0.0.1');
$serverPort = (int) uno_env_value('DB_PORT', '3306');
$serverIp = $serverPort > 0 && $serverPort !== 3306 ? $serverHost . ':' . $serverPort : $serverHost;
$username = uno_env_value('DB_USERNAME');
$pass = uno_env_value('DB_PASSWORD');
$dbName = uno_env_value('DB_DATABASE');

if ($username === '' || $dbName === '') {
    http_response_code(500);
    exit('Pterodactyl database configuration is incomplete.');
}
