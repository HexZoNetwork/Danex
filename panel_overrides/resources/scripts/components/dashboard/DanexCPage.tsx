import React, { useCallback, useEffect, useMemo, useState } from 'react';
import tw from 'twin.macro';
import styled from 'styled-components/macro';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faBolt,
    faChartLine,
    faCheck,
    faExclamationTriangle,
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

    const loadOverview = async () => {
        const data = await getDanexCOverview(60);
        setOverview(data);
    };

    const loadTimeline = async () => {
        const data = await getDanexCTimeline(60);
        setTimeline(Array.isArray(data.timeline) ? data.timeline : []);
    };

    const loadFeed = async () => {
        const data = await getDanexCFeed(40);
        setFeed(Array.isArray(data.live_feed) ? data.live_feed : []);
    };

    const refreshAll = useCallback(async () => {
        await Promise.all([loadOverview(), loadTimeline(), loadFeed()]);
    }, []);

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
            void loadOverview();
            void loadFeed();
        }, 5000);
        const slow = window.setInterval(() => {
            if (isConnected) return;
            void loadTimeline();
        }, 10000);

        return () => {
            mounted = false;
            window.clearInterval(fast);
            window.clearInterval(slow);
        };
    }, [isConnected, refreshAll]);

    useEffect(() => {
        if (!toast) return;
        const timer = window.setTimeout(() => setToast(''), 2200);
        return () => window.clearTimeout(timer);
    }, [toast]);

    const sortedPaths = useMemo(() => {
        const rows = [...(overview.most_targeted_paths || [])];
        rows.sort((a, b) => (sortBy === 'denied_ratio' ? b.denied_ratio - a.denied_ratio : b.count - a.count));
        return rows.slice(0, 15);
    }, [overview.most_targeted_paths, sortBy]);

    const chartData = useMemo(() => {
        const source = timeline.length ? timeline : overview.timeline;
        return {
            labels: source.map((p) => new Date(p.timestamp).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })),
            datasets: [
                {
                    label: 'Allowed',
                    data: source.map((p) => p.allowed),
                    borderColor: '#8b5cf6',
                    backgroundColor: 'rgba(139, 92, 246, 0.1)',
                    pointBackgroundColor: '#a78bfa',
                    pointBorderColor: '#0b0b10',
                    fill: true,
                    tension: 0.34,
                },
                {
                    label: 'Denied',
                    data: source.map((p) => p.denied),
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
        ['Total Requests', overview.metrics.total_requests, '#a78bfa', faChartLine, 'Live sampled traffic'],
        ['Denied Requests', overview.metrics.denied_requests, '#ef4444', faTimes, `${overview.metrics.denied_percentage}% of total`],
        ['Allowed Requests', overview.metrics.allowed_requests, '#10b981', faCheck, `${overview.metrics.allowed_percentage}% of total`],
        ['Bypassed Requests', overview.metrics.bypassed_requests, '#f59e0b', faExclamationTriangle, `${overview.metrics.bypassed_percentage}% of total`],
    ] as const;

    return (
        <PageContentBlock title={'WAF Dashboard'} showFlashKey={'dashboard'}>
            <div css={tw`mx-auto w-full max-w-6xl space-y-4`}>
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

                <div css={tw`flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between`}>
                    <div>
                        <Label>DANEX X EL7 SECURITY MONITOR</Label>
                        <h1 css={tw`mt-1 text-2xl sm:text-3xl font-semibold text-neutral-50`}>WAF Dashboard</h1>
                    </div>
                    <div css={tw`inline-flex items-center self-start rounded-lg border px-3 py-2 text-xs`} style={{ background: '#0b0b10', borderColor: 'rgba(139, 92, 246, 0.28)' }}>
                        <span css={tw`mr-2 h-2 w-2 rounded-full`} style={{ background: isConnected ? '#10b981' : '#f59e0b', boxShadow: `0 0 14px ${isConnected ? '#10b981' : '#f59e0b'}` }} />
                        <span css={tw`uppercase tracking-wider text-neutral-300`}>{isConnected ? 'websocket live' : 'polling fallback'}</span>
                    </div>
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
                                <p css={tw`text-[11px] uppercase tracking-wider text-neutral-500`}>60m rolling</p>
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
                            <p css={tw`mt-2 text-xs text-neutral-400`}>Score: {overview.threat.score}/100</p>
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
                                                <td css={tw`py-2 pr-3 font-mono text-[12px] text-neutral-200 break-all`}>{row.path}</td>
                                                <td css={tw`py-2 px-3 text-purple-200`}>{row.count.toLocaleString()}</td>
                                                <td css={tw`py-2 px-3 text-red-300`}>{row.denied.toLocaleString()}</td>
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
                            <FontAwesomeIcon icon={faBolt} css={tw`text-purple-300`} />
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
