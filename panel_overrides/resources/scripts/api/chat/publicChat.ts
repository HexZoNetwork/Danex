import http from '@/api/http';

export type ChatMediaType = 'text' | 'image' | 'audio' | 'link';
export type ChatConversationType = 'global' | 'private' | 'group';

export interface ChatUser {
    id: number;
    username: string;
    displayName: string;
    avatarUrl?: string;
    birthday?: string | null;
    createdAt?: string | null;
    mutedUntil?: string | null;
    lastSeenAt?: string | null;
    isOnline?: boolean;
    role?: 'owner' | 'admin' | 'member';
}

export interface ChatConversation {
    id: number;
    type: ChatConversationType;
    name: string;
    avatarUrl: string | null;
    groupUsername: string | null;
    groupCode: string | null;
    notificationMutedUntil: string | null;
    members: ChatUser[];
    lastMessageAt: string | null;
}

export interface ChatPoll {
    question: string;
    options: { text: string; votes: number }[];
    myVote: number | null;
}

export interface PublicChatMessage {
    id: number;
    conversationId: number;
    userId: number;
    username: string;
    displayName: string;
    avatarUrl: string | null;
    birthday: string | null;
    joinedAt: string | null;
    mentions: string[];
    body: string | null;
    mediaUrl: string | null;
    mediaType: ChatMediaType;
    mediaMime: string | null;
    mediaName: string | null;
    editedAt: string | null;
    createdAt: string;
    updatedAt: string;
    isOwn: boolean;
    readCount: number;
    isReadByOthers: boolean;
    reply: {
        id: number;
        username: string;
        displayName: string;
        avatarUrl: string | null;
        birthday: string | null;
        joinedAt: string | null;
        body: string;
    } | null;
    poll: ChatPoll | null;
    reactions: { emoji: string; count: number; mine: boolean }[];
}

export interface ChatCallParticipant {
    id: number;
    username: string;
    displayName: string;
    avatarUrl: string | null;
    micMuted: boolean;
    speakingLevel: number;
    joinedAt: string | null;
}

export interface ChatCallSignal {
    id: number;
    type: 'join' | 'leave' | 'end' | 'offer' | 'answer' | 'ice' | 'ring' | 'ring_response';
    fromUserId: number | null;
    toUserId: number | null;
    payload: any;
    createdAt: string | null;
}

export interface ChatCallState {
    active: boolean;
    call: {
        id: number;
        conversationId: number;
        startedBy: number;
        startedAt: string | null;
        participants: ChatCallParticipant[];
    } | null;
    signals: ChatCallSignal[];
    lastSignalId: number;
}

export interface ChatNotificationItem {
    id: number;
    conversationId: number | null;
    fromUserId: number | null;
    sourceType: 'system' | 'dm' | 'group' | 'global' | 'call';
    title: string;
    body: string | null;
    avatarUrl: string | null;
    meta: any;
    createdAt: string | null;
    read: boolean;
}

const rawUser = (item: any): ChatUser => ({
    id: Number(item.id),
    username: String(item.username || ''),
    displayName: String(item.display_name || item.username || ''),
    avatarUrl: item.avatar_url ? String(item.avatar_url) : undefined,
    birthday: item.birthday ? String(item.birthday) : null,
    createdAt: item.created_at ? String(item.created_at) : null,
    mutedUntil: item.muted_until ? String(item.muted_until) : null,
    lastSeenAt: item.last_seen_at ? String(item.last_seen_at) : null,
    isOnline: Boolean(item.is_online),
    role: ['owner', 'admin', 'member'].includes(String(item.role || '')) ? (String(item.role) as any) : undefined,
});

const rawConversation = (item: any): ChatConversation => ({
    id: Number(item.id),
    type: (item.type || 'global') as ChatConversationType,
    name: String(item.name || 'Chat'),
    avatarUrl: item.avatar_url ? String(item.avatar_url) : null,
    groupUsername: item.group_username ?? null,
    groupCode: item.group_code ?? null,
    notificationMutedUntil: item.notification_muted_until ? String(item.notification_muted_until) : null,
    members: Array.isArray(item.members) ? item.members.map(rawUser) : [],
    lastMessageAt: item.last_message_at ?? null,
});

const rawPoll = (poll: any): ChatPoll | null => {
    if (!poll || !Array.isArray(poll.options)) {
        return null;
    }

    const options = poll.options
        .filter((opt: any) => opt !== null && opt !== undefined)
        .map((opt: any) => ({
            text: String(opt?.text ?? opt?.value ?? opt?.label ?? ''),
            votes: Number(opt?.votes ?? 0),
        }))
        .filter((opt: { text: string; votes: number }) => opt.text.trim().length > 0);

    return {
        question: String(poll.question || ''),
        options,
        myVote: typeof poll.my_vote === 'number' ? Number(poll.my_vote) : null,
    };
};

const rawDataToMessage = (item: any): PublicChatMessage => ({
    id: Number(item.id),
    conversationId: Number(item.conversation_id || 1),
    userId: Number(item.user_id),
    username: String(item.username || ''),
    displayName: String(item.display_name || item.username || ''),
    avatarUrl: item.avatar_url ? String(item.avatar_url) : null,
    birthday: item.birthday ? String(item.birthday) : null,
    joinedAt: item.joined_at ? String(item.joined_at) : null,
    mentions: Array.isArray(item.mentions) ? item.mentions.map(String) : [],
    body: item.body ?? null,
    mediaUrl: item.media_url ?? null,
    mediaType: (item.media_type || 'text') as ChatMediaType,
    mediaMime: item.media_mime ?? null,
    mediaName: item.media_name ?? null,
    editedAt: item.edited_at ?? null,
    createdAt: String(item.created_at),
    updatedAt: String(item.updated_at),
    isOwn: Boolean(item.is_own),
    readCount: Number(item.read_count || 0),
    isReadByOthers: Boolean(item.is_read_by_others),
    reply: item.reply
        ? {
              id: Number(item.reply.id),
              username: String(item.reply.username || ''),
              displayName: String(item.reply.display_name || item.reply.username || ''),
              avatarUrl: item.reply.avatar_url ? String(item.reply.avatar_url) : null,
              birthday: item.reply.birthday ? String(item.reply.birthday) : null,
              joinedAt: item.reply.joined_at ? String(item.reply.joined_at) : null,
              body: String(item.reply.body || ''),
          }
        : null,
    poll: rawPoll(item.poll),
    reactions: Array.isArray(item.reactions)
        ? item.reactions.map((r: any) => ({
              emoji: String(r?.emoji || ''),
              count: Number(r?.count || 0),
              mine: Boolean(r?.mine),
          }))
        : [],
});

const rawCallParticipant = (item: any): ChatCallParticipant => ({
    id: Number(item.id),
    username: String(item.username || ''),
    displayName: String(item.display_name || item.username || ''),
    avatarUrl: item.avatar_url ? String(item.avatar_url) : null,
    micMuted: Boolean(item.mic_muted),
    speakingLevel: Math.max(0, Math.min(100, Number(item.speaking_level || 0))),
    joinedAt: item.joined_at ? String(item.joined_at) : null,
});

const rawCallSignal = (item: any): ChatCallSignal => ({
    id: Number(item.id),
    type: String(item.type || 'ice') as ChatCallSignal['type'],
    fromUserId: item.from_user_id !== null && item.from_user_id !== undefined ? Number(item.from_user_id) : null,
    toUserId: item.to_user_id !== null && item.to_user_id !== undefined ? Number(item.to_user_id) : null,
    payload: item.payload ?? null,
    createdAt: item.created_at ? String(item.created_at) : null,
});

export const getConversations = async (): Promise<ChatConversation[]> => {
    const { data } = await http.get('/api/client/chat/conversations');

    return Array.isArray(data?.data) ? data.data.map(rawConversation) : [];
};

export const sendPresenceHeartbeat = async (): Promise<void> => {
    await http.post('/api/client/chat/presence');
};

export const getChatNotifications = async (sinceId?: number, limit = 60): Promise<{
    items: ChatNotificationItem[];
    unreadCount: number;
    lastNotificationId: number;
}> => {
    const { data } = await http.get('/api/client/chat/notifications', {
        params: {
            since_id: sinceId && sinceId > 0 ? sinceId : undefined,
            limit: limit > 0 ? limit : undefined,
        },
    });
    const raw = data || {};
    const items: ChatNotificationItem[] = Array.isArray(raw?.data)
        ? raw.data.map((item: any) => ({
              id: Number(item.id),
              conversationId: item.conversation_id !== null && item.conversation_id !== undefined ? Number(item.conversation_id) : null,
              fromUserId: item.from_user_id !== null && item.from_user_id !== undefined ? Number(item.from_user_id) : null,
              sourceType: String(item.source_type || 'system') as ChatNotificationItem['sourceType'],
              title: String(item.title || ''),
              body: item.body ? String(item.body) : null,
              avatarUrl: item.avatar_url ? String(item.avatar_url) : null,
              meta: item.meta ?? null,
              createdAt: item.created_at ? String(item.created_at) : null,
              read: Boolean(item.read),
          }))
        : [];

    return {
        items,
        unreadCount: Number(raw?.unread_count || 0),
        lastNotificationId: Number(raw?.last_notification_id || 0),
    };
};

export const readChatNotifications = async (notificationIds?: number[]): Promise<void> => {
    await http.post('/api/client/chat/notifications/read', {
        notification_ids: notificationIds && notificationIds.length ? notificationIds : undefined,
    });
};

export const muteConversationNotifications = async (conversationId: number, minutes?: number): Promise<void> => {
    await http.post('/api/client/chat/notifications/mute', {
        conversation_id: conversationId,
        minutes: minutes || undefined,
    });
};

export const unmuteConversationNotifications = async (conversationId: number): Promise<void> => {
    await http.post('/api/client/chat/notifications/unmute', {
        conversation_id: conversationId,
    });
};

export const searchChatUsers = async (query: string): Promise<ChatUser[]> => {
    const { data } = await http.get('/api/client/chat/users', { params: { query } });

    return Array.isArray(data?.data) ? data.data.map(rawUser) : [];
};

export const createPrivateConversation = async (username: string): Promise<number> => {
    const { data } = await http.post('/api/client/chat/conversations/private', { username });

    return Number(data?.data?.conversation_id || 0);
};

export const createGroupConversation = async (
    name: string,
    groupUsername: string,
    memberUsernames: string[],
    groupCode?: string
): Promise<number> => {
    const { data } = await http.post('/api/client/chat/conversations/group', {
        name,
        group_username: groupUsername,
        group_code: groupCode || undefined,
        member_usernames: memberUsernames,
    });

    return Number(data?.data?.conversation_id || 0);
};

export const updateGroupConversation = async (
    conversationId: number,
    payload: { name?: string; groupUsername?: string; groupCode?: string; avatarUrl?: string }
): Promise<void> => {
    await http.patch(`/api/client/chat/conversations/${conversationId}`, {
        name: payload.name,
        group_username: payload.groupUsername,
        group_code: payload.groupCode,
        avatar_url: payload.avatarUrl,
    });
};

export const addGroupMember = async (conversationId: number, username: string): Promise<void> => {
    await http.post(`/api/client/chat/conversations/${conversationId}/members`, { username });
};

export const kickGroupMember = async (conversationId: number, memberId: number): Promise<void> => {
    await http.delete(`/api/client/chat/conversations/${conversationId}/members/${memberId}`);
};

export const banGroupMember = async (conversationId: number, memberId: number): Promise<void> => {
    await http.post(`/api/client/chat/conversations/${conversationId}/members/${memberId}/ban`);
};

export const muteChatMember = async (conversationId: number, memberId: number, minutes?: number): Promise<void> => {
    await http.post(`/api/client/chat/conversations/${conversationId}/members/${memberId}/mute`, {
        minutes: minutes || undefined,
    });
};

export const unmuteChatMember = async (conversationId: number, memberId: number): Promise<void> => {
    await http.delete(`/api/client/chat/conversations/${conversationId}/members/${memberId}/mute`);
};

export const setGroupAdmin = async (conversationId: number, memberId: number, admin: boolean): Promise<void> => {
    await http.post(`/api/client/chat/conversations/${conversationId}/members/${memberId}/admin`, { admin });
};

export const getPublicMessages = async (opts: {
    conversationId: number;
    sinceId?: number;
    limit?: number;
}): Promise<PublicChatMessage[]> => {
    const { data } = await http.get('/api/client/chat/messages', {
        params: {
            conversation_id: opts.conversationId,
            since_id: opts.sinceId || undefined,
            limit: opts.limit || (opts.sinceId ? 100 : 80),
        },
    });

    return Array.isArray(data?.data) ? data.data.map(rawDataToMessage) : [];
};

export const postPublicMessage = async (payload: {
    conversationId: number;
    message?: string;
    mediaUrl?: string;
    mediaType?: ChatMediaType;
    mediaMime?: string;
    mediaName?: string;
    replyToId?: number;
}): Promise<PublicChatMessage> => {
    const { data } = await http.post('/api/client/chat/messages', {
        conversation_id: payload.conversationId,
        message: payload.message || '',
        media_url: payload.mediaUrl || undefined,
        media_type: payload.mediaType || undefined,
        media_mime: payload.mediaMime || undefined,
        media_name: payload.mediaName || undefined,
        reply_to_id: payload.replyToId || undefined,
    });

    return rawDataToMessage(data?.data || {});
};

export const getCallState = async (conversationId: number, sinceId?: number): Promise<ChatCallState> => {
    const { data } = await http.get('/api/client/chat/calls/state', {
        params: {
            conversation_id: conversationId,
            since_id: sinceId && sinceId > 0 ? sinceId : undefined,
        },
    });
    const raw = data?.data || {};
    const call = raw.call
        ? {
              id: Number(raw.call.id),
              conversationId: Number(raw.call.conversation_id || conversationId),
              startedBy: Number(raw.call.started_by || 0),
              startedAt: raw.call.started_at ? String(raw.call.started_at) : null,
              participants: Array.isArray(raw.call.participants) ? raw.call.participants.map(rawCallParticipant) : [],
          }
        : null;

    return {
        active: Boolean(raw.active && call),
        call,
        signals: Array.isArray(raw.signals) ? raw.signals.map(rawCallSignal) : [],
        lastSignalId: Number(raw.last_signal_id || 0),
    };
};

export const startConversationCall = async (conversationId: number): Promise<void> => {
    await http.post('/api/client/chat/calls/start', { conversation_id: conversationId });
};

export const joinConversationCall = async (conversationId: number): Promise<void> => {
    await http.post('/api/client/chat/calls/join', { conversation_id: conversationId });
};

export const leaveConversationCall = async (conversationId: number): Promise<void> => {
    await http.post('/api/client/chat/calls/leave', { conversation_id: conversationId });
};

export const endConversationCall = async (conversationId: number): Promise<void> => {
    await http.post('/api/client/chat/calls/end', { conversation_id: conversationId });
};

export const sendCallSignal = async (payload: {
    conversationId: number;
    type: 'offer' | 'answer' | 'ice' | 'ring' | 'ring_response';
    toUserId?: number;
    signalPayload: any;
}): Promise<number> => {
    const { data } = await http.post('/api/client/chat/calls/signal', {
        conversation_id: payload.conversationId,
        type: payload.type,
        to_user_id: payload.toUserId || undefined,
        payload: payload.signalPayload,
    });

    return Number(data?.data?.signal_id || 0);
};

export const updateCallMic = async (payload: {
    conversationId: number;
    muted?: boolean;
    speakingLevel?: number;
}): Promise<void> => {
    await http.post('/api/client/chat/calls/mic', {
        conversation_id: payload.conversationId,
        muted: payload.muted,
        speaking_level: payload.speakingLevel,
    });
};

export const createPollMessage = async (payload: {
    conversationId: number;
    question: string;
    options: string[];
    mediaUrl?: string;
    mediaName?: string;
    mediaMime?: string;
}): Promise<PublicChatMessage> => {
    const { data } = await http.post('/api/client/chat/polls', {
        conversation_id: payload.conversationId,
        question: payload.question,
        options: payload.options,
        media_url: payload.mediaUrl || undefined,
        media_name: payload.mediaName || undefined,
        media_mime: payload.mediaMime || undefined,
    });

    return rawDataToMessage(data?.data || {});
};

export const votePoll = async (messageId: number, optionIndex: number): Promise<PublicChatMessage> => {
    const { data } = await http.post(`/api/client/chat/polls/${messageId}/vote`, {
        option_index: optionIndex,
    });

    return rawDataToMessage(data?.data || {});
};

export const editPublicMessage = async (messageId: number, message: string): Promise<PublicChatMessage> => {
    const { data } = await http.post(`/api/client/chat/messages/${messageId}/edit`, { message });

    return rawDataToMessage(data?.data || {});
};

export const deletePublicMessage = async (messageId: number): Promise<void> => {
    await http.delete(`/api/client/chat/messages/${messageId}`);
};

export const toggleReaction = async (messageId: number, emoji: string): Promise<PublicChatMessage> => {
    const { data } = await http.post(`/api/client/chat/messages/${messageId}/reactions`, { emoji });

    return rawDataToMessage(data?.data || {});
};

export const markPublicMessagesRead = async (conversationId: number, messageIds?: number[]): Promise<void> => {
    await http.post('/api/client/chat/read', {
        conversation_id: conversationId,
        message_ids: messageIds && messageIds.length ? messageIds : undefined,
    });
};

export const uploadPublicMedia = async (
    file: File
): Promise<{ url: string; mediaType: ChatMediaType; mediaMime: string | null; mediaName: string | null }> => {
    const formData = new FormData();
    formData.append('file', file);

    const { data } = await http.post('/api/client/chat/upload', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
    });

    return {
        url: String(data?.data?.url || ''),
        mediaType: (data?.data?.media_type || 'link') as ChatMediaType,
        mediaMime: data?.data?.media_mime ?? null,
        mediaName: data?.data?.media_name ?? null,
    };
};
