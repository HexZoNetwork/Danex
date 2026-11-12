#ifndef TRACKING_H
#define TRACKING_H

#include <string>
#include <vector>
#include <mutex>
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
    std::string store_path;
    mutable std::mutex mu;

    void load();
    bool append(const ViolationRecord& record);
    void rewrite();

public:
    Tracker();

    void add_violation(const std::string& uuid, const std::string& name,
                       const std::string& owner, double usage,
                       int files, const std::string& reason);

    void generate_report();
    void clear_old(int hours = 24);
    std::size_t size() const;
    std::string path() const;
};

extern Tracker tracker;

#endif
