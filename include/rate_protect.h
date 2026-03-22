#ifndef RATE_PROTECT_H
#define RATE_PROTECT_H

#include <string>
#include <unordered_map>
#include <ctime>

struct RateStats {
    int count;
    time_t first;
    time_t last;
};

class RateProtector {
private:
    std::unordered_map<std::string, RateStats> stats;
    int max_requests;
    int time_window;
    
public:
    RateProtector();
    
    void init(int max_req, int window);
    bool check(const std::string& key);
    void reset(const std::string& key);
};

#endif