#include "telegram.h"
#include "logger.h"
#include "db_guard.h"
#include <curl/curl.h>
#include <sstream>
#include <iomanip>
#include <cstring>
#include <ctime>
#include <algorithm>
#include <cstdlib>

namespace {
bool offline_mode_enabled() {
    const char* v = std::getenv("DANN_GUARD_OFFLINE");
    if (!v) return false;
    std::string s = v;
    std::transform(s.begin(), s.end(), s.begin(), [](unsigned char c) { return (char)std::tolower(c); });
    return s == "1" || s == "true" || s == "yes" || s == "on";
}
}

TelegramBot::TelegramBot() {}

void TelegramBot::init(const std::string& t, const std::string& cid,
                       const std::string& ch, const std::string& rep, 
                       const std::string& cr) {
    token = t;
    chat_id = cid;
    channel = ch;
    report_channel = rep;
    creator = cr;
}

size_t TelegramBot::write_callback(void* contents, size_t size, size_t nmemb, std::string* output) {
    size_t total = size * nmemb;
    output->append((char*)contents, total);
    return total;
}

bool TelegramBot::send_html_message(const std::string& message) {
    if (offline_mode_enabled()) {
        logger.warn("⚠️ Offline mode active, but telegram send still attempted");
    }

    CURL* curl = curl_easy_init();
    if (!curl) {
        logger.error("❌ CURL init failed");
        return false;
    }
    
    std::string url = "https://api.telegram.org/bot" + token + "/sendMessage";
    
    // URL encode the message
    char* encoded_message = curl_easy_escape(curl, message.c_str(), message.length());
    std::string encoded_str(encoded_message);
    curl_free(encoded_message);
    
    std::string post_fields = "chat_id=" + chat_id + "&text=" + encoded_str + "&parse_mode=HTML";
    
    logger.info("📤 Sending to Telegram: " + chat_id);
    
    curl_easy_setopt(curl, CURLOPT_URL, url.c_str());
    curl_easy_setopt(curl, CURLOPT_POSTFIELDS, post_fields.c_str());
    curl_easy_setopt(curl, CURLOPT_TIMEOUT, 10L);
    curl_easy_setopt(curl, CURLOPT_WRITEFUNCTION, write_callback);
    
    std::string response_string;
    curl_easy_setopt(curl, CURLOPT_WRITEDATA, &response_string);
    
    CURLcode res = curl_easy_perform(curl);
    
    if (res != CURLE_OK) {
        logger.error("❌ Telegram CURL error: " + std::string(curl_easy_strerror(res)));
        curl_easy_cleanup(curl);
        return false;
    }
    
    long response_code;
    curl_easy_getinfo(curl, CURLINFO_RESPONSE_CODE, &response_code);
    curl_easy_cleanup(curl);
    
    if (response_code == 200) {
        logger.info("✅ Telegram notif sent (HTTP 200)");
        return true;
    } else {
        logger.error("❌ Telegram HTTP error: " + std::to_string(response_code));
        logger.error("❌ Response: " + response_string);
        return false;
    }
}

void TelegramBot::notify_startup() {
    std::ostringstream msg;
    msg << "🛡️ DANN GUARD\n"
        << "🚨 SYSTEM STARTED\n"
        << "⏱️ " << std::time(nullptr) << "\n"
        << "━━━━━━━━━━━━━━━━━━━\n\n"
        << "👤 Creator: " << creator << "\n"
        << "📢 Channel: " << channel << "\n"
        << "📢 Report: " << report_channel;
    
    send_html_message(msg.str());
}

TelegramBot bot;
