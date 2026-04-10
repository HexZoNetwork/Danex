#include "db_guard.h"
#include "logger.h"
#include <mysql/mysql.h>
#include <sstream>
#include <cstring>
#include <iomanip>
#include <cstdlib>
#include <sys/wait.h>

namespace {
std::string get_panel_dir() {
    const char* env_panel_dir = std::getenv("DANN_PANEL_DIR");
    if (env_panel_dir && *env_panel_dir) {
        return env_panel_dir;
    }

    return "/var/www/pterodactyl";
}

std::string shell_quote(const std::string& value) {
    std::string escaped = "'";
    for (char c : value) {
        if (c == '\'') {
            escaped += "'\\''";
        } else {
            escaped += c;
        }
    }
    escaped += "'";
    return escaped;
}

std::string normalize_reason_for_cli(const std::string& reason) {
    std::string out;
    out.reserve(reason.size());
    for (char c : reason) {
        if (c == '\n' || c == '\r' || c == '\t') out.push_back(' ');
        else out.push_back(c);
    }
    const size_t max_len = 512;
    if (out.size() > max_len) out.resize(max_len);
    return out;
}

bool try_panel_suspend(int server_id, const std::string& reason) {
    if (server_id <= 0) return false;

    const std::string reason_arg = normalize_reason_for_cli(reason);
    std::ostringstream cmd;
    cmd << "cd " << shell_quote(get_panel_dir()) << " && php artisan p:server:guard-suspension "
        << server_id
        << " --reason=" << shell_quote(reason_arg)
        << " --action=suspend --no-interaction >/dev/null 2>&1";

    int rc = std::system(cmd.str().c_str());
    if (rc == -1) return false;
    if (WIFEXITED(rc) && WEXITSTATUS(rc) == 0) return true;
    return false;
}
}

DatabaseGuard::DatabaseGuard() : conn(nullptr), has_suspended_column(true) {}

DatabaseGuard::~DatabaseGuard() {
    if (conn) {
        mysql_close(conn);
        conn = nullptr;
    }
}

bool DatabaseGuard::init(const std::string& h, const std::string& u,
                         const std::string& p, const std::string& db_name) {
    std::lock_guard<std::recursive_mutex> lock(mutex_);
    host = h;
    user = u;
    password = p;
    dbname = db_name;
    return connect();
}

bool DatabaseGuard::connect() {
    std::lock_guard<std::recursive_mutex> lock(mutex_);
    if (conn) {
        mysql_close(conn);
        conn = nullptr;
    }

    conn = mysql_init(nullptr);
    if (!conn) {
        logger.error("MySQL init failed");
        return false;
    }

    if (!mysql_real_connect(conn, host.c_str(), user.c_str(), password.c_str(),
                            dbname.c_str(), 3306, nullptr, 0)) {
        logger.error("MySQL connect failed: " + std::string(mysql_error(conn)));
        mysql_close(conn);
        conn = nullptr;
        return false;
    }

    mysql_set_character_set(conn, "utf8mb4");
    logger.info("✅ MySQL Connected");

    has_suspended_column = column_exists("servers", "suspended");
    if (!has_suspended_column)
        logger.warn("servers table missing 'suspended' column; using status-only suspension");

    return true;
}

bool DatabaseGuard::ensure_connection() {
    std::lock_guard<std::recursive_mutex> lock(mutex_);
    if (!conn) return connect();
    if (mysql_ping(conn) != 0) {
        logger.warn("MySQL reconnecting...");
        return connect();
    }
    return true;
}

std::string DatabaseGuard::escape(const std::string& s) {
    std::lock_guard<std::recursive_mutex> lock(mutex_);
    if (!ensure_connection()) return "";
    std::string out;
    out.resize(s.size() * 2 + 1);
    unsigned long len = mysql_real_escape_string(conn, &out[0], s.c_str(), (unsigned long)s.size());
    out.resize(len);
    return out;
}

bool DatabaseGuard::column_exists(const std::string& table, const std::string& column) {
    std::lock_guard<std::recursive_mutex> lock(mutex_);
    if (!conn) return false;

    std::ostringstream q;
    q << "SELECT COUNT(*) FROM information_schema.COLUMNS "
      << "WHERE TABLE_SCHEMA = '" << escape(dbname) << "' "
      << "AND TABLE_NAME = '" << escape(table) << "' "
      << "AND COLUMN_NAME = '" << escape(column) << "' LIMIT 1";

    if (mysql_query(conn, q.str().c_str()) != 0) {
        logger.warn("column_exists query failed: " + std::string(mysql_error(conn)));
        return false;
    }

    MYSQL_RES* result = mysql_store_result(conn);
    if (!result) return false;
    MYSQL_ROW row = mysql_fetch_row(result);
    bool exists = row && row[0] && atoi(row[0]) > 0;
    mysql_free_result(result);
    return exists;
}

ServerInfo DatabaseGuard::get_server_info(const std::string& uuid) {
    std::lock_guard<std::recursive_mutex> lock(mutex_);
    ServerInfo info;
    info.id = -1;
    info.owner_id = -1;
    info.uuid = uuid;

    if (!ensure_connection()) return info;

    std::ostringstream query;
    query << "SELECT id, name, owner_id, egg_id FROM servers WHERE uuid = '" << escape(uuid) << "' LIMIT 1";

    if (mysql_query(conn, query.str().c_str())) {
        logger.error("get_server_info query failed: " + std::string(mysql_error(conn)));
        return info;
    }

    MYSQL_RES* result = mysql_store_result(conn);
    if (!result) return info;

    MYSQL_ROW row = mysql_fetch_row(result);
    int owner_id = -1;
    int egg_id = -1;
    if (row) {
        info.id = row[0] ? atoi(row[0]) : -1;
        info.name = row[1] ? row[1] : "";
        owner_id = row[2] ? atoi(row[2]) : -1;
        info.owner_id = owner_id;
        egg_id = row[3] ? atoi(row[3]) : -1;
        info.egg_id = egg_id;
        mysql_free_result(result);

        if (owner_id > 0) {
            std::ostringstream user_query;
            user_query << "SELECT username, email, name_first, name_last FROM users WHERE id = " << owner_id << " LIMIT 1";

            if (mysql_query(conn, user_query.str().c_str()) == 0) {
                MYSQL_RES* user_result = mysql_store_result(conn);
                if (user_result) {
                    MYSQL_ROW user_row = mysql_fetch_row(user_result);
                    if (user_row) {
                        info.username   = user_row[0] ? user_row[0] : "";
                        info.email      = user_row[1] ? user_row[1] : "";
                        info.first_name = user_row[2] ? user_row[2] : "";
                        info.last_name  = user_row[3] ? user_row[3] : "";
                    }
                    mysql_free_result(user_result);
                }
            } else {
                logger.error("get_server_info user query failed: " + std::string(mysql_error(conn)));
            }
        }

        if (egg_id > 0) {
            std::ostringstream egg_query;
            egg_query << "SELECT e.name, n.name FROM eggs e "
                      << "LEFT JOIN nests n ON e.nest_id = n.id "
                      << "WHERE e.id = " << egg_id << " LIMIT 1";

            if (mysql_query(conn, egg_query.str().c_str()) == 0) {
                MYSQL_RES* egg_result = mysql_store_result(conn);
                if (egg_result) {
                    MYSQL_ROW egg_row = mysql_fetch_row(egg_result);
                    if (egg_row) {
                        info.egg_name  = egg_row[0] ? egg_row[0] : "";
                        info.nest_name = egg_row[1] ? egg_row[1] : "";
                    }
                    mysql_free_result(egg_result);
                }
            } else {
                logger.error("get_server_info egg query failed: " + std::string(mysql_error(conn)));
            }
        }
    } else {
        mysql_free_result(result);
    }

    return info;
}

std::vector<std::string> DatabaseGuard::get_all_server_uuids() {
    std::lock_guard<std::recursive_mutex> lock(mutex_);
    std::vector<std::string> uuids;
    if (!ensure_connection()) return uuids;

    const char* query = "SELECT uuid FROM servers WHERE uuid IS NOT NULL AND uuid != ''";
    if (mysql_query(conn, query) != 0) {
        logger.error("get_all_server_uuids failed: " + std::string(mysql_error(conn)));
        return uuids;
    }

    MYSQL_RES* result = mysql_store_result(conn);
    if (!result) return uuids;

    MYSQL_ROW row;
    while ((row = mysql_fetch_row(result)) != nullptr) {
        if (row[0] && *row[0]) {
            std::string uuid = row[0];
            if (!uuid.empty()) uuids.push_back(uuid);
        }
    }

    mysql_free_result(result);
    return uuids;
}

std::vector<ServerActivityEntry> DatabaseGuard::get_recent_server_activity(int server_id, long long after_id, int limit) {
    std::lock_guard<std::recursive_mutex> lock(mutex_);
    std::vector<ServerActivityEntry> rows;
    if (server_id <= 0 || limit <= 0) return rows;
    if (!ensure_connection()) return rows;

    std::ostringstream query;
    query << "SELECT al.id, al.event, al.ip, al.properties "
          << "FROM activity_logs al "
          << "JOIN activity_log_subjects als ON als.activity_log_id = al.id "
          << "WHERE als.subject_type = 'server' "
          << "AND als.subject_id = " << server_id << " "
          << "AND (al.actor_id IS NULL OR al.actor_id <> 1) "
          << "AND al.id > " << after_id << " "
          << "ORDER BY al.id ASC "
          << "LIMIT " << limit;

    if (mysql_query(conn, query.str().c_str()) != 0) {
        logger.error("get_recent_server_activity failed: " + std::string(mysql_error(conn)));
        return rows;
    }

    MYSQL_RES* result = mysql_store_result(conn);
    if (!result) return rows;

    MYSQL_ROW row;
    while ((row = mysql_fetch_row(result)) != nullptr) {
        ServerActivityEntry entry;
        entry.id = row[0] ? atoll(row[0]) : 0;
        entry.event = row[1] ? row[1] : "";
        entry.ip = row[2] ? row[2] : "";
        entry.properties_json = row[3] ? row[3] : "";
        rows.push_back(entry);
    }

    mysql_free_result(result);
    return rows;
}

bool DatabaseGuard::suspend_server(int server_id, const std::string& reason) {
    std::lock_guard<std::recursive_mutex> lock(mutex_);
    if (server_id <= 0) return false;

    if (try_panel_suspend(server_id, reason)) {
        logger.warn("Server " + std::to_string(server_id) + " suspended via Pterodactyl service");
        return true;
    }

    logger.error("Pterodactyl suspension command failed for server " + std::to_string(server_id) + ", direct DB fallback disabled to preserve suspension policy");

    const char* fallback_env = std::getenv("DANN_ALLOW_DIRECT_DB_SUSPEND_FALLBACK");
    const bool allow_direct_fallback = fallback_env && std::string(fallback_env) == "1";
    if (!allow_direct_fallback) {
        return false;
    }

    logger.warn("Direct DB suspension fallback is enabled by DANN_ALLOW_DIRECT_DB_SUSPEND_FALLBACK=1");
    if (!ensure_connection()) return false;

    std::ostringstream query;
    query << "UPDATE servers SET ";
    if (has_suspended_column) query << "suspended = 1, ";
    query << "status = 'suspended', updated_at = NOW() "
          << "WHERE id = " << server_id << " AND (status IS NULL OR status != 'suspended')";

    if (mysql_query(conn, query.str().c_str()) != 0) {
        logger.error("suspend_server failed: " + std::string(mysql_error(conn)));
        return false;
    }

    return mysql_affected_rows(conn) > 0;
}

int DatabaseGuard::count_suspended_servers() {
    std::lock_guard<std::recursive_mutex> lock(mutex_);
    if (!ensure_connection()) return 0;

    const char* query = "SELECT COUNT(*) FROM servers WHERE status = 'suspended'";
    if (mysql_query(conn, query) != 0) {
        logger.error("count_suspended_servers failed: " + std::string(mysql_error(conn)));
        return 0;
    }

    MYSQL_RES* result = mysql_store_result(conn);
    if (!result) return 0;

    MYSQL_ROW row = mysql_fetch_row(result);
    int count = (row && row[0]) ? atoi(row[0]) : 0;
    mysql_free_result(result);
    return count;
}

bool DatabaseGuard::log_user_violation(
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
) {
    std::lock_guard<std::recursive_mutex> lock(mutex_);
    if (!ensure_connection()) return false;

    if (severity < 1) severity = 1;
    if (severity > 10) severity = 10;

    std::ostringstream q;
    q << "INSERT INTO user_violations "
      << "(user_id, username, server_id, server_uuid, server_name, violation_type, details, file_name, file_size, disk_usage_gb, file_count, action_taken, severity) VALUES ("
      << user_id << ","
      << "'" << escape(username) << "',"
      << server_id << ","
      << "'" << escape(server_uuid) << "',"
      << "'" << escape(server_name) << "',"
      << "'" << escape(violation_type) << "',"
      << "'" << escape(details) << "',"
      << "'" << escape(file_name) << "',"
      << file_size << ","
      << std::fixed << std::setprecision(2) << disk_usage_gb << ","
      << file_count << ","
      << "'" << escape(action_taken) << "',"
      << severity
      << ")";

    if (mysql_query(conn, q.str().c_str()) != 0) {
        logger.error("log_user_violation failed: " + std::string(mysql_error(conn)));
        return false;
    }
    return true;
}

bool DatabaseGuard::log_illegal_file(
    const std::string& file_hash,
    const std::string& file_name,
    const std::string& file_path,
    const std::string& server_uuid,
    int user_id,
    const std::string& detection_reason,
    long long file_size
) {
    std::lock_guard<std::recursive_mutex> lock(mutex_);
    if (!ensure_connection()) return false;

    std::ostringstream q;
    q << "INSERT INTO illegal_files "
      << "(file_hash, file_name, file_path, server_uuid, user_id, detection_reason, file_size, first_seen, last_seen, seen_count) VALUES ("
      << "'" << escape(file_hash) << "',"
      << "'" << escape(file_name) << "',"
      << "'" << escape(file_path) << "',"
      << "'" << escape(server_uuid) << "',"
      << user_id << ","
      << "'" << escape(detection_reason) << "',"
      << file_size << ",NOW(),NOW(),1)"
      << " ON DUPLICATE KEY UPDATE "
      << "last_seen = NOW(), "
      << "seen_count = seen_count + 1, "
      << "detection_reason = VALUES(detection_reason), "
      << "file_size = VALUES(file_size)";

    if (mysql_query(conn, q.str().c_str()) != 0) {
        logger.error("log_illegal_file failed: " + std::string(mysql_error(conn)));
        return false;
    }
    return true;
}

bool DatabaseGuard::bump_daily_stats(int suspend_inc, int files_deleted_inc, int process_killed_inc) {
    std::lock_guard<std::recursive_mutex> lock(mutex_);
    if (!ensure_connection()) return false;

    if (suspend_inc < 0) suspend_inc = 0;
    if (files_deleted_inc < 0) files_deleted_inc = 0;
    if (process_killed_inc < 0) process_killed_inc = 0;

    std::ostringstream q;
    q << "INSERT INTO daily_stats (`date`, total_suspend, total_files_deleted, total_process_killed, unique_users) VALUES ("
      << "CURDATE(), " << suspend_inc << ", " << files_deleted_inc << ", " << process_killed_inc << ", 0)"
      << " ON DUPLICATE KEY UPDATE "
      << "total_suspend = total_suspend + " << suspend_inc << ", "
      << "total_files_deleted = total_files_deleted + " << files_deleted_inc << ", "
      << "total_process_killed = total_process_killed + " << process_killed_inc;

    if (mysql_query(conn, q.str().c_str()) != 0) {
        logger.error("bump_daily_stats failed: " + std::string(mysql_error(conn)));
        return false;
    }
    return true;
}

DatabaseGuard db;
