const SAMPLE_KEY = 'danex_rum_sample_v1';
const SAMPLE_RATE = 0.01;
const MAX_QUEUE = 50;
const FLUSH_COUNT = 20;
const FLUSH_INTERVAL_MS = 60000;

type RumMetric =
    | 'LCP'
    | 'CLS'
    | 'INP'
    | 'FCP'
    | 'TTFB'
    | 'api_latency'
    | 'js_error'
    | 'unhandled_rejection';

interface RumEvent {
    metric: RumMetric;
    value?: number;
    route?: string;
    rating?: 'good' | 'needs-improvement' | 'poor' | string;
    delta?: number;
    ttfb?: number;
    status?: number;
    api_path?: string;
    at?: number;
    meta?: Record<string, unknown>;
}

let enabled = false;
let started = false;
let queue: RumEvent[] = [];
let flushTimer: number | undefined;

const nowSec = () => Math.floor(Date.now() / 1000);
const routePath = () => window.location.pathname || '/';

const shouldSample = () => {
    try {
        const existing = sessionStorage.getItem(SAMPLE_KEY);
        if (existing === '1') return true;
        if (existing === '0') return false;
        const sampled = Math.random() < SAMPLE_RATE;
        sessionStorage.setItem(SAMPLE_KEY, sampled ? '1' : '0');
        return sampled;
    } catch {
        return Math.random() < SAMPLE_RATE;
    }
};

const ratingFor = (metric: RumMetric, value: number): 'good' | 'needs-improvement' | 'poor' => {
    if (metric === 'LCP') {
        if (value <= 2500) return 'good';
        if (value <= 4000) return 'needs-improvement';
        return 'poor';
    }
    if (metric === 'INP') {
        if (value <= 200) return 'good';
        if (value <= 500) return 'needs-improvement';
        return 'poor';
    }
    if (metric === 'CLS') {
        if (value <= 0.1) return 'good';
        if (value <= 0.25) return 'needs-improvement';
        return 'poor';
    }
    return 'good';
};

declare global {
    interface Window {
        __danexRumTrackApi?: (path: string, durationMs: number, status?: number) => void;
    }
}

const sendEvents = (events: RumEvent[]) => {
    if (!events.length) return;
    const payload = JSON.stringify({ events });
    const url = '/api/client/rum';
    try {
        if (navigator.sendBeacon) {
            const blob = new Blob([payload], { type: 'application/json' });
            if (navigator.sendBeacon(url, blob)) return;
        }
    } catch {
        // no-op
    }
    void fetch(url, {
        method: 'POST',
        credentials: 'include',
        keepalive: true,
        headers: { 'Content-Type': 'application/json' },
        body: payload,
    }).catch(() => undefined);
};

const flushRumQueue = (force = false) => {
    if (!enabled || !queue.length) return;
    if (!force && queue.length < FLUSH_COUNT) return;
    const events = queue.splice(0, queue.length);
    sendEvents(events);
};

const enqueueRum = (event: RumEvent) => {
    if (!enabled) return;
    queue.push({ ...event, route: event.route || routePath(), at: event.at || nowSec() });
    if (queue.length > MAX_QUEUE) {
        queue = queue.slice(queue.length - MAX_QUEUE);
    }
    if (queue.length >= FLUSH_COUNT) {
        flushRumQueue();
    }
};

const observeCoreWebVitals = () => {
    const nav = performance.getEntriesByType('navigation')[0] as PerformanceNavigationTiming | undefined;
    if (nav && nav.responseStart > 0) {
        enqueueRum({
            metric: 'TTFB',
            value: nav.responseStart,
            rating: nav.responseStart <= 800 ? 'good' : nav.responseStart <= 1800 ? 'needs-improvement' : 'poor',
        });
    }

    const fcp = performance.getEntriesByName('first-contentful-paint')[0] as PerformanceEntry | undefined;
    if (fcp) {
        const value = fcp.startTime;
        enqueueRum({
            metric: 'FCP',
            value,
            rating: value <= 1800 ? 'good' : value <= 3000 ? 'needs-improvement' : 'poor',
        });
    }

};

export const rumTrackApi = (path: string, durationMs: number, status?: number) => {
    if (!enabled) return;
    const isError = !!status && status >= 500;
    if (!isError && durationMs < 2000) return;
    if (!isError && Math.random() > 0.05) return;
    enqueueRum({
        metric: 'api_latency',
        api_path: path.slice(0, 255),
        value: Math.max(0, durationMs),
        status,
        rating: durationMs <= 300 ? 'good' : durationMs <= 1000 ? 'needs-improvement' : 'poor',
    });
};

const setupErrorCollectors = () => {
    window.addEventListener('error', (event) => {
        enqueueRum({
            metric: 'js_error',
            value: 1,
            meta: {
                message: String(event.message || ''),
                source: String(event.filename || ''),
            },
        });
    });
    window.addEventListener('unhandledrejection', (event) => {
        enqueueRum({
            metric: 'unhandled_rejection',
            value: 1,
            meta: {
                message: String((event.reason && (event.reason.message || event.reason)) || 'unknown'),
            },
        });
    });
};

export const initRum = () => {
    if (started) return;
    started = true;
    enabled = shouldSample();
    if (!enabled) return;
    window.__danexRumTrackApi = rumTrackApi;
    observeCoreWebVitals();
    setupErrorCollectors();
    flushTimer = window.setInterval(() => flushRumQueue(), FLUSH_INTERVAL_MS);
    window.addEventListener('beforeunload', () => flushRumQueue(true));
};

export const stopRum = () => {
    if (flushTimer) {
        window.clearInterval(flushTimer);
        flushTimer = undefined;
    }
    delete window.__danexRumTrackApi;
    flushRumQueue(true);
};
