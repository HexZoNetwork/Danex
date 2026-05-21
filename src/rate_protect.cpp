#include "rate_protect.h"

RateProtector::RateProtector()
    : max_requests(0), time_window(0), max_keys(100000), last_cleanup(std::chrono::steady_clock::now()) {}

void RateProtector::init(int max_req, int window) {
    init(max_req, window, 100000);
}

void RateProtector::init(int max_req, int window, std::size_t max_tracked_keys) {
    std::lock_guard<std::mutex> lock(stats_mu);
    max_requests = max_req;
    time_window = window;
    max_keys = max_tracked_keys == 0 ? 1 : max_tracked_keys;
    last_cleanup = std::chrono::steady_clock::now();
    stats.clear();
}

void RateProtector::evict_expired_locked(std::chrono::steady_clock::time_point now) {
    const auto ttl = std::chrono::seconds(time_window > 0 ? time_window : 1);
    for (auto it = stats.begin(); it != stats.end();) {
        if (now - it->second.last > ttl) it = stats.erase(it);
        else ++it;
    }

    while (stats.size() > max_keys) {
        auto oldest = stats.begin();
        for (auto it = stats.begin(); it != stats.end(); ++it) {
            if (it->second.last < oldest->second.last) oldest = it;
        }
        stats.erase(oldest);
    }
}

bool RateProtector::check(const std::string& key) {
    if (key.empty() || max_requests <= 0 || time_window <= 0) return false;

    const auto now = std::chrono::steady_clock::now();
    std::lock_guard<std::mutex> lock(stats_mu);
    if (now - last_cleanup >= std::chrono::seconds(1) || stats.size() > max_keys) {
        evict_expired_locked(now);
        last_cleanup = now;
    }

    auto it = stats.find(key);
    
    if (it == stats.end()) {
        if (stats.size() >= max_keys) {
            auto oldest = stats.begin();
            for (auto scan = stats.begin(); scan != stats.end(); ++scan) {
                if (scan->second.last < oldest->second.last) oldest = scan;
            }
            stats.erase(oldest);
        }
        RateStats rs;
        rs.count = 1;
        rs.first = now;
        rs.last = now;
        stats[key] = rs;
        return true;
    }
    
    if (now - it->second.first > std::chrono::seconds(time_window)) {
        it->second.count = 1;
        it->second.first = now;
        it->second.last = now;
        return true;
    }
    
    it->second.count++;
    it->second.last = now;
    
    return it->second.count <= max_requests;
}

void RateProtector::reset(const std::string& key) {
    std::lock_guard<std::mutex> lock(stats_mu);
    stats.erase(key);
}
