import React, { useEffect, useMemo, useState } from 'react';
import tw from 'twin.macro';
import styled from 'styled-components/macro';
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

ChartJS.register(LineElement, LinearScale, PointElement, Tooltip, Legend, CategoryScale, Filler);

const Surface = styled.div`
    ${tw`rounded-lg border p-4`};
    border-color: #4c1d95;
    background: linear-gradient(180deg, rgba(17, 11, 33, 0.96) 0%, rgba(10, 10, 20, 0.96) 100%);
    box-shadow: 0 0 0 1px rgba(139, 92, 246, 0.18), 0 10px 24px rgba(10, 6, 24, 0.5);
    position: relative;
    overflow: hidden;
`;

const GridOverlay = styled.div`
    pointer-events: none;
    position: absolute;
    inset: 0;
    background-image:
        linear-gradient(rgba(139, 92, 246, 0.05) 1px, transparent 1px),
        linear-gradient(90deg, rgba(96, 165, 250, 0.04) 1px, transparent 1px);
    background-size: 24px 24px;
`;

const Scanline = styled.div`
    pointer-events: none;
    position: absolute;
    inset: 0;
    background: repeating-linear-gradient(
        to bottom,
        rgba(255, 255, 255, 0.02),
        rgba(255, 255, 255, 0.02) 1px,
        transparent 2px,
        transparent 4px
    );
    opacity: 0.25;
`;

const StatValue = tw.p`font-bold text-2xl text-neutral-100`;
const Label = tw.p`text-[11px] uppercase tracking-wider text-purple-200`;

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

export default () => {
    const [overview, setOverview] = useState<DanexCOverviewResponse>(emptyState);
    const [feed, setFeed] = useState<DanexCFeedItem[]>([]);
    const [timeline, setTimeline] = useState<DanexCTimelinePoint[]>([]);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(true);
    const [sortBy, setSortBy] = useState<'count' | 'denied_ratio'>('count');

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

    useEffect(() => {
        let mounted = true;
        (async () => {
            try {
                await Promise.all([loadOverview(), loadTimeline(), loadFeed()]);
                if (mounted) setError('');
            } catch (e: any) {
                if (mounted) setError(String(e?.response?.data?.error || 'Failed to load DanexC telemetry.'));
            } finally {
                if (mounted) setLoading(false);
            }
        })();

        const fast = window.setInterval(() => {
            void loadOverview();
            void loadFeed();
        }, 5000);
        const slow = window.setInterval(() => {
            void loadTimeline();
        }, 10000);

        return () => {
            mounted = false;
            window.clearInterval(fast);
            window.clearInterval(slow);
        };
    }, []);

    const sortedPaths = useMemo(() => {
        const rows = [...(overview.most_targeted_paths || [])];
        rows.sort((a, b) => {
            if (sortBy === 'denied_ratio') return b.denied_ratio - a.denied_ratio;
            return b.count - a.count;
        });
        return rows.slice(0, 15);
    }, [overview.most_targeted_paths, sortBy]);

    const chartData = useMemo(() => {
        const source = timeline.length ? timeline : overview.timeline;
        return {
            labels: source.map((p) => new Date(p.timestamp).toLocaleTimeString()),
            datasets: [
                {
                    label: 'Allowed',
                    data: source.map((p) => p.allowed),
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16,185,129,0.12)',
                    fill: true,
                    tension: 0.25,
                },
                {
                    label: 'Denied',
                    data: source.map((p) => p.denied),
                    borderColor: '#ef4444',
                    backgroundColor: 'rgba(239,68,68,0.16)',
                    fill: true,
                    tension: 0.25,
                },
            ],
        };
    }, [overview.timeline, timeline]);

    const chartOptions = useMemo(
        () => ({
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    labels: { color: '#e5e7eb' },
                },
            },
            scales: {
                x: {
                    ticks: { color: '#c4b5fd', maxRotation: 0, autoSkip: true },
                    grid: { color: 'rgba(167,139,250,0.14)' },
                },
                y: {
                    ticks: { color: '#bfdbfe' },
                    grid: { color: 'rgba(96,165,250,0.14)' },
                },
            },
        }),
        []
    );

    return (
        <PageContentBlock title={'DanexC Analytics'} showFlashKey={'dashboard'}>
            <div css={tw`mx-auto w-full max-w-6xl space-y-4`}>
                {error && <div css={tw`rounded-lg border border-red-700 bg-red-900/20 px-3 py-2 text-sm text-red-200`}>{error}</div>}

                <div css={tw`grid gap-3 grid-cols-1 sm:grid-cols-2 xl:grid-cols-4`}>
                    {[
                        ['Total Requests', overview.metrics.total_requests, '#a78bfa'],
                        ['Denied Requests', overview.metrics.denied_requests, '#ef4444'],
                        ['Allowed Requests', overview.metrics.allowed_requests, '#10b981'],
                        ['Bypassed Requests', overview.metrics.bypassed_requests, '#f59e0b'],
                    ].map(([title, value, color]) => (
                        <Surface key={String(title)}>
                            <GridOverlay />
                            <Scanline />
                            <div css={tw`relative z-10`}>
                                <Label>{String(title)}</Label>
                                <StatValue style={{ color: String(color) }}>{Number(value).toLocaleString()}</StatValue>
                                <p css={tw`text-[12px] text-neutral-300`}>
                                    {title === 'Denied Requests'
                                        ? `${overview.metrics.denied_percentage}% of total`
                                        : title === 'Allowed Requests'
                                          ? `${overview.metrics.allowed_percentage}% of total`
                                          : title === 'Bypassed Requests'
                                            ? `${overview.metrics.bypassed_percentage}% of total`
                                            : 'Live sampled traffic'}
                                </p>
                            </div>
                        </Surface>
                    ))}
                </div>

                <div css={tw`grid gap-3 grid-cols-1 xl:grid-cols-3`}>
                    <Surface css={tw`xl:col-span-2 h-[320px]`}>
                        <GridOverlay />
                        <Scanline />
                        <div css={tw`relative z-10 h-full`}>
                            <div css={tw`flex items-center justify-between mb-2`}>
                                <Label>Traffic Timeline</Label>
                                <p css={tw`text-[11px] text-blue-200`}>60m rolling</p>
                            </div>
                            <div css={tw`h-[260px]`}>
                                <Line data={chartData} options={chartOptions} />
                            </div>
                        </div>
                    </Surface>

                    <Surface>
                        <GridOverlay />
                        <Scanline />
                        <div css={tw`relative z-10`}>
                            <Label>Threat Level</Label>
                            <p css={tw`text-2xl font-bold uppercase`} style={{ color: threatColor(overview.threat.level) }}>
                                {overview.threat.level}
                            </p>
                            <div css={tw`mt-2 h-3 rounded bg-neutral-900 border border-purple-900 overflow-hidden`}>
                                <div
                                    css={tw`h-full transition-all duration-300`}
                                    style={{ width: `${overview.threat.score}%`, background: threatColor(overview.threat.level) }}
                                />
                            </div>
                            <p css={tw`mt-2 text-xs text-neutral-300`}>Score: {overview.threat.score}/100</p>
                            <div css={tw`mt-3 space-y-1`}>
                                {(overview.threat.reason_codes || []).slice(0, 6).map((r) => (
                                    <div key={r} css={tw`text-[11px] text-purple-200 font-mono`}>
                                        {r}
                                    </div>
                                ))}
                            </div>
                        </div>
                    </Surface>
                </div>

                <div css={tw`grid gap-3 grid-cols-1 xl:grid-cols-3`}>
                    <Surface css={tw`xl:col-span-2`}>
                        <GridOverlay />
                        <Scanline />
                        <div css={tw`relative z-10`}>
                            <div css={tw`flex items-center justify-between mb-2`}>
                                <Label>Most Targeted Paths</Label>
                                <div css={tw`inline-flex rounded border border-purple-800 overflow-hidden`}>
                                    <button
                                        type={'button'}
                                        css={tw`px-2 py-1 text-xs font-mono`}
                                        style={{ background: sortBy === 'count' ? '#4c1d95' : '#1f1633', color: '#e9d5ff' }}
                                        onClick={() => setSortBy('count')}
                                    >
                                        count
                                    </button>
                                    <button
                                        type={'button'}
                                        css={tw`px-2 py-1 text-xs font-mono`}
                                        style={{ background: sortBy === 'denied_ratio' ? '#4c1d95' : '#1f1633', color: '#e9d5ff' }}
                                        onClick={() => setSortBy('denied_ratio')}
                                    >
                                        denied ratio
                                    </button>
                                </div>
                            </div>
                            <div css={tw`overflow-auto`}>
                                <table css={tw`w-full text-sm`}>
                                    <thead>
                                        <tr css={tw`text-left text-purple-200 text-[11px] uppercase`}>
                                            <th css={tw`py-1`}>Path</th>
                                            <th css={tw`py-1`}>Requests</th>
                                            <th css={tw`py-1`}>Denied</th>
                                            <th css={tw`py-1`}>Threat</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {sortedPaths.map((row: DanexCTargetPath) => (
                                            <tr key={row.path} css={tw`border-t border-purple-900/50`}>
                                                <td css={tw`py-1 font-mono text-[12px] text-neutral-200`}>{row.path}</td>
                                                <td css={tw`py-1 text-blue-200`}>{row.count.toLocaleString()}</td>
                                                <td css={tw`py-1 text-red-300`}>{row.denied.toLocaleString()}</td>
                                                <td css={tw`py-1`}>
                                                    <div css={tw`h-2 rounded bg-neutral-900 border border-purple-900 overflow-hidden`}>
                                                        <div
                                                            css={tw`h-full`}
                                                            style={{
                                                                width: `${Math.min(100, row.denied_ratio)}%`,
                                                                background:
                                                                    row.denied_ratio >= 70
                                                                        ? '#ef4444'
                                                                        : row.denied_ratio >= 40
                                                                          ? '#f59e0b'
                                                                          : '#10b981',
                                                            }}
                                                        />
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                        {sortedPaths.length === 0 && (
                                            <tr>
                                                <td colSpan={4} css={tw`py-3 text-center text-neutral-400`}>
                                                    {loading ? 'Loading telemetry...' : 'No path data available.'}
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </Surface>

                    <Surface>
                        <GridOverlay />
                        <Scanline />
                        <div css={tw`relative z-10 space-y-2`}>
                            <Label>System Configuration</Label>
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
                                <div key={String(k)} css={tw`flex items-center justify-between gap-2 text-xs border-b border-purple-900/40 pb-1`}>
                                    <span css={tw`text-purple-200 uppercase`}>{k}</span>
                                    <span css={tw`text-neutral-100 font-mono text-right`}>{v}</span>
                                </div>
                            ))}
                        </div>
                    </Surface>
                </div>

                <Surface>
                    <GridOverlay />
                    <Scanline />
                    <div css={tw`relative z-10`}>
                        <Label>Live Request Feed</Label>
                        <div css={tw`mt-2 max-h-[220px] overflow-auto space-y-1`}>
                            {feed.slice(0, 40).map((item, idx) => (
                                <div key={`${item.timestamp}-${idx}`} css={tw`text-xs font-mono flex items-start gap-2`}>
                                    <span css={tw`text-neutral-500 shrink-0`}>{new Date(item.timestamp).toLocaleTimeString()}</span>
                                    <span
                                        css={tw`shrink-0`}
                                        style={{
                                            color:
                                                item.severity === 'danger'
                                                    ? '#ef4444'
                                                    : item.severity === 'warning'
                                                      ? '#f59e0b'
                                                      : '#22d3ee',
                                        }}
                                    >
                                        [{item.severity.toUpperCase()}]
                                    </span>
                                    <span css={tw`text-neutral-200 break-all`}>{item.message}</span>
                                </div>
                            ))}
                            {feed.length === 0 && <p css={tw`text-sm text-neutral-400`}>No live events available.</p>}
                        </div>
                    </div>
                </Surface>
            </div>
        </PageContentBlock>
    );
};
