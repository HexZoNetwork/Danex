const SAMPLE_KEY = 'danex_rum_sample_v1';
const SAMPLE_RATE = 0.005;
const MAX_QUEUE = 24;
const FLUSH_COUNT = 8;
const FLUSH_INTERVAL_MS = 120000;

type RumMetric =
    | 'LCP'
    | 'CLS'
    | 'INP'
    | 'FCP'
    | 'TTFB'
    | 'API_LATENCY'
    | 'JS_ERROR'
    | 'UNHANDLED_REJECTION';

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

const shouldEnableForDevice = () => {
    try {
        const nav = navigator as any;
        const connection = nav.connection;
        if (connection && connection.saveData === true) return false;
        const memory = Number(nav.deviceMemory || 0);
        if (memory > 0 && memory <= 2) return false;
        const cores = Number(nav.hardwareConcurrency || 0);
        if (cores > 0 && cores <= 2) return false;
    } catch {
        // no-op
    }

    return true;
};

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
    window.setTimeout(() => {
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
    }, 1500);
};

export const rumTrackApi = (path: string, durationMs: number, status?: number) => {
    if (!enabled) return;
    const isError = !!status && status >= 500;
    if (!isError && durationMs < 3000) return;
    if (!isError && Math.random() > 0.2) return;
    enqueueRum({
        metric: 'API_LATENCY',
        api_path: path.slice(0, 255),
        value: Math.max(0, durationMs),
        status,
        rating: durationMs <= 300 ? 'good' : durationMs <= 1000 ? 'needs-improvement' : 'poor',
    });
};

const setupErrorCollectors = () => {
    window.addEventListener('error', (event) => {
        const source = String(event.filename || '');
        if (source.startsWith('chrome-extension://') || source.startsWith('moz-extension://')) {
            return;
        }
        enqueueRum({
            metric: 'JS_ERROR',
            value: 1,
            meta: {
                message: String(event.message || ''),
                source,
            },
        });
    });
    window.addEventListener('unhandledrejection', (event) => {
        const message = String((event.reason && (event.reason.message || event.reason)) || 'unknown');
        if (message.includes('chrome-extension://') || message.includes('moz-extension://')) {
            return;
        }
        enqueueRum({
            metric: 'UNHANDLED_REJECTION',
            value: 1,
            meta: {
                message,
            },
        });
    });
};

export const initRum = () => {
    if (started) return;
    started = true;
    if (!shouldEnableForDevice()) return;
    enabled = shouldSample();
    if (!enabled) return;
    window.__danexRumTrackApi = rumTrackApi;
    observeCoreWebVitals();
    setupErrorCollectors();
    flushTimer = window.setInterval(() => flushRumQueue(), FLUSH_INTERVAL_MS);
    window.addEventListener('beforeunload', () => flushRumQueue(true));
    window.addEventListener('pagehide', () => flushRumQueue(true));
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
            flushRumQueue(true);
        }
    });
};

export const stopRum = () => {
    if (flushTimer) {
        window.clearInterval(flushTimer);
        flushTimer = undefined;
    }
    delete window.__danexRumTrackApi;
    flushRumQueue(true);
};
