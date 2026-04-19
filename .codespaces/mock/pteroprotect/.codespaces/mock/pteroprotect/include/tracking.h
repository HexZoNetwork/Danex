#ifndef TRACKING_H
#define TRACKING_H

#include <string>
#include <vector>
#include <ctime>

struct ViolationRecord {
    std::string uuid;
    std::string server_name;
    std::string owner;
    double disk_usage;
    int file_count;
    std::string reason;
    time_t timestamp;
};

class Tracker {
private:
    std::vector<ViolationRecord> violations;
    time_t last_report;
    
public:
    Tracker();
    
    void add_violation(const std::string& uuid, const std::string& name,
                       const std::string& owner, double usage,
                       int files, const std::string& reason);
    
    void generate_report();
    void clear_old(int hours = 24);
};

extern Tracker tracker;

#endif