#ifndef LOGGER_H
#define LOGGER_H

#include <string>
#include <fstream>

class Logger {
private:
    std::ofstream log_file;
    std::string get_timestamp();
    
public:
    Logger();
    ~Logger();
    
    void init(const std::string& path);
    void info(const std::string& msg);
    void warn(const std::string& msg);
    void error(const std::string& msg);
};

extern Logger logger;

#endif
