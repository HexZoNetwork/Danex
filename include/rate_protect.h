#ifndef RATE_PROTECT_H
#define RATE_PROTECT_H

#include <string>
#include <unordered_map>
#include <chrono>
#include <mutex>

struct RateStats {
    int count;
    std::chrono::steady_clock::time_point first;
    std::chrono::steady_clock::time_point last;
};

class RateProtector {
private:
    std::unordered_map<std::string, RateStats> stats;
    std::mutex stats_mu;
    int max_requests;
    int time_window;
    std::size_t max_keys;
    std::chrono::steady_clock::time_point last_cleanup;

    void evict_expired_locked(std::chrono::steady_clock::time_point now);
    
public:
    RateProtector();
    
    void init(int max_req, int window);
    void init(int max_req, int window, std::size_t max_tracked_keys);
    bool check(const std::string& key);
    void reset(const std::string& key);
};

#endif
