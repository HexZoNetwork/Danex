<?php

return [
    'waf' => [
        'enabled' => env('PTEROPROTECT_WAF_ENABLED', true),
        'lockdown_flag' => env('PTEROPROTECT_LOCKDOWN_FLAG', '/pteroprotect/runtime/lockdown.json'),
        'mode_flag' => env('PTEROPROTECT_MODE_FLAG', '/pteroprotect/runtime/mode.json'),
        'log_file' => env('PTEROPROTECT_WAF_LOG', '/pteroprotect/runtime/waf_decisions.log'),

        'trust_private_ranges' => env('PTEROPROTECT_WAF_TRUST_PRIVATE_RANGES', false),
        'trusted_ips' => [],
        'allow_header_bypass' => env('PTEROPROTECT_WAF_ALLOW_HEADER_BYPASS', false),

        'global_decay_seconds' => env('PTEROPROTECT_WAF_DECAY_SECONDS', 10),

        'block_empty_agent_on_api' => true,
        'block_client_ip_spoof_headers' => true,
        'block_malformed_host_header' => true,
        'block_query_pipe_equals_pattern' => true,
        'max_query_pairs' => env('PTEROPROTECT_WAF_MAX_QUERY_PAIRS', 30),
        'max_query_length' => env('PTEROPROTECT_WAF_MAX_QUERY_LENGTH', 2048),
        'max_content_length' => env('PTEROPROTECT_WAF_MAX_CONTENT_LENGTH', 1048576),
        'block_headless_stealth' => true,
        'fingerprint_cluster_limit_enabled' => true,

        'suspicious_user_agents' => [
            'curl/',
            'wget/',
            'python-requests',
            'go-http-client',
            'httpclient',
            'aiohttp',
            'scrapy',
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

        'resource_per_ip_limit' => 12,
        'resource_global_limit' => 50,
        'lockdown_resource_per_ip_limit' => 3,
        'lockdown_resource_global_limit' => 12,

        'websocket_per_ip_limit' => 120,
        'websocket_global_limit' => 900,
        'lockdown_websocket_per_ip_limit' => 24,
        'lockdown_websocket_global_limit' => 160,

        'web_per_ip_limit' => 60,
        'web_global_limit' => 200,

        'challenge_cookie_name' => env('PTEROPROTECT_CHALLENGE_COOKIE', 'pp_clearance'),
        'resource_clearance_limit' => 14,
        'lockdown_resource_clearance_limit' => 6,
        'api_clearance_limit' => 40,
        'lockdown_api_clearance_limit' => 12,

        'resource_fingerprint_cluster_limit' => 120,
        'lockdown_resource_fingerprint_cluster_limit' => 60,
        'api_fingerprint_cluster_limit' => 160,
        'lockdown_api_fingerprint_cluster_limit' => 80,
        'websocket_fingerprint_cluster_limit' => 320,
        'lockdown_websocket_fingerprint_cluster_limit' => 120,
        'web_fingerprint_cluster_limit' => 180,
        'lockdown_web_fingerprint_cluster_limit' => 90,
    ],
];
