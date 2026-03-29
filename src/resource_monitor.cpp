#include "resource_monitor.h"
#include "db_guard.h"
#include "telegram.h"
#include "logger.h"

#include <curl/curl.h>
#include <nlohmann/json.hpp>

#include <sstream>
#include <iomanip>
#include <cstring>
#include <ctime>
#include <algorithm>
#include <chrono>
#include <cctype>
#include <cstdlib>
#include <fstream>
#include <set>
#include <regex>
#include <sys/stat.h>
#include <arpa/inet.h>

using json = nlohmann::json;

// ─────────────────────────────────────────────────────────────────────────────
// Anti-false-positive constants
// ─────────────────────────────────────────────────────────────────────────────
static const int NORMAL_HITS_REQUIRED = 3;   // consecutive readings to trigger
static const int HARD_HITS_REQUIRED   = 2;   // fewer hits needed at extreme usage
static const double HARD_THRESHOLD    = 98.0; // "extreme" utilisation %
static const double HOT_THRESHOLD     = 80.0; // sustained "killer" behaviour
// Allow temporary overhead (install/auth bursts) before enforcement math.
static const double CPU_ENFORCEMENT_BUFFER_MULTIPLIER = 1.30;
static const int ACTION_COOLDOWN_SEC  = 300;  // 5-min cooldown per server
static const int SERVER_CACHE_TTL_SEC = 300;  // refresh server list every 5 min
static const int STARTUP_GRACE_SECONDS = 90;
static const int RESOURCE_SUSPEND_STRIKES = 3;
static const double UNLIMITED_CPU_WARN_ABS = 150.0;
static const double UNLIMITED_CPU_HARD_ABS = 150.0;
static const long long UNLIMITED_RAM_WARN_BYTES = 4LL * 1024 * 1024 * 1024;
static const long long UNLIMITED_RAM_HARD_BYTES = 8LL * 1024 * 1024 * 1024;
static const long long DEFAULT_BANDWIDTH_LIMIT_BYTES = 20LL * 1024 * 1024 * 1024; // 20GiB in and 20GiB out
static const int DEFAULT_BANDWIDTH_WINDOW_SEC = 3 * 60 * 60; // 3 hours
static const int MAX_RESTART_BEFORE_SUSPEND = 5;
static const int IPTABLES_BLOCK_CONN_THRESHOLD = 120;
static const int NET_WARNING_CONN_THRESHOLD = 250;
static const int NET_HARD_CONN_THRESHOLD = 700;
static const int NET_WARNING_UNIQUE_IPS = 40;
static const int NET_HARD_UNIQUE_IPS = 100;
static const int SELF_DDOS_CONN_THRESHOLD = 120;
static const int SELF_DDOS_HARD_THRESHOLD = 300;

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────
namespace {

struct InboundStats {
    int total_conns = 0;
    int local_conns = 0;
    int external_conns = 0;
    int unique_ips = 0;
    int unique_external_ips = 0;
    int max_ip_conns = 0;
    bool self_ddos = false;
    bool l7_flood = false;
    bool infra_only_local = false;
    std::string top_summary;
    std::string actor_summary;
};

struct WingsConfigSnapshot {
    std::string remote_url;
    std::string docker_interface_ip;
    std::string docker_network_name;
};

struct ProcessAbuseInfo {
    bool suspicious = false;
    std::string summary;
};

struct ActivityAbuseInfo {
    bool suspicious = false;
    bool hard = false;
    int score = 0;
    long long last_id = 0;
    std::string summary;
};

struct ApiTrafficProfile {
    bool enabled = false;
    int net_warning_conn = NET_WARNING_CONN_THRESHOLD;
    int net_hard_conn = NET_HARD_CONN_THRESHOLD;
    int net_warning_unique_ips = NET_WARNING_UNIQUE_IPS;
    int net_hard_unique_ips = NET_HARD_UNIQUE_IPS;
    int self_ddos_conn = SELF_DDOS_CONN_THRESHOLD;
    int self_ddos_hard = SELF_DDOS_HARD_THRESHOLD;
    int net_hits_required = HARD_HITS_REQUIRED;
};

std::string resolve_local_container_ref(const std::string& identifier, const std::string& uuid = "");

std::string shell_quote_single(const std::string& value) {
    std::string escaped = "'";
    for (char c : value) {
        if (c == '\'') escaped += "'\\''";
        else escaped += c;
    }
    escaped += "'";
    return escaped;
}

bool is_safe_docker_ref_token(const std::string& value) {
    if (value.empty() || value.size() > 128) return false;
    static const std::regex safe_re(R"(^[A-Za-z0-9][A-Za-z0-9_.:-]*$)");
    return std::regex_match(value, safe_re);
}

bool is_safe_hostname_token(const std::string& value) {
    if (value.empty() || value.size() > 255) return false;
    static const std::regex safe_re(R"(^[A-Za-z0-9][A-Za-z0-9._-]*$)");
    return std::regex_match(value, safe_re);
}

bool is_safe_numeric_token(const std::string& value) {
    if (value.empty() || value.size() > 16) return false;
    return std::all_of(value.begin(), value.end(), [](unsigned char c) { return std::isdigit(c) != 0; });
}

bool is_safe_ip_literal_token(const std::string& value) {
    if (value.empty() || value.size() > 64) return false;
    static const std::regex safe_re(R"(^[0-9A-Fa-f:.]+$)");
    return std::regex_match(value, safe_re);
}

std::string trim_copy(const std::string& s) {
    size_t a = s.find_first_not_of(" \n\r\t");
    if (a == std::string::npos) return "";
    size_t b = s.find_last_not_of(" \n\r\t");
    return s.substr(a, b - a + 1);
}

long long parse_env_ll(const char* key, long long fallback) {
    const char* raw = std::getenv(key);
    if (!raw || *raw == '\0') return fallback;
    try {
        std::string value = trim_copy(raw);
        if (value.empty()) return fallback;
        return std::stoll(value);
    } catch (...) {
        return fallback;
    }
}

std::string to_lower_copy_res(std::string s) {
    std::transform(s.begin(), s.end(), s.begin(), [](unsigned char c) {
        return (char)std::tolower(c);
    });
    return s;
}

std::string exec_read_all(const std::string& cmd) {
    FILE* p = popen(cmd.c_str(), "r");
    if (!p) return "";
    char buf[512];
    std::string out;
    while (fgets(buf, sizeof(buf), p)) out += buf;
    pclose(p);
    return out;
}

long long get_host_total_ram_bytes() {
    static long long cached = -1;
    if (cached >= 0) return cached;

    std::ifstream f("/proc/meminfo");
    if (!f.is_open()) {
        cached = 0;
        return cached;
    }
    std::string key;
    long long kb = 0;
    std::string unit;
    while (f >> key >> kb >> unit) {
        if (key == "MemTotal:") {
            cached = kb * 1024LL;
            return cached;
        }
    }
    cached = 0;
    return cached;
}

std::string get_guard_home_for_monitor() {
    const char* env_home = std::getenv("DANN_GUARD_HOME");
    if (env_home && *env_home) return env_home;
    return "/pteroprotect";
}

bool contains_any(const std::string& haystack, const std::vector<std::string>& needles) {
    for (const auto& needle : needles) {
        if (!needle.empty() && haystack.find(needle) != std::string::npos) return true;
    }
    return false;
}

std::string join_hits(const std::vector<std::string>& hits, const std::string& sep = " | ") {
    std::ostringstream out;
    for (size_t i = 0; i < hits.size(); ++i) {
        if (i > 0) out << sep;
        out << hits[i];
    }
    return out.str();
}

std::string json_prop_string(const std::string& raw, const std::string& key) {
    if (raw.empty()) return "";
    try {
        json j = json::parse(raw);
        if (!j.is_object() || !j.contains(key)) return "";
        const auto& v = j[key];
        if (v.is_string()) return v.get<std::string>();
        if (v.is_number_integer()) return std::to_string(v.get<long long>());
        if (v.is_number_float()) return std::to_string(v.get<double>());
        if (v.is_array()) {
            std::vector<std::string> items;
            for (const auto& item : v) {
                if (item.is_string()) items.push_back(item.get<std::string>());
            }
            return join_hits(items, ",");
        }
    } catch (...) {
    }
    return "";
}

bool looks_like_raw_ip_url(const std::string& text) {
    std::string s = to_lower_copy_res(text);
    return s.find("http://") != std::string::npos || s.find("https://") != std::string::npos
        ? (s.find("://1.") != std::string::npos || s.find("://2.") != std::string::npos ||
           s.find("://3.") != std::string::npos || s.find("://4.") != std::string::npos ||
           s.find("://5.") != std::string::npos || s.find("://6.") != std::string::npos ||
           s.find("://7.") != std::string::npos || s.find("://8.") != std::string::npos ||
           s.find("://9.") != std::string::npos)
        : false;
}

bool looks_like_raw_ip_fetch_command(const std::string& command) {
    if (command.empty()) return false;
    static const std::regex ip_fetch_re(
        R"((curl|wget)\s+([a-z]+://)?([0-9]{1,3}\.){3}[0-9]{1,3}([/:][^ ]*)?)",
        std::regex::icase);
    return std::regex_search(command, ip_fetch_re);
}

bool looks_like_suspicious_exec_target(const std::string& text) {
    std::string s = to_lower_copy_res(text);
    return contains_any(s, {
        "./x86_64", "./a.out", "./bot", "./miner", "./scan", "./upd", "./update",
        " ramabypass.jar", "/x86_64", ".elf", ".bin"
    });
}

ActivityAbuseInfo collect_recent_activity_abuse(int server_id, long long after_id) {
    ActivityAbuseInfo info;
    std::vector<ServerActivityEntry> rows = db.get_recent_server_activity(server_id, after_id, 30);
    if (rows.empty()) return info;

    int score = 0;
    bool hard = false;
    std::vector<std::string> hits;

    for (const auto& row : rows) {
        if (row.id > info.last_id) info.last_id = row.id;
        std::string event = to_lower_copy_res(row.event);
        std::string command = to_lower_copy_res(json_prop_string(row.properties_json, "command"));
        std::string file = to_lower_copy_res(json_prop_string(row.properties_json, "file"));
        std::string files = to_lower_copy_res(json_prop_string(row.properties_json, "files"));
        std::string url = to_lower_copy_res(json_prop_string(row.properties_json, "url"));
        std::string variable = to_lower_copy_res(json_prop_string(row.properties_json, "variable"));
        std::string new_value = to_lower_copy_res(json_prop_string(row.properties_json, "new"));

        if (event == "server:console.command") {
            if (contains_any(command, {"bash <(", "sh <(", "| sh", "| bash", "curl http", "wget http"}) &&
                contains_any(command, {"chmod +x", "chmod 777", "./", "/tmp/", "/dev/shm/"})) {
                score += 6;
                hard = true;
                hits.push_back("console:" + trim_copy(command));
                continue;
            }

            if (contains_any(command, {"curl ", "wget ", "busybox wget", "fetch "})) {
                score += looks_like_raw_ip_url(command) ? 3 : 2;
                if (looks_like_raw_ip_fetch_command(command)) {
                    score += 3;
                    hard = true;
                }
                if (looks_like_suspicious_exec_target(command) || contains_any(command, {"ramabypass", "x86_64"})) score += 2;
                hits.push_back("fetch:" + trim_copy(command));
            }

            if (contains_any(command, {"chmod 777", "chmod +x"})) {
                score += 2;
                if (looks_like_suspicious_exec_target(command) || contains_any(command, {"x86_64", "a.out", ".elf", ".bin"})) {
                    score += 3;
                    hard = true;
                }
                hits.push_back("chmod:" + trim_copy(command));
            }

            if (command.find("./") != std::string::npos &&
                !contains_any(command, {"./start.sh", "./entrypoint.sh", "./run.sh"})) {
                score += looks_like_suspicious_exec_target(command) ? 5 : 3;
                if (looks_like_suspicious_exec_target(command)) hard = true;
                hits.push_back("exec:" + trim_copy(command));
            }
        } else if (event == "server:file.pull") {
            if (!url.empty()) {
                int add = looks_like_raw_ip_url(url) ? 3 : 1;
                if (contains_any(url, {"x86_64", "a.out", ".elf", ".bin", "ramabypass"})) add += 2;
                score += add;
                hits.push_back("pull:" + trim_copy(url));
            }
        } else if (event == "server:file.uploaded" || event == "server:file.write") {
            std::string target = !file.empty() ? file : files;
            if (contains_any(target, {"x86_64", "a.out", "ramabypass.jar", "/bot", "/miner"})) {
                score += 3;
                hits.push_back("file:" + trim_copy(target));
            }
        } else if (event == "server:startup.edit") {
            if ((variable == "cmd_run" || variable == "startup" || variable == "command") &&
                contains_any(new_value, {"curl ", "wget ", "./", "bash -c", "sh -c", "java -jar ramabypass.jar"})) {
                score += 4;
                if (contains_any(new_value, {"./x86_64", "ramabypass.jar"})) hard = true;
                hits.push_back("startup:" + trim_copy(new_value));
            }
        }
    }

    info.score = score;
    info.hard = hard;
    info.suspicious = hard || score >= 6 || (score >= 4 && hits.size() >= 2);
    if (!hits.empty()) {
        if (hits.size() > 4) hits.resize(4);
        info.summary = join_hits(hits);
    }
    return info;
}

bool looks_like_api_service(const ServerInfo& info, const PtlcServerEntry& srv) {
    std::string text = to_lower_copy_res(
        info.name + " " + info.egg_name + " " + info.nest_name + " " +
        srv.name + " " + info.uuid + " " + srv.identifier);

    static const std::vector<std::string> api_markers = {
        " api", "api ", "rest", "graphql", "express", "fastify", "koa",
        "nestjs", "nodejs", "node.js", "backend", "microservice", "webhook"
    };

    for (const auto& marker : api_markers) {
        if (text.find(marker) != std::string::npos) return true;
    }
    return false;
}

ApiTrafficProfile get_api_profile(const ServerInfo& info, const PtlcServerEntry& srv) {
    ApiTrafficProfile p;
    p.enabled = looks_like_api_service(info, srv);
    if (!p.enabled) return p;

    p.net_warning_conn = 700;
    p.net_hard_conn = 1800;
    p.net_warning_unique_ips = 120;
    p.net_hard_unique_ips = 260;
    p.self_ddos_conn = 260;
    p.self_ddos_hard = 600;
    p.net_hits_required = 3;
    return p;
}

WingsConfigSnapshot read_wings_config() {
    WingsConfigSnapshot cfg;
    std::ifstream file("/etc/pterodactyl/config.yml");
    if (!file.is_open()) return cfg;

    std::string line;
    bool in_docker = false;
    bool in_network = false;
    while (std::getline(file, line)) {
        std::string raw = trim_copy(line);
        if (raw.empty() || raw[0] == '#') continue;

        size_t indent = line.find_first_not_of(' ');
        if (indent == std::string::npos) indent = 0;

        if (indent == 0) {
            in_docker = raw == "docker:";
            in_network = false;
            if (raw.find("remote:") == 0) {
                cfg.remote_url = trim_copy(raw.substr(std::string("remote:").size()));
            }
            continue;
        }

        if (in_docker && indent == 2) {
            in_network = raw == "network:";
            continue;
        }

        if (in_docker && in_network && indent >= 4) {
            if (raw.find("interface:") == 0) {
                cfg.docker_interface_ip = trim_copy(raw.substr(std::string("interface:").size()));
            } else if (raw.find("name:") == 0) {
                cfg.docker_network_name = trim_copy(raw.substr(std::string("name:").size()));
            }
        }
    }

    return cfg;
}

std::string extract_host_from_url(const std::string& url) {
    std::string v = trim_copy(url);
    size_t scheme = v.find("://");
    if (scheme != std::string::npos) v = v.substr(scheme + 3);
    size_t slash = v.find('/');
    if (slash != std::string::npos) v = v.substr(0, slash);
    size_t at = v.rfind('@');
    if (at != std::string::npos) v = v.substr(at + 1);
    size_t colon = v.rfind(':');
    if (colon != std::string::npos && v.find(']') == std::string::npos) v = v.substr(0, colon);
    if (!v.empty() && v.front() == '[' && v.back() == ']') v = v.substr(1, v.size() - 2);
    return trim_copy(v);
}

std::set<std::string> resolve_host_ips(const std::string& host) {
    std::set<std::string> ips;
    if (!is_safe_hostname_token(host)) return ips;
    std::string cmd = "getent ahosts " + shell_quote_single(host) + " 2>/dev/null";
    std::stringstream ss(exec_read_all(cmd));
    std::string line;
    while (std::getline(ss, line)) {
        std::stringstream ls(line);
        std::string ip;
        if (ls >> ip) ips.insert(trim_copy(ip));
    }
    return ips;
}

std::set<std::string> get_wings_known_ips() {
    static std::set<std::string> ips = [] {
        WingsConfigSnapshot cfg = read_wings_config();
        std::set<std::string> out;
        if (!cfg.docker_interface_ip.empty()) out.insert(cfg.docker_interface_ip);
        std::string remote_host = extract_host_from_url(cfg.remote_url);
        std::set<std::string> resolved = resolve_host_ips(remote_host);
        out.insert(resolved.begin(), resolved.end());
        return out;
    }();
    return ips;
}

std::vector<std::string> split_copy(const std::string& s, char delim) {
    std::vector<std::string> parts;
    std::stringstream ss(s);
    std::string item;
    while (std::getline(ss, item, delim)) parts.push_back(item);
    return parts;
}

long long parse_size_to_bytes(std::string text) {
    text = trim_copy(text);
    if (text.empty()) return 0;

    text.erase(std::remove(text.begin(), text.end(), 'i'), text.end());
    text.erase(std::remove(text.begin(), text.end(), 'B'), text.end());

    size_t idx = 0;
    double value = std::strtod(text.c_str(), nullptr);
    while (idx < text.size() &&
           (std::isdigit((unsigned char)text[idx]) || text[idx] == '.' || text[idx] == ' ')) {
        idx++;
    }

    std::string unit = trim_copy(text.substr(idx));
    double mult = 1.0;
    if (unit == "K") mult = 1024.0;
    else if (unit == "M") mult = 1024.0 * 1024.0;
    else if (unit == "G") mult = 1024.0 * 1024.0 * 1024.0;
    else if (unit == "T") mult = 1024.0 * 1024.0 * 1024.0 * 1024.0;
    return (long long)(value * mult);
}

long long get_container_uptime_seconds(const std::string& identifier, const std::string& uuid = "") {
    std::string ref = resolve_local_container_ref(identifier, uuid);
    if (!is_safe_docker_ref_token(ref)) return -1;

    std::string started_at = trim_copy(exec_read_all(
        "docker inspect --format '{{.State.StartedAt}}' " + shell_quote_single(ref) + " 2>/dev/null"));
    if (started_at.empty() || started_at == "0001-01-01T00:00:00Z") return -1;

    std::string epoch = trim_copy(exec_read_all(
        "date -u -d " + std::string("'") + started_at + std::string("'") + " +%s 2>/dev/null"));
    if (epoch.empty()) return -1;

    long long started_ts = atoll(epoch.c_str());
    if (started_ts <= 0) return -1;

    long long now_ts = (long long)time(nullptr);
    if (now_ts < started_ts) return 0;
    return now_ts - started_ts;
}

bool env_offline_enabled() {
    const char* v = std::getenv("DANN_GUARD_OFFLINE");
    if (!v) return false;
    std::string s = v;
    std::transform(s.begin(), s.end(), s.begin(), [](unsigned char c) { return (char)std::tolower(c); });
    return s == "1" || s == "true" || s == "yes" || s == "on";
}

std::string get_container_pid(const std::string& identifier) {
    if (!is_safe_docker_ref_token(identifier)) return "";
    return trim_copy(exec_read_all(
        "docker inspect --format '{{.State.Pid}}' " + shell_quote_single(identifier) + " 2>/dev/null"));
}

ProcessAbuseInfo collect_process_abuse(const std::string& identifier) {
    ProcessAbuseInfo info;
    std::string pid = get_container_pid(identifier);
    if (pid.empty() || pid == "0") return info;

    std::string cmd =
        "nsenter -t " + pid + " -m -p -- sh -c "
        "\"for f in /proc/[0-9]*/cmdline; do tr '\\000' ' ' < \\\"$f\\\" 2>/dev/null; echo; done\" 2>/dev/null";
    std::string body = exec_read_all(cmd);
    if (body.empty()) return info;

    static const std::vector<std::string> critical_patterns = {
        " masscan", "zmap", "zgrab", "nmap ", "nmap-", "hping3", "nping",
        "slowhttptest", "goldeneye", "xerxes", "torshammer",
        "locust ", "medusa ", "hydra ",
        "tmate ", "ngrok ", "cloudflared tunnel",
        "socat tcp", "nc -e ", "ncat -e ",
        "169.254.169.254", "169.254.170.2", "100.100.100.200",
        "metadata.google.internal", "latest/meta-data", "computeMetadata/v1",
        "/var/lib/cloud/", "/run/cloud-init", "/etc/cloud/"
    };

    std::stringstream ss(body);
    std::string line;
    std::vector<std::string> hits;
    while (std::getline(ss, line)) {
        std::string lc = to_lower_copy_res(trim_copy(line));
        if (lc.empty()) continue;

        for (const auto& pattern : critical_patterns) {
            if (lc.find(pattern) != std::string::npos) {
                hits.push_back(trim_copy(line));
                break;
            }
        }

        if ((int)hits.size() >= 3) break;
    }

    if (hits.empty()) return info;

    info.suspicious = true;
    std::ostringstream out;
    for (size_t i = 0; i < hits.size(); ++i) {
        if (i > 0) out << " | ";
        out << hits[i];
    }
    info.summary = out.str();
    return info;
}

std::string resolve_local_container_ref(const std::string& identifier, const std::string& uuid) {
    std::vector<std::string> candidates;
    if (!identifier.empty()) candidates.push_back(identifier);
    if (!uuid.empty()) candidates.push_back(uuid);

    for (const auto& candidate : candidates) {
        if (!is_safe_docker_ref_token(candidate)) continue;
        std::string inspect = trim_copy(exec_read_all(
            "docker inspect --format '{{.Id}}' " + shell_quote_single(candidate) + " 2>/dev/null"));
        if (!inspect.empty()) return candidate;
    }

    if (!uuid.empty() && is_safe_docker_ref_token(uuid)) {
        std::string by_label = trim_copy(exec_read_all(
            "docker ps --filter " + shell_quote_single("label=service_uuid=" + uuid) + " --format '{{.ID}}' | head -n 1"));
        if (!by_label.empty()) return by_label;

        std::string by_name = trim_copy(exec_read_all(
            "docker ps --filter " + shell_quote_single("name=" + uuid) + " --format '{{.ID}}' | head -n 1"));
        if (!by_name.empty()) return by_name;
    }

    if (!identifier.empty() && is_safe_docker_ref_token(identifier)) {
        std::string by_name = trim_copy(exec_read_all(
            "docker ps --filter " + shell_quote_single("name=" + identifier) + " --format '{{.ID}}' | head -n 1"));
        if (!by_name.empty()) return by_name;
    }

    return "";
}

bool restart_container(const std::string& identifier, const std::string& uuid = "") {
    std::string ref = resolve_local_container_ref(identifier, uuid);
    if (!is_safe_docker_ref_token(ref)) return false;
    int rc = system(("docker restart -t 5 " + shell_quote_single(ref) + " >/dev/null 2>&1").c_str());
    return rc == 0;
}

std::string detect_dropper_artifact(const std::string& server_uuid) {
    if (server_uuid.empty()) return "";
    static const std::vector<std::string> suspects = {
        "x86_64", "a.out", "bot", "miner", "scan", "upd", "update", "ramabypass.jar"
    };

    for (const auto& name : suspects) {
        std::string path = "/var/lib/pterodactyl/volumes/" + server_uuid + "/" + name;
        struct stat st{};
        if (stat(path.c_str(), &st) == 0 && S_ISREG(st.st_mode) && st.st_size >= 4096) {
            return name + " (" + std::to_string((long long)st.st_size / 1024LL) + "KB)";
        }
    }
    return "";
}

bool stop_container_now(const std::string& identifier, const std::string& uuid = "") {
    std::string ref = resolve_local_container_ref(identifier, uuid);
    if (!is_safe_docker_ref_token(ref)) return false;
    int rc = system(("docker stop -t 3 " + shell_quote_single(ref) + " >/dev/null 2>&1").c_str());
    if (rc == 0) return true;
    return system(("docker kill " + shell_quote_single(ref) + " >/dev/null 2>&1").c_str()) == 0;
}

bool send_sigterm_container(const std::string& identifier, const std::string& uuid = "") {
    std::string ref = resolve_local_container_ref(identifier, uuid);
    if (!is_safe_docker_ref_token(ref)) return false;
    int rc = system(("docker kill --signal=TERM " + shell_quote_single(ref) + " >/dev/null 2>&1").c_str());
    return rc == 0;
}

bool read_local_resources(const std::string& identifier, const std::string& uuid, ResourceSnapshot& snap) {
    std::string ref = resolve_local_container_ref(identifier, uuid);
    if (!is_safe_docker_ref_token(ref)) return false;

    std::string state_line = trim_copy(exec_read_all(
        "docker inspect --format '{{.State.Status}}|{{.State.Running}}' " + shell_quote_single(ref) + " 2>/dev/null"));
    std::vector<std::string> state_parts = split_copy(state_line, '|');
    if (state_parts.size() < 2) return false;

    snap.state = trim_copy(state_parts[0]);
    snap.is_suspended = false;

    std::string stat_line = trim_copy(exec_read_all(
        "docker stats --no-stream --format '{{.CPUPerc}}|{{.MemUsage}}' " + shell_quote_single(ref) + " 2>/dev/null"));
    std::vector<std::string> stat_parts = split_copy(stat_line, '|');
    if (stat_parts.size() < 2) return false;

    std::string cpu_text = trim_copy(stat_parts[0]);
    cpu_text.erase(std::remove(cpu_text.begin(), cpu_text.end(), '%'), cpu_text.end());
    snap.cpu_absolute = std::strtod(cpu_text.c_str(), nullptr);

    std::string mem_text = trim_copy(stat_parts[1]);
    size_t slash = mem_text.find('/');
    if (slash != std::string::npos) mem_text = mem_text.substr(0, slash);
    snap.mem_bytes = parse_size_to_bytes(mem_text);
    snap.net_rx_bytes = 0;
    snap.net_tx_bytes = 0;
    return true;
}

bool is_private_or_local_ip(const std::string& ip) {
    std::string normalized = trim_copy(ip);
    if (normalized.empty()) return true;
    if (normalized == "127.0.0.1" || normalized == "::1") return true;

    in_addr addr4{};
    if (inet_pton(AF_INET, normalized.c_str(), &addr4) == 1) {
        uint32_t host = ntohl(addr4.s_addr);
        if ((host & 0xFF000000u) == 0x0A000000u) return true;        // 10.0.0.0/8
        if ((host & 0xFFF00000u) == 0xAC100000u) return true;        // 172.16.0.0/12
        if ((host & 0xFFFF0000u) == 0xC0A80000u) return true;        // 192.168.0.0/16
        if ((host & 0xFF000000u) == 0x7F000000u) return true;        // 127.0.0.0/8
        if ((host & 0xFFFF0000u) == 0xA9FE0000u) return true;        // 169.254.0.0/16
        if ((host & 0xFFC00000u) == 0x64400000u) return true;        // 100.64.0.0/10
        return false;
    }

    in6_addr addr6{};
    if (inet_pton(AF_INET6, normalized.c_str(), &addr6) == 1) {
        if ((addr6.s6_addr[0] & 0xFE) == 0xFC) return true;          // fc00::/7
        if (addr6.s6_addr[0] == 0xFE && (addr6.s6_addr[1] & 0xC0) == 0x80) return true; // fe80::/10
        static const unsigned char loopback[16] = {0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,1};
        return std::memcmp(addr6.s6_addr, loopback, 16) == 0;
    }

    return false;
}

std::set<std::string> get_host_ip_set() {
    std::set<std::string> ips;
    ips.insert("127.0.0.1");
    ips.insert("::1");

    std::string body = exec_read_all("hostname -I 2>/dev/null");
    std::stringstream hs(body);
    std::string ip;
    while (hs >> ip) ips.insert(trim_copy(ip));

    body = exec_read_all("ip -o addr show 2>/dev/null");
    std::stringstream ss(body);
    std::string line;
    while (std::getline(ss, line)) {
        line = trim_copy(line);
        if (line.empty()) continue;
        std::vector<std::string> parts = split_copy(line, ' ');
        for (size_t i = 0; i + 1 < parts.size(); ++i) {
            if ((parts[i] == "inet" || parts[i] == "inet6") && !parts[i + 1].empty()) {
                std::string addr = parts[i + 1];
                size_t slash = addr.find('/');
                if (slash != std::string::npos) addr = addr.substr(0, slash);
                if (!addr.empty()) ips.insert(addr);
            }
        }
    }

    return ips;
}

bool is_host_ip(const std::string& ip) {
    static std::set<std::string> host_ips = get_host_ip_set();
    return host_ips.count(ip) > 0;
}

bool is_wings_known_ip(const std::string& ip) {
    static std::set<std::string> wings_ips = get_wings_known_ips();
    return wings_ips.count(ip) > 0;
}

std::map<std::string, std::string> get_container_ip_actor_map() {
    std::map<std::string, std::string> actors;
    std::string body = exec_read_all("docker ps --format '{{.ID}}|{{.Label \"service_uuid\"}}|{{.Names}}' 2>/dev/null");
    std::stringstream ss(body);
    std::string line;
    while (std::getline(ss, line)) {
        line = trim_copy(line);
        if (line.empty()) continue;
        std::vector<std::string> parts = split_copy(line, '|');
        if (parts.size() < 3) continue;
        std::string id = trim_copy(parts[0]);
        std::string uuid = trim_copy(parts[1]);
        std::string name = trim_copy(parts[2]);
        if (id.empty()) continue;

        std::string ips = exec_read_all(
            "docker inspect --format '{{range .NetworkSettings.Networks}}{{.IPAddress}} {{end}}' " + shell_quote_single(id) + " 2>/dev/null");
        std::stringstream is(ips);
        std::string ip;
        while (is >> ip) {
            if (!trim_copy(ip).empty()) {
                actors[trim_copy(ip)] = !uuid.empty() ? (name + "|" + uuid) : name;
            }
        }
    }
    return actors;
}

bool block_iptables_ip(const std::string& ip) {
    if (ip.empty() || is_private_or_local_ip(ip)) return false;
    if (!is_safe_ip_literal_token(ip)) return false;
    std::string check_cmd = "iptables -C PTEROPROTECT -s " + ip + " -j DROP >/dev/null 2>&1";
    if (system(check_cmd.c_str()) == 0) return true;
    std::string add_cmd = "iptables -I PTEROPROTECT -s " + ip + " -j DROP >/dev/null 2>&1";
    return system(add_cmd.c_str()) == 0;
}

InboundStats collect_inbound_stats(const std::string& identifier) {
    InboundStats stats;
    static std::map<std::string, std::string> actor_map = get_container_ip_actor_map();
    std::string pid = get_container_pid(identifier);
    if (pid.empty() || pid == "0" || !is_safe_numeric_token(pid)) return stats;

    std::string cmd = "nsenter -t " + pid + " -n ss -tnH state established,syn-recv,fin-wait-1,fin-wait-2,close-wait,last-ack,time-wait 2>/dev/null";
    std::string body = exec_read_all(cmd);
    if (body.empty()) return stats;

    std::map<std::string, int> counts;
    std::map<std::string, int> external_counts;
    std::map<std::string, int> local_counts;
    bool infra_only_local = true;
    std::stringstream ss(body);
    std::string line;
    while (std::getline(ss, line)) {
        line = trim_copy(line);
        if (line.empty()) continue;

        std::stringstream ls(line);
        std::string state, recvq, sendq, local_addr, peer_addr;
        if (!(ls >> state >> recvq >> sendq >> local_addr >> peer_addr)) continue;

        size_t pos = peer_addr.rfind(':');
        std::string ip = pos == std::string::npos ? peer_addr : peer_addr.substr(0, pos);
        if (!ip.empty() && ip.front() == '[' && ip.back() == ']') ip = ip.substr(1, ip.size() - 2);
        if (ip.empty()) continue;

        counts[ip]++;
        stats.total_conns++;

        bool localish = is_private_or_local_ip(ip) || is_host_ip(ip);
        if (localish) {
            stats.local_conns++;
            local_counts[ip]++;
            if (!is_wings_known_ip(ip) && !is_host_ip(ip)) infra_only_local = false;
        } else {
            stats.external_conns++;
            external_counts[ip]++;
        }
    }

    std::vector<std::pair<std::string, int> > items(counts.begin(), counts.end());
    std::sort(items.begin(), items.end(),
              [](const std::pair<std::string, int>& a, const std::pair<std::string, int>& b) {
                  return a.second > b.second;
              });

    stats.unique_ips = (int)counts.size();
    stats.unique_external_ips = (int)external_counts.size();

    std::ostringstream out;
    if (stats.local_conns > 0) out << "inbound_local=" << stats.local_conns << " ";
    int shown = 0;
    for (const auto& kv : items) {
        if (kv.second > stats.max_ip_conns) stats.max_ip_conns = kv.second;
        out << kv.first << "=" << kv.second;
        if (++shown >= 3) break;
        out << " ";
    }

    stats.infra_only_local = !local_counts.empty() && infra_only_local;
    stats.self_ddos = !stats.infra_only_local &&
                      stats.local_conns >= SELF_DDOS_CONN_THRESHOLD &&
                      stats.local_conns >= stats.external_conns;
    stats.l7_flood = stats.unique_external_ips >= NET_WARNING_UNIQUE_IPS ||
                     (stats.external_conns >= NET_WARNING_CONN_THRESHOLD && stats.max_ip_conns < IPTABLES_BLOCK_CONN_THRESHOLD);

    if (!local_counts.empty()) {
        std::vector<std::pair<std::string, int> > locals(local_counts.begin(), local_counts.end());
        std::sort(locals.begin(), locals.end(),
                  [](const std::pair<std::string, int>& a, const std::pair<std::string, int>& b) {
                      return a.second > b.second;
                  });

        std::ostringstream actor;
        int actor_shown = 0;
        for (const auto& kv : locals) {
            if (actor_shown > 0) actor << " ";
            actor << kv.first << "=" << kv.second;

            auto it = actor_map.find(kv.first);
            if (it != actor_map.end()) {
                actor << "[" << it->second << "]";
            } else if (is_wings_known_ip(kv.first)) {
                actor << "[wings/panel]";
            } else if (is_host_ip(kv.first)) {
                actor << "[host]";
            }

            if (++actor_shown >= 3) break;
        }
        stats.actor_summary = actor.str();
    }

    stats.top_summary = trim_copy(out.str());
    return stats;
}

std::string block_abusive_inbound_ips(const std::string& identifier) {
    std::string pid = get_container_pid(identifier);
    if (pid.empty() || pid == "0" || !is_safe_numeric_token(pid)) return "";

    std::string cmd = "nsenter -t " + pid + " -n ss -tnH 2>/dev/null";
    std::string body = exec_read_all(cmd);
    if (body.empty()) return "";

    std::map<std::string, int> counts;
    std::stringstream ss(body);
    std::string line;
    while (std::getline(ss, line)) {
        line = trim_copy(line);
        if (line.empty()) continue;

        std::stringstream ls(line);
        std::string state, recvq, sendq, local_addr, peer_addr;
        if (!(ls >> state >> recvq >> sendq >> local_addr >> peer_addr)) continue;

        size_t pos = peer_addr.rfind(':');
        std::string ip = pos == std::string::npos ? peer_addr : peer_addr.substr(0, pos);
        if (!ip.empty() && ip.front() == '[' && ip.back() == ']') ip = ip.substr(1, ip.size() - 2);
        if (ip.empty() || is_private_or_local_ip(ip)) continue;
        counts[ip]++;
    }

    std::vector<std::pair<std::string, int> > items(counts.begin(), counts.end());
    std::sort(items.begin(), items.end(),
              [](const std::pair<std::string, int>& a, const std::pair<std::string, int>& b) {
                  return a.second > b.second;
              });

    std::ostringstream blocked;
    int applied = 0;
    for (const auto& kv : items) {
        if (kv.second >= IPTABLES_BLOCK_CONN_THRESHOLD && block_iptables_ip(kv.first)) {
            if (applied > 0) blocked << ",";
            blocked << kv.first;
            if (++applied >= 5) break;
        }
    }
    return blocked.str();
}

std::string get_waktu_wib_res() {
    std::time_t now = std::time(nullptr);
    std::tm tm_wib = *std::localtime(&now);
    tm_wib.tm_hour += 7;
    std::mktime(&tm_wib);
    char buf[80];
    std::strftime(buf, sizeof(buf), "%d/%m/%Y %H:%M:%S WIB", &tm_wib);
    return std::string(buf);
}

std::string build_alert(const std::string& abuse_type,
                        const ServerInfo& info,
                        double cpu_pct_used, double cpu_absolute, int cpu_limit,
                        double ram_pct_used, long long mem_bytes, long long mem_limit_bytes,
                        const std::string& action_text) {
    std::string waktu = get_waktu_wib_res();
    std::string owner = info.first_name + " " + info.last_name;
    if (owner == " " || owner.empty()) owner = info.username;
    if (owner.empty()) owner = "Tidak Diketahui";

    long long ram_mb       = mem_bytes       / (1024LL * 1024);
    long long ram_limit_mb = mem_limit_bytes / (1024LL * 1024);

    std::ostringstream msg;
    msg << std::fixed << std::setprecision(1);
    msg << "<b>🛡️ DANN GUARD PROTECTION</b>\n"
        << "<b>🚨 " << abuse_type << "</b>\n"
        << "<code>⏱️ " << waktu << "</code>\n"
        << "━━━━━━━━━━━━━━━━━━━\n\n"
        << "<b>👤 OWNER INFORMATION</b>\n"
        << "<blockquote>"
        << "├─ Nama    : " << owner << "\n"
        << "├─ Username: @" << info.username << "\n"
        << "├─ Email   : " << info.email << "\n"
        << "├─ Server ID: <code>" << info.id << "</code>\n"
        << "└─ UUID    : <code>" << info.uuid << "</code>\n"
        << "</blockquote>\n\n"
        << "<b>📊 RESOURCE ABUSE</b>\n"
        << "<blockquote>";

    if (cpu_limit > 0)
        msg << "CPU : <code>" << cpu_absolute << "% / " << cpu_limit
            << "% limit (" << (int)cpu_pct_used << "%)</code>\n";

    if (mem_limit_bytes > 0)
        msg << "RAM : <code>" << ram_mb << " / " << ram_limit_mb
            << " MB (" << (int)ram_pct_used << "%)</code>\n";

    msg << "</blockquote>\n\n"
        << "<b>🛑 ACTION</b>\n"
        << "<blockquote>"
        << action_text
        << "</blockquote>\n\n"
        << "━━━━━━━━━━━━━━━━━━━\n"
        << "<b>👤 Creator:</b> @gantengdann\n"
        << "<b>📢 Channel:</b> @aboutdannz\n"
        << "<b>📢 Report:</b> @reportdann";

    return msg.str();
}

} // namespace

// ─────────────────────────────────────────────────────────────────────────────
// ResourceMonitor
// ─────────────────────────────────────────────────────────────────────────────
ResourceMonitor::ResourceMonitor()
    : ptlc_url(),
      api_key(),
      state_file(get_guard_home_for_monitor() + "/runtime/resource_monitor_state.json"),
      offline_mode(false),
      cpu_threshold_pct(90),
      ram_threshold_pct(90),
      check_interval(10),
      running(false),
      bandwidth_in_limit_bytes(DEFAULT_BANDWIDTH_LIMIT_BYTES),
      bandwidth_out_limit_bytes(DEFAULT_BANDWIDTH_LIMIT_BYTES),
      bandwidth_window_sec(DEFAULT_BANDWIDTH_WINDOW_SEC),
      server_cache(),
      server_cache_time(0) {}

ResourceMonitor::~ResourceMonitor() { stop(); }

void ResourceMonitor::init(const std::string& url, const std::string& key,
                           int cpu_pct, int ram_pct, int interval) {
    ptlc_url          = url;
    api_key           = key;
    cpu_threshold_pct = (cpu_pct > 0 && cpu_pct <= 100) ? cpu_pct : 90;
    ram_threshold_pct = (ram_pct > 0 && ram_pct <= 100) ? ram_pct : 90;
    check_interval    = (interval > 0) ? interval : 10;

    // Bandwidth quota per server, default: 20 GiB inbound + 20 GiB outbound per 3 hours.
    long long in_gib = parse_env_ll("PTEROPROTECT_SERVER_INBOUND_LIMIT_GIB", 20);
    long long out_gib = parse_env_ll("PTEROPROTECT_SERVER_OUTBOUND_LIMIT_GIB", 20);
    long long window_sec = parse_env_ll("PTEROPROTECT_SERVER_BANDWIDTH_WINDOW_SEC", DEFAULT_BANDWIDTH_WINDOW_SEC);

    if (in_gib < 1) in_gib = 20;
    if (out_gib < 1) out_gib = 20;
    if (window_sec < 300) window_sec = DEFAULT_BANDWIDTH_WINDOW_SEC;

    bandwidth_in_limit_bytes = in_gib * 1024LL * 1024LL * 1024LL;
    bandwidth_out_limit_bytes = out_gib * 1024LL * 1024LL * 1024LL;
    bandwidth_window_sec = static_cast<int>(window_sec);
}

void ResourceMonitor::start() {
    offline_mode = env_offline_enabled();
    if (!offline_mode && (ptlc_url.empty() || api_key.empty())) offline_mode = true;
    if (running) return;
    ensure_runtime_paths();
    load_state();
    if (offline_mode) ensure_iptables_chain();
    running = true;
    monitor_thread = std::thread(&ResourceMonitor::run_loop, this);
    if (offline_mode)
        logger.info("✅ Resource monitor started in OFFLINE docker mode");
    else
        logger.info("✅ Resource monitor started (CPU≥" + std::to_string(cpu_threshold_pct) +
                    "% RAM≥" + std::to_string(ram_threshold_pct) + "%)");

    logger.info("📶 Bandwidth quota: inbound=" +
                std::to_string(bandwidth_in_limit_bytes / (1024LL * 1024LL * 1024LL)) + "GiB"
                " outbound=" +
                std::to_string(bandwidth_out_limit_bytes / (1024LL * 1024LL * 1024LL)) + "GiB"
                " window=" + std::to_string(bandwidth_window_sec) + "s per server");
}

void ResourceMonitor::stop() {
    if (!running) return;
    running = false;
    if (monitor_thread.joinable()) monitor_thread.join();
    save_state();
}

void ResourceMonitor::run_loop() {
    while (running) {
        try {
            time_t start = time(nullptr);
            check_all();
            int elapsed   = (int)(time(nullptr) - start);
            int target_interval = offline_mode ? 1 : check_interval;
            int sleep_sec = std::max(1, target_interval - elapsed);
            std::this_thread::sleep_for(std::chrono::seconds(sleep_sec));
        } catch (...) {
            std::this_thread::sleep_for(std::chrono::seconds(5));
        }
    }
}

// ─── cURL write callback ─────────────────────────────────────────────────────
size_t ResourceMonitor::write_cb(void* data, size_t size, size_t nmemb, std::string* out) {
    size_t total = size * nmemb;
    out->append(static_cast<char*>(data), total);
    return total;
}

// ─── Authenticated GET → returns response body or "" on error ────────────────
std::string ResourceMonitor::http_get(const std::string& url) {
    if (offline_mode) return "";
    CURL* curl = curl_easy_init();
    if (!curl) return "";

    std::string response;
    struct curl_slist* headers = nullptr;
    headers = curl_slist_append(headers, ("Authorization: Bearer " + api_key).c_str());
    headers = curl_slist_append(headers, "Accept: application/json");
    headers = curl_slist_append(headers, "Content-Type: application/json");

    curl_easy_setopt(curl, CURLOPT_URL,           url.c_str());
    curl_easy_setopt(curl, CURLOPT_HTTPHEADER,     headers);
    curl_easy_setopt(curl, CURLOPT_WRITEFUNCTION,  write_cb);
    curl_easy_setopt(curl, CURLOPT_WRITEDATA,      &response);
    curl_easy_setopt(curl, CURLOPT_TIMEOUT,        10L);
    curl_easy_setopt(curl, CURLOPT_SSL_VERIFYPEER, 1L);

    CURLcode res = curl_easy_perform(curl);
    curl_slist_free_all(headers);
    curl_easy_cleanup(curl);

    if (res != CURLE_OK) {
        logger.error("PTLC API curl error: " + std::string(curl_easy_strerror(res)));
        return "";
    }
    return response;
}

// ─── Fetch (paginated) list of all servers from /api/client?type=admin ───────
bool ResourceMonitor::refresh_server_list() {
    if (offline_mode) {
        std::vector<PtlcServerEntry> servers;
        std::string body = exec_read_all("docker ps --format '{{.ID}}|{{.Label \"service_uuid\"}}|{{.Names}}' 2>/dev/null");
        std::stringstream ss(body);
        std::string line;

        while (std::getline(ss, line)) {
            line = trim_copy(line);
            if (line.empty()) continue;

            std::vector<std::string> parts = split_copy(line, '|');
            if (parts.size() < 3) continue;

            std::string container_id = trim_copy(parts[0]);
            std::string uuid = trim_copy(parts[1]);
            std::string name = trim_copy(parts[2]);
            if (container_id.empty() || uuid.empty()) continue;

            std::string limit_line = trim_copy(exec_read_all(
                "docker inspect --format '{{.HostConfig.NanoCpus}}|{{.HostConfig.Memory}}' " + shell_quote_single(container_id) + " 2>/dev/null"));
            std::vector<std::string> limit_parts = split_copy(limit_line, '|');

            PtlcServerEntry srv;
            srv.identifier = container_id;
            srv.uuid = uuid;
            srv.name = name;
            srv.cpu_limit = 0;
            srv.mem_limit_bytes = 0;

            if (limit_parts.size() >= 2) {
                long long nano_cpus = atoll(limit_parts[0].c_str());
                srv.mem_limit_bytes = atoll(limit_parts[1].c_str());
                if (nano_cpus > 0) srv.cpu_limit = (int)(nano_cpus / 10000000LL);
            }

            servers.push_back(srv);
        }

        std::lock_guard<std::mutex> lock(state_mutex);
        server_cache = servers;
        server_cache_time = time(nullptr);
        logger.info("Docker offline: cached " + std::to_string(servers.size()) + " container(s)");
        return true;
    }

    std::vector<PtlcServerEntry> servers;
    int page = 1;

    while (true) {
        std::string url = ptlc_url + "/api/client?type=admin&per_page=100&page=" +
                          std::to_string(page);
        std::string body = http_get(url);
        if (body.empty()) return false;

        try {
            json j = json::parse(body);
            if (!j.contains("data")) break;

            for (auto& item : j["data"]) {
                if (!item.contains("attributes")) continue;
                auto& attr = item["attributes"];

                PtlcServerEntry srv;
                srv.identifier = attr.value("identifier", "");
                srv.uuid       = attr.value("uuid",       "");
                srv.name       = attr.value("name",       "");

                if (attr.contains("limits")) {
                    srv.cpu_limit       = attr["limits"].value("cpu", 100);
                    long long mem_mb    = attr["limits"].value("memory", 512LL);
                    srv.mem_limit_bytes = mem_mb * 1024LL * 1024LL;
                } else {
                    srv.cpu_limit       = 100;
                    srv.mem_limit_bytes = 512LL * 1024LL * 1024LL;
                }

                if (!srv.identifier.empty() && !srv.uuid.empty())
                    servers.push_back(srv);
            }

            // Pagination
            int total_pages = 1;
            if (j.contains("meta") && j["meta"].contains("pagination"))
                total_pages = j["meta"]["pagination"].value("total_pages", 1);

            if (page >= total_pages) break;
            page++;

        } catch (...) {
            logger.error("PTLC: failed to parse server list (page " + std::to_string(page) + ")");
            return false;
        }
    }

    std::lock_guard<std::mutex> lock(state_mutex);
    server_cache      = servers;
    server_cache_time = time(nullptr);
    logger.info("PTLC: cached " + std::to_string(servers.size()) + " server(s)");
    return true;
}

// ─── Fetch real-time resource stats for one server ───────────────────────────
bool ResourceMonitor::get_resources(const std::string& identifier, const std::string& uuid, ResourceSnapshot& snap) {
    if (offline_mode) {
        return read_local_resources(identifier, uuid, snap);
    }

    std::string url  = ptlc_url + "/api/client/servers/" + identifier + "/resources";
    std::string body = http_get(url);
    if (body.empty()) return false;

    try {
        json j = json::parse(body);
        if (!j.contains("attributes")) return false;

        auto& attr     = j["attributes"];
        snap.state        = attr.value("current_state", "unknown");
        snap.is_suspended = attr.value("is_suspended",  false);

        if (attr.contains("resources")) {
            auto& res       = attr["resources"];
            snap.cpu_absolute = res.value("cpu_absolute",  0.0);
            snap.mem_bytes    = res.value("memory_bytes",  0LL);
            snap.net_rx_bytes = res.value("network_rx_bytes", 0LL);
            snap.net_tx_bytes = res.value("network_tx_bytes", 0LL);
        }

        // Some panel responses report "offline" even while the local Docker container is alive.
        // In that case, trust direct host metrics so CPU killers do not bypass enforcement.
        if ((snap.state != "running" || snap.cpu_absolute <= 0.0) &&
            read_local_resources(identifier, uuid, snap)) {
            return true;
        }
        return true;

    } catch (...) {
        logger.error("PTLC: failed to parse resources for " + identifier);
        return false;
    }
}

void ResourceMonitor::ensure_runtime_paths() {
    std::string runtime_dir = get_guard_home_for_monitor() + "/runtime";
    mkdir(runtime_dir.c_str(), 0755);
}

void ResourceMonitor::ensure_iptables_chain() {
    system("iptables -N PTEROPROTECT >/dev/null 2>&1 || true");
    system("iptables -C DOCKER-USER -j PTEROPROTECT >/dev/null 2>&1 || iptables -I DOCKER-USER -j PTEROPROTECT >/dev/null 2>&1");
    system("iptables -C PTEROPROTECT -s 127.0.0.1/32 -j RETURN >/dev/null 2>&1 || iptables -I PTEROPROTECT -s 127.0.0.1/32 -j RETURN >/dev/null 2>&1");
    system("iptables -C PTEROPROTECT -s 10.0.0.0/8 -j RETURN >/dev/null 2>&1 || iptables -I PTEROPROTECT -s 10.0.0.0/8 -j RETURN >/dev/null 2>&1");
    system("iptables -C PTEROPROTECT -s 172.16.0.0/12 -j RETURN >/dev/null 2>&1 || iptables -I PTEROPROTECT -s 172.16.0.0/12 -j RETURN >/dev/null 2>&1");
    system("iptables -C PTEROPROTECT -s 192.168.0.0/16 -j RETURN >/dev/null 2>&1 || iptables -I PTEROPROTECT -s 192.168.0.0/16 -j RETURN >/dev/null 2>&1");
}

void ResourceMonitor::load_state() {
    std::ifstream file(state_file);
    if (!file.is_open()) return;

    try {
        json j;
        file >> j;

        std::lock_guard<std::mutex> lock(state_mutex);
        restart_count.clear();
        resource_strikes.clear();
        last_activity_log_id.clear();
        last_action.clear();
        trust_score.clear();
        cpu_ema.clear();
        ram_ema.clear();
        net_ema.clear();
        bw_window_base_rx.clear();
        bw_window_base_tx.clear();
        bw_window_start.clear();

        if (j.contains("restart_count")) restart_count = j["restart_count"].get<std::map<std::string, int> >();
        if (j.contains("resource_strikes")) resource_strikes = j["resource_strikes"].get<std::map<std::string, int> >();
        if (j.contains("last_activity_log_id")) last_activity_log_id = j["last_activity_log_id"].get<std::map<std::string, long long> >();
        if (j.contains("last_action")) last_action = j["last_action"].get<std::map<std::string, time_t> >();
        if (j.contains("trust_score")) trust_score = j["trust_score"].get<std::map<std::string, double> >();
        if (j.contains("cpu_ema")) cpu_ema = j["cpu_ema"].get<std::map<std::string, double> >();
        if (j.contains("ram_ema")) ram_ema = j["ram_ema"].get<std::map<std::string, double> >();
        if (j.contains("net_ema")) net_ema = j["net_ema"].get<std::map<std::string, double> >();
        if (j.contains("bw_window_base_rx")) bw_window_base_rx = j["bw_window_base_rx"].get<std::map<std::string, long long> >();
        if (j.contains("bw_window_base_tx")) bw_window_base_tx = j["bw_window_base_tx"].get<std::map<std::string, long long> >();
        if (j.contains("bw_window_start")) bw_window_start = j["bw_window_start"].get<std::map<std::string, time_t> >();
    } catch (...) {
        logger.warn("Resource monitor state load failed, starting with fresh in-memory state");
    }
}

void ResourceMonitor::save_state() {
    json j;
    {
        std::lock_guard<std::mutex> lock(state_mutex);
        j["restart_count"] = restart_count;
        j["resource_strikes"] = resource_strikes;
        j["last_activity_log_id"] = last_activity_log_id;
        j["last_action"] = last_action;
        j["trust_score"] = trust_score;
        j["cpu_ema"] = cpu_ema;
        j["ram_ema"] = ram_ema;
        j["net_ema"] = net_ema;
        j["bw_window_base_rx"] = bw_window_base_rx;
        j["bw_window_base_tx"] = bw_window_base_tx;
        j["bw_window_start"] = bw_window_start;
    }

    std::ofstream file(state_file);
    if (!file.is_open()) return;
    file << j.dump(2);
}

// ─── Evaluate one server and act if thresholds are breached ──────────────────
void ResourceMonitor::handle_server(const PtlcServerEntry& srv) {
    ResourceSnapshot snap{};
    if (!get_resources(srv.identifier, srv.uuid, snap)) {
        std::string offline_dropper = detect_dropper_artifact(srv.uuid);
        if (offline_dropper.empty()) return;

        ServerInfo db_info = db.get_server_info(srv.uuid);
        if (db_info.id <= 0) return;

        time_t now = time(nullptr);
        time_t last_act = 0;
        {
            std::lock_guard<std::mutex> lock(state_mutex);
            last_act = last_action.count(srv.uuid) ? last_action.at(srv.uuid) : 0;
        }
        bool cooldown_ok = (now - last_act) >= ACTION_COOLDOWN_SEC;
        if (!cooldown_ok) return;

        bool container_stopped = stop_container_now(srv.identifier, srv.uuid);
        bool suspended = db.suspend_server(db_info.id);
        {
            std::lock_guard<std::mutex> lock(state_mutex);
            last_action[srv.uuid] = now;
            restart_count[srv.uuid] = restart_count.count(srv.uuid) ? (restart_count[srv.uuid] + 1) : 1;
        }
        save_state();

        std::string details = "offline_artifact=" + offline_dropper + " resources_api=unavailable";
        db.log_user_violation(
            db_info.owner_id, db_info.username,
            db_info.id, db_info.uuid, db_info.name,
            "process_abuse", details,
            "", 0, 0.0, 0,
            suspended ? "suspended" : (container_stopped ? "container_stopped" : "observe_only"),
            8
        );
        db.bump_daily_stats(suspended ? 1 : 0, 0, 0);

        logger.info("🚨 OFFLINE DROPPER ARTIFACT — " + srv.name + " (" + srv.uuid + ") | " + details +
                    (suspended ? " | SUSPENDED" : (container_stopped ? " | CONTAINER_STOPPED" : " | OBSERVED")));
        bot.send_report_message(build_alert(
            "OFFLINE DROPPER ARTIFACT", db_info,
            0.0, 0.0, srv.cpu_limit,
            0.0, 0, srv.mem_limit_bytes,
            suspended ? "Server suspended via database ✅" :
                (container_stopped ? "Container stopped locally ✅" : "Observed only ⚠️")
        ));
        return;
    }

    // Compute utilisation as a percentage of the allocated limit.
    // If limit == 0 (unlimited), treat every 100 absolute CPU % as 100 util %.
    double cpu_pct_raw_used = (srv.cpu_limit > 0)
        ? (snap.cpu_absolute / srv.cpu_limit * 100.0)
        : snap.cpu_absolute;
    // Enforcement buffer applies only when a CPU limit exists.
    double cpu_pct_used = (srv.cpu_limit > 0)
        ? (cpu_pct_raw_used / CPU_ENFORCEMENT_BUFFER_MULTIPLIER)
        : cpu_pct_raw_used;

    const long long host_total_ram_bytes = get_host_total_ram_bytes();
    const long long dynamic_unlimited_ram_cap = host_total_ram_bytes > 0
        ? std::max(256LL * 1024LL * 1024LL, (host_total_ram_bytes * 30LL) / 100LL)
        : UNLIMITED_RAM_WARN_BYTES;
    const long long effective_mem_limit_bytes = (srv.mem_limit_bytes > 0)
        ? srv.mem_limit_bytes
        : dynamic_unlimited_ram_cap;

    double ram_pct_used = (effective_mem_limit_bytes > 0)
        ? (snap.mem_bytes / static_cast<double>(effective_mem_limit_bytes) * 100.0)
        : 0.0;

    bool cpu_hot     = (srv.cpu_limit > 0)
        ? (cpu_pct_used >= HOT_THRESHOLD)
        : (snap.cpu_absolute >= UNLIMITED_CPU_WARN_ABS * 0.8);
    bool cpu_high    = (srv.cpu_limit > 0)
        ? (cpu_pct_used >= cpu_threshold_pct)
        : (snap.cpu_absolute >= UNLIMITED_CPU_WARN_ABS);
    bool cpu_extreme = (srv.cpu_limit > 0)
        ? (cpu_pct_used >= HARD_THRESHOLD)
        : (snap.cpu_absolute >= UNLIMITED_CPU_HARD_ABS);
    bool ram_hot     = (srv.mem_limit_bytes > 0)
        ? (ram_pct_used >= HOT_THRESHOLD)
        : (snap.mem_bytes >= (effective_mem_limit_bytes * 8 / 10));
    bool ram_high    = (srv.mem_limit_bytes > 0)
        ? (ram_pct_used >= ram_threshold_pct)
        : (snap.mem_bytes >= effective_mem_limit_bytes);
    bool ram_extreme = (srv.mem_limit_bytes > 0)
        ? (ram_pct_used >= HARD_THRESHOLD)
        : (snap.mem_bytes >= effective_mem_limit_bytes);

    time_t now = time(nullptr);
    long long bw_rx_window_bytes = 0;
    long long bw_tx_window_bytes = 0;
    int bw_window_age_sec = 0;
    bool bw_window_reset = false;
    {
        std::lock_guard<std::mutex> lock(state_mutex);
        time_t& window_start = bw_window_start[srv.uuid];
        long long& base_rx = bw_window_base_rx[srv.uuid];
        long long& base_tx = bw_window_base_tx[srv.uuid];

        if (window_start <= 0) {
            window_start = now;
            base_rx = snap.net_rx_bytes;
            base_tx = snap.net_tx_bytes;
        }

        if (bandwidth_window_sec > 0 && (now - window_start) >= bandwidth_window_sec) {
            window_start = now;
            base_rx = snap.net_rx_bytes;
            base_tx = snap.net_tx_bytes;
            bw_window_reset = true;
        }

        if (snap.net_rx_bytes < base_rx || snap.net_tx_bytes < base_tx) {
            window_start = now;
            base_rx = snap.net_rx_bytes;
            base_tx = snap.net_tx_bytes;
            bw_window_reset = true;
        }

        bw_rx_window_bytes = std::max(0LL, snap.net_rx_bytes - base_rx);
        bw_tx_window_bytes = std::max(0LL, snap.net_tx_bytes - base_tx);
        bw_window_age_sec = static_cast<int>(std::max<time_t>(0, now - window_start));
    }

    bool bw_in_over  = bandwidth_in_limit_bytes > 0 && bw_rx_window_bytes >= bandwidth_in_limit_bytes;
    bool bw_out_over = bandwidth_out_limit_bytes > 0 && bw_tx_window_bytes >= bandwidth_out_limit_bytes;
    bool bw_trigger  = bw_in_over || bw_out_over;

    ServerInfo db_info = db.get_server_info(srv.uuid);
    if (db_info.id <= 0) {
        logger.warn("PTLC resource: UUID " + srv.uuid + " not found in DB — skipping");
        return;
    }

    long long last_activity_id = 0;
    {
        std::lock_guard<std::mutex> lock(state_mutex);
        last_activity_id = last_activity_log_id.count(srv.uuid) ? last_activity_log_id[srv.uuid] : 0;
    }
    ActivityAbuseInfo activity_abuse = collect_recent_activity_abuse(db_info.id, last_activity_id);
    if (activity_abuse.last_id > last_activity_id) {
        std::lock_guard<std::mutex> lock(state_mutex);
        last_activity_log_id[srv.uuid] = activity_abuse.last_id;
    }

    time_t last_act = 0;
    {
        std::lock_guard<std::mutex> lock(state_mutex);
        last_act = last_action.count(srv.uuid) ? last_action.at(srv.uuid) : 0;
    }
    bool cooldown_ok = (now - last_act) >= ACTION_COOLDOWN_SEC;
    bool activity_urgent = activity_abuse.hard || activity_abuse.score >= 5;

    // Even if the container is already offline, suspicious panel activity should still be actionable.
    if (snap.state != "running" || snap.is_suspended) {
        bool activity_trigger = (cooldown_ok || activity_urgent) && activity_abuse.suspicious && !snap.is_suspended;
        if (activity_trigger) {
            bool suspended = db.suspend_server(db_info.id);
            std::ostringstream det;
            det << "ACT score=" << activity_abuse.score;
            if (!activity_abuse.summary.empty()) det << " " << activity_abuse.summary;
            det << " | state=" << snap.state;

            db.log_user_violation(
                db_info.owner_id, db_info.username,
                db_info.id, db_info.uuid, db_info.name,
                "activity_abuse", det.str(),
                "", 0, 0.0, 0,
                suspended ? "suspended" : "observe_only",
                8
            );
            db.bump_daily_stats(suspended ? 1 : 0, 0, 0);

            {
                std::lock_guard<std::mutex> lock(state_mutex);
                last_action[srv.uuid] = now;
                if (activity_abuse.last_id > 0) last_activity_log_id[srv.uuid] = activity_abuse.last_id;
                consecutive_cpu_hit.erase(srv.uuid);
                consecutive_ram_hit.erase(srv.uuid);
                consecutive_net_hit.erase(srv.uuid);
                cpu_ema.erase(srv.uuid);
                ram_ema.erase(srv.uuid);
                net_ema.erase(srv.uuid);
            }
            save_state();

            logger.info("🚨 CONSOLE / PAYLOAD ABUSE — " + srv.name + " (" + srv.uuid + ") | " +
                        det.str() + (suspended ? " | SUSPENDED" : " | OBSERVED"));
            bot.send_report_message(build_alert(
                "CONSOLE / PAYLOAD ABUSE", db_info,
                cpu_pct_raw_used, snap.cpu_absolute, srv.cpu_limit,
                ram_pct_used, snap.mem_bytes, srv.mem_limit_bytes,
                suspended ? "Server suspended via database ✅" : "Observed only ⚠️"
            ));
        } else {
            std::lock_guard<std::mutex> lock(state_mutex);
            consecutive_cpu_hit.erase(srv.uuid);
            consecutive_ram_hit.erase(srv.uuid);
            consecutive_net_hit.erase(srv.uuid);
            cpu_ema.erase(srv.uuid);
            ram_ema.erase(srv.uuid);
            net_ema.erase(srv.uuid);
            if (activity_abuse.last_id > 0) last_activity_log_id[srv.uuid] = activity_abuse.last_id;
        }
        if (activity_abuse.last_id > last_activity_id) save_state();
        return;
    }

    ApiTrafficProfile api_profile = get_api_profile(db_info, srv);
    InboundStats inbound = offline_mode ? collect_inbound_stats(srv.identifier) : InboundStats{};
    ProcessAbuseInfo proc_abuse = collect_process_abuse(srv.identifier);
    std::string dropper_artifact = detect_dropper_artifact(srv.uuid);
    if (!dropper_artifact.empty()) {
        proc_abuse.suspicious = true;
        if (!proc_abuse.summary.empty()) proc_abuse.summary += " | ";
        proc_abuse.summary += "dropper_file=" + dropper_artifact;
    }
    long long uptime_seconds = get_container_uptime_seconds(srv.identifier, srv.uuid);
    bool startup_grace = uptime_seconds >= 0 && uptime_seconds < STARTUP_GRACE_SECONDS;

    if (api_profile.enabled) {
        inbound.self_ddos = !inbound.infra_only_local &&
                            inbound.local_conns >= api_profile.self_ddos_conn &&
                            inbound.local_conns >= inbound.external_conns;
        inbound.l7_flood = inbound.unique_external_ips >= api_profile.net_warning_unique_ips ||
                           (inbound.external_conns >= api_profile.net_warning_conn &&
                            inbound.max_ip_conns < IPTABLES_BLOCK_CONN_THRESHOLD);
    }

    int    cpu_hits, ram_hits, net_hits, total_restarts, resource_strike_count;
    double cpu_avg, ram_avg, net_avg, score_now;

    {
        std::lock_guard<std::mutex> lock(state_mutex);
        cpu_hits = cpu_hot ? (consecutive_cpu_hit[srv.uuid] + 1) : 0;
        ram_hits = ram_hot ? (consecutive_ram_hit[srv.uuid] + 1) : 0;
        consecutive_cpu_hit[srv.uuid] = cpu_hits;
        consecutive_ram_hit[srv.uuid] = ram_hits;
        net_hits = (offline_mode && (inbound.external_conns >= api_profile.net_warning_conn ||
                                     inbound.local_conns >= api_profile.self_ddos_conn ||
                                     inbound.unique_external_ips >= api_profile.net_warning_unique_ips))
            ? (consecutive_net_hit[srv.uuid] + 1)
            : 0;
        consecutive_net_hit[srv.uuid] = net_hits;
        cpu_avg = cpu_ema.count(srv.uuid) ? cpu_ema[srv.uuid] : cpu_pct_used;
        ram_avg = ram_ema.count(srv.uuid) ? ram_ema[srv.uuid] : ram_pct_used;
        net_avg = net_ema.count(srv.uuid) ? net_ema[srv.uuid] : (double)inbound.external_conns;
        cpu_avg = cpu_avg * 0.65 + cpu_pct_used * 0.35;
        ram_avg = ram_avg * 0.65 + ram_pct_used * 0.35;
        net_avg = net_avg * 0.55 + inbound.external_conns * 0.45;
        cpu_ema[srv.uuid] = cpu_avg;
        ram_ema[srv.uuid] = ram_avg;
        net_ema[srv.uuid] = net_avg;
        score_now = trust_score.count(srv.uuid) ? trust_score[srv.uuid] : 100.0;
        double penalty = 0.0;
        if (!startup_grace && cpu_avg >= HOT_THRESHOLD) penalty += 10.0;
        if (!startup_grace && ram_avg >= HOT_THRESHOLD) penalty += 10.0;
        if (inbound.external_conns >= api_profile.net_warning_conn) penalty += api_profile.enabled ? 6.0 : 10.0;
        if (inbound.external_conns >= api_profile.net_hard_conn) penalty += api_profile.enabled ? 12.0 : 18.0;
        if (inbound.unique_external_ips >= api_profile.net_warning_unique_ips) penalty += api_profile.enabled ? 5.0 : 8.0;
        if (inbound.unique_external_ips >= api_profile.net_hard_unique_ips) penalty += api_profile.enabled ? 10.0 : 14.0;
        if (inbound.self_ddos) penalty += 16.0;
        if (inbound.local_conns >= api_profile.self_ddos_hard) penalty += api_profile.enabled ? 12.0 : 18.0;
        if (proc_abuse.suspicious) penalty += 45.0;
        if (activity_abuse.suspicious) penalty += activity_abuse.hard ? 38.0 : 22.0;
        if (!startup_grace && cpu_high) penalty += 8.0;
        if (!startup_grace && ram_high) penalty += 8.0;
        if (!startup_grace && cpu_extreme) penalty += 15.0;
        if (!startup_grace && ram_extreme) penalty += 15.0;
        if (!cpu_hot && !ram_hot && score_now < 100.0) score_now += 2.0;
        score_now -= penalty;
        if (score_now < 0.0) score_now = 0.0;
        if (score_now > 100.0) score_now = 100.0;
        trust_score[srv.uuid] = score_now;
        total_restarts = restart_count.count(srv.uuid) ? restart_count[srv.uuid] : 0;
        resource_strike_count = resource_strikes.count(srv.uuid) ? resource_strikes[srv.uuid] : 0;
    }

    bool cpu_trigger = cooldown_ok && (
        (cpu_extreme && cpu_hits >= HARD_HITS_REQUIRED) ||
        (cpu_high    && cpu_hits >= NORMAL_HITS_REQUIRED) ||
        (cpu_hot     && cpu_hits >= HARD_HITS_REQUIRED && cpu_avg >= HOT_THRESHOLD)
    );
    bool ram_trigger = cooldown_ok && (
        (ram_extreme && ram_hits >= HARD_HITS_REQUIRED) ||
        (ram_high    && ram_hits >= NORMAL_HITS_REQUIRED) ||
        (ram_hot     && ram_hits >= HARD_HITS_REQUIRED && ram_avg >= HOT_THRESHOLD)
    );

    if (startup_grace) {
        cpu_trigger = false;
        ram_trigger = false;
    }

    bool net_extreme = inbound.external_conns >= api_profile.net_hard_conn ||
                       inbound.unique_external_ips >= api_profile.net_hard_unique_ips;
    bool net_warning = inbound.external_conns >= api_profile.net_warning_conn ||
                       inbound.unique_external_ips >= api_profile.net_warning_unique_ips;
    bool self_ddos = inbound.self_ddos;
    bool l7_pressure = inbound.l7_flood || self_ddos;

    bool net_trigger = offline_mode && cooldown_ok && (
        (net_extreme && net_hits >= 1) ||
        (net_warning && net_hits >= api_profile.net_hits_required) ||
        (self_ddos && net_hits >= 1) ||
        (!inbound.infra_only_local && inbound.local_conns >= api_profile.self_ddos_hard)
    );

    std::string blocked_ip = offline_mode && (net_trigger || net_extreme)
        ? block_abusive_inbound_ips(srv.identifier)
        : "";
    bool trust_trigger = cooldown_ok && (score_now <= 45.0 || (l7_pressure && (cpu_hot || ram_hot || net_warning)));
    bool process_trigger = cooldown_ok && proc_abuse.suspicious;
    bool activity_trigger = (cooldown_ok || activity_urgent) && activity_abuse.suspicious;

    if (!cpu_trigger && !ram_trigger && !net_trigger && !bw_trigger && !trust_trigger && !process_trigger && !activity_trigger) {
        if (activity_abuse.last_id > last_activity_id) save_state();
        return;
    }

    bool resource_only_trigger = (cpu_trigger || ram_trigger) && !process_trigger && !activity_trigger && !net_trigger;
    if (resource_only_trigger) {
        resource_strike_count++;
    } else if (!cpu_hot && !ram_hot && resource_strike_count > 0) {
        resource_strike_count--;
    }

    // Determine violation label
    std::string abuse_type;
    if (process_trigger) abuse_type = "PROCESS / TOOL ABUSE";
    else if (activity_trigger) abuse_type = "CONSOLE / PAYLOAD ABUSE";
    else if (self_ddos) abuse_type = "SELF DDoS / L7 ABUSE";
    else if (net_trigger && (cpu_trigger || ram_trigger)) abuse_type = "NETWORK + RESOURCE ABUSE";
    else if (net_trigger) abuse_type = "NETWORK FLOOD";
    else if (cpu_trigger && ram_trigger) abuse_type = "CPU + RAM ABUSE";
    else if (cpu_trigger) abuse_type = "CPU ABUSE";
    else if (ram_trigger) abuse_type = "RAM ABUSE";
    else if (bw_trigger) abuse_type = "BANDWIDTH ABUSE";
    else abuse_type = "TRUST SCORE ABUSE";

    bool restart_ok = false;
    bool suspended = false;
    bool container_stopped = false;
    bool sigterm_sent = false;
    bool api_net_only = api_profile.enabled && net_trigger && !self_ddos && !cpu_trigger && !ram_trigger && !process_trigger;
    bool resource_suspend_ready = resource_only_trigger && resource_strike_count >= RESOURCE_SUSPEND_STRIKES;
    bool force_suspend = process_trigger || activity_trigger || self_ddos || bw_trigger || resource_suspend_ready || score_now <= 25.0;
    bool should_suspend = force_suspend || total_restarts >= (MAX_RESTART_BEFORE_SUSPEND - 1);
    if (api_net_only && score_now > 20.0) should_suspend = false;
    bool resource_sigterm_only = resource_only_trigger && !process_trigger && !activity_trigger && !self_ddos && !bw_trigger;
    if (should_suspend) {
        if (resource_sigterm_only) {
            sigterm_sent = send_sigterm_container(srv.identifier, srv.uuid);
            container_stopped = sigterm_sent;
            suspended = false;
        } else {
            container_stopped = stop_container_now(srv.identifier, srv.uuid);
            suspended = db.suspend_server(db_info.id);
        }
        if (suspended || container_stopped) total_restarts++;
    } else if (offline_mode) {
        if (api_net_only) {
            restart_ok = false;
        } else {
            restart_ok = restart_container(srv.identifier, srv.uuid);
            if (restart_ok) total_restarts++;
        }
    } else {
        suspended = db.suspend_server(db_info.id);
        if (suspended) total_restarts++;
    }

    // Reset counters and set cooldown
    {
        std::lock_guard<std::mutex> lock(state_mutex);
        last_action[srv.uuid]          = now;
        consecutive_cpu_hit[srv.uuid]  = 0;
        consecutive_ram_hit[srv.uuid]  = 0;
        consecutive_net_hit[srv.uuid]  = 0;
        restart_count[srv.uuid]        = total_restarts;
        resource_strikes[srv.uuid]     = (suspended || container_stopped) ? 0 : resource_strike_count;
        if (activity_abuse.last_id > 0) last_activity_log_id[srv.uuid] = activity_abuse.last_id;
    }
    save_state();

    // ── Build violation details string ───────────────────────────────────────
    std::ostringstream det;
    det << std::fixed << std::setprecision(1);
    if (cpu_trigger)
        det << "CPU " << snap.cpu_absolute << "% / " << srv.cpu_limit
            << "% limit (" << (int)cpu_pct_raw_used << "%, policy=" << (int)cpu_pct_used << "%)";
    if (cpu_trigger && ram_trigger) det << " | ";
    if (ram_trigger) {
        long long ram_mb       = snap.mem_bytes       / (1024LL * 1024);
        long long ram_limit_mb = effective_mem_limit_bytes / (1024LL * 1024);
        det << "RAM " << ram_mb << "/";
        det << ram_limit_mb << " MB (" << (int)ram_pct_used << "%)";
        if (srv.mem_limit_bytes <= 0) det << " [policy 30% host RAM]";
    }
    if (bw_trigger) {
        if (!det.str().empty()) det << " | ";
        const long long rx_mb = bw_rx_window_bytes / (1024LL * 1024);
        const long long tx_mb = bw_tx_window_bytes / (1024LL * 1024);
        const long long lim_in_mb = bandwidth_in_limit_bytes / (1024LL * 1024);
        const long long lim_out_mb = bandwidth_out_limit_bytes / (1024LL * 1024);
        det << "BW window=" << bw_window_age_sec << "s/" << bandwidth_window_sec
            << "s in=" << rx_mb << "MB/" << lim_in_mb
            << "MB out=" << tx_mb << "MB/" << lim_out_mb << "MB";
        if (bw_window_reset) det << " reset=yes";
    }
    if ((cpu_trigger || ram_trigger) && net_trigger) det << " | ";
    if (net_trigger || net_warning || self_ddos) {
        det << "NET ext=" << inbound.external_conns
            << " local=" << inbound.local_conns
            << " unique_ext=" << inbound.unique_external_ips;
        if (!inbound.top_summary.empty()) det << " | top=" << inbound.top_summary;
        if (!inbound.actor_summary.empty()) det << " | actors=" << inbound.actor_summary;
        if (self_ddos) det << " | self_ddos=yes";
        else if (l7_pressure) det << " | l7_pressure=yes";
        if (inbound.infra_only_local) det << " | infra_local_only=yes";
        if (api_profile.enabled) det << " | api_mode=yes";
    }
    if (startup_grace) {
        if ((cpu_trigger || ram_trigger || net_trigger || net_warning || self_ddos) && !det.str().empty()) det << " | ";
        det << "startup_grace=yes uptime=" << uptime_seconds << "s";
    }
    if (process_trigger) {
        if ((cpu_trigger || ram_trigger || net_trigger || net_warning || self_ddos) && !det.str().empty()) det << " | ";
        det << "PROC " << proc_abuse.summary;
    }
    if (activity_trigger) {
        if ((cpu_trigger || ram_trigger || net_trigger || net_warning || self_ddos || process_trigger) && !det.str().empty()) det << " | ";
        det << "ACT score=" << activity_abuse.score;
        if (!activity_abuse.summary.empty()) det << " " << activity_abuse.summary;
    }

    if (!det.str().empty()) det << " | ";
    det << "trust=" << (int)score_now
        << " cpu_avg=" << (int)cpu_avg
        << "% ram_avg=" << (int)ram_avg
        << "% net_avg=" << (int)net_avg
        << " strikes=" << total_restarts
        << " resource_strikes=" << resource_strike_count;
    if (!blocked_ip.empty()) det << " | iptables_blocked=" << blocked_ip;

    std::string violation_type_str =
        process_trigger ? "process_abuse" :
        (activity_trigger ? "activity_abuse" :
        (net_trigger ? (self_ddos ? "self_ddos" : "network_abuse") :
        (cpu_trigger ? "cpu_abuse" : (ram_trigger ? "ram_abuse" : (bw_trigger ? "bandwidth_abuse" : "behavior_abuse")))));
    int severity = (process_trigger || activity_trigger || cpu_extreme || ram_extreme || net_extreme || self_ddos || bw_trigger || score_now <= 25.0) ? 8 : 6;

    // ── Log to database ───────────────────────────────────────────────────────
    db.log_user_violation(
        db_info.owner_id, db_info.username,
        db_info.id, db_info.uuid, db_info.name,
        violation_type_str, det.str(),
        "", 0, 0.0, 0,
        suspended ? "suspended" : (sigterm_sent ? "sigterm_sent" : (container_stopped ? "container_stopped" : (restart_ok ? "restarted" : "observe_only"))),
        severity
    );
    db.bump_daily_stats(suspended ? 1 : 0, 0, 0);

    logger.info("🚨 " + abuse_type + " — " + srv.name + " (" + srv.uuid + ") | " + det.str() +
                (suspended ? " | SUSPENDED" : (sigterm_sent ? " | SIGTERM_SENT" : (container_stopped ? " | CONTAINER_STOPPED" : (restart_ok ? " | RESTARTED" : " | OBSERVED")))));

    // ── Telegram alert ────────────────────────────────────────────────────────
    std::string action_text = suspended ? "Server suspended via database ✅" :
                              (sigterm_sent ? "SIGTERM sent to container ✅" :
                              (container_stopped ? "Container stopped locally ✅" :
                               (restart_ok ? "Container restarted locally ✅" : "Observed only ⚠️")));
    bot.send_report_message(build_alert(
        abuse_type, db_info,
        cpu_pct_raw_used, snap.cpu_absolute, srv.cpu_limit,
        ram_pct_used, snap.mem_bytes, effective_mem_limit_bytes,
        action_text
    ));
}

// ─── One full sweep of all servers ───────────────────────────────────────────
void ResourceMonitor::check_all() {
    // Refresh server list if stale
    bool cache_stale;
    {
        std::lock_guard<std::mutex> lock(state_mutex);
        cache_stale = server_cache.empty() ||
                      (time(nullptr) - server_cache_time) >= SERVER_CACHE_TTL_SEC;
    }
    if (cache_stale && !refresh_server_list()) return;

    std::vector<PtlcServerEntry> snapshot;
    {
        std::lock_guard<std::mutex> lock(state_mutex);
        snapshot = server_cache;
    }

    for (const auto& srv : snapshot)
        handle_server(srv);
}

ResourceMonitor res_monitor;
