<?php

return [
    'waf' => [
        'enabled' => env('PTEROPROTECT_WAF_ENABLED', true),
        'lockdown_flag' => env('PTEROPROTECT_LOCKDOWN_FLAG', '/pteroprotect/runtime/lockdown.json'),
        'mode_flag' => env('PTEROPROTECT_MODE_FLAG', '/pteroprotect/runtime/mode.json'),
        // Keep default log path aligned with fail2ban/check.sh expectations.
        'log_file' => env('PTEROPROTECT_WAF_LOG', '/dev/shm/pteroprotect/waf.log'),

        'trust_private_ranges' => env('PTEROPROTECT_WAF_TRUST_PRIVATE_RANGES', false),
        'trusted_ips' => [],
        'allow_header_bypass' => env('PTEROPROTECT_WAF_ALLOW_HEADER_BYPASS', false),

        'global_decay_seconds' => env('PTEROPROTECT_WAF_DECAY_SECONDS', 10),

        // Keep API compatibility for internal integrations that omit UA.
        'block_empty_agent_on_api' => false,
        'block_client_ip_spoof_headers' => true,
        'block_malformed_host_header' => true,
        'block_query_pipe_equals_pattern' => true,
        'max_query_pairs' => env('PTEROPROTECT_WAF_MAX_QUERY_PAIRS', 30),
        'max_query_length' => env('PTEROPROTECT_WAF_MAX_QUERY_LENGTH', 2048),
        'max_content_length' => env('PTEROPROTECT_WAF_MAX_CONTENT_LENGTH', 1048576),
        'block_headless_stealth' => true,
        // Can trigger false positives on NAT/shared-client traffic; keep opt-in.
        'fingerprint_cluster_limit_enabled' => env('PTEROPROTECT_WAF_FP_CLUSTER_ENABLED', false),

        // High-confidence abusive automation markers only.
        'suspicious_user_agents' => [
            'sqlmap',
            'headlesschrome',
            'puppeteer',
            'playwright',
            'selenium',
            'phantomjs',
        ],
        'suspicious_path_patterns' => [
            '#(?:\.\./|\.\.\\\)#',
            '#(?:wp-admin|wp-login\.php|xmlrpc\.php)#i',
            '#(?:etc/passwd|/proc/self/environ)#i',
            '#(?:select.+from|union.+select|sleep\(|benchmark\()#i',
        ],
        'strict_lockdown_block_patterns' => [
            '#^api/application/#i',
        ],
        'emergency_block_patterns' => [
            '#^api/application/#i',
        ],
        'block_paths_in_emergency' => false,

        'mode_multipliers' => [
            'normal' => 1.0,
            'aggressive' => 0.75,
            'emergency' => 0.5,
        ],

        'auth_per_ip_limit' => 8,
        'auth_global_limit' => 24,

        'api_per_ip_limit' => 30,
        'api_global_limit' => 140,
        'lockdown_api_per_ip_limit' => 8,
        'lockdown_api_global_limit' => 30,

        'resource_per_ip_limit' => 300,
        'resource_global_limit' => 1500,
        'lockdown_resource_per_ip_limit' => 60,
        'lockdown_resource_global_limit' => 240,

        'websocket_per_ip_limit' => 120,
        'websocket_global_limit' => 900,
        'lockdown_websocket_per_ip_limit' => 24,
        'lockdown_websocket_global_limit' => 160,

        'web_per_ip_limit' => 60,
        'web_global_limit' => 200,

        'challenge_cookie_name' => env('PTEROPROTECT_CHALLENGE_COOKIE', 'pp_clearance'),
        'resource_clearance_limit' => 1200,
        'lockdown_resource_clearance_limit' => 200,
        'api_clearance_limit' => 40,
        'lockdown_api_clearance_limit' => 12,

        'resource_fingerprint_cluster_limit' => 800,
        'lockdown_resource_fingerprint_cluster_limit' => 200,
        'api_fingerprint_cluster_limit' => 160,
        'lockdown_api_fingerprint_cluster_limit' => 80,
        'websocket_fingerprint_cluster_limit' => 320,
        'lockdown_websocket_fingerprint_cluster_limit' => 120,
        'web_fingerprint_cluster_limit' => 180,
        'lockdown_web_fingerprint_cluster_limit' => 90,
    ],
];
