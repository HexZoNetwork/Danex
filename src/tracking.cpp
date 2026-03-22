#include "tracking.h"
#include "logger.h"
#include "telegram.h"
#include <algorithm>
#include <ctime>

Tracker::Tracker() : last_report(0) {}

void Tracker::add_violation(const std::string& uuid, const std::string& name,
                             const std::string& owner, double usage,
                             int files, const std::string& reason) {
    ViolationRecord vr;
    vr.uuid = uuid;
    vr.server_name = name;
    vr.owner = owner;
    vr.disk_usage = usage;
    vr.file_count = files;
    vr.reason = reason;
    vr.timestamp = time(nullptr);
    
    violations.push_back(vr);
}

void Tracker::generate_report() {
    if (violations.empty()) return;
    
    time_t now = time(nullptr);
    if (now - last_report < 3600) return; // Once per hour
    
    std::string report = "📋 VIOLATION REPORT\n";
    report += "━━━━━━━━━━━━━━\n\n";
    
    int count = 0;
    for (const auto& v : violations) {
        if (count++ >= 10) break;
        
        report += "• " + v.server_name + "\n";
        report += "  Owner: " + v.owner + "\n";
        report += "  UUID: " + v.uuid.substr(0,8) + "\n";
        report += "  Reason: " + v.reason + "\n\n";
    }
    
    // Send via telegram (simplified)
    logger.info("Report generated: " + std::to_string(violations.size()) + " violations");
    
    last_report = now;
}

void Tracker::clear_old(int hours) {
    time_t cutoff = time(nullptr) - (hours * 3600);
    
    violations.erase(std::remove_if(violations.begin(), violations.end(),
        [cutoff](const ViolationRecord& v) {
            return v.timestamp < cutoff;
        }), violations.end());
}

Tracker tracker;