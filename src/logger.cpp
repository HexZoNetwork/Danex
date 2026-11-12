#include "logger.h"
#include <iostream>
#include <chrono>
#include <iomanip>
#include <sstream>
#include <ctime>
#include <sys/stat.h>

namespace {
std::string sanitize_log_message(const std::string& msg) {
    std::string out;
    out.reserve(msg.size());
    for (unsigned char c : msg) {
        if (c == '\n' || c == '\r' || c == '\t') {
            out += ' ';
        } else if (c < 0x20 || c == 0x7f) {
            out += '?';
        } else {
            out += static_cast<char>(c);
        }
    }
    return out;
}
} // namespace

Logger::Logger() {}

Logger::~Logger() {
    if (log_file.is_open()) {
        log_file.close();
    }
}

void Logger::init(const std::string& path) {
    std::lock_guard<std::mutex> lock(mutex);
    log_file.open(path, std::ios::app);
    if (!log_file.is_open()) {
        std::cerr << "Failed to open log file: " << path << std::endl;
    } else {
        chmod(path.c_str(), 0640);
    }
}

std::string Logger::get_timestamp() {
    auto now = std::chrono::system_clock::now();
    auto in_time_t = std::chrono::system_clock::to_time_t(now);
    
    std::tm bt;
    localtime_r(&in_time_t, &bt);
    
    mktime(&bt);
    
    std::ostringstream oss;
    oss << std::put_time(&bt, "%H:%M:%S %Z");
    return oss.str();
}

void Logger::info(const std::string& msg) {
    std::string timestamp = get_timestamp();
    std::string safe = sanitize_log_message(msg);
    std::lock_guard<std::mutex> lock(mutex);
    std::cout << "[" << timestamp << "] [INFO] " << safe << std::endl;
    
    if (log_file.is_open()) {
        log_file << "[" << timestamp << "] [INFO] " << safe << std::endl;
        log_file.flush();
    }
}

void Logger::warn(const std::string& msg) {
    std::string timestamp = get_timestamp();
    std::string safe = sanitize_log_message(msg);
    std::lock_guard<std::mutex> lock(mutex);
    std::cout << "[" << timestamp << "] [WARN] " << safe << std::endl;
    
    if (log_file.is_open()) {
        log_file << "[" << timestamp << "] [WARN] " << safe << std::endl;
        log_file.flush();
    }
}

void Logger::error(const std::string& msg) {
    std::string timestamp = get_timestamp();
    std::string safe = sanitize_log_message(msg);
    std::lock_guard<std::mutex> lock(mutex);
    std::cout << "[" << timestamp << "] [ERROR] " << safe << std::endl;
    
    if (log_file.is_open()) {
        log_file << "[" << timestamp << "] [ERROR] " << safe << std::endl;
        log_file.flush();
    }
}

Logger logger;
