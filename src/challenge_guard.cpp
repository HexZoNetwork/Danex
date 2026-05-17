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
    int challenge_type = 1;
    bool challenge_theme_custom_enabled = false;
    std::string challenge_theme_gradient_start = "#07070b";
    std::string challenge_theme_gradient_end = "#111117";
    std::string challenge_theme_accent = "#8b5cf6";
    std::string cookie_name = "pp_clearance";
    std::string cookie_secure_mode = "auto";
    int session_ip_prefix_v4 = 24;
    int session_ip_prefix_v6 = 56;
    int session_grace_sec = 20;
    std::string node_id = "local-node";
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
    int challenge_mode = 0;
    bool voice_mode = false;
    bool answer_numeric = true;
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
    std::vector<std::string> ips;
    std::time_t exp = 0;
};

static std::atomic<bool> g_running(true);
static std::atomic<int> g_active_workers(0);
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
static std::mutex g_completed_mu;
static std::map<std::string, std::time_t> g_completed_challenges;
static std::mutex g_source_bucket_mu;
static std::map<std::string, std::pair<std::time_t, int>> g_source_buckets;
static std::string random_nonce();
static std::string to_lower(std::string s);
static bool base64_decode(const std::string& in, std::string& out);
static std::string hmac_sha256_hex(const std::string& key, const std::string& data);
static bool secure_equals(const std::string& a, const std::string& b);
static bool daemon_bearer_token_format_ok(const std::string& token);
static std::string ip_prefix_of(const std::string& ip, int v4_prefix_bits, int v6_prefix_bits);
static bool ip_in_same_prefix(const std::string& a, const std::string& b, int v4_prefix_bits, int v6_prefix_bits);

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
    MYSQL* conn = mysql_init(nullptr);
    if (!conn) return false;
    bool ok = false;
    if (mysql_real_connect(conn,
            s.db_host.empty() ? "127.0.0.1" : s.db_host.c_str(),
            s.db_user.c_str(),
            s.db_password.c_str(),
            s.db_name.c_str(),
            0, nullptr, 0) != nullptr) {
        std::string esc;
        esc.resize(token.size() * 2 + 1);
        unsigned long esc_len = mysql_real_escape_string(conn, &esc[0], token.c_str(), static_cast<unsigned long>(token.size()));
        esc.resize(esc_len);
        std::string q1 = "SELECT 1 FROM api_keys WHERE token='" + esc + "' LIMIT 1";
        if (mysql_query(conn, q1.c_str()) == 0) {
            MYSQL_RES* res = mysql_store_result(conn);
            if (res) {
                ok = mysql_num_rows(res) > 0;
                mysql_free_result(res);
            }
        }
        if (!ok) {
            std::string q2 = "SELECT 1 FROM personal_access_tokens WHERE token='" + esc + "' LIMIT 1";
            if (mysql_query(conn, q2.c_str()) == 0) {
                MYSQL_RES* res2 = mysql_store_result(conn);
                if (res2) {
                    ok = mysql_num_rows(res2) > 0;
                    mysql_free_result(res2);
                }
            }
        }
    }
    mysql_close(conn);
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

static std::string normalize_text_answer(const std::string& input) {
    std::string s = to_lower(trim(input));
    std::string out;
    out.reserve(s.size());
    for (char c : s) {
        unsigned char u = static_cast<unsigned char>(c);
        if (std::isalnum(u)) out.push_back(static_cast<char>(u));
    }
    return out.empty() ? s : out;
}

static std::string normalize_speech_friendly_text_answer(const std::string& input) {
    std::string s = to_lower(trim(input));
    for (char& c : s) {
        const unsigned char u = static_cast<unsigned char>(c);
        if (!std::isalnum(u)) c = ' ';
    }
    std::stringstream ss(s);
    std::string tok;
    std::string out;
    auto map_number_word = [](const std::string& w) -> std::string {
        if (w == "nol" || w == "zero") return "0";
        if (w == "satu" || w == "one") return "1";
        if (w == "dua" || w == "two") return "2";
        if (w == "tiga" || w == "three") return "3";
        if (w == "empat" || w == "four") return "4";
        if (w == "lima" || w == "five") return "5";
        if (w == "enam" || w == "six") return "6";
        if (w == "tujuh" || w == "seven") return "7";
        if (w == "delapan" || w == "eight") return "8";
        if (w == "sembilan" || w == "nine") return "9";
        if (w == "sepuluh" || w == "ten") return "10";
        return w;
    };
    while (ss >> tok) {
        out += map_number_word(tok);
    }
    return out.empty() ? normalize_text_answer(input) : out;
}

static std::string normalize_expected_answer(const std::string& value, bool numeric_mode, bool voice_mode) {
    if (numeric_mode) return normalize_numeric_answer(value);
    if (voice_mode) return normalize_speech_friendly_text_answer(value);
    return normalize_text_answer(value);
}

static std::string sanitize_hex_color(const std::string& value, const std::string& fallback) {
    const std::string v = trim(value);
    auto is_hex = [](char c) -> bool {
        return (c >= '0' && c <= '9') ||
               (c >= 'a' && c <= 'f') ||
               (c >= 'A' && c <= 'F');
    };
    if (v.size() == 7 && v[0] == '#') {
        for (std::size_t i = 1; i < v.size(); ++i) {
            if (!is_hex(v[i])) return fallback;
        }
        return v;
    }
    if (v.size() == 4 && v[0] == '#') {
        for (std::size_t i = 1; i < v.size(); ++i) {
            if (!is_hex(v[i])) return fallback;
        }
        std::string out = "#";
        out.push_back(v[1]); out.push_back(v[1]);
        out.push_back(v[2]); out.push_back(v[2]);
        out.push_back(v[3]); out.push_back(v[3]);
        return out;
    }
    return fallback;
}

struct Phase1ChallengeSpec {
    std::string question;
    std::string answer;
    std::string label;
    std::string hint;
    std::string input_placeholder;
    bool answer_numeric = true;
    bool voice_enabled = false;
};

static Phase1ChallengeSpec build_phase1_challenge(std::mt19937& gen, int challenge_mode, int challenge_type) {
    (void)challenge_mode;
    Phase1ChallengeSpec spec;
    int safe_type = challenge_type;
    if (safe_type < 1) safe_type = 1;
    if (safe_type > 66) safe_type = 66;

    auto to_upper_ascii = [](std::string s) -> std::string {
        for (char& c : s) c = static_cast<char>(std::toupper(static_cast<unsigned char>(c)));
        return s;
    };
    auto reverse_str = [](std::string s) -> std::string {
        std::reverse(s.begin(), s.end());
        return s;
    };
    auto rotate_left = [](const std::string& s, int n) -> std::string {
        if (s.empty()) return s;
        int k = n % static_cast<int>(s.size());
        if (k < 0) k += static_cast<int>(s.size());
        return s.substr(static_cast<std::size_t>(k)) + s.substr(0, static_cast<std::size_t>(k));
    };
    auto caesar_encode = [](std::string s, int sh) -> std::string {
        for (char& c : s) {
            if (c >= 'a' && c <= 'z') c = static_cast<char>('a' + ((c - 'a' + sh) % 26));
            else if (c >= 'A' && c <= 'Z') c = static_cast<char>('A' + ((c - 'A' + sh) % 26));
        }
        return s;
    };
    auto remove_vowels = [](const std::string& s) -> std::string {
        std::string o;
        for (char c : s) {
            char l = static_cast<char>(std::tolower(static_cast<unsigned char>(c)));
            if (l == 'a' || l == 'e' || l == 'i' || l == 'o' || l == 'u') continue;
            o.push_back(c);
        }
        return o.empty() ? s : o;
    };

    // Exactly one numeric challenge.
    if (safe_type == 1) {
        std::uniform_int_distribution<int> d2(12, 39);
        std::uniform_int_distribution<int> d3(5, 21);
        int a = d2(gen);
        int b = d3(gen);
        int c = d3(gen);
        long long ans = static_cast<long long>(a) * b - c;
        spec.question = "(" + std::to_string(a) + " × " + std::to_string(b) + ") - " + std::to_string(c) + " = ?";
        spec.answer = std::to_string(ans);
        spec.label = "Tahap 1: hitung hasil operasi.";
        spec.hint = "Ini satu-satunya type numeric.";
        spec.input_placeholder = "Jawaban angka";
        spec.answer_numeric = true;
        return spec;
    }

    // Dedicated voice type.
    if (safe_type == 66) {
        static const std::vector<std::string> voice_codes = {
            "MERAH SATU", "BIRU TUJUH", "ALFA LIMA", "NOVA DUA", "DELTA TIGA", "TITAN SEMBILAN"
        };
        std::uniform_int_distribution<int> voice_dis(0, static_cast<int>(voice_codes.size()) - 1);
        const std::string voice_code = voice_codes[static_cast<std::size_t>(voice_dis(gen))];
        spec.question = "Mode voice unik (type 66): tekan tombol mic, ucapkan frasa ini, lalu submit:\n" + voice_code;
        spec.answer = voice_code;
        spec.label = "Tahap 1: voice challenge.";
        spec.hint = "Boleh ketik manual; angka kata/digit dianggap setara.";
        spec.input_placeholder = "Hasil suara / ketik manual";
        spec.answer_numeric = false;
        spec.voice_enabled = true;
        return spec;
    }

    // Types 2..65: phase-1 answer is tied to interactive captcha widget only.
    spec.question = "Selesaikan captcha interaktif di atas, lalu tekan Continue.";
    spec.answer = "ok";
    spec.label = "Tahap 1: captcha interaktif.";
    spec.hint = "Setiap sesi punya variasi parameter acak.";
    spec.input_placeholder = "Auto";
    spec.answer_numeric = false;
    return spec;
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

static std::string session_cookie_fingerprint(const Settings& s, const std::string& raw_session_cookie) {
    const std::string cookie = trim(raw_session_cookie);
    if (cookie.empty() || s.secret.empty()) return "";
    return hmac_sha256_b64url(s.secret, "sid:" + cookie);
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

static void log_event_json(const std::string& event, const json& fields = json::object()) {
    json out = json::object();
    out["ts"] = static_cast<long long>(std::time(nullptr));
    out["event"] = event;
    for (auto it = fields.begin(); it != fields.end(); ++it) out[it.key()] = it.value();
    std::cerr << out.dump() << "\n";
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
            s.challenge_type = std::max(1, std::min(66, json_get_int(net, "waf_challenge_type", 1)));
            s.challenge_theme_custom_enabled = parse_bool(net.value("waf_challenge_theme_custom_enabled", json(false)), false);
            s.challenge_theme_gradient_start = sanitize_hex_color(
                json_get_string(net, "waf_challenge_theme_gradient_start", "#07070b"),
                "#07070b"
            );
            s.challenge_theme_gradient_end = sanitize_hex_color(
                json_get_string(net, "waf_challenge_theme_gradient_end", "#111117"),
                "#111117"
            );
            s.challenge_theme_accent = sanitize_hex_color(
                json_get_string(net, "waf_challenge_theme_accent", "#8b5cf6"),
                "#8b5cf6"
            );
            s.cookie_name = trim(json_get_string(net, "waf_challenge_cookie_name", "pp_clearance"));
            if (s.cookie_name.empty()) s.cookie_name = "pp_clearance";
            s.cookie_secure_mode = to_lower(trim(json_get_string(net, "challenge_cookie_secure_mode", "auto")));
            if (!(s.cookie_secure_mode == "auto" || s.cookie_secure_mode == "always" || s.cookie_secure_mode == "never")) {
                s.cookie_secure_mode = "auto";
            }
            s.session_ip_prefix_v4 = std::max(8, std::min(32, json_get_int(net, "session_ip_prefix_v4", 24)));
            s.session_ip_prefix_v6 = std::max(32, std::min(128, json_get_int(net, "session_ip_prefix_v6", 56)));
            s.session_grace_sec = std::max(0, std::min(300, json_get_int(net, "session_grace_sec", 20)));
            s.node_id = trim(json_get_string(net, "node_id", "local-node"));
            if (s.node_id.empty()) s.node_id = "local-node";
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

static int clamp_challenge_type(int value) {
    if (value < 1) return 1;
    if (value > 66) return 66;
    return value;
}

static int resolve_challenge_type(const Settings& s, const std::map<std::string, std::string>& q) {
    int t = clamp_challenge_type(s.challenge_type);
    auto parse = [&](const std::string& raw, int fallback) -> int {
        try {
            return clamp_challenge_type(std::stoi(trim(raw)));
        } catch (...) {
            return fallback;
        }
    };
    auto it = q.find("type");
    if (it != q.end()) return parse(it->second, t);
    it = q.find("ct");
    if (it != q.end()) return parse(it->second, t);
    return t;
}

static std::string challenge_type_name(int type) {
    static const std::vector<std::string> names = {
        "Type 01 Equation Gate", "Type 02 Icon Census", "Type 03 Word Forge", "Type 04 Number Trail", "Type 05 Code Mirror", "Type 06 Voice Echo",
        "Type 07 Formula Weave", "Type 08 Glyph Tally", "Type 09 Lexi Shuffle", "Type 10 Delta Ladder", "Type 11 Cipher Trace", "Type 12 Audio Phrase",
        "Type 13 Symbol Chain", "Type 14 Pixel Count", "Type 15 Token Builder", "Type 16 Pulse Sequence", "Type 17 Key Replay", "Type 18 Mic Relay",
        "Type 19 Operand Quest", "Type 20 Emoji Sweep", "Type 21 Anagram Lock", "Type 22 Step Progression", "Type 23 Code Relay", "Type 24 Voice Relay",
        "Type 25 Bracket Logic", "Type 26 Target Counter", "Type 27 Word Rewire", "Type 28 Pattern Rise", "Type 29 String Match", "Type 30 Speech Match",
        "Type 31 Grid Solver", "Type 32 Icon Merge", "Type 33 Phrase Puzzle", "Type 34 Gap Sequence", "Type 35 Signature Copy", "Type 36 Vocal Token",
        "Type 37 Compute Path", "Type 38 Marker Count", "Type 39 Letter Craft", "Type 40 Increment Path", "Type 41 Passcode Echo", "Type 42 Voice Token",
        "Type 43 Numeric Blend", "Type 44 Icon Blend", "Type 45 Jumble Decode", "Type 46 Ladder Guess", "Type 47 Checksum Copy", "Type 48 Speech Decode",
        "Type 49 Operand Shift", "Type 50 Focus Count", "Type 51 Syntax Puzzle", "Type 52 Offset Sequence", "Type 53 Tag Replay", "Type 54 Audio Verify",
        "Type 55 Chain Compute", "Type 56 Visual Count", "Type 57 Lexicon Twist", "Type 58 Orbit Sequence", "Type 59 Keyframe Copy", "Type 60 Mic Verify",
        "Type 61 Logic Mix", "Type 62 Visual Sweep", "Type 63 Puzzle Mesh", "Type 64 Sequence Mesh", "Type 65 Code Mesh", "Type 66 Voice Mesh"
    };
    int t = clamp_challenge_type(type) - 1;
    return names[static_cast<std::size_t>(t)];
}

static int challenge_mode_for_type(int type) {
    return clamp_challenge_type(type) - 1; // unique mode id per type
}

static std::string challenge_mode_name(int challenge_type) {
    return std::string("variant_") + std::to_string(clamp_challenge_type(challenge_type));
}

static std::string challenge_theme_css_vars(const Settings& s, int type) {
    (void)type;
    if (!s.challenge_theme_custom_enabled) {
        return "--bg:#07070b;"
               "--bg2:#111117;"
               "--card:#0b0b10;"
               "--line:rgba(139,92,246,.42);"
               "--text:#f7f7fb;"
               "--muted:#a6a6b8;"
               "--acc:#8b5cf6;"
               "--acc2:#06b6d4;"
               "--err:#ef4444;"
               "--radius:12px;";
    }
    const std::string gs = sanitize_hex_color(s.challenge_theme_gradient_start, "#07070b");
    const std::string ge = sanitize_hex_color(s.challenge_theme_gradient_end, "#111117");
    const std::string ac = sanitize_hex_color(s.challenge_theme_accent, "#8b5cf6");
    return "--bg:" + gs + ";"
           "--bg2:" + ge + ";"
           "--card:#0b0b10;"
           "--line:rgba(139,92,246,.42);"
           "--text:#f7f7fb;"
           "--muted:#a6a6b8;"
           "--acc:" + ac + ";"
           "--acc2:" + ac + ";"
           "--err:#ef4444;"
           "--radius:10px;";
}

static void apply_challenge_profile_tuning(
    int type,
    int& adaptive_pow_bits,
    int& wait_min_ms,
    int& wait_max_ms,
    int& adaptive_pow_mem_mb,
    int& adaptive_pow_cpu_level
) {
    int t = clamp_challenge_type(type);
    int family = (t - 1) / 6; // 0..10
    int slot = (t - 1) % 6;   // 0..5

    // Family controls global hardness trend.
    adaptive_pow_bits += (family / 2) - 1;     // -1..+4
    adaptive_pow_cpu_level += family / 3;      // 0..3
    wait_min_ms += family * 350;
    wait_max_ms += family * 700;

    // Slot controls fine-grained behavior diversity.
    adaptive_pow_bits += (slot >= 3 ? 1 : 0) - (slot == 0 ? 1 : 0);
    adaptive_pow_mem_mb += ((slot % 4) - 1) * 8; // -8,0,+8,+16
    adaptive_pow_cpu_level += (slot % 2 == 0 ? 1 : 0);

    if (slot == 4 || slot == 5) {
        wait_min_ms += 900;
        wait_max_ms += 1400;
    }
    if (slot == 2) {
        wait_min_ms = std::max(2500, wait_min_ms - 700);
        wait_max_ms = std::max(wait_min_ms + 1200, wait_max_ms - 1200);
    }

    adaptive_pow_bits = std::max(8, std::min(24, adaptive_pow_bits));
    adaptive_pow_mem_mb = std::max(8, std::min(192, adaptive_pow_mem_mb));
    adaptive_pow_cpu_level = std::max(1, std::min(8, adaptive_pow_cpu_level));
    wait_min_ms = std::max(2500, std::min(22000, wait_min_ms));
    wait_max_ms = std::max(wait_min_ms + 1000, std::min(26000, wait_max_ms));
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

static std::string ip_prefix_of(const std::string& ip, int v4_prefix_bits, int v6_prefix_bits) {
    in_addr a4{};
    if (inet_pton(AF_INET, ip.c_str(), &a4) == 1) {
        int bits = std::max(0, std::min(32, v4_prefix_bits));
        const uint32_t host = ntohl(a4.s_addr);
        const uint32_t mask = bits == 0 ? 0u : (bits == 32 ? 0xFFFFFFFFu : (0xFFFFFFFFu << (32 - bits)));
        const uint32_t pref = host & mask;
        std::ostringstream oss;
        oss << "v4:" << bits << ":" << pref;
        return oss.str();
    }
    in6_addr a6{};
    if (inet_pton(AF_INET6, ip.c_str(), &a6) == 1) {
        int bits = std::max(0, std::min(128, v6_prefix_bits));
        const int full = bits / 8;
        const int rem = bits % 8;
        std::ostringstream oss;
        oss << "v6:" << bits << ":";
        for (int i = 0; i < full; ++i) oss << std::hex << std::setw(2) << std::setfill('0') << static_cast<int>(a6.s6_addr[i]);
        if (rem > 0 && full < 16) {
            unsigned char masked = static_cast<unsigned char>(a6.s6_addr[full] & (0xFFu << (8 - rem)));
            oss << std::hex << std::setw(2) << std::setfill('0') << static_cast<int>(masked);
        }
        return oss.str();
    }
    return "";
}

static bool ip_in_same_prefix(const std::string& a, const std::string& b, int v4_prefix_bits, int v6_prefix_bits) {
    const std::string pa = ip_prefix_of(a, v4_prefix_bits, v6_prefix_bits);
    const std::string pb = ip_prefix_of(b, v4_prefix_bits, v6_prefix_bits);
    return !pa.empty() && pa == pb;
}

static bool source_bucket_allow(const Settings& s, const std::string& ip, const std::string& ua_fp, const std::string& bucket, int per_sec) {
    const std::time_t now = std::time(nullptr);
    const std::string key = ip_prefix_of(ip, s.session_ip_prefix_v4, s.session_ip_prefix_v6) + "|" + ua_fp + "|" + bucket;
    std::lock_guard<std::mutex> lock(g_source_bucket_mu);
    for (auto it = g_source_buckets.begin(); it != g_source_buckets.end();) {
        if (it->second.first + 30 < now) it = g_source_buckets.erase(it);
        else ++it;
    }
    auto& rec = g_source_buckets[key];
    if (rec.first != now) {
        rec.first = now;
        rec.second = 0;
    }
    rec.second++;
    return rec.second <= per_sec;
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

static bool session_has_ip(const SessionRec& sr, const std::string& ip) {
    for (const auto& known_ip : sr.ips) {
        if (known_ip == ip) return true;
    }
    return false;
}

static void session_add_ip(SessionRec& sr, const std::string& ip) {
    if (ip.empty() || session_has_ip(sr, ip)) return;
    sr.ips.push_back(ip);
    while (sr.ips.size() > 5) {
        sr.ips.erase(sr.ips.begin());
    }
}

static bool has_active_session_binding(const std::string& ip, const std::string& ua_fp) {
    std::lock_guard<std::mutex> lock(g_session_mu);
    const std::time_t now = std::time(nullptr);
    auto it = g_ip_session_map.find(session_scope_key(ip, ua_fp));
    if (it == g_ip_session_map.end()) return false;
    return !it->second.sid.empty() && it->second.exp >= now;
}

static void erase_sessions_for_ua(const std::string& ua_fp) {
    for (auto it = g_ip_session_map.begin(); it != g_ip_session_map.end();) {
        if (it->second.ua == ua_fp) it = g_ip_session_map.erase(it);
        else ++it;
    }
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

static std::string issue_token(const Settings& s, const std::string& ip, const std::string& ua_fp, const std::string& sid, const std::string& sid_fp) {
    json p;
    p["v"] = 2;
    p["ip"] = ip;
    p["ua"] = ua_fp;
    p["ua_fp"] = ua_fp;
    p["sid"] = sid;
    if (!sid_fp.empty()) p["sid_fp"] = sid_fp;
    p["iat"] = static_cast<long long>(std::time(nullptr));
    p["exp"] = static_cast<long long>(std::time(nullptr) + s.ttl);
    p["iss"] = s.node_id;
    p["kid"] = "v2";
    p["ip_prefix"] = ip_prefix_of(ip, s.session_ip_prefix_v4, s.session_ip_prefix_v6);
    p["net_prefix_v4"] = p["ip_prefix"];
    p["net_prefix_v6"] = p["ip_prefix"];
    p["risk"] = "normal";
    p["jti"] = random_token(12);
    std::string payload = p.dump();
    std::string b = base64url_encode(payload);
    std::string sig = hmac_sha256_b64url(s.secret, payload);
    return b + "." + sig;
}

static bool verify_token(const Settings& s, const std::string& token, const std::string& ip, const std::string& ua_fp, const std::string& req_sid_fp = "", std::string* reason_out = nullptr) {
    auto fail = [&](const std::string& why) -> bool {
        if (reason_out) *reason_out = why;
        return false;
    };
    std::size_t dot = token.find('.');
    if (dot == std::string::npos) return fail("token_format");
    std::string b = token.substr(0, dot);
    std::string sig = token.substr(dot + 1);
    std::string payload;
    if (!base64url_decode(b, payload)) return fail("token_decode");
    std::string expected = hmac_sha256_b64url(s.secret, payload);
    if (!secure_equals(expected, sig)) return fail("sig_invalid");
    try {
        json p = json::parse(payload);
        if (!p.is_object()) return fail("token_payload");
        long long exp = p.value("exp", 0LL);
        std::string sid = p.value("sid", std::string());
        if (exp < static_cast<long long>(std::time(nullptr))) return fail("expired");
        if (sid.empty()) return fail("sid_missing");
        const std::string token_ip = p.value("ip", std::string());
        const std::string token_ua = p.value("ua_fp", p.value("ua", std::string()));
        if (token_ip.empty() || token_ua.empty()) return fail("claims_missing");
        const std::string token_sid_fp = p.value("sid_fp", std::string());
        if (!token_sid_fp.empty() && (req_sid_fp.empty() || token_sid_fp != req_sid_fp)) {
            return fail("session_mismatch");
        }
        const bool ip_prefix_ok = ip_in_same_prefix(token_ip, ip, s.session_ip_prefix_v4, s.session_ip_prefix_v6);
        const bool ua_match = (token_ua == ua_fp);
        if (!ua_match && !ip_prefix_ok) return fail("ua_miss");
        if (!ip_prefix_ok) return fail("ip_prefix_miss");
        {
            std::lock_guard<std::mutex> lock(g_session_mu);
            const std::time_t now = std::time(nullptr);
            auto valid_entry = [&](const SessionRec& sr, const std::string& expect_ua) -> bool {
                return sr.sid == sid && sr.ua == expect_ua && sr.exp >= now;
            };
            auto valid_entry_relaxed = [&](const SessionRec& sr) -> bool {
                return sr.sid == sid && sr.exp >= now;
            };

            // Strict binding path.
            auto cur_it = g_ip_session_map.find(session_scope_key(ip, ua_fp));
            if (cur_it != g_ip_session_map.end() && valid_entry(cur_it->second, ua_fp)) {
                session_add_ip(cur_it->second, ip);
                return true;
            }
            if (!ua_match && cur_it != g_ip_session_map.end() && valid_entry_relaxed(cur_it->second)) {
                cur_it->second.ua = ua_fp;
                session_add_ip(cur_it->second, ip);
                return true;
            }

            // Mobile networks can rotate client IPs between requests. Bind the
            // clearance to sid+UA and allow up to five recent IPs for that sid.
            for (auto it = g_ip_session_map.begin(); it != g_ip_session_map.end(); ++it) {
                if (!valid_entry(it->second, ua_fp)) {
                    if (!(!ua_match && valid_entry_relaxed(it->second))) continue;
                }
                if (session_has_ip(it->second, ip) || it->second.ips.size() < 5) {
                    SessionRec migrated = it->second;
                    migrated.ua = ua_fp;
                    session_add_ip(migrated, ip);
                    g_ip_session_map[session_scope_key(ip, ua_fp)] = migrated;
                    return true;
                }
            }
            return fail("session_not_found");
        }
    } catch (...) {
        return fail("token_parse");
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

    const bool expensive_path =
        req.path == "/check" ||
        req.path == "/check-web" ||
        req.path == "/new" ||
        req.path == "/click" ||
        req.path == "/verify-math" ||
        req.path == "/solve";
    if (expensive_path && !source_bucket_allow(s, ip, ua_fp, req.path, req.path == "/check" || req.path == "/check-web" ? 35 : 12)) {
        log_event_json("rate_limited", {{"ip", ip}, {"path", req.path}, {"reason", "source_bucket"}});
        send_response(fd, 429, "Too Many Requests", "{\"ok\":false,\"error\":\"rate_limited\"}", {{"Content-Type", "application/json; charset=utf-8"}, {"Retry-After", "1"}}, head_only);
        close(fd);
        return;
    }

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
        std::string req_sid_fp = session_cookie_fingerprint(s, read_cookie(cookie, "pterodactyl_session"));
        std::string verify_reason;
        if (!tok.empty() && verify_token(s, tok, ip, ua_fp, req_sid_fp, &verify_reason)) {
            log_event_json("token_validated", {{"ok", true}, {"ip", ip}, {"node_id", s.node_id}});
            send_response(fd, 204, "No Content", "", {}, head_only);
        } else {
            log_event_json("session_mismatch", {{"ip", ip}, {"reason", verify_reason.empty() ? "no_cookie_or_invalid" : verify_reason}, {"node_id", s.node_id}});
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
        std::string req_sid_fp = session_cookie_fingerprint(s, read_cookie(cookie, "pterodactyl_session"));
        std::string verify_reason;
        if (!tok.empty() && verify_token(s, tok, ip, ua_fp, req_sid_fp, &verify_reason)) {
            log_event_json("token_validated", {{"ok", true}, {"ip", ip}, {"node_id", s.node_id}, {"path", "check-web"}});
            send_response(fd, 204, "No Content", "", {}, head_only);
        } else {
            if (has_active_session_binding(ip, ua_fp)) {
                log_event_json("token_validated", {{"ok", true}, {"ip", ip}, {"node_id", s.node_id}, {"path", "check-web-session"}});
                send_response(fd, 204, "No Content", "", {}, head_only);
            } else {
                log_event_json("session_mismatch", {{"ip", ip}, {"reason", verify_reason.empty() ? "no_cookie_or_invalid" : verify_reason}, {"node_id", s.node_id}, {"path", "check-web"}});
                send_response(fd, 401, "Unauthorized", "", {}, head_only);
            }
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
            log_event_json("provider_api_token_required", {
                {"ip", ip},
                {"node_id", s.node_id},
                {"path", "check-provider-api"}
            });
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
        const int challenge_type = resolve_challenge_type(s, nq);
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
        if (hinted_mobile) adaptive_pow_bits -= 2;
        if (suspicious_low_power_hint) adaptive_pow_bits = std::max(adaptive_pow_bits, s.pow_bits - 1);
        const int min_pow_bits = s.strict_mode ? (hinted_mobile ? 10 : std::max(11, s.pow_bits - 2)) : 8;
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
        if (hinted_mobile) adaptive_pow_mem_mb = std::min(adaptive_pow_mem_mb, 32);
        else adaptive_pow_mem_mb = std::min(adaptive_pow_mem_mb, 96);
        if (hinted_hc > 0 && hinted_hc <= 2) adaptive_pow_mem_mb = std::min(adaptive_pow_mem_mb, 24);
        if (suspicious_low_power_hint) adaptive_pow_mem_mb = std::max(adaptive_pow_mem_mb, 32);
        if (s.strict_mode) adaptive_pow_mem_mb = std::max(adaptive_pow_mem_mb, hinted_mobile ? 16 : 32);
        adaptive_pow_mem_mb = std::max(8, adaptive_pow_mem_mb);
        int adaptive_pow_cpu_level = 4;
        if (hinted_hc > 0) {
            if (hinted_hc <= 2) adaptive_pow_cpu_level = 2;
            else if (hinted_hc <= 4) adaptive_pow_cpu_level = 3;
            else if (hinted_hc <= 6) adaptive_pow_cpu_level = 4;
            else if (hinted_hc <= 10) adaptive_pow_cpu_level = 5;
            else adaptive_pow_cpu_level = 6;
        }
        if (hinted_mobile) adaptive_pow_cpu_level = std::max(1, adaptive_pow_cpu_level - 2);
        if (suspicious_low_power_hint) adaptive_pow_cpu_level = std::max(adaptive_pow_cpu_level, 3);
        if (s.strict_mode) adaptive_pow_cpu_level = std::max(adaptive_pow_cpu_level, hinted_mobile ? 2 : 3);
        adaptive_pow_cpu_level = std::max(1, std::min(8, adaptive_pow_cpu_level));
        apply_challenge_profile_tuning(
            challenge_type,
            adaptive_pow_bits,
            wait_min_ms,
            wait_max_ms,
            adaptive_pow_mem_mb,
            adaptive_pow_cpu_level
        );

        std::random_device rd;
        std::mt19937 gen(rd());
        std::uniform_int_distribution<int> wait_human_ms(wait_min_ms, wait_max_ms);
        const int challenge_mode = challenge_mode_for_type(challenge_type);
        const Phase1ChallengeSpec spec = build_phase1_challenge(gen, challenge_mode, challenge_type);
        std::string nonce = random_nonce();
        NonceRec rec;
        std::vector<int> pattern_nodes = generate_pattern_nodes(gen);
        {
            std::lock_guard<std::mutex> lock(g_nonce_mu);
            rec.ans = spec.answer;
            rec.ip = ip;
            rec.ua = ua_fp;
            rec.answer_key = "ans_" + random_token(6);
            rec.click_key = "clk_" + random_token(6);
            rec.behavior_key = "beh_" + random_token(6);
            rec.connection_key = "conn_" + random_token(6);
            rec.pattern_key = "pat_" + random_token(6);
            rec.pow_salt = "pow_" + random_token(12);
            rec.challenge_mode = challenge_mode;
            rec.voice_mode = spec.voice_enabled;
            rec.answer_numeric = spec.answer_numeric;
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
        out["question"] = spec.question;
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
        out["challenge_type"] = challenge_type;
        out["challenge_profile"] = challenge_type_name(challenge_type);
        out["challenge_mode"] = challenge_mode_name(challenge_type);
        out["phase1_numeric"] = rec.answer_numeric;
        out["phase1_voice_enabled"] = rec.voice_mode;
        out["phase1_label"] = spec.label;
        out["phase1_hint"] = spec.hint;
        out["phase1_input_placeholder"] = spec.input_placeholder;
        out["completion_id"] = "cmp_" + random_token(10);
        log_event_json("challenge_issued", {{"ip", ip}, {"node_id", s.node_id}, {"challenge_type", challenge_type}, {"nonce", nonce}});
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
        answer = normalize_expected_answer(answer, rec.answer_numeric, rec.voice_mode);
        const std::string expected_answer = normalize_expected_answer(rec.ans, rec.answer_numeric, rec.voice_mode);
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
        const int challenge_type = resolve_challenge_type(s, q);
        const std::string challenge_profile = challenge_type_name(challenge_type);
        const std::string challenge_theme_vars = challenge_theme_css_vars(s, challenge_type);
        std::string html =
            "<!doctype html><html><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">"
            "<title>DANEX X EL7 Clearance</title>"
            "<style>"
            ":root{" + challenge_theme_vars + "}"
            "*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:18px;"
            "font-family:'Trebuchet MS','Segoe UI',Tahoma,sans-serif;color:var(--text);"
            "background:radial-gradient(1200px 580px at 5% -5%,rgba(139,92,246,.16) 0%,transparent 62%),"
            "radial-gradient(1000px 620px at 95% 110%,rgba(6,182,212,.07) 0%,transparent 60%),"
            "linear-gradient(180deg,var(--bg),var(--bg2))}"
            ".card{width:min(840px,98vw);background:linear-gradient(180deg,rgba(255,255,255,.045),rgba(255,255,255,.012)),var(--card);"
            "border:1px solid var(--line);border-radius:var(--radius,12px);box-shadow:0 30px 80px rgba(0,0,0,.52),0 0 42px rgba(139,92,246,.16);overflow-y:auto;max-height:98vh}"
            ".head{padding:16px 18px;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:10px;background:#111117}"
            ".dot{width:10px;height:10px;border-radius:999px;background:var(--acc);box-shadow:0 0 20px rgba(139,92,246,.85)}"
            ".title{font-weight:800;letter-spacing:.25px}.sub{margin-left:auto;font-size:12px;color:var(--muted);font-weight:600}"
            ".body{padding:18px}.tabs{display:flex;gap:8px;margin:0 0 14px}.tab{flex:1;text-align:center;padding:9px 10px;border:1px solid rgba(139,92,246,.28);border-radius:11px;color:var(--muted);font-size:12px;background:#0b0b10}"
            ".tab.on{color:#fff;background:var(--acc);border-color:rgba(255,255,255,.18);box-shadow:0 0 22px rgba(139,92,246,.26)}.pane{display:none}.pane.on{display:block}.big{padding:16px;border:1px solid rgba(139,92,246,.24);border-radius:13px;background:#0b0b10}"
            ".phase-ind{display:inline-block;margin:0 0 8px;padding:4px 10px;border-radius:999px;font-size:11px;font-weight:800;letter-spacing:.9px;border:1px solid rgba(139,92,246,.38);color:#ddd6fe;background:rgba(139,92,246,.12)}"
            ".phase-ind.p1{border-color:#1f7b4a;background:#113324;color:#bfffe0;box-shadow:0 0 14px rgba(34,180,95,.22)}"
            ".phase-ind.p2{border-color:#7a2db3;background:#2a1440;color:#f2d9ff;box-shadow:0 0 14px rgba(196,103,255,.24)}"
            ".connbox{position:relative;min-height:56vh;max-height:74vh;padding:16px}.human-wrap{position:absolute;left:16px;top:16px;display:block}"
            ".timer{font-size:32px;font-weight:900;letter-spacing:.7px;color:#f7f7fb;text-shadow:0 0 14px rgba(139,92,246,.4)}.q{margin:0 0 10px;color:var(--muted);font-size:14px;line-height:1.5}"
            ".qa{margin:0 0 12px;padding:12px;border:1px solid rgba(139,92,246,.24);border-radius:10px;background:#111117;color:#f7f7fb;font-weight:700;white-space:pre-wrap}"
            ".pat{margin:0 0 12px;padding:12px;border:1px solid rgba(139,92,246,.24);border-radius:10px;background:#0b0b10;display:none}"
            ".pat canvas{display:block;width:100%;max-width:300px;aspect-ratio:1/1;background:#09090d;border:1px solid rgba(139,92,246,.32);border-radius:10px;touch-action:none;margin:0 auto}"
            ".row{display:flex;gap:10px}.row input{flex:1}"
            "input,button{border-radius:11px;border:1px solid rgba(139,92,246,.42);background:#0b0b10;color:var(--text);padding:12px 14px;font-size:14px;outline:none}"
            "input:focus{border-color:var(--acc);box-shadow:0 0 0 2px rgba(139,92,246,.22)}"
            "button{cursor:pointer;background:var(--acc);border-color:rgba(255,255,255,.18);font-weight:800;min-width:118px;box-shadow:0 0 24px rgba(139,92,246,.28);transition:transform .18s ease,box-shadow .18s ease}"
            "#human_btn{width:200px;min-height:34px;font-size:12px;padding:8px 12px;letter-spacing:.1px;box-shadow:0 8px 22px rgba(139,92,246,.3)}"
            "button.secondary{background:#0b0b10;border-color:rgba(139,92,246,.34);color:#d8d8e8}"
            "button:hover{transform:translateY(-1px);box-shadow:0 0 32px rgba(139,92,246,.38)}button:disabled{opacity:.66;cursor:not-allowed;transform:none}"
            ".hint{margin-top:10px;color:var(--muted);font-size:12px}.status{margin-top:10px;color:#a78bfa;min-height:18px;font-size:13px}.err{margin-top:6px;color:var(--err);min-height:18px;font-size:13px}"
            "@media (max-width:640px){.connbox{min-height:52vh}.row{flex-direction:column}button{width:100%}#human_btn{width:142px;min-height:30px;font-size:10px;padding:6px 8px}}"
            "body{background:linear-gradient(180deg,var(--bg),var(--bg2));font-family:'Segoe UI',Tahoma,sans-serif;padding:14px}"
            ".card{width:min(760px,98vw);background:var(--card);border:1px solid var(--line);border-radius:8px;box-shadow:0 6px 18px rgba(0,0,0,.22)}"
            ".head{padding:12px 14px;border-bottom:1px solid var(--line)}.title{font-size:15px}.sub{font-size:12px}.dot{background:var(--acc);box-shadow:none}"
            ".body{padding:14px}.tabs{margin:0 0 10px;gap:8px}.tab{padding:8px 10px;border-radius:7px;border-color:var(--line);background:#0b0b10;color:var(--muted)}"
            ".tab.on{background:#8b5cf6;border-color:rgba(255,255,255,.18);color:var(--text)}"
            ".big{background:transparent;border:0;padding:0}.connbox{min-height:260px;max-height:none;padding:10px 0 0 0;position:relative;display:block;overflow:hidden}"
            ".human-wrap{position:absolute;left:14px;top:90px;display:block;margin:0;z-index:2}"
            ".phase-ind{border-radius:999px;padding:4px 10px;border-color:rgba(139,92,246,.38);background:rgba(139,92,246,.12);color:#f7f7fb;letter-spacing:.3px}"
            ".phase-ind.p1,.phase-ind.p2{border-color:rgba(139,92,246,.38);background:rgba(139,92,246,.12);color:#f7f7fb;box-shadow:none}"
            ".q{font-size:14px;color:var(--muted);margin:0 0 8px}.qa{margin:0 0 10px;padding:12px;border-radius:7px;border:1px solid var(--line);background:#111117;color:var(--text)}"
            ".pat{margin:0 0 10px;padding:10px;border-radius:7px;border:1px solid var(--line);background:#111117}"
            ".row{gap:8px}.row input{min-width:0}input,button{height:40px;padding:9px 12px;border-radius:7px;border:1px solid var(--line);background:#111117;color:var(--text)}"
            "input:focus{border-color:#8b5cf6;box-shadow:0 0 0 1px rgba(139,92,246,.35)}"
            "button{background:var(--acc);border-color:var(--acc);min-width:110px}button.secondary{background:#111117;border-color:var(--line);color:#d8d8e8}"
            "#human_btn{width:auto;min-width:160px;min-height:34px;padding:0 12px;letter-spacing:.1px;box-shadow:none}"
            "button:hover{filter:brightness(1.03)}.hint{font-size:13px;color:var(--muted)}.status{color:#a78bfa}.err{color:var(--err)}.timer{text-shadow:none;font-size:24px}"
            "@media (max-width:640px){.card{width:100%}.body{padding:12px}.tab{padding:7px 8px}.q{font-size:13px}.row{flex-direction:column}button{width:100%}.connbox{min-height:200px;padding-top:8px}.human-wrap{top:76px}#human_btn{width:auto!important;min-width:120px;min-height:30px;font-size:10px;padding:0 8px}}"
            "@keyframes dx-in{0%{opacity:0;transform:translateY(16px) scale(.985);filter:blur(5px)}100%{opacity:1;transform:translateY(0) scale(1);filter:blur(0)}}"
            "@keyframes dx-pulse{0%,100%{opacity:.55;transform:scale(.9)}50%{opacity:1;transform:scale(1.14)}}"
            "@keyframes dx-scan{0%{transform:translateY(-120%);opacity:0}28%,60%{opacity:.52}100%{transform:translateY(120%);opacity:0}}"
            "@keyframes dx-mark{0%,100%{transform:translateX(-7px);opacity:.58}50%{transform:translateX(7px);opacity:1}}"
            "@keyframes dx-grid{0%{background-position:0 0,0 0}100%{background-position:76px 0,0 76px}}"
            "@keyframes dx-geo{0%{transform:translate3d(-2%,-1%,0) rotate(-2deg);opacity:.38}50%{opacity:.72}100%{transform:translate3d(2%,1%,0) rotate(2deg);opacity:.5}}"
            "@keyframes dx-node{0%,100%{transform:scale(.92);opacity:.45}50%{transform:scale(1.08);opacity:1}}"
            "html,body{height:100%;overflow:hidden}body{position:relative;display:grid;place-items:center;background:radial-gradient(900px 520px at 12% -8%,rgba(139,92,246,.18),transparent 64%),radial-gradient(760px 520px at 92% 104%,rgba(6,182,212,.08),transparent 62%),linear-gradient(180deg,#07070b,#111117)!important}"
            "body:before{content:'';position:fixed;inset:0;pointer-events:none;background:repeating-linear-gradient(90deg,rgba(255,255,255,.025) 0 1px,transparent 1px 64px),repeating-linear-gradient(0deg,rgba(255,255,255,.018) 0 1px,transparent 1px 64px);opacity:.62;animation:dx-grid 24s linear infinite}"
            "body:after{content:'';position:fixed;left:0;right:0;top:0;height:34vh;pointer-events:none;background:linear-gradient(180deg,transparent,rgba(139,92,246,.08),transparent);mix-blend-mode:screen;animation:dx-scan 8s linear infinite}"
            ".geo{position:fixed;inset:0;pointer-events:none;overflow:hidden;z-index:0}.geo:before,.geo:after{content:'';position:absolute;border:1px solid rgba(139,92,246,.18);box-shadow:0 0 38px rgba(139,92,246,.08);transform-origin:center;animation:dx-geo 18s ease-in-out infinite alternate}.geo:before{width:42vw;height:42vw;left:-12vw;top:12vh;clip-path:polygon(50% 0,100% 50%,50% 100%,0 50%)}.geo:after{width:48vw;height:26vw;right:-14vw;bottom:4vh;clip-path:polygon(0 18%,78% 0,100% 72%,18% 100%);animation-duration:22s;animation-direction:alternate-reverse}.nodefield{position:fixed;inset:0;pointer-events:none;z-index:0;background:radial-gradient(circle at 18% 28%,rgba(139,92,246,.32) 0 2px,transparent 3px),radial-gradient(circle at 72% 18%,rgba(6,182,212,.22) 0 2px,transparent 3px),radial-gradient(circle at 84% 76%,rgba(139,92,246,.24) 0 2px,transparent 3px),radial-gradient(circle at 32% 82%,rgba(255,255,255,.16) 0 1px,transparent 2px);animation:dx-node 3.4s ease-in-out infinite}"
            ".card{position:relative;width:min(940px,calc(100vw - 22px))!important;max-height:calc(100vh - 22px)!important;border-radius:18px!important;background:linear-gradient(180deg,rgba(255,255,255,.045),rgba(255,255,255,.012)),#0b0b10!important;border:1px solid rgba(139,92,246,.42)!important;box-shadow:0 32px 96px rgba(0,0,0,.62),0 0 58px rgba(139,92,246,.18)!important;animation:dx-in .42s cubic-bezier(.4,0,.2,1) both;overflow:hidden!important;display:flex;flex-direction:column}"
            ".card:before{content:'';position:absolute;inset:0;pointer-events:none;background:linear-gradient(90deg,transparent,rgba(139,92,246,.08),transparent);transform:translateX(-120%);animation:dx-scan 5.8s ease-in-out infinite}"
            ".head{position:relative;display:grid!important;grid-template-columns:auto minmax(0,1fr) auto;gap:14px;align-items:center;padding:18px 20px!important;background:#111117!important;border-bottom:1px solid rgba(139,92,246,.28)!important}"
            ".dot{display:none}.coremark{width:54px;height:44px;border-radius:13px;border:1px solid rgba(139,92,246,.52);background:#0b0b10;box-shadow:inset 0 0 20px rgba(139,92,246,.12),0 0 24px rgba(139,92,246,.16);position:relative;overflow:hidden}"
            ".coremark:before{content:'';position:absolute;left:12px;right:12px;top:11px;height:3px;background:#8b5cf6;box-shadow:0 10px 0 #06b6d4,0 20px 0 rgba(139,92,246,.58);animation:dx-mark 1.5s ease-in-out infinite}"
            ".brand{min-width:0;display:flex;flex-direction:column;gap:3px}.eyebrow{font-size:10px;letter-spacing:.2em;text-transform:uppercase;color:#a78bfa;font-weight:900}.title{font-size:22px!important;line-height:1;font-weight:900!important;letter-spacing:.08em;text-transform:uppercase;color:#f7f7fb!important}.sub{margin-left:0!important;justify-self:end;border:1px solid rgba(139,92,246,.3);background:#0b0b10;border-radius:999px;padding:7px 10px;color:#d8d8e8!important;font-size:11px!important;letter-spacing:.08em;text-transform:uppercase;white-space:nowrap}"
            ".body{position:relative;padding:18px!important;overflow:auto!important;min-height:0;flex:1 1 auto;overscroll-behavior:contain;-webkit-overflow-scrolling:touch}.pane.on{display:block;animation:dx-in .24s cubic-bezier(.4,0,.2,1) both}.telemetry{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-bottom:12px}.metric{border:1px solid rgba(139,92,246,.22);border-radius:11px;background:#09090d;padding:9px 10px;color:#a6a6b8;font-size:10px;letter-spacing:.12em;text-transform:uppercase}.metric b{display:block;margin-top:3px;color:#f7f7fb;font-size:12px;letter-spacing:.04em}"
            ".tabs{display:grid!important;grid-template-columns:1fr 1fr;background:#09090d;border:1px solid rgba(139,92,246,.22);border-radius:12px;padding:6px;gap:6px!important}.tab{border-radius:9px!important;border:1px solid transparent!important;background:transparent!important;text-transform:uppercase;letter-spacing:.1em;font-weight:900}.tab.on{background:#8b5cf6!important;border-color:rgba(255,255,255,.18)!important;box-shadow:0 0 24px rgba(139,92,246,.28)!important;color:#fff!important}"
            ".connbox{min-height:360px!important;border:1px solid rgba(139,92,246,.22)!important;border-radius:14px!important;background:radial-gradient(circle at 80% 20%,rgba(139,92,246,.12),transparent 18rem),#09090d!important;padding:18px!important;overflow:hidden!important}"
            ".connbox:before{content:'BOOT SEQUENCE';position:absolute;right:18px;top:18px;color:rgba(167,139,250,.18);font-weight:900;font-size:30px;letter-spacing:.08em}.timer{font-size:44px!important;text-shadow:0 0 22px rgba(139,92,246,.45)!important}.human-wrap{left:auto!important;right:18px!important;top:auto!important;bottom:18px!important}.phase-ind{margin-top:2px!important}.qa,.pat,#phase1_widget{border-radius:12px!important}.pat{position:relative;z-index:2;overflow:visible!important}.pat canvas{width:min(100%,360px)!important;max-width:360px!important;border-color:rgba(139,92,246,.46)!important;box-shadow:inset 0 0 0 1px rgba(255,255,255,.04),0 0 28px rgba(139,92,246,.12);cursor:pointer}.status,.err{border-radius:10px;padding:8px 10px;background:#09090d;border:1px solid rgba(139,92,246,.18)}"
            "button{min-height:42px!important;padding:10px 15px!important;border-radius:10px!important;display:inline-flex!important;align-items:center;justify-content:center;gap:7px;line-height:1.1!important}#human_btn{min-width:190px!important;box-shadow:0 0 28px rgba(139,92,246,.34)!important}.row{position:relative;z-index:3;margin-top:10px!important}"
            ".challenge-grid{display:grid;grid-template-columns:150px minmax(0,1fr);gap:14px;min-height:0}.tabs{display:flex!important;flex-direction:column!important;grid-template-columns:none!important;align-self:start;position:sticky;top:0;padding:8px!important;border-radius:14px!important;background:#09090d!important}.tab{position:relative;text-align:left!important;padding:12px 12px 12px 34px!important}.tab:before{content:'';position:absolute;left:12px;top:50%;width:9px;height:9px;border-radius:999px;background:#2b2b34;border:1px solid rgba(139,92,246,.35);transform:translateY(-50%)}.tab.on:before{background:#10b981;box-shadow:0 0 18px rgba(16,185,129,.52)}.monitor{min-width:0;border:1px solid rgba(139,92,246,.24);border-radius:16px;background:#07070b;box-shadow:inset 0 0 0 1px rgba(255,255,255,.025),0 20px 58px rgba(0,0,0,.34);overflow:hidden}.monitor-top{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:1px;background:rgba(139,92,246,.18);border-bottom:1px solid rgba(139,92,246,.24)}.monitor-top span{display:block;background:#111117;padding:10px 12px;color:#8e8ea3;font-size:10px;letter-spacing:.12em;text-transform:uppercase}.monitor-top b{display:block;margin-top:3px;color:#f7f7fb;font-size:12px;letter-spacing:0}.pane{padding:16px!important}.pane.on{display:block!important}.connbox{min-height:340px!important;border:0!important;border-radius:0!important;background:radial-gradient(circle at 84% 16%,rgba(139,92,246,.16),transparent 18rem),linear-gradient(180deg,#09090d,#07070b)!important}.phase-ind{display:inline-flex!important;align-items:center;gap:8px}.phase-ind:before{content:'';width:8px;height:8px;border-radius:999px;background:currentColor;box-shadow:0 0 16px currentColor}.qa{font-family:Menlo,Consolas,monospace!important}.status,.err{margin:12px 16px 16px!important}.command-bar{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px}.hint{border-top:1px solid rgba(139,92,246,.14);padding-top:10px}.card:after{content:'';position:absolute;left:0;right:0;top:0;height:2px;background:linear-gradient(90deg,transparent,#8b5cf6,#06b6d4,transparent);opacity:.9;animation:dx-mark 2.8s ease-in-out infinite}"
            "@media(max-width:680px){body{padding:10px!important;align-items:center}.card{max-height:calc(100vh - 20px)!important;min-height:0!important}.head{grid-template-columns:auto 1fr!important}.sub{grid-column:1/-1;justify-self:start;white-space:normal}.telemetry{grid-template-columns:1fr}.challenge-grid{grid-template-columns:1fr}.tabs{position:relative;display:grid!important;grid-template-columns:1fr 1fr!important;flex-direction:row!important}.monitor-top{grid-template-columns:1fr 1fr}.connbox{min-height:300px!important}.timer{font-size:32px!important}.human-wrap{left:14px!important;right:14px!important;bottom:14px!important}#human_btn{width:100%!important}.title{font-size:18px!important}.pat{padding:10px!important}.pat canvas{width:min(100%,320px)!important}.command-bar{grid-template-columns:1fr}}"
            "</style></head><body><div class=\"geo\" aria-hidden=\"true\"></div><div class=\"nodefield\" aria-hidden=\"true\"></div><div class=\"card\">"
            "<div class=\"head\"><span class=\"coremark\" aria-hidden=\"true\"></span><span class=\"brand\"><span class=\"eyebrow\">DANEX X EL7</span><span class=\"title\">Clearance Core</span></span><span class=\"sub\">Type #" + std::to_string(challenge_type) + " • " + challenge_profile + " • 30m</span></div>"
            "<div class=\"body\"><div class=\"telemetry\"><span class=\"metric\">Binding<b>IP + UA</b></span><span class=\"metric\">Runtime<b>Proof Active</b></span><span class=\"metric\">Mode<b>Interactive</b></span></div><div class=\"challenge-grid\"><div class=\"tabs\"><div class=\"tab on\" id=\"tab_conn\">Link</div><div class=\"tab\" id=\"tab_chal\">Solve</div><div class=\"tab\">Pattern</div><div class=\"tab\">Proof</div></div><div class=\"monitor\"><div class=\"monitor-top\"><span>Connection<b id=\"ctimer\">--</b></span><span>Phase<b>Adaptive</b></span><span>Proof<b>PoW</b></span><span>Clearance<b>30m</b></span></div>"
            "<div class=\"pane on\" id=\"pane_conn\"><div class=\"big connbox\" id=\"connbox\"><p class=\"q\">Checking connection integrity...</p><div class=\"timer\" aria-hidden=\"true\">SYNC</div><p class=\"q\">Klik tombol untuk buka challenge manual. Session tetap dikunci ke IP + User-Agent.</p><div class=\"human-wrap\" id=\"human_wrap\"><button id=\"human_btn\" type=\"button\" disabled>Preparing challenge...</button></div></div></div>"
            "<div class=\"pane\" id=\"pane_chal\"><div class=\"phase-ind p1\" id=\"phase_ind\">PHASE 1</div><p class=\"q\" id=\"phaseq\">Tahap 1: selesaikan challenge.</p><p class=\"q\" id=\"phint\"></p>"
            "<p class=\"qa\" id=\"q\">Memuat challenge...</p><div class=\"pat\" id=\"phase1_widget\" style=\"display:block\"></div><div class=\"pat\" id=\"patbox\"><canvas id=\"pc\" width=\"280\" height=\"280\"></canvas></div><div class=\"row command-bar\" id=\"ainput_wrap\"><input id=\"a\" placeholder=\"Masukkan jawaban\"/><button id=\"mic_btn\" type=\"button\" class=\"secondary\" style=\"display:none;min-width:120px;\">Use Mic</button></div><div class=\"row\"><button id=\"b\">Continue</button><button id=\"rb\" type=\"button\" class=\"secondary\">Restart (3)</button></div>"
            "<div class=\"hint\">Tip: gunakan perangkat normal (mouse/touch/scroll) agar lolos validasi anti-bot.</div></div><div class=\"status\" id=\"s\"></div><div class=\"err\" id=\"e\"></div></div></div></div></div>"
            "<script>const CHALLENGE_TYPE=" + std::to_string(challenge_type) + ";let nonce=\"\",ak=\"\",hk=\"\",bk=\"\",ck=\"\",pk=\"\",powSalt=\"\",powHash=\"\";let powBits=14,powMemMb=48,powCpuLevel=4,powCounter=-1,powReady=false;let phase=1;let pseq=[];let clicked=[];let pTrace=[];let pStart=0;let pDir=0;let pActive=false;let ppx=null,ppy=null,pvdx=0,pvdy=0;let started=Date.now();let unlockAt=0;let waitTimer=null;let humanMoveTimer=null;let humanReady=false;let enteredChallenge=false;let clickVerified=false;let hardOpened=false;let uiLocked=false;let pm=0,pd=0,pdc=0,tm=0,sc=0,kc=0,px=null,py=null,pvx=0,pvy=0;let lastBX=-1,lastBY=-1;let humanPauseUntil=0;let phase2Hint='';let phase1Label='Tahap 1: selesaikan challenge.';let phase1Hint='';let phase1Placeholder='Masukkan jawaban';let phase1Mode='variant_1';let phase1Numeric=true;let phase1VoiceEnabled=false;let phase1ConceptPassed=false;let phase1ConceptType=0;let voiceListening=false;let restartsLeft=3;let clickSamples=[];let clickResetBusy=false;let clickLastMark=0;let hardPenaltyUntil=0;let pendingHumanClick=false;let powTaskActive=false,pendingFinalSubmit=false,powLoopStop=false,challengeSolved=false;let patternPulse=0;const HARD_PENALTY_MS=9*60*60*1000;const CLICK_LIMIT_PER_SEC=(((navigator&&navigator.maxTouchPoints)||0)>=1||/android|iphone|ipad|ipod|mobile/i.test(String((navigator&&navigator.userAgent)||'')))?30:10;"
            "const elQ=document.getElementById('q'),elA=document.getElementById('a'),elB=document.getElementById('b'),elRB=document.getElementById('rb'),elS=document.getElementById('s'),elE=document.getElementById('e'),elCT=document.getElementById('ctimer'),elHB=document.getElementById('human_btn'),elCW=document.getElementById('connbox'),elHW=document.getElementById('human_wrap'),elPC=document.getElementById('pane_conn'),elPH=document.getElementById('pane_chal'),elTC=document.getElementById('tab_conn'),elTH=document.getElementById('tab_chal'),elPI=document.getElementById('phase_ind'),elPQ=document.getElementById('phaseq'),elPHint=document.getElementById('phint'),elPat=document.getElementById('patbox'),elW=document.getElementById('phase1_widget'),elInputWrap=document.getElementById('ainput_wrap'),elMic=document.getElementById('mic_btn'),pc=document.getElementById('pc'),ctx=pc.getContext('2d');"
            "function normAns(v){let s=String(v||'').trim();if(!s)return s;s=s.replace(/[−–—﹣－]/g,'-').replace(/[＋]/g,'+');const sign=(s[0]==='+'||s[0]==='-')?s[0]:'';if(sign)s=s.slice(1);s=s.replace(/[\\s,._'\\u00A0\\u202F]/g,'');return sign+s;}"
            "function phase1Value(v){const raw=String(v||'').trim();return phase1Numeric?normAns(raw):raw;}"
            "const SpeechRec=window.SpeechRecognition||window.webkitSpeechRecognition||null;"
            "function configureVoiceUI(){if(!elMic)return;const isVoice=!!phase1VoiceEnabled;if(!isVoice){elMic.style.display='none';elMic.disabled=true;return;}elMic.style.display='';if(!SpeechRec){elMic.disabled=true;elMic.textContent='Mic N/A';if(elPHint){elPHint.textContent=(phase1Hint?phase1Hint+' ':'')+'Mic tidak didukung browser ini, ketik manual.';}return;}elMic.disabled=false;elMic.textContent=voiceListening?'Listening...':'Use Mic';}"
            "async function captureVoiceInput(){if(!SpeechRec||voiceListening||!elMic)return;voiceListening=true;configureVoiceUI();try{const rec=new SpeechRec();rec.lang='id-ID';rec.interimResults=false;rec.maxAlternatives=1;await new Promise((resolve,reject)=>{let done=false;rec.onresult=(ev)=>{try{const tx=String((((ev||{}).results||[])[0]||[])[0]?.transcript||'').trim();if(elA&&tx)elA.value=tx;done=true;resolve();}catch(_e){reject(new Error('voice_parse_failed'));}};rec.onerror=()=>{if(!done)reject(new Error('voice_failed'));};rec.onend=()=>{if(!done)resolve();};rec.start();setTimeout(()=>{try{rec.stop();}catch(_e){}},6500);});if(elS){elS.textContent='Voice input captured.';}}catch(_e){if(elE){elE.textContent='Voice input gagal, ketik manual.';}}voiceListening=false;configureVoiceUI();}"
            "function setupPhase1Concept(){if(!elW){phase1ConceptPassed=true;return;}if(CHALLENGE_TYPE===1||phase1VoiceEnabled){elW.innerHTML='';elW.style.display='none';phase1ConceptPassed=true;return;}elW.style.display='block';phase1ConceptType=((CHALLENGE_TYPE-2)%64+64)%64;const fam=Math.floor(phase1ConceptType/8),v=phase1ConceptType%8;phase1ConceptPassed=false;const done=()=>{if(phase1ConceptPassed)return;phase1ConceptPassed=true;if(elS)elS.textContent='Concept check passed.';if(elW){elW.setAttribute('data-locked','1');elW.querySelectorAll('button,input,select,textarea').forEach(n=>{try{n.disabled=true;}catch(_e){}});elW.querySelectorAll('[draggable=\"true\"],[data-e],[data-n],[data-b],[data-m],[data-s]').forEach(n=>{n.style.pointerEvents='none';});}};if(elW)elW.removeAttribute('data-locked');if(fam===0){const target=((17*phase1ConceptType+11)%97)+2;const tol=1+(v%3);elW.innerHTML='<div style=\"margin-bottom:6px\">Slider presisi: set ke '+String(target)+' (±'+String(tol)+').</div><input id=\"w_slider\" type=\"range\" min=\"0\" max=\"100\" value=\"0\" style=\"width:100%\"><div id=\"w_sv\">0</div>';const sl=document.getElementById('w_slider');const sv=document.getElementById('w_sv');if(sl&&sv){sl.oninput=()=>{const x=Number(sl.value||0);sv.textContent=String(x);if(Math.abs(x-target)<=tol)done();};}return;}if(fam===1){const count=3+(v>=4?1:0);elW.innerHTML='<div style=\"margin-bottom:6px\">Drag urutkan tile 1..'+String(count)+'.</div><div id=\"w_slots\" style=\"display:flex;gap:6px;flex-wrap:wrap;margin-bottom:6px\">'+Array.from({length:count},(_,i)=>'<div data-s=\"'+String(i+1)+'\" style=\"flex:1;border:1px dashed #8b5cf6;min-height:34px;padding:6px;min-width:64px\"></div>').join('')+'</div><div id=\"w_tiles\" style=\"display:flex;gap:6px;flex-wrap:wrap\">'+Array.from({length:count},(_,i)=>'<div draggable=\"true\" data-v=\"'+String(i+1)+'\" style=\"padding:6px 10px;border:1px solid #8b5cf6;cursor:grab\">'+String(i+1)+'</div>').join('')+'</div>';const tiles=Array.from(elW.querySelectorAll('[draggable=\"true\"]'));for(let i=tiles.length-1;i>0;i--){const j=(phase1ConceptType+i*5)%tiles.length;const p=tiles[i].parentNode;if(p&&tiles[j])p.insertBefore(tiles[j],tiles[i]);}let drag=null;elW.querySelectorAll('[draggable=\"true\"]').forEach(t=>t.addEventListener('dragstart',()=>{drag=t;}));elW.querySelectorAll('[data-s]').forEach(s=>{s.addEventListener('dragover',e=>e.preventDefault());s.addEventListener('drop',e=>{e.preventDefault();if(!drag)return;s.innerHTML='';s.appendChild(drag);const ok=Array.from(elW.querySelectorAll('[data-s]')).every(x=>{const c=x.querySelector('[data-v]');return c&&String(c.getAttribute('data-v'))===String(x.getAttribute('data-s'));});if(ok)done();});});return;}if(fam===2){const len=3+(v%3);let seq=[];for(let i=0;i<len;i++)seq.push(((phase1ConceptType*3+i*2)%7)+1);let pos=0;elW.innerHTML='<div style=\"margin-bottom:6px\">Tap sequence: '+seq.join('-')+'</div><div style=\"display:flex;gap:6px;flex-wrap:wrap\">'+[1,2,3,4,5,6,7].map(n=>'<button type=\"button\" class=\"secondary\" data-n=\"'+n+'\" style=\"min-width:44px\">'+n+'</button>').join('')+'</div>';elW.querySelectorAll('[data-n]').forEach(b=>{b.addEventListener('click',()=>{const n=Number(b.getAttribute('data-n'));if(n===seq[pos]){pos++;if(pos>=seq.length)done();}else{pos=0;}});});return;}if(fam===3){const packs=[['🍎','🍏','🍋','🍇'],['⚽','🏀','🏐','🎾'],['🚗','🚕','🚌','🚓'],['⭐','🌙','☀️','☁️']];const arr=packs[v%packs.length];const target=arr[(phase1ConceptType+v)%arr.length];const need=2+(v%3);let hit=0;elW.innerHTML='<div style=\"margin-bottom:6px\">Klik '+String(need)+'x simbol '+target+'.</div><div style=\"display:flex;gap:6px;font-size:24px;flex-wrap:wrap\">'+Array.from({length:9},(_,i)=>{const e=(i%4===0)?target:arr[(i+phase1ConceptType)%arr.length];return '<span data-e=\"'+e+'\" style=\"cursor:pointer;padding:2px 6px;border:1px solid rgba(139,92,246,.32)\">'+e+'</span>';}).join('')+'</div>';elW.querySelectorAll('[data-e]').forEach(x=>{x.addEventListener('click',()=>{if(x.getAttribute('data-e')===target){x.style.opacity='0.35';x.style.pointerEvents='none';hit++;if(hit>=need)done();}});});return;}if(fam===4){const hold=900+v*300;let t0=0,ok=false;elW.innerHTML='<div style=\"margin-bottom:6px\">Hold tepat '+String((hold/1000).toFixed(1))+' detik.</div><button id=\"w_hold\" type=\"button\" class=\"secondary\" style=\"min-width:160px\">Hold</button><div id=\"w_hs\" style=\"margin-top:6px\">0%</div>';const hb=document.getElementById('w_hold');const hs=document.getElementById('w_hs');const start=()=>{t0=Date.now();ok=false;};const stop=()=>{if(!t0)return;const dt=Date.now()-t0;t0=0;if(dt>=hold&&dt<hold+700){ok=true;done();}if(hs)hs.textContent=ok?'OK':'Ulangi';};const tick=()=>{if(!t0||!hs||ok)return;const p=Math.max(0,Math.min(100,Math.floor(((Date.now()-t0)/hold)*100)));hs.textContent=String(p)+'%';if(p>=100)hs.textContent='Lepas sekarang';};if(hb){hb.addEventListener('mousedown',start);hb.addEventListener('touchstart',start,{passive:true});hb.addEventListener('mouseup',stop);hb.addEventListener('mouseleave',stop);hb.addEventListener('touchend',stop);setInterval(tick,80);}return;}if(fam===5){const bits=4+(v>=4?1:0);const max=(1<<bits)-1;const target=((phase1ConceptType*37)+v*11)%Math.max(2,max);let val=0;const bstr=(n)=>n.toString(2).padStart(bits,'0');const bvals=Array.from({length:bits},(_,i)=>(1<<(bits-1-i)));elW.innerHTML='<div style=\"margin-bottom:6px\">Atur bit ke '+bstr(target)+'</div><div id=\"w_bits\" style=\"display:flex;gap:6px;flex-wrap:wrap\">'+bvals.map(b=>'<button type=\"button\" class=\"secondary\" data-b=\"'+b+'\" style=\"min-width:42px\">0</button>').join('')+'</div><div id=\"w_bv\" style=\"margin-top:6px\">'+bstr(0)+'</div>';const bv=document.getElementById('w_bv');elW.querySelectorAll('[data-b]').forEach(b=>{b.addEventListener('click',()=>{const bit=Number(b.getAttribute('data-b')||0);val=(val^bit);b.textContent=((val&bit)!==0)?'1':'0';if(bv)bv.textContent=bstr(val);if(val===target)done();});});return;}if(fam===6){const gx=((phase1ConceptType+v)%4)+1,gy=((phase1ConceptType*2+v)%4)+1;let x=0,y=0,steps=0;const blocks=[[1,1],[3,1],[1,3],[3,3],[2,1],[2,3],[1,2],[3,2]].slice(0,2+(v%3));const isBlock=(ix,iy)=>blocks.some(b=>b[0]===ix&&b[1]===iy);elW.innerHTML='<div style=\"margin-bottom:6px\">Grid route: capai G('+gx+','+gy+') hindari kotak merah.</div><div id=\"w_grid\" style=\"display:grid;grid-template-columns:repeat(5,28px);gap:4px;margin-bottom:8px\"></div><div style=\"display:flex;gap:6px;flex-wrap:wrap\"><button type=\"button\" class=\"secondary\" data-m=\"U\">Up</button><button type=\"button\" class=\"secondary\" data-m=\"L\">Left</button><button type=\"button\" class=\"secondary\" data-m=\"D\">Down</button><button type=\"button\" class=\"secondary\" data-m=\"R\">Right</button></div>';const g=document.getElementById('w_grid');const paint=()=>{if(!g)return;g.innerHTML='';for(let iy=0;iy<5;iy++){for(let ix=0;ix<5;ix++){const c=document.createElement('div');c.style.width='28px';c.style.height='28px';c.style.border='1px solid rgba(139,92,246,.32)';c.style.display='flex';c.style.alignItems='center';c.style.justifyContent='center';if(isBlock(ix,iy)){c.style.background='#5a1f29';c.textContent='■';}if(ix===gx&&iy===gy)c.style.outline='2px solid #35b56a';if(ix===x&&iy===y){c.style.background='#6d28d9';c.textContent='●';}g.appendChild(c);}}};const mv=(m)=>{let nx=x,ny=y;if(m==='U'&&y>0)ny--;if(m==='D'&&y<4)ny++;if(m==='L'&&x>0)nx--;if(m==='R'&&x<4)nx++;if(isBlock(nx,ny))return;x=nx;y=ny;steps++;paint();if(x===gx&&y===gy&&steps>=4)done();};paint();elW.querySelectorAll('[data-m]').forEach(b=>b.addEventListener('click',()=>mv(String(b.getAttribute('data-m')||''))));return;}const set=v%2===0?['A','B','C','D']:['K','L','M','N'];const pair=3+(v%2);let vals=[];for(let i=0;i<pair;i++){vals.push(set[i]);vals.push(set[i]);}for(let i=vals.length-1;i>0;i--){const j=(phase1ConceptType+i*7)%vals.length;const t=vals[i];vals[i]=vals[j];vals[j]=t;}let open=[],locked=0;elW.innerHTML='<div style=\"margin-bottom:6px\">Memory pair: buka '+String(pair)+' pasang.</div><div id=\"w_mem\" style=\"display:grid;grid-template-columns:repeat(4,52px);gap:6px\">'+vals.map((_,i)=>'<button type=\"button\" class=\"secondary\" data-i=\"'+i+'\" style=\"height:42px\">?</button>').join('')+'</div>';const btns=Array.from(elW.querySelectorAll('[data-i]'));const show=(idx,on)=>{const b=btns[idx];if(!b)return;b.textContent=on?vals[idx]:'?';};btns.forEach(b=>b.addEventListener('click',()=>{const i=Number(b.getAttribute('data-i'));if(open.includes(i)||b.disabled)return;show(i,true);open.push(i);if(open.length<2)return;const a=open[0],c=open[1];if(vals[a]===vals[c]){btns[a].disabled=true;btns[c].disabled=true;locked++;open=[];if(locked>=pair)done();}else{setTimeout(()=>{show(a,false);show(c,false);open=[];},420);}}));}"
            "setupPhase1Concept=(()=>{const h32=s=>{let h=2166136261>>>0;for(let i=0;i<s.length;i++){h^=s.charCodeAt(i);h=Math.imul(h,16777619);}return h>>>0;};const rng=s=>{let a=(s>>>0)||1;return()=>{a=(a+0x6D2B79F5)>>>0;let t=a;t=Math.imul(t^(t>>>15),t|1);t^=t+Math.imul(t^(t>>>7),t|61);return((t^(t>>>14))>>>0)/4294967296;};};const ri=(r,min,max)=>min+Math.floor(r()*(max-min+1));const sh=(arr,r)=>{for(let i=arr.length-1;i>0;i--){const j=Math.floor(r()*(i+1));const t=arr[i];arr[i]=arr[j];arr[j]=t;}return arr;};return function(){if(!elW){phase1ConceptPassed=true;return;}if(CHALLENGE_TYPE===1||phase1VoiceEnabled){elW.innerHTML='';elW.style.display='none';phase1ConceptPassed=true;return;}elW.style.display='block';if(elW)elW.removeAttribute('data-locked');phase1ConceptPassed=false;phase1ConceptType=((CHALLENGE_TYPE-2)%64+64)%64;const fam=phase1ConceptType%8;const lvl=Math.floor(phase1ConceptType/8);const seed=h32(String(nonce||'')+'|'+String(CHALLENGE_TYPE)+'|'+String((Date.now()/977)|0));const r=rng(seed^Math.imul(lvl+1,2654435761));const done=()=>{if(phase1ConceptPassed)return;phase1ConceptPassed=true;if(elS)elS.textContent='Concept check passed.';elW.setAttribute('data-locked','1');elW.querySelectorAll('button,input,select,textarea').forEach(n=>{try{n.disabled=true;}catch(_e){}});elW.querySelectorAll('[data-lock]').forEach(n=>{n.style.pointerEvents='none';});};if(fam===0){const target=ri(r,14,96),tol=ri(r,1,3);elW.innerHTML='<div style=\"margin-bottom:7px\">Set slider ke <b>'+String(target)+'</b> (toleransi ±'+String(tol)+').</div><input id=\"w_slider\" type=\"range\" min=\"0\" max=\"100\" value=\"0\" style=\"width:100%\"><div id=\"w_sv\" style=\"margin-top:6px\">0</div>';const sl=document.getElementById('w_slider'),sv=document.getElementById('w_sv');if(sl&&sv){sl.oninput=()=>{const x=Number(sl.value||0);sv.textContent=String(x);if(Math.abs(x-target)<=tol)done();};}return;}if(fam===1){const count=lvl>=4?5:4;const seq=Array.from({length:count},(_,i)=>i+1);const btns=sh(seq.slice(),r);let pos=0;elW.innerHTML='<div style=\"margin-bottom:7px\">Klik angka berurutan 1 sampai '+String(count)+'.</div><div id=\"w_tap\" style=\"display:flex;gap:8px;flex-wrap:wrap\">'+btns.map(n=>'<button type=\"button\" class=\"secondary\" data-lock=\"1\" data-n=\"'+String(n)+'\" style=\"min-width:56px\">'+String(n)+'</button>').join('')+'</div><div id=\"w_prog\" style=\"margin-top:6px\">Progress: 0/'+String(count)+'</div>';const pg=document.getElementById('w_prog');elW.querySelectorAll('[data-n]').forEach(b=>b.addEventListener('click',()=>{const n=Number(b.getAttribute('data-n')||0);if(n===seq[pos]){pos++;b.disabled=true;if(pg)pg.textContent='Progress: '+String(pos)+'/'+String(count);if(pos>=count)done();}else{pos=0;elW.querySelectorAll('[data-n]').forEach(x=>{x.disabled=false;});if(pg)pg.textContent='Salah urutan, ulang dari 1.';}}));return;}if(fam===2){const pal=['Merah','Biru','Hijau','Kuning','Ungu','Oranye'];const len=ri(r,3,5);const seq=[];for(let i=0;i<len;i++)seq.push(pal[ri(r,0,pal.length-1)]);let i=0;elW.innerHTML='<div style=\"margin-bottom:7px\">Ulangi sequence warna ini:</div><div style=\"display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px\">'+seq.map(c=>'<span style=\"padding:4px 8px;border:1px solid rgba(139,92,246,.32);border-radius:8px\">'+c+'</span>').join('')+'</div><div style=\"display:flex;gap:8px;flex-wrap:wrap\">'+pal.map(c=>'<button type=\"button\" class=\"secondary\" data-lock=\"1\" data-c=\"'+c+'\">'+c+'</button>').join('')+'</div><div id=\"w_seq_state\" style=\"margin-top:6px\">Step 1/'+String(len)+'</div>';const st=document.getElementById('w_seq_state');elW.querySelectorAll('[data-c]').forEach(b=>b.addEventListener('click',()=>{const c=String(b.getAttribute('data-c')||'');if(c===seq[i]){i++;if(st)st.textContent='Step '+String(Math.min(i+1,len))+'/'+String(len);if(i>=len)done();}else{i=0;if(st)st.textContent='Salah, ulang dari awal.';}}));return;}if(fam===3){const packs=[['🍎','🍋','🍇','🍉'],['⚽','🏀','🎾','🏐'],['🚗','🚕','🚌','🚓'],['⭐','🌙','☀️','☁️']];const bag=packs[ri(r,0,packs.length-1)],target=bag[ri(r,0,bag.length-1)],need=ri(r,3,5);let hit=0;const cells=[];for(let k=0;k<12;k++)cells.push((r()<0.34)?target:bag[ri(r,0,bag.length-1)]);elW.innerHTML='<div style=\"margin-bottom:7px\">Klik simbol '+target+' sebanyak '+String(need)+' kali.</div><div style=\"display:grid;grid-template-columns:repeat(6,minmax(38px,1fr));gap:6px\">'+cells.map(e=>'<button type=\"button\" class=\"secondary\" data-lock=\"1\" data-e=\"'+e+'\" style=\"font-size:20px;padding:6px\">'+e+'</button>').join('')+'</div><div id=\"w_hit\" style=\"margin-top:6px\">0/'+String(need)+'</div>';const hs=document.getElementById('w_hit');elW.querySelectorAll('[data-e]').forEach(x=>x.addEventListener('click',()=>{if(x.disabled)return;if(String(x.getAttribute('data-e'))===target){hit++;x.disabled=true;x.style.opacity='0.55';if(hs)hs.textContent=String(hit)+'/'+String(need);if(hit>=need)done();}else{x.style.opacity='0.35';setTimeout(()=>{x.style.opacity='1';},120);}}));return;}if(fam===4){const holdMs=ri(r,900,2200);let t0=0,raf=0;elW.innerHTML='<div style=\"margin-bottom:7px\">Tahan tombol selama '+String((holdMs/1000).toFixed(1))+' detik lalu lepas.</div><button id=\"w_hold\" type=\"button\" class=\"secondary\" data-lock=\"1\" style=\"min-width:180px\">Hold</button><div id=\"w_hold_state\" style=\"margin-top:6px\">0%</div>';const hb=document.getElementById('w_hold'),hs=document.getElementById('w_hold_state');const tick=()=>{if(!t0||!hs)return;const p=Math.max(0,Math.min(100,Math.floor(((Date.now()-t0)/holdMs)*100)));hs.textContent=String(p)+'%';raf=requestAnimationFrame(tick);};const begin=()=>{if(t0)return;t0=Date.now();tick();};const end=()=>{if(!t0)return;const dt=Date.now()-t0;t0=0;if(raf){cancelAnimationFrame(raf);raf=0;}if(dt>=holdMs&&dt<holdMs+850){if(hs)hs.textContent='OK';done();}else if(hs){hs.textContent='Belum pas, ulangi.';}};if(hb){hb.addEventListener('mousedown',begin);hb.addEventListener('touchstart',begin,{passive:true});hb.addEventListener('mouseup',end);hb.addEventListener('mouseleave',end);hb.addEventListener('touchend',end);}return;}if(fam===5){const bits=lvl>=4?5:4;const max=(1<<bits)-1;const target=ri(r,1,max);let val=0;const bstr=n=>n.toString(2).padStart(bits,'0');const bvals=Array.from({length:bits},(_,i)=>(1<<(bits-1-i)));elW.innerHTML='<div style=\"margin-bottom:7px\">Atur bit menjadi '+bstr(target)+'.</div><div style=\"display:flex;gap:7px;flex-wrap:wrap\">'+bvals.map(b=>'<button type=\"button\" class=\"secondary\" data-lock=\"1\" data-b=\"'+String(b)+'\" style=\"min-width:46px\">0</button>').join('')+'</div><div id=\"w_bv\" style=\"margin-top:6px\">'+bstr(0)+'</div>';const bv=document.getElementById('w_bv');elW.querySelectorAll('[data-b]').forEach(b=>b.addEventListener('click',()=>{const bit=Number(b.getAttribute('data-b')||0);val=(val^bit);b.textContent=((val&bit)!==0)?'1':'0';if(bv)bv.textContent=bstr(val);if(val===target)done();}));return;}if(fam===6){const size=5;const gx=ri(r,2,4),gy=ri(r,2,4);let x=0,y=0,steps=0;const blocks=[];for(let iy=0;iy<size;iy++){for(let ix=0;ix<size;ix++){if((ix===0&&iy===0)||(ix===gx&&iy===gy))continue;if(r()<0.2)blocks.push([ix,iy]);}}const blockSet=new Set(blocks.map(b=>String(b[0])+','+String(b[1])));const isBlock=(ix,iy)=>blockSet.has(String(ix)+','+String(iy));const canReach=()=>{const q=[[0,0]],seen=new Set(['0,0']);while(q.length){const p=q.shift();if(!p)break;const cx=p[0],cy=p[1];if(cx===gx&&cy===gy)return true;[[1,0],[-1,0],[0,1],[0,-1]].forEach(d=>{const nx=cx+d[0],ny=cy+d[1],k=String(nx)+','+String(ny);if(nx<0||ny<0||nx>=size||ny>=size||isBlock(nx,ny)||seen.has(k))return;seen.add(k);q.push([nx,ny]);});}return false;};if(!canReach()){blockSet.clear();}elW.innerHTML='<div style=\"margin-bottom:7px\">Arahkan titik ungu ke G('+String(gx)+','+String(gy)+').</div><div id=\"w_grid\" style=\"display:grid;grid-template-columns:repeat(5,30px);gap:4px;margin-bottom:8px\"></div><div style=\"display:flex;gap:6px;flex-wrap:wrap\"><button type=\"button\" class=\"secondary\" data-lock=\"1\" data-m=\"U\">Up</button><button type=\"button\" class=\"secondary\" data-lock=\"1\" data-m=\"L\">Left</button><button type=\"button\" class=\"secondary\" data-lock=\"1\" data-m=\"D\">Down</button><button type=\"button\" class=\"secondary\" data-lock=\"1\" data-m=\"R\">Right</button></div>';const g=document.getElementById('w_grid');const paint=()=>{if(!g)return;g.innerHTML='';for(let iy=0;iy<size;iy++){for(let ix=0;ix<size;ix++){const c=document.createElement('div');c.style.width='30px';c.style.height='30px';c.style.border='1px solid rgba(139,92,246,.32)';c.style.display='flex';c.style.alignItems='center';c.style.justifyContent='center';if(isBlock(ix,iy)){c.style.background='#5a1f29';c.textContent='■';}if(ix===gx&&iy===gy){c.style.outline='2px solid #35b56a';if(!isBlock(ix,iy))c.textContent='G';}if(ix===x&&iy===y){c.style.background='#6d28d9';c.textContent='●';}g.appendChild(c);}}};const mv=m=>{let nx=x,ny=y;if(m==='U'&&y>0)ny--;if(m==='D'&&y<size-1)ny++;if(m==='L'&&x>0)nx--;if(m==='R'&&x<size-1)nx++;if(isBlock(nx,ny))return;x=nx;y=ny;steps++;paint();if(x===gx&&y===gy&&steps>=3)done();};paint();elW.querySelectorAll('[data-m]').forEach(b=>b.addEventListener('click',()=>mv(String(b.getAttribute('data-m')||''))));return;}const symbols=['A','B','C','D','E','F','G','H'];const pair=lvl>=4?4:3;const chosen=sh(symbols.slice(),r).slice(0,pair);let vals=[];chosen.forEach(s=>{vals.push(s);vals.push(s);});sh(vals,r);let open=[];let lock=0;elW.innerHTML='<div style=\"margin-bottom:7px\">Memory pair: temukan '+String(pair)+' pasang.</div><div id=\"w_mem\" style=\"display:grid;grid-template-columns:repeat(auto-fit,minmax(58px,1fr));gap:7px\">'+vals.map((_,i)=>'<button type=\"button\" class=\"secondary\" data-lock=\"1\" data-i=\"'+String(i)+'\" style=\"height:44px\">?</button>').join('')+'</div>';const btns=Array.from(elW.querySelectorAll('[data-i]'));const show=(idx,on)=>{const b=btns[idx];if(!b)return;b.textContent=on?vals[idx]:'?';};btns.forEach(b=>b.addEventListener('click',()=>{const i=Number(b.getAttribute('data-i')||-1);if(i<0||open.includes(i)||b.disabled)return;show(i,true);open.push(i);if(open.length<2)return;const a=open[0],c=open[1];if(vals[a]===vals[c]){btns[a].disabled=true;btns[c].disabled=true;lock++;open=[];if(lock>=pair)done();}else{setTimeout(()=>{show(a,false);show(c,false);open=[];},420);}}));};})();"
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
            "function drawPattern(){const w=pc.width,h=pc.height;ctx.clearRect(0,0,w,h);ctx.fillStyle='#09090d';ctx.fillRect(0,0,w,h);ctx.strokeStyle='rgba(139,92,246,.08)';ctx.lineWidth=1;const off=(patternPulse%24);for(let gx=-off;gx<w;gx+=24){ctx.beginPath();ctx.moveTo(gx,0);ctx.lineTo(gx,h);ctx.stroke();}for(let gy=-off;gy<h;gy+=24){ctx.beginPath();ctx.moveTo(0,gy);ctx.lineTo(w,gy);ctx.stroke();}if(Array.isArray(clicked)&&clicked.length>1){const segs=[];ctx.lineWidth=3;for(let i=1;i<clicked.length;i++){const a=nodes[clicked[i-1]-1],b=nodes[clicked[i]-1];if(!a||!b)continue;const ax=a[0]/100*w,ay=a[1]/100*h,bx=b[0]/100*w,by=b[1]/100*h;let hit=false;for(let j=0;j<segs.length;j++){const s=segs[j];if(segCross([ax,ay],[bx,by],s[0],s[1])){hit=true;break;}}ctx.strokeStyle=hit?randRGB():'#8b5cf6';ctx.shadowColor=hit?'rgba(239,68,68,.45)':'rgba(139,92,246,.45)';ctx.shadowBlur=12;ctx.beginPath();ctx.moveTo(ax,ay);ctx.lineTo(bx,by);ctx.stroke();ctx.shadowBlur=0;segs.push([[ax,ay],[bx,by]]);}}for(let i=0;i<nodes.length;i++){const n=nodes[i],x=n[0]/100*w,y=n[1]/100*h;const pulse=1.5+Math.sin((patternPulse+i*18)/18)*1.2;ctx.beginPath();ctx.arc(x,y,12+pulse,0,Math.PI*2);ctx.fillStyle='rgba(139,92,246,.08)';ctx.fill();ctx.beginPath();ctx.arc(x,y,11,0,Math.PI*2);ctx.fillStyle='#111117';ctx.fill();ctx.strokeStyle='#8b5cf6';ctx.stroke();ctx.fillStyle='#f7f7fb';ctx.font='12px sans-serif';ctx.fillText(String(i+1),x-4,y+4);}if(elPHint&&/overlap\\s+terdeteksi/i.test(String(elPHint.textContent||''))){elPHint.textContent=sanitizeHint(phase2Hint);}}"
            "function nearestNode(ev){const r=pc.getBoundingClientRect();const x=((ev.clientX-r.left)/r.width)*100;const y=((ev.clientY-r.top)/r.height)*100;let best=-1,bd=1e9;for(let i=0;i<nodes.length;i++){const dx=x-nodes[i][0],dy=y-nodes[i][1],d=dx*dx+dy*dy;if(d<bd){bd=d;best=i;}}if(best<0||bd>18*18)return -1;return best+1;}"
            "function addClickNode(n){if(n<1||n>9)return;if(!pStart)pStart=Date.now();if(clicked.length&&clicked[clicked.length-1]===n)return;clicked.push(n);const pt=nodes[n-1];const t=Date.now()-pStart;if(ppx!==null&&ppy!==null){const dx=pt[0]-ppx,dy=pt[1]-ppy;if((pvdx!==0||pvdy!==0)&&((dx*pvdx+dy*pvdy)<-1))pDir++;pvdx=dx;pvdy=dy;}ppx=pt[0];ppy=pt[1];pTrace.push([pt[0],pt[1],t]);if(pTrace.length>220)pTrace.shift();drawPattern();}"
            "pc.addEventListener('pointerdown',e=>{if(phase!==2)return;const n=nearestNode(e);if(n>0)addClickNode(n);});"
            "function patternPayload(){const dur=pStart?Date.now()-pStart:0;return{duration_ms:dur,dir_changes:pDir,trace:pTrace,clicked_nodes:clicked};}"
            "function connectionInfo(){const n=navigator||{};const s=screen||{};const tz=(Intl&&Intl.DateTimeFormat)?(Intl.DateTimeFormat().resolvedOptions().timeZone||''):'unknown';"
            "return{webdriver:!!n.webdriver,ua_len:String(n.userAgent||'').length,lang_len:String(n.language||'').length,tz_len:String(tz||'').length,max_touch_points:Number(n.maxTouchPoints||0),hardware_concurrency:Number(n.hardwareConcurrency||0),screen_w:Number(s.width||0),screen_h:Number(s.height||0),color_depth:Number(s.colorDepth||0)};}"
            "function clientHintQuery(){const n=navigator||{};const hc=Math.max(0,Math.min(64,Number(n.hardwareConcurrency||0)));const dm=Math.max(0,Math.min(64,Number(n.deviceMemory||0)));const mobile=((Number(n.maxTouchPoints||0)>0)||/android|iphone|ipad|ipod|mobile/i.test(String(n.userAgent||'')))?1:0;return'?hc='+encodeURIComponent(String(hc))+'&dm='+encodeURIComponent(String(dm))+'&m='+String(mobile)+'&ct='+String(CHALLENGE_TYPE);}"
            "function showConn(){if(uiLocked||hardOpened||enteredChallenge){showChal();return;}elPC.classList.add('on');elPH.classList.remove('on');elTC.classList.add('on');elTH.classList.remove('on');}"
            "function showChal(){elPC.classList.remove('on');elPH.classList.add('on');elTC.classList.remove('on');elTH.classList.add('on');}"
            "function lockChallengeUI(){uiLocked=true;hardOpened=true;enteredChallenge=true;humanReady=true;showChal();}"
            "function setPhaseMath(){phase=1;const needText=(CHALLENGE_TYPE===1||phase1VoiceEnabled);elQ.style.display=needText?'':'none';elInputWrap.style.display=needText?'':'none';elPat.style.display='none';if(elA){elA.value=needText?'':'ok';elA.placeholder=String(phase1Placeholder||'Masukkan jawaban');}if(elPQ){elPQ.textContent=String(phase1Label||'Tahap 1: selesaikan challenge.');}if(elPHint){elPHint.textContent=String(phase1Hint||'');}if(elPI){elPI.textContent='PHASE 1';elPI.className='phase-ind p1';}configureVoiceUI();setupPhase1Concept();}"
            "function setPhasePattern(){phase=2;lockChallengeUI();elQ.style.display='';elQ.textContent='Ikuti urutan titik sesuai petunjuk, lalu tekan Continue.';if(elW)elW.style.display='none';elInputWrap.style.display='none';elPat.style.display='block';if(elPI){elPI.textContent='PHASE 2';elPI.className='phase-ind p2';}}"
            "function randBtn(){if(!elHB||!elCW||!elHW)return;if(Date.now()<humanPauseUntil)return;const pad=14;const topMin=(elCW.clientWidth<640?76:90);const maxX=Math.max(pad,elCW.clientWidth-elHB.offsetWidth-pad);const maxY=Math.max(topMin,elCW.clientHeight-elHB.offsetHeight-pad);let x=pad,y=topMin;for(let i=0;i<6;i++){const nx=pad+Math.floor(Math.random()*(Math.max(1,maxX-pad+1)));const ny=topMin+Math.floor(Math.random()*(Math.max(1,maxY-topMin+1)));if(Math.abs(nx-lastBX)+Math.abs(ny-lastBY)>=12){x=nx;y=ny;break;}x=nx;y=ny;}lastBX=x;lastBY=y;elHW.style.left=String(x)+'px';elHW.style.top=String(y)+'px';}"
            "function fmtMs(ms){const t=Math.max(0,Math.ceil(ms/1000));const m=Math.floor(t/60);const s=t%60;return String(m)+'m '+String(s).padStart(2,'0')+'s';}"
            "function updateWait(){const now=Date.now();if(now<hardPenaltyUntil){if(elHW)elHW.style.display='none';}else{if(elHW)elHW.style.display='';}const ms=unlockAt-now;if(ms<=0){elCT.textContent='OK';}else{elCT.textContent=fmtMs(ms);}if(uiLocked||hardOpened||enteredChallenge){showChal();elB.disabled=false;if(powTaskActive&&powReady){elS.textContent='Browser proof ready. Tap Continue.';}else if(powReady){elS.textContent='Challenge ready. Tap Continue.';}else if(powTaskActive){elS.textContent='Browser proof masih dihitung. RAM '+String(powMemMb)+'MB, CPU L'+String(powCpuLevel)+'. Tunggu sebentar...';}else{elS.textContent='Preparing browser proof...';}if(ms<=0&&waitTimer&&powReady){clearInterval(waitTimer);waitTimer=null;}return;}if(ms<=0){if(waitTimer){clearInterval(waitTimer);waitTimer=null;}elS.textContent='Connection check passed.';elB.disabled=false;if(elHW){elHW.style.display='';elHB.disabled=false;elHB.textContent='I am human, pass me';}return;}const label=fmtMs(ms);showConn();elB.disabled=true;elS.textContent='Checking connection... '+label+' | tap button to open challenge';}"
            "const showErr=(m)=>{elE.textContent=String(m||\"Unknown error\");elE.style.display=\"block\";elS.textContent=\"\";};"
            "const hideErr=()=>{elE.textContent=\"\";elE.style.display=\"none\";};"
            "async function loadC(){hideErr();elS.textContent=\"\";elB.disabled=true;clickSamples=[];if(!hardOpened){uiLocked=false;showConn();humanReady=false;enteredChallenge=false;elHB.disabled=true;elHB.textContent='Preparing challenge...';requestAnimationFrame(randBtn);if(humanMoveTimer)clearInterval(humanMoveTimer);humanMoveTimer=setInterval(()=>{if(!humanReady)randBtn();},900);}else{uiLocked=true;humanReady=true;enteredChallenge=true;elHB.disabled=true;elHB.textContent='Challenge opened';if(humanMoveTimer){clearInterval(humanMoveTimer);humanMoveTimer=null;}showChal();}const r=await fetch('/__pteroprotect/challenge/new'+clientHintQuery(),{cache:'no-store'});const j=await r.json();if(!j.ok)throw new Error(String(j.error||'challenge unavailable'));"
            "nonce=j.nonce;ak=j.answer_key||'answer';hk=j.click_key||'click';bk=j.behavior_key||'behavior';ck=j.connection_key||'connection';pk=j.pattern_key||'pattern';powSalt=String(j.pow_salt||'');powBits=Math.max(8,Math.min(24,Number(j.pow_bits||14)));powMemMb=Math.max(8,Math.min(192,Number(j.pow_mem_mb||48)));powCpuLevel=Math.max(1,Math.min(8,Number(j.pow_cpu_level||4)));powCounter=-1;powHash='';powReady=false;powTaskActive=false;pendingFinalSubmit=false;powLoopStop=false;challengeSolved=false;phase1Mode=String(j.challenge_mode||'variant_1');phase1Label=String(j.phase1_label||'Tahap 1: selesaikan challenge.');phase1Hint=String(j.phase1_hint||'');phase1Placeholder=String(j.phase1_input_placeholder||'Masukkan jawaban');phase1Numeric=!!j.phase1_numeric;phase1VoiceEnabled=!!j.phase1_voice_enabled;clickVerified=!!(clickVerified||hardOpened||enteredChallenge||humanReady||uiLocked);setPhaseMath();pseq=[];clicked=[];pTrace=[];pStart=0;pDir=0;pActive=false;ppx=null;ppy=null;pvdx=0;pvdy=0;phase2Hint='';drawPattern();elQ.textContent=j.question;if(elA){elA.placeholder=phase1Placeholder;}elHB.disabled=false;elHB.textContent='I am human, pass me';if(pendingHumanClick){pendingHumanClick=false;setTimeout(()=>{elHB.click();},0);}restartsLeft=3;elRB.disabled=true;elRB.textContent='Restart ('+String(restartsLeft)+')';"
            "elS.textContent='Browser proof mulai. RAM '+String(powMemMb)+'MB, CPU L'+String(powCpuLevel)+'.';powTaskActive=true;(async()=>{try{let firstPass=true;while(!powLoopStop){const pow=await solvePow(nonce,powSalt,powBits,powMemMb,powCpuLevel);if(firstPass){powCounter=pow.counter;powHash=pow.hash;powReady=true;elRB.disabled=false;const usedMb=Number(pow.mem_mb||powMemMb);const usedCpu=Number(pow.cpu_level||powCpuLevel);elS.textContent='Browser proof ready in '+String(pow.ms)+'ms. Tap Continue.';elB.disabled=false;if(pendingFinalSubmit&&phase===2&&clickVerified){pendingFinalSubmit=false;setTimeout(()=>{elB.click();},0);}}firstPass=false;powTaskActive=false;break;}powTaskActive=false;}catch(err){powReady=false;powTaskActive=false;const msg=String(err.message||err);elE.textContent=msg;elS.textContent='PoW failed. Restart challenge.';elB.disabled=false;}})();"
            "const raw=Number(j.connection_delay_ms||0);const baseDelay=Math.min(21600000,Math.max(0,raw));const penaltyLeft=Math.max(0,hardPenaltyUntil-Date.now());const d=Math.max(baseDelay,penaltyLeft);const keepOpened=(uiLocked||hardOpened||clickVerified||enteredChallenge||humanReady||phase===2)&&penaltyLeft<=0;started=Date.now();unlockAt=Date.now()+d;if(keepOpened){lockChallengeUI();elHB.disabled=true;elHB.textContent='Challenge opened';if(humanMoveTimer){clearInterval(humanMoveTimer);humanMoveTimer=null;}showChal();elA.focus();}else{humanReady=false;enteredChallenge=false;elHB.disabled=false;elHB.textContent='I am human, pass me';if(penaltyLeft>0&&elHW)elHW.style.display='none';}updateWait();if(waitTimer)clearInterval(waitTimer);waitTimer=setInterval(updateWait,1000);}"
            "elHB.onclick=async()=>{try{if(!nonce||!hk){pendingHumanClick=true;elHB.disabled=true;elHB.textContent='Preparing challenge...';elS.textContent='Preparing challenge...';return;}const c={nonce:nonce,click:hk};const cr=await fetch('/__pteroprotect/challenge/click',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(c)});const cj=await cr.json();if(!cj.ok){throw new Error(cj.error||'click_invalid');}lockChallengeUI();clickVerified=true;elHB.disabled=true;elHB.textContent='Challenge opened';if(humanMoveTimer){clearInterval(humanMoveTimer);humanMoveTimer=null;}showChal();if(powReady){elA.focus();}updateWait();}catch(err){const msg=String(err.message||err);if(msg==='click_rate_limited'){resetToConnectionRateLimited();return;}elE.textContent=msg;}};"
            "const pauseHumanBtn=(ms)=>{humanPauseUntil=Math.max(humanPauseUntil,Date.now()+ms);};elHB.addEventListener('pointerenter',()=>pauseHumanBtn(120));elHB.addEventListener('pointerdown',()=>pauseHumanBtn(120));elHB.addEventListener('touchstart',()=>pauseHumanBtn(120),{passive:true});elHB.addEventListener('focus',()=>pauseHumanBtn(120));window.addEventListener('resize',()=>{if(!humanReady)randBtn();});if(elMic){elMic.addEventListener('click',()=>{captureVoiceInput();});}"
            "elA.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();elB.click();}});"
            "elRB.onclick=async()=>{if(restartsLeft<=0){elRB.disabled=true;return;}restartsLeft-=1;elRB.textContent='Restart ('+String(restartsLeft)+')';if(restartsLeft<=0)elRB.disabled=true;elE.textContent='';if(phase===1){phase1ConceptPassed=false;elS.textContent='Step-1 di-reset.';setPhaseMath();if(elA&&elInputWrap&&elInputWrap.style.display!=='none'){elA.value='';elA.focus();}elB.disabled=false;return;}if(phase===2){elS.textContent='Pattern di-reset.';clicked=[];pTrace=[];pStart=0;pDir=0;pActive=false;ppx=null;ppy=null;pvdx=0;pvdy=0;drawPattern();elB.disabled=false;return;}elS.textContent='Challenge di-reset.';elB.disabled=false;};"
            "elB.onclick=async()=>{try{if(Date.now()<unlockAt&&!enteredChallenge){updateWait();return;}if(!clickVerified){throw new Error('click_required');}elE.textContent='';elB.disabled=true;"
            "if(phase===1){if(!phase1ConceptPassed){throw new Error('phase1_widget_required');}const m={nonce:nonce};m[ak]=phase1Value(elA.value||'');const mr=await fetch('/__pteroprotect/challenge/verify-math',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(m)});const mj=await mr.json();if(!mj.ok){if(mj.error==='answer_wrong'&&Number.isFinite(Number(mj.attempts_left))){elS.textContent='Salah. Sisa percobaan step-1: '+String(Math.max(0,Number(mj.attempts_left)));}throw new Error(mj.error||'phase1_failed');}setPhasePattern();pseq=Array.isArray(mj.pattern_points)?mj.pattern_points:[];clicked=[];pTrace=[];pStart=0;pDir=0;pActive=false;ppx=null;ppy=null;pvdx=0;pvdy=0;elPQ.textContent='Tahap 2: klik angka sesuai urutan.';phase2Hint=sanitizeHint(String(mj.pattern_hint||''));elPHint.textContent=phase2Hint;drawPattern();elB.disabled=false;return;}"
            "if(!powReady){pendingFinalSubmit=true;elS.textContent='Browser proof belum selesai. Auto lanjut saat proof ready.';elB.disabled=true;return;}const p={nonce:nonce,rd:'" + rd + "',pow_counter:powCounter,pow_hash:powHash};p[ak]=phase1Value(elA.value||'');p[bk]=behavior();p[ck]=connectionInfo();p[pk]=patternPayload();"
            "const r=await fetch('/__pteroprotect/challenge/solve',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(p)});const j=await r.json();if(!j.ok)throw new Error(j.error||'failed');challengeSolved=true;powLoopStop=true;location.href=j.redirect||'" + rd + "';}"
            "catch(err){const msg=String(err.message||err);showErr(msg);if(msg==='phase1_widget_required'){elS.textContent='Selesaikan captcha interaktif phase-1 dulu.';}else if((msg==='answer_wrong'||msg==='pattern_invalid'||msg==='math_not_verified'||msg==='nonce_invalid'||msg==='nonce_expired'||msg==='pow_invalid')&&restartsLeft>0){elS.textContent='Salah. Kamu bisa tekan Restart ('+String(restartsLeft)+')';}elB.disabled=false;}};"
            "async function cleanupOldChallengeCaches(){try{if(window.caches&&caches.keys){const keys=await caches.keys();await Promise.all(keys.filter(k=>k.startsWith('pp-challenge-')).map(k=>caches.delete(k)));}if('serviceWorker' in navigator&&navigator.serviceWorker.getRegistrations){const regs=await navigator.serviceWorker.getRegistrations();await Promise.all(regs.filter(r=>{const s=String(r.scope||'');return s===location.origin+'/'||s.includes('/__pteroprotect/challenge/');}).map(r=>r.unregister()));}}catch(_e){}}"
            "async function registerChallengeSW(){if(!('serviceWorker' in navigator))return;try{await navigator.serviceWorker.register('/__pteroprotect/challenge/sw.js',{scope:'/__pteroprotect/challenge/'});}catch(_e){}}"
            "function animatePattern(){patternPulse=(patternPulse+1)%240;if(phase===2)drawPattern();requestAnimationFrame(animatePattern);}requestAnimationFrame(animatePattern);"
            "document.addEventListener('visibilitychange',()=>{if(document.hidden&&powTaskActive){elS.textContent='Browser proof tetap dihitung saat tab tersembunyi...';}});window.addEventListener('beforeunload',()=>{powLoopStop=true;});cleanupOldChallengeCaches().finally(()=>registerChallengeSW().finally(()=>{loadC().catch(e=>elE.textContent=String(e.message||e));}));</script></body></html>";
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
        std::string completion_id;
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
            completion_id = trim(in.value("completion_id", std::string()));
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
        answer = normalize_expected_answer(answer, rec.answer_numeric, rec.voice_mode);
        const std::string expected_answer = normalize_expected_answer(rec.ans, rec.answer_numeric, rec.voice_mode);
        if (expected_answer != answer) {
            send_response(fd, 401, "Unauthorized", "{\"ok\":false,\"error\":\"answer_wrong\"}", {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
            close(fd);
            return;
        }
        if (completion_id.empty()) completion_id = "cmp_" + nonce;
        {
            std::lock_guard<std::mutex> lock(g_completed_mu);
            const std::time_t now = std::time(nullptr);
            for (auto it = g_completed_challenges.begin(); it != g_completed_challenges.end();) {
                if (it->second <= now) it = g_completed_challenges.erase(it);
                else ++it;
            }
            auto it = g_completed_challenges.find(completion_id);
            if (it != g_completed_challenges.end()) {
                send_response(fd, 200, "OK", "{\"ok\":true,\"duplicate\":true}", {{"Content-Type", "application/json; charset=utf-8"}}, head_only);
                close(fd);
                return;
            }
            g_completed_challenges[completion_id] = now + std::max(5, s.session_grace_sec);
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
            sr.ips.push_back(ip);
            sr.exp = std::time(nullptr) + s.ttl;
            g_ip_session_map[session_scope_key(ip, ua_fp)] = sr;
        }
        std::string cookie = req.headers.count("cookie") ? req.headers["cookie"] : "";
        std::string sid_fp = session_cookie_fingerprint(s, read_cookie(cookie, "pterodactyl_session"));
        std::string tok = issue_token(s, ip, ua_fp, sid, sid_fp);
        std::string secure_attr = "Secure";
        if (s.cookie_secure_mode == "never") secure_attr.clear();
        if (s.cookie_secure_mode == "auto") {
            secure_attr = (req.headers.count("x-forwarded-proto") && to_lower(trim(req.headers.at("x-forwarded-proto"))) == "http") ? "" : "Secure";
        }
        std::string cookie_line = s.cookie_name + "=" + tok + "; Path=/; Max-Age=" + std::to_string(s.ttl) + "; HttpOnly; SameSite=Lax";
        if (!secure_attr.empty()) cookie_line += "; " + secure_attr;
        std::vector<std::pair<std::string, std::string>> headers = {
            {"Content-Type", "application/json; charset=utf-8"},
            {"Set-Cookie", cookie_line},
        };
        json out;
        out["ok"] = true;
        out["redirect"] = rd;
        log_event_json("challenge_passed", {{"ip", ip}, {"node_id", s.node_id}, {"sid", sid}});
        log_event_json("token_issued", {{"ip", ip}, {"node_id", s.node_id}, {"sid", sid}});
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
        constexpr int kMaxWorkers = 512;
        int cur = g_active_workers.load();
        while (cur < kMaxWorkers && !g_active_workers.compare_exchange_weak(cur, cur + 1)) {
        }
        if (cur >= kMaxWorkers) {
            close(fd);
            continue;
        }
        std::thread([fd, rip]() {
            handle_client(fd, rip);
            g_active_workers.fetch_sub(1);
        }).detach();
    }

    close(server_fd);
    return 0;
}
