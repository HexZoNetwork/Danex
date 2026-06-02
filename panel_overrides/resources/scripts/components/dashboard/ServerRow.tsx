import React, { memo, useEffect, useRef, useState } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faEthernet, faHdd, faMemory, faMicrochip, faServer } from '@fortawesome/free-solid-svg-icons';
import { Link } from 'react-router-dom';
import { Server } from '@/api/server/getServer';
import getServerResourceUsage, { ServerPowerState, ServerStats } from '@/api/server/getServerResourceUsage';
import { bytesToString, ip, mbToBytes } from '@/lib/formatters';
import tw from 'twin.macro';
import Spinner from '@/components/elements/Spinner';
import styled from 'styled-components/macro';
import isEqual from 'react-fast-compare';

const isAlarmState = (current: number, limit: number): boolean => limit > 0 && current / (limit * 1024 * 1024) >= 0.9;

const statusColor = (status: ServerPowerState | undefined, suspended: boolean) => {
    if (suspended) return '#ef4444';
    if (!status || status === 'offline') return '#74748a';
    if (status === 'running') return '#10b981';
    return '#f59e0b';
};

const StatusIndicatorBox = styled(Link)<{ $status: ServerPowerState | undefined; $suspended: boolean; $compact?: boolean }>`
    ${tw`relative rounded-lg no-underline overflow-hidden`};
    display: ${({ $compact }) => ($compact ? 'grid' : 'flex')};
    grid-template-columns: ${({ $compact }) => ($compact ? 'repeat(12, minmax(0, 1fr))' : 'none')};
    flex-direction: ${({ $compact }) => ($compact ? 'row' : 'column')};
    min-height: auto;
    height: ${({ $compact }) => ($compact ? 'auto' : '100%')};
    gap: ${({ $compact }) => ($compact ? '0.75rem' : '0.65rem')};
    padding: ${({ $compact }) => ($compact ? '0.85rem' : '0.85rem')};
    background: #0b0b10;
    border: 1px solid rgba(139, 92, 246, 0.24);
    box-shadow: 0 16px 34px rgba(0, 0, 0, 0.42), inset 0 1px 0 rgba(255, 255, 255, 0.04);
    transition: transform 240ms var(--el7-ease), border-color 240ms var(--el7-ease), box-shadow 240ms var(--el7-ease), background 240ms var(--el7-ease);

    &::before {
        content: '';
        position: absolute;
        inset: 0;
        pointer-events: none;
        border-top: 1px solid rgba(255, 255, 255, 0.05);
        background:
            linear-gradient(90deg, transparent, rgba(139, 92, 246, 0.05), transparent),
            repeating-linear-gradient(90deg, rgba(255,255,255,0.018) 0 1px, transparent 1px 44px);
        opacity: 0.7;
        transform: translateX(-20%);
        transition: transform 700ms var(--el7-ease), opacity 240ms var(--el7-ease);
    }

    & .status-bar {
        position: absolute;
        left: ${({ $compact }) => ($compact ? '0' : '1rem')};
        right: ${({ $compact }) => ($compact ? 'auto' : '1rem')};
        top: ${({ $compact }) => ($compact ? '0.5rem' : '0')};
        bottom: ${({ $compact }) => ($compact ? '0.5rem' : 'auto')};
        width: ${({ $compact }) => ($compact ? '3px' : 'auto')};
        height: ${({ $compact }) => ($compact ? 'auto' : '3px')};
        border-radius: 999px;
        background: ${({ $status, $suspended }) => statusColor($status, $suspended)};
        box-shadow: 0 0 18px ${({ $status, $suspended }) => statusColor($status, $suspended)};
    }

    & .server-card-open {
        opacity: ${({ $compact }) => ($compact ? 1 : 0)};
        transform: ${({ $compact }) => ($compact ? 'none' : 'translateX(-4px)')};
        transition: opacity 220ms var(--el7-ease), transform 220ms var(--el7-ease);
    }

    &:hover {
        transform: translateY(${({ $compact }) => ($compact ? '-3px' : '-4px')});
        background: #111117;
        border-color: rgba(139, 92, 246, 0.7);
        box-shadow: 0 24px 58px rgba(0, 0, 0, 0.54), 0 0 32px rgba(139, 92, 246, 0.24);
    }

    &:hover::before {
        opacity: 1;
        transform: translateX(10%);
    }

    &:hover .server-card-open {
        opacity: 1;
        transform: translateY(0);
    }

    @media (max-width: 640px) {
        display: flex;
        flex-direction: column;
        gap: ${({ $compact }) => ($compact ? '0.65rem' : '0.7rem')};
        padding: ${({ $compact }) => ($compact ? '0.72rem' : '0.78rem')};
        border-radius: 0.85rem;
        border-color: rgba(139, 92, 246, 0.32);
        box-shadow: 0 14px 32px rgba(0, 0, 0, 0.42), inset 0 1px 0 rgba(255, 255, 255, 0.05);

        & .status-bar {
            left: 0.7rem;
            right: 0.7rem;
            top: 0;
            bottom: auto;
            width: auto;
            height: 3px;
        }

        &:hover {
            transform: none;
        }
    }
`;

const IconBox = styled.div`
    ${tw`rounded-lg w-11 h-11 flex items-center justify-center p-3 flex-shrink-0`};
    background: #111117;
    border: 1px solid rgba(139, 92, 246, 0.36);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.06), 0 0 20px rgba(139, 92, 246, 0.16);
    color: #a78bfa;

    @media (max-width: 640px) {
        width: 2.25rem;
        height: 2.25rem;
        padding: 0.55rem;
        border-radius: 0.65rem;
    }
`;

const ResourceCell = styled.div<{ $alarm?: boolean; $compact?: boolean }>`
    ${tw`rounded-lg border px-2 py-2 min-w-0`};
    background: #111117;
    border-color: ${({ $alarm }) => ($alarm ? 'rgba(239, 68, 68, 0.42)' : 'rgba(139, 92, 246, 0.18)')};

    svg {
        color: ${({ $alarm }) => ($alarm ? '#ef4444' : '#a78bfa')};
    }

    @media (max-width: 640px) {
        padding: ${({ $compact }) => ($compact ? '0.5rem 0.55rem' : '0.55rem 0.6rem')};
        border-radius: 0.7rem;
        background: #111117;
        border-color: ${({ $alarm }) => ($alarm ? 'rgba(239, 68, 68, 0.48)' : 'rgba(139, 92, 246, 0.26)')};
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.045);
    }
`;

const Meter = styled.div<{ $value: number; $alarm?: boolean }>`
    ${tw`mt-2 h-1.5 rounded-full overflow-hidden`};
    background: #09090d;
    border: 1px solid rgba(139, 92, 246, 0.12);

    &::after {
        content: '';
        display: block;
        height: 100%;
        width: ${({ $value }) => `${Math.max(0, Math.min(100, $value))}%`};
        background: ${({ $alarm }) => ($alarm ? '#ef4444' : '#8b5cf6')};
        box-shadow: 0 0 14px ${({ $alarm }) => ($alarm ? 'rgba(239, 68, 68, 0.5)' : 'rgba(139, 92, 246, 0.5)')};
        transition: width 400ms var(--el7-ease);
    }
`;

type Timer = ReturnType<typeof setInterval>;

const pct = (current: number, limitMb: number) => {
    if (limitMb <= 0) return 0;
    return (current / mbToBytes(limitMb)) * 100;
};

const ResourceMetric = ({
    icon,
    label,
    value,
    limit,
    usage,
    alarm,
    compact,
}: {
    icon: any;
    label: string;
    value: string;
    limit: string;
    usage: number;
    alarm: boolean;
    compact?: boolean;
}) => {
    const boundedUsage = Math.max(0, Math.min(100, usage));

    return (
        <ResourceCell $alarm={alarm} $compact={compact}>
            <div css={compact ? tw`flex flex-col items-start gap-0.5 sm:flex-row sm:items-center sm:justify-between sm:gap-2` : tw`flex items-center justify-between gap-2`}>
                <div css={tw`flex items-center min-w-0`}>
                    <FontAwesomeIcon icon={icon} css={compact ? tw`mr-1.5 flex-shrink-0 text-[10px]` : tw`mr-2 flex-shrink-0`} />
                    <span css={compact ? tw`text-[10px] uppercase tracking-wider text-neutral-500` : tw`text-[11px] sm:text-xs uppercase tracking-wider text-neutral-500`}>{label}</span>
                </div>
                <span css={compact ? tw`max-w-full truncate text-left text-[10px] font-mono text-neutral-100 sm:max-w-[52%] sm:text-right` : tw`max-w-[52%] truncate text-right text-[11px] sm:text-xs font-mono text-neutral-100`}>{value}</span>
            </div>
            <Meter
                $value={boundedUsage}
                $alarm={alarm}
                role={'meter'}
                aria-label={`${label} usage`}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={Math.round(boundedUsage)}
                aria-valuetext={`${value} of ${limit}`}
            />
            {!compact && <p css={tw`mt-1 truncate text-right text-[10px] text-neutral-500`}>of {limit} ({Math.round(boundedUsage)}%)</p>}
        </ResourceCell>
    );
};

const ServerRow = ({ server, className, eager = false, compact = false }: { server: Server; className?: string; eager?: boolean; compact?: boolean }) => {
    const interval = useRef<Timer | null>(null);
    const rowRef = useRef<HTMLDivElement | null>(null);
    const [isSuspended, setIsSuspended] = useState(server.status === 'suspended');
    const [isVisible, setIsVisible] = useState(eager);
    const [stats, setStats] = useState<ServerStats | null>(null);
    const [resourceError, setResourceError] = useState(false);

    const getStats = () =>
        getServerResourceUsage(server.uuid)
            .then((data) => {
                setResourceError(false);
                setStats(data);
            })
            .catch((error) => {
                if (error?.response?.status === 409) return;
                setResourceError(true);
                console.error(error);
            });

    useEffect(() => {
        setIsSuspended(stats?.isSuspended || server.status === 'suspended');
    }, [stats?.isSuspended, server.status]);

    useEffect(() => {
        if (eager) {
            setIsVisible(true);
            return;
        }

        if (!rowRef.current || !('IntersectionObserver' in window)) {
            setIsVisible(true);
            return;
        }

        const observer = new IntersectionObserver(
            (entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    setIsVisible(true);
                    observer.disconnect();
                }
            },
            { rootMargin: '180px 0px' }
        );
        observer.observe(rowRef.current);

        return () => observer.disconnect();
    }, [eager]);

    useEffect(() => {
        if (isSuspended || !isVisible) return;

        let cancelled = false;
        const startPolling = () => {
            if (cancelled) return;
            getStats().then(() => {
                if (!cancelled) interval.current = setInterval(() => getStats(), 45000);
            });
        };

        if ((window as any).requestIdleCallback) {
            (window as any).requestIdleCallback(startPolling, { timeout: 1200 });
        } else {
            window.setTimeout(startPolling, 200);
        }

        return () => {
            cancelled = true;
            interval.current && clearInterval(interval.current);
        };
    }, [isSuspended, isVisible]);

    const alarms = { cpu: false, memory: false, disk: false };
    if (stats) {
        alarms.cpu = server.limits.cpu === 0 ? false : stats.cpuUsagePercent >= server.limits.cpu * 0.9;
        alarms.memory = isAlarmState(stats.memoryUsageInBytes, server.limits.memory);
        alarms.disk = server.limits.disk === 0 ? false : isAlarmState(stats.diskUsageInBytes, server.limits.disk);
    }

    const diskLimit = server.limits.disk !== 0 ? bytesToString(mbToBytes(server.limits.disk)) : 'Unlimited';
    const memoryLimit = server.limits.memory !== 0 ? bytesToString(mbToBytes(server.limits.memory)) : 'Unlimited';
    const cpuLimit = server.limits.cpu !== 0 ? server.limits.cpu + ' %' : 'Unlimited';
    const status = isSuspended
        ? 'suspended'
        : stats?.status || server.status || (resourceError ? 'poll delayed' : 'checking');
    const statusForColor = stats?.status || (server.status === 'suspended' ? 'offline' : undefined);
    const allocation = server.allocations
        .filter((alloc) => alloc.isDefault)
        .map((allocation) => `${allocation.alias || ip(allocation.ip)}:${allocation.port}`)
        .join(', ');
    const identityCss = compact
        ? tw`relative z-10 flex items-center col-span-12 md:col-span-6 lg:col-span-5 min-w-0`
        : tw`relative z-10 flex items-start min-w-0 pb-3 border-b`;
    const networkCss = compact ? tw`relative z-10 hidden lg:flex lg:col-span-2 items-center min-w-0` : tw`hidden`;
    const resourcesCss = compact
        ? tw`relative z-10 block md:col-span-6 lg:col-span-5 xl:col-span-5`
        : tw`relative z-10 flex-1`;
    const resourceGridCss = compact ? tw`grid grid-cols-3 gap-1.5 sm:gap-2` : tw`grid grid-cols-1 sm:grid-cols-3 gap-2`;
    const descriptionCss = compact ? tw`hidden md:block mt-1 text-xs text-neutral-400 break-words` : tw`mt-1 text-xs text-neutral-400 break-words`;
    const descriptionStyle = {
        display: '-webkit-box',
        WebkitLineClamp: 1,
        WebkitBoxOrient: 'vertical' as const,
        overflow: 'hidden',
    };

    return (
        <div className={className} ref={rowRef}>
            <StatusIndicatorBox to={`/server/${server.id}`} $status={statusForColor} $suspended={isSuspended} $compact={compact}>
                <div className={'status-bar'} />
                <div css={identityCss} style={compact ? undefined : { borderColor: 'rgba(139, 92, 246, 0.18)' }}>
                    <IconBox>
                        <FontAwesomeIcon icon={faServer} />
                    </IconBox>
                    <div css={tw`ml-2.5 sm:ml-3 min-w-0 flex-1`}>
                        <div css={tw`flex flex-wrap items-start justify-between gap-2`}>
                            <p css={tw`min-w-0 flex-1 text-sm sm:text-base leading-snug break-words text-white font-semibold`}>{server.name}</p>
                            <span css={tw`rounded border px-2 py-0.5 text-[10px] uppercase tracking-wider flex-shrink-0 leading-4`} style={{ color: resourceError && !stats ? '#f59e0b' : statusColor(statusForColor, isSuspended), borderColor: 'rgba(139, 92, 246, 0.2)', background: '#111117' }}>
                                {status}
                            </span>
                        </div>
                        <p css={descriptionCss} style={descriptionStyle}>
                            {server.description || 'No description configured.'}
                        </p>
                        <div css={compact ? tw`mt-1 flex items-center text-xs text-neutral-500 lg:hidden` : tw`mt-2 flex items-center text-xs text-neutral-500`}>
                            <FontAwesomeIcon icon={faEthernet} css={tw`mr-2 text-purple-300`} />
                            <span css={tw`font-mono truncate`}>{allocation || 'no allocation'}</span>
                        </div>
                        {!compact && (
                            <div className={'server-card-open'} css={tw`mt-2 h-0.5 w-12 rounded-full`} style={{ background: 'rgba(139, 92, 246, 0.75)', boxShadow: '0 0 14px rgba(139, 92, 246, 0.45)' }} />
                        )}
                    </div>
                </div>

                <div css={networkCss}>
                    <div css={tw`rounded-lg border px-3 py-2 w-full`} style={{ background: '#111117', borderColor: 'rgba(139, 92, 246, 0.18)' }}>
                        <div css={tw`flex items-center text-xs text-neutral-500 uppercase tracking-wider`}>
                            <FontAwesomeIcon icon={faEthernet} css={tw`mr-2 text-purple-300`} />
                            Network
                        </div>
                        <p css={tw`mt-1 text-xs font-mono text-neutral-200 truncate`}>{allocation || 'no allocation'}</p>
                    </div>
                </div>

                <div css={resourcesCss}>
                    {!stats || isSuspended ? (
                        isSuspended ? (
                            <div css={tw`rounded-lg border px-3 py-4 text-center text-sm text-red-200`} style={{ background: '#111117', borderColor: 'rgba(239, 68, 68, 0.38)' }}>
                                {server.status === 'suspended' ? 'Suspended' : 'Connection Error'}
                            </div>
                        ) : resourceError ? (
                            <div css={tw`rounded-lg border px-3 py-4 text-center text-sm text-yellow-200`} style={{ background: '#111117', borderColor: 'rgba(245, 158, 11, 0.34)' }}>
                                Poll delayed
                            </div>
                        ) : server.isTransferring || server.status ? (
                            <div css={tw`rounded-lg border px-3 py-4 text-center text-sm text-neutral-300`} style={{ background: '#111117', borderColor: 'rgba(139, 92, 246, 0.18)' }}>
                                {server.isTransferring
                                    ? 'Transferring'
                                    : server.status === 'installing'
                                    ? 'Installing'
                                    : server.status === 'restoring_backup'
                                    ? 'Restoring Backup'
                                    : 'Unavailable'}
                            </div>
                        ) : (
                            <div css={tw`flex justify-center py-4`}>
                                <Spinner size={'small'} />
                            </div>
                        )
                    ) : (
                        <div css={resourceGridCss}>
                            <ResourceMetric
                                icon={faMicrochip}
                                label={'CPU'}
                                value={`${stats.cpuUsagePercent.toFixed(2)}%`}
                                limit={cpuLimit}
                                usage={server.limits.cpu === 0 ? Math.min(100, stats.cpuUsagePercent) : (stats.cpuUsagePercent / server.limits.cpu) * 100}
                                alarm={alarms.cpu}
                                compact={compact}
                            />
                            <ResourceMetric
                                icon={faMemory}
                                label={'RAM'}
                                value={bytesToString(stats.memoryUsageInBytes)}
                                limit={memoryLimit}
                                usage={pct(stats.memoryUsageInBytes, server.limits.memory)}
                                alarm={alarms.memory}
                                compact={compact}
                            />
                            <ResourceMetric
                                icon={faHdd}
                                label={'Disk'}
                                value={bytesToString(stats.diskUsageInBytes)}
                                limit={diskLimit}
                                usage={pct(stats.diskUsageInBytes, server.limits.disk)}
                                alarm={alarms.disk}
                                compact={compact}
                            />
                        </div>
                    )}
                </div>
            </StatusIndicatorBox>
        </div>
    );
};

export default memo(ServerRow, isEqual);
