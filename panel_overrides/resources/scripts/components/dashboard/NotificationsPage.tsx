import React, { useEffect, useMemo, useState } from 'react';
import tw from 'twin.macro';
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

const Card = tw.div`rounded-lg border border-neutral-700 bg-neutral-800 p-3`;
const Row = tw.div`flex items-start justify-between gap-3`;
const Avatar = tw.img`w-10 h-10 rounded-full object-cover border border-neutral-700`;
const AvatarFallback = tw.div`w-10 h-10 rounded-full bg-neutral-700 text-neutral-100 text-xs font-semibold flex items-center justify-center border border-neutral-700`;

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
    const [browserNotifState, setBrowserNotifState] = useState<'unsupported' | 'default' | 'denied' | 'granted'>(
        typeof window === 'undefined' || !('Notification' in window) ? 'unsupported' : Notification.permission
    );

    const conversationMap = useMemo(() => {
        const map: Record<number, ChatConversation> = {};
        for (const conversation of conversations) {
            map[conversation.id] = conversation;
        }
        return map;
    }, [conversations]);

    const load = async () => {
        const [notifications, conv] = await Promise.all([getChatNotifications(undefined, 120), getConversations()]);
        setItems(notifications.items.sort((a, b) => Number(b.id) - Number(a.id)));
        setConversations(conv);
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

    const visibleItems = showUnreadOnly ? items.filter((item) => !item.read) : items;
    const unreadCount = items.filter((item) => !item.read).length;

    return (
        <PageContentBlock title={'Notifications'} showFlashKey={'dashboard'}>
            <div css={tw`rounded-lg border border-neutral-700 bg-neutral-800 p-3 mb-3`}>
                <div css={tw`flex flex-col sm:flex-row sm:items-center justify-between gap-3`}>
                    <div>
                        <p css={tw`text-sm text-neutral-200 font-semibold`}>Realtime Notifications</p>
                        <p css={tw`text-xs text-neutral-400`}>System, DM, group, global, dan call.</p>
                    </div>
                    <div css={tw`text-left sm:text-right`}>
                        <p css={tw`text-sm text-neutral-200`}>Unread: {unreadCount}</p>
                        <p css={tw`text-xs text-neutral-500`}>Total: {items.length}</p>
                    </div>
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
                            <Card key={`notif-${item.id}`} css={[!item.read ? tw`border-blue-600/60` : tw`opacity-80`, tw`transition-all`]}>
                                <Row>
                                    <div css={tw`flex items-start gap-3 min-w-0`}>
                                        {avatar ? <Avatar src={avatar} alt={title} /> : <AvatarFallback>{initials(title)}</AvatarFallback>}
                                        <div css={tw`min-w-0`}>
                                            <div css={tw`flex items-center gap-2`}>
                                                <p css={tw`text-sm font-semibold text-neutral-100 truncate`}>{title}</p>
                                                {!item.read && <span css={tw`text-[10px] bg-blue-600 text-white px-2 py-0.5 rounded-full`}>NEW</span>}
                                            </div>
                                            <p css={tw`text-xs text-neutral-300 mt-0.5 whitespace-pre-wrap break-words`}>{item.body || '-'}</p>
                                            <p css={tw`text-[11px] text-neutral-500 mt-1`}>{stamp}</p>
                                        </div>
                                    </div>
                                    <div css={tw`flex items-center gap-1 flex-wrap justify-end`}>
                                        {!item.read && (
                                            <Button type={'button'} size={'xsmall'} color={'secondary'} onClick={() => markOneRead(item.id)}>
                                                Read
                                            </Button>
                                        )}
                                        {conversation && (
                                            <Button
                                                type={'button'}
                                                size={'xsmall'}
                                                color={'secondary'}
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
