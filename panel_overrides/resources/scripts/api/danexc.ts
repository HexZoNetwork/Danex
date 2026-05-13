import http from '@/api/http';

export interface DanexCMetrics {
    total_requests: number;
    denied_requests: number;
    allowed_requests: number;
    bypassed_requests: number;
    denied_percentage: number;
    allowed_percentage: number;
    bypassed_percentage: number;
}

export interface DanexCTargetPath {
    path: string;
    count: number;
    denied: number;
    allowed: number;
    percentage: number;
    denied_ratio: number;
}

export interface DanexCSystemConfig {
    rate_limit: string;
    active_rules: number;
    detection_sensitivity: string;
    auto_ban_duration: string;
    whitelist_count: number;
    blacklist_count: number;
    protection_mode: string;
    uptime: string;
}

export interface DanexCTimelinePoint {
    timestamp: string;
    allowed: number;
    denied: number;
    bypassed: number;
}

export interface DanexCThreat {
    level: 'low' | 'medium' | 'high';
    score: number;
    reason_codes: string[];
}

export interface DanexCFeedItem {
    timestamp: string;
    severity: 'info' | 'warning' | 'danger';
    message: string;
}

export interface DanexCOverviewResponse {
    metrics: DanexCMetrics;
    most_targeted_paths: DanexCTargetPath[];
    system_config: DanexCSystemConfig;
    timeline: DanexCTimelinePoint[];
    threat: DanexCThreat;
    live_feed: DanexCFeedItem[];
    meta: {
        window_minutes: number;
        generated_at: string;
    };
}

export const getDanexCOverview = async (windowMinutes = 60): Promise<DanexCOverviewResponse> => {
    const { data } = await http.get('/api/client/danexc/overview', { params: { window: windowMinutes } });
    return data;
};

export const getDanexCTimeline = async (windowMinutes = 60): Promise<{ timeline: DanexCTimelinePoint[] }> => {
    const { data } = await http.get('/api/client/danexc/timeline', { params: { window: windowMinutes } });
    return data;
};

export const getDanexCFeed = async (limit = 40): Promise<{ live_feed: DanexCFeedItem[] }> => {
    const { data } = await http.get('/api/client/danexc/feed', { params: { limit } });
    return data;
};
