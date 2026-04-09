#include "disk_protect.h"
#include "logger.h"
#include "db_guard.h"
#include "telegram.h"

#include <dirent.h>
#include <sys/stat.h>
#include <sys/statvfs.h>
#include <unistd.h>

#include <sstream>
#include <algorithm>
#include <cstdlib>
#include <iomanip>
#include <cstring>
#include <ctime>
#include <fstream>
#include <set>
#include <thread>
#include <chrono>
#include <mutex>
#include <regex>
#include <cmath>
#include <map>
#include <openssl/evp.h>

const double MAKSIMAL_UKURAN_GB = 10.0;
const double BATAS_LONJAKAN_GB = 3.0;
const int JEDA_CEK_DETIK = 10;
const int MAX_SCAN_DEPTH = 6;

// responsif + anti false positive
const double DISK_SPIKE_HARD_GB = 5.0;
const int CONSECUTIVE_TRIGGER_REQUIRED = 2;
const int ALERT_COOLDOWN_SECONDS = 120;
const int ALERT_DEDUP_SECONDS = 1800;
const int FILE_SCAN_INTERVAL_SECONDS = 5;
const int SOFT_QUARANTINE_ALLOW_SECONDS = 86400;

// network flood thresholds
const int TCP_CONN_WARNING = 300;
const int TCP_CONN_CRITICAL = 1000;
const unsigned long long HOST_DISK_CRITICAL_FREE_BYTES = 512ULL * 1024ULL * 1024ULL;
const unsigned long long HOST_DISK_EMERGENCY_FREE_BYTES = 128ULL * 1024ULL * 1024ULL;
const int QUARANTINE_HOLD_SECONDS = 1800;
const double DISK_OVER_LIMIT_MULTIPLIER = 3.0;
const long long SPARSE_FILE_MIN_SIZE_BYTES = 512LL * 1024LL * 1024LL;
const long long SPARSE_FILE_MAX_ALLOCATED_BYTES = 64LL * 1024LL * 1024LL;
const double SPARSE_FILE_MAX_ALLOCATED_RATIO = 0.01;
const int DISK_FLOOD_FILENAME_HARD_THRESHOLD = 1;
const double POST_ACTION_TARGET_FLOOR_GB = 0.25;
const int MAX_RECLAIM_PASSES = 8;

const std::vector<std::string> EKSTENSI_AMAN = {
    ".jar", ".zip", ".tar.gz", ".db", ".sqlite", ".bak", ".backup",
    ".png", ".jpg", ".jpeg", ".gif", ".mp3", ".mp4", ".avi", ".mov",
    ".pdf", ".doc", ".svg", ".woff", ".woff2", ".eot", ".ttf", ".ico",
    ".webp", ".webm", ".mkv"
};

const std::vector<std::string> SAFE_FILES = {
    "package.json", "package-lock.json", "yarn.lock", "pnpm-lock.yaml",
    "composer.json", "composer.lock", "readme.md", ".gitignore", ".env",
    "nginx.conf", "php.ini", "my.cnf", "config.json", "wp-config.php",
    "index.php", "index.html", "style.css",
    "tsconfig.json", "webpack.config.js", "vite.config.ts", "next.config.js",
    "nuxt.config.js", "angular.json", ".eslintrc", ".prettierrc"
};

// "kill" generic dihapus agar false positive rendah
const std::vector<std::string> KATA_KUNCI_FILE_JAHAT = {
    "cluster.fork()", "zerants", "stratum+tcp", "minerd", "udp-flood", "tcp-flood",
    "fs.statfssync", "buffer.alloc(1024 * 1024 * 1024)", "child_process.exec",
    "nsenter", "/var/run/docker.sock", "/etc/shadow", "/root/.ssh",
    "nc -e /bin/sh", "ngrok tcp", "cap_sys_admin", "chmod +s /bin/bash",
    "eval(buffer.from", "xmrig", "backdoor",
    "webshell", "exploit", "forkbomb", "diskiller", "filldisk",
    "scarrydeath", "killdisk",
    // DDoS / network-flood patterns
    "dgram.createsocket", "http2.connect", "attackhttp1", "attackhttp2",
    "attackraw", "attackslow", "attackudp", "attacktls", "attackdns",
    "networkloop", "diskworkers", "networkworkers", "targetports", "targetdomain",
    "dd if=/dev/zero", "chattr +i", "maxsockets", "attackintensity",
    "udp4",
    "wwtztzy", "lopyu",
    // Cloud metadata / cloud-init abuse
    "169.254.169.254", "169.254.170.2", "100.100.100.200", "metadata.google.internal",
    "/var/lib/cloud/", "/run/cloud-init", "/etc/cloud/", "cloud-init", "latest/meta-data",
    "computeMetadata/v1", "metadata/instance", "opc/v2", "iam/security-credentials"
};

// Single-hit critical patterns — each alone is enough to flag the file.
// These are highly specific obfuscation + execution combos with near-zero false positive rate.
const std::vector<std::string> CRITICAL_KEYWORDS = {
    // PHP eval + decode chains
    "eval(base64_decode(", "eval(gzinflate(", "eval(gzuncompress(",
    "eval(str_rot13(", "@eval(", "assert(base64_decode(",
    "preg_replace('/.*/ e'", "preg_replace(\"/.*/e\"",
    // JS eval + decode chains
    "eval(atob(", "eval(buffer.from(", "function(atob(",
    "new function(\"return", "(function(){eval(",
    // Python exec + decode chains
    "exec(compile(base64", "exec(__import__('base64')",
    "exec(bytes.fromhex(", "__import__('os').system(",
    // Shell decode + execute pipelines
    "| base64 --decode | bash", "| base64 -d | bash",
    "| base64 -d | sh", "| base64 --decode | sh",
    "bash -c \"$(curl", "bash -c \"$(wget",
    "sh -c \"$(curl", "sh -c \"$(wget",
    // Reverse shell one-liners
    "/bin/bash -i >& /dev/tcp/", "bash -i >& /dev/tcp/",
    // Crypto miners hidden in encoded blobs
    "xmrig", "stratum+tcp://", "stratum+ssl://",
    // Pterodactyl-specific attack paths
    "chattr +i /var/lib",
    ".t.me/wwtztzy", "lopyu_",
    // Cloud metadata / cloud-init theft
    "http://169.254.169.254", "http://169.254.170.2",
    "http://100.100.100.200", "metadata.google.internal/computeMetadata/v1",
    "/var/lib/cloud/instance", "/var/lib/cloud/seed", "/run/cloud-init",
    "/etc/cloud/cloud.cfg"
};

// Multi-hit obfuscation indicators — require 3+ matches to flag.
// Each alone is too generic, but a cluster of them strongly suggests obfuscated malware.
const std::vector<std::string> OBFUSCATION_PATTERNS = {
    // Encoding functions used to hide payloads
    "base64_decode(", "base64_encode(", "atob(", "btoa(",
    "gzinflate(", "gzuncompress(", "str_rot13(",
    "string.fromcharcode(", "fromcharcode(",
    // Eval / execute variants (generic but suspicious in combination)
    "eval(", "assert(", "exec(", "system(",
    "passthru(", "shell_exec(", "popen(",
    // Obfuscated variable / property names typical of JS/PHP packers
    "_0x", "\\x41\\x42\\x43",              // hex escape sequences in source
    "\\u0065\\u0076\\u0061\\u006c",        // 'eval' in unicode escapes
    "unescape(%u", "unescape(%2",
    // JS packer signatures
    "p,a,c,k,e,d", "packed=function(",
    // PHP obfuscation via variable variables / globals
    "${\"globals\"}", "$_=$_", "$$_",
    "chr(ord(",
    // Python obfuscation
    "bytes.fromhex(", "__import__(",
    "getattr(__builtins__"
};

std::map<std::string, double> cache_ukuran;
std::map<std::string, double> cache_ukuran_apparent;
std::map<std::string, int> cache_consecutive_hit;
std::map<std::string, time_t> cache_last_action;
std::map<std::string, time_t> cache_last_file_scan;
std::map<std::string, time_t> cache_last_alert_sent;
std::map<std::string, std::string> cache_last_alert_signature;
bool alert_cache_loaded = false;
std::map<std::string, time_t> quarantine_until;
std::map<std::string, std::string> quarantine_reason;
std::map<std::string, std::string> quarantine_original_path;
std::map<std::string, std::string> quarantine_server_uuid;
std::map<std::string, time_t> soft_allow_until;
std::map<std::string, double> local_trust_score;
std::set<std::string> cache_penuh;

struct ContentScanCacheEntry {
    long long size = -1;
    time_t modified = 0;
    bool suspicious = false;
    std::string reason;
};

std::map<std::string, ContentScanCacheEntry> content_scan_cache;

std::mutex cache_mutex;

namespace {
bool is_valid_uuid(const std::string& s) {
    static const std::regex re(
        "^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$");
    return std::regex_match(s, re);
}

std::string trim(const std::string& s) {
    size_t a = s.find_first_not_of(" \n\r\t");
    if (a == std::string::npos) return "";
    size_t b = s.find_last_not_of(" \n\r\t");
    return s.substr(a, b - a + 1);
}

std::string to_lower_copy(std::string v) {
    std::transform(v.begin(), v.end(), v.begin(), [](unsigned char c){ return (char)std::tolower(c); });
    return v;
}

bool has_text_like_extension(const std::string& name) {
    static const std::vector<std::string> text_exts = {
        ".sh", ".bash", ".zsh", ".js", ".mjs", ".cjs", ".ts", ".jsx", ".tsx",
        ".py", ".php", ".pl", ".rb", ".lua", ".java", ".go", ".rs",
        ".c", ".cc", ".cpp", ".h", ".hpp",
        ".json", ".yml", ".yaml", ".toml", ".ini", ".env", ".conf",
        ".txt", ".md", ".xml", ".html", ".css", ".sql"
    };

    std::string lower = to_lower_copy(name);
    for (const auto& ext : text_exts) {
        if (lower.size() >= ext.size() && lower.substr(lower.size() - ext.size()) == ext) {
            return true;
        }
    }
    return false;
}

bool is_known_dropper_filename(const std::string& name) {
    static const std::set<std::string> bad_names = {
        "x86_64", "a.out", "bot", "miner", "scan", "upd", "update",
        "ramabypass.jar"
    };
    return bad_names.count(to_lower_copy(name)) > 0;
}

bool is_probably_elf_file(const std::string& path) {
    std::ifstream f(path, std::ios::binary);
    if (!f.is_open()) return false;
    unsigned char magic[4] = {0, 0, 0, 0};
    f.read(reinterpret_cast<char*>(magic), 4);
    if (f.gcount() < 4) return false;
    return magic[0] == 0x7F && magic[1] == 'E' && magic[2] == 'L' && magic[3] == 'F';
}

bool is_disk_flood_artifact_name(const std::string& lower_name) {
    static const std::regex flood_re(
        R"(file_[0-9]+_[0-9]+_[0-9]+_(fallocate|sparse|truncate)\.bin$)",
        std::regex_constants::icase);

    if (std::regex_search(lower_name, flood_re)) return true;
    if (lower_name.find("_fallocate.bin") != std::string::npos) return true;
    if (lower_name.find("_sparse.bin") != std::string::npos) return true;
    if (lower_name.find("_truncate.bin") != std::string::npos) return true;
    return false;
}

bool is_soft_quarantine_reason(const std::string& reason) {
    std::string r = to_lower_copy(reason);
    return r.find("obfuscation/encoding cluster") != std::string::npos ||
           r.find("references quarantined file") != std::string::npos ||
           r.find("manifest references quarantined file") != std::string::npos;
}

ContentScanCacheEntry make_content_cache_entry(long long size, time_t modified,
                                               bool suspicious, const std::string& reason) {
    ContentScanCacheEntry entry;
    entry.size = size;
    entry.modified = modified;
    entry.suspicious = suspicious;
    entry.reason = reason;
    return entry;
}

std::string build_alert_signature(const std::string& type,
                                  const std::vector<FileInfo>& files,
                                  double cleaned_mb,
                                  int tcp_conns,
                                  const std::string& reason) {
    std::ostringstream sig;
    sig << type << "|tcp=" << tcp_conns << "|clean=" << (int)std::llround(cleaned_mb)
        << "|reason=" << reason << "|files=" << files.size();

    int count = 0;
    for (const auto& f : files) {
        sig << "|" << (f.hash.empty() ? f.name : f.hash);
        if (!f.suspicion_reason.empty()) sig << ":" << f.suspicion_reason;
        if (++count >= 10) break;
    }

    return sig.str();
}

bool ends_with_copy(const std::string& value, const std::string& suffix) {
    return value.size() >= suffix.size() &&
           value.compare(value.size() - suffix.size(), suffix.size(), suffix) == 0;
}

bool file_list_is_quarantine_only(const std::vector<FileInfo>& files) {
    if (files.empty()) return false;
    for (const auto& f : files) {
        if (!ends_with_copy(f.path, ".karantina")) return false;
    }
    return true;
}

std::string get_guard_home_for_cache() {
    const char* env_home = std::getenv("DANN_GUARD_HOME");
    if (env_home && *env_home) return env_home;
    return "/pteroprotect";
}

std::string get_alert_cache_file() {
    return get_guard_home_for_cache() + "/alert_dedupe.tsv";
}

void load_alert_cache_locked() {
    if (alert_cache_loaded) return;
    alert_cache_loaded = true;

    std::ifstream in(get_alert_cache_file().c_str());
    if (!in.is_open()) return;

    std::string line;
    while (std::getline(in, line)) {
        if (line.empty()) continue;
        size_t p1 = line.find('\t');
        size_t p2 = p1 == std::string::npos ? std::string::npos : line.find('\t', p1 + 1);
        if (p1 == std::string::npos || p2 == std::string::npos) continue;

        std::string server_uuid = line.substr(0, p1);
        time_t sent_at = (time_t)std::atoll(line.substr(p1 + 1, p2 - p1 - 1).c_str());
        std::string signature = line.substr(p2 + 1);
        if (!server_uuid.empty() && sent_at > 0 && !signature.empty()) {
            cache_last_alert_sent[server_uuid] = sent_at;
            cache_last_alert_signature[server_uuid] = signature;
        }
    }
}

void persist_alert_cache_locked() {
    std::ofstream out(get_alert_cache_file().c_str(), std::ios::trunc);
    if (!out.is_open()) return;

    for (const auto& kv : cache_last_alert_signature) {
        std::map<std::string, time_t>::const_iterator time_it = cache_last_alert_sent.find(kv.first);
        if (time_it == cache_last_alert_sent.end()) continue;
        out << kv.first << '\t' << (long long)time_it->second << '\t' << kv.second << '\n';
    }
}

bool should_send_alert(const std::string& server_uuid,
                       const std::string& signature,
                       time_t now) {
    std::lock_guard<std::mutex> lock(cache_mutex);
    load_alert_cache_locked();
    auto sig_it = cache_last_alert_signature.find(server_uuid);
    auto time_it = cache_last_alert_sent.find(server_uuid);
    if (sig_it != cache_last_alert_signature.end() &&
        time_it != cache_last_alert_sent.end() &&
        sig_it->second == signature &&
        (now - time_it->second) < ALERT_DEDUP_SECONDS) {
        return false;
    }

    cache_last_alert_signature[server_uuid] = signature;
    cache_last_alert_sent[server_uuid] = now;
    persist_alert_cache_locked();
    return true;
}

std::string exec_read_first_line(const std::string& cmd) {
    FILE* p = popen(cmd.c_str(), "r");
    if (!p) return "";
    char buf[512];
    std::string out;
    if (fgets(buf, sizeof(buf), p)) out = buf;
    pclose(p);
    return trim(out);
}

std::string base_name(const std::string& path) {
    size_t pos = path.find_last_of('/');
    return pos == std::string::npos ? path : path.substr(pos + 1);
}

std::string dir_name(const std::string& path) {
    size_t pos = path.find_last_of('/');
    if (pos == std::string::npos) return ".";
    if (pos == 0) return "/";
    return path.substr(0, pos);
}

bool file_exists_regular(const std::string& path) {
    struct stat st;
    return stat(path.c_str(), &st) == 0 && S_ISREG(st.st_mode);
}

bool is_manifest_name(const std::string& name) {
    static const std::set<std::string> manifest_names = {
        "package.json", "package-lock.json", "yarn.lock", "pnpm-lock.yaml",
        "composer.json", "composer.lock", "requirements.txt", "pyproject.toml",
        "pipfile", "pipfile.lock", "docker-compose.yml", "docker-compose.yaml",
        "compose.yml", "compose.yaml", "ecosystem.config.js", "ecosystem.config.cjs",
        "pm2.config.js", "pm2.config.cjs", "tsconfig.json", "vite.config.js",
        "vite.config.ts", "webpack.config.js", "next.config.js", "nuxt.config.js",
        ".env", ".env.production", ".env.local", "config.yml", "config.yaml",
        "app.yml", "app.yaml"
    };
    return manifest_names.count(to_lower_copy(name)) > 0;
}

bool is_reference_scan_candidate(const std::string& name) {
    static const std::vector<std::string> text_exts = {
        ".js", ".mjs", ".cjs", ".ts", ".jsx", ".tsx", ".json", ".yml", ".yaml",
        ".toml", ".env", ".conf", ".cfg", ".ini", ".sh", ".bash", ".py", ".php",
        ".lua", ".txt", ".md"
    };

    std::string lower = to_lower_copy(name);
    if (is_manifest_name(lower)) return true;
    for (const auto& ext : text_exts) {
        if (lower.size() >= ext.size() && lower.substr(lower.size() - ext.size()) == ext) {
            return true;
        }
    }
    return false;
}

bool text_file_mentions(const std::string& path, const std::vector<std::string>& needles) {
    struct stat st;
    if (stat(path.c_str(), &st) != 0 || !S_ISREG(st.st_mode) || st.st_size <= 0 || st.st_size > 1024 * 1024) {
        return false;
    }

    std::ifstream f(path, std::ios::binary);
    if (!f.is_open()) return false;
    std::string content((std::istreambuf_iterator<char>(f)), std::istreambuf_iterator<char>());
    std::string lc = to_lower_copy(content);

    for (const auto& needle : needles) {
        if (!needle.empty() && lc.find(needle) != std::string::npos) {
            return true;
        }
    }
    return false;
}

FileInfo stat_file_info(const std::string& path, const std::string& reason) {
    FileInfo file;
    struct stat st;
    if (stat(path.c_str(), &st) == 0) {
        file.size = st.st_size;
        file.modified = st.st_mtime;
        file.accessed = st.st_atime;
        file.is_directory = S_ISDIR(st.st_mode);
        file.is_symlink = S_ISLNK(st.st_mode);
    }

    file.path = path;
    file.name = base_name(path);
    size_t dot = file.name.find_last_of(".");
    if (dot != std::string::npos) file.extension = file.name.substr(dot);
    file.is_suspicious = true;
    file.suspicion_reason = reason;
    return file;
}

std::vector<FileInfo> find_related_files_for_quarantine(const std::string& server_root, const FileInfo& seed) {
    std::vector<FileInfo> related;
    std::set<std::string> added;

    std::string file_dir = dir_name(seed.path);
    std::string parent_dir = dir_name(file_dir);
    std::string lower_name = to_lower_copy(seed.name);
    std::string lower_path = to_lower_copy(seed.path);
    std::vector<std::string> needles = {
        lower_name,
        to_lower_copy("./" + seed.name),
        to_lower_copy("../" + seed.name),
        to_lower_copy("./" + lower_name),
        to_lower_copy(seed.path)
    };

    auto add_candidate = [&](const std::string& candidate, const std::string& why) {
        if (candidate.empty() || candidate == seed.path) return;
        if (candidate.find(server_root + "/.dann_quarantine/") == 0) return;
        if (!file_exists_regular(candidate)) return;
        if (!added.insert(candidate).second) return;
        related.push_back(stat_file_info(candidate, why));
    };

    for (const auto& dir : {file_dir, parent_dir}) {
        if (dir.empty() || dir.find(server_root) != 0) continue;

        DIR* dp = opendir(dir.c_str());
        if (!dp) continue;

        struct dirent* entry;
        while ((entry = readdir(dp)) != nullptr) {
            std::string name = entry->d_name;
            if (name == "." || name == "..") continue;
            std::string full = dir + "/" + name;
            if (!file_exists_regular(full)) continue;

            if (is_manifest_name(name)) {
                if (text_file_mentions(full, needles)) {
                    add_candidate(full, "Manifest references quarantined file: " + seed.name);
                }
                continue;
            }

            if (!is_reference_scan_candidate(name)) continue;
            if (text_file_mentions(full, needles)) {
                add_candidate(full, "References quarantined file: " + seed.name);
            }
        }

        closedir(dp);
    }

    return related;
}

bool token_match(const std::string& text, const std::string& token) {
    size_t pos = text.find(token);
    while (pos != std::string::npos) {
        bool left_ok = (pos == 0) || !std::isalnum((unsigned char)text[pos - 1]);
        size_t end = pos + token.size();
        bool right_ok = (end >= text.size()) || !std::isalnum((unsigned char)text[end]);
        if (left_ok && right_ok) return true;
        pos = text.find(token, pos + 1);
    }
    return false;
}

std::string violation_type_from_reason(const std::string& reason) {
    std::string r = to_lower_copy(reason);
    if (r.find("disk") != std::string::npos) return "disk_over";
    if (r.find("flood") != std::string::npos) return "file_flood";
    return "illegal_process";
}

bool looks_like_cloud_metadata_abuse_text(const std::string& text) {
    if (text.empty()) return false;

    std::string lc = to_lower_copy(text);
    static const std::vector<std::string> indicators = {
        "169.254.169.254",
        "169.254.170.2",
        "100.100.100.200",
        "metadata.google.internal",
        "latest/meta-data",
        "computeMetadata/v1",
        "metadata/instance",
        "iam/security-credentials",
        "/var/lib/cloud/",
        "/var/lib/cloud/instance",
        "/var/lib/cloud/seed",
        "/run/cloud-init",
        "/etc/cloud/",
        "/etc/cloud/cloud.cfg",
        "cloud-init",
        "opc/v2"
    };

    for (const auto& indicator : indicators) {
        if (lc.find(to_lower_copy(indicator)) != std::string::npos) {
            return true;
        }
    }

    return false;
}

bool contains_cloud_metadata_abuse(const std::vector<FileInfo>& files) {
    for (const auto& file : files) {
        if (looks_like_cloud_metadata_abuse_text(file.name) ||
            looks_like_cloud_metadata_abuse_text(file.path) ||
            looks_like_cloud_metadata_abuse_text(file.suspicion_reason)) {
            return true;
        }
    }
    return false;
}

int severity_from_metrics(double disk_gb, double disk_limit_gb, double spike_disk, int tcp_conns) {
    int s = 1;
    if (disk_gb > disk_limit_gb) s += 2;
    if (spike_disk > DISK_SPIKE_HARD_GB) s += 2;
    if (tcp_conns >= TCP_CONN_CRITICAL) s += 4;
    else if (tcp_conns >= TCP_CONN_WARNING) s += 2;
    if (s > 10) s = 10;
    return s;
}

std::string get_waktu_wib() {
    std::time_t now = std::time(nullptr);
    std::tm tm_wib = *std::localtime(&now);
    tm_wib.tm_hour += 7;
    std::mktime(&tm_wib);
    char waktu[80];
    std::strftime(waktu, sizeof(waktu), "%d/%m/%Y %H:%M:%S WIB", &tm_wib);
    return std::string(waktu);
}

std::string get_container_id_by_uuid(const std::string& uuid) {
    if (!is_valid_uuid(uuid)) return "";
    std::string cmd = "docker ps --filter label=service_uuid=" + uuid + " --format '{{.ID}}' | head -n 1";
    return exec_read_first_line(cmd);
}

bool stop_container_by_uuid(const std::string& uuid) {
    std::string id = get_container_id_by_uuid(uuid);
    if (id.empty()) return false;

    // Prevent attacker workload from auto-restarting while we are mitigating.
    system(("docker update --restart=no " + id + " >/dev/null 2>&1").c_str());

    if (system(("docker stop -t 3 " + id + " >/dev/null 2>&1").c_str()) == 0) {
        return true;
    }

    return system(("docker kill " + id + " >/dev/null 2>&1").c_str()) == 0;
}

int get_container_tcp_connections(const std::string& uuid) {
    std::string id = get_container_id_by_uuid(uuid);
    if (id.empty()) return 0;
    std::string cmd = "docker inspect --format '{{.State.Pid}}' " + id + " 2>/dev/null";
    std::string pid_str = exec_read_first_line(cmd);
    if (pid_str.empty() || pid_str == "0") return 0;
    std::string count_cmd = "cat /proc/" + pid_str + "/net/tcp /proc/" + pid_str +
                            "/net/tcp6 2>/dev/null | tail -n +2 | wc -l";
    std::string count_str = exec_read_first_line(count_cmd);
    return count_str.empty() ? 0 : atoi(count_str.c_str());
}

std::string rakit_laporan_server(const std::string& tipe, double ukuran, double lonjakan,
                                 double apparent_ukuran, double apparent_lonjakan,
                                 double mb_dihapus, const ServerInfo& info,
                                 const std::vector<FileInfo>& daftar_file,
                                 int tcp_conns = 0,
                                 const std::string& sumber_besar = "",
                                 const std::string& aksi = "") {
    std::string waktu = get_waktu_wib();
    std::string owner = info.first_name + " " + info.last_name;
    if (owner == " " || owner.empty()) owner = info.username;
    if (owner.empty()) owner = "Tidak Diketahui";

    std::ostringstream msg;
    msg << "<b>🛡️ DANN GUARD PROTECTION</b>\n"
        << "<b>🚨 " << tipe << "</b>\n"
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
        << "<b>📊 RESOURCE USAGE</b>\n"
        << "<blockquote>"
        << "Disk Real : <code>" << std::fixed << std::setprecision(2) << ukuran << "GB</code>";

    if (lonjakan > 0.01) msg << " (+<code>" << std::fixed << std::setprecision(2) << lonjakan << "GB</code>)";
    msg << "\nDisk App  : <code>" << std::fixed << std::setprecision(2) << apparent_ukuran << "GB</code>";
    if (apparent_lonjakan > 0.01) {
        msg << " (+<code>" << std::fixed << std::setprecision(2) << apparent_lonjakan << "GB</code>)";
    }

    if (tcp_conns > 0) {
        msg << "\nTCP Conns : <code>" << tcp_conns << "</code>";
        if (tcp_conns >= TCP_CONN_CRITICAL) msg << " ⚠️ FLOOD";
        else if (tcp_conns >= TCP_CONN_WARNING) msg << " ⚠️";
    }

    msg << "\nCleaned   : <code>" << (int)std::round(mb_dihapus) << "MB</code>\n"
        << "</blockquote>\n\n";

    if (!daftar_file.empty()) {
        bool quarantined_only = file_list_is_quarantine_only(daftar_file);
        msg << "<b>📋 " << (quarantined_only ? "FILES QUARANTINED" : "FILES DELETED") << "</b>\n<blockquote>";
        int count = 0;
        for (const auto& f : daftar_file) {
            msg << "├─ <code>" << f.name << "</code> <i>(" << f.suspicion_reason << ")</i>\n";
            if (++count >= 10) {
                int rem = (int)daftar_file.size() - 10;
                if (rem > 0) msg << "└─ <i>... and " << rem << " more</i>\n";
                break;
            }
        }
        msg << "</blockquote>\n\n";
    }

    if (!sumber_besar.empty()) {
        msg << "<b>📦 BIGGEST PATHS</b>\n<blockquote>"
            << sumber_besar
            << "</blockquote>\n\n";
    }

    if (!aksi.empty()) {
        msg << "<b>⚙️ ACTION TAKEN</b>\n"
            << "<blockquote><code>" << aksi << "</code></blockquote>\n\n";
    }

    msg << "<b>🛑 VIOLATION REASON</b>\n"
        << "<blockquote>" << tipe << "</blockquote>\n\n"
        << "━━━━━━━━━━━━━━━━━━━\n"
        << "<b>👤 Creator:</b> @gantengdann\n"
        << "<b>📢 Channel:</b> @aboutdannz\n"
        << "<b>📢 Report:</b> @reportdann";
    return msg.str();
}

bool parse_size_path_line(const std::string& line, long long& size, std::string& path_out);

std::string get_top_disk_sources(const std::string& path, int limit = 5) {
    std::string cmd = "du -x -k -d 2 \"" + path + "\" 2>/dev/null | sort -nr | head -n " + std::to_string(limit + 1);
    FILE* p = popen(cmd.c_str(), "r");
    if (!p) return "";

    char buf[512];
    std::ostringstream formatted;
    int count = 0;

    while (fgets(buf, sizeof(buf), p)) {
        long long kb = 0;
        std::string entry_path;
        if (!parse_size_path_line(buf, kb, entry_path)) continue;
        if (entry_path == path) continue;

        std::string label = entry_path;
        if (label.find(path + "/") == 0) label = label.substr(path.size() + 1);
        if (label.empty()) continue;

        double gb = kb / (1024.0 * 1024.0);
        formatted << "• " << label << " = <code>"
                  << std::fixed << std::setprecision(2) << gb << "GB</code>\n";
        if (++count >= limit) break;
    }

    pclose(p);
    return formatted.str();
}

bool parse_size_path_line(const std::string& line, long long& size, std::string& path_out) {
    size = 0;
    path_out.clear();
    std::string s = trim(line);
    if (s.empty()) return false;

    std::size_t split_pos = std::string::npos;
    for (std::size_t i = 0; i < s.size(); ++i) {
        unsigned char c = static_cast<unsigned char>(s[i]);
        if (std::isspace(c)) {
            split_pos = i;
            break;
        }
    }
    if (split_pos == std::string::npos) return false;

    std::string lhs = trim(s.substr(0, split_pos));
    std::size_t rhs_begin = split_pos;
    while (rhs_begin < s.size() && std::isspace(static_cast<unsigned char>(s[rhs_begin]))) rhs_begin++;
    if (rhs_begin >= s.size()) return false;

    std::string rhs = trim(s.substr(rhs_begin));
    if (lhs.empty() || rhs.empty()) return false;
    char* endptr = nullptr;
    long long parsed = std::strtoll(lhs.c_str(), &endptr, 10);
    if (!endptr || *endptr != '\0' || parsed < 0) return false;
    size = parsed;
    path_out = rhs;
    return true;
}

unsigned long long get_host_free_bytes(const std::string& path) {
    struct statvfs vfs;
    if (statvfs(path.c_str(), &vfs) != 0) return 0;
    return (unsigned long long)vfs.f_bavail * (unsigned long long)vfs.f_frsize;
}

std::string shell_escape_single(const std::string& s) {
    std::string out = "'";
    for (char c : s) {
        if (c == '\'') out += "'\\''";
        else out += c;
    }
    out += "'";
    return out;
}

bool ensure_dir(const std::string& path) {
    std::string cmd = "mkdir -p " + shell_escape_single(path) + " >/dev/null 2>&1";
    return system(cmd.c_str()) == 0;
}

bool volume_has_only_quarantine_artifacts(const std::string& path) {
    DIR* dir = opendir(path.c_str());
    if (!dir) return false;

    struct dirent* entry;
    bool has_any_entry = false;
    bool only_quarantine = true;
    while ((entry = readdir(dir)) != nullptr) {
        std::string name = entry->d_name;
        if (name == "." || name == "..") continue;
        has_any_entry = true;

        if (name == ".dann_quarantine") continue;

        std::string lower = to_lower_copy(name);
        if (lower.size() >= 10 && lower.substr(lower.size() - 10) == ".karantina") continue;

        only_quarantine = false;
        break;
    }

    closedir(dir);
    return has_any_entry && only_quarantine;
}

double emergency_delete_largest_files(const std::string& path, unsigned long long min_target_free_bytes) {
    std::string cmd = "find \"" + path + "\" -xdev -type f -printf '%s\\t%p\\n' 2>/dev/null | sort -nr | head -n 80";
    FILE* p = popen(cmd.c_str(), "r");
    if (!p) return 0.0;

    char buf[8192];
    double freed_mb = 0.0;
    while (fgets(buf, sizeof(buf), p)) {
        long long size = 0;
        std::string file_path;
        if (!parse_size_path_line(buf, size, file_path)) continue;
        if (file_path.empty()) continue;
        if (unlink(file_path.c_str()) == 0) {
            freed_mb += size / (1024.0 * 1024.0);
        }
        if (get_host_free_bytes(path) >= min_target_free_bytes) break;
    }

    pclose(p);
    return freed_mb;
}

bool remove_path_force(const std::string& path) {
    std::string cmd = "chattr -R -i " + shell_escape_single(path) + " >/dev/null 2>&1 || true; "
                    + std::string("rm -rf ") + shell_escape_single(path) + " >/dev/null 2>&1";
    return system(cmd.c_str()) == 0;
}

std::vector<FileInfo> reclaim_largest_entries(const std::string& root_path,
                                              double reclaim_target_mb,
                                              double& reclaimed_mb,
                                              int max_entries = 3) {
    std::vector<FileInfo> reclaimed;
    reclaimed_mb = 0.0;

    std::string cmd = "du -x -k -d 1 \"" + root_path + "\" 2>/dev/null | sort -nr";
    FILE* p = popen(cmd.c_str(), "r");
    if (!p) return reclaimed;

    char buf[8192];
    while (fgets(buf, sizeof(buf), p)) {
        long long kb = 0;
        std::string entry_path;
        if (!parse_size_path_line(buf, kb, entry_path)) continue;
        if (entry_path.empty() || entry_path == root_path) continue;

        std::string label = entry_path;
        if (label.find(root_path + "/") == 0) label = label.substr(root_path.size() + 1);
        if (label.empty() || label == ".dann_quarantine") continue;

        if (!remove_path_force(entry_path)) {
            logger.warn("⚠️ Failed to remove large path directly: " + entry_path + " (trying file-level reclaim)");

            std::string fcmd =
                "find " + shell_escape_single(entry_path) +
                " -xdev -type f -printf '%s %p\\n' 2>/dev/null | sort -nr | head -n 30";
            FILE* fp = popen(fcmd.c_str(), "r");
            if (!fp) continue;

            char fbuf[4096];
            int reclaimed_files = 0;
            while (fgets(fbuf, sizeof(fbuf), fp)) {
                long long fbytes = 0;
                std::string fpath;
                if (!parse_size_path_line(fbuf, fbytes, fpath)) continue;
                if (fbytes <= 0 || fpath.empty()) continue;
                if (unlink(fpath.c_str()) != 0) continue;

                FileInfo fi;
                fi.name = fpath;
                if (fi.name.find(root_path + "/") == 0) fi.name = fi.name.substr(root_path.size() + 1);
                fi.path = fpath;
                fi.size = fbytes;
                fi.suspicion_reason = "Disk over limit file-level reclaim";
                reclaimed.push_back(fi);
                reclaimed_mb += fbytes / (1024.0 * 1024.0);
                reclaimed_files++;

                if ((int)reclaimed.size() >= max_entries * 6) break;
                if (reclaim_target_mb > 0.0 && reclaimed_mb >= reclaim_target_mb) break;
            }
            pclose(fp);
            if (reclaimed_files == 0) continue;
            if ((int)reclaimed.size() >= max_entries * 6) break;
            if (reclaim_target_mb > 0.0 && reclaimed_mb >= reclaim_target_mb) break;
            continue;
        }

        FileInfo item;
        item.name = label;
        item.path = entry_path;
        item.size = kb * 1024LL;
        item.suspicion_reason = "Disk over limit auto cleanup";
        reclaimed.push_back(item);
        reclaimed_mb += kb / 1024.0;

        if ((int)reclaimed.size() >= max_entries) break;
        if (reclaim_target_mb > 0.0 && reclaimed_mb >= reclaim_target_mb) break;
    }

    pclose(p);
    return reclaimed;
}
} // namespace

bool is_safe_file(const std::string& filename) {
    std::string lower = to_lower_copy(filename);
    if (lower.size() >= 10 && lower.substr(lower.size() - 10) == ".karantina") return true;
    for (const auto& s : SAFE_FILES) if (lower == s) return true;
    for (const auto& ext : EKSTENSI_AMAN) {
        if (lower.size() >= ext.size() && lower.substr(lower.size() - ext.size()) == ext) return true;
    }
    return false;
}

DiskProtector::DiskProtector() : running(false) {}
DiskProtector::~DiskProtector() { stop(); }

void DiskProtector::init(const std::string& path, double max_disk, int max_size, int max_flood, int window, int interval) {
    volumes_path = path;
    max_disk_gb = max_disk;
    max_file_size_mb = max_size;
    max_file_flood = max_flood;
    flood_window = window;
    check_interval = interval > 0 ? interval : JEDA_CEK_DETIK;
    auto_cleanup = true;
    auto_suspend = true;
}

bool DiskProtector::is_path_allowed(const std::string& path) {
    static const std::vector<std::string> allowed = {
        "/node_modules/",
        "/vendor/",
        "/.git/",
        "/.cache/",
        "/.dann_quarantine/",
        "/tmp/",
        "/logs/",
        "/.npm/",
        "/.pnpm-store/",
        "/.yarn/"
    };
    for (const auto& seg : allowed) if (path.find(seg) != std::string::npos) return true;
    std::string lower = to_lower_copy(path);
    if (lower.size() >= 10 && lower.substr(lower.size() - 10) == ".karantina") return true;
    return false;
}

std::string DiskProtector::get_file_hash(const std::string& path) {
    std::ifstream file(path, std::ios::binary);
    if (!file.is_open()) return "";

    EVP_MD_CTX* mdctx = EVP_MD_CTX_new();
    if (!mdctx) return "";
    const EVP_MD* md = EVP_sha256();

    if (EVP_DigestInit_ex(mdctx, md, NULL) != 1) {
        EVP_MD_CTX_free(mdctx);
        return "";
    }

    char buffer[8192];
    while (file.good()) {
        file.read(buffer, sizeof(buffer));
        std::streamsize n = file.gcount();
        if (n > 0) EVP_DigestUpdate(mdctx, buffer, (size_t)n);
    }

    unsigned char hash[EVP_MAX_MD_SIZE];
    unsigned int hash_len = 0;
    EVP_DigestFinal_ex(mdctx, hash, &hash_len);
    EVP_MD_CTX_free(mdctx);

    std::ostringstream oss;
    for (unsigned int i = 0; i < hash_len; i++) {
        oss << std::hex << std::setw(2) << std::setfill('0') << (int)hash[i];
    }
    return oss.str();
}

bool DiskProtector::is_binary_file(const std::string& path) {
    std::ifstream file(path, std::ios::binary);
    if (!file.is_open()) return false;

    char buffer[1024];
    file.read(buffer, sizeof(buffer));
    int count = (int)file.gcount();
    for (int i = 0; i < count; i++) if (buffer[i] == '\0') return true;
    return false;
}

double DiskProtector::get_folder_size_gb(const std::string& path) {
    std::string cmd = "du -x -sk \"" + path + "\" 2>/dev/null | cut -f1";
    std::string r = exec_read_first_line(cmd);
    return r.empty() ? 0.0 : atof(r.c_str()) / (1024.0 * 1024.0);
}

double DiskProtector::get_folder_apparent_size_gb(const std::string& path) {
    std::string cmd = "du -x --apparent-size -sk \"" + path + "\" 2>/dev/null | cut -f1";
    std::string r = exec_read_first_line(cmd);
    return r.empty() ? 0.0 : atof(r.c_str()) / (1024.0 * 1024.0);
}

std::vector<FileInfo> DiskProtector::scan_folder(const std::string& path, int depth) {
    std::vector<FileInfo> suspicious;
    if (depth > MAX_SCAN_DEPTH) return suspicious;

    DIR* dir = opendir(path.c_str());
    if (!dir) return suspicious;

    struct dirent* entry;
    while ((entry = readdir(dir)) != nullptr) {
        std::string name = entry->d_name;
        if (name == "." || name == "..") continue;

        std::string full = path + "/" + name;
        struct stat st;
        if (lstat(full.c_str(), &st) != 0) continue;
        if (is_path_allowed(full)) continue;

        if (S_ISDIR(st.st_mode)) {
            auto nested = scan_folder(full, depth + 1);
            if (!nested.empty()) {
                suspicious.reserve(suspicious.size() + nested.size());
                suspicious.insert(suspicious.end(), nested.begin(), nested.end());
            }
            continue;
        }

        if (!S_ISREG(st.st_mode)) continue;
        if (is_safe_file(name)) continue;

        FileInfo file;
        file.name = name;
        file.path = full;
        file.size = st.st_size;
        file.modified = st.st_mtime;
        file.accessed = st.st_atime;
        file.is_symlink = S_ISLNK(st.st_mode);

        size_t dot = name.find_last_of(".");
        if (dot != std::string::npos) file.extension = name.substr(dot);

        std::string reason;
        if (!is_suspicious_file(file, reason)) continue;

        file.is_suspicious = true;
        file.suspicion_reason = reason;
        suspicious.push_back(file);
    }

    closedir(dir);
    return suspicious;
}

bool DiskProtector::is_suspicious_file(const FileInfo& file, std::string& reason) {
    if (is_safe_file(file.name)) return false;
    if (is_path_allowed(file.path)) return false;
    if (file.size < 1024) return false;

    {
        std::lock_guard<std::mutex> lock(cache_mutex);
        auto allow_it = soft_allow_until.find(file.path);
        if (allow_it != soft_allow_until.end()) {
            if (allow_it->second > time(nullptr)) return false;
            soft_allow_until.erase(allow_it);
        }
    }

    struct stat st;
    if (stat(file.path.c_str(), &st) == 0 && st.st_size >= SPARSE_FILE_MIN_SIZE_BYTES) {
        long long allocated_bytes = (long long)st.st_blocks * 512LL;
        double allocated_ratio = st.st_size > 0
            ? static_cast<double>(allocated_bytes) / static_cast<double>(st.st_size)
            : 1.0;
        if (allocated_bytes <= SPARSE_FILE_MAX_ALLOCATED_BYTES &&
            allocated_ratio <= SPARSE_FILE_MAX_ALLOCATED_RATIO) {
            std::ostringstream oss;
            oss << "Sparse fake-size file detected: apparent="
                << std::fixed << std::setprecision(2)
                << (st.st_size / (1024.0 * 1024.0 * 1024.0))
                << "GB allocated="
                << (allocated_bytes / (1024.0 * 1024.0))
                << "MB";
            reason = oss.str();
            return true;
        }
    }

    if (stat(file.path.c_str(), &st) == 0) {
        bool exec_mode = (st.st_mode & (S_IXUSR | S_IXGRP | S_IXOTH)) != 0;
        bool name_dropper = is_known_dropper_filename(file.name);
        bool elf_payload = is_probably_elf_file(file.path);
        if (name_dropper && (exec_mode || elf_payload || st.st_size >= 16384)) {
            reason = "Known dropper filename detected: " + file.name;
            return true;
        }
    }

    // ── 1. Filename check against all keyword lists ─────────────────────────
    std::string lower_name = to_lower_copy(file.name);

    if (is_disk_flood_artifact_name(lower_name)) {
        reason = "Disk flood artifact filename: " + file.name;
        return true;
    }

    for (const auto& k : CRITICAL_KEYWORDS) {
        if (lower_name.find(to_lower_copy(k)) != std::string::npos) {
            reason = "Filename matches critical pattern: " + k;
            return true;
        }
    }
    for (const auto& k : KATA_KUNCI_FILE_JAHAT) {
        std::string lk = to_lower_copy(k);
        if (token_match(lower_name, lk)) {
            reason = "Filename contains: " + k;
            return true;
        }
    }

    // ── 2. File-size limit ───────────────────────────────────────────────────
    // Do not auto-delete files based on size alone; directory-level disk
    // protection handles quota abuse with fewer false positives.
    double size_mb = file.size / (1024.0 * 1024.0);

    // ── 3. Content scanning ─────────────────────────────────────────────────
    // Scan up to 64 KB from the start of any file ≤ 50 MB, regardless of
    // binary / text.  For binary files we also scan embedded printable text
    // so an encoded payload inside a .so or .bin is not missed.
    const size_t MAX_SCAN_BYTES = 65536;   // 64 KB
    const double MAX_SCAN_MB    = 50.0;    // don't scan giants
    const double MIN_PRINTABLE_RATIO = 0.85;

    if (size_mb > MAX_SCAN_MB) return false;

    {
        std::lock_guard<std::mutex> lock(cache_mutex);
        auto it = content_scan_cache.find(file.path);
        if (it != content_scan_cache.end() &&
            it->second.size == file.size &&
            it->second.modified == file.modified) {
            reason = it->second.reason;
            return it->second.suspicious;
        }
    }

    std::ifstream f(file.path, std::ios::binary);
    if (!f.is_open()) return false;

    // Read up to MAX_SCAN_BYTES
    std::string raw(MAX_SCAN_BYTES, '\0');
    f.read(&raw[0], (std::streamsize)MAX_SCAN_BYTES);
    raw.resize((size_t)f.gcount());
    f.close();

    // Build a printable-text-only copy (replace non-printable bytes with ' ')
    // so we can run string searches against binary blobs safely.
    std::string printable;
    printable.reserve(raw.size());
    size_t printable_count = 0;
    for (unsigned char c : raw) {
        bool is_printable = (c >= 0x20 && c < 0x7f) || c == '\n' || c == '\r' || c == '\t';
        if (is_printable) printable_count++;
        printable += is_printable ? (char)c : ' ';
    }

    double printable_ratio = raw.empty()
        ? 0.0
        : static_cast<double>(printable_count) / static_cast<double>(raw.size());

    if (!has_text_like_extension(file.name) && printable_ratio < MIN_PRINTABLE_RATIO) {
        std::lock_guard<std::mutex> lock(cache_mutex);
        content_scan_cache[file.path] = make_content_cache_entry(file.size, file.modified, false, "");
        return false;
    }

    std::string lc = to_lower_copy(printable);

    // 3a. Single-hit critical keyword check
    for (const auto& k : CRITICAL_KEYWORDS) {
        std::string lk = to_lower_copy(k);
        if (lc.find(lk) != std::string::npos) {
            reason = "Content matches critical pattern: " + k;
            std::lock_guard<std::mutex> lock(cache_mutex);
            content_scan_cache[file.path] = make_content_cache_entry(file.size, file.modified, true, reason);
            return true;
        }
    }

    // 3b. KATA_KUNCI_FILE_JAHAT — require 2 distinct matches
    {
        int hit = 0;
        std::string first_match;
        for (const auto& k : KATA_KUNCI_FILE_JAHAT) {
            std::string lk = to_lower_copy(k);
            if (lc.find(lk) != std::string::npos) {
                if (++hit == 1) first_match = k;
                if (hit >= 2) {
                    reason = "Content has multiple suspicious patterns (e.g. \"" + first_match + "\")";
                    std::lock_guard<std::mutex> lock(cache_mutex);
                    content_scan_cache[file.path] = make_content_cache_entry(file.size, file.modified, true, reason);
                    return true;
                }
            }
        }
    }

    // 3c. Obfuscation / encryption pattern cluster — require 3 distinct matches
    {
        int hit = 0;
        std::string first_match;
        for (const auto& k : OBFUSCATION_PATTERNS) {
            std::string lk = to_lower_copy(k);
            if (lc.find(lk) != std::string::npos) {
                if (++hit == 1) first_match = k;
                if (hit >= 3) {
                    reason = "Content shows obfuscation/encoding cluster (e.g. \"" + first_match + "\")";
                    std::lock_guard<std::mutex> lock(cache_mutex);
                    content_scan_cache[file.path] = make_content_cache_entry(file.size, file.modified, true, reason);
                    return true;
                }
            }
        }
    }

    {
        std::lock_guard<std::mutex> lock(cache_mutex);
        content_scan_cache[file.path] = make_content_cache_entry(file.size, file.modified, false, "");
    }

    return false;
}

void DiskProtector::delete_file(const std::string& path, const std::string& reason) {
    struct stat st;
    if (lstat(path.c_str(), &st) != 0) return;
    if (!S_ISREG(st.st_mode)) return;

    if (unlink(path.c_str()) == 0) logger.info("🗑️ Deleted: " + path + " (" + reason + ")");
    else logger.error("❌ Failed to delete: " + path);
}

double DiskProtector::wipe_server_volume(const std::string& volume_path) {
    if (volume_path.empty() || volume_path == "/" || volume_path == volumes_path) {
        logger.error("❌ Refusing hard-wipe on unsafe path: " + volume_path);
        return 0.0;
    }

    std::string allowed_prefix = volumes_path + "/";
    if (volume_path.rfind(allowed_prefix, 0) != 0) {
        logger.error("❌ Refusing hard-wipe outside volumes path: " + volume_path);
        return 0.0;
    }

    double before_mb = get_folder_size_gb(volume_path) * 1024.0;
    int rc = 1;
    for (int pass = 0; pass < 6; ++pass) {
        std::string unlock_cmd =
            "chattr -R -i " + shell_escape_single(volume_path) + " >/dev/null 2>&1 || true";
        std::string chmod_cmd =
            "chmod -R u+w " + shell_escape_single(volume_path) + " >/dev/null 2>&1 || true";
        std::string wipe_cmd =
            "find " + shell_escape_single(volume_path) +
            " -xdev -mindepth 1 -depth -print0 2>/dev/null | xargs -0 -r rm -rf -- >/dev/null 2>&1";
        (void)system(unlock_cmd.c_str());
        (void)system(chmod_cmd.c_str());
        rc = system(wipe_cmd.c_str());
        double pass_after_mb = get_folder_size_gb(volume_path) * 1024.0;
        if (pass_after_mb <= 1.0) {
            rc = 0;
            break;
        }
        std::this_thread::sleep_for(std::chrono::milliseconds(120));
    }

    double after_mb = get_folder_size_gb(volume_path) * 1024.0;
    double freed_mb = before_mb - after_mb;
    if (freed_mb < 0.0) freed_mb = 0.0;

    if (rc == 0 || after_mb <= 1.0) {
        logger.warn("🧨 Hard-wipe volume: " + volume_path +
                    " freed=" + std::to_string((int)std::llround(freed_mb)) + "MB");
    } else {
        logger.error("❌ Hard-wipe incomplete on: " + volume_path +
                     " remaining=" + std::to_string((int)std::llround(after_mb)) + "MB");
    }

    return freed_mb;
}

bool DiskProtector::quarantine_file(const std::string& server_uuid, const FileInfo& file,
                                    std::string& quarantined_path) {
    std::string base = volumes_path + "/" + server_uuid + "/.dann_quarantine";
    if (!ensure_dir(base)) return false;

    std::string stamp = std::to_string((long long)time(nullptr));
    quarantined_path = base + "/" + stamp + "_" + file.name + ".karantina";
    if (rename(file.path.c_str(), quarantined_path.c_str()) != 0) return false;

    chmod(quarantined_path.c_str(), 0);
    system(("chattr +i " + shell_escape_single(quarantined_path) + " >/dev/null 2>&1").c_str());

    {
        std::lock_guard<std::mutex> lock(cache_mutex);
        quarantine_until[quarantined_path] = time(nullptr) + QUARANTINE_HOLD_SECONDS;
        quarantine_reason[quarantined_path] = file.suspicion_reason;
        quarantine_original_path[quarantined_path] = file.path;
        quarantine_server_uuid[quarantined_path] = server_uuid;
        double score = local_trust_score.count(server_uuid) ? local_trust_score[server_uuid] : 100.0;
        score -= 12.0;
        if (score < 0.0) score = 0.0;
        local_trust_score[server_uuid] = score;
    }

    logger.warn("🔒 Quarantined: " + file.path + " -> " + quarantined_path);
    return true;
}

void DiskProtector::release_expired_quarantine() {
    time_t now = time(nullptr);
    std::vector<std::string> expired;
    {
        std::lock_guard<std::mutex> lock(cache_mutex);
        for (const auto& kv : quarantine_until) {
            if (kv.second <= now) expired.push_back(kv.first);
        }
    }

    for (const auto& path : expired) {
        std::string reason;
        std::string original_path;
        std::string server_uuid;
        double trust_score = 0.0;
        {
            std::lock_guard<std::mutex> lock(cache_mutex);
            reason = quarantine_reason.count(path) ? quarantine_reason[path] : "";
            original_path = quarantine_original_path.count(path) ? quarantine_original_path[path] : "";
            server_uuid = quarantine_server_uuid.count(path) ? quarantine_server_uuid[path] : "";
            trust_score = local_trust_score.count(server_uuid) ? local_trust_score[server_uuid] : 100.0;
        }

        system(("chattr -i " + shell_escape_single(path) + " >/dev/null 2>&1").c_str());
        chmod(path.c_str(), 0600);
        bool restored = false;
        if (!original_path.empty() &&
            is_soft_quarantine_reason(reason) &&
            trust_score >= 60.0 &&
            access(original_path.c_str(), F_OK) != 0) {
            ensure_dir(dir_name(original_path));
            if (rename(path.c_str(), original_path.c_str()) == 0) {
                chmod(original_path.c_str(), 0644);
                std::lock_guard<std::mutex> lock(cache_mutex);
                soft_allow_until[original_path] = now + SOFT_QUARANTINE_ALLOW_SECONDS;
                content_scan_cache.erase(original_path);
                restored = true;
                logger.warn("🔓 Restored soft-quarantined file: " + original_path +
                            " trust=" + std::to_string((int)trust_score));
            }
        }

        if (!restored) {
            unlink(path.c_str());
        }
        std::lock_guard<std::mutex> lock(cache_mutex);
        quarantine_until.erase(path);
        quarantine_reason.erase(path);
        quarantine_original_path.erase(path);
        quarantine_server_uuid.erase(path);
    }
}

void DiskProtector::check_server(const std::string& uuid) {
    if (!is_valid_uuid(uuid)) return;

    std::string path = volumes_path + "/" + uuid;
    ServerInfo info = db.get_server_info(uuid);
    if (info.id <= 0 && volume_has_only_quarantine_artifacts(path)) {
        return;
    }

    double disk_gb = get_folder_size_gb(path);
    double apparent_disk_gb = get_folder_apparent_size_gb(path);
    int tcp_conns = get_container_tcp_connections(uuid);

    double prev_disk = disk_gb;
    double prev_apparent_disk = apparent_disk_gb;
    int prev_hit = 0;
    time_t now = time(nullptr), last_action = 0;

    {
        std::lock_guard<std::mutex> lock(cache_mutex);
        if (cache_ukuran.count(uuid)) prev_disk = cache_ukuran[uuid];
        if (cache_ukuran_apparent.count(uuid)) prev_apparent_disk = cache_ukuran_apparent[uuid];
        if (cache_consecutive_hit.count(uuid)) prev_hit = cache_consecutive_hit[uuid];
        if (cache_last_action.count(uuid)) last_action = cache_last_action[uuid];
        cache_ukuran[uuid] = disk_gb;
        cache_ukuran_apparent[uuid] = apparent_disk_gb;
    }

    double spike_disk = disk_gb - prev_disk;
    double apparent_spike_disk = apparent_disk_gb - prev_apparent_disk;

    double disk_over_limit_gb = max_disk_gb * DISK_OVER_LIMIT_MULTIPLIER;
    bool disk_over  = disk_gb > disk_over_limit_gb;
    bool disk_spike = spike_disk > BATAS_LONJAKAN_GB;
    bool disk_hard  = spike_disk > DISK_SPIKE_HARD_GB;

    bool net_critical = tcp_conns >= TCP_CONN_CRITICAL;
    bool net_warning  = tcp_conns >= TCP_CONN_WARNING;

    bool anomaly = disk_over || disk_spike || disk_hard || net_critical;
    int hit = anomaly ? (prev_hit + 1) : 0;

    {
        std::lock_guard<std::mutex> lock(cache_mutex);
        cache_consecutive_hit[uuid] = hit;
    }

    bool cooldown_ok    = (now - last_action) >= ALERT_COOLDOWN_SECONDS;
    bool hard_trigger   = disk_hard || net_critical;
    bool normal_trigger = anomaly && hit >= CONSECUTIVE_TRIGGER_REQUIRED;
    bool need_action    = hard_trigger || (cooldown_ok && normal_trigger);
    // Always scan file contents every guard cycle so short-lived droppers
    // cannot slip through between anomaly-triggered passes.
    bool should_scan_files = true;

    std::string reason = "ANOMALY";
    if (net_critical) reason = "NETWORK FLOOD " + std::to_string(tcp_conns) + " TCP CONNS";
    else if (disk_hard)  reason = "DISK HARD SPIKE +" + std::to_string((int)spike_disk) + "GB";
    else if (disk_spike) reason = "DISK SPIKE +" + std::to_string((int)spike_disk) + "GB";
    else if (net_warning) reason = "NETWORK WARNING " + std::to_string(tcp_conns) + " TCP CONNS";
    else if (disk_over) reason = "DISK OVER LIMIT x" + std::to_string((int)DISK_OVER_LIMIT_MULTIPLIER);

    std::vector<FileInfo> files;
    if (should_scan_files) {
        files = scan_folder(path);
        std::lock_guard<std::mutex> lock(cache_mutex);
        cache_last_file_scan[uuid] = now;
    }

    int disk_flood_filename_hits = 0;
    for (const auto& f : files) {
        std::string r = to_lower_copy(f.suspicion_reason);
        if (r.find("disk flood artifact filename:") != std::string::npos) {
            disk_flood_filename_hits++;
        }
    }

    if (disk_flood_filename_hits >= DISK_FLOOD_FILENAME_HARD_THRESHOLD) {
        hard_trigger = true;
        need_action = true;
        reason = "DISK FLOOD ARTIFACT x" + std::to_string(disk_flood_filename_hits);
    }

    bool hard_delete_and_stop = need_action && (disk_over || disk_hard || (disk_flood_filename_hits > 0));

    std::vector<FileInfo> deleted;
    std::set<std::string> handled_paths;
    double total_mb_deleted = 0.0;
    std::string biggest_sources;
    int quarantined_count = 0;
    int soft_quarantined_count = 0;
    int hard_quarantined_count = 0;
    double trust_now = 100.0;
    bool local_container_stopped = false;

    if (disk_over || disk_spike || disk_hard) biggest_sources = get_top_disk_sources(path);

    if (need_action && (disk_over || disk_spike || disk_hard)) {
        double reclaim_target_mb = 256.0;
        if (disk_gb > disk_over_limit_gb) {
            double over_limit_mb = (disk_gb - disk_over_limit_gb) * 1024.0;
            if (over_limit_mb > reclaim_target_mb) reclaim_target_mb = over_limit_mb;
        }
        if (spike_disk > 0.0) {
            double spike_mb = spike_disk * 1024.0;
            if (spike_mb > reclaim_target_mb) reclaim_target_mb = spike_mb;
        }

        double remaining_target_mb = reclaim_target_mb;
        double reclaimed_total_mb = 0.0;
        int passes = 0;
        double previous_disk_gb = disk_gb;
        while (passes < MAX_RECLAIM_PASSES && remaining_target_mb > 8.0) {
            double reclaimed_mb = 0.0;
            std::vector<FileInfo> reclaimed_entries = reclaim_largest_entries(path, remaining_target_mb, reclaimed_mb, 12);
            if (!reclaimed_entries.empty()) {
                deleted.insert(deleted.end(), reclaimed_entries.begin(), reclaimed_entries.end());
                total_mb_deleted += reclaimed_mb;
                reclaimed_total_mb += reclaimed_mb;
            }

            disk_gb = get_folder_size_gb(path);
            apparent_disk_gb = get_folder_apparent_size_gb(path);
            double progress_mb = (previous_disk_gb - disk_gb) * 1024.0;
            if (progress_mb < 1.0) {
                unsigned long long now_free = get_host_free_bytes(path);
                double emergency_mb = emergency_delete_largest_files(
                    path,
                    now_free + static_cast<unsigned long long>(remaining_target_mb * 1024.0 * 1024.0)
                );
                if (emergency_mb > 0.0) {
                    reclaimed_total_mb += emergency_mb;
                    total_mb_deleted += emergency_mb;
                    disk_gb = get_folder_size_gb(path);
                    apparent_disk_gb = get_folder_apparent_size_gb(path);
                } else {
                    // If files are still held open, deletion may not release space
                    // until the container process exits. Stop once, then retry.
                    if (!local_container_stopped) {
                        local_container_stopped = stop_container_by_uuid(uuid);
                        if (local_container_stopped) {
                            reason += " | container_stopped=1";
                            std::this_thread::sleep_for(std::chrono::milliseconds(200));
                            disk_gb = get_folder_size_gb(path);
                            apparent_disk_gb = get_folder_apparent_size_gb(path);
                            previous_disk_gb = disk_gb;
                            passes++;
                            continue;
                        }
                    }
                    break;
                }
            }

            previous_disk_gb = disk_gb;
            if (disk_gb <= std::max(max_disk_gb, POST_ACTION_TARGET_FLOOR_GB)) break;
            remaining_target_mb = std::max(0.0, remaining_target_mb - std::max(progress_mb, reclaimed_mb));
            passes++;
        }

        if (reclaimed_total_mb > 0.0) {
            biggest_sources = get_top_disk_sources(path);
            reason += " | reclaimed=" + std::to_string((int)std::llround(reclaimed_total_mb)) + "MB";
        }
    }

    if (hard_delete_and_stop && need_action) {
        local_container_stopped = stop_container_by_uuid(uuid);
        double wiped_mb = wipe_server_volume(path);
        if (wiped_mb > 0.0) {
            total_mb_deleted += wiped_mb;
            reason += " | hard_wipe=" + std::to_string((int)std::llround(wiped_mb)) + "MB";
        }
        if (local_container_stopped) {
            reason += " | container_stopped=1";
        }

        disk_gb = get_folder_size_gb(path);
        apparent_disk_gb = get_folder_apparent_size_gb(path);
        if (disk_gb > POST_ACTION_TARGET_FLOOR_GB) {
            for (int pass = 0; pass < MAX_RECLAIM_PASSES && disk_gb > POST_ACTION_TARGET_FLOOR_GB; ++pass) {
                double reclaimed_mb = 0.0;
                std::vector<FileInfo> reclaimed_entries = reclaim_largest_entries(
                    path,
                    disk_gb * 1024.0,
                    reclaimed_mb,
                    20
                );
                if (!reclaimed_entries.empty()) {
                    deleted.insert(deleted.end(), reclaimed_entries.begin(), reclaimed_entries.end());
                    total_mb_deleted += reclaimed_mb;
                } else {
                    unsigned long long now_free = get_host_free_bytes(path);
                    double emergency_mb = emergency_delete_largest_files(
                        path,
                        now_free + (256ULL * 1024ULL * 1024ULL)
                    );
                    if (emergency_mb <= 0.0) break;
                    total_mb_deleted += emergency_mb;
                }
                disk_gb = get_folder_size_gb(path);
                apparent_disk_gb = get_folder_apparent_size_gb(path);
            }
            reason += " | residual=" + std::to_string((int)std::llround(disk_gb * 1024.0)) + "MB";
        }
        apparent_spike_disk = apparent_disk_gb - prev_apparent_disk;
        biggest_sources = get_top_disk_sources(path);
    } else {
        for (auto& f : files) {
            if (!handled_paths.insert(f.path).second) continue;

            double mb = f.size / (1024.0 * 1024.0);
            FileInfo original_file = f;
            bool file_soft_quarantine = is_soft_quarantine_reason(f.suspicion_reason);
            if (file_soft_quarantine) {
                logger.warn("🟡 Soft signal only (no quarantine): " + f.path +
                            " (" + f.suspicion_reason + ")");
                continue;
            }
            std::string hash = get_file_hash(f.path);
            std::string quarantined_path;
            bool quarantined = quarantine_file(uuid, f, quarantined_path);
            if (!quarantined) {
                if (file_soft_quarantine) {
                    logger.warn("🟡 Soft-quarantine skipped delete for: " + f.path +
                                " (" + f.suspicion_reason + ")");
                } else {
                    delete_file(f.path, f.suspicion_reason.empty() ? "UNKNOWN" : f.suspicion_reason);
                    total_mb_deleted += mb;
                }
            } else {
                quarantined_count++;
                if (file_soft_quarantine) soft_quarantined_count++;
                else hard_quarantined_count++;
                f.path = quarantined_path;
            }
            if (hash.empty()) hash = "nohash";
            f.hash = hash;
            deleted.push_back(f);

            if (info.id > 0) {
                db.log_illegal_file(
                    hash,
                    f.name,
                    f.path,
                    info.uuid,
                    info.owner_id,
                    f.suspicion_reason,
                    (long long)f.size
                );
            }

            if (quarantined) {
                auto related = find_related_files_for_quarantine(path, original_file);
                for (auto& rel : related) {
                    if (!handled_paths.insert(rel.path).second) continue;

                    std::string rel_hash = get_file_hash(rel.path);
                    std::string rel_quarantined_path;
                    bool rel_soft_quarantine = file_soft_quarantine || is_soft_quarantine_reason(rel.suspicion_reason);
                    if (rel_soft_quarantine) {
                        logger.warn("🟡 Soft signal only for related file (no quarantine): " + rel.path +
                                    " (" + rel.suspicion_reason + ")");
                        continue;
                    }
                    bool rel_quarantined = quarantine_file(uuid, rel, rel_quarantined_path);
                    if (!rel_quarantined) {
                        delete_file(rel.path, rel.suspicion_reason);
                        total_mb_deleted += rel.size / (1024.0 * 1024.0);
                    } else {
                        quarantined_count++;
                        if (rel_soft_quarantine) soft_quarantined_count++;
                        else hard_quarantined_count++;
                        rel.path = rel_quarantined_path;
                    }

                    if (rel_hash.empty()) rel_hash = "nohash";
                    rel.hash = rel_hash;
                    deleted.push_back(rel);

                    if (info.id > 0) {
                        db.log_illegal_file(
                            rel_hash,
                            rel.name,
                            rel.path,
                            info.uuid,
                            info.owner_id,
                            rel.suspicion_reason,
                            (long long)rel.size
                        );
                    }
                }
            }
        }
    }

    {
        std::lock_guard<std::mutex> lock(cache_mutex);
        trust_now = local_trust_score.count(uuid) ? local_trust_score[uuid] : 100.0;
    }
    bool soft_only_quarantine = quarantined_count > 0 && hard_quarantined_count == 0;
    if (quarantined_count > 0) {
        if (!soft_only_quarantine) {
        local_container_stopped = stop_container_by_uuid(uuid);
        }
        reason += " | quarantine=" + std::to_string(quarantined_count) + " trust=" + std::to_string((int)trust_now);
        if (soft_quarantined_count > 0) reason += " | soft=" + std::to_string(soft_quarantined_count);
        if (hard_quarantined_count > 0) reason += " | hard=" + std::to_string(hard_quarantined_count);
        if (local_container_stopped) reason += " | container_stopped=1";
    }

    if (need_action) {
        bool suspended = false;
        if (info.id > 0 && auto_suspend) suspended = db.suspend_server(info.id);
        bool hard_enforced = hard_delete_and_stop || (hard_quarantined_count > 0) || disk_hard || (disk_flood_filename_hits > 0);
        std::string action_label;
        if (hard_delete_and_stop) {
            action_label = suspended ? "suspended+hard_wipe_stop"
                : (local_container_stopped ? "hard_wipe_stop" : "hard_wipe");
        } else {
            action_label = suspended ? "suspended"
                : (local_container_stopped ? "container_stopped"
                : (hard_enforced ? "hard_quarantine" : "observe_only"));
        }

        {
            std::lock_guard<std::mutex> lock(cache_mutex);
            cache_penuh.insert(uuid);
            cache_last_action[uuid] = now;
            cache_consecutive_hit[uuid] = 0;
        }

        if (info.id > 0) {
            int severity = severity_from_metrics(disk_gb, disk_over_limit_gb, spike_disk, tcp_conns);
            if (trust_now <= 40.0 && auto_suspend) suspended = db.suspend_server(info.id);
            db.log_user_violation(
                info.owner_id, info.username, info.id, info.uuid, info.name,
                violation_type_from_reason(reason), reason, "",
                0, disk_gb, (int)deleted.size(),
                action_label, severity
            );
            db.bump_daily_stats(suspended ? 1 : 0, (int)deleted.size(), 0);
        }

        std::string alert_signature = build_alert_signature(reason, deleted, total_mb_deleted, tcp_conns, reason);
        if (should_send_alert(uuid, alert_signature, now)) {
            bot.send_report_message(rakit_laporan_server(
                reason, disk_gb, spike_disk, apparent_disk_gb, apparent_spike_disk,
                total_mb_deleted, info, deleted, tcp_conns, biggest_sources, action_label
            ));
        } else {
            logger.info("🔕 Suppressed duplicate server alert for " + uuid + " type=" + reason);
        }
    } else {
        if (disk_over) {
            std::lock_guard<std::mutex> lock(cache_mutex);
            cache_penuh.insert(uuid);
        } else {
            std::lock_guard<std::mutex> lock(cache_mutex);
            cache_penuh.erase(uuid);
        }
    }

    if ((total_mb_deleted > 0 || quarantined_count > 0) && !need_action && !deleted.empty()) {
        bool suspended = false;
        if (info.id > 0 && auto_suspend && hard_quarantined_count > 0) {
            suspended = db.suspend_server(info.id);
        }

        if (info.id > 0) {
            db.log_user_violation(
                info.owner_id, info.username, info.id, info.uuid, info.name,
                "illegal_file", "Suspicious files cleaned", deleted.front().name,
                (long long)deleted.front().size, disk_gb, (int)deleted.size(),
                suspended ? "suspended" : (local_container_stopped ? "container_stopped" : (soft_only_quarantine ? "soft_quarantine" : "file_delete")), 4
            );
            db.bump_daily_stats(suspended ? 1 : 0, (int)deleted.size(), 0);
        }

        std::string file_alert_type = contains_cloud_metadata_abuse(deleted)
            ? "CLOUD METADATA / CLOUD-INIT ABUSE"
            : "FILES CLEANED";
        std::string alert_signature = build_alert_signature(file_alert_type, deleted, total_mb_deleted, tcp_conns, file_alert_type);
        if (should_send_alert(uuid, alert_signature, now)) {
            std::string action_taken = suspended ? "suspended"
                : (local_container_stopped ? "container_stopped" : (soft_only_quarantine ? "soft_quarantine" : "file_delete"));
            bot.send_report_message(rakit_laporan_server(
                file_alert_type, disk_gb, 0, apparent_disk_gb, 0,
                total_mb_deleted, info, deleted, tcp_conns, biggest_sources, action_taken
            ));
        } else {
            logger.info("🔕 Suppressed duplicate file-cleaned alert for " + uuid + " type=" + file_alert_type);
        }
    }
}

void DiskProtector::scan_all() {
    release_expired_quarantine();
    unsigned long long host_free_bytes = get_host_free_bytes(volumes_path);
    DIR* dir = opendir(volumes_path.c_str());
    if (!dir) {
        logger.error("❌ Cannot open " + volumes_path);
        return;
    }

    std::set<std::string> uuid_set;
    struct dirent* entry;
    while ((entry = readdir(dir)) != nullptr) {
        if (strlen(entry->d_name) == 36 && is_valid_uuid(entry->d_name)) {
            std::string uuid = entry->d_name;
            std::string p = volumes_path + "/" + uuid;
            struct stat st;
            if (stat(p.c_str(), &st) == 0 && S_ISDIR(st.st_mode)) uuid_set.insert(uuid);
        }
    }
    closedir(dir);

    std::vector<std::string> db_uuids = db.get_all_server_uuids();
    for (const auto& uuid : db_uuids) {
        if (!uuid.empty() && is_valid_uuid(uuid)) uuid_set.insert(uuid);
    }

    std::vector<std::string> uuids(uuid_set.begin(), uuid_set.end());

    if (host_free_bytes > 0 && host_free_bytes <= HOST_DISK_CRITICAL_FREE_BYTES) {
        std::string largest_uuid;
        double largest_gb = -1.0;
        for (const auto& uuid : uuids) {
            double usage = get_folder_size_gb(volumes_path + "/" + uuid);
            if (usage > largest_gb) {
                largest_gb = usage;
                largest_uuid = uuid;
            }
        }

        if (!largest_uuid.empty()) {
            logger.warn("⚠️ Host disk critical, prioritizing largest server " + largest_uuid +
                        " (" + std::to_string(largest_gb) + "GB)");
            if (host_free_bytes <= HOST_DISK_EMERGENCY_FREE_BYTES) {
                std::string largest_path = volumes_path + "/" + largest_uuid;
                ServerInfo info = db.get_server_info(largest_uuid);
                bool container_stopped = stop_container_by_uuid(largest_uuid);
                bool suspended = false;
                if (info.id > 0) suspended = db.suspend_server(info.id);
                double freed_mb = 0.0;
                std::vector<FileInfo> reclaimed_entries = reclaim_largest_entries(
                    largest_path,
                    (HOST_DISK_CRITICAL_FREE_BYTES - host_free_bytes) / (1024.0 * 1024.0),
                    freed_mb,
                    5
                );
                if (reclaimed_entries.empty()) {
                    freed_mb = emergency_delete_largest_files(largest_path, HOST_DISK_CRITICAL_FREE_BYTES);
                }
                logger.warn("⚠️ Emergency disk reclaim on " + largest_uuid +
                            " freed " + std::to_string((int)freed_mb) + "MB");
                if (info.id > 0) {
                    db.log_user_violation(
                        info.owner_id, info.username, info.id, info.uuid, info.name,
                        "disk_over", "Emergency host disk reclaim", "",
                        0, largest_gb, 0,
                        suspended ? "suspend+emergency_reclaim" :
                            (container_stopped ? "container_stopped+emergency_reclaim" : "emergency_reclaim"), 10
                    );
                    db.bump_daily_stats(suspended ? 1 : 0, 0, 0);
                }
            }
            check_server(largest_uuid);
        }
    }

    for (const auto& uuid : uuids) check_server(uuid);
}

void DiskProtector::run_loop() {
    running = true;
    while (running) {
        try {
            time_t start = time(nullptr);
            scan_all();

            int elapsed = (int)(time(nullptr) - start);
            int sleep_time = check_interval - elapsed;
            std::this_thread::sleep_for(std::chrono::seconds(std::max(1, sleep_time)));
        } catch (...) {
            std::this_thread::sleep_for(std::chrono::seconds(5));
        }
    }
}

void DiskProtector::start() {
    if (running) return;
    running = true;
    monitor_thread = std::thread(&DiskProtector::run_loop, this);
}

void DiskProtector::stop() {
    if (!running) return;
    running = false;
    if (monitor_thread.joinable()) monitor_thread.join();
}

void DiskProtector::scan_now() { scan_all(); }

void DiskProtector::clean_now() {
    DIR* dir = opendir(volumes_path.c_str());
    if (!dir) return;

    struct dirent* entry;
    while ((entry = readdir(dir)) != nullptr) {
        if (strlen(entry->d_name) == 36 && is_valid_uuid(entry->d_name)) {
            std::string path = volumes_path + "/" + entry->d_name;
            std::string cmd = "find \"" + path + "\" -mindepth 1 -maxdepth 1 -exec rm -rf {} + 2>/dev/null";
            system(cmd.c_str());
        }
    }
    closedir(dir);
}

int DiskProtector::get_total_servers() {
    int count = 0;
    DIR* dir = opendir(volumes_path.c_str());
    if (!dir) return 0;

    struct dirent* entry;
    while ((entry = readdir(dir)) != nullptr) {
        if (strlen(entry->d_name) == 36 && is_valid_uuid(entry->d_name)) count++;
    }
    closedir(dir);
    return count;
}

int DiskProtector::get_suspended_count() {
    return db.count_suspended_servers();
}

std::vector<ServerInfo> DiskProtector::get_all_servers() {
    std::vector<ServerInfo> servers;
    DIR* dir = opendir(volumes_path.c_str());
    if (!dir) return servers;

    struct dirent* entry;
    while ((entry = readdir(dir)) != nullptr) {
        if (strlen(entry->d_name) == 36 && is_valid_uuid(entry->d_name)) {
            std::string uuid = entry->d_name;
            ServerInfo info = db.get_server_info(uuid);
            servers.push_back(info);
        }
    }
    closedir(dir);
    return servers;
}

std::map<std::string, double> DiskProtector::get_disk_usage() {
    std::map<std::string, double> usage;
    DIR* dir = opendir(volumes_path.c_str());
    if (!dir) return usage;

    struct dirent* entry;
    while ((entry = readdir(dir)) != nullptr) {
        if (strlen(entry->d_name) == 36 && is_valid_uuid(entry->d_name)) {
            std::string uuid = entry->d_name;
            usage[uuid] = get_folder_size_gb(volumes_path + "/" + uuid);
        }
    }
    closedir(dir);
    return usage;
}

DiskProtector disk;
