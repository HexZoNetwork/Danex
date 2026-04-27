#ifndef CONFIG_H
#define CONFIG_H

#include <string>
#include <nlohmann/json.hpp>

using json = nlohmann::json;

struct DatabaseConfig {
    std::string host;
    std::string user;
    std::string password;
    std::string name;
};

struct TelegramConfig {
    std::string token;
    std::string chat_id;
    std::string channel;
    std::string report_channel;
    std::string creator;
};

struct PathsConfig {
    std::string volumes;
};

struct LimitsConfig {
    int check_interval;
    double max_disk_gb;
    int max_file_size_mb;
    int max_file_flood;
    int flood_window;
    int cpu_threshold_pct;
    int ram_threshold_pct;
};

struct PtlcConfig {
    std::string url;
    std::string api_key;
};

struct RuntimeConfig {
    bool offline_mode;
    std::string state_dir;
    std::string quarantine_dir_name;
};

struct NetworkConfig {
    int http_conn_limit;
    int http_req_rate;
    int http_req_burst;
    int host_new_conn_per_ip;
    int host_new_conn_burst;
    int host_connlimit_per_ip;
    int host_recent_hitcount;
    int host_recent_window_sec;
};

struct MonitorConfig {
    bool checkhost_enabled;
    std::string checkhost_api_key;
    std::string external_url;
    std::string challenge_path;
    std::string local_health_url;
    int check_interval_normal_sec;
    int check_interval_anomaly_sec;
    int external_fail_streak_threshold;
    double latency_p95_ms_threshold;
    double error_rate_threshold;
    int lockdown_ttl_sec;
};

struct AbuseConfig {
    int self_ddos_req_threshold;
    int window_ms;
    int strike_window_sec;
    int max_strikes;
    int sigterm_grace_ms;
    bool then_sigkill;
    int escalation_ttl_sec;
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
    
    static Config load(const std::string& filename);
    void save(const std::string& filename);
};

#endif
