#include "config.h"
#include "logger.h"
#include <cstdlib>
#include <cmath>
#include <cctype>
#include <fstream>
#include <iostream>

using json = nlohmann::json;

namespace {
std::string env_or_default(const char* key, const std::string& fallback) {
    const char* value = std::getenv(key);
    return (value != nullptr && *value != '\0') ? std::string(value) : fallback;
}

bool has_control_chars(const std::string& value) {
    for (unsigned char c : value) {
        if (c < 0x20 || c == 0x7f) return true;
    }
    return false;
}

void clamp_int(int& value, int lo, int hi) {
    if (value < lo) value = lo;
    if (value > hi) value = hi;
}

void validate_config(Config& cfg) {
    if (cfg.paths.volumes.empty() || cfg.paths.volumes[0] != '/'
        || has_control_chars(cfg.paths.volumes)) {
        logger.warn("Invalid paths.volumes, using /var/lib/pterodactyl/volumes");
        cfg.paths.volumes = "/var/lib/pterodactyl/volumes";
    }

    if (cfg.runtime.state_dir.empty() || cfg.runtime.state_dir[0] != '/'
        || has_control_chars(cfg.runtime.state_dir)) {
        logger.warn("Invalid runtime.state_dir, using /pteroprotect/runtime");
        cfg.runtime.state_dir = "/pteroprotect/runtime";
    }

    const std::string& qdir = cfg.runtime.quarantine_dir_name;
    if (qdir.empty() || qdir == "." || qdir == ".."
        || qdir.find('/') != std::string::npos || has_control_chars(qdir)) {
        logger.warn("Invalid runtime.quarantine_dir_name, using .dann_quarantine");
        cfg.runtime.quarantine_dir_name = ".dann_quarantine";
    }

    if (!std::isfinite(cfg.limits.max_disk_gb) || cfg.limits.max_disk_gb <= 0.0) {
        logger.warn("Invalid limits.max_disk_gb, using 10.0");
        cfg.limits.max_disk_gb = 10.0;
    }
    clamp_int(cfg.limits.check_interval, 1, 3600);
    clamp_int(cfg.limits.max_file_size_mb, 1, 1048576);
    clamp_int(cfg.limits.max_file_flood, 1, 1000000);
    clamp_int(cfg.limits.flood_window, 1, 86400);
    clamp_int(cfg.limits.cpu_threshold_pct, 1, 100);
    clamp_int(cfg.limits.ram_threshold_pct, 1, 100);

    clamp_int(cfg.monitor.check_interval_normal_sec, 1, 3600);
    clamp_int(cfg.monitor.check_interval_anomaly_sec, 1, 3600);
    clamp_int(cfg.monitor.checkhost_max_nodes, 1, 1024);
    clamp_int(cfg.monitor.checkhost_zero_node_threshold, 1, 1000);
    clamp_int(cfg.monitor.external_fail_streak_threshold, 1, 1000);
    if (!std::isfinite(cfg.monitor.latency_p95_ms_threshold) || cfg.monitor.latency_p95_ms_threshold <= 0.0) {
        cfg.monitor.latency_p95_ms_threshold = 10000.0;
    }
    if (!std::isfinite(cfg.monitor.error_rate_threshold)
        || cfg.monitor.error_rate_threshold < 0.0 || cfg.monitor.error_rate_threshold > 1.0) {
        cfg.monitor.error_rate_threshold = 0.5;
    }
    clamp_int(cfg.monitor.lockdown_ttl_sec, 1, 86400);

    clamp_int(cfg.abuse.self_ddos_req_threshold, 1, 1000000);
    clamp_int(cfg.abuse.window_ms, 1, 60000);
    clamp_int(cfg.abuse.strike_window_sec, 1, 86400);
    clamp_int(cfg.abuse.max_strikes, 1, 1000);
    clamp_int(cfg.abuse.sigterm_grace_ms, 0, 60000);
    clamp_int(cfg.abuse.escalation_ttl_sec, 1, 86400);
}
}

Config Config::load(const std::string& filename) {
    Config cfg;
    std::ifstream file(filename);
    
    if (!file.is_open()) {
        logger.error("❌ Failed to open config file: " + filename);
        logger.error("   Using default empty config");
        return cfg;
    }
    
    try {
        json j;
        file >> j;
        
        // Database
        cfg.database.host = env_or_default("DANN_DB_HOST", j.value("database", json::object()).value("host", "127.0.0.1"));
        cfg.database.user = env_or_default("DANN_DB_USER", j.value("database", json::object()).value("user", ""));
        cfg.database.password = env_or_default("DANN_DB_PASSWORD", j.value("database", json::object()).value("password", ""));
        cfg.database.name = env_or_default("DANN_DB_NAME", j.value("database", json::object()).value("name", ""));
        
        // Telegram
        cfg.telegram.token = env_or_default("DANN_TG_TOKEN", j.value("telegram", json::object()).value("token", ""));
        cfg.telegram.chat_id = env_or_default("DANN_TG_CHAT_ID", j.value("telegram", json::object()).value("chat_id", ""));
        cfg.telegram.channel = j.value("telegram", json::object()).value("channel", "");
        cfg.telegram.report_channel = j.value("telegram", json::object()).value("report_channel", "");
        cfg.telegram.creator = j.value("telegram", json::object()).value("creator", "");
        
        // Paths
        cfg.paths.volumes = j.value("paths", json::object()).value("volumes", "/var/lib/pterodactyl/volumes");
        
        // Limits
        cfg.limits.check_interval = j.value("limits", json::object()).value("check_interval", 1);
        cfg.limits.max_disk_gb = j.value("limits", json::object()).value("max_disk_gb", 10.0);
        cfg.limits.max_file_size_mb = j.value("limits", json::object()).value("max_file_size_mb", 512);
        cfg.limits.max_file_flood = j.value("limits", json::object()).value("max_file_flood", 12);
        cfg.limits.flood_window = j.value("limits", json::object()).value("flood_window", 5);
        cfg.limits.cpu_threshold_pct = j.value("limits", json::object()).value("cpu_threshold_pct", 80);
        cfg.limits.ram_threshold_pct = j.value("limits", json::object()).value("ram_threshold_pct", 80);

        cfg.runtime.offline_mode = j.value("runtime", json::object()).value("offline_mode", true);
        cfg.runtime.state_dir = j.value("runtime", json::object()).value("state_dir", "/pteroprotect/runtime");
        cfg.runtime.quarantine_dir_name = j.value("runtime", json::object()).value("quarantine_dir_name", ".dann_quarantine");

        cfg.network.http_conn_limit = j.value("network", json::object()).value("http_conn_limit", 20);
        cfg.network.http_req_rate = j.value("network", json::object()).value("http_req_rate", 8);
        cfg.network.http_req_burst = j.value("network", json::object()).value("http_req_burst", 12);
        cfg.network.host_new_conn_per_ip = j.value("network", json::object()).value("host_new_conn_per_ip", 12);
        cfg.network.host_new_conn_burst = j.value("network", json::object()).value("host_new_conn_burst", 20);
        cfg.network.host_connlimit_per_ip = j.value("network", json::object()).value("host_connlimit_per_ip", 30);
        cfg.network.host_recent_hitcount = j.value("network", json::object()).value("host_recent_hitcount", 60);
        cfg.network.host_recent_window_sec = j.value("network", json::object()).value("host_recent_window_sec", 5);

        cfg.monitor.checkhost_enabled = j.value("monitor", json::object()).value("checkhost_enabled", true);
        cfg.monitor.checkhost_api_key = j.value("monitor", json::object()).value("checkhost_api_key", "");
        cfg.monitor.external_url = j.value("monitor", json::object()).value("external_url", "");
        cfg.monitor.challenge_path = j.value("monitor", json::object()).value("challenge_path", "/__pteroprotect/challenge/page");
        cfg.monitor.local_health_url = j.value("monitor", json::object()).value("local_health_url", "http://127.0.0.1:18080/api/system");
        cfg.monitor.check_interval_normal_sec = j.value("monitor", json::object()).value("check_interval_normal_sec", 5);
        cfg.monitor.check_interval_anomaly_sec = j.value("monitor", json::object()).value("check_interval_anomaly_sec", 2);
        cfg.monitor.checkhost_max_nodes = j.value("monitor", json::object()).value("checkhost_max_nodes", 8);
        cfg.monitor.checkhost_zero_node_threshold = j.value("monitor", json::object()).value("checkhost_zero_node_threshold", 3);
        cfg.monitor.external_fail_streak_threshold = j.value("monitor", json::object()).value("external_fail_streak_threshold", 3);
        cfg.monitor.latency_p95_ms_threshold = j.value("monitor", json::object()).value("latency_p95_ms_threshold", 10000.0);
        cfg.monitor.error_rate_threshold = j.value("monitor", json::object()).value("error_rate_threshold", 0.5);
        cfg.monitor.lockdown_ttl_sec = j.value("monitor", json::object()).value("lockdown_ttl_sec", 180);

        cfg.abuse.self_ddos_req_threshold = j.value("abuse", json::object()).value("self_ddos_req_threshold", 100);
        cfg.abuse.window_ms = j.value("abuse", json::object()).value("window_ms", 500);
        cfg.abuse.strike_window_sec = j.value("abuse", json::object()).value("strike_window_sec", 60);
        cfg.abuse.max_strikes = j.value("abuse", json::object()).value("max_strikes", 3);
        cfg.abuse.sigterm_grace_ms = j.value("abuse", json::object()).value("sigterm_grace_ms", 1500);
        cfg.abuse.then_sigkill = j.value("abuse", json::object()).value("then_sigkill", true);
        cfg.abuse.escalation_ttl_sec = j.value("abuse", json::object()).value("escalation_ttl_sec", 45);

        // PTLC (optional)
        if (j.contains("ptlc")) {
            cfg.ptlc.url     = env_or_default("DANN_PTLC_URL", j["ptlc"].value("url", ""));
            cfg.ptlc.api_key = env_or_default("DANN_PTLC_API_KEY", j["ptlc"].value("api_key", ""));
        }

        if (cfg.monitor.external_url.empty()) {
            cfg.monitor.external_url = cfg.ptlc.url;
        }

        validate_config(cfg);

        logger.info("✅ Config loaded from " + filename);
        
    } catch (const std::exception& e) {
        logger.error("❌ Failed to parse config file: " + std::string(e.what()));
        logger.error("   Falling back to safe defaults");
        cfg = Config();
    }
    
    return cfg;
}
