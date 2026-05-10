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
            'aggressive' => 0.9,
            'emergency' => 0.5,
        ],

        'auth_per_ip_limit' => 20,
        'auth_global_limit' => 80,

        'api_per_ip_limit' => 80,
        'api_global_limit' => 320,
        'lockdown_api_per_ip_limit' => 20,
        'lockdown_api_global_limit' => 80,

        'resource_per_ip_limit' => 700,
        'resource_global_limit' => 3200,
        'lockdown_resource_per_ip_limit' => 120,
        'lockdown_resource_global_limit' => 480,

        'websocket_per_ip_limit' => 240,
        'websocket_global_limit' => 1600,
        'lockdown_websocket_per_ip_limit' => 48,
        'lockdown_websocket_global_limit' => 260,

        'web_per_ip_limit' => 180,
        'web_global_limit' => 700,

        'challenge_cookie_name' => env('PTEROPROTECT_CHALLENGE_COOKIE', 'pp_clearance'),
        'resource_clearance_limit' => 3000,
        'lockdown_resource_clearance_limit' => 360,
        'api_clearance_limit' => 160,
        'lockdown_api_clearance_limit' => 30,

        'resource_fingerprint_cluster_limit' => 1600,
        'lockdown_resource_fingerprint_cluster_limit' => 360,
        'api_fingerprint_cluster_limit' => 320,
        'lockdown_api_fingerprint_cluster_limit' => 120,
        'websocket_fingerprint_cluster_limit' => 640,
        'lockdown_websocket_fingerprint_cluster_limit' => 200,
        'web_fingerprint_cluster_limit' => 360,
        'lockdown_web_fingerprint_cluster_limit' => 140,
    ],
    'resilience' => [
        'enabled' => env('PTEROPROTECT_RESILIENCE_ENABLED', true),
        'state_file' => env('PTEROPROTECT_RESILIENCE_STATE_FILE', '/pteroprotect/runtime/resilience_state.json'),
        'events_file' => env('PTEROPROTECT_RESILIENCE_EVENTS_FILE', '/pteroprotect/runtime/resilience_events.jsonl'),
        'poison_file' => env('PTEROPROTECT_RESILIENCE_POISON_FILE', '/pteroprotect/runtime/poison_fingerprints.json'),
        'replay_queue_file' => env('PTEROPROTECT_RESILIENCE_REPLAY_FILE', '/pteroprotect/runtime/replay_queue.jsonl'),
    ],
];
