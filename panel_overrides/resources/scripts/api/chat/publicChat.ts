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
    role?: 'owner' | 'admin' | 'member';
}

export interface ChatConversation {
    id: number;
    type: ChatConversationType;
    name: string;
    groupUsername: string | null;
    groupCode: string | null;
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

const rawUser = (item: any): ChatUser => ({
    id: Number(item.id),
    username: String(item.username || ''),
    displayName: String(item.display_name || item.username || ''),
    avatarUrl: item.avatar_url ? String(item.avatar_url) : undefined,
    birthday: item.birthday ? String(item.birthday) : null,
    createdAt: item.created_at ? String(item.created_at) : null,
    mutedUntil: item.muted_until ? String(item.muted_until) : null,
    role: ['owner', 'admin', 'member'].includes(String(item.role || '')) ? (String(item.role) as any) : undefined,
});

const rawConversation = (item: any): ChatConversation => ({
    id: Number(item.id),
    type: (item.type || 'global') as ChatConversationType,
    name: String(item.name || 'Chat'),
    groupUsername: item.group_username ?? null,
    groupCode: item.group_code ?? null,
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

export const getConversations = async (): Promise<ChatConversation[]> => {
    const { data } = await http.get('/api/client/chat/conversations');

    return Array.isArray(data?.data) ? data.data.map(rawConversation) : [];
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
    payload: { name?: string; groupUsername?: string; groupCode?: string }
): Promise<void> => {
    await http.patch(`/api/client/chat/conversations/${conversationId}`, {
        name: payload.name,
        group_username: payload.groupUsername,
        group_code: payload.groupCode,
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
    const { data } = await http.patch(`/api/client/chat/messages/${messageId}`, { message });

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
