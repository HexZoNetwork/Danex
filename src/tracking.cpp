#include "tracking.h"
#include "logger.h"
#include "telegram.h"
#include <algorithm>
#include <cstdlib>
#include <fstream>
#include <sstream>
#include <sys/stat.h>

namespace {

constexpr std::size_t kMaxViolationRecords = 10000;

std::string sanitize_field(const std::string& value) {
    std::string out;
    out.reserve(value.size());
    for (char c : value) {
        if (c == '\t' || c == '\n' || c == '\r') out.push_back(' ');
        else out.push_back(c);
    }
    return out;
}

std::string html_escape(const std::string& value) {
    std::string out;
    out.reserve(value.size());
    for (char c : value) {
        switch (c) {
            case '&': out += "&amp;"; break;
            case '<': out += "&lt;"; break;
            case '>': out += "&gt;"; break;
            default: out.push_back(c); break;
        }
    }
    return out;
}

std::vector<std::string> split_tab(const std::string& line) {
    std::vector<std::string> parts;
    std::string part;
    for (char c : line) {
        if (c == '\t') {
            parts.push_back(part);
            part.clear();
        } else {
            part.push_back(c);
        }
    }
    parts.push_back(part);
    return parts;
}

bool file_writable(const std::string& path) {
    std::ofstream f(path, std::ios::app);
    return f.good();
}

std::string choose_store_path() {
    const char* env = std::getenv("PTEROPROTECT_TRACKING_FILE");
    if (env && *env) return env;
    const std::string primary = "/pteroprotect/runtime/tracking_violations.tsv";
    if (file_writable(primary)) return primary;
    const std::string shm = "/dev/shm/pteroprotect/tracking_violations.tsv";
    if (file_writable(shm)) return shm;
    return "/tmp/pteroprotect_tracking.tsv";
}

} // namespace

Tracker::Tracker() : last_report(0) {
    store_path = choose_store_path();
    load();
}

std::size_t Tracker::size() const {
    std::lock_guard<std::mutex> lock(mu);
    return violations.size();
}

std::string Tracker::path() const {
    return store_path;
}

void Tracker::load() {
    std::ifstream f(store_path);
    if (!f.is_open()) return;
    std::string line;
    while (std::getline(f, line)) {
        if (line.empty()) continue;
        auto p = split_tab(line);
        if (p.size() < 7) continue;
        ViolationRecord vr;
        try {
            vr.timestamp = static_cast<time_t>(std::stoll(p[0]));
            vr.uuid = p[1];
            vr.server_name = p[2];
            vr.owner = p[3];
            vr.disk_usage = std::stod(p[4]);
            vr.file_count = std::stoi(p[5]);
        } catch (...) {
            continue;
        }
        vr.reason = p[6];
        violations.push_back(vr);
    }
    if (violations.size() > kMaxViolationRecords) {
        violations.erase(violations.begin(), violations.end() - static_cast<std::ptrdiff_t>(kMaxViolationRecords));
    }
}

bool Tracker::append(const ViolationRecord& record) {
    std::ofstream f(store_path, std::ios::app);
    if (!f.is_open()) return false;
    f << record.timestamp << '\t'
      << sanitize_field(record.uuid) << '\t'
      << sanitize_field(record.server_name) << '\t'
      << sanitize_field(record.owner) << '\t'
      << record.disk_usage << '\t'
      << record.file_count << '\t'
      << sanitize_field(record.reason) << '\n';
    return f.good();
}

void Tracker::rewrite() {
    std::ofstream f(store_path, std::ios::trunc);
    if (!f.is_open()) {
        logger.warn("tracking: cannot rewrite persistence store " + store_path);
        return;
    }
    for (const auto& record : violations) {
        f << record.timestamp << '\t'
          << sanitize_field(record.uuid) << '\t'
          << sanitize_field(record.server_name) << '\t'
          << sanitize_field(record.owner) << '\t'
          << record.disk_usage << '\t'
          << record.file_count << '\t'
          << sanitize_field(record.reason) << '\n';
    }
}

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

    std::lock_guard<std::mutex> lock(mu);
    violations.push_back(vr);
    if (!append(vr)) {
        logger.warn("tracking: failed to persist violation for " + uuid);
    }
    if (violations.size() > kMaxViolationRecords) {
        violations.erase(violations.begin(), violations.end() - static_cast<std::ptrdiff_t>(kMaxViolationRecords));
        rewrite();
    }
}

void Tracker::generate_report() {
    std::lock_guard<std::mutex> lock(mu);
    if (violations.empty()) return;

    time_t now = time(nullptr);
    if (now - last_report < 3600) return;

    std::string report = "📋 VIOLATION REPORT\n";
    report += "━━━━━━━━━━━━━━\n\n";

    int count = 0;
    for (const auto& v : violations) {
        if (count++ >= 10) break;

        report += "• " + html_escape(v.server_name) + "\n";
        report += "  Owner: " + html_escape(v.owner) + "\n";
        report += "  UUID: " + html_escape(v.uuid.substr(0, 8)) + "\n";
        report += "  Reason: " + html_escape(v.reason) + "\n\n";
    }

    bool sent = bot.send_report_message(report);
    if (sent) {
        logger.info("Report sent: " + std::to_string(violations.size()) + " violations");
        last_report = now;
    } else {
        logger.warn("Report delivery failed or Telegram unavailable; will retry next cycle");
    }
}

void Tracker::clear_old(int hours) {
    time_t cutoff = time(nullptr) - (hours * 3600);

    std::lock_guard<std::mutex> lock(mu);
    violations.erase(std::remove_if(violations.begin(), violations.end(),
        [cutoff](const ViolationRecord& v) {
            return v.timestamp < cutoff;
        }), violations.end());
    rewrite();
}

Tracker tracker;
