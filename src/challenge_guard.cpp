#include <arpa/inet.h>
#include <fcntl.h>
#include <netdb.h>
#include <netinet/in.h>
#include <mysql/mysql.h>
#include <openssl/evp.h>
#include <openssl/hmac.h>
#include <openssl/sha.h>
#include <openssl/ssl.h>
#include <sys/socket.h>
#include <sys/select.h>
#include <sys/types.h>
#include <unistd.h>

#include <algorithm>
#include <atomic>
#include <chrono>
#include <cctype>
#include <cerrno>
#include <cstdint>
#include <cmath>
#include <cstdio>
#include <cstdlib>
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
    bool strict_mode = true;
    bool provider_token_gate_enabled = false;
    std::string bind = "127.0.0.1";
    int port = 18444;
    int ttl = 1800;
    int pow_bits = 14;
    std::string cookie_name = "pp_clearance";
    std::string secret;
    std::vector<std::string> trusted_hosts;
    std::vector<std::string> provider_token_cidrs;
    std::string provider_token_cache_file;
    std::string provider_token_ip_cache_file;
    int provider_token_ip_cache_ttl_sec = 604800;
    std::vector<std::string> provider_token_provider_keywords;
    std::string db_host = "127.0.0.1";
    std::string db_user;
    std::string db_password;
    std::string db_name;
    std::string panel_app_key;
};

struct NonceRec {
    std::string ans;
    std::string ip;
    std::string ua;
    std::string answer_key;
    std::string click_key;
    std::string behavior_key;
    std::string connection_key;
    std::string pattern_key;
    std::string pow_salt;
    std::string pattern_seq;
    bool click_verified = false;
    bool math_verified = false;
    int math_fail_count = 0;
    std::time_t click_bucket_sec = 0;
    int click_bucket_count = 0;
    int pow_bits = 18;
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
static std::string g_ephemeral_secret;
static std::mutex g_api_token_cache_mu;
static std::map<std::string, std::time_t> g_valid_api_token_cache;
static std::string random_nonce();
static std::string to_lower(std::string s);
static bool base64_decode(const std::string& in, std::string& out);
static std::string hmac_sha256_hex(const std::string& key, const std::string& data);
static bool secure_equals(const std::string& a, const std::string& b);
static bool daemon_bearer_token_format_ok(const std::string& token);

static inline std::string trim(const std::string& in) {
    std::size_t b = 0;
    while (b < in.size() && std::isspace(static_cast<unsigned char>(in[b]))) b++;
    std::size_t e = in.size();
    while (e > b && std::isspace(static_cast<unsigned char>(in[e - 1]))) e--;
    return in.substr(b, e - b);
}

static std::map<std::string, std::string> parse_env_file(const std::string& path) {
    std::map<std::string, std::string> out;
    std::ifstream f(path);
    if (!f.is_open()) return out;

    std::string line;
    while (std::getline(f, line)) {
        if (!line.empty() && line.back() == '\r') line.pop_back();
        std::string t = trim(line);
        if (t.empty() || t[0] == '#') continue;
        std::size_t eq = t.find('=');
        if (eq == std::string::npos) continue;
        std::string key = trim(t.substr(0, eq));
        std::string value = trim(t.substr(eq + 1));
        if (value.size() >= 2) {
            char q = value.front();
            if ((q == '"' || q == '\'') && value.back() == q) {
                value = value.substr(1, value.size() - 2);
            }
        }
        if (!key.empty()) out[key] = value;
    }
    return out;
}

static void append_csv_parts(const std::string& text, std::vector<std::string>& out) {
    std::stringstream ss(text);
    std::string part;
    while (std::getline(ss, part, ',')) {
        part = trim(part);
        if (!part.empty()) out.push_back(part);
    }
}

static void append_provider_cidrs_from_file(const std::string& path, std::vector<std::string>& out) {
    std::ifstream f(path);
    if (!f.is_open()) return;
    std::string line;
    while (std::getline(f, line)) {
        if (!line.empty() && line.back() == '\r') line.pop_back();
        std::string cleaned = trim(line);
        std::size_t hash = cleaned.find('#');
        if (hash != std::string::npos) cleaned = trim(cleaned.substr(0, hash));
        if (cleaned.empty()) continue;
        append_csv_parts(cleaned, out);
    }
}

static bool begins_with(const std::string& value, const std::string& prefix) {
    return value.size() >= prefix.size() && value.compare(0, prefix.size(), prefix) == 0;
}

static bool base64_decode(const std::string& in, std::string& out) {
    out.clear();
    if (in.empty()) return true;
    BIO* b64 = BIO_new(BIO_f_base64());
    BIO* bio = BIO_new_mem_buf(in.data(), static_cast<int>(in.size()));
    if (!b64 || !bio) {
        if (b64) BIO_free(b64);
        if (bio) BIO_free(bio);
        return false;
    }
    BIO_set_flags(b64, BIO_FLAGS_BASE64_NO_NL);
    bio = BIO_push(b64, bio);
    std::string buf(in.size(), '\0');
    int n = BIO_read(bio, &buf[0], static_cast<int>(buf.size()));
    BIO_free_all(bio);
    if (n < 0) return false;
    buf.resize(static_cast<std::size_t>(n));
    out.swap(buf);
    return true;
}

static std::string hmac_sha256_hex(const std::string& key, const std::string& data) {
    unsigned char digest[EVP_MAX_MD_SIZE];
    unsigned int len = 0;
    HMAC(EVP_sha256(),
         reinterpret_cast<const unsigned char*>(key.data()), static_cast<int>(key.size()),
         reinterpret_cast<const unsigned char*>(data.data()), data.size(),
         digest, &len);
    static const char* kHex = "0123456789abcdef";
    std::string out;
    out.resize(static_cast<std::size_t>(len) * 2);
    for (unsigned int i = 0; i < len; ++i) {
        out[2 * i] = kHex[(digest[i] >> 4) & 0x0F];
        out[2 * i + 1] = kHex[digest[i] & 0x0F];
    }
    return out;
}

static bool is_panel_api_token_format(const std::string& token) {
    if (!(begins_with(token, "ptla_") || begins_with(token, "ptlc_"))) return false;
    if (token.size() <= 16 || token.size() > 255) return false;
    for (char c : token) {
        const bool ok = std::isalnum(static_cast<unsigned char>(c)) || c == '_';
        if (!ok) return false;
    }
    return true;
}

static bool parse_laravel_app_key(const std::string& app_key, std::string& out_key) {
    std::string raw = trim(app_key);
    if (raw.empty()) return false;
    if (begins_with(raw, "base64:")) {
        return base64_decode(raw.substr(7), out_key);
    }
    out_key = raw;
    return !out_key.empty();
}

static bool php_unserialize_string(const std::string& input, std::string& out) {
    if (input.size() < 6 || input[0] != 's' || input[1] != ':') return false;
    std::size_t len_start = 2;
    std::size_t len_end = input.find(':', len_start);
    if (len_end == std::string::npos) return false;
    std::size_t quote = len_end + 1;
    if (quote >= input.size() || input[quote] != '"') return false;
    std::size_t end_quote = input.rfind("\";");
    if (end_quote == std::string::npos || end_quote <= quote) return false;
    out = input.substr(quote + 1, end_quote - quote - 1);
    return true;
}

static bool laravel_decrypt_string(const std::string& app_key, const std::string& payload_b64, std::string& out) {
    std::string key;
    if (!parse_laravel_app_key(app_key, key)) return false;

    std::string payload_json;
    if (!base64_decode(payload_b64, payload_json)) return false;

    json payload = json::parse(payload_json, nullptr, false);
    if (payload.is_discarded() || !payload.is_object()) return false;

    const std::string iv_b64 = trim(payload.value("iv", ""));
    const std::string value_b64 = trim(payload.value("value", ""));
    const std::string mac = to_lower(trim(payload.value("mac", "")));
    if (iv_b64.empty() || value_b64.empty() || mac.empty()) return false;

    std::string expected_mac = to_lower(hmac_sha256_hex(key, iv_b64 + value_b64));
    if (!secure_equals(expected_mac, mac)) return false;

    std::string iv;
    if (!base64_decode(iv_b64, iv)) return false;

    EVP_CIPHER_CTX* ctx = EVP_CIPHER_CTX_new();
    if (!ctx) return false;

    const EVP_CIPHER* cipher = nullptr;
    if (key.size() == 32) cipher = EVP_aes_256_cbc();
    else if (key.size() == 16) cipher = EVP_aes_128_cbc();
    if (!cipher) {
        EVP_CIPHER_CTX_free(ctx);
        return false;
    }

    std::string ciphertext;
    if (!base64_decode(value_b64, ciphertext)) {
        EVP_CIPHER_CTX_free(ctx);
        return false;
    }

    bool ok = false;
    int out_len1 = 0;
    int out_len2 = 0;
    std::string plain(ciphertext.size() + EVP_CIPHER_block_size(cipher), '\0');
    if (EVP_DecryptInit_ex(ctx, cipher, nullptr,
            reinterpret_cast<const unsigned char*>(key.data()),
            reinterpret_cast<const unsigned char*>(iv.data())) == 1 &&
        EVP_DecryptUpdate(ctx,
            reinterpret_cast<unsigned char*>(&plain[0]), &out_len1,
            reinterpret_cast<const unsigned char*>(ciphertext.data()),
            static_cast<int>(ciphertext.size())) == 1 &&
        EVP_DecryptFinal_ex(ctx,
            reinterpret_cast<unsigned char*>(&plain[0]) + out_len1, &out_len2) == 1) {
        plain.resize(static_cast<std::size_t>(out_len1 + out_len2));
        ok = php_unserialize_string(plain, out);
    }

    EVP_CIPHER_CTX_free(ctx);
    return ok;
}

static bool token_cache_contains_valid_api_token(const std::string& token) {
    std::lock_guard<std::mutex> lock(g_api_token_cache_mu);
    auto it = g_valid_api_token_cache.find(token);
    if (it == g_valid_api_token_cache.end()) return false;
    std::time_t now = std::time(nullptr);
    if (it->second < now) {
        g_valid_api_token_cache.erase(it);
        return false;
    }
    return true;
}

static void token_cache_store_valid_api_token(const std::string& token, int ttl_sec = 30) {
    std::lock_guard<std::mutex> lock(g_api_token_cache_mu);
    g_valid_api_token_cache[token] = std::time(nullptr) + ttl_sec;
}

static bool is_valid_panel_api_bearer(const Settings& s, const std::string& token) {
    if (!is_panel_api_token_format(token)) return false;
    if (token_cache_contains_valid_api_token(token)) return true;
    std::string cmd = "/usr/bin/php /pteroprotect/scripts/validate_api_key.php " + token + " 2>/dev/null";
    FILE* pipe = popen(cmd.c_str(), "r");
    if (!pipe) return false;
    char buf[64] = {0};
    std::string output;
    if (fgets(buf, sizeof(buf), pipe) != nullptr) output = trim(buf);
    const int rc = pclose(pipe);
    const bool ok = (rc == 0 && output == "valid");
    if (ok) token_cache_store_valid_api_token(token);
    return ok;
}

static bool ua_mobile_like(const std::string& ua) {
    std::string l = ua;
    std::transform(l.begin(), l.end(), l.begin(), [](unsigned char c) { return static_cast<char>(std::tolower(c)); });
    return l.find("mobile") != std::string::npos ||
           l.find("android") != std::string::npos ||
           l.find("iphone") != std::string::npos ||
           l.find("ipad") != std::string::npos ||
           l.find("ipod") != std::string::npos;
}

static bool ua_inapp_like(const std::string& ua) {
    std::string l = ua;
    std::transform(l.begin(), l.end(), l.begin(), [](unsigned char c) { return static_cast<char>(std::tolower(c)); });
    static const std::vector<std::string> hints = {
        " wv)", "; wv", "telegram", "fb_iab", "fban", "fbav", "instagram",
        "line/", "micromessenger", "gsa/", "okhttp", "vivo", "miuibrowser"
    };
    for (const auto& h : hints) {
        if (l.find(h) != std::string::npos) return true;
    }
    return false;
}

static std::string ua_binding_material(const std::string& ua_raw) {
    std::string ua = trim(ua_raw);
    std::transform(ua.begin(), ua.end(), ua.begin(), [](unsigned char c) { return static_cast<char>(std::tolower(c)); });
    if (ua.empty()) return ua;
    if (ua.size() > 512) ua = ua.substr(0, 512);

    // Keep strict/full UA for desktop. Relax mobile/in-app into stable buckets
    // because many webviews mutate UA tokens between requests (wv/version/etc).
    const bool mobile = ua_mobile_like(ua);
    const bool inapp = ua_inapp_like(ua);
    if (!mobile && !inapp) {
        return ua;
    }

    std::string platform = "mobile";
    if (ua.find("android") != std::string::npos) platform = "android";
    else if (ua.find("iphone") != std::string::npos || ua.find("ipad") != std::string::npos || ua.find("ipod") != std::string::npos) platform = "ios";

    std::string browser = "other";
    std::string major = "0";
    const std::vector<std::pair<std::string, std::string>> rules = {
        {"edg/", "edge"},
        {"opr/", "opera"},
        {"firefox/", "firefox"},
        {"fxios/", "firefox"},
        {"crios/", "chrome"},
        {"chrome/", "chrome"},
        {"version/", "safari"},
        {"telegram", "telegram"},
        {"fbav", "facebook"},
        {"instagram", "instagram"},
    };
    for (const auto& r : rules) {
        const auto pos = ua.find(r.first);
        if (pos == std::string::npos) continue;
        browser = r.second;
        break;
    }

    return std::string("mobile|") + platform + "|" + browser;
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
        // Support common UTF-8 minus/dash glyphs pasted from rich text:
        // U+2212 (E2 88 92), U+2013 (E2 80 93), U+2014 (E2 80 94), U+FE63 (EF B9 A3), U+FF0D (EF BC 8D)
        if ((s.size() - i) >= 3) {
            const unsigned char b1 = static_cast<unsigned char>(s[i]);
            const unsigned char b2 = static_cast<unsigned char>(s[i + 1]);
            const unsigned char b3 = static_cast<unsigned char>(s[i + 2]);
            const bool is_minus_utf8 =
                (b1 == 0xE2 && b2 == 0x88 && b3 == 0x92) ||
                (b1 == 0xE2 && b2 == 0x80 && (b3 == 0x93 || b3 == 0x94)) ||
                (b1 == 0xEF && b2 == 0xB9 && b3 == 0xA3) ||
                (b1 == 0xEF && b2 == 0xBC && b3 == 0x8D);
            const bool is_plus_utf8 = (b1 == 0xEF && b2 == 0xBC && b3 == 0x8B); // U+FF0B fullwidth plus
            if (is_minus_utf8 && out.empty()) {
                out.push_back('-');
                i += 2;
                continue;
            }
            if (is_plus_utf8 && out.empty()) {
                out.push_back('+');
                i += 2;
                continue;
            }
        }
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

static std::string sha256_hex(const std::string& input) {
    unsigned char digest[SHA256_DIGEST_LENGTH];
    SHA256(reinterpret_cast<const unsigned char*>(input.data()), input.size(), digest);
    static const char* kHex = "0123456789abcdef";
    std::string out;
    out.resize(SHA256_DIGEST_LENGTH * 2);
    for (int i = 0; i < SHA256_DIGEST_LENGTH; ++i) {
        out[2 * i] = kHex[(digest[i] >> 4) & 0x0F];
        out[2 * i + 1] = kHex[digest[i] & 0x0F];
    }
    return out;
}

static bool hash_has_leading_zero_bits(const std::string& hex_hash, int bits) {
    if (bits <= 0) return true;
    int full_nibbles = bits / 4;
    int rem_bits = bits % 4;
    if (static_cast<int>(hex_hash.size()) < full_nibbles + (rem_bits ? 1 : 0)) return false;
    for (int i = 0; i < full_nibbles; ++i) {
        if (hex_hash[static_cast<std::size_t>(i)] != '0') return false;
    }
    if (rem_bits == 0) return true;
    char c = static_cast<char>(std::tolower(static_cast<unsigned char>(hex_hash[static_cast<std::size_t>(full_nibbles)])));
    int v = 0;
    if (c >= '0' && c <= '9') v = c - '0';
    else if (c >= 'a' && c <= 'f') v = 10 + (c - 'a');
    else return false;
    int threshold = 1 << (4 - rem_bits);
    return v < threshold;
}

static bool verify_pow_solution(const NonceRec& rec, const std::string& nonce, long long counter, const std::string& client_hash) {
    if (counter < 0 || counter > 200000000LL) return false;
    if (rec.pow_bits < 8 || rec.pow_bits > 24) return false;
    if (rec.pow_salt.empty()) return false;
    std::string payload = nonce + "|" + rec.pow_salt + "|" + std::to_string(counter);
    std::string calc_hash = sha256_hex(payload);
    if (!hash_has_leading_zero_bits(calc_hash, rec.pow_bits)) return false;
    if (!client_hash.empty() && to_lower(trim(client_hash)) != calc_hash) return false;
    return true;
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

static bool parse_cidr_bits(const std::string& text, int& bits_out) {
    try {
        std::size_t idx = 0;
        int bits = std::stoi(trim(text), &idx);
        if (idx != trim(text).size()) return false;
        bits_out = bits;
        return true;
    } catch (...) {
        return false;
    }
}

static bool ipv4_in_cidr(const in_addr& net, int bits, const in_addr& ip) {
    if (bits <= 0) return true;
    if (bits > 32) return false;
    const uint32_t net_v = ntohl(net.s_addr);
    const uint32_t ip_v = ntohl(ip.s_addr);
    const uint32_t mask = (bits == 32) ? 0xFFFFFFFFu : (0xFFFFFFFFu << (32 - bits));
    return (net_v & mask) == (ip_v & mask);
}

static bool ipv6_in_cidr(const in6_addr& net, int bits, const in6_addr& ip) {
    if (bits <= 0) return true;
    if (bits > 128) return false;
    const int full_bytes = bits / 8;
    const int rem_bits = bits % 8;
    for (int i = 0; i < full_bytes; ++i) {
        if (net.s6_addr[i] != ip.s6_addr[i]) return false;
    }
    if (rem_bits == 0) return true;
    const unsigned char mask = static_cast<unsigned char>(0xFFu << (8 - rem_bits));
    return (net.s6_addr[full_bytes] & mask) == (ip.s6_addr[full_bytes] & mask);
}

static bool ip_or_cidr_matches_ip(const std::string& spec, const std::string& ip) {
    std::string s = trim(spec);
    std::string c = trim(ip);
    if (s.empty() || c.empty()) return false;
    if (s == c) return true;

    const std::size_t slash = s.find('/');
    if (slash == std::string::npos) return false;
    const std::string net_part = trim(s.substr(0, slash));
    const std::string bits_part = trim(s.substr(slash + 1));
    if (net_part.empty() || bits_part.empty()) return false;

    int bits = 0;
    if (!parse_cidr_bits(bits_part, bits)) return false;

    in_addr net4{}, ip4{};
    if (inet_pton(AF_INET, net_part.c_str(), &net4) == 1 &&
        inet_pton(AF_INET, c.c_str(), &ip4) == 1) {
        return ipv4_in_cidr(net4, bits, ip4);
    }

    in6_addr net6{}, ip6{};
    if (inet_pton(AF_INET6, net_part.c_str(), &net6) == 1 &&
        inet_pton(AF_INET6, c.c_str(), &ip6) == 1) {
        return ipv6_in_cidr(net6, bits, ip6);
    }

    return false;
}

static bool host_or_cidr_matches_ip(const std::string& host_or_ip, const std::string& ip) {
    std::string h = trim(host_or_ip);
    std::string c = trim(ip);
    if (h.empty() || c.empty()) return false;
    if (h == c) return true;
    if (ip_or_cidr_matches_ip(h, c)) return true;

    addrinfo hints{};
    hints.ai_family = AF_UNSPEC;
    hints.ai_socktype = SOCK_STREAM;
    addrinfo* res = nullptr;
    if (getaddrinfo(h.c_str(), nullptr, &hints, &res) != 0) return false;

    bool matched = false;
    char buf[INET6_ADDRSTRLEN] = {0};
    for (addrinfo* p = res; p != nullptr; p = p->ai_next) {
        if (p->ai_family == AF_INET) {
            auto* a4 = reinterpret_cast<sockaddr_in*>(p->ai_addr);
            if (inet_ntop(AF_INET, &a4->sin_addr, buf, sizeof(buf)) != nullptr && c == std::string(buf)) {
                matched = true;
                break;
            }
        } else if (p->ai_family == AF_INET6) {
            auto* a6 = reinterpret_cast<sockaddr_in6*>(p->ai_addr);
            if (inet_ntop(AF_INET6, &a6->sin6_addr, buf, sizeof(buf)) != nullptr && c == std::string(buf)) {
                matched = true;
                break;
            }
        }
    }
    freeaddrinfo(res);
    return matched;
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
            json db = j.value("database", json::object());
            json ptlc = j.value("ptlc", json::object());
            s.enabled = parse_bool(net.value("waf_challenge_enabled", json(true)), true);
            s.strict_mode = parse_bool(net.value("waf_challenge_strict_mode", json(true)), true);
            s.provider_token_gate_enabled = parse_bool(net.value("provider_token_gate_enabled", json(false)), false);
            s.bind = json_get_string(net, "waf_challenge_bind", "127.0.0.1");
            s.port = std::max(1, std::min(65535, json_get_int(net, "waf_challenge_port", 18444)));
            s.ttl = std::max(60, std::min(86400, json_get_int(net, "waf_challenge_ttl_sec", 1800)));
            s.pow_bits = std::max(8, std::min(24, json_get_int(net, "waf_pow_bits", 14)));
            s.cookie_name = trim(json_get_string(net, "waf_challenge_cookie_name", "pp_clearance"));
            if (s.cookie_name.empty()) s.cookie_name = "pp_clearance";
            s.secret = trim(json_get_string(net, "waf_challenge_secret", ""));
            if (s.secret.empty()) {
                s.secret = trim(json_get_string(net, "unblock_portal_token", ""));
            }
            if (net.contains("trusted_hosts")) {
                if (net["trusted_hosts"].is_array()) {
                    for (const auto& item : net["trusted_hosts"]) {
                        if (!item.is_string()) continue;
                        std::string host = trim(item.get<std::string>());
                        if (!host.empty()) s.trusted_hosts.push_back(host);
                    }
                } else if (net["trusted_hosts"].is_string()) {
                    std::stringstream ss(net["trusted_hosts"].get<std::string>());
                    std::string part;
                    while (std::getline(ss, part, ',')) {
                        part = trim(part);
                        if (!part.empty()) s.trusted_hosts.push_back(part);
                    }
                }
            }
            auto append_provider_cidrs = [&](const json& node) {
                if (node.is_array()) {
                    for (const auto& item : node) {
                        if (!item.is_string()) continue;
                        std::string cidr = trim(item.get<std::string>());
                        if (!cidr.empty()) s.provider_token_cidrs.push_back(cidr);
                    }
                } else if (node.is_string()) {
                    append_csv_parts(node.get<std::string>(), s.provider_token_cidrs);
                }
            };
            if (net.contains("provider_token_ipv4_cidrs")) append_provider_cidrs(net["provider_token_ipv4_cidrs"]);
            if (net.contains("provider_token_ipv6_cidrs")) append_provider_cidrs(net["provider_token_ipv6_cidrs"]);
            s.provider_token_cache_file = trim(json_get_string(net, "provider_token_cache_file", "/pteroprotect/cache/provider_ranges.txt"));
            s.provider_token_ip_cache_file = trim(json_get_string(net, "provider_token_ip_cache_file", "/pteroprotect/cache/provider_ip_cache.json"));
            s.provider_token_ip_cache_ttl_sec = std::max(300, std::min(2592000, json_get_int(net, "provider_token_ip_cache_ttl_sec", 604800)));
            if (net.contains("provider_token_provider_keywords")) {
                if (net["provider_token_provider_keywords"].is_array()) {
                    for (const auto& item : net["provider_token_provider_keywords"]) {
                        if (!item.is_string()) continue;
                        std::string keyword = to_lower(trim(item.get<std::string>()));
                        if (!keyword.empty()) s.provider_token_provider_keywords.push_back(keyword);
                    }
                } else if (net["provider_token_provider_keywords"].is_string()) {
                    std::vector<std::string> parts;
                    append_csv_parts(net["provider_token_provider_keywords"].get<std::string>(), parts);
                    for (const auto& part : parts) s.provider_token_provider_keywords.push_back(to_lower(part));
                }
            }
            if (!s.provider_token_cache_file.empty()) {
                append_provider_cidrs_from_file(s.provider_token_cache_file, s.provider_token_cidrs);
            }
            s.db_host = trim(db.value("host", std::string("127.0.0.1")));
            if (s.db_host.empty()) s.db_host = "127.0.0.1";
            s.db_user = trim(db.value("user", std::string("")));
            s.db_password = db.value("password", std::string(""));
            s.db_name = trim(db.value("name", std::string("")));
            s.panel_app_key = trim(ptlc.value("app_key", std::string("")));
        } catch (...) {
            // keep defaults
        }
    }
    std::map<std::string, std::string> panel_env = parse_env_file("/var/www/pterodactyl/.env");
    if (s.db_host.empty()) s.db_host = "127.0.0.1";
    if (s.db_user.empty()) {
        auto it = panel_env.find("DB_USERNAME");
        if (it != panel_env.end()) s.db_user = trim(it->second);
    }
    if (s.db_password.empty()) {
        auto it = panel_env.find("DB_PASSWORD");
        if (it != panel_env.end()) s.db_password = it->second;
    }
    if (s.db_name.empty()) {
        auto it = panel_env.find("DB_DATABASE");
        if (it != panel_env.end()) s.db_name = trim(it->second);
    }
    if (s.panel_app_key.empty()) {
        auto it = panel_env.find("APP_KEY");
        if (it != panel_env.end()) s.panel_app_key = trim(it->second);
    }
    if (s.secret.empty()) {
        const char* env_secret = std::getenv("PTEROPROTECT_WAF_CHALLENGE_SECRET");
        if (env_secret && *env_secret) s.secret = trim(env_secret);
    }
    if (s.secret.empty()) {
        const char* env_token = std::getenv("PTEROPROTECT_UNBLOCK_PORTAL_TOKEN");
        if (env_token && *env_token) s.secret = trim(env_token);
    }
    if (s.secret.empty()) {
        if (g_ephemeral_secret.empty()) g_ephemeral_secret = random_nonce();
        s.secret = g_ephemeral_secret;
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

static bool is_ip_literal(const std::string& ip) {
    sockaddr_in sa4{};
    if (inet_pton(AF_INET, ip.c_str(), &sa4.sin_addr) == 1) return true;
    sockaddr_in6 sa6{};
    return inet_pton(AF_INET6, ip.c_str(), &sa6.sin6_addr) == 1;
}

static bool is_loopback_proxy_ip(const std::string& ip) {
    std::string t = trim(ip);
    return t == "127.0.0.1" || t == "::1" || t == "::ffff:127.0.0.1";
}

static std::string resolve_client_ip(const HttpRequest& req) {
    std::string peer = trim(req.remote_ip);
    if (is_loopback_proxy_ip(peer)) {
        auto it_real = req.headers.find("x-real-ip");
        if (it_real != req.headers.end()) {
            std::string v = first_xff_ip(it_real->second);
            if (!v.empty() && is_ip_literal(v)) return v;
        }
        auto it_xff = req.headers.find("x-forwarded-for");
        if (it_xff != req.headers.end()) {
            std::string v = first_xff_ip(it_xff->second);
            if (!v.empty() && is_ip_literal(v)) return v;
        }
    }
    return is_ip_literal(peer) ? peer : "127.0.0.1";
}

static bool is_ipv4_literal(const std::string& ip) {
    sockaddr_in sa{};
    return inet_pton(AF_INET, ip.c_str(), &sa.sin_addr) == 1;
}

static int connect_ipv4_with_timeout(const std::string& ip, int port, int timeout_ms) {
    int fd = socket(AF_INET, SOCK_STREAM, 0);
    if (fd < 0) return -1;

    int flags = fcntl(fd, F_GETFL, 0);
    if (flags >= 0) (void)fcntl(fd, F_SETFL, flags | O_NONBLOCK);

    sockaddr_in dst{};
    dst.sin_family = AF_INET;
    dst.sin_port = htons(static_cast<uint16_t>(port));
    if (inet_pton(AF_INET, ip.c_str(), &dst.sin_addr) != 1) {
        close(fd);
        return -1;
    }

    int rc = connect(fd, reinterpret_cast<sockaddr*>(&dst), sizeof(dst));
    if (rc != 0 && errno != EINPROGRESS) {
        close(fd);
        return -1;
    }

    fd_set wfds;
    FD_ZERO(&wfds);
    FD_SET(fd, &wfds);
    timeval tv{};
    tv.tv_sec = timeout_ms / 1000;
    tv.tv_usec = (timeout_ms % 1000) * 1000;
    rc = select(fd + 1, nullptr, &wfds, nullptr, &tv);
    if (rc <= 0) {
        close(fd);
        return -1;
    }

    int soerr = 0;
    socklen_t soerr_len = sizeof(soerr);
    if (getsockopt(fd, SOL_SOCKET, SO_ERROR, &soerr, &soerr_len) != 0 || soerr != 0) {
        close(fd);
        return -1;
    }

    if (flags >= 0) (void)fcntl(fd, F_SETFL, flags);
    timeval io_tv{};
    io_tv.tv_sec = timeout_ms / 1000;
    io_tv.tv_usec = (timeout_ms % 1000) * 1000;
    (void)setsockopt(fd, SOL_SOCKET, SO_RCVTIMEO, &io_tv, sizeof(io_tv));
    (void)setsockopt(fd, SOL_SOCKET, SO_SNDTIMEO, &io_tv, sizeof(io_tv));
    return fd;
}

static bool response_indicates_server_or_html(const std::string& response) {
    std::string low = to_lower(response);
    const bool server_banner =
        (low.find("\nserver: nginx") != std::string::npos) ||
        (low.find("\nserver: apache") != std::string::npos) ||
        (low.find("\nserver: openresty") != std::string::npos);
    const bool html_like =
        (low.find("content-type: text/html") != std::string::npos) ||
        (low.find("<!doctype html") != std::string::npos) ||
        (low.find("<html") != std::string::npos) ||
        (low.find("<head") != std::string::npos) ||
        (low.find("<body") != std::string::npos);
    return server_banner || html_like;
}

static bool probe_http_plain(const std::string& ip, int port) {
    int fd = connect_ipv4_with_timeout(ip, port, 450);
    if (fd < 0) return false;

    std::string req = "GET / HTTP/1.0\r\nHost: " + ip + "\r\nConnection: close\r\n\r\n";
    (void)send(fd, req.data(), req.size(), 0);
    char buf[4096];
    ssize_t n = recv(fd, buf, sizeof(buf), 0);
    close(fd);
    if (n <= 0) return false;
    return response_indicates_server_or_html(std::string(buf, buf + n));
}

static bool probe_http_tls(const std::string& ip, int port) {
    int fd = connect_ipv4_with_timeout(ip, port, 650);
    if (fd < 0) return false;

    SSL_CTX* ctx = SSL_CTX_new(TLS_client_method());
    if (!ctx) {
        close(fd);
        return false;
    }
    SSL_CTX_set_verify(ctx, SSL_VERIFY_NONE, nullptr);
    SSL* ssl = SSL_new(ctx);
    if (!ssl) {
        SSL_CTX_free(ctx);
        close(fd);
        return false;
    }
    (void)SSL_set_tlsext_host_name(ssl, ip.c_str());
    SSL_set_fd(ssl, fd);
    if (SSL_connect(ssl) <= 0) {
        SSL_free(ssl);
        SSL_CTX_free(ctx);
        close(fd);
        return false;
    }

    std::string req = "GET / HTTP/1.0\r\nHost: " + ip + "\r\nConnection: close\r\n\r\n";
    (void)SSL_write(ssl, req.data(), static_cast<int>(req.size()));
    char buf[4096];
    int n = SSL_read(ssl, buf, sizeof(buf));
    SSL_shutdown(ssl);
    SSL_free(ssl);
    SSL_CTX_free(ctx);
    close(fd);
    if (n <= 0) return false;
    return response_indicates_server_or_html(std::string(buf, buf + n));
}

[[maybe_unused]] static bool probe_client_http_server_banner(const std::string& ip) {
    if (!is_ipv4_literal(ip)) return false;
    if (probe_http_plain(ip, 80)) return true;
    if (probe_http_tls(ip, 443)) return true;
    return false;
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
    int min_ms = std::max(1000, rec.min_connection_ms);
    // Once click handshake is verified, keep behavior gate light to avoid blocking legit users.
    if (rec.click_verified) {
        min_ms = 300;
    }
    if (effective_ms < min_ms) return false;

    if (rec.click_verified) {
        return true;
    }

    const bool pointer_ok = (pointer_moves >= 2 && pointer_distance >= 60 && pointer_dir_changes >= 1);
    const bool touch_ok = (touch_moves >= 1);
    const bool fallback_ok = (scroll_count >= 1 || key_count >= 1 || pointer_moves >= 1);
    return pointer_ok || touch_ok || fallback_ok;
}

static bool ua_declared_browser(const std::string& ua) {
    if (ua.size() < 8 || ua.size() > 1200) return false;
    const std::string low = to_lower(ua);
    static const std::vector<std::string> blocked = {
        "curl/", "wget/", "python-requests", "go-http-client", "libwww-perl",
        "java/", "aiohttp", "httpclient", "axios/", "node-fetch", "scrapy", "postmanruntime",
        "headless", "headlesschrome", "puppeteer", "playwright", "selenium", "phantomjs"
    };
    for (const auto& bad : blocked) {
        if (low.find(bad) != std::string::npos) return false;
    }
    if (low.find("mozilla/5.0") == std::string::npos &&
        low.find("mozilla/") == std::string::npos &&
        low.find("applewebkit/") == std::string::npos &&
        low.find("gecko/") == std::string::npos) {
        return false;
    }
    static const std::vector<std::string> browsers = {
        "chrome/", "crios/", "edg/", "edga/", "edgios/", "firefox/", "fxios/",
        "safari/", "opr/", "opera/", "samsungbrowser/", "miuibrowser/", "huaweibrowser/",
        "duckduckgo/", "brave/", "vivaldi/"
    };
    for (const auto& tok : browsers) {
        if (low.find(tok) != std::string::npos) return true;
    }
    // Allow common mobile webview style signatures after bot token filtering.
    if (low.find("mobile") != std::string::npos &&
        (low.find("version/") != std::string::npos || low.find("wv") != std::string::npos)) {
        return true;
    }
    if (low.find("mozilla/") != std::string::npos &&
        (low.find("applewebkit/") != std::string::npos || low.find("gecko/") != std::string::npos)) {
        return true;
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
        {0, 1, 2, 5, 8},
        {6, 7, 8, 5, 2},
        {0, 3, 6, 7, 8},
        {2, 1, 4, 7, 6},
        {0, 4, 5, 2}
    };
    std::uniform_int_distribution<int> tdis(0, static_cast<int>(templates.size() - 1));
    std::uniform_int_distribution<int> rdis(0, 3);
    std::uniform_int_distribution<int> fdis(0, 1);
    std::uniform_int_distribution<int> len_dis(5, 6);
    std::vector<int> out;
    const auto& base = templates[static_cast<std::size_t>(tdis(gen))];
    int rot = rdis(gen);
    bool flip = fdis(gen) == 1;

    auto can_append = [&](int cand) -> bool {
        if (!out.empty() && out.back() == cand) return false;
        if (out.size() >= 2 && out[out.size() - 2] == cand) return false; // block A-B-A bounce.
        return true;
    };

    for (int n : base) {
        int v = transform_grid_node(n, rot, flip);
        if (can_append(v)) out.push_back(v);
    }

    const int target_len = std::min<int>(len_dis(gen), static_cast<int>(base.size()));
    if (static_cast<int>(out.size()) > target_len) out.resize(static_cast<std::size_t>(target_len));

    std::uniform_int_distribution<int> node_dis(0, 8);
    for (int i = 0; static_cast<int>(out.size()) < target_len && i < 32; ++i) {
        int cand = node_dis(gen);
        if (can_append(cand)) out.push_back(cand);
    }

    if (out.size() < 5) out = {0, 1, 2, 5, 8};
    return out;
}

static bool pattern_pass(const json& pattern, const NonceRec& rec) {
    if (rec.pattern_seq.empty()) return false;
    if (!pattern.is_object()) return false;
    std::vector<int> expected = split_ints_dash(rec.pattern_seq);
    if (expected.empty()) return false;

    if (pattern.contains("clicked_nodes") && pattern["clicked_nodes"].is_array()) {
        const json& arr = pattern["clicked_nodes"];
        if (arr.size() < expected.size() || arr.size() > expected.size() + 8) return false;
        std::vector<int> got;
        got.reserve(arr.size());
        for (const auto& v : arr) {
            if (!v.is_number_integer()) return false;
            int n = v.get<int>();
            if (n < 1 || n > 9) return false;
            got.push_back(n - 1);
        }
        for (std::size_t k = 1; k < got.size(); ++k) {
            if (got[k] == got[k - 1]) return false;
            if (k >= 2 && got[k] == got[k - 2]) return false; // reject A-B-A bounce (e.g. 5-6-5)
        }
        std::size_t i = 0;
        for (int v : got) {
            if (i < expected.size() && v == expected[i]) {
                i++;
                continue;
            }
            if (i > 0 && v == expected[i - 1]) continue;
            // Tolerate accidental extra taps; only enforce ordered subsequence.
            continue;
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
    for (std::size_t k = 2; k < visited.size(); ++k) {
        if (visited[k] == visited[k - 2]) return false; // reject ambiguous A-B-A bounce from pointer trace
    }
    std::size_t i = 0;
    for (int v : visited) {
        if (i < expected.size() && v == expected[i]) {
            i++;
            continue;
        }
        if (i > 0 && v == expected[i - 1]) {
            continue; // tolerate duplicate hovering on current target node
        }
        // Tolerate noisy pointer traces around the target nodes.
        continue;
    }
    return i == expected.size();
}

static std::string pattern_hint_text(const std::vector<int>& nodes) {
    auto number_word = [](int n) -> std::string {
        switch (n) {
            case 1: return "satu";
            case 2: return "dua";
            case 3: return "tiga";
            case 4: return "empat";
            case 5: return "lima";
            case 6: return "enam";
            case 7: return "tujuh";
            case 8: return "delapan";
            case 9: return "sembilan";
            default: return "";
        }
    };

    auto obfuscate_word = [](const std::string& in) -> std::string {
        std::random_device rd;
        std::mt19937 gen(rd());
        std::uniform_int_distribution<int> p(0, 99);
        std::vector<std::string> out;
        out.reserve(in.size());
        bool changed = false;

        for (char ch : in) {
            char c = static_cast<char>(std::tolower(static_cast<unsigned char>(ch)));
            std::string repl(1, c);
            std::vector<std::string> candidates;
            switch (c) {
                case 'a': candidates = {"4", "@"}; break;
                case 'e': candidates = {"3"}; break;
                case 'i': candidates = {"1", "!"}; break;
                case 'o': candidates = {"0"}; break;
                case 's': candidates = {"5", "$"}; break;
                case 't': candidates = {"7"}; break;
                case 'b': candidates = {"8"}; break;
                case 'g': candidates = {"9"}; break;
                case 'l': candidates = {"1"}; break;
                case 'z': candidates = {"2"}; break;
                default: break;
            }
            if (!candidates.empty() && p(gen) < 55) {
                repl = candidates[static_cast<std::size_t>(p(gen) % static_cast<int>(candidates.size()))];
                changed = true;
            }
            out.push_back(repl);
        }

        if (!changed) {
            for (std::size_t i = 0; i < out.size(); ++i) {
                if (out[i] == "a") { out[i] = "4"; changed = true; break; }
                if (out[i] == "i") { out[i] = "1"; changed = true; break; }
                if (out[i] == "e") { out[i] = "3"; changed = true; break; }
            }
        }

        std::string joined;
        for (const auto& s : out) joined += s;
        return joined;
    };

    std::ostringstream oss;
    oss << "Ikuti urutan kata: ";
    for (std::size_t i = 0; i < nodes.size(); ++i) {
        int n = nodes[i];
        if (n < 0 || n > 8) continue;
        if (i > 0) oss << " -> ";
        std::string w = number_word(n + 1);
        if (w.empty()) {
            oss << (n + 1);
        } else {
            oss << obfuscate_word(w);
        }
    }
    return oss.str();
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

static bool secure_equals(const std::string& a, const std::string& b) {
    if (a.size() != b.size()) return false;
    unsigned char diff = 0;
    for (std::size_t i = 0; i < a.size(); ++i) {
        diff |= static_cast<unsigned char>(a[i] ^ b[i]);
    }
    return diff == 0;
}

static bool is_loopback_ip(const std::string& ip) {
    std::string t = trim(ip);
    return t == "127.0.0.1" || t == "::1" || t == "::ffff:127.0.0.1";
}

static bool is_private_or_cgnat_ip(const std::string& ip) {
    in_addr a4{};
    if (inet_pton(AF_INET, ip.c_str(), &a4) == 1) {
        const uint32_t host = ntohl(a4.s_addr);
        if ((host & 0xFF000000u) == 0x0A000000u) return true;        // 10.0.0.0/8
        if ((host & 0xFFF00000u) == 0xAC100000u) return true;        // 172.16.0.0/12
        if ((host & 0xFFFF0000u) == 0xC0A80000u) return true;        // 192.168.0.0/16
        if ((host & 0xFFC00000u) == 0x64400000u) return true;        // 100.64.0.0/10
        if ((host & 0xFFFF0000u) == 0xA9FE0000u) return true;        // 169.254.0.0/16
    }

    in6_addr a6{};
    if (inet_pton(AF_INET6, ip.c_str(), &a6) == 1) {
        if ((a6.s6_addr[0] & 0xFE) == 0xFC) return true;             // fc00::/7
        if (a6.s6_addr[0] == 0xFE && (a6.s6_addr[1] & 0xC0) == 0x80) return true; // fe80::/10
    }
    return false;
}

static bool ip_geo_similar(const std::string& prev_ip_raw, const std::string& now_ip_raw) {
    const std::string prev_ip = trim(prev_ip_raw);
    const std::string now_ip = trim(now_ip_raw);
    if (prev_ip.empty() || now_ip.empty()) return false;
    if (prev_ip == now_ip) return true;
    if (is_loopback_ip(prev_ip) || is_loopback_ip(now_ip)) return true;

    in_addr p4{}, n4{};
    if (inet_pton(AF_INET, prev_ip.c_str(), &p4) == 1 &&
        inet_pton(AF_INET, now_ip.c_str(), &n4) == 1) {
        const uint32_t pa = ntohl(p4.s_addr);
        const uint32_t na = ntohl(n4.s_addr);
        // "Geo mirip" heuristic for IPv4: same /16 (or private/CGNAT family).
        if ((pa & 0xFFFF0000u) == (na & 0xFFFF0000u)) return true;
        if (is_private_or_cgnat_ip(prev_ip) && is_private_or_cgnat_ip(now_ip)) return true;
        return false;
    }

    in6_addr p6{}, n6{};
    if (inet_pton(AF_INET6, prev_ip.c_str(), &p6) == 1 &&
        inet_pton(AF_INET6, now_ip.c_str(), &n6) == 1) {
        // "Geo mirip" heuristic for IPv6: same /48.
        if (p6.s6_addr[0] != n6.s6_addr[0] ||
            p6.s6_addr[1] != n6.s6_addr[1] ||
            p6.s6_addr[2] != n6.s6_addr[2] ||
            p6.s6_addr[3] != n6.s6_addr[3] ||
            p6.s6_addr[4] != n6.s6_addr[4] ||
            p6.s6_addr[5] != n6.s6_addr[5]) {
            return false;
        }
        return true;
    }

    // Different IP family (IPv4 <-> IPv6): treat as not similar.
    return false;
}

static bool is_provider_range_ip(const Settings& s, const std::string& ip) {
    if (!s.provider_token_gate_enabled) return false;
    for (const auto& cidr : s.provider_token_cidrs) {
        if (host_or_cidr_matches_ip(cidr, ip)) return true;
    }
    if (is_loopback_ip(ip)) return false;
    for (char c : ip) {
        const bool ok = std::isdigit(static_cast<unsigned char>(c)) ||
            (c >= 'a' && c <= 'f') || (c >= 'A' && c <= 'F') || c == '.' || c == ':';
        if (!ok) return false;
    }
    std::string cmd = "/usr/bin/python3 /pteroprotect/scripts/provider_ip_lookup.py " + g_cfg_path + " " + ip + " 2>/dev/null";
    FILE* pipe = popen(cmd.c_str(), "r");
    if (!pipe) return false;
    char buf[64] = {0};
    std::string output;
    if (fgets(buf, sizeof(buf), pipe) != nullptr) output = trim(buf);
    pclose(pipe);
    return output == "provider";
}

static std::string session_scope_key(const std::string& ip, const std::string& ua_fp) {
    return ip + "|" + ua_fp;
}

static bool has_valid_auth_token_header(const Settings& s, const HttpRequest& req, const std::string& client_ip) {
    auto internal_it = req.headers.find("x-pteroprotect-internal");
    const bool internal_marked = (internal_it != req.headers.end() && trim(internal_it->second) == "1");

    // Allow panel->wings internal daemon polling only when real client IP is localhost.
    auto ua_it = req.headers.find("user-agent");
    std::string ua = (ua_it != req.headers.end()) ? to_lower(ua_it->second) : "";
    if (internal_marked && is_loopback_ip(client_ip) && ua.find("guzzlehttp/") != std::string::npos) {
        return true;
    }

    // Accept valid panel API bearer even if x-pteroprotect-internal header is missing.
    auto it = req.headers.find("authorization");
    if (it != req.headers.end()) {
        std::string v = trim(it->second);
        std::string low = to_lower(v);
        const std::string pre = "bearer ";
        if (low.size() > pre.size() && low.compare(0, pre.size(), pre) == 0) {
            std::string tok = trim(v.substr(pre.size()));
            return is_valid_panel_api_bearer(s, tok);
        }
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
        const std::string token_ip = p.value("ip", std::string());
        const std::string token_ua = p.value("ua", std::string());
        if (token_ip.empty() || token_ua.empty()) return false;
        {
            std::lock_guard<std::mutex> lock(g_session_mu);
            const std::time_t now = std::time(nullptr);
            auto valid_entry = [&](const SessionRec& sr, const std::string& expect_ua) -> bool {
                return sr.sid == sid && sr.ua == expect_ua && sr.exp >= now;
            };

            // Strict binding path.
            auto cur_it = g_ip_session_map.find(session_scope_key(ip, ua_fp));
            if (cur_it != g_ip_session_map.end() && valid_entry(cur_it->second, ua_fp)) {
                return true;
            }

            // Tolerant path for edge-IP/UA drift: if original token scope session
            // is still valid, migrate it to current tuple.
            auto tok_it = g_ip_session_map.find(session_scope_key(token_ip, token_ua));
            if (tok_it != g_ip_session_map.end() && valid_entry(tok_it->second, token_ua)) {
                if (token_ip == ip || (token_ua == ua_fp && ip_geo_similar(token_ip, ip))) {
                    SessionRec migrated = tok_it->second;
                    migrated.ua = ua_fp;
                    g_ip_session_map[session_scope_key(ip, ua_fp)] = migrated;
                    return true;
                }
            }
            return false;
        }
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
    std::string ip = resolve_client_ip(req);
    std::string ua = req.headers.count("user-agent") ? req.headers["user-agent"] : "";
    std::string ua_fp = sha256_hex_24(ua_binding_material(ua));

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

    if (req.path == "/check-web") {
        if (!s.enabled) {
            send_response(fd, 204, "No Content", "", {}, head_only);
            close(fd);
            return;
        }
        if (is_provider_range_ip(s, ip)) {
            send_response(fd, 403, "Forbidden", "", {}, head_only);
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
        const bool token_ok = has_valid_auth_token_header(s, req, ip);
        if (token_ok) {
            send_response(fd, 204, "No Content", "", {}, head_only);
        } else {
            send_response(fd, 401, "Unauthorized", "", {}, head_only);
        }
        close(fd);
        return;
    }

    if (req.path == "/check-provider-api") {
        if (!s.enabled) {
            send_response(fd, 204, "No Content", "", {}, head_only);
            close(fd);
            return;
        }
        if (!is_provider_range_ip(s, ip)) {
            send_response(fd, 204, "No Content", "", {}, head_only);
            close(fd);
            return;
        }
        const bool token_ok = has_valid_auth_token_header(s, req, ip);
        if (token_ok) {
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

        std::map<std::string, std::string> nq = parse_query(req.query);
        int hinted_hc = 0;
        double hinted_dm = 0.0;
        bool hinted_mobile = false;
        {
            auto it = nq.find("hc");
            if (it != nq.end()) {
                try { hinted_hc = std::stoi(trim(it->second)); } catch (...) { hinted_hc = 0; }
            }
            it = nq.find("dm");
            if (it != nq.end()) {
                try { hinted_dm = std::stod(trim(it->second)); } catch (...) { hinted_dm = 0.0; }
            }
            it = nq.find("m");
            if (it != nq.end()) {
                const std::string mv = to_lower(trim(it->second));
                hinted_mobile = (mv == "1" || mv == "true" || mv == "yes" || mv == "y");
            }
        }
        hinted_hc = std::max(0, std::min(64, hinted_hc));
        if (hinted_dm < 0.0) hinted_dm = 0.0;
        if (hinted_dm > 64.0) hinted_dm = 64.0;
        const bool ua_mobile = ua_mobile_like(ua);
        if (hinted_mobile && !ua_mobile) hinted_mobile = false;
        if (!hinted_mobile && ua_mobile) hinted_mobile = true;
        const bool suspicious_low_power_hint = (!ua_mobile) &&
            ((hinted_hc > 0 && hinted_hc <= 2) || (hinted_dm > 0.0 && hinted_dm <= 2.0));

        int adaptive_pow_bits = s.pow_bits;
        if (hinted_hc > 0) {
            if (hinted_hc <= 2) adaptive_pow_bits -= 3;
            else if (hinted_hc <= 4) adaptive_pow_bits -= 2;
            else if (hinted_hc <= 6) adaptive_pow_bits -= 1;
            else if (hinted_hc >= 12) adaptive_pow_bits += 1;
        }
        if (hinted_dm > 0.0 && hinted_dm <= 2.0) adaptive_pow_bits -= 1;
        if (hinted_mobile) adaptive_pow_bits -= 1;
        if (suspicious_low_power_hint) adaptive_pow_bits = std::max(adaptive_pow_bits + 2, s.pow_bits + 1);
        const int min_pow_bits = s.strict_mode ? std::max(12, s.pow_bits - 1) : 8;
        adaptive_pow_bits = std::max(min_pow_bits, std::min(24, adaptive_pow_bits));

        int wait_min_ms = 8 * 1000;
        int wait_max_ms = 20 * 1000;
        if (hinted_hc > 0) {
            if (hinted_hc <= 2) {
                wait_min_ms = 4 * 1000;
                wait_max_ms = 10 * 1000;
            } else if (hinted_hc <= 4) {
                wait_min_ms = 6 * 1000;
                wait_max_ms = 14 * 1000;
            } else if (hinted_hc >= 12) {
                wait_min_ms = 10 * 1000;
                wait_max_ms = 22 * 1000;
            }
        }
        if (hinted_mobile) {
            wait_min_ms = std::max(3 * 1000, wait_min_ms - 1500);
            wait_max_ms = std::max(wait_min_ms + 1000, wait_max_ms - 2500);
        }
        int adaptive_pow_mem_mb = 48;
        if (hinted_dm > 0.0) {
            adaptive_pow_mem_mb = static_cast<int>(std::round(hinted_dm * 1024.0 * 0.10));
        }
        if (hinted_mobile) adaptive_pow_mem_mb = std::min(adaptive_pow_mem_mb, 64);
        else adaptive_pow_mem_mb = std::min(adaptive_pow_mem_mb, 192);
        if (hinted_hc > 0 && hinted_hc <= 2) adaptive_pow_mem_mb = std::min(adaptive_pow_mem_mb, 48);
        if (suspicious_low_power_hint) adaptive_pow_mem_mb = std::max(adaptive_pow_mem_mb, 128);
        if (s.strict_mode) adaptive_pow_mem_mb = std::max(adaptive_pow_mem_mb, hinted_mobile ? 32 : 64);
        adaptive_pow_mem_mb = std::max(8, adaptive_pow_mem_mb);
        int adaptive_pow_cpu_level = 4;
        if (hinted_hc > 0) {
            if (hinted_hc <= 2) adaptive_pow_cpu_level = 2;
            else if (hinted_hc <= 4) adaptive_pow_cpu_level = 3;
            else if (hinted_hc <= 6) adaptive_pow_cpu_level = 4;
            else if (hinted_hc <= 10) adaptive_pow_cpu_level = 5;
            else adaptive_pow_cpu_level = 6;
        }
        if (hinted_mobile) adaptive_pow_cpu_level = std::max(2, adaptive_pow_cpu_level - 1);
        if (suspicious_low_power_hint) adaptive_pow_cpu_level = std::max(adaptive_pow_cpu_level, 5);
        if (s.strict_mode) adaptive_pow_cpu_level = std::max(adaptive_pow_cpu_level, hinted_mobile ? 3 : 4);
        adaptive_pow_cpu_level = std::max(1, std::min(8, adaptive_pow_cpu_level));

        std::random_device rd;
        std::mt19937 gen(rd());
        std::uniform_int_distribution<int> num_a(250, 2800);
        std::uniform_int_distribution<int> num_b(250, 2800);
        std::uniform_int_distribution<int> num_c(120, 1800);
        std::uniform_int_distribution<int> opdis(0, 1);
        std::uniform_int_distribution<int> wait_human_ms(wait_min_ms, wait_max_ms);
        int a = 0, b = 0, c = 0;
        bool plus = true;
        long long ans = 0;
        bool picked = false;
        for (int tries = 0; tries < 128; ++tries) {
            a = num_a(gen);
            b = num_b(gen);
            c = num_c(gen);
            plus = opdis(gen) == 0;
            ans = plus ? (static_cast<long long>(a) + static_cast<long long>(b) - c)
                       : (static_cast<long long>(a) - static_cast<long long>(b) + c);
            if (ans >= 100 && ans <= 9999) {
                picked = true;
                break;
            }
        }
        if (!picked) {
            a = 1500;
            b = 900;
            c = 400;
            plus = true;
            ans = static_cast<long long>(a) + static_cast<long long>(b) - c; // 2000
        }
        std::string nonce = random_nonce();
        NonceRec rec;
        std::vector<int> pattern_nodes = generate_pattern_nodes(gen);
        {
            std::lock_guard<std::mutex> lock(g_nonce_mu);
            rec.ans = std::to_string(ans);
            rec.ip = ip;
            rec.ua = ua_fp;
            rec.answer_key = "ans_" + random_token(6);
            rec.click_key = "clk_" + random_token(6);
            rec.behavior_key = "beh_" + random_token(6);
            rec.connection_key = "conn_" + random_token(6);
            rec.pattern_key = "pat_" + random_token(6);
            rec.pow_salt = "pow_" + random_token(12);
            rec.pow_bits = adaptive_pow_bits;
            rec.pattern_seq = join_ints_dash(pattern_nodes);
            rec.min_connection_ms = wait_human_ms(gen);
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
        out["click_key"] = rec.click_key;
        out["behavior_key"] = rec.behavior_key;
        out["connection_key"] = rec.connection_key;
        out["pattern_key"] = rec.pattern_key;
        out["pow_salt"] = rec.pow_salt;
        out["pow_bits"] = rec.pow_bits;
        out["pow_mem_mb"] = adaptive_pow_mem_mb;
        out["pow_cpu_level"] = adaptive_pow_cpu_level;
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
        if (!found || rec.exp < std::time(nullptr)) {
            send_response(fd, 401, "Unauthorized", "{\"ok\":false,\"error\":\"nonce_invalid\"}", {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
            close(fd);
            return;
        }
        if (rec.ua != ua_fp) {
            if (!rec.click_verified) {
                send_response(fd, 401, "Unauthorized", "{\"ok\":false,\"error\":\"nonce_invalid\"}", {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
                close(fd);
                return;
            }
            std::lock_guard<std::mutex> lock(g_nonce_mu);
            auto it = g_nonce_map.find(nonce);
            if (it != g_nonce_map.end()) {
                it->second.ua = ua_fp;
                rec.ua = ua_fp;
            }
        }
        if (rec.ip != ip) {
            // Mobile/CDN paths can shift IP during the challenge window.
            if (!rec.click_verified) {
                send_response(fd, 401, "Unauthorized", "{\"ok\":false,\"error\":\"nonce_invalid\"}", {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
                close(fd);
                return;
            }
            if (!ip_geo_similar(rec.ip, ip)) {
                send_response(fd, 401, "Unauthorized", "{\"ok\":false,\"error\":\"nonce_invalid\"}", {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
                close(fd);
                return;
            }
            std::lock_guard<std::mutex> lock(g_nonce_mu);
            auto it = g_nonce_map.find(nonce);
            if (it != g_nonce_map.end() && it->second.ua == ua_fp) {
                it->second.ip = ip;
                rec.ip = ip;
            }
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
            int attempts_left = 0;
            bool exceeded = false;
            std::lock_guard<std::mutex> lock(g_nonce_mu);
            auto it = g_nonce_map.find(nonce);
            if (it != g_nonce_map.end()) {
                it->second.math_fail_count++;
                attempts_left = std::max(0, 5 - it->second.math_fail_count);
                if (it->second.math_fail_count >= 5) {
                    exceeded = true;
                    g_nonce_map.erase(it);
                }
            }
            if (exceeded) {
                send_response(fd, 401, "Unauthorized", "{\"ok\":false,\"error\":\"math_attempts_exceeded\"}", {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
            } else {
                json out = json::object();
                out["ok"] = false;
                out["error"] = "answer_wrong";
                out["attempts_left"] = attempts_left;
                send_response(fd, 401, "Unauthorized", out.dump(), {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
            }
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

    if (req.path == "/click" && req.method == "POST") {
        if (!s.enabled) {
            send_response(fd, 200, "OK", "{\"ok\":true}", {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
            close(fd);
            return;
        }
        json in = json::object();
        std::string nonce;
        std::string click_value;
        try {
            in = json::parse(req.body);
            nonce = trim(in.value("nonce", std::string()));
            click_value = trim(in.value("click", std::string()));
        } catch (...) {
            send_response(fd, 400, "Bad Request", "{\"ok\":false,\"error\":\"invalid_json\"}", {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
            close(fd);
            return;
        }
        if (nonce.empty() || click_value.empty()) {
            send_response(fd, 401, "Unauthorized", "{\"ok\":false,\"error\":\"click_invalid\"}", {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
            close(fd);
            return;
        }
        const int click_rate_limit_per_sec = ua_mobile_like(ua) ? 30 : 10;
        bool ok = false;
        bool rate_limited = false;
        {
            std::lock_guard<std::mutex> lock(g_nonce_mu);
            auto it = g_nonce_map.find(nonce);
            if (it != g_nonce_map.end() &&
                it->second.exp >= std::time(nullptr) &&
                it->second.ua == ua_fp &&
                !it->second.click_key.empty() &&
                click_value == it->second.click_key) {
                // Once verified, ignore repeated taps so accidental spam cannot invalidate the nonce.
                if (it->second.click_verified) {
                    ok = true;
                } else {
                    const std::time_t now_sec = std::time(nullptr);
                    if (it->second.click_bucket_sec != now_sec) {
                        it->second.click_bucket_sec = now_sec;
                        it->second.click_bucket_count = 0;
                    }
                    it->second.click_bucket_count++;
                    if (it->second.click_bucket_count > click_rate_limit_per_sec) {
                        // Soft rate-limit: keep nonce alive and let user retry next second.
                        rate_limited = true;
                    } else {
                        // Mobile/CDN paths can temporarily shift edge IP between requests.
                        // Keep nonce bound to current IP once the click handshake is valid.
                        it->second.ip = ip;
                        it->second.click_verified = true;
                        ok = true;
                    }
                }
            }
        }
        if (rate_limited) {
            send_response(fd, 429, "Too Many Requests", "{\"ok\":false,\"error\":\"click_rate_limited\"}", {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
            close(fd);
            return;
        }
        if (!ok) {
            send_response(fd, 401, "Unauthorized", "{\"ok\":false,\"error\":\"click_invalid\"}", {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
            close(fd);
            return;
        }
        send_response(fd, 200, "OK", "{\"ok\":true}", {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
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
            ":root{--bg:#070d18;--bg2:#09182d;--card:#0e1f36;--line:#1f3f66;--text:#e8f3ff;--muted:#9fc0dd;--acc:#2f88ff;--acc2:#6be0ff;--err:#ff9a9a;}"
            "*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:18px;"
            "font-family:'Trebuchet MS','Segoe UI',Tahoma,sans-serif;color:var(--text);"
            "background:radial-gradient(1200px 580px at 5% -5%,#17365e 0%,transparent 62%),"
            "radial-gradient(1000px 620px at 95% 110%,#0f3954 0%,transparent 60%),"
            "linear-gradient(180deg,var(--bg),var(--bg2))}"
            ".card{width:min(840px,98vw);background:linear-gradient(180deg,rgba(255,255,255,.06),rgba(255,255,255,.015));"
            "border:1px solid rgba(103,153,204,.36);border-radius:18px;box-shadow:0 30px 80px rgba(0,0,0,.46);overflow-y:auto;max-height:98vh}"
            ".head{padding:16px 18px;border-bottom:1px solid rgba(103,153,204,.28);display:flex;align-items:center;gap:10px}"
            ".dot{width:10px;height:10px;border-radius:999px;background:linear-gradient(135deg,var(--acc),var(--acc2));box-shadow:0 0 20px rgba(75,184,255,.85)}"
            ".title{font-weight:800;letter-spacing:.25px}.sub{margin-left:auto;font-size:12px;color:var(--muted);font-weight:600}"
            ".body{padding:18px}.tabs{display:flex;gap:8px;margin:0 0 14px}.tab{flex:1;text-align:center;padding:9px 10px;border:1px solid rgba(103,153,204,.32);border-radius:11px;color:var(--muted);font-size:12px}"
            ".tab.on{color:#d7ebff;background:linear-gradient(180deg,rgba(22,53,88,.92),rgba(14,40,68,.92));border-color:#4f82ba}.pane{display:none}.pane.on{display:block}.big{padding:16px;border:1px solid rgba(103,153,204,.28);border-radius:13px;background:linear-gradient(180deg,#0a1a2d,#0a1728)}"
            ".phase-ind{display:inline-block;margin:0 0 8px;padding:4px 10px;border-radius:999px;font-size:11px;font-weight:800;letter-spacing:.9px;border:1px solid #2f5b8b;color:#cce6ff;background:#132742}"
            ".phase-ind.p1{border-color:#1f7b4a;background:#113324;color:#bfffe0;box-shadow:0 0 14px rgba(34,180,95,.22)}"
            ".phase-ind.p2{border-color:#7a2db3;background:#2a1440;color:#f2d9ff;box-shadow:0 0 14px rgba(196,103,255,.24)}"
            ".connbox{position:relative;min-height:56vh;max-height:74vh;padding:16px}.human-wrap{position:absolute;left:16px;top:16px;display:block}"
            ".timer{font-size:32px;font-weight:900;letter-spacing:.7px;color:#d2eaff;text-shadow:0 0 14px rgba(83,171,255,.35)}.q{margin:0 0 10px;color:var(--muted);font-size:14px;line-height:1.5}"
            ".qa{margin:0 0 12px;padding:12px;border:1px solid rgba(103,153,204,.3);border-radius:10px;background:#0a1a2d;color:#cae6ff;font-weight:700}"
            ".pat{margin:0 0 12px;padding:12px;border:1px solid rgba(103,153,204,.3);border-radius:10px;background:#07172a;display:none}"
            ".pat canvas{display:block;width:100%;max-width:300px;aspect-ratio:1/1;background:#061221;border:1px solid #2a5279;border-radius:10px;touch-action:none;margin:0 auto}"
            ".row{display:flex;gap:10px}.row input{flex:1}"
            "input,button{border-radius:11px;border:1px solid #325b8a;background:#0d2139;color:var(--text);padding:12px 14px;font-size:14px;outline:none}"
            "input:focus{border-color:#5ca2ea;box-shadow:0 0 0 2px rgba(92,162,234,.22)}"
            "button{cursor:pointer;background:linear-gradient(135deg,#206be0,#2e9cff);border-color:#45a8ff;font-weight:800;min-width:118px}"
            "#human_btn{width:200px;min-height:34px;font-size:12px;padding:8px 12px;letter-spacing:.1px;box-shadow:0 8px 20px rgba(22,96,196,.32)}"
            "button.secondary{background:#142b45;border-color:#2f5b8b;color:#cbe6ff}"
            "button:hover{filter:brightness(1.07)}button:disabled{opacity:.66;cursor:not-allowed}"
            ".hint{margin-top:10px;color:var(--muted);font-size:12px}.status{margin-top:10px;color:#9fd2ff;min-height:18px;font-size:13px}.err{margin-top:6px;color:var(--err);min-height:18px;font-size:13px}"
            "@media (max-width:640px){.connbox{min-height:52vh}.row{flex-direction:column}button{width:100%}#human_btn{width:142px;min-height:30px;font-size:10px;padding:6px 8px}}"
            ":root{--bg:#1f2933;--bg2:#263445;--card:#2f3b4d;--line:#42536b;--text:#e5edf7;--muted:#9fb0c7;--acc:#3b82f6;--err:#fca5a5}"
            "body{background:linear-gradient(180deg,var(--bg),var(--bg2));font-family:'Segoe UI',Tahoma,sans-serif;padding:14px}"
            ".card{width:min(760px,98vw);background:var(--card);border:1px solid var(--line);border-radius:8px;box-shadow:0 6px 18px rgba(0,0,0,.22)}"
            ".head{padding:12px 14px;border-bottom:1px solid var(--line)}.title{font-size:15px}.sub{font-size:12px}.dot{background:var(--acc);box-shadow:none}"
            ".body{padding:14px}.tabs{margin:0 0 10px;gap:8px}.tab{padding:8px 10px;border-radius:7px;border-color:var(--line);background:#2d3a4d;color:var(--muted)}"
            ".tab.on{background:#253347;border-color:#4d617d;color:var(--text)}"
            ".big{background:transparent;border:0;padding:0}.connbox{min-height:260px;max-height:none;padding:10px 0 0 0;position:relative;display:block;overflow:hidden}"
            ".human-wrap{position:absolute;left:14px;top:90px;display:block;margin:0;z-index:2}"
            ".phase-ind{border-radius:999px;padding:4px 10px;border-color:#516581;background:#31435a;color:#dce8f7;letter-spacing:.3px}"
            ".phase-ind.p1,.phase-ind.p2{border-color:#516581;background:#31435a;color:#dce8f7;box-shadow:none}"
            ".q{font-size:14px;color:var(--muted);margin:0 0 8px}.qa{margin:0 0 10px;padding:12px;border-radius:7px;border:1px solid var(--line);background:#2a3648;color:var(--text)}"
            ".pat{margin:0 0 10px;padding:10px;border-radius:7px;border:1px solid var(--line);background:#2a3648}"
            ".row{gap:8px}.row input{min-width:0}input,button{height:40px;padding:9px 12px;border-radius:7px;border:1px solid var(--line);background:#2a3648;color:var(--text)}"
            "input:focus{border-color:#5d8fd1;box-shadow:0 0 0 1px rgba(93,143,209,.35)}"
            "button{background:var(--acc);border-color:var(--acc);min-width:110px}button.secondary{background:#2a3648;border-color:var(--line);color:#cfe0f5}"
            "#human_btn{width:auto;min-width:160px;min-height:34px;padding:0 12px;letter-spacing:.1px;box-shadow:none}"
            "button:hover{filter:brightness(1.03)}.hint{font-size:13px;color:var(--muted)}.status{color:#9fc5ff}.err{color:var(--err)}.timer{text-shadow:none;font-size:24px}"
            "@media (max-width:640px){.card{width:100%}.body{padding:12px}.tab{padding:7px 8px}.q{font-size:13px}.row{flex-direction:column}button{width:100%}.connbox{min-height:200px;padding-top:8px}.human-wrap{top:76px}#human_btn{width:auto!important;min-width:120px;min-height:30px;font-size:10px;padding:0 8px}}"
            "</style></head><body><div class=\"card\">"
            "<div class=\"head\"><span class=\"dot\"></span><span class=\"title\">PteroProtect Verification</span><span class=\"sub\">30m clearance</span></div>"
            "<div class=\"body\"><div class=\"tabs\"><div class=\"tab on\" id=\"tab_conn\">Connection</div><div class=\"tab\" id=\"tab_chal\">Challenge</div></div>"
            "<div class=\"pane on\" id=\"pane_conn\"><div class=\"big connbox\" id=\"connbox\"><p class=\"q\">Checking connection integrity...</p><div class=\"timer\" id=\"ctimer\">--</div><p class=\"q\">Klik tombol untuk buka challenge manual. Session tetap dikunci ke IP + User-Agent.</p><div class=\"human-wrap\" id=\"human_wrap\"><button id=\"human_btn\" type=\"button\" disabled>Preparing challenge...</button></div></div></div>"
            "<div class=\"pane\" id=\"pane_chal\"><div class=\"phase-ind p1\" id=\"phase_ind\">PHASE 1</div><p class=\"q\" id=\"phaseq\">Tahap 1: selesaikan math dulu.</p><p class=\"q\" id=\"phint\"></p>"
            "<p class=\"qa\" id=\"q\">Memuat challenge...</p><div class=\"pat\" id=\"patbox\"><canvas id=\"pc\" width=\"280\" height=\"280\"></canvas></div><div class=\"row\" id=\"ainput_wrap\"><input id=\"a\" placeholder=\"Masukkan jawaban\"/></div><div class=\"row\"><button id=\"b\">Continue</button><button id=\"rb\" type=\"button\" class=\"secondary\">Restart (3)</button></div>"
            "<div class=\"hint\">Tip: gunakan perangkat normal (mouse/touch/scroll) agar lolos validasi anti-bot.</div></div><div class=\"status\" id=\"s\"></div><div class=\"err\" id=\"e\"></div></div></div>"
            "<script>let nonce=\"\",ak=\"\",hk=\"\",bk=\"\",ck=\"\",pk=\"\",powSalt=\"\",powHash=\"\";let powBits=14,powMemMb=48,powCpuLevel=4,powCounter=-1,powReady=false;let phase=1;let pseq=[];let clicked=[];let pTrace=[];let pStart=0;let pDir=0;let pActive=false;let ppx=null,ppy=null,pvdx=0,pvdy=0;let started=Date.now();let unlockAt=0;let waitTimer=null;let humanMoveTimer=null;let humanReady=false;let enteredChallenge=false;let clickVerified=false;let hardOpened=false;let uiLocked=false;let pm=0,pd=0,pdc=0,tm=0,sc=0,kc=0,px=null,py=null,pvx=0,pvy=0;let lastBX=-1,lastBY=-1;let humanPauseUntil=0;let phase2Hint='';let restartsLeft=3;let clickSamples=[];let clickResetBusy=false;let clickLastMark=0;let hardPenaltyUntil=0;let pendingHumanClick=false;let powTaskActive=false,pendingFinalSubmit=false,powLoopStop=false,challengeSolved=false;const HARD_PENALTY_MS=9*60*60*1000;const CLICK_LIMIT_PER_SEC=(((navigator&&navigator.maxTouchPoints)||0)>=1||/android|iphone|ipad|ipod|mobile/i.test(String((navigator&&navigator.userAgent)||'')))?30:10;"
            "const elQ=document.getElementById('q'),elA=document.getElementById('a'),elB=document.getElementById('b'),elRB=document.getElementById('rb'),elS=document.getElementById('s'),elE=document.getElementById('e'),elCT=document.getElementById('ctimer'),elHB=document.getElementById('human_btn'),elCW=document.getElementById('connbox'),elHW=document.getElementById('human_wrap'),elPC=document.getElementById('pane_conn'),elPH=document.getElementById('pane_chal'),elTC=document.getElementById('tab_conn'),elTH=document.getElementById('tab_chal'),elPI=document.getElementById('phase_ind'),elPQ=document.getElementById('phaseq'),elPHint=document.getElementById('phint'),elPat=document.getElementById('patbox'),elInputWrap=document.getElementById('ainput_wrap'),pc=document.getElementById('pc'),ctx=pc.getContext('2d');"
            "function normAns(v){let s=String(v||'').trim();if(!s)return s;s=s.replace(/[−–—﹣－]/g,'-').replace(/[＋]/g,'+');const sign=(s[0]==='+'||s[0]==='-')?s[0]:'';if(sign)s=s.slice(1);s=s.replace(/[\\s,._'\\u00A0\\u202F]/g,'');return sign+s;}"
            "async function sha256Hex(text){const enc=new TextEncoder();const buf=await crypto.subtle.digest('SHA-256',enc.encode(text));const arr=new Uint8Array(buf);let out='';for(const b of arr){out+=b.toString(16).padStart(2,'0');}return out;}"
            "function hasLeadingZeroBits(hex,bits){if(bits<=0)return true;const n=Math.floor(bits/4),r=bits%4;for(let i=0;i<n;i++){if(hex[i]!=='0')return false;}if(r===0)return true;const v=parseInt(hex[n]||'f',16);if(Number.isNaN(v))return false;return v<(1<<(4-r));}"
            "async function solvePow(nonce,salt,bits,memMb,cpuLvl){if(!nonce||!salt)throw new Error('pow_invalid');const start=Date.now();const n=navigator||{};const hc=Math.max(1,Math.min(64,Number(n.hardwareConcurrency||2)));const mobile=((Number(n.maxTouchPoints||0)>0)||/android|iphone|ipad|ipod|mobile/i.test(String(n.userAgent||'')));const cpuLevel=Math.max(1,Math.min(8,Number(cpuLvl||4)));const baseBatch=(hc<=2?48:(hc<=4?84:(hc<=6?132:220)));const batch=mobile?Math.max(28,Math.floor(baseBatch*0.72)):baseBatch;const sleepBase=(hc<=2?8:(hc<=4?5:(hc<=6?2:1)));const sleepMs=Math.max(0,sleepBase-Math.floor(cpuLevel/2))+(mobile?1:0);let targetMb=Math.max(8,Math.min(mobile?64:192,Number(memMb||48)));let memBytes=Math.max(8*1024*1024,Math.floor(targetMb*1024*1024));let mem=null;while(memBytes>=8*1024*1024){try{mem=new Uint8Array(memBytes);break;}catch(_e){memBytes=Math.floor(memBytes/2);}}if(!mem)throw new Error('pow_mem_alloc_failed');const stride=4096;for(let i=0;i<mem.length;i+=stride){mem[i]=(i^bits)&255;}const touchPerIter=(hc<=2?2:(hc<=4?3:4))+cpuLevel;const cpuMixRounds=(cpuLevel*6)+(hc>=8?4:0);let mix=bits&255;let c=0;while(c<200000000){let idx=((c*1103515245)^(mix*2654435761))>>>0;if(mem.length>0){for(let t=0;t<touchPerIter;t++){idx=(idx+4099+((mix+13*t)&255))%mem.length;const v=mem[idx];mix=(mix+v+c+t)&255;mem[idx]=mix^((idx>>>5)&255);}for(let r=0;r<cpuMixRounds;r++){mix=(mix*33+r+c)&255;idx=(idx+mix+17+r)%mem.length;mem[idx]=(mem[idx]^mix^r)&255;}}const h=await sha256Hex(nonce+'|'+salt+'|'+String(c));if(hasLeadingZeroBits(h,bits)){return{counter:c,hash:h,ms:Date.now()-start,mem_mb:Math.floor(mem.length/1048576),cpu_level:cpuLevel};}c++;if((c%batch)===0){await new Promise(r=>setTimeout(r,sleepMs));}}throw new Error('pow_timeout');}"
            "function trackPointer(x,y){if(px!==null&&py!==null){const dx=x-px,dy=y-py;pd+=Math.hypot(dx,dy);pm++;if((pvx!==0||pvy!==0)&&((dx*pvx+dy*pvy)<-4))pdc++;pvx=dx;pvy=dy;}px=x;py=y;}"
            "window.addEventListener('mousemove',e=>trackPointer(e.clientX,e.clientY),{passive:true});"
            "window.addEventListener('touchmove',e=>{const t=e.touches&&e.touches[0];if(!t)return;tm++;trackPointer(t.clientX,t.clientY);},{passive:true});"
            "window.addEventListener('scroll',()=>{sc++;},{passive:true});"
            "window.addEventListener('keydown',()=>{kc++;});"
            "function behavior(){return{duration_ms:Date.now()-started,pointer_moves:pm,pointer_distance:Math.round(pd),pointer_dir_changes:pdc,touch_moves:tm,scroll_count:sc,key_count:kc};}"
            "function resetToConnectionRateLimited(){if(clickResetBusy)return true;clickResetBusy=true;const now=Date.now();hardPenaltyUntil=Math.max(hardPenaltyUntil,now+HARD_PENALTY_MS);unlockAt=hardPenaltyUntil;clickSamples=[];clickVerified=false;uiLocked=false;hardOpened=false;enteredChallenge=false;humanReady=false;elHB.disabled=true;elHB.textContent='Penalty active';if(elHW)elHW.style.display='none';showConn();elS.textContent='Klik terlalu cepat (>'+String(CLICK_LIMIT_PER_SEC)+'/s). Hukuman 9 jam aktif.';updateWait();if(waitTimer)clearInterval(waitTimer);waitTimer=setInterval(updateWait,1000);clickResetBusy=false;return true;}"
            "function registerChallengeClick(){const now=Date.now();if(now-clickLastMark<35)return false;clickLastMark=now;clickSamples.push(now);while(clickSamples.length&&now-clickSamples[0]>1000)clickSamples.shift();if(clickSamples.length>CLICK_LIMIT_PER_SEC){return resetToConnectionRateLimited();}return false;}"
            "const registerGlobalClickWatch=()=>{const hit=()=>{registerChallengeClick();};window.addEventListener('pointerdown',hit,{capture:true,passive:true});window.addEventListener('mousedown',hit,{capture:true,passive:true});window.addEventListener('touchstart',hit,{capture:true,passive:true});window.addEventListener('click',hit,{capture:true,passive:true});document.addEventListener('click',hit,{capture:true,passive:true});};registerGlobalClickWatch();"
            "const nodes=[[20,20],[50,20],[80,20],[20,50],[50,50],[80,50],[20,80],[50,80],[80,80]];"
            "function randRGB(){const r=80+Math.floor(Math.random()*176),g=80+Math.floor(Math.random()*176),b=80+Math.floor(Math.random()*176);return'rgb('+r+','+g+','+b+')';}"
            "function segCross(a,b,c,d){const ccw=(p1,p2,p3)=>((p3[1]-p1[1])*(p2[0]-p1[0])>(p2[1]-p1[1])*(p3[0]-p1[0]));return(ccw(a,c,d)!==ccw(b,c,d))&&(ccw(a,b,c)!==ccw(a,b,d));}"
            "function sanitizeHint(v){const s=String(v||'');return(/overlap\\s+terdeteksi/i.test(s))?'':s;}"
            "function drawPattern(){const w=pc.width,h=pc.height;ctx.clearRect(0,0,w,h);ctx.fillStyle='#061221';ctx.fillRect(0,0,w,h);if(Array.isArray(clicked)&&clicked.length>1){const segs=[];ctx.lineWidth=3;for(let i=1;i<clicked.length;i++){const a=nodes[clicked[i-1]-1],b=nodes[clicked[i]-1];if(!a||!b)continue;const ax=a[0]/100*w,ay=a[1]/100*h,bx=b[0]/100*w,by=b[1]/100*h;let hit=false;for(let j=0;j<segs.length;j++){const s=segs[j];if(segCross([ax,ay],[bx,by],s[0],s[1])){hit=true;break;}}ctx.strokeStyle=hit?randRGB():'#66b6ff';ctx.beginPath();ctx.moveTo(ax,ay);ctx.lineTo(bx,by);ctx.stroke();segs.push([[ax,ay],[bx,by]]);}}for(let i=0;i<nodes.length;i++){const n=nodes[i],x=n[0]/100*w,y=n[1]/100*h;ctx.beginPath();ctx.arc(x,y,11,0,Math.PI*2);ctx.fillStyle='#0f2740';ctx.fill();ctx.strokeStyle='#4da0ff';ctx.stroke();ctx.fillStyle='#bfe1ff';ctx.font='12px sans-serif';ctx.fillText(String(i+1),x-4,y+4);}if(elPHint&&/overlap\\s+terdeteksi/i.test(String(elPHint.textContent||''))){elPHint.textContent=sanitizeHint(phase2Hint);}}"
            "function nearestNode(ev){const r=pc.getBoundingClientRect();const x=((ev.clientX-r.left)/r.width)*100;const y=((ev.clientY-r.top)/r.height)*100;let best=-1,bd=1e9;for(let i=0;i<nodes.length;i++){const dx=x-nodes[i][0],dy=y-nodes[i][1],d=dx*dx+dy*dy;if(d<bd){bd=d;best=i;}}if(best<0||bd>18*18)return -1;return best+1;}"
            "function addClickNode(n){if(n<1||n>9)return;if(!pStart)pStart=Date.now();if(clicked.length&&clicked[clicked.length-1]===n)return;clicked.push(n);const pt=nodes[n-1];const t=Date.now()-pStart;if(ppx!==null&&ppy!==null){const dx=pt[0]-ppx,dy=pt[1]-ppy;if((pvdx!==0||pvdy!==0)&&((dx*pvdx+dy*pvdy)<-1))pDir++;pvdx=dx;pvdy=dy;}ppx=pt[0];ppy=pt[1];pTrace.push([pt[0],pt[1],t]);if(pTrace.length>220)pTrace.shift();drawPattern();}"
            "pc.addEventListener('pointerdown',e=>{if(phase!==2)return;const n=nearestNode(e);if(n>0)addClickNode(n);});"
            "function patternPayload(){const dur=pStart?Date.now()-pStart:0;return{duration_ms:dur,dir_changes:pDir,trace:pTrace,clicked_nodes:clicked};}"
            "function connectionInfo(){const n=navigator||{};const s=screen||{};const tz=(Intl&&Intl.DateTimeFormat)?(Intl.DateTimeFormat().resolvedOptions().timeZone||''):'unknown';"
            "return{webdriver:!!n.webdriver,ua_len:String(n.userAgent||'').length,lang_len:String(n.language||'').length,tz_len:String(tz||'').length,max_touch_points:Number(n.maxTouchPoints||0),hardware_concurrency:Number(n.hardwareConcurrency||0),screen_w:Number(s.width||0),screen_h:Number(s.height||0),color_depth:Number(s.colorDepth||0)};}"
            "function clientHintQuery(){const n=navigator||{};const hc=Math.max(0,Math.min(64,Number(n.hardwareConcurrency||0)));const dm=Math.max(0,Math.min(64,Number(n.deviceMemory||0)));const mobile=((Number(n.maxTouchPoints||0)>0)||/android|iphone|ipad|ipod|mobile/i.test(String(n.userAgent||'')))?1:0;return'?hc='+encodeURIComponent(String(hc))+'&dm='+encodeURIComponent(String(dm))+'&m='+String(mobile);}"
            "function showConn(){if(uiLocked||hardOpened||enteredChallenge){showChal();return;}elPC.classList.add('on');elPH.classList.remove('on');elTC.classList.add('on');elTH.classList.remove('on');}"
            "function showChal(){elPC.classList.remove('on');elPH.classList.add('on');elTC.classList.remove('on');elTH.classList.add('on');}"
            "function lockChallengeUI(){uiLocked=true;hardOpened=true;enteredChallenge=true;humanReady=true;showChal();}"
            "function setPhaseMath(){phase=1;elQ.style.display='';elInputWrap.style.display='';elPat.style.display='none';elA.value='';if(elPI){elPI.textContent='PHASE 1';elPI.className='phase-ind p1';}}"
            "function setPhasePattern(){phase=2;lockChallengeUI();elQ.style.display='';elQ.textContent='Ikuti urutan titik sesuai petunjuk, lalu tekan Continue.';elInputWrap.style.display='none';elPat.style.display='block';if(elPI){elPI.textContent='PHASE 2';elPI.className='phase-ind p2';}}"
            "function randBtn(){if(!elHB||!elCW||!elHW)return;if(Date.now()<humanPauseUntil)return;const pad=14;const topMin=(elCW.clientWidth<640?76:90);const maxX=Math.max(pad,elCW.clientWidth-elHB.offsetWidth-pad);const maxY=Math.max(topMin,elCW.clientHeight-elHB.offsetHeight-pad);let x=pad,y=topMin;for(let i=0;i<6;i++){const nx=pad+Math.floor(Math.random()*(Math.max(1,maxX-pad+1)));const ny=topMin+Math.floor(Math.random()*(Math.max(1,maxY-topMin+1)));if(Math.abs(nx-lastBX)+Math.abs(ny-lastBY)>=12){x=nx;y=ny;break;}x=nx;y=ny;}lastBX=x;lastBY=y;elHW.style.left=String(x)+'px';elHW.style.top=String(y)+'px';}"
            "function fmtMs(ms){const t=Math.max(0,Math.ceil(ms/1000));const m=Math.floor(t/60);const s=t%60;return String(m)+'m '+String(s).padStart(2,'0')+'s';}"
            "function updateWait(){const now=Date.now();if(now<hardPenaltyUntil){if(elHW)elHW.style.display='none';}else{if(elHW)elHW.style.display='';}const ms=unlockAt-now;if(ms<=0){elCT.textContent='OK';}else{elCT.textContent=fmtMs(ms);}if(uiLocked||hardOpened||enteredChallenge){showChal();elB.disabled=false;if(powTaskActive&&powReady){elS.textContent='PoW aktif terus di background sampai challenge selesai.';}else if(powReady){elS.textContent='Challenge ready. Tap Continue.';}else if(powTaskActive){elS.textContent='Preparing browser proof in background...';}else{elS.textContent='Preparing browser proof...';}if(ms<=0&&waitTimer&&powReady){clearInterval(waitTimer);waitTimer=null;}return;}if(ms<=0){if(waitTimer){clearInterval(waitTimer);waitTimer=null;}elS.textContent='Connection check passed.';elB.disabled=false;if(elHW){elHW.style.display='';elHB.disabled=false;elHB.textContent='I am human, pass me';}return;}const label=fmtMs(ms);showConn();elB.disabled=true;elS.textContent='Checking connection... '+label+' | tap button to open challenge';}"
            "const showErr=(m)=>{elE.textContent=String(m||\"Unknown error\");elE.style.display=\"block\";elS.textContent=\"\";};"
            "const hideErr=()=>{elE.textContent=\"\";elE.style.display=\"none\";};"
            "async function loadC(){hideErr();elS.textContent=\"\";elB.disabled=true;clickSamples=[];if(!hardOpened){uiLocked=false;showConn();humanReady=false;enteredChallenge=false;elHB.disabled=true;elHB.textContent='Preparing challenge...';requestAnimationFrame(randBtn);if(humanMoveTimer)clearInterval(humanMoveTimer);humanMoveTimer=setInterval(()=>{if(!humanReady)randBtn();},900);}else{uiLocked=true;humanReady=true;enteredChallenge=true;elHB.disabled=true;elHB.textContent='Challenge opened';if(humanMoveTimer){clearInterval(humanMoveTimer);humanMoveTimer=null;}showChal();}const r=await fetch('/__pteroprotect/challenge/new'+clientHintQuery(),{cache:'no-store'});const j=await r.json();if(!j.ok)throw new Error(String(j.error||'challenge unavailable'));"
            "nonce=j.nonce;ak=j.answer_key||'answer';hk=j.click_key||'click';bk=j.behavior_key||'behavior';ck=j.connection_key||'connection';pk=j.pattern_key||'pattern';powSalt=String(j.pow_salt||'');powBits=Math.max(8,Math.min(24,Number(j.pow_bits||14)));powMemMb=Math.max(8,Math.min(192,Number(j.pow_mem_mb||48)));powCpuLevel=Math.max(1,Math.min(8,Number(j.pow_cpu_level||4)));powCounter=-1;powHash='';powReady=false;powTaskActive=false;pendingFinalSubmit=false;powLoopStop=false;challengeSolved=false;clickVerified=!!(clickVerified||hardOpened||enteredChallenge||humanReady||uiLocked);setPhaseMath();pseq=[];clicked=[];pTrace=[];pStart=0;pDir=0;pActive=false;ppx=null;ppy=null;pvdx=0;pvdy=0;phase2Hint='';elPQ.textContent='Tahap 1: selesaikan math dulu.';elPHint.textContent='';drawPattern();elQ.textContent=j.question;elHB.disabled=false;elHB.textContent='I am human, pass me';if(pendingHumanClick){pendingHumanClick=false;setTimeout(()=>{elHB.click();},0);}restartsLeft=3;elRB.disabled=true;elRB.textContent='Restart ('+String(restartsLeft)+')';"
            "elS.textContent='Running browser PoW (RAM '+String(powMemMb)+'MB, CPU L'+String(powCpuLevel)+') in background...';powTaskActive=true;(async()=>{try{let firstPass=true;while(!powLoopStop){const pow=await solvePow(nonce,powSalt,powBits,powMemMb,powCpuLevel);if(firstPass){powCounter=pow.counter;powHash=pow.hash;powReady=true;elRB.disabled=false;const usedMb=Number(pow.mem_mb||powMemMb);const usedCpu=Number(pow.cpu_level||powCpuLevel);elS.textContent='PoW passed in '+String(pow.ms)+'ms. RAM '+String(usedMb)+'MB, CPU L'+String(usedCpu)+'. PoW tetap berjalan sampai challenge selesai.';elB.disabled=false;if(pendingFinalSubmit&&phase===2&&clickVerified){pendingFinalSubmit=false;setTimeout(()=>{elB.click();},0);}}firstPass=false;if(challengeSolved||powLoopStop)break;await new Promise(r=>setTimeout(r,0));}powTaskActive=false;}catch(err){powReady=false;powTaskActive=false;const msg=String(err.message||err);elE.textContent=msg;elS.textContent='PoW failed. Restart challenge.';elB.disabled=false;}})();"
            "const raw=Number(j.connection_delay_ms||0);const baseDelay=Math.min(21600000,Math.max(0,raw));const penaltyLeft=Math.max(0,hardPenaltyUntil-Date.now());const d=Math.max(baseDelay,penaltyLeft);const keepOpened=(uiLocked||hardOpened||clickVerified||enteredChallenge||humanReady||phase===2)&&penaltyLeft<=0;started=Date.now();unlockAt=Date.now()+d;if(keepOpened){lockChallengeUI();elHB.disabled=true;elHB.textContent='Challenge opened';if(humanMoveTimer){clearInterval(humanMoveTimer);humanMoveTimer=null;}showChal();elA.focus();}else{humanReady=false;enteredChallenge=false;elHB.disabled=false;elHB.textContent='I am human, pass me';if(penaltyLeft>0&&elHW)elHW.style.display='none';}updateWait();if(waitTimer)clearInterval(waitTimer);waitTimer=setInterval(updateWait,1000);}"
            "elHB.onclick=async()=>{try{if(!nonce||!hk){pendingHumanClick=true;elHB.disabled=true;elHB.textContent='Preparing challenge...';elS.textContent='Preparing challenge...';return;}const c={nonce:nonce,click:hk};const cr=await fetch('/__pteroprotect/challenge/click',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(c)});const cj=await cr.json();if(!cj.ok){throw new Error(cj.error||'click_invalid');}lockChallengeUI();clickVerified=true;elHB.disabled=true;elHB.textContent='Challenge opened';if(humanMoveTimer){clearInterval(humanMoveTimer);humanMoveTimer=null;}showChal();if(powReady){elA.focus();}updateWait();}catch(err){const msg=String(err.message||err);if(msg==='click_rate_limited'){resetToConnectionRateLimited();return;}elE.textContent=msg;}};"
            "const pauseHumanBtn=(ms)=>{humanPauseUntil=Math.max(humanPauseUntil,Date.now()+ms);};elHB.addEventListener('pointerenter',()=>pauseHumanBtn(120));elHB.addEventListener('pointerdown',()=>pauseHumanBtn(120));elHB.addEventListener('touchstart',()=>pauseHumanBtn(120),{passive:true});elHB.addEventListener('focus',()=>pauseHumanBtn(120));window.addEventListener('resize',()=>{if(!humanReady)randBtn();});"
            "elA.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();elB.click();}});"
            "elRB.onclick=async()=>{if(restartsLeft<=0){elRB.disabled=true;return;}restartsLeft-=1;elRB.textContent='Restart ('+String(restartsLeft)+')';if(restartsLeft<=0)elRB.disabled=true;elE.textContent='';if(phase===1){elS.textContent='Math di-reset.';elA.value='';elA.focus();elB.disabled=false;return;}if(phase===2){elS.textContent='Pattern di-reset.';clicked=[];pTrace=[];pStart=0;pDir=0;pActive=false;ppx=null;ppy=null;pvdx=0;pvdy=0;drawPattern();elB.disabled=false;return;}elS.textContent='Challenge di-reset.';elB.disabled=false;};"
            "elB.onclick=async()=>{try{if(Date.now()<unlockAt&&!enteredChallenge){updateWait();return;}if(!clickVerified){throw new Error('click_required');}elE.textContent='';elB.disabled=true;"
            "if(phase===1){const m={nonce:nonce};m[ak]=normAns(elA.value||'');const mr=await fetch('/__pteroprotect/challenge/verify-math',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(m)});const mj=await mr.json();if(!mj.ok){if(mj.error==='answer_wrong'&&Number.isFinite(Number(mj.attempts_left))){elS.textContent='Salah. Sisa percobaan math: '+String(Math.max(0,Number(mj.attempts_left)));}throw new Error(mj.error||'math_failed');}setPhasePattern();pseq=Array.isArray(mj.pattern_points)?mj.pattern_points:[];clicked=[];pTrace=[];pStart=0;pDir=0;pActive=false;ppx=null;ppy=null;pvdx=0;pvdy=0;elPQ.textContent='Tahap 2: klik angka sesuai urutan.';phase2Hint=sanitizeHint(String(mj.pattern_hint||''));elPHint.textContent=phase2Hint;drawPattern();elB.disabled=false;return;}"
            "if(!powReady){pendingFinalSubmit=true;elS.textContent='PoW masih berjalan di background... auto lanjut saat selesai.';elB.disabled=true;return;}const p={nonce:nonce,rd:'" + rd + "',pow_counter:powCounter,pow_hash:powHash};p[ak]=normAns(elA.value||'');p[bk]=behavior();p[ck]=connectionInfo();p[pk]=patternPayload();"
            "const r=await fetch('/__pteroprotect/challenge/solve',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(p)});const j=await r.json();if(!j.ok)throw new Error(j.error||'failed');challengeSolved=true;powLoopStop=true;location.href=j.redirect||'" + rd + "';}"
            "catch(err){const msg=String(err.message||err);showErr(msg);if((msg==='answer_wrong'||msg==='pattern_invalid'||msg==='math_not_verified'||msg==='nonce_invalid'||msg==='nonce_expired'||msg==='pow_invalid')&&restartsLeft>0){elS.textContent='Salah. Kamu bisa tekan Restart ('+String(restartsLeft)+')';}elB.disabled=false;}};"
            "async function cleanupOldChallengeCaches(){try{if(window.caches&&caches.keys){const keys=await caches.keys();await Promise.all(keys.filter(k=>k.startsWith('pp-challenge-')).map(k=>caches.delete(k)));}if('serviceWorker' in navigator&&navigator.serviceWorker.getRegistrations){const regs=await navigator.serviceWorker.getRegistrations();await Promise.all(regs.filter(r=>{const s=String(r.scope||'');return s===location.origin+'/'||s.includes('/__pteroprotect/challenge/');}).map(r=>r.unregister()));}}catch(_e){}}"
            "async function registerChallengeSW(){if(!('serviceWorker' in navigator))return;try{await navigator.serviceWorker.register('/__pteroprotect/challenge/sw.js',{scope:'/__pteroprotect/challenge/'});}catch(_e){}}"
            "document.addEventListener('visibilitychange',()=>{if(document.hidden&&powTaskActive){elS.textContent='PoW tetap jalan di background tab...';}});window.addEventListener('beforeunload',()=>{powLoopStop=true;});cleanupOldChallengeCaches().finally(()=>registerChallengeSW().finally(()=>{loadC().catch(e=>elE.textContent=String(e.message||e));}));</script></body></html>";
        send_response(fd, 200, "OK", html, {{"Content-Type", "text/html; charset=utf-8"}}, head_only);
        close(fd);
        return;
    }

    if (req.path == "/sw.js" && (req.method == "GET" || req.method == "HEAD")) {
        std::string js =
            "const CACHE='pp-challenge-v22';"
            "const PAGE='/__pteroprotect/challenge/page';"
            "self.addEventListener('install',e=>{e.waitUntil(caches.open(CACHE).then(c=>c.addAll([PAGE,'/__pteroprotect/challenge/sw.js'])).catch(()=>{}).then(()=>self.skipWaiting()));});"
            "self.addEventListener('activate',e=>{e.waitUntil((async()=>{"
            "const isRootScope=!!(self.registration&&self.registration.scope===self.location.origin+'/');"
            "if(isRootScope){"
            "try{const keys=await caches.keys();await Promise.all(keys.filter(k=>k.startsWith('pp-challenge-')).map(k=>caches.delete(k)));}catch(_e){}"
            "try{await self.registration.unregister();}catch(_e){}"
            "try{const cls=await self.clients.matchAll({type:'window',includeUncontrolled:true});for(const c of cls){try{c.navigate(c.url);}catch(_e){}}}catch(_e){}"
            "return;"
            "}"
            "try{const keys=await caches.keys();await Promise.all(keys.filter(k=>k.startsWith('pp-challenge-')&&k!==CACHE).map(k=>caches.delete(k)));}catch(_e){}"
            "await self.clients.claim();"
            "})());});"
            "self.addEventListener('fetch',e=>{"
            "if(e.request.method!=='GET')return;"
            "const isRootScope=!!(self.registration&&self.registration.scope===self.location.origin+'/');"
            "if(isRootScope)return;"
            "const u=new URL(e.request.url);"
            "if(u.pathname.startsWith('/__pteroprotect/challenge/new')||u.pathname.startsWith('/__pteroprotect/challenge/click')||u.pathname.startsWith('/__pteroprotect/challenge/verify-math')||u.pathname.startsWith('/__pteroprotect/challenge/solve')||u.pathname.startsWith('/__pteroprotect/challenge/check')){"
            "e.respondWith(fetch(e.request,{cache:'no-store'}));return;}"
            "if(e.request.mode==='navigate'){"
            "e.respondWith(fetch(e.request).then(r=>{const rc=r.clone();caches.open(CACHE).then(c=>c.put(PAGE,rc)).catch(()=>{});return r;}).catch(()=>caches.match(PAGE).then(r=>r||new Response('Offline. Retry when internet is back.',{status:503,headers:{'Content-Type':'text/plain; charset=utf-8'}}))));return;}"
            "if(u.pathname.startsWith('/__pteroprotect/challenge/')){"
            "e.respondWith(caches.match(e.request).then(hit=>hit||fetch(e.request).then(r=>{const rc=r.clone();caches.open(CACHE).then(c=>c.put(e.request,rc)).catch(()=>{});return r;})).catch(()=>new Response('',{status:503,statusText:'Service Unavailable'})));"
            "}"
            "});";
        std::vector<std::pair<std::string, std::string>> headers = {
            {"Content-Type", "application/javascript; charset=utf-8"},
            {"Service-Worker-Allowed", "/"}
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
        long long pow_counter = -1;
        std::string pow_hash;
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
            if (!rec.click_verified) {
                send_response(fd, 401, "Unauthorized", "{\"ok\":false,\"error\":\"fingerprint_mismatch\"}", {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
                close(fd);
                return;
            }
            std::lock_guard<std::mutex> lock(g_nonce_mu);
            auto it = g_nonce_map.find(nonce);
            if (it != g_nonce_map.end()) {
                it->second.ua = ua_fp;
                rec.ua = ua_fp;
            }
        }
        if (rec.ip != ip) {
            if (!rec.click_verified) {
                send_response(fd, 401, "Unauthorized", "{\"ok\":false,\"error\":\"fingerprint_mismatch\"}", {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
                close(fd);
                return;
            }
            if (!ip_geo_similar(rec.ip, ip)) {
                send_response(fd, 401, "Unauthorized", "{\"ok\":false,\"error\":\"fingerprint_mismatch\"}", {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
                close(fd);
                return;
            }
            std::lock_guard<std::mutex> lock(g_nonce_mu);
            auto it = g_nonce_map.find(nonce);
            if (it != g_nonce_map.end() && it->second.ua == ua_fp) {
                it->second.ip = ip;
                rec.ip = ip;
            }
        }
        if (!rec.click_verified) {
            send_response(fd, 401, "Unauthorized", "{\"ok\":false,\"error\":\"click_required\"}", {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
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
        if (in.contains("pow_counter")) {
            if (in["pow_counter"].is_number_integer()) pow_counter = in["pow_counter"].get<long long>();
            else if (in["pow_counter"].is_string()) {
                try { pow_counter = std::stoll(trim(in["pow_counter"].get<std::string>())); } catch (...) { pow_counter = -1; }
            }
        }
        if (in.contains("pow_hash") && in["pow_hash"].is_string()) {
            pow_hash = trim(in["pow_hash"].get<std::string>());
        }
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
        bool pow_ok = verify_pow_solution(rec, nonce, pow_counter, pow_hash);
        if (!pow_ok) {
            send_response(fd, 401, "Unauthorized", "{\"ok\":false,\"error\":\"pow_invalid\"}", {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
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
        {
            std::lock_guard<std::mutex> lock(g_nonce_mu);
            auto it = g_nonce_map.find(nonce);
            if (it != g_nonce_map.end()) g_nonce_map.erase(it);
        }

        std::string sid = random_nonce();
        {
            std::lock_guard<std::mutex> lock(g_session_mu);
            SessionRec sr;
            sr.sid = sid;
            sr.ua = ua_fp;
            sr.exp = std::time(nullptr) + s.ttl;
            g_ip_session_map[session_scope_key(ip, ua_fp)] = sr;
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
