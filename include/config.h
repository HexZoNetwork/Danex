#ifndef CONFIG_H
#define CONFIG_H

#include <string>
#include <nlohmann/json.hpp>

using json = nlohmann::json;

struct DatabaseConfig {
    std::string host = "127.0.0.1";
    std::string user;
    std::string password;
    std::string name;
    std::string tls_mode = "verify_ca";
    std::string tls_ca;
};

struct TelegramConfig {
    std::string token;
    std::string chat_id;
    std::string channel;
    std::string report_channel;
    std::string creator;
};

struct PathsConfig {
    std::string volumes = "/var/lib/pterodactyl/volumes";
};

struct LimitsConfig {
    int check_interval = 10;
    double max_disk_gb = 10.0;
    int max_file_size_mb = 512;
    int max_file_flood = 12;
    int flood_window = 5;
    int cpu_threshold_pct = 80;
    int ram_threshold_pct = 80;
};

struct PtlcConfig {
    std::string url;
    std::string api_key;
};

struct RuntimeConfig {
    bool offline_mode = false;
    std::string state_dir = "/pteroprotect/runtime";
    std::string quarantine_dir_name = ".dann_quarantine";
};

struct NetworkConfig {
    int http_conn_limit = 20;
    int http_req_rate = 8;
    int http_req_burst = 12;
    int host_new_conn_per_ip = 12;
    int host_new_conn_burst = 20;
    int host_connlimit_per_ip = 30;
    int host_recent_hitcount = 60;
    int host_recent_window_sec = 5;
};

struct MonitorConfig {
    bool checkhost_enabled = true;
    std::string checkhost_api_key;
    std::string external_url;
    std::string challenge_path = "/__pteroprotect/challenge/page";
    std::string local_health_url = "http://127.0.0.1:18080/api/system";
    int check_interval_normal_sec = 5;
    int check_interval_anomaly_sec = 2;
    int checkhost_max_nodes = 8;
    int checkhost_zero_node_threshold = 3;
    int external_fail_streak_threshold = 3;
    double latency_p95_ms_threshold = 10000.0;
    double error_rate_threshold = 0.5;
    int lockdown_ttl_sec = 180;
};

struct AbuseConfig {
    int self_ddos_req_threshold = 100;
    int window_ms = 500;
    int strike_window_sec = 60;
    int max_strikes = 3;
    int sigterm_grace_ms = 1500;
    bool then_sigkill = true;
    int escalation_ttl_sec = 45;
};

struct Config {
    DatabaseConfig database;
    TelegramConfig telegram;
    PtlcConfig ptlc;
    PathsConfig paths;
    LimitsConfig limits;
    RuntimeConfig runtime;
    NetworkConfig network;
    MonitorConfig monitor;
    AbuseConfig abuse;
    bool valid = false;

    static Config load(const std::string& filename);
};

#endif
