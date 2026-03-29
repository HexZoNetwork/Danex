#ifndef RESOURCE_MONITOR_H
#define RESOURCE_MONITOR_H

#include <string>
#include <vector>
#include <map>
#include <thread>
#include <atomic>
#include <mutex>
#include <ctime>

// One entry per server fetched from the PTLC client API list.
struct PtlcServerEntry {
    std::string identifier;    // short ID, e.g. "a1b2c3d4"
    std::string uuid;          // full UUID
    std::string name;
    int         cpu_limit;     // allocated CPU % (100 = 1 core; 0 = unlimited)
    long long   mem_limit_bytes; // allocated RAM in bytes (0 = unlimited)
};

// Real-time resource snapshot from /api/client/servers/{id}/resources
struct ResourceSnapshot {
    std::string state;         // "running", "offline", etc.
    bool        is_suspended;
    double      cpu_absolute;  // actual CPU % consumed
    long long   mem_bytes;     // actual RAM bytes consumed
    long long   net_rx_bytes;  // inbound bytes (from Wings/API when available)
    long long   net_tx_bytes;  // outbound bytes (from Wings/API when available)
};

class ResourceMonitor {
private:
    std::string ptlc_url;
    std::string api_key;
    std::string state_file;
    bool offline_mode;
    int cpu_threshold_pct;     // flag when utilisation >= this
    int ram_threshold_pct;
    int check_interval;        // seconds between full sweeps

    std::atomic<bool> running;
    std::thread monitor_thread;

    // Per-server anti-false-positive counters and last-action timestamps
    std::map<std::string, int>    consecutive_cpu_hit;
    std::map<std::string, int>    consecutive_ram_hit;
    std::map<std::string, int>    consecutive_net_hit;
    std::map<std::string, int>    restart_count;
    std::map<std::string, int>    resource_strikes;
    std::map<std::string, long long> last_activity_log_id;
    std::map<std::string, time_t> last_action;
    std::map<std::string, double> trust_score;
    std::map<std::string, double> cpu_ema;
    std::map<std::string, double> ram_ema;
    std::map<std::string, double> net_ema;
    std::map<std::string, long long> bw_window_base_rx;
    std::map<std::string, long long> bw_window_base_tx;
    std::map<std::string, time_t> bw_window_start;

    long long bandwidth_in_limit_bytes;
    long long bandwidth_out_limit_bytes;
    int bandwidth_window_sec;

    // Cached server list (refreshed every 5 minutes)
    std::vector<PtlcServerEntry> server_cache;
    time_t server_cache_time;
    std::mutex state_mutex;

    static size_t write_cb(void* data, size_t size, size_t nmemb, std::string* out);
    std::string   http_get(const std::string& url);

    bool refresh_server_list();
    bool get_resources(const std::string& identifier, const std::string& uuid, ResourceSnapshot& snap);
    void load_state();
    void save_state();
    void ensure_runtime_paths();
    void ensure_iptables_chain();

    void check_all();
    void handle_server(const PtlcServerEntry& srv);

public:
    ResourceMonitor();
    ~ResourceMonitor();

    // Call before start(); skips monitor if url/key are empty.
    void init(const std::string& url, const std::string& key,
              int cpu_pct, int ram_pct, int interval);

    void start();
    void stop();
    void run_loop();
};

extern ResourceMonitor res_monitor;

#endif
