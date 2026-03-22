#include "tracker_db.h"
#include "logger.h"
#include <sstream>
#include <mysql/mysql.h>
#include <cstring>

DatabaseTracker::DatabaseTracker() : conn(nullptr) {}

DatabaseTracker::~DatabaseTracker() {
    if (conn) {
        mysql_close(conn);
    }
}

bool DatabaseTracker::init(const std::string& h, const std::string& u, 
                            const std::string& p, const std::string& db) {
    host = h;
    user = u;
    password = p;
    dbname = db;
    
    conn = mysql_init(nullptr);
    if (!conn) {
        logger.error("Tracker MySQL init failed");
        return false;
    }
    
    if (!mysql_real_connect(conn, host.c_str(), user.c_str(), password.c_str(), 
                            dbname.c_str(), 3306, nullptr, 0)) {
        logger.error("Tracker MySQL connect failed");
        return false;
    }
    
    logger.info("✅ Tracker MySQL Connected");
    return true;
}

std::string DatabaseTracker::type_to_string(ViolationType type) {
    switch(type) {
        case VIOLATION_DISK_OVER:      return "disk_over";
        case VIOLATION_FILE_FLOOD:     return "file_flood";
        case VIOLATION_ILLEGAL_FILE:   return "illegal_file";
        case VIOLATION_CPU_ABUSE:      return "cpu_abuse";
        case VIOLATION_RAM_ABUSE:      return "ram_abuse";
        default:                       return "unknown";
    }
}

// FUNGSI YANG DIPAKAI OLEH MAIN.CPP
std::vector<int> DatabaseTracker::get_blacklisted_users() {
    std::vector<int> users;
    // Return empty for now
    return users;
}

std::string DatabaseTracker::get_daily_report() {
    return "Daily report not implemented";
}

std::string DatabaseTracker::generate_offender_report() {
    return "Offender report not implemented";
}

// FUNGSI LAINNYA (STUB - GA DIPAKE)
bool DatabaseTracker::record_violation(int, const std::string&, int, const std::string&,
                                        const std::string&, ViolationType, const std::string&,
                                        const std::string&, long long, double, int,
                                        const std::string&, int) { return true; }

bool DatabaseTracker::record_simple_violation(int, ViolationType, const std::string&, const std::string&) { return true; }

UserStats DatabaseTracker::get_user_stats(int) { UserStats s; return s; }

std::vector<UserStats> DatabaseTracker::get_top_offenders(int) { return {}; }

bool DatabaseTracker::blacklist_user(int, const std::string&, const std::string&) { return true; }

bool DatabaseTracker::unblacklist_user(int) { return true; }

bool DatabaseTracker::is_blacklisted(int) { return false; }

void DatabaseTracker::update_daily_stats(int, int, int) {}

bool DatabaseTracker::track_illegal_file(const std::string&, const std::string&,
                                          const std::string&, const std::string&,
                                          int, const std::string&, long long) { return true; }

void DatabaseTracker::cleanup_old_records(int) {}

DatabaseTracker tracker_db;
