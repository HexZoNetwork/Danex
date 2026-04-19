#include "rate_protect.h"

RateProtector::RateProtector() : max_requests(0), time_window(0) {}

void RateProtector::init(int max_req, int window) {
    max_requests = max_req;
    time_window = window;
}

bool RateProtector::check(const std::string& key) {
    time_t now = time(nullptr);
    auto it = stats.find(key);
    
    if (it == stats.end()) {
        RateStats rs;
        rs.count = 1;
        rs.first = now;
        rs.last = now;
        stats[key] = rs;
        return true;
    }
    
    if (now - it->second.first > time_window) {
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
    stats.erase(key);
}