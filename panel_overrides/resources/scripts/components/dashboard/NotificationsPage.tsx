import React, { useEffect, useMemo, useRef, useState } from 'react';
import tw from 'twin.macro';
import styled from 'styled-components/macro';
import PageContentBlock from '@/components/elements/PageContentBlock';
import { Button } from '@/components/elements/button/index';
import {
    ChatConversation,
    getChatNotifications,
    getConversations,
    muteConversationNotifications,
    readChatNotifications,
    unmuteConversationNotifications,
} from '@/api/chat/publicChat';
import { format } from 'date-fns';

const Card = styled.div`
    ${tw`rounded-lg border p-3`};
    background: #0b0b10;
    border-color: rgba(139, 92, 246, 0.22);
    box-shadow: 0 14px 34px rgba(0, 0, 0, 0.38);
    transition: border-color 180ms cubic-bezier(0.4, 0, 0.2, 1), box-shadow 180ms cubic-bezier(0.4, 0, 0.2, 1), transform 180ms cubic-bezier(0.4, 0, 0.2, 1);

    &:hover {
        transform: translateY(-1px);
        border-color: rgba(139, 92, 246, 0.44);
        box-shadow: 0 18px 42px rgba(0, 0, 0, 0.48), inset 2px 0 0 rgba(139, 92, 246, 0.56);
    }
`;
const Row = styled.div`
    ${tw`flex items-start justify-between gap-3`};

    @media (max-width: 640px) {
        ${tw`flex-col`};
    }
`;
const Avatar = styled.img`
    ${tw`w-10 h-10 rounded-full object-cover border`};
    border-color: rgba(139, 92, 246, 0.26);
`;
const AvatarFallback = styled.div`
    ${tw`w-10 h-10 rounded-full text-neutral-100 text-xs font-semibold flex items-center justify-center border`};
    background: #111117;
    border-color: rgba(139, 92, 246, 0.26);
`;
const CountBadge = styled.span`
    ${tw`inline-flex items-center justify-center rounded-full border px-2.5 py-1 text-xs font-bold text-white`};
    background: #ef4444;
    border-color: rgba(255, 255, 255, 0.18);
    box-shadow: 0 0 18px rgba(239, 68, 68, 0.38);
`;
const NewBadge = styled.span`
    ${tw`text-[10px] text-white px-2 py-0.5 rounded-full border font-bold`};
    background: #ef4444;
    border-color: rgba(255, 255, 255, 0.16);
    box-shadow: 0 0 16px rgba(239, 68, 68, 0.32);
`;
const TabButton = styled.button<{ $active?: boolean }>`
    ${tw`rounded-md border px-3 py-2 text-xs font-semibold uppercase tracking-wider transition`};
    background: ${({ $active }) => ($active ? '#8b5cf6' : '#111117')};
    color: ${({ $active }) => ($active ? '#ffffff' : '#d4d4df')};
    border-color: ${({ $active }) => ($active ? 'rgba(167, 139, 250, 0.72)' : 'rgba(139, 92, 246, 0.24)')};
    box-shadow: ${({ $active }) => ($active ? '0 0 22px rgba(139, 92, 246, 0.32)' : 'none')};

    &:hover {
        border-color: rgba(139, 92, 246, 0.62);
        background: ${({ $active }) => ($active ? '#8b5cf6' : '#15151d')};
    }
`;

const initials = (name: string): string => {
    const parts = name.trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return 'NT';
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return `${parts[0][0] || ''}${parts[1][0] || ''}`.toUpperCase();
};

export default () => {
    const [items, setItems] = useState<any[]>([]);
    const [conversations, setConversations] = useState<ChatConversation[]>([]);
    const [loading, setLoading] = useState(true);
    const [showUnreadOnly, setShowUnreadOnly] = useState(false);
    const [channelFilter, setChannelFilter] = useState<'all' | 'direct' | 'public' | 'system'>('all');
    const [browserNotifState, setBrowserNotifState] = useState<'unsupported' | 'default' | 'denied' | 'granted'>(
        typeof window === 'undefined' || !('Notification' in window) ? 'unsupported' : Notification.permission
    );
    const autoMarkedRef = useRef<Set<number>>(new Set());

    const conversationMap = useMemo(() => {
        const map: Record<number, ChatConversation> = {};
        for (const conversation of conversations) {
            map[conversation.id] = conversation;
        }
        return map;
    }, [conversations]);

    const load = async () => {
        const [notifications, conv] = await Promise.all([getChatNotifications(undefined, 120), getConversations()]);
        const sorted = notifications.items.sort((a, b) => Number(b.id) - Number(a.id));
        setItems(sorted);
        setConversations(conv);

        const unreadIds = sorted
            .filter((item) => !item.read && !autoMarkedRef.current.has(Number(item.id)))
            .map((item) => Number(item.id))
            .filter((id) => id > 0);
        if (unreadIds.length > 0) {
            unreadIds.forEach((id) => autoMarkedRef.current.add(id));
            await readChatNotifications(unreadIds);
            setItems((current) => current.map((item) => (unreadIds.includes(Number(item.id)) ? { ...item, read: true } : item)));
        }
    };

    useEffect(() => {
        let cancelled = false;
        (async () => {
            try {
                await load();
            } finally {
                if (!cancelled) setLoading(false);
            }
        })();

        const timer = window.setInterval(() => {
            load().catch(() => {
                // ignore
            });
        }, 4000);

        return () => {
            cancelled = true;
            window.clearInterval(timer);
        };
    }, []);

    const markAllRead = async () => {
        await readChatNotifications();
        await load();
    };

    const markOneRead = async (id: number) => {
        await readChatNotifications([id]);
        setItems((current) => current.map((item) => (item.id === id ? { ...item, read: true } : item)));
    };

    const enableBrowserNotifications = async () => {
        if (typeof window === 'undefined' || !('Notification' in window)) {
            setBrowserNotifState('unsupported');
            return;
        }
        try {
            const result = await Notification.requestPermission();
            setBrowserNotifState(result);
        } catch {
            setBrowserNotifState(Notification.permission);
        }
    };

    const filteredByChannel = items.filter((item) => {
        if (channelFilter === 'direct') return item.sourceType === 'dm' || item.sourceType === 'group' || item.sourceType === 'call';
        if (channelFilter === 'public') return item.sourceType === 'global';
        if (channelFilter === 'system') return item.sourceType === 'system';
        return true;
    });
    const visibleItems = showUnreadOnly ? filteredByChannel.filter((item) => !item.read) : filteredByChannel;
    const unreadCount = items.filter((item) => !item.read).length;

    return (
        <PageContentBlock title={'Notifications'} showFlashKey={'dashboard'}>
            <div
                css={tw`rounded-lg border p-3 mb-3`}
                style={{ background: '#0b0b10', borderColor: 'rgba(139, 92, 246, 0.24)', boxShadow: '0 18px 48px rgba(0, 0, 0, 0.5)' }}
            >
                <div css={tw`flex flex-col sm:flex-row sm:items-center justify-between gap-3`}>
                    <div>
                        <p css={tw`text-sm text-neutral-200 font-semibold`}>Realtime Notifications</p>
                        <p css={tw`text-xs text-neutral-400`}>System, DM, group, global, dan call.</p>
                    </div>
                    <div css={tw`text-left sm:text-right`}>
                        <p css={tw`text-sm text-neutral-200 flex sm:justify-end items-center gap-2`}>
                            Unread <CountBadge>{unreadCount}</CountBadge>
                        </p>
                        <p css={tw`text-xs text-neutral-500`}>Total: {items.length}</p>
                    </div>
                </div>
                <div css={tw`mt-3 grid grid-cols-2 sm:grid-cols-4 gap-2`}>
                    <TabButton type={'button'} $active={channelFilter === 'all'} onClick={() => setChannelFilter('all')}>
                        All
                    </TabButton>
                    <TabButton type={'button'} $active={channelFilter === 'direct'} onClick={() => setChannelFilter('direct')}>
                        DM / Group
                    </TabButton>
                    <TabButton type={'button'} $active={channelFilter === 'public'} onClick={() => setChannelFilter('public')}>
                        Public
                    </TabButton>
                    <TabButton type={'button'} $active={channelFilter === 'system'} onClick={() => setChannelFilter('system')}>
                        System
                    </TabButton>
                </div>
                <div css={tw`mt-3 grid grid-cols-1 sm:grid-cols-3 gap-2`}>
                    <Button
                        type={'button'}
                        size={'xsmall'}
                        color={showUnreadOnly ? 'primary' : 'secondary'}
                        css={tw`w-full justify-center`}
                        onClick={() => setShowUnreadOnly((s) => !s)}
                    >
                            {showUnreadOnly ? 'Showing Unread' : 'Show Unread Only'}
                    </Button>
                    <Button type={'button'} size={'xsmall'} color={'secondary'} css={tw`w-full justify-center`} onClick={enableBrowserNotifications}>
                        Browser: {browserNotifState}
                    </Button>
                    <Button type={'button'} size={'xsmall'} css={tw`w-full justify-center`} onClick={markAllRead}>
                        Mark All Read
                    </Button>
                </div>
            </div>
            {browserNotifState === 'unsupported' && (
                <p css={tw`text-xs text-yellow-400 mb-3`}>
                    Browser notifications unavailable on this origin. Use HTTPS domain (not plain HTTP IP) to get Chrome popup notifications.
                </p>
            )}
            {loading ? (
                <p css={tw`text-sm text-neutral-400`}>Loading notifications...</p>
            ) : visibleItems.length === 0 ? (
                <p css={tw`text-sm text-neutral-400`}>No notifications yet.</p>
            ) : (
                <div css={tw`space-y-2`}>
                    {visibleItems.map((item) => {
                        const conversation = item.conversationId ? conversationMap[item.conversationId] : null;
                        const avatar = item.sourceType === 'system' ? null : (conversation?.avatarUrl || item.avatarUrl || null);
                        const title = item.title || (conversation?.name || 'Notification');
                        const stamp = item.createdAt ? format(new Date(item.createdAt), 'MMM d, yyyy HH:mm') : '-';

                        return (
                            <Card
                                key={`notif-${item.id}`}
                                css={[!item.read ? undefined : tw`opacity-80`, tw`transition-all`]}
                                style={!item.read ? { borderColor: 'rgba(139, 92, 246, 0.62)' } : undefined}
                            >
                                <Row>
                                    <div css={tw`flex items-start gap-3 min-w-0`}>
                                        {avatar ? <Avatar src={avatar} alt={title} /> : <AvatarFallback>{initials(title)}</AvatarFallback>}
                                        <div css={tw`min-w-0`}>
                                            <div css={tw`flex items-center gap-2`}>
                                                <p css={tw`text-sm font-semibold text-neutral-100 truncate`}>{title}</p>
                                                {!item.read && <NewBadge>NEW</NewBadge>}
                                            </div>
                                            <p css={tw`text-xs text-neutral-300 mt-0.5 whitespace-pre-wrap break-words`}>{item.body || '-'}</p>
                                            <p css={tw`text-[11px] text-neutral-500 mt-1`}>{stamp}</p>
                                        </div>
                                    </div>
                                    <div css={tw`flex items-center gap-1 flex-wrap justify-end w-full sm:w-auto`}>
                                        {!item.read && (
                                            <Button type={'button'} size={'xsmall'} color={'secondary'} css={tw`flex-1 sm:flex-none justify-center`} onClick={() => markOneRead(item.id)}>
                                                Read
                                            </Button>
                                        )}
                                        {conversation && (
                                            <Button
                                                type={'button'}
                                                size={'xsmall'}
                                                color={'secondary'}
                                                css={tw`flex-1 sm:flex-none justify-center`}
                                                onClick={async () => {
                                                    if (conversation.notificationMutedUntil) {
                                                        await unmuteConversationNotifications(conversation.id);
                                                    } else {
                                                        await muteConversationNotifications(conversation.id, 60 * 24 * 7);
                                                    }
                                                    await load();
                                                }}
                                            >
                                                {conversation.notificationMutedUntil ? 'Unmute' : 'Mute'}
                                            </Button>
                                        )}
                                        {item.conversationId && (
                                            <Button
                                                type={'button'}
                                                size={'xsmall'}
                                                css={tw`flex-1 sm:flex-none justify-center`}
                                                onClick={() => {
                                                    window.location.href = `/chat?conversation=${item.conversationId}`;
                                                }}
                                            >
                                                Open
                                            </Button>
                                        )}
                                    </div>
                                </Row>
                            </Card>
                        );
                    })}
                </div>
            )}
        </PageContentBlock>
    );
};
