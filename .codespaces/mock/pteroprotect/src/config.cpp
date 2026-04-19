#include "config.h"
#include "logger.h"
#include <cstdlib>
#include <fstream>
#include <iostream>

using json = nlohmann::json;

namespace {
std::string env_or_default(const char* key, const std::string& fallback) {
    const char* value = std::getenv(key);
    return (value != nullptr && *value != '\0') ? std::string(value) : fallback;
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

        // PTLC (optional)
        if (j.contains("ptlc")) {
            cfg.ptlc.url     = env_or_default("DANN_PTLC_URL", j["ptlc"].value("url", ""));
            cfg.ptlc.api_key = env_or_default("DANN_PTLC_API_KEY", j["ptlc"].value("api_key", ""));
        }
        
        logger.info("✅ Config loaded from " + filename);
        
    } catch (const std::exception& e) {
        logger.error("❌ Failed to parse config file: " + std::string(e.what()));
    }
    
    return cfg;
}
