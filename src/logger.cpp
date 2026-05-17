#include "logger.h"
#include <iostream>
#include <chrono>
#include <iomanip>
#include <sstream>
#include <ctime>

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
    std::lock_guard<std::mutex> lock(mutex);
    std::cout << "[" << timestamp << "] [INFO] " << msg << std::endl;
    
    if (log_file.is_open()) {
        log_file << "[" << timestamp << "] [INFO] " << msg << std::endl;
        log_file.flush();
    }
}

void Logger::warn(const std::string& msg) {
    std::string timestamp = get_timestamp();
    std::lock_guard<std::mutex> lock(mutex);
    std::cout << "[" << timestamp << "] [WARN] " << msg << std::endl;
    
    if (log_file.is_open()) {
        log_file << "[" << timestamp << "] [WARN] " << msg << std::endl;
        log_file.flush();
    }
}

void Logger::error(const std::string& msg) {
    std::string timestamp = get_timestamp();
    std::lock_guard<std::mutex> lock(mutex);
    std::cout << "[" << timestamp << "] [ERROR] " << msg << std::endl;
    
    if (log_file.is_open()) {
        log_file << "[" << timestamp << "] [ERROR] " << msg << std::endl;
        log_file.flush();
    }
}

Logger logger;
