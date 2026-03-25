#ifndef TELEGRAM_H
#define TELEGRAM_H

#include <string>
#include <curl/curl.h>
#include "db_guard.h"

class TelegramBot {
private:
    std::string token;
    std::string chat_id;
    std::string channel;
    std::string report_channel;
    std::string creator;
    
    static size_t write_callback(void* contents, size_t size, size_t nmemb, std::string* output);
    
public:
    TelegramBot();
    
    void init(const std::string& t, const std::string& cid,
              const std::string& ch, const std::string& rep, 
              const std::string& cr);
    
    bool send_html_message(const std::string& message);
    bool send_report_message(const std::string& message);

    void notify_suspend(const ServerInfo& info, const std::string& reason, 
                        const std::string& details, const std::string& action);
    
    void notify_files_deleted(const ServerInfo& info, const std::string& files);
    
    void notify_process_killed(const ServerInfo& info, int pid, 
                               const std::string& pname, const std::string& reason);
    
    void notify_flood(const ServerInfo& info, int new_files, const std::string& pattern);
    
    void notify_disk_over(const ServerInfo& info, double total_gb, int file_count);
    
    void notify_startup();

private:
    bool send_html_message_to(const std::string& target_chat_id, const std::string& message);
};

extern TelegramBot bot;

#endif
