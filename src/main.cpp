#include <iostream>
#include <thread>
#include <chrono>
#include <signal.h>
#include <iomanip>
#include <sstream>
#include <cstdlib>
#include <fcntl.h>
#include <sys/file.h>
#include <unistd.h>

#include "config.h"
#include "logger.h"
#include "db_guard.h"
#include "telegram.h"
#include "disk_protect.h"
#include "tracker_db.h"
#include "resource_monitor.h"

Config config;
bool running = true;
static volatile sig_atomic_t shutdown_requested = 0;
time_t last_report_time = 0;
time_t last_offender_report = 0;
int guard_lock_fd = -1;

void signal_handler(int sig) {
    (void)sig;
    shutdown_requested = 1;
}

void send_periodic_reports() {
    // Disabled for now
}

void check_blacklisted_users() {
    // Disabled for now
}

std::string get_guard_home() {
    const char* env_home = std::getenv("DANN_GUARD_HOME");
    if (env_home && *env_home) {
        return env_home;
    }

    return "/pteroprotect";
}

bool acquire_single_instance_lock(const std::string& guard_home) {
    std::string lock_path = guard_home + "/dann_guard.lock";
    guard_lock_fd = open(lock_path.c_str(), O_RDWR | O_CREAT, 0644);
    if (guard_lock_fd < 0) {
        std::cerr << "Failed to open lock file: " << lock_path << std::endl;
        return false;
    }

    if (flock(guard_lock_fd, LOCK_EX | LOCK_NB) != 0) {
        std::cerr << "Another dann_guard instance is already running." << std::endl;
        close(guard_lock_fd);
        guard_lock_fd = -1;
        return false;
    }

    ftruncate(guard_lock_fd, 0);
    std::string pid = std::to_string(getpid()) + "\n";
    (void)write(guard_lock_fd, pid.c_str(), pid.size());
    return true;
}

int main() {
    // Setup signal handlers
    signal(SIGINT, signal_handler);
    signal(SIGTERM, signal_handler);
    
    try {
        std::string guard_home = get_guard_home();

        if (!acquire_single_instance_lock(guard_home)) {
            return 1;
        }

        // Load config
        config = Config::load(guard_home + "/config.json");

        const char* offline_env = std::getenv("DANN_GUARD_OFFLINE");
        if ((offline_env == nullptr || *offline_env == '\0') && config.runtime.offline_mode) {
            setenv("DANN_GUARD_OFFLINE", "1", 0);
        }
        
        // Initialize logger
        logger.init(guard_home + "/dann_guard.log");
        logger.info("🚀 DANN GUARD STARTING...");
        
        // Initialize telegram
        bot.init(config.telegram.token, config.telegram.chat_id,
                 config.telegram.channel, config.telegram.report_channel,
                 config.telegram.creator);
        
        // Send startup notification
        bot.notify_startup();

        // Initialize database
        if (!db.init(config.database.host, config.database.user,
                     config.database.password, config.database.name)) {
            logger.error("❌ Failed to initialize database");
            bot.send_report_message(
                "<b>🚨 DANN GUARD STARTUP FAILED</b>\n"
                "<code>Database init failed. Check database.host/user/password/name in config.</code>"
            );
            return 1;
        }
        
        // Initialize tracker database (optional)
        tracker_db.init(config.database.host, config.database.user,
                        config.database.password, config.database.name);
        
        // Initialize disk protector
        disk.init(config.paths.volumes, config.limits.max_disk_gb,
                  config.limits.max_file_size_mb, config.limits.max_file_flood,
                  config.limits.flood_window, config.limits.check_interval);

        // Initialize resource monitor (PTLC CPU/RAM tracking)
        res_monitor.init(config.ptlc.url, config.ptlc.api_key,
                         config.limits.cpu_threshold_pct,
                         config.limits.ram_threshold_pct,
                         config.limits.check_interval);

        logger.info("✅ Guard started - Interval: " + std::to_string(config.limits.check_interval) + "s");

        // Start disk/resource monitors in background threads
        disk.start();
        res_monitor.start();

        // Main thread waits for shutdown signal
        while (!shutdown_requested) {
            std::this_thread::sleep_for(std::chrono::seconds(1));
        }

        logger.info("Received shutdown signal, stopping...");

        disk.stop();
        res_monitor.stop();
        
    } catch (const std::exception& e) {
        logger.error("❌ Fatal error: " + std::string(e.what()));
        return 1;
    }
    
    logger.info("🛑 DANN GUARD STOPPED");
    if (guard_lock_fd >= 0) {
        close(guard_lock_fd);
        guard_lock_fd = -1;
    }
    return 0;
}
