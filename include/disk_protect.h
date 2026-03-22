#ifndef DISK_PROTECT_H
#define DISK_PROTECT_H

#include <string>
#include <vector>
#include <map>
#include <thread>
#include <atomic>
#include <ctime>

// ServerInfo is defined in db_guard.h
#include "db_guard.h"

struct FileInfo {
    std::string name;
    std::string path;
    std::string extension;
    std::string hash;

    long long size = 0;
    time_t modified = 0;
    time_t accessed = 0;

    bool is_directory = false;
    bool is_symlink = false;
    bool is_suspicious = false;

    std::string suspicion_reason;
};

class DiskProtector {
private:
    std::string volumes_path;
    double max_disk_gb = 10.0;
    int max_file_size_mb = 200;
    int max_file_flood = 1000;
    int flood_window = 60;
    int check_interval = 10;

    bool auto_cleanup = true;
    bool auto_suspend = true;

    std::atomic<bool> running;
    std::thread monitor_thread;

public:
    DiskProtector();
    ~DiskProtector();

    void init(const std::string& path, double max_disk, int max_size,
              int max_flood, int window, int interval);

    void start();
    void stop();
    void run_loop();

    void scan_all();
    void scan_now();
    void clean_now();

    void check_server(const std::string& uuid);

    double get_folder_size_gb(const std::string& path);
    std::vector<FileInfo> scan_folder(const std::string& path, int depth = 0);
    bool is_suspicious_file(const FileInfo& file, std::string& reason);
    bool is_path_allowed(const std::string& path);

    std::string get_file_hash(const std::string& path);
    bool is_binary_file(const std::string& path);
    void delete_file(const std::string& path, const std::string& reason);
    bool quarantine_file(const std::string& server_uuid, const FileInfo& file,
                         std::string& quarantined_path);
    void release_expired_quarantine();

    int get_total_servers();
    int get_suspended_count();
    std::vector<ServerInfo> get_all_servers();
    std::map<std::string, double> get_disk_usage();
};

extern DiskProtector disk;

#endif
