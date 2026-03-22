#include <arpa/inet.h>
#include <fcntl.h>
#include <netinet/in.h>
#include <openssl/evp.h>
#include <openssl/hmac.h>
#include <openssl/sha.h>
#include <sys/socket.h>
#include <sys/types.h>
#include <unistd.h>

#include <algorithm>
#include <atomic>
#include <chrono>
#include <cctype>
#include <cerrno>
#include <cmath>
#include <csignal>
#include <cstring>
#include <fstream>
#include <iomanip>
#include <iostream>
#include <map>
#include <mutex>
#include <nlohmann/json.hpp>
#include <random>
#include <sstream>
#include <string>
#include <thread>
#include <vector>

using json = nlohmann::json;

struct Settings {
    bool enabled = true;
    std::string bind = "127.0.0.1";
    int port = 18444;
    int ttl = 1800;
    std::string cookie_name = "pp_clearance";
    std::string secret = "dannhexzoprotect";
};

struct NonceRec {
    std::string ans;
    std::string ip;
    std::string ua;
    std::string answer_key;
    std::string behavior_key;
    std::string connection_key;
    std::string pattern_key;
    std::string pattern_seq;
    bool math_verified = false;
    int math_fail_count = 0;
    int min_connection_ms = 5000;
    std::time_t exp = 0;
    std::time_t issued_at = 0;
};
struct SessionRec {
    std::string sid;
    std::string ua;
    std::time_t exp = 0;
};

static std::atomic<bool> g_running(true);
static std::mutex g_nonce_mu;
static std::map<std::string, NonceRec> g_nonce_map;
static std::mutex g_session_mu;
static std::map<std::string, SessionRec> g_ip_session_map;
static std::mutex g_cfg_mu;
static Settings g_cached_cfg;
static std::time_t g_cached_cfg_at = 0;
static std::string g_cfg_path = "/pteroprotect/config.json";

static inline std::string trim(const std::string& in) {
    std::size_t b = 0;
    while (b < in.size() && std::isspace(static_cast<unsigned char>(in[b]))) b++;
    std::size_t e = in.size();
    while (e > b && std::isspace(static_cast<unsigned char>(in[e - 1]))) e--;
    return in.substr(b, e - b);
}

static std::string to_lower(std::string s) {
    std::transform(s.begin(), s.end(), s.begin(), [](unsigned char c) { return static_cast<char>(std::tolower(c)); });
    return s;
}

static std::string normalize_numeric_answer(const std::string& input) {
    std::string s = trim(input);
    std::string out;
    out.reserve(s.size());
    for (std::size_t i = 0; i < s.size(); ++i) {
        unsigned char c = static_cast<unsigned char>(s[i]);
        if ((c == '+' || c == '-') && out.empty()) {
            out.push_back(static_cast<char>(c));
            continue;
        }
        if (std::isdigit(c)) {
            out.push_back(static_cast<char>(c));
            continue;
        }
        if (c == ',' || c == '.' || c == '_' || c == '\'' || std::isspace(c)) {
            continue;
        }
        return s;
    }
    if (out == "+" || out == "-") return s;
    return out.empty() ? s : out;
}

static bool parse_bool(const json& j, bool fallback) {
    if (j.is_boolean()) return j.get<bool>();
    if (j.is_number_integer()) return j.get<int>() != 0;
    if (j.is_string()) {
        std::string v = to_lower(trim(j.get<std::string>()));
        return !(v == "0" || v == "false" || v == "off" || v == "no");
    }
    return fallback;
}

static std::string base64url_encode(const unsigned char* data, std::size_t len) {
    if (len == 0) return "";
    std::string out;
    out.resize(4 * ((len + 2) / 3));
    int written = EVP_EncodeBlock(reinterpret_cast<unsigned char*>(&out[0]), data, static_cast<int>(len));
    if (written < 0) return "";
    out.resize(static_cast<std::size_t>(written));
    for (char& c : out) {
        if (c == '+') c = '-';
        else if (c == '/') c = '_';
    }
    while (!out.empty() && out.back() == '=') out.pop_back();
    return out;
}

static std::string base64url_encode(const std::string& s) {
    return base64url_encode(reinterpret_cast<const unsigned char*>(s.data()), s.size());
}

static bool base64url_decode(const std::string& in, std::string& out) {
    std::string b64 = in;
    for (char& c : b64) {
        if (c == '-') c = '+';
        else if (c == '_') c = '/';
    }
    while ((b64.size() % 4) != 0) b64.push_back('=');
    std::vector<unsigned char> buf(b64.size() + 4, 0);
    int n = EVP_DecodeBlock(buf.data(), reinterpret_cast<const unsigned char*>(b64.data()), static_cast<int>(b64.size()));
    if (n < 0) return false;
    int pad = 0;
    if (!b64.empty() && b64[b64.size() - 1] == '=') pad++;
    if (b64.size() > 1 && b64[b64.size() - 2] == '=') pad++;
    n -= pad;
    if (n < 0) n = 0;
    out.assign(reinterpret_cast<char*>(buf.data()), static_cast<std::size_t>(n));
    return true;
}

static std::string hmac_sha256_b64url(const std::string& key, const std::string& payload) {
    unsigned char digest[EVP_MAX_MD_SIZE];
    unsigned int digest_len = 0;
    HMAC(EVP_sha256(), key.data(), static_cast<int>(key.size()),
         reinterpret_cast<const unsigned char*>(payload.data()),
         payload.size(), digest, &digest_len);
    return base64url_encode(digest, digest_len);
}

static std::string sha256_hex_24(const std::string& text) {
    unsigned char out[SHA256_DIGEST_LENGTH];
    SHA256(reinterpret_cast<const unsigned char*>(text.data()), text.size(), out);
    std::ostringstream oss;
    for (int i = 0; i < SHA256_DIGEST_LENGTH; ++i) {
        oss << std::hex << std::setw(2) << std::setfill('0') << static_cast<int>(out[i]);
    }
    std::string hex = oss.str();
    return hex.substr(0, 24);
}

static std::string json_get_string(const json& root, const std::string& key, const std::string& fallback) {
    if (!root.is_object()) return fallback;
    auto it = root.find(key);
    if (it == root.end()) return fallback;
    if (it->is_string()) return it->get<std::string>();
    if (it->is_number_integer()) return std::to_string(it->get<int>());
    if (it->is_boolean()) return it->get<bool>() ? "true" : "false";
    return fallback;
}

static int json_get_int(const json& root, const std::string& key, int fallback) {
    if (!root.is_object()) return fallback;
    auto it = root.find(key);
    if (it == root.end()) return fallback;
    if (it->is_number_integer()) return it->get<int>();
    if (it->is_string()) {
        try {
            return std::stoi(it->get<std::string>());
        } catch (...) {
            return fallback;
        }
    }
    return fallback;
}

static Settings load_settings() {
    std::lock_guard<std::mutex> lock(g_cfg_mu);
    std::time_t now = std::time(nullptr);
    if (g_cached_cfg_at != 0 && now - g_cached_cfg_at <= 2) {
        return g_cached_cfg;
    }

    Settings s;
    std::ifstream f(g_cfg_path);
    if (f.is_open()) {
        try {
            json j;
            f >> j;
            json net = j.value("network", json::object());
            s.enabled = parse_bool(net.value("waf_challenge_enabled", json(true)), true);
            s.bind = json_get_string(net, "waf_challenge_bind", "127.0.0.1");
            s.port = std::max(1, std::min(65535, json_get_int(net, "waf_challenge_port", 18444)));
            s.ttl = std::max(60, std::min(86400, json_get_int(net, "waf_challenge_ttl_sec", 1800)));
            s.cookie_name = trim(json_get_string(net, "waf_challenge_cookie_name", "pp_clearance"));
            if (s.cookie_name.empty()) s.cookie_name = "pp_clearance";
            s.secret = trim(json_get_string(net, "waf_challenge_secret", ""));
            if (s.secret.empty()) {
                s.secret = trim(json_get_string(net, "unblock_portal_token", "dannhexzoprotect"));
            }
            if (s.secret.empty()) s.secret = "dannhexzoprotect";
        } catch (...) {
            // keep defaults
        }
    }
    g_cached_cfg = s;
    g_cached_cfg_at = now;
    return s;
}

struct HttpRequest {
    std::string method;
    std::string target;
    std::string path;
    std::string query;
    std::map<std::string, std::string> headers;
    std::string body;
    std::string remote_ip;
};

static std::map<std::string, std::string> parse_query(const std::string& q) {
    std::map<std::string, std::string> out;
    std::size_t i = 0;
    while (i < q.size()) {
        std::size_t amp = q.find('&', i);
        if (amp == std::string::npos) amp = q.size();
        std::string part = q.substr(i, amp - i);
        std::size_t eq = part.find('=');
        std::string k = (eq == std::string::npos) ? part : part.substr(0, eq);
        std::string v = (eq == std::string::npos) ? "" : part.substr(eq + 1);
        out[k] = v;
        i = amp + 1;
    }
    return out;
}

static std::string random_nonce() {
    std::random_device rd;
    std::mt19937_64 gen(rd());
    std::uniform_int_distribution<int> dis(0, 255);
    unsigned char raw[18];
    for (unsigned char& c : raw) c = static_cast<unsigned char>(dis(gen));
    return base64url_encode(raw, sizeof(raw));
}

static std::string random_token(int len = 8) {
    static const char k[] = "abcdefghijklmnopqrstuvwxyz0123456789";
    std::random_device rd;
    std::mt19937 gen(rd());
    std::uniform_int_distribution<int> dis(0, static_cast<int>(sizeof(k) - 2));
    std::string out;
    out.reserve(static_cast<std::size_t>(len));
    for (int i = 0; i < len; ++i) out.push_back(k[dis(gen)]);
    return out;
}

static std::string first_xff_ip(const std::string& xff) {
    std::size_t pos = xff.find(',');
    if (pos == std::string::npos) return trim(xff);
    return trim(xff.substr(0, pos));
}

static std::string read_cookie(const std::string& cookie_hdr, const std::string& name) {
    std::size_t i = 0;
    while (i < cookie_hdr.size()) {
        std::size_t sem = cookie_hdr.find(';', i);
        if (sem == std::string::npos) sem = cookie_hdr.size();
        std::string p = trim(cookie_hdr.substr(i, sem - i));
        std::size_t eq = p.find('=');
        if (eq != std::string::npos) {
            std::string k = trim(p.substr(0, eq));
            if (k == name) return p.substr(eq + 1);
        }
        i = sem + 1;
    }
    return "";
}

static void cleanup_nonce_map() {
    std::time_t now = std::time(nullptr);
    std::lock_guard<std::mutex> lock(g_nonce_mu);
    for (auto it = g_nonce_map.begin(); it != g_nonce_map.end();) {
        if (it->second.exp <= now) it = g_nonce_map.erase(it);
        else ++it;
    }
}

static void cleanup_session_map() {
    std::time_t now = std::time(nullptr);
    std::lock_guard<std::mutex> lock(g_session_mu);
    for (auto it = g_ip_session_map.begin(); it != g_ip_session_map.end();) {
        if (it->second.exp <= now) it = g_ip_session_map.erase(it);
        else ++it;
    }
}

static int json_int_or(const json& j, const std::string& key, int fallback = 0) {
    if (!j.is_object()) return fallback;
    auto it = j.find(key);
    if (it == j.end()) return fallback;
    if (it->is_number_integer()) return it->get<int>();
    if (it->is_number_float()) return static_cast<int>(it->get<double>());
    if (it->is_string()) {
        try { return std::stoi(it->get<std::string>()); } catch (...) { return fallback; }
    }
    return fallback;
}

static bool behavior_pass(const json& behavior, const NonceRec& rec) {
    // Simple anti-bot heuristics:
    // require some interaction time + either pointer gesture or touch/scroll/key activity.
    const int duration_ms = std::max(0, json_int_or(behavior, "duration_ms", 0));
    const int pointer_moves = std::max(0, json_int_or(behavior, "pointer_moves", 0));
    const int pointer_distance = std::max(0, json_int_or(behavior, "pointer_distance", 0));
    const int pointer_dir_changes = std::max(0, json_int_or(behavior, "pointer_dir_changes", 0));
    const int touch_moves = std::max(0, json_int_or(behavior, "touch_moves", 0));
    const int scroll_count = std::max(0, json_int_or(behavior, "scroll_count", 0));
    const int key_count = std::max(0, json_int_or(behavior, "key_count", 0));

    const std::time_t now = std::time(nullptr);
    const int server_age_ms = rec.issued_at > 0 ? static_cast<int>(std::max<std::time_t>(0, now - rec.issued_at) * 1000) : 0;
    const int effective_ms = std::max(duration_ms, server_age_ms);
    const int min_ms = std::max(1000, rec.min_connection_ms);
    if (effective_ms < min_ms) return false;

    const bool pointer_ok = (pointer_moves >= 4 && pointer_distance >= 120 && pointer_dir_changes >= 1);
    const bool touch_ok = (touch_moves >= 2);
    const bool fallback_ok = (scroll_count >= 1 || key_count >= 1);
    return pointer_ok || touch_ok || fallback_ok;
}

static bool ua_declared_browser(const std::string& ua) {
    if (ua.size() < 20 || ua.size() > 700) return false;
    const std::string low = to_lower(ua);
    static const std::vector<std::string> blocked = {
        "curl/", "wget/", "python-requests", "go-http-client", "okhttp", "libwww-perl",
        "java/", "aiohttp", "httpclient", "axios/", "node-fetch", "scrapy", "postmanruntime"
    };
    for (const auto& bad : blocked) {
        if (low.find(bad) != std::string::npos) return false;
    }
    if (low.find("mozilla/5.0") == std::string::npos) return false;
    static const std::vector<std::string> browsers = {
        "chrome/", "edg/", "firefox/", "safari/", "opr/", "samsungbrowser/"
    };
    for (const auto& tok : browsers) {
        if (low.find(tok) != std::string::npos) return true;
    }
    return false;
}

static bool connection_pass(const json& conn, const std::string& ua) {
    if (!conn.is_object()) return false;
    const bool webdriver = conn.value("webdriver", true);
    if (webdriver) return false;

    const int ua_len = std::max(0, json_int_or(conn, "ua_len", 0));
    const int lang_len = std::max(0, json_int_or(conn, "lang_len", 0));
    const int tz_len = std::max(0, json_int_or(conn, "tz_len", 0));
    const int max_touch_points = std::max(0, json_int_or(conn, "max_touch_points", 0));
    const int hc = std::max(0, json_int_or(conn, "hardware_concurrency", 0));
    const int sw = std::max(0, json_int_or(conn, "screen_w", 0));
    const int sh = std::max(0, json_int_or(conn, "screen_h", 0));
    const int cd = std::max(0, json_int_or(conn, "color_depth", 0));

    if (ua_len < 20 || ua_len > 700) return false;
    if (std::abs(static_cast<int>(ua.size()) - ua_len) > 20) return false;
    if (lang_len < 2 || lang_len > 64) return false;
    if (tz_len < 1 || tz_len > 64) return false;
    if (max_touch_points > 20) return false;
    if (hc > 0 && (hc < 1 || hc > 256)) return false;
    if ((sw > 0 && sw < 120) || (sh > 0 && sh < 120)) return false;
    if (cd > 0 && (cd < 8 || cd > 64)) return false;
    return true;
}

static std::string join_ints_dash(const std::vector<int>& values) {
    std::ostringstream oss;
    for (std::size_t i = 0; i < values.size(); ++i) {
        if (i > 0) oss << "-";
        oss << values[i];
    }
    return oss.str();
}

static std::vector<int> split_ints_dash(const std::string& text) {
    std::vector<int> out;
    std::string cur;
    for (char c : text) {
        if (c == '-') {
            if (!cur.empty()) {
                try { out.push_back(std::stoi(cur)); } catch (...) {}
            }
            cur.clear();
        } else {
            cur.push_back(c);
        }
    }
    if (!cur.empty()) {
        try { out.push_back(std::stoi(cur)); } catch (...) {}
    }
    return out;
}

static int transform_grid_node(int id, int rot, bool flip) {
    int r = id / 3;
    int c = id % 3;
    if (flip) c = 2 - c;
    for (int i = 0; i < rot; ++i) {
        int nr = c;
        int nc = 2 - r;
        r = nr;
        c = nc;
    }
    return r * 3 + c;
}

static std::vector<int> generate_pattern_nodes(std::mt19937& gen) {
    static const std::vector<std::vector<int>> templates = {
        {0, 4, 8, 2, 4, 6},
        {0, 3, 6, 4, 2, 5, 8},
        {0, 1, 2, 4, 6, 7, 8},
        {6, 3, 0, 4, 8, 5, 2},
        {0, 3, 4, 5, 8}
    };
    std::uniform_int_distribution<int> tdis(0, static_cast<int>(templates.size() - 1));
    std::uniform_int_distribution<int> rdis(0, 3);
    std::uniform_int_distribution<int> fdis(0, 1);
    std::vector<int> out;
    const auto& base = templates[static_cast<std::size_t>(tdis(gen))];
    int rot = rdis(gen);
    bool flip = fdis(gen) == 1;
    for (int n : base) {
        int v = transform_grid_node(n, rot, flip);
        if (out.empty() || out.back() != v) out.push_back(v);
    }
    std::uniform_int_distribution<int> extra_dis(2, 5);
    std::uniform_int_distribution<int> node_dis(0, 8);
    int extra = extra_dis(gen);
    for (int i = 0; i < extra; ++i) {
        int cand = node_dis(gen);
        if (out.empty() || out.back() != cand) out.push_back(cand);
    }
    return out;
}

static bool pattern_pass(const json& pattern, const NonceRec& rec) {
    if (rec.pattern_seq.empty()) return false;
    if (!pattern.is_object()) return false;
    std::vector<int> expected = split_ints_dash(rec.pattern_seq);
    if (expected.empty()) return false;

    if (pattern.contains("clicked_nodes") && pattern["clicked_nodes"].is_array()) {
        const json& arr = pattern["clicked_nodes"];
        if (arr.size() < expected.size() || arr.size() > expected.size() + 12) return false;
        std::vector<int> got;
        got.reserve(arr.size());
        for (const auto& v : arr) {
            if (!v.is_number_integer()) return false;
            int n = v.get<int>();
            if (n < 1 || n > 9) return false;
            got.push_back(n - 1);
        }
        std::size_t i = 0;
        for (int v : got) {
            if (i < expected.size() && v == expected[i]) {
                i++;
                continue;
            }
            if (i > 0 && v == expected[i - 1]) continue;
            return false;
        }
        return i == expected.size();
    }

    if (!pattern.contains("trace") || !pattern["trace"].is_array()) return false;
    const json& trace = pattern["trace"];
    if (trace.size() < 10 || trace.size() > 256) return false;
    const int duration_ms = std::max(0, json_int_or(pattern, "duration_ms", 0));
    const int dir_changes = std::max(0, json_int_or(pattern, "dir_changes", 0));
    if (duration_ms < 900 || duration_ms > 60000) return false;
    if (dir_changes < 1) return false;

    static const double centers[9][2] = {
        {20, 20}, {50, 20}, {80, 20},
        {20, 50}, {50, 50}, {80, 50},
        {20, 80}, {50, 80}, {80, 80}
    };

    auto nearest_node = [&](double x, double y) -> int {
        int best = -1;
        double best_d2 = 1e9;
        for (int i = 0; i < 9; ++i) {
            double dx = x - centers[i][0];
            double dy = y - centers[i][1];
            double d2 = dx * dx + dy * dy;
            if (d2 < best_d2) {
                best_d2 = d2;
                best = i;
            }
        }
        return best_d2 <= (16.0 * 16.0) ? best : -1;
    };

    bool has_prev = false;
    double prev_x = 0.0, prev_y = 0.0;
    int prev_t = -1;
    double travel = 0.0;
    std::vector<int> visited;
    int last_node = -1;

    for (const auto& p : trace) {
        if (!p.is_array() || p.size() < 3) return false;
        if (!p[0].is_number() || !p[1].is_number() || !p[2].is_number_integer()) return false;
        double x = p[0].get<double>();
        double y = p[1].get<double>();
        int t = p[2].get<int>();
        if (x < 0.0 || x > 100.0 || y < 0.0 || y > 100.0 || t < 0) return false;
        if (prev_t >= 0 && t < prev_t) return false;
        prev_t = t;
        if (has_prev) {
            double dx = x - prev_x;
            double dy = y - prev_y;
            travel += std::sqrt(dx * dx + dy * dy);
        }
        prev_x = x;
        prev_y = y;
        has_prev = true;
        int node = nearest_node(x, y);
        if (node >= 0 && node != last_node) {
            visited.push_back(node);
            last_node = node;
        }
    }
    if (travel < 60.0) return false;
    if (visited.size() < expected.size()) return false;
    std::size_t i = 0;
    for (int v : visited) {
        if (i < expected.size() && v == expected[i]) {
            i++;
            continue;
        }
        if (i > 0 && v == expected[i - 1]) {
            continue; // tolerate duplicate hovering on current target node
        }
        return false;
    }
    return i == expected.size();
}

static std::string pattern_hint_text(const std::vector<int>& nodes) {
    std::ostringstream oss;
    oss << "Ikuti urutan angka: ";
    for (std::size_t i = 0; i < nodes.size(); ++i) {
        int n = nodes[i];
        if (n < 0 || n > 8) continue;
        if (i > 0) oss << " -> ";
        oss << (n + 1);
    }
    return oss.str();
}

static bool ptero_api_token_format_ok(const std::string& token) {
    std::string t = trim(token);
    if (t.size() < 24 || t.size() > 256) return false;
    std::string low = to_lower(t);
    const bool has_ptero_prefix =
        (low.rfind("ptla_", 0) == 0) ||
        (low.rfind("plta_", 0) == 0) ||
        (low.rfind("ptlc_", 0) == 0);
    if (!has_ptero_prefix) return false;
    for (char c : t) {
        const bool ok =
            std::isalnum(static_cast<unsigned char>(c)) ||
            c == '_' || c == '-';
        if (!ok) return false;
    }
    return true;
}

static bool daemon_bearer_token_format_ok(const std::string& token) {
    std::string t = trim(token);
    if (t.size() < 24 || t.size() > 400) return false;
    std::size_t dot = t.find('.');
    if (dot == std::string::npos || dot == 0 || dot + 1 >= t.size()) return false;
    if (t.find('.', dot + 1) != std::string::npos) return false;
    std::string left = t.substr(0, dot);
    std::string right = t.substr(dot + 1);
    if (left.size() < 6 || left.size() > 128) return false;
    if (right.size() < 12 || right.size() > 320) return false;
    for (char c : t) {
        if (c == '.') continue;
        const bool ok = std::isalnum(static_cast<unsigned char>(c)) || c == '_' || c == '-';
        if (!ok) return false;
    }
    return true;
}

static bool generic_bearer_token_format_ok(const std::string& token) {
    std::string t = trim(token);
    if (t.size() < 12 || t.size() > 600) return false;
    bool has_alnum = false;
    for (char c : t) {
        const bool ok =
            std::isalnum(static_cast<unsigned char>(c)) ||
            c == '_' || c == '-' || c == '.' || c == '~' ||
            c == '+' || c == '/' || c == '=';
        if (!ok) return false;
        if (std::isalnum(static_cast<unsigned char>(c))) has_alnum = true;
    }
    return has_alnum;
}

static bool is_loopback_ip(const std::string& ip) {
    std::string t = trim(ip);
    return t == "127.0.0.1" || t == "::1" || t == "::ffff:127.0.0.1";
}

static bool has_valid_auth_token_header(const HttpRequest& req) {
    // Allow panel->wings internal daemon polling from localhost even if token
    // format does not match strict API token patterns.
    auto ua_it = req.headers.find("user-agent");
    std::string ua = (ua_it != req.headers.end()) ? to_lower(ua_it->second) : "";
    if (is_loopback_ip(req.remote_ip) && ua.find("guzzlehttp/") != std::string::npos) {
        return true;
    }

    auto it = req.headers.find("authorization");
    if (it != req.headers.end()) {
        std::string v = trim(it->second);
        std::string low = to_lower(v);
        const std::string pre = "bearer ";
        if (low.size() > pre.size() && low.compare(0, pre.size(), pre) == 0) {
            std::string tok = trim(v.substr(pre.size()));
            if (ptero_api_token_format_ok(tok)) return true;
            if (daemon_bearer_token_format_ok(tok)) return true;
            if (generic_bearer_token_format_ok(tok)) return true;
        }
    }
    auto it2 = req.headers.find("x-api-key");
    if (it2 != req.headers.end()) {
        if (ptero_api_token_format_ok(trim(it2->second))) return true;
    }
    return false;
}

static std::string issue_token(const Settings& s, const std::string& ip, const std::string& ua_fp, const std::string& sid) {
    json p;
    p["ip"] = ip;
    p["ua"] = ua_fp;
    p["sid"] = sid;
    p["exp"] = static_cast<long long>(std::time(nullptr) + s.ttl);
    std::string payload = p.dump();
    std::string b = base64url_encode(payload);
    std::string sig = hmac_sha256_b64url(s.secret, payload);
    return b + "." + sig;
}

static bool verify_token(const Settings& s, const std::string& token, const std::string& ip, const std::string& ua_fp) {
    std::size_t dot = token.find('.');
    if (dot == std::string::npos) return false;
    std::string b = token.substr(0, dot);
    std::string sig = token.substr(dot + 1);
    std::string payload;
    if (!base64url_decode(b, payload)) return false;
    std::string expected = hmac_sha256_b64url(s.secret, payload);
    if (expected != sig) return false;
    try {
        json p = json::parse(payload);
        if (!p.is_object()) return false;
        long long exp = p.value("exp", 0LL);
        std::string sid = p.value("sid", std::string());
        if (exp < static_cast<long long>(std::time(nullptr))) return false;
        if (sid.empty()) return false;
        if (p.value("ip", std::string()) != ip) return false;
        if (p.value("ua", std::string()) != ua_fp) return false;
        {
            std::lock_guard<std::mutex> lock(g_session_mu);
            auto it = g_ip_session_map.find(ip);
            if (it == g_ip_session_map.end()) return false;
            if (it->second.sid != sid) return false;
            if (it->second.exp < std::time(nullptr)) return false;
        }
        return true;
    } catch (...) {
        return false;
    }
}

static void send_raw(int fd, const std::string& data) {
    const char* p = data.data();
    std::size_t left = data.size();
    while (left > 0) {
        ssize_t n = ::send(fd, p, left, 0);
        if (n <= 0) return;
        p += n;
        left -= static_cast<std::size_t>(n);
    }
}

static void send_response(int fd, int status, const std::string& status_text, const std::string& body,
                          const std::vector<std::pair<std::string, std::string>>& headers = {},
                          bool head_only = false) {
    std::ostringstream oss;
    oss << "HTTP/1.1 " << status << " " << status_text << "\r\n";
    oss << "Server: challenge_guard/1.0\r\n";
    oss << "Connection: close\r\n";
    oss << "Cache-Control: no-store\r\n";
    for (const auto& h : headers) {
        oss << h.first << ": " << h.second << "\r\n";
    }
    oss << "Content-Length: " << body.size() << "\r\n\r\n";
    if (!head_only) oss << body;
    send_raw(fd, oss.str());
}

static bool read_request(int fd, HttpRequest& req) {
    std::string raw;
    raw.reserve(8192);
    char buf[2048];
    int content_len = 0;
    bool header_done = false;
    std::size_t header_end = std::string::npos;

    while (raw.size() < 1024 * 1024) {
        ssize_t n = recv(fd, buf, sizeof(buf), 0);
        if (n <= 0) break;
        raw.append(buf, buf + n);
        if (!header_done) {
            header_end = raw.find("\r\n\r\n");
            if (header_end != std::string::npos) {
                header_done = true;
                std::string header_blob = raw.substr(0, header_end);
                std::istringstream hs(header_blob);
                std::string line;
                if (!std::getline(hs, line)) return false;
                if (!line.empty() && line.back() == '\r') line.pop_back();
                std::istringstream rl(line);
                std::string version;
                rl >> req.method >> req.target >> version;
                if (req.method.empty() || req.target.empty()) return false;
                while (std::getline(hs, line)) {
                    if (!line.empty() && line.back() == '\r') line.pop_back();
                    std::size_t c = line.find(':');
                    if (c == std::string::npos) continue;
                    std::string k = to_lower(trim(line.substr(0, c)));
                    std::string v = trim(line.substr(c + 1));
                    req.headers[k] = v;
                }
                auto it = req.headers.find("content-length");
                if (it != req.headers.end()) {
                    try { content_len = std::max(0, std::min(65536, std::stoi(it->second))); } catch (...) { content_len = 0; }
                }
                if (raw.size() >= header_end + 4 + static_cast<std::size_t>(content_len)) break;
            }
        } else {
            if (raw.size() >= header_end + 4 + static_cast<std::size_t>(content_len)) break;
        }
    }

    if (!header_done || header_end == std::string::npos) return false;
    std::size_t body_off = header_end + 4;
    if (raw.size() < body_off) return false;
    if (content_len > 0 && raw.size() >= body_off) {
        req.body = raw.substr(body_off, static_cast<std::size_t>(content_len));
    }

    std::size_t q = req.target.find('?');
    if (q == std::string::npos) req.path = req.target;
    else {
        req.path = req.target.substr(0, q);
        req.query = req.target.substr(q + 1);
    }
    if (req.path.empty()) req.path = "/";
    return true;
}

static void handle_client(int fd, std::string remote_ip) {
    HttpRequest req;
    req.remote_ip = std::move(remote_ip);
    if (!read_request(fd, req)) {
        send_response(fd, 400, "Bad Request", "{\"ok\":false}");
        close(fd);
        return;
    }

    bool head_only = (req.method == "HEAD");
    if (!(req.method == "GET" || req.method == "POST" || req.method == "HEAD")) {
        send_response(fd, 405, "Method Not Allowed", "{\"ok\":false}", {{"Content-Type", "application/json"}}, head_only);
        close(fd);
        return;
    }

    Settings s = load_settings();
    std::string xff = req.headers.count("x-forwarded-for") ? req.headers["x-forwarded-for"] : "";
    std::string ip = !xff.empty() ? first_xff_ip(xff) : req.remote_ip;
    std::string ua = req.headers.count("user-agent") ? req.headers["user-agent"] : "";
    std::string ua_fp = sha256_hex_24(ua);

    cleanup_nonce_map();
    cleanup_session_map();

    if (req.path == "/health") {
        std::string body = std::string("{\"ok\":true,\"enabled\":") + (s.enabled ? "true" : "false") + "}";
        send_response(fd, 200, "OK", body, {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
        close(fd);
        return;
    }

    if (req.path == "/check") {
        if (!s.enabled) {
            send_response(fd, 204, "No Content", "", {}, head_only);
            close(fd);
            return;
        }
        std::string cookie = req.headers.count("cookie") ? req.headers["cookie"] : "";
        std::string tok = read_cookie(cookie, s.cookie_name);
        if (!tok.empty() && verify_token(s, tok, ip, ua_fp)) {
            send_response(fd, 204, "No Content", "", {}, head_only);
        } else {
            send_response(fd, 401, "Unauthorized", "", {}, head_only);
        }
        close(fd);
        return;
    }

    if (req.path == "/check-token") {
        if (!s.enabled) {
            send_response(fd, 204, "No Content", "", {}, head_only);
            close(fd);
            return;
        }
        std::string cookie = req.headers.count("cookie") ? req.headers["cookie"] : "";
        std::string tok = read_cookie(cookie, s.cookie_name);
        std::map<std::string, std::string> q = parse_query(req.query);
        std::string wing_q_token = q.count("token") ? trim(q["token"]) : "";
        const bool wing_query_ok = generic_bearer_token_format_ok(wing_q_token);
        if ((!tok.empty() && verify_token(s, tok, ip, ua_fp)) || has_valid_auth_token_header(req) || wing_query_ok) {
            send_response(fd, 204, "No Content", "", {}, head_only);
        } else {
            send_response(fd, 401, "Unauthorized", "", {}, head_only);
        }
        close(fd);
        return;
    }

    if (req.path == "/new" && (req.method == "GET" || req.method == "HEAD")) {
        if (!s.enabled) {
            send_response(fd, 200, "OK", "{\"ok\":true,\"disabled\":true}", {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
            close(fd);
            return;
        }
        if (!ua_declared_browser(ua)) {
            send_response(fd, 401, "Unauthorized", "{\"ok\":false,\"error\":\"ua_invalid\"}", {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
            close(fd);
            return;
        }

        std::random_device rd;
        std::mt19937 gen(rd());
        std::uniform_int_distribution<int> num_a(12000, 98000);
        std::uniform_int_distribution<int> num_b(12000, 98000);
        std::uniform_int_distribution<int> num_c(1200, 9500);
        std::uniform_int_distribution<int> opdis(0, 1);
        std::uniform_int_distribution<int> delay_ms_dis(5000, 11000);
        int a = num_a(gen), b = num_b(gen), c = num_c(gen);
        bool plus = opdis(gen) == 0;
        long long ans = plus ? (static_cast<long long>(a) + static_cast<long long>(b) - c)
                             : (static_cast<long long>(a) - static_cast<long long>(b) + c);
        std::string nonce = random_nonce();
        NonceRec rec;
        std::vector<int> pattern_nodes = generate_pattern_nodes(gen);
        {
            std::lock_guard<std::mutex> lock(g_nonce_mu);
            rec.ans = std::to_string(ans);
            rec.ip = ip;
            rec.ua = ua_fp;
            rec.answer_key = "ans_" + random_token(6);
            rec.behavior_key = "beh_" + random_token(6);
            rec.connection_key = "conn_" + random_token(6);
            rec.pattern_key = "pat_" + random_token(6);
            rec.pattern_seq = join_ints_dash(pattern_nodes);
            rec.min_connection_ms = delay_ms_dis(gen);
            rec.issued_at = std::time(nullptr);
            // Keep nonce valid longer to reduce false invalidation on mobile/slow interaction.
            rec.exp = std::time(nullptr) + 3600;
            g_nonce_map[nonce] = rec;
        }

        json out;
        out["ok"] = true;
        out["nonce"] = nonce;
        out["question"] = "(" + std::to_string(a) + (plus ? " + " : " - ") + std::to_string(b) + ") " + (plus ? "- " : "+ ") + std::to_string(c) + " = ?";
        out["answer_key"] = rec.answer_key;
        out["behavior_key"] = rec.behavior_key;
        out["connection_key"] = rec.connection_key;
        out["pattern_key"] = rec.pattern_key;
        out["connection_delay_ms"] = rec.min_connection_ms;
        send_response(fd, 200, "OK", out.dump(), {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
        close(fd);
        return;
    }

    if (req.path == "/verify-math" && req.method == "POST") {
        if (!s.enabled) {
            send_response(fd, 200, "OK", "{\"ok\":true}", {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
            close(fd);
            return;
        }
        json in = json::object();
        std::string nonce;
        try {
            in = json::parse(req.body);
            nonce = trim(in.value("nonce", std::string()));
        } catch (...) {
            send_response(fd, 400, "Bad Request", "{\"ok\":false,\"error\":\"invalid_json\"}", {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
            close(fd);
            return;
        }
        if (nonce.empty()) {
            send_response(fd, 401, "Unauthorized", "{\"ok\":false,\"error\":\"nonce_invalid\"}", {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
            close(fd);
            return;
        }

        NonceRec rec;
        bool found = false;
        {
            std::lock_guard<std::mutex> lock(g_nonce_mu);
            auto it = g_nonce_map.find(nonce);
            if (it != g_nonce_map.end()) {
                rec = it->second;
                found = true;
            }
        }
        if (!found || rec.exp < std::time(nullptr) || rec.ua != ua_fp) {
            send_response(fd, 401, "Unauthorized", "{\"ok\":false,\"error\":\"nonce_invalid\"}", {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
            close(fd);
            return;
        }
        if (rec.answer_key.empty() || !in.contains(rec.answer_key)) {
            send_response(fd, 401, "Unauthorized", "{\"ok\":false,\"error\":\"answer_key_invalid\"}", {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
            close(fd);
            return;
        }

        std::string answer;
        if (in[rec.answer_key].is_string()) answer = trim(in[rec.answer_key].get<std::string>());
        else if (in[rec.answer_key].is_number_integer()) answer = std::to_string(in[rec.answer_key].get<long long>());
        else if (in[rec.answer_key].is_number_float()) answer = std::to_string(static_cast<long long>(in[rec.answer_key].get<double>()));
        answer = normalize_numeric_answer(answer);
        const std::string expected_answer = normalize_numeric_answer(rec.ans);
        if (answer != expected_answer) {
            std::lock_guard<std::mutex> lock(g_nonce_mu);
            auto it = g_nonce_map.find(nonce);
            if (it != g_nonce_map.end()) {
                it->second.math_fail_count++;
                if (it->second.math_fail_count >= 4) g_nonce_map.erase(it);
            }
            send_response(fd, 401, "Unauthorized", "{\"ok\":false,\"error\":\"answer_wrong\"}", {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
            close(fd);
            return;
        }

        std::vector<int> expected_nodes = split_ints_dash(rec.pattern_seq);
        {
            std::lock_guard<std::mutex> lock(g_nonce_mu);
            auto it = g_nonce_map.find(nonce);
            if (it != g_nonce_map.end()) it->second.math_verified = true;
        }
        json out;
        out["ok"] = true;
        out["pattern_points"] = expected_nodes;
        out["pattern_hint"] = pattern_hint_text(expected_nodes);
        send_response(fd, 200, "OK", out.dump(), {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
        close(fd);
        return;
    }

    if (req.path == "/page" && (req.method == "GET" || req.method == "HEAD")) {
        std::map<std::string, std::string> q = parse_query(req.query);
        std::string rd = q.count("rd") ? q["rd"] : "/";
        if (rd.empty() || rd[0] != '/') rd = "/";
        std::string html =
            "<!doctype html><html><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">"
            "<title>PteroProtect Challenge</title>"
            "<style>"
            ":root{--bg:#081225;--card:#0e1f36;--line:#1f3f66;--text:#d5ebff;--muted:#9ec3e4;--acc:#2f88ff;--acc2:#63d0ff;--err:#ff8b8b;}"
            "*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:18px;"
            "font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif;"
            "color:var(--text);background:radial-gradient(1000px 480px at 10% 0%,#173861 0%,transparent 60%),"
            "radial-gradient(1000px 520px at 100% 100%,#0b4f63 0%,transparent 58%),var(--bg)}"
            ".card{width:min(460px,96vw);background:linear-gradient(180deg,rgba(255,255,255,.03),rgba(255,255,255,.01));"
            "border:1px solid var(--line);border-radius:16px;overflow:hidden;box-shadow:0 30px 80px rgba(0,0,0,.4)}"
            ".head{padding:14px 16px;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:10px}"
            ".dot{width:10px;height:10px;border-radius:999px;background:linear-gradient(135deg,var(--acc),var(--acc2));box-shadow:0 0 18px rgba(72,170,255,.8)}"
            ".title{font-weight:700;letter-spacing:.2px}.sub{margin-left:auto;font-size:12px;color:var(--muted)}"
            ".body{padding:16px}.tabs{display:flex;gap:8px;margin:0 0 12px}.tab{flex:1;text-align:center;padding:8px 10px;border:1px solid var(--line);border-radius:10px;color:var(--muted);font-size:12px}"
            ".tab.on{color:#cfe7ff;background:#0c223d;border-color:#3a6aa0}.pane{display:none}.pane.on{display:block}.big{padding:14px;border:1px solid var(--line);border-radius:12px;background:#0a1a2d}"
            ".connbox{position:relative;min-height:190px;padding-bottom:84px}.human-wrap{position:absolute;left:14px;top:98px}"
            ".timer{font-size:28px;font-weight:800;letter-spacing:.4px;color:#bfe1ff}.q{margin:0 0 10px;color:var(--muted);font-size:14px;line-height:1.45}"
            ".qa{margin:0 0 12px;padding:12px;border:1px solid var(--line);border-radius:10px;background:#0a1a2d;color:#bfe1ff;font-weight:600}"
            ".pat{margin:0 0 12px;padding:10px;border:1px solid var(--line);border-radius:10px;background:#07172a;display:none}"
            ".pat canvas{display:block;width:100%;max-width:280px;aspect-ratio:1/1;background:#061221;border:1px solid #24466c;border-radius:8px;touch-action:none;margin:0 auto}"
            ".row{display:flex;gap:10px}.row input{flex:1}"
            "input,button{border-radius:10px;border:1px solid #2b527f;background:#0c1f34;color:var(--text);padding:12px 13px;font-size:14px;outline:none}"
            "input:focus{border-color:#4f93df;box-shadow:0 0 0 2px rgba(79,147,223,.18)}"
            "button{cursor:pointer;background:linear-gradient(135deg,#1f70e6,#2f9cff);border-color:#3ca3ff;font-weight:700;min-width:112px}"
            "button.secondary{background:#142b45;border-color:#2f5b8b;color:#bfe1ff}"
            "button:hover{filter:brightness(1.06)}button:disabled{opacity:.65;cursor:not-allowed}"
            ".hint{margin-top:10px;color:var(--muted);font-size:12px}.status{margin-top:9px;color:#9fd2ff;min-height:18px;font-size:13px}.err{margin-top:6px;color:var(--err);min-height:18px;font-size:13px}"
            "@media (max-width:520px){.row{flex-direction:column}button{width:100%}}"
            "</style></head><body><div class=\"card\">"
            "<div class=\"head\"><span class=\"dot\"></span><span class=\"title\">PteroProtect Verification</span><span class=\"sub\">30m clearance</span></div>"
            "<div class=\"body\"><div class=\"tabs\"><div class=\"tab on\" id=\"tab_conn\">Connection</div><div class=\"tab\" id=\"tab_chal\">Challenge</div></div>"
            "<div class=\"pane on\" id=\"pane_conn\"><div class=\"big connbox\" id=\"connbox\"><p class=\"q\">Checking connection integrity...</p><div class=\"timer\" id=\"ctimer\">--</div><div class=\"human-wrap\" id=\"human_wrap\"><button id=\"human_btn\" type=\"button\">I am human, pass me</button></div></div></div>"
            "<div class=\"pane\" id=\"pane_chal\"><p class=\"q\" id=\"phaseq\">Tahap 1: selesaikan math dulu.</p><p class=\"q\" id=\"phint\"></p>"
            "<p class=\"qa\" id=\"q\">Memuat challenge...</p><div class=\"pat\" id=\"patbox\"><canvas id=\"pc\" width=\"280\" height=\"280\"></canvas></div><div class=\"row\" id=\"ainput_wrap\"><input id=\"a\" placeholder=\"Masukkan jawaban\"/></div><div class=\"row\"><button id=\"b\">Continue</button><button id=\"rb\" type=\"button\" class=\"secondary\">Restart (3)</button></div>"
            "<div class=\"hint\">Tip: gunakan perangkat normal (mouse/touch/scroll) agar lolos validasi anti-bot.</div></div><div class=\"status\" id=\"s\"></div><div class=\"err\" id=\"e\"></div></div></div>"
            "<script>let nonce=\"\",ak=\"\",bk=\"\",ck=\"\",pk=\"\";let phase=1;let pseq=[];let clicked=[];let pTrace=[];let pStart=0;let pDir=0;let pActive=false;let ppx=null,ppy=null,pvdx=0,pvdy=0;let started=Date.now();let unlockAt=0;let waitTimer=null;let humanMoveTimer=null;let humanReady=false;let enteredChallenge=false;let pm=0,pd=0,pdc=0,tm=0,sc=0,kc=0,px=null,py=null,pvx=0,pvy=0;let restartsLeft=3;"
            "const elQ=document.getElementById('q'),elA=document.getElementById('a'),elB=document.getElementById('b'),elRB=document.getElementById('rb'),elS=document.getElementById('s'),elE=document.getElementById('e'),elCT=document.getElementById('ctimer'),elHB=document.getElementById('human_btn'),elCW=document.getElementById('connbox'),elHW=document.getElementById('human_wrap'),elPC=document.getElementById('pane_conn'),elPH=document.getElementById('pane_chal'),elTC=document.getElementById('tab_conn'),elTH=document.getElementById('tab_chal'),elPQ=document.getElementById('phaseq'),elPHint=document.getElementById('phint'),elPat=document.getElementById('patbox'),elInputWrap=document.getElementById('ainput_wrap'),pc=document.getElementById('pc'),ctx=pc.getContext('2d');"
            "function normAns(v){let s=String(v||'').trim();if(!s)return s;const sign=(s[0]==='+'||s[0]==='-')?s[0]:'';if(sign)s=s.slice(1);s=s.replace(/[\\s,._']/g,'');return sign+s;}"
            "function trackPointer(x,y){if(px!==null&&py!==null){const dx=x-px,dy=y-py;pd+=Math.hypot(dx,dy);pm++;if((pvx!==0||pvy!==0)&&((dx*pvx+dy*pvy)<-4))pdc++;pvx=dx;pvy=dy;}px=x;py=y;}"
            "window.addEventListener('mousemove',e=>trackPointer(e.clientX,e.clientY),{passive:true});"
            "window.addEventListener('touchmove',e=>{const t=e.touches&&e.touches[0];if(!t)return;tm++;trackPointer(t.clientX,t.clientY);},{passive:true});"
            "window.addEventListener('scroll',()=>{sc++;},{passive:true});"
            "window.addEventListener('keydown',()=>{kc++;});"
            "function behavior(){return{duration_ms:Date.now()-started,pointer_moves:pm,pointer_distance:Math.round(pd),pointer_dir_changes:pdc,touch_moves:tm,scroll_count:sc,key_count:kc};}"
            "const nodes=[[20,20],[50,20],[80,20],[20,50],[50,50],[80,50],[20,80],[50,80],[80,80]];"
            "function drawPattern(){const w=pc.width,h=pc.height;ctx.clearRect(0,0,w,h);ctx.fillStyle='#061221';ctx.fillRect(0,0,w,h);if(Array.isArray(clicked)&&clicked.length>1){ctx.strokeStyle='#66b6ff';ctx.lineWidth=3;ctx.beginPath();for(let i=0;i<clicked.length;i++){const n=nodes[clicked[i]-1];if(!n)continue;const x=n[0]/100*w,y=n[1]/100*h;if(i===0)ctx.moveTo(x,y);else ctx.lineTo(x,y);}ctx.stroke();}for(let i=0;i<nodes.length;i++){const n=nodes[i],x=n[0]/100*w,y=n[1]/100*h;ctx.beginPath();ctx.arc(x,y,11,0,Math.PI*2);ctx.fillStyle='#0f2740';ctx.fill();ctx.strokeStyle='#4da0ff';ctx.stroke();ctx.fillStyle='#bfe1ff';ctx.font='12px sans-serif';ctx.fillText(String(i+1),x-4,y+4);}}"
            "function nearestNode(ev){const r=pc.getBoundingClientRect();const x=((ev.clientX-r.left)/r.width)*100;const y=((ev.clientY-r.top)/r.height)*100;let best=-1,bd=1e9;for(let i=0;i<nodes.length;i++){const dx=x-nodes[i][0],dy=y-nodes[i][1],d=dx*dx+dy*dy;if(d<bd){bd=d;best=i;}}if(best<0||bd>18*18)return -1;return best+1;}"
            "function addClickNode(n){if(n<1||n>9)return;if(!pStart)pStart=Date.now();if(clicked.length&&clicked[clicked.length-1]===n)return;clicked.push(n);const pt=nodes[n-1];const t=Date.now()-pStart;if(ppx!==null&&ppy!==null){const dx=pt[0]-ppx,dy=pt[1]-ppy;if((pvdx!==0||pvdy!==0)&&((dx*pvdx+dy*pvdy)<-1))pDir++;pvdx=dx;pvdy=dy;}ppx=pt[0];ppy=pt[1];pTrace.push([pt[0],pt[1],t]);if(pTrace.length>220)pTrace.shift();drawPattern();}"
            "pc.addEventListener('pointerdown',e=>{if(phase!==2)return;const n=nearestNode(e);if(n>0)addClickNode(n);});"
            "function patternPayload(){const dur=pStart?Date.now()-pStart:0;return{duration_ms:dur,dir_changes:pDir,trace:pTrace,clicked_nodes:clicked};}"
            "function connectionInfo(){const n=navigator||{};const s=screen||{};const tz=(Intl&&Intl.DateTimeFormat)?(Intl.DateTimeFormat().resolvedOptions().timeZone||''):'unknown';"
            "return{webdriver:!!n.webdriver,ua_len:String(n.userAgent||'').length,lang_len:String(n.language||'').length,tz_len:String(tz||'').length,max_touch_points:Number(n.maxTouchPoints||0),hardware_concurrency:Number(n.hardwareConcurrency||0),screen_w:Number(s.width||0),screen_h:Number(s.height||0),color_depth:Number(s.colorDepth||0)};}"
            "function showConn(){elPC.classList.add('on');elPH.classList.remove('on');elTC.classList.add('on');elTH.classList.remove('on');}"
            "function showChal(){elPC.classList.remove('on');elPH.classList.add('on');elTC.classList.remove('on');elTH.classList.add('on');}"
            "function setPhaseMath(){phase=1;elQ.style.display='';elInputWrap.style.display='';elPat.style.display='none';elA.value='';}"
            "function setPhasePattern(){phase=2;elQ.style.display='none';elInputWrap.style.display='none';elPat.style.display='block';}"
            "function randBtn(){if(!elHB||!elCW||!elHW)return;const pad=12;const topMin=96;const maxX=Math.max(pad,elCW.clientWidth-elHB.offsetWidth-pad);const maxY=Math.max(topMin,elCW.clientHeight-elHB.offsetHeight-pad);const x=pad+Math.floor(Math.random()*(Math.max(1,maxX-pad+1)));const y=topMin+Math.floor(Math.random()*(Math.max(1,maxY-topMin+1)));elHW.style.left=String(x)+'px';elHW.style.top=String(y)+'px';}"
            "function fmtMs(ms){const t=Math.max(0,Math.ceil(ms/1000));const m=Math.floor(t/60);const s=t%60;return String(m)+'m '+String(s).padStart(2,'0')+'s';}"
            "function updateWait(){const ms=unlockAt-Date.now();if(ms<=0){if(waitTimer){clearInterval(waitTimer);waitTimer=null;}if(humanMoveTimer){clearInterval(humanMoveTimer);humanMoveTimer=null;}elCT.textContent='OK';elS.textContent='Connection check passed.';elB.disabled=false;return;}const label=fmtMs(ms);elCT.textContent=label;if(!enteredChallenge){showConn();elB.disabled=true;elS.textContent='Checking connection... '+label+' | tap button to open challenge';}else{elB.disabled=false;elS.textContent='Challenge opened. No cooldown for submit.';}}"
            "async function loadC(){elE.textContent='';elS.textContent='';elB.disabled=true;const r=await fetch('/__pteroprotect/challenge/new',{cache:'no-store'});const j=await r.json();if(!j.ok)throw new Error('challenge unavailable');"
            "nonce=j.nonce;ak=j.answer_key||'answer';bk=j.behavior_key||'behavior';ck=j.connection_key||'connection';pk=j.pattern_key||'pattern';setPhaseMath();pseq=[];clicked=[];pTrace=[];pStart=0;pDir=0;pActive=false;ppx=null;ppy=null;pvdx=0;pvdy=0;elPQ.textContent='Tahap 1: selesaikan math dulu.';elPHint.textContent='';drawPattern();elQ.textContent=j.question;restartsLeft=3;elRB.disabled=false;elRB.textContent='Restart ('+String(restartsLeft)+')';"
            "const raw=Number(j.connection_delay_ms||0);const d=Math.min(32400000,Math.max(360000,raw));started=Date.now();humanReady=false;enteredChallenge=false;elHB.disabled=false;elHB.textContent='I am human, pass me';unlockAt=Date.now()+d;requestAnimationFrame(randBtn);if(humanMoveTimer)clearInterval(humanMoveTimer);humanMoveTimer=setInterval(()=>{if(!humanReady)randBtn();},1800);updateWait();if(waitTimer)clearInterval(waitTimer);waitTimer=setInterval(updateWait,250);}"
            "elHB.onclick=()=>{humanReady=true;enteredChallenge=true;elHB.disabled=true;elHB.textContent='Challenge opened';if(humanMoveTimer){clearInterval(humanMoveTimer);humanMoveTimer=null;}showChal();elA.focus();updateWait();};"
            "window.addEventListener('resize',()=>{if(!humanReady)randBtn();});"
            "elA.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();elB.click();}});"
            "elRB.onclick=async()=>{if(restartsLeft<=0){elRB.disabled=true;return;}restartsLeft-=1;elRB.textContent='Restart ('+String(restartsLeft)+')';if(restartsLeft<=0)elRB.disabled=true;elE.textContent='';elS.textContent='Challenge di-reset...';await loadC().catch(e=>elE.textContent=String(e.message||e));};"
            "elB.onclick=async()=>{try{if(Date.now()<unlockAt&&!enteredChallenge){updateWait();return;}elE.textContent='';elB.disabled=true;"
            "if(phase===1){const m={nonce:nonce};m[ak]=normAns(elA.value||'');const mr=await fetch('/__pteroprotect/challenge/verify-math',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(m)});const mj=await mr.json();if(!mj.ok)throw new Error(mj.error||'math_failed');setPhasePattern();pseq=Array.isArray(mj.pattern_points)?mj.pattern_points:[];clicked=[];pTrace=[];pStart=0;pDir=0;pActive=false;ppx=null;ppy=null;pvdx=0;pvdy=0;elPQ.textContent='Tahap 2: klik angka sesuai urutan.';elPHint.textContent=String(mj.pattern_hint||'');drawPattern();elB.disabled=false;return;}"
            "const p={nonce:nonce,rd:'" + rd + "'};p[ak]=normAns(elA.value||'');p[bk]=behavior();p[ck]=connectionInfo();p[pk]=patternPayload();"
            "const r=await fetch('/__pteroprotect/challenge/solve',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(p)});const j=await r.json();if(!j.ok)throw new Error(j.error||'failed');location.href=j.redirect||'" + rd + "';}"
            "catch(err){const msg=String(err.message||err);elS.textContent='';elE.textContent=msg;if((msg==='answer_wrong'||msg==='pattern_invalid'||msg==='math_not_verified'||msg==='nonce_invalid'||msg==='nonce_expired')&&restartsLeft>0){elS.textContent='Salah. Kamu bisa tekan Restart ('+String(restartsLeft)+')';}elB.disabled=false;}};"
            "if('serviceWorker' in navigator){navigator.serviceWorker.register('/__pteroprotect/challenge/sw.js',{scope:'/__pteroprotect/challenge/'}).catch(()=>{});}loadC().catch(e=>elE.textContent=String(e.message||e));</script></body></html>";
        send_response(fd, 200, "OK", html, {{"Content-Type", "text/html; charset=utf-8"}}, head_only);
        close(fd);
        return;
    }

    if (req.path == "/sw.js" && (req.method == "GET" || req.method == "HEAD")) {
        std::string js =
            "const CACHE='pp-challenge-v8';"
            "const PRECACHE=['/__pteroprotect/challenge/page'];"
            "self.addEventListener('install',e=>{e.waitUntil(caches.open(CACHE).then(c=>c.addAll(PRECACHE)).then(()=>self.skipWaiting()));});"
            "self.addEventListener('activate',e=>{e.waitUntil(self.clients.claim());});"
            "self.addEventListener('fetch',e=>{"
            "const u=new URL(e.request.url);"
            "if(u.pathname.startsWith('/__pteroprotect/challenge/new')||u.pathname.startsWith('/__pteroprotect/challenge/verify-math')||u.pathname.startsWith('/__pteroprotect/challenge/solve')||u.pathname.startsWith('/__pteroprotect/challenge/check')){"
            "e.respondWith(fetch(e.request));return;}"
            "if(u.pathname.startsWith('/__pteroprotect/challenge/')){"
            "e.respondWith(caches.match(e.request).then(r=>r||fetch(e.request).then(n=>{const cp=n.clone();caches.open(CACHE).then(c=>c.put(e.request,cp)).catch(()=>{});return n;}).catch(()=>caches.match('/__pteroprotect/challenge/page'))));"
            "}"
            "});";
        std::vector<std::pair<std::string, std::string>> headers = {
            {"Content-Type", "application/javascript; charset=utf-8"},
            {"Service-Worker-Allowed", "/__pteroprotect/challenge/"}
        };
        send_response(fd, 200, "OK", js, headers, head_only);
        close(fd);
        return;
    }

    if (req.path == "/solve" && req.method == "POST") {
        if (!s.enabled) {
            send_response(fd, 200, "OK", "{\"ok\":true,\"redirect\":\"/\"}", {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
            close(fd);
            return;
        }
        std::string nonce, answer, rd = "/";
        json behavior = json::object();
        json conn = json::object();
        json pattern = json::object();
        json in = json::object();
        try {
            in = json::parse(req.body);
            nonce = trim(in.value("nonce", std::string()));
            rd = trim(in.value("rd", std::string("/")));
        } catch (...) {
            send_response(fd, 400, "Bad Request", "{\"ok\":false,\"error\":\"invalid_json\"}", {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
            close(fd);
            return;
        }
        if (rd.empty() || rd[0] != '/') rd = "/";

        NonceRec rec;
        bool found = false;
        {
            std::lock_guard<std::mutex> lock(g_nonce_mu);
            auto it = g_nonce_map.find(nonce);
            if (it != g_nonce_map.end()) {
                rec = it->second;
                g_nonce_map.erase(it);
                found = true;
            }
        }
        if (!found) {
            send_response(fd, 401, "Unauthorized", "{\"ok\":false,\"error\":\"nonce_invalid\"}", {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
            close(fd);
            return;
        }
        if (rec.exp < std::time(nullptr)) {
            send_response(fd, 401, "Unauthorized", "{\"ok\":false,\"error\":\"nonce_expired\"}", {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
            close(fd);
            return;
        }
        if (rec.ua != ua_fp) {
            send_response(fd, 401, "Unauthorized", "{\"ok\":false,\"error\":\"fingerprint_mismatch\"}", {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
            close(fd);
            return;
        }
        if (!rec.math_verified) {
            send_response(fd, 401, "Unauthorized", "{\"ok\":false,\"error\":\"math_not_verified\"}", {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
            close(fd);
            return;
        }
        if (rec.answer_key.empty() || !in.contains(rec.answer_key)) {
            send_response(fd, 401, "Unauthorized", "{\"ok\":false,\"error\":\"answer_key_invalid\"}", {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
            close(fd);
            return;
        }
        if (in[rec.answer_key].is_string()) answer = trim(in[rec.answer_key].get<std::string>());
        else if (in[rec.answer_key].is_number_integer()) answer = std::to_string(in[rec.answer_key].get<long long>());
        else if (in[rec.answer_key].is_number_float()) answer = std::to_string(static_cast<long long>(in[rec.answer_key].get<double>()));
        if (!rec.behavior_key.empty() && in.contains(rec.behavior_key) && in[rec.behavior_key].is_object()) {
            behavior = in[rec.behavior_key];
        }
        if (!rec.connection_key.empty() && in.contains(rec.connection_key) && in[rec.connection_key].is_object()) {
            conn = in[rec.connection_key];
        }
        if (!rec.pattern_key.empty() && in.contains(rec.pattern_key) && in[rec.pattern_key].is_object()) {
            pattern = in[rec.pattern_key];
        }
        if (!ua_declared_browser(ua) || !connection_pass(conn, ua)) {
            send_response(fd, 401, "Unauthorized", "{\"ok\":false,\"error\":\"connection_invalid\"}", {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
            close(fd);
            return;
        }
        if (!pattern_pass(pattern, rec)) {
            send_response(fd, 401, "Unauthorized", "{\"ok\":false,\"error\":\"pattern_invalid\"}", {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
            close(fd);
            return;
        }
        if (!behavior_pass(behavior, rec)) {
            send_response(fd, 401, "Unauthorized", "{\"ok\":false,\"error\":\"behavior_insufficient\"}", {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
            close(fd);
            return;
        }
        answer = normalize_numeric_answer(answer);
        const std::string expected_answer = normalize_numeric_answer(rec.ans);
        if (expected_answer != answer) {
            send_response(fd, 401, "Unauthorized", "{\"ok\":false,\"error\":\"answer_wrong\"}", {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
            close(fd);
            return;
        }

        std::string sid = random_nonce();
        {
            std::lock_guard<std::mutex> lock(g_session_mu);
            SessionRec sr;
            sr.sid = sid;
            sr.ua = ua_fp;
            sr.exp = std::time(nullptr) + s.ttl;
            g_ip_session_map[ip] = sr;
        }
        std::string tok = issue_token(s, ip, ua_fp, sid);
        std::vector<std::pair<std::string, std::string>> headers = {
            {"Content-Type", "application/json; charset=utf-8"},
            {"Set-Cookie", s.cookie_name + "=" + tok + "; Path=/; Max-Age=" + std::to_string(s.ttl) + "; HttpOnly; Secure; SameSite=Lax"},
        };
        json out;
        out["ok"] = true;
        out["redirect"] = rd;
        send_response(fd, 200, "OK", out.dump(), headers, head_only);
        close(fd);
        return;
    }

    send_response(fd, 404, "Not Found", "{\"ok\":false,\"error\":\"not_found\"}", {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
    close(fd);
}

static void signal_handler(int) { g_running = false; }

int main() {
    const char* cfg_env = std::getenv("PTEROPROTECT_CONFIG_PATH");
    if (cfg_env && *cfg_env) g_cfg_path = cfg_env;
    Settings s = load_settings();

    signal(SIGINT, signal_handler);
    signal(SIGTERM, signal_handler);

    int server_fd = socket(AF_INET, SOCK_STREAM, 0);
    if (server_fd < 0) {
        std::cerr << "challenge_guard: socket failed\n";
        return 1;
    }

    int yes = 1;
    setsockopt(server_fd, SOL_SOCKET, SO_REUSEADDR, &yes, sizeof(yes));

    sockaddr_in addr{};
    addr.sin_family = AF_INET;
    addr.sin_port = htons(static_cast<uint16_t>(s.port));
    if (inet_pton(AF_INET, s.bind.c_str(), &addr.sin_addr) != 1) {
        std::cerr << "challenge_guard: invalid bind " << s.bind << "\n";
        close(server_fd);
        return 1;
    }

    if (bind(server_fd, reinterpret_cast<sockaddr*>(&addr), sizeof(addr)) != 0) {
        std::cerr << "challenge_guard: bind failed: " << std::strerror(errno) << "\n";
        close(server_fd);
        return 1;
    }

    if (listen(server_fd, 128) != 0) {
        std::cerr << "challenge_guard: listen failed\n";
        close(server_fd);
        return 1;
    }
    int fl = fcntl(server_fd, F_GETFL, 0);
    if (fl >= 0) {
        fcntl(server_fd, F_SETFL, fl | O_NONBLOCK);
    }

    while (g_running) {
        sockaddr_in cli{};
        socklen_t cl = sizeof(cli);
        int fd = accept(server_fd, reinterpret_cast<sockaddr*>(&cli), &cl);
        if (fd < 0) {
            if (errno == EINTR || errno == EAGAIN || errno == EWOULDBLOCK) {
                std::this_thread::sleep_for(std::chrono::milliseconds(20));
                continue;
            }
            std::this_thread::sleep_for(std::chrono::milliseconds(20));
            continue;
        }
        char ipbuf[INET_ADDRSTRLEN] = {0};
        inet_ntop(AF_INET, &cli.sin_addr, ipbuf, sizeof(ipbuf));
        std::string rip = ipbuf[0] ? ipbuf : "127.0.0.1";
        std::thread([fd, rip]() { handle_client(fd, rip); }).detach();
    }

    close(server_fd);
    return 0;
}
