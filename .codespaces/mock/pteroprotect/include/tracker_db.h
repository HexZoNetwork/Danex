#ifndef TRACKER_DB_H
#define TRACKER_DB_H

#include <string>
#include <vector>
#include <ctime>
#include <mysql/mysql.h>

enum ViolationType {
    VIOLATION_DISK_OVER,
    VIOLATION_FILE_FLOOD,
    VIOLATION_ILLEGAL_FILE,
    VIOLATION_ILLEGAL_PROCESS,
    VIOLATION_CPU_ABUSE,
    VIOLATION_RAM_ABUSE
};

struct UserStats {
    int user_id;
    std::string username;
    std::string email;
    int total_violations;
    int disk_violations;
    int flood_violations;
    int illegal_files;
    int illegal_processes;
    int total_severity;
    time_t last_violation;
    bool is_blacklisted;
};

class DatabaseTracker {
private:
    MYSQL* conn;
    std::string host;
    std::string user;
    std::string password;
    std::string dbname;
    
    bool connect();
    std::string type_to_string(ViolationType type);
    
public:
    DatabaseTracker();
    ~DatabaseTracker();
    
    bool init(const std::string& h, const std::string& u, 
              const std::string& p, const std::string& db);
    
    // Record violations
    bool record_violation(int user_id, const std::string& username,
                          int server_id, const std::string& server_uuid,
                          const std::string& server_name,
                          ViolationType type, const std::string& details,
                          const std::string& file_name, long long file_size,
                          double disk_usage, int file_count,
                          const std::string& action, int severity);
    
    bool record_simple_violation(int user_id, ViolationType type, 
                                  const std::string& details,
                                  const std::string& action);
    
    // Get user stats
    UserStats get_user_stats(int user_id);
    std::vector<UserStats> get_top_offenders(int limit = 10);
    
    // Blacklist management
    bool blacklist_user(int user_id, const std::string& reason, 
                        const std::string& blacklisted_by);
    bool unblacklist_user(int user_id);
    bool is_blacklisted(int user_id);
    std::vector<int> get_blacklisted_users();
    
    // Daily stats
    void update_daily_stats(int suspend = 0, int files = 0, int processes = 0);
    std::string get_daily_report();
    
    // File tracking
    bool track_illegal_file(const std::string& file_hash, 
                            const std::string& file_name,
                            const std::string& file_path,
                            const std::string& server_uuid,
                            int user_id,
                            const std::string& reason,
                            long long file_size);
    
    // Cleanup old records
    void cleanup_old_records(int days = 30);
    
    // Generate report
    std::string generate_offender_report();
};

extern DatabaseTracker tracker_db;

#endif
