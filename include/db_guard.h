#ifndef DB_GUARD_H
#define DB_GUARD_H

#include <string>
#include <vector>
#include <mutex>
#include <mysql/mysql.h>

struct ServerInfo {
    int id = -1;
    int owner_id = -1;
    int egg_id = -1;
    bool suspended = false;
    std::string uuid;
    std::string name;
    std::string status;
    std::string username;
    std::string email;
    std::string first_name;
    std::string last_name;
    std::string egg_name;
    std::string nest_name;
};

struct ServerActivityEntry {
    long long id = 0;
    std::string event;
    std::string ip;
    std::string properties_json;
};

class DatabaseGuard {
private:
    MYSQL* conn;
    std::string host;
    std::string user;
    std::string password;
    std::string dbname;
    bool has_suspended_column;
    mutable std::recursive_mutex mutex_;

    bool connect();
    std::string escape(const std::string& s);
    bool ensure_connection();
    bool column_exists(const std::string& table, const std::string& column);

public:
    DatabaseGuard();
    ~DatabaseGuard();

    bool init(const std::string& h, const std::string& u,
              const std::string& p, const std::string& db_name);

    ServerInfo get_server_info(const std::string& uuid);
    std::vector<std::string> get_all_server_uuids();
    std::vector<ServerActivityEntry> get_recent_server_activity(int server_id, long long after_id = 0, int limit = 25);
    bool suspend_server(int server_id, const std::string& reason = "");
    int count_suspended_servers();

    bool log_user_violation(
        int user_id,
        const std::string& username,
        int server_id,
        const std::string& server_uuid,
        const std::string& server_name,
        const std::string& violation_type,
        const std::string& details,
        const std::string& file_name,
        long long file_size,
        double disk_usage_gb,
        int file_count,
        const std::string& action_taken,
        int severity
    );

    bool log_illegal_file(
        const std::string& file_hash,
        const std::string& file_name,
        const std::string& file_path,
        const std::string& server_uuid,
        int user_id,
        const std::string& detection_reason,
        long long file_size
    );

    bool bump_daily_stats(int suspend_inc, int files_deleted_inc, int process_killed_inc = 0);
};

extern DatabaseGuard db;

#endif
