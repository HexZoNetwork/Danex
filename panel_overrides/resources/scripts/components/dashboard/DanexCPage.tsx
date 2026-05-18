import React, { useCallback, useEffect, useMemo, useState } from 'react';
import tw from 'twin.macro';
import styled from 'styled-components/macro';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faBolt,
    faChartLine,
    faCheck,
    faClock,
    faExclamationTriangle,
    faFire,
    faSyncAlt,
    faShieldAlt,
    faSlidersH,
    faTimes,
} from '@fortawesome/free-solid-svg-icons';
import PageContentBlock from '@/components/elements/PageContentBlock';
import {
    DanexCFeedItem,
    DanexCOverviewResponse,
    DanexCTargetPath,
    DanexCTimelinePoint,
    getDanexCFeed,
    getDanexCOverview,
    getDanexCTimeline,
} from '@/api/danexc';
import { Line } from 'react-chartjs-2';
import { Chart as ChartJS, Filler, Legend, LineElement, LinearScale, PointElement, Tooltip, CategoryScale } from 'chart.js';
import useWafRealtime, { WafRealtimeEvent } from '@/plugins/useWafRealtime';

ChartJS.register(LineElement, LinearScale, PointElement, Tooltip, Legend, CategoryScale, Filler);

const Surface = styled.div<{ $delay?: number }>`
    ${tw`rounded-lg border p-4 relative overflow-hidden`};
    background: #0b0b10;
    border-color: rgba(139, 92, 246, 0.24);
    box-shadow: 0 18px 48px rgba(0, 0, 0, 0.5), inset 0 1px 0 rgba(255, 255, 255, 0.04);
    animation: danex-fade-up 420ms var(--el7-ease) both;
    animation-delay: ${({ $delay }) => `${$delay || 0}ms`};
    transition: transform 260ms var(--el7-ease), border-color 260ms var(--el7-ease), box-shadow 260ms var(--el7-ease);

    &::before {
        content: '';
        position: absolute;
        inset: 0;
        pointer-events: none;
        border-top: 1px solid rgba(255, 255, 255, 0.05);
    }

    &:hover {
        transform: translateY(-4px);
        border-color: rgba(139, 92, 246, 0.72);
        box-shadow: 0 24px 64px rgba(0, 0, 0, 0.58), 0 0 30px rgba(139, 92, 246, 0.2);
    }
`;

const Hero = styled.div`
    ${tw`relative overflow-hidden rounded-2xl border p-5 sm:p-6`};
    background:
        radial-gradient(circle at 12% 0%, rgba(139, 92, 246, 0.3), transparent 24rem),
        radial-gradient(circle at 88% 12%, rgba(6, 182, 212, 0.14), transparent 22rem),
        linear-gradient(135deg, #09090d 0%, #111117 58%, #07070b 100%);
    border-color: rgba(139, 92, 246, 0.34);
    box-shadow: 0 30px 90px rgba(0, 0, 0, 0.62), 0 0 54px rgba(139, 92, 246, 0.16);

    &::before {
        content: '';
        position: absolute;
        inset: 0;
        pointer-events: none;
        background:
            repeating-linear-gradient(90deg, rgba(255, 255, 255, 0.025) 0 1px, transparent 1px 64px),
            repeating-linear-gradient(0deg, rgba(255, 255, 255, 0.016) 0 1px, transparent 1px 64px),
            linear-gradient(110deg, transparent 0 44%, rgba(139, 92, 246, 0.12) 45%, transparent 47% 100%);
        opacity: 0.78;
    }
`;

const Pill = styled.button<{ $active?: boolean }>`
    ${tw`rounded-full border px-3 py-1.5 text-[11px] font-semibold uppercase tracking-wider transition`};
    color: ${({ $active }) => ($active ? '#ffffff' : '#a3a3b2')};
    background: ${({ $active }) => ($active ? 'rgba(139, 92, 246, 0.34)' : 'rgba(7, 7, 11, 0.72)')};
    border-color: ${({ $active }) => ($active ? 'rgba(167, 139, 250, 0.72)' : 'rgba(139, 92, 246, 0.22)')};
    box-shadow: ${({ $active }) => ($active ? '0 0 22px rgba(139, 92, 246, 0.28)' : 'none')};

    &:hover {
        color: #ffffff;
        border-color: rgba(167, 139, 250, 0.64);
    }
`;

const MiniMetric = styled.div`
    ${tw`rounded-xl border px-3 py-2`};
    background: rgba(7, 7, 11, 0.72);
    border-color: rgba(139, 92, 246, 0.2);
`;

const Label = styled.p`
    ${tw`text-[11px] uppercase tracking-widest font-semibold`};
    color: #a3a3b2;
`;

const StatValue = styled.p`
    ${tw`font-bold text-3xl leading-tight`};
    color: #f9fafb;
    letter-spacing: 0;
`;

const Segment = styled.button<{ $active: boolean }>`
    ${tw`px-3 py-1.5 text-[11px] font-semibold uppercase transition-all duration-200`};
    color: ${({ $active }) => ($active ? '#ffffff' : '#a3a3b2')};
    background: ${({ $active }) => ($active ? '#1a1428' : '#0f0f16')};
    border-left: 1px solid rgba(139, 92, 246, 0.2);

    &:first-child {
        border-left: 0;
    }

    &:hover {
        color: #ffffff;
        background: rgba(139, 92, 246, 0.14);
    }
`;

const BarTrack = styled.div`
    ${tw`h-2 rounded-full overflow-hidden`};
    background: #15151d;
    border: 1px solid rgba(139, 92, 246, 0.22);
`;

const emptyState: DanexCOverviewResponse = {
    metrics: {
        total_requests: 0,
        denied_requests: 0,
        allowed_requests: 0,
        bypassed_requests: 0,
        denied_percentage: 0,
        allowed_percentage: 0,
        bypassed_percentage: 0,
    },
    most_targeted_paths: [],
    system_config: {
        rate_limit: 'n/a',
        active_rules: 0,
        detection_sensitivity: 'n/a',
        auto_ban_duration: 'n/a',
        whitelist_count: 0,
        blacklist_count: 0,
        protection_mode: 'Learning',
        uptime: 'n/a',
    },
    timeline: [],
    threat: { level: 'low', score: 0, reason_codes: [] },
    live_feed: [],
    meta: { window_minutes: 60, generated_at: '' },
};

const normalizeOverview = (data: Partial<DanexCOverviewResponse> | null | undefined): DanexCOverviewResponse => ({
    metrics: { ...emptyState.metrics, ...(data?.metrics || {}) },
    most_targeted_paths: Array.isArray(data?.most_targeted_paths) ? data.most_targeted_paths : [],
    system_config: { ...emptyState.system_config, ...(data?.system_config || {}) },
    timeline: Array.isArray(data?.timeline) ? data.timeline : [],
    threat: {
        ...emptyState.threat,
        ...(data?.threat || {}),
        reason_codes: Array.isArray(data?.threat?.reason_codes) ? data.threat.reason_codes : [],
    },
    live_feed: Array.isArray(data?.live_feed) ? data.live_feed : [],
    meta: { ...emptyState.meta, ...(data?.meta || {}) },
});

const threatColor = (level: string): string => {
    if (level === 'high') return '#ef4444';
    if (level === 'medium') return '#f59e0b';
    return '#10b981';
};

const counterStep = (target: number, progress: number) => Math.round(target * (1 - Math.pow(1 - progress, 3)));

const AnimatedNumber = ({ value }: { value: number }) => {
    const [display, setDisplay] = useState(0);

    useEffect(() => {
        let frame = 0;
        let raf = 0;
        const total = 60;
        const tick = () => {
            frame += 1;
            setDisplay(counterStep(value, Math.min(1, frame / total)));
            if (frame < total) raf = window.requestAnimationFrame(tick);
        };
        raf = window.requestAnimationFrame(tick);
        return () => window.cancelAnimationFrame(raf);
    }, [value]);

    return <>{display.toLocaleString()}</>;
};

export default () => {
    const [overview, setOverview] = useState<DanexCOverviewResponse>(emptyState);
    const [feed, setFeed] = useState<DanexCFeedItem[]>([]);
    const [timeline, setTimeline] = useState<DanexCTimelinePoint[]>([]);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(true);
    const [sortBy, setSortBy] = useState<'count' | 'denied_ratio'>('count');
    const [toast, setToast] = useState('');
    const [windowMinutes, setWindowMinutes] = useState<15 | 60 | 360>(60);
    const [refreshing, setRefreshing] = useState(false);
    const [lastUpdated, setLastUpdated] = useState<Date | null>(null);

    const loadOverview = useCallback(async (minutes: number) => {
        const data = await getDanexCOverview(minutes);
        setOverview(normalizeOverview(data));
    }, []);

    const loadTimeline = useCallback(async (minutes: number) => {
        const data = await getDanexCTimeline(minutes);
        setTimeline(Array.isArray(data.timeline) ? data.timeline : []);
    }, []);

    const loadFeed = useCallback(async () => {
        const data = await getDanexCFeed(40);
        setFeed(Array.isArray(data.live_feed) ? data.live_feed : []);
    }, []);

    const refreshAll = useCallback(async () => {
        setRefreshing(true);
        try {
            await Promise.all([loadOverview(windowMinutes), loadTimeline(windowMinutes), loadFeed()]);
            setLastUpdated(new Date());
        } finally {
            setRefreshing(false);
        }
    }, [loadFeed, loadOverview, loadTimeline, windowMinutes]);

    const onRealtimeEvent = useCallback(
        (event: WafRealtimeEvent) => {
            if (event.type === 'request.new' || event.type === 'threat.change') {
                setToast(event.type === 'request.new' ? 'New request sampled' : 'Threat level updated');
                void refreshAll();
            }
        },
        [refreshAll]
    );
    const { isConnected } = useWafRealtime({ enabled: true, onEvent: onRealtimeEvent });

    useEffect(() => {
        let mounted = true;
        (async () => {
            try {
                await refreshAll();
                if (mounted) setError('');
            } catch (e: any) {
                if (mounted) setError(String(e?.response?.data?.error || 'Failed to load DanexC telemetry.'));
            } finally {
                if (mounted) setLoading(false);
            }
        })();

        const fast = window.setInterval(() => {
            if (isConnected) return;
            void loadOverview(windowMinutes);
            void loadFeed();
        }, 5000);
        const slow = window.setInterval(() => {
            if (isConnected) return;
            void loadTimeline(windowMinutes);
        }, 10000);

        return () => {
            mounted = false;
            window.clearInterval(fast);
            window.clearInterval(slow);
        };
    }, [isConnected, loadFeed, loadOverview, loadTimeline, refreshAll, windowMinutes]);

    useEffect(() => {
        if (!toast) return;
        const timer = window.setTimeout(() => setToast(''), 2200);
        return () => window.clearTimeout(timer);
    }, [toast]);

    const sortedPaths = useMemo(() => {
        const rows = [...(overview.most_targeted_paths || [])];
        rows.sort((a, b) => (sortBy === 'denied_ratio' ? Number(b.denied_ratio || 0) - Number(a.denied_ratio || 0) : Number(b.count || 0) - Number(a.count || 0)));
        return rows.slice(0, 15);
    }, [overview.most_targeted_paths, sortBy]);

    const derived = useMemo(() => {
        const total = Math.max(0, Number(overview.metrics.total_requests || 0));
        const denied = Math.max(0, Number(overview.metrics.denied_requests || 0));
        const allowed = Math.max(0, Number(overview.metrics.allowed_requests || 0));
        const bypassed = Math.max(0, Number(overview.metrics.bypassed_requests || 0));
        const sampled = Math.max(1, windowMinutes);
        const denyRate = total > 0 ? Math.round((denied / total) * 1000) / 10 : 0;
        const allowedRate = total > 0 ? Math.round((allowed / total) * 1000) / 10 : 0;
        const bypassRate = total > 0 ? Math.round((bypassed / total) * 1000) / 10 : 0;
        return {
            denyRate,
            allowedRate,
            bypassRate,
            rpm: Math.round((total / sampled) * 10) / 10,
            protectedPaths: overview.most_targeted_paths?.length || 0,
            denyPressure: denied > allowed ? 'Hostile edge pressure' : denied > 0 ? 'Controlled filtering' : 'Quiet perimeter',
        };
    }, [overview.metrics, overview.most_targeted_paths, windowMinutes]);

    const chartData = useMemo(() => {
        const source = (timeline.length ? timeline : overview.timeline).filter((p) => p && p.timestamp);
        return {
            labels: source.map((p) => new Date(p.timestamp).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })),
            datasets: [
                {
                    label: 'Allowed',
                    data: source.map((p) => Number(p.allowed || 0)),
                    borderColor: '#8b5cf6',
                    backgroundColor: 'rgba(139, 92, 246, 0.1)',
                    pointBackgroundColor: '#a78bfa',
                    pointBorderColor: '#0b0b10',
                    fill: true,
                    tension: 0.34,
                },
                {
                    label: 'Denied',
                    data: source.map((p) => Number(p.denied || 0)),
                    borderColor: '#ef4444',
                    backgroundColor: 'rgba(239, 68, 68, 0.08)',
                    pointBackgroundColor: '#ef4444',
                    pointBorderColor: '#0b0b10',
                    fill: true,
                    tension: 0.34,
                },
            ],
        };
    }, [overview.timeline, timeline]);

    const chartOptions = useMemo(
        () => ({
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 1500, easing: 'easeOutQuart' as const },
            interaction: { intersect: false, mode: 'index' as const },
            plugins: {
                legend: {
                    labels: { color: '#a3a3b2', boxWidth: 10, boxHeight: 10 },
                },
                tooltip: {
                    backgroundColor: '#0b0b10',
                    borderColor: 'rgba(139, 92, 246, 0.45)',
                    borderWidth: 1,
                    titleColor: '#ffffff',
                    bodyColor: '#d4d4df',
                },
            },
            scales: {
                x: {
                    ticks: { color: '#74748a', maxRotation: 0, autoSkip: true },
                    grid: { color: 'rgba(139, 92, 246, 0.08)' },
                },
                y: {
                    ticks: { color: '#74748a' },
                    grid: { color: 'rgba(139, 92, 246, 0.08)' },
                },
            },
        }),
        []
    );

    const stats = [
        ['Total Requests', overview.metrics.total_requests || 0, '#a78bfa', faChartLine, 'Live sampled traffic'],
        ['Denied Requests', overview.metrics.denied_requests || 0, '#ef4444', faTimes, `${overview.metrics.denied_percentage || 0}% of total`],
        ['Allowed Requests', overview.metrics.allowed_requests || 0, '#10b981', faCheck, `${overview.metrics.allowed_percentage || 0}% of total`],
        ['Bypassed Requests', overview.metrics.bypassed_requests || 0, '#f59e0b', faExclamationTriangle, `${overview.metrics.bypassed_percentage || 0}% of total`],
    ] as const;

    return (
        <PageContentBlock title={'WAF Dashboard'} showFlashKey={'dashboard'}>
            <div css={tw`mx-auto w-full max-w-7xl space-y-4`}>
                {toast && (
                    <div
                        css={tw`fixed right-4 top-20 z-50 rounded-lg border px-4 py-3 text-sm text-neutral-100 shadow-xl`}
                        style={{ background: '#0b0b10', borderColor: 'rgba(139, 92, 246, 0.46)' }}
                    >
                        {toast}
                    </div>
                )}
                {error && (
                    <div css={tw`rounded-lg border border-red-700 bg-red-900/20 px-3 py-2 text-sm text-red-200`}>
                        {error}
                    </div>
                )}

                <Hero>
                    <div css={tw`relative z-10 flex flex-col gap-5 xl:flex-row xl:items-end xl:justify-between`}>
                        <div css={tw`max-w-3xl`}>
                            <Label>DANEX X EL7 SECURITY MONITOR</Label>
                            <h1 css={tw`mt-2 text-3xl sm:text-4xl font-black tracking-tight text-neutral-50`}>WAF Command Dashboard</h1>
                            <p css={tw`mt-2 max-w-2xl text-sm leading-6 text-neutral-300`}>
                                Live perimeter counts, threat pressure, targeted paths, and feed telemetry in one control surface.
                            </p>
                            <div css={tw`mt-4 flex flex-wrap gap-2`}>
                                {[15, 60, 360].map((minutes) => (
                                    <Pill key={minutes} type={'button'} $active={windowMinutes === minutes} onClick={() => setWindowMinutes(minutes as 15 | 60 | 360)}>
                                        {minutes === 360 ? '6h' : `${minutes}m`}
                                    </Pill>
                                ))}
                                <Pill type={'button'} onClick={() => void refreshAll()} disabled={refreshing}>
                                    <FontAwesomeIcon icon={faSyncAlt} css={tw`mr-2`} spin={refreshing} /> refresh
                                </Pill>
                            </div>
                        </div>
                        <div css={tw`grid grid-cols-2 gap-2 sm:grid-cols-4 xl:min-w-[520px]`}>
                            <MiniMetric>
                                <p css={tw`text-[10px] uppercase tracking-wider text-neutral-500`}>Connection</p>
                                <p css={tw`mt-1 text-sm font-semibold text-neutral-100`}>
                                    <span css={tw`mr-2 inline-block h-2 w-2 rounded-full`} style={{ background: isConnected ? '#10b981' : '#f59e0b', boxShadow: `0 0 14px ${isConnected ? '#10b981' : '#f59e0b'}` }} />
                                    {isConnected ? 'Live WS' : 'Polling'}
                                </p>
                            </MiniMetric>
                            <MiniMetric>
                                <p css={tw`text-[10px] uppercase tracking-wider text-neutral-500`}>Requests/min</p>
                                <p css={tw`mt-1 text-lg font-bold text-purple-100`}>{derived.rpm.toLocaleString()}</p>
                            </MiniMetric>
                            <MiniMetric>
                                <p css={tw`text-[10px] uppercase tracking-wider text-neutral-500`}>Denied rate</p>
                                <p css={tw`mt-1 text-lg font-bold`} style={{ color: threatColor(overview.threat.level) }}>{derived.denyRate}%</p>
                            </MiniMetric>
                            <MiniMetric>
                                <p css={tw`text-[10px] uppercase tracking-wider text-neutral-500`}>Updated</p>
                                <p css={tw`mt-1 text-sm font-semibold text-neutral-100`}>{lastUpdated ? lastUpdated.toLocaleTimeString() : 'booting'}</p>
                            </MiniMetric>
                        </div>
                    </div>
                </Hero>

                <div css={tw`grid gap-3 grid-cols-1 md:grid-cols-3`}>
                    <Surface $delay={120}>
                        <div css={tw`relative z-10 flex items-center justify-between gap-3`}>
                            <div>
                                <Label>Protection Posture</Label>
                                <p css={tw`mt-2 text-xl font-bold text-neutral-100`}>{derived.denyPressure}</p>
                                <p css={tw`mt-1 text-xs text-neutral-400`}>{overview.system_config.protection_mode} mode, {overview.system_config.active_rules} active rules</p>
                            </div>
                            <FontAwesomeIcon icon={faShieldAlt} css={tw`text-3xl`} style={{ color: threatColor(overview.threat.level) }} />
                        </div>
                    </Surface>
                    <Surface $delay={180}>
                        <div css={tw`relative z-10 flex items-center justify-between gap-3`}>
                            <div>
                                <Label>Routing Split</Label>
                                <p css={tw`mt-2 text-xl font-bold text-neutral-100`}>{derived.allowedRate}% allowed</p>
                                <p css={tw`mt-1 text-xs text-neutral-400`}>{derived.bypassRate}% bypassed by safe-path logic</p>
                            </div>
                            <FontAwesomeIcon icon={faBolt} css={tw`text-3xl text-purple-300`} />
                        </div>
                    </Surface>
                    <Surface $delay={240}>
                        <div css={tw`relative z-10 flex items-center justify-between gap-3`}>
                            <div>
                                <Label>Hot Paths</Label>
                                <p css={tw`mt-2 text-xl font-bold text-neutral-100`}>{derived.protectedPaths.toLocaleString()} tracked</p>
                                <p css={tw`mt-1 text-xs text-neutral-400`}>Sorted by volume or denied ratio</p>
                            </div>
                            <FontAwesomeIcon icon={faFire} css={tw`text-3xl text-yellow-300`} />
                        </div>
                    </Surface>
                </div>

                <div css={tw`grid gap-3 grid-cols-1 sm:grid-cols-2 xl:grid-cols-4`}>
                    {stats.map(([title, value, color, icon, subtitle], index) => (
                        <Surface key={title} $delay={index * 80}>
                            <div css={tw`relative z-10 flex items-start justify-between gap-4`}>
                                <div>
                                    <Label>{title}</Label>
                                    <StatValue style={{ color }}>
                                        <AnimatedNumber value={Number(value)} />
                                    </StatValue>
                                    <p css={tw`mt-1 text-[12px] text-neutral-400`}>{subtitle}</p>
                                </div>
                                <div css={tw`rounded-lg border p-3`} style={{ borderColor: 'rgba(139, 92, 246, 0.28)', color, background: '#111117' }}>
                                    <FontAwesomeIcon icon={icon} />
                                </div>
                            </div>
                        </Surface>
                    ))}
                </div>

                <div css={tw`grid gap-3 grid-cols-1 xl:grid-cols-3`}>
                    <Surface css={tw`xl:col-span-2 h-[330px]`} $delay={420}>
                        <div css={tw`relative z-10 h-full`}>
                            <div css={tw`flex items-center justify-between mb-3`}>
                                <Label>Traffic Timeline</Label>
                                <p css={tw`text-[11px] uppercase tracking-wider text-neutral-500`}>{windowMinutes === 360 ? '6h' : `${windowMinutes}m`} rolling</p>
                            </div>
                            <div css={tw`h-[272px]`}>
                                <Line data={chartData} options={chartOptions} />
                            </div>
                        </div>
                    </Surface>

                    <Surface $delay={500}>
                        <div css={tw`relative z-10`}>
                            <div css={tw`flex items-center justify-between`}>
                                <Label>Threat Level</Label>
                                <FontAwesomeIcon icon={faShieldAlt} style={{ color: threatColor(overview.threat.level) }} />
                            </div>
                            <p css={tw`mt-3 text-4xl font-bold uppercase`} style={{ color: threatColor(overview.threat.level) }}>
                                {overview.threat.level}
                            </p>
                            <BarTrack css={tw`mt-4 h-4`}>
                                <div
                                    css={tw`h-full transition-all duration-700`}
                                    style={{ width: `${overview.threat.score}%`, background: threatColor(overview.threat.level), boxShadow: `0 0 20px ${threatColor(overview.threat.level)}` }}
                                />
                            </BarTrack>
                            <p css={tw`mt-2 text-xs text-neutral-400`}>Score: {overview.threat.score}/100 · {derived.denyRate}% denied</p>
                            <div css={tw`mt-4 space-y-1`}>
                                {(overview.threat.reason_codes || []).slice(0, 6).map((r) => (
                                    <div key={r} css={tw`rounded border px-2 py-1 text-[11px] text-purple-200 font-mono`} style={{ borderColor: 'rgba(139, 92, 246, 0.2)', background: '#111117' }}>
                                        {r}
                                    </div>
                                ))}
                            </div>
                        </div>
                    </Surface>
                </div>

                <div css={tw`grid gap-3 grid-cols-1 xl:grid-cols-3`}>
                    <Surface css={tw`xl:col-span-2`} $delay={580}>
                        <div css={tw`relative z-10`}>
                            <div css={tw`flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between mb-3`}>
                                <Label>Most Targeted Paths</Label>
                                <div css={tw`inline-flex rounded-lg border overflow-hidden self-start`} style={{ borderColor: 'rgba(139, 92, 246, 0.24)' }}>
                                    <Segment type={'button'} $active={sortBy === 'count'} onClick={() => setSortBy('count')}>count</Segment>
                                    <Segment type={'button'} $active={sortBy === 'denied_ratio'} onClick={() => setSortBy('denied_ratio')}>denied ratio</Segment>
                                </div>
                            </div>
                            <div css={tw`overflow-auto`}>
                                <table css={tw`w-full text-sm`}>
                                    <thead>
                                        <tr css={tw`text-left text-[11px] uppercase tracking-wider text-neutral-500`}>
                                            <th css={tw`py-2 pr-3`}>Path</th>
                                            <th css={tw`py-2 px-3`}>Requests</th>
                                            <th css={tw`py-2 px-3`}>Denied</th>
                                            <th css={tw`py-2 pl-3`}>Threat</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {sortedPaths.map((row: DanexCTargetPath, index) => (
                                            <tr key={row.path} css={tw`border-t transition-colors duration-150`} style={{ borderColor: 'rgba(139, 92, 246, 0.14)', animation: `danex-fade-up 280ms var(--el7-ease) ${index * 35}ms both` }}>
                                                <td css={tw`py-2 pr-3 font-mono text-[12px] text-neutral-200 break-all`}>{row.path || '/'}</td>
                                                <td css={tw`py-2 px-3 text-purple-200`}>{Number(row.count || 0).toLocaleString()}</td>
                                                <td css={tw`py-2 px-3 text-red-300`}>{Number(row.denied || 0).toLocaleString()}</td>
                                                <td css={tw`py-2 pl-3 min-w-[140px]`}>
                                                    <BarTrack>
                                                        <div
                                                            css={tw`h-full transition-all duration-500`}
                                                            style={{
                                                                width: `${Math.min(100, row.denied_ratio)}%`,
                                                                background: row.denied_ratio >= 70 ? '#ef4444' : row.denied_ratio >= 40 ? '#f59e0b' : '#10b981',
                                                            }}
                                                        />
                                                    </BarTrack>
                                                </td>
                                            </tr>
                                        ))}
                                        {sortedPaths.length === 0 && (
                                            <tr>
                                                <td colSpan={4} css={tw`py-5 text-center text-neutral-400`}>
                                                    {loading ? 'Loading telemetry...' : 'No path data available.'}
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </Surface>

                    <Surface $delay={660}>
                        <div css={tw`relative z-10 space-y-3`}>
                            <div css={tw`flex items-center justify-between`}>
                                <Label>System Config</Label>
                                <FontAwesomeIcon icon={faSlidersH} css={tw`text-purple-300`} />
                            </div>
                            {[
                                ['Rate limit', overview.system_config.rate_limit],
                                ['Active rules', String(overview.system_config.active_rules)],
                                ['Sensitivity', overview.system_config.detection_sensitivity],
                                ['Auto-ban', overview.system_config.auto_ban_duration],
                                ['Whitelist', String(overview.system_config.whitelist_count)],
                                ['Blacklist', String(overview.system_config.blacklist_count)],
                                ['Mode', overview.system_config.protection_mode],
                                ['Uptime', overview.system_config.uptime],
                            ].map(([k, v]) => (
                                <div key={String(k)} css={tw`flex items-center justify-between gap-3 rounded border px-3 py-2 text-xs`} style={{ background: '#111117', borderColor: 'rgba(139, 92, 246, 0.16)' }}>
                                    <span css={tw`text-neutral-500 uppercase tracking-wider`}>{k}</span>
                                    <span css={tw`text-neutral-100 font-mono text-right`}>{v}</span>
                                </div>
                            ))}
                        </div>
                    </Surface>
                </div>

                <Surface $delay={720}>
                    <div css={tw`relative z-10`}>
                        <div css={tw`flex items-center justify-between`}>
                            <Label>Live Request Feed</Label>
                            <div css={tw`flex items-center gap-2 text-[11px] uppercase tracking-wider text-neutral-500`}>
                                <FontAwesomeIcon icon={faClock} />
                                {lastUpdated ? lastUpdated.toLocaleTimeString() : 'waiting'}
                            </div>
                        </div>
                        <div css={tw`mt-3 max-h-[230px] overflow-auto space-y-1`}>
                            {feed.slice(0, 40).map((item, idx) => (
                                <div key={`${item.timestamp}-${idx}`} css={tw`rounded border px-3 py-2 text-xs font-mono flex items-start gap-2`} style={{ background: '#111117', borderColor: 'rgba(139, 92, 246, 0.12)' }}>
                                    <span css={tw`text-neutral-500 flex-shrink-0`}>{new Date(item.timestamp).toLocaleTimeString()}</span>
                                    <span
                                        css={tw`flex-shrink-0`}
                                        style={{ color: item.severity === 'danger' ? '#ef4444' : item.severity === 'warning' ? '#f59e0b' : '#8b5cf6' }}
                                    >
                                        [{item.severity.toUpperCase()}]
                                    </span>
                                    <span css={tw`text-neutral-200 break-all`}>{item.message}</span>
                                </div>
                            ))}
                            {feed.length === 0 && (
                                <p css={tw`rounded border px-3 py-3 text-sm text-neutral-400`} style={{ background: '#111117', borderColor: 'rgba(139, 92, 246, 0.12)' }}>
                                    No live events available.
                                </p>
                            )}
                        </div>
                    </div>
                </Surface>
            </div>
        </PageContentBlock>
    );
};
