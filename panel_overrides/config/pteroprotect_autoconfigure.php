<?php

return [
    'allowed_wings_port_range' => env('PTEROPROTECT_AUTOCONF_PORT_RANGE', '8081-8099'),
    'ssh_timeout_sec' => (int) env('PTEROPROTECT_AUTOCONF_SSH_TIMEOUT', 30),
    'max_parallel_runs' => (int) env('PTEROPROTECT_AUTOCONF_MAX_PARALLEL', 3),
    'host_key_policy' => env('PTEROPROTECT_AUTOCONF_HOST_KEY_POLICY', 'strict_tofu'),
    'queue' => env('PTEROPROTECT_AUTOCONF_QUEUE', 'standard'),
];
