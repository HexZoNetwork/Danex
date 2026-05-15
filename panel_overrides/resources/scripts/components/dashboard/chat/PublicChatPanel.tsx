import React, { useEffect, useMemo, useRef, useState } from 'react';
import tw from 'twin.macro';
import styled from 'styled-components/macro';
import { format } from 'date-fns';
import { useLocation } from 'react-router-dom';
import useFlash from '@/plugins/useFlash';
import { useStoreState } from 'easy-peasy';
import {
    addGroupMember,
    banGroupMember,
    ChatCallParticipant,
    ChatConversation,
    ChatUser,
    createGroupConversation,
    createPollMessage,
    createPrivateConversation,
    deletePublicMessage,
    editPublicMessage,
    getConversations,
    getCallState,
    getPublicMessages,
    joinConversationCall,
    muteConversationNotifications,
    markPublicMessagesRead,
    muteChatMember,
    postPublicMessage,
    PublicChatMessage,
    searchChatUsers,
    sendPresenceHeartbeat,
    sendCallSignal,
    setGroupAdmin,
    toggleReaction,
    updateGroupConversation,
    updateCallMic,
    unmuteChatMember,
    unmuteConversationNotifications,
    uploadPublicMedia,
    votePoll,
    kickGroupMember,
    leaveConversationCall,
    startConversationCall,
    endConversationCall,
} from '@/api/chat/publicChat';
import { Button } from '@/components/elements/button/index';

const Root = styled.div`
    ${tw`rounded-lg border h-full min-h-[70vh] max-h-[85vh] flex flex-col lg:flex-row overflow-hidden`};
    background: #0b0b10;
    border-color: rgba(139, 92, 246, 0.24);
    box-shadow: 0 18px 48px rgba(0, 0, 0, 0.5);
`;
const Sidebar = styled.div`
    ${tw`w-full lg:w-[21rem] border-b lg:border-b-0 lg:border-r flex flex-col`};
    background: #0b0b10;
    border-color: rgba(139, 92, 246, 0.18);
`;
const SideHeader = styled.div`
    ${tw`px-4 py-3 border-b`};
    border-color: rgba(139, 92, 246, 0.18);
`;
const SideTitle = tw.h2`text-base font-semibold text-neutral-100`;
const SideBlock = styled.div`
    ${tw`p-3 border-b`};
    border-color: rgba(139, 92, 246, 0.18);
`;
const SideList = tw.div`flex-1 overflow-y-auto p-2 space-y-2`;
const ConvButton = styled.button`
    ${tw`w-full text-left p-2 rounded-md border transition`};
    background: #111117;
    border-color: rgba(139, 92, 246, 0.2);

    &:hover {
        background: rgba(139, 92, 246, 0.1);
        border-color: rgba(139, 92, 246, 0.5);
        box-shadow: inset 2px 0 0 rgba(139, 92, 246, 0.72);
    }
`;
const Input = styled.input`
    ${tw`w-full rounded-md border text-neutral-100 px-3 py-2 text-sm focus:outline-none`};
    background: #111117;
    border-color: rgba(139, 92, 246, 0.24);

    &:focus {
        border-color: rgba(139, 92, 246, 0.68);
        box-shadow: 0 0 0 2px rgba(139, 92, 246, 0.18);
    }
`;
const TextArea = styled.textarea`
    ${tw`w-full rounded-md border text-neutral-100 px-3 py-2 text-sm min-h-[66px] focus:outline-none`};
    background: #111117;
    border-color: rgba(139, 92, 246, 0.24);

    &:focus {
        border-color: rgba(139, 92, 246, 0.68);
        box-shadow: 0 0 0 2px rgba(139, 92, 246, 0.18);
    }
`;
const Small = tw.button`text-xs text-neutral-300 hover:text-neutral-100`;
const Tiny = styled.button`
    ${tw`text-[11px] text-neutral-300 hover:text-neutral-100 px-2 py-1 rounded border`};
    background: #111117;
    border-color: rgba(139, 92, 246, 0.24);

    &:hover {
        border-color: rgba(139, 92, 246, 0.54);
    }
`;

const Main = styled.div`
    ${tw`flex-1 flex flex-col min-w-0 min-h-0`};
    background: #07070b;
`;
const MainHeader = styled.div`
    ${tw`px-3 py-2 lg:px-4 lg:py-3 border-b flex flex-wrap items-start lg:items-center justify-between gap-2`};
    border-color: rgba(139, 92, 246, 0.18);
`;
const HeaderTitle = tw.h3`text-base font-semibold text-neutral-100 truncate`;
const HeaderMeta = tw.div`text-xs text-neutral-400 truncate`;
const Body = tw.div`flex-1 min-h-0 overflow-y-auto overflow-x-hidden px-2 py-2 lg:px-4 lg:py-3 space-y-2`;
const Composer = styled.form`
    ${tw`flex-shrink-0 p-3 border-t space-y-2`};
    background: #0b0b10;
    border-color: rgba(139, 92, 246, 0.18);
`;

const BubbleWrap = tw.div`flex`;
const Bubble = tw.div`relative max-w-[94%] lg:max-w-[84%] rounded-xl px-3 py-2 text-sm whitespace-pre-wrap break-words`;
const Meta = tw.div`mt-1 text-[11px] text-neutral-400 flex items-center gap-2 flex-wrap`;
const Tag = styled.span`
    ${tw`px-1.5 py-0.5 rounded text-[10px] text-neutral-200 border`};
    background: #111117;
    border-color: rgba(139, 92, 246, 0.22);
`;

const AvatarImage = tw.img`w-full h-full object-cover`;
const AvatarFallback = styled.div`
    ${tw`w-full h-full text-[10px] font-bold text-neutral-100 flex items-center justify-center`};
    background: #15151d;
`;
const OnlineDot = styled.span`
    ${tw`absolute -right-0.5 -bottom-0.5 w-2.5 h-2.5 rounded-full border`};
    background: #10b981;
    border-color: #07070b;
`;

const panelStyle: React.CSSProperties = {
    background: '#0b0b10',
    borderColor: 'rgba(139, 92, 246, 0.28)',
    boxShadow: '0 22px 60px rgba(0, 0, 0, 0.62), 0 0 26px rgba(139, 92, 246, 0.12)',
};

const panelHeaderStyle: React.CSSProperties = {
    background: '#111117',
    borderColor: 'rgba(139, 92, 246, 0.18)',
};

const chipButtonStyle: React.CSSProperties = {
    background: '#111117',
    borderColor: 'rgba(139, 92, 246, 0.22)',
    color: '#d4d4df',
};

const URL_REGEX = /(https?:\/\/[^\s]+)/gi;
const TOKEN_REGEX = /(https?:\/\/[^\s]+|@[a-zA-Z0-9._-]{3,32})/g;

const isSafeHttpUrl = (candidate: string): boolean => {
    try {
        const parsed = new URL(candidate);

        return parsed.protocol === 'http:' || parsed.protocol === 'https:';
    } catch {
        return false;
    }
};

const firstUrlInText = (text: string): string | null => {
    const matches = text.match(URL_REGEX);
    if (!matches?.length) return null;

    return isSafeHttpUrl(matches[0]) ? matches[0] : null;
};

const formatLinkLabel = (url: string): string => {
    try {
        const parsed = new URL(url);

        return `${parsed.hostname}${parsed.pathname === '/' ? '' : parsed.pathname}`;
    } catch {
        return url;
    }
};

const safeTime = (value: string | null | undefined): string => {
    if (!value) return '--:--';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '--:--';

    try {
        return format(date, 'HH:mm');
    } catch {
        return '--:--';
    }
};

const safeDate = (value: string | null | undefined): string => {
    if (!value) return '-';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '-';

    try {
        return format(date, 'MMM d, yyyy');
    } catch {
        return '-';
    }
};

const ONLINE_WINDOW_MS = 2 * 60 * 1000;
const PRESENCE_HEARTBEAT_MS = 20_000;
const CALL_POLL_MS = 2500;
const MIC_SYNC_MS = 1200;
const CALL_RESTART_DEBOUNCE_MS = 1800;
const CALL_MAX_RESTART_PER_PEER = 4;
const CALL_RESTART_WINDOW_MS = 60_000;

type RuntimeIceServer = {
    urls?: string | string[];
    username?: string;
    credential?: string;
};

const toUnixMs = (value: string | null | undefined): number => {
    if (!value) return 0;
    const t = new Date(value).getTime();

    return Number.isFinite(t) ? t : 0;
};

const presenceLabel = (lastSeenMs: number): { online: boolean; text: string } => {
    if (!lastSeenMs) {
        return { online: false, text: 'last seen unknown' };
    }

    const diff = Date.now() - lastSeenMs;
    if (diff <= ONLINE_WINDOW_MS) {
        return { online: true, text: 'online' };
    }

    const date = new Date(lastSeenMs);
    if (Number.isNaN(date.getTime())) {
        return { online: false, text: 'last seen unknown' };
    }

    const today = format(new Date(), 'yyyy-MM-dd');
    const day = format(date, 'yyyy-MM-dd');
    const stamp = day === today ? format(date, 'HH:mm') : format(date, 'MMM d, yyyy HH:mm');

    return { online: false, text: `last seen ${stamp}` };
};

const avatarForName = (name: string): string => {
    const clean = name.trim();
    if (!clean) return '?';
    const parts = clean.split(/\s+/).filter(Boolean);
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();

    return `${parts[0][0] || ''}${parts[1][0] || ''}`.toUpperCase();
};

const clampLevel = (value: number): number => Math.max(0, Math.min(100, Math.round(value)));

const normalizeIceServer = (raw: RuntimeIceServer | null | undefined): RTCIceServer | null => {
    if (!raw || !raw.urls) return null;

    const sourceUrls = Array.isArray(raw.urls) ? raw.urls : [raw.urls];
    const urls = sourceUrls
        .map((url) => String(url || '').trim())
        .filter((url) => /^stuns?:/i.test(url) || /^turns?:/i.test(url));
    if (!urls.length) return null;

    const server: RTCIceServer = { urls };
    if (raw.username) server.username = String(raw.username);
    if (raw.credential) server.credential = String(raw.credential);

    return server;
};

const parseIceServerJson = (raw: string | null | undefined): RTCIceServer[] => {
    if (!raw) return [];
    try {
        const parsed = JSON.parse(raw);
        if (!Array.isArray(parsed)) return [];
        return parsed.map((item) => normalizeIceServer(item as RuntimeIceServer)).filter(Boolean) as RTCIceServer[];
    } catch {
        return [];
    }
};

const dedupeIceServers = (servers: RTCIceServer[]): RTCIceServer[] => {
    const seen = new Set<string>();
    const result: RTCIceServer[] = [];

    for (const server of servers) {
        const urls = Array.isArray(server.urls) ? server.urls : [server.urls];
        const key = `${urls.join('|')}|${server.username || ''}|${server.credential || ''}`;
        if (seen.has(key)) continue;
        seen.add(key);
        result.push(server);
    }

    return result;
};

const resolveRtcIceServers = (): RTCIceServer[] => {
    const defaults: RTCIceServer[] = [
        { urls: ['stun:stun.l.google.com:19302', 'stun:stun1.l.google.com:19302'] },
        { urls: ['stun:stun.cloudflare.com:3478'] },
    ];

    const envRaw = process.env.MIX_CHAT_CALL_ICE_SERVERS;
    const envServers = parseIceServerJson(envRaw);

    let storageServers: RTCIceServer[] = [];
    if (typeof window !== 'undefined') {
        storageServers = [
            ...parseIceServerJson(window.localStorage.getItem('chat.call.iceServers')),
            ...parseIceServerJson(window.localStorage.getItem('chat_call_ice_servers')),
        ];
    }

    return dedupeIceServers([...storageServers, ...envServers, ...defaults]);
};

const buildRtcConfiguration = (): RTCConfiguration => ({
    iceServers: resolveRtcIceServers(),
    iceCandidatePoolSize: 8,
    bundlePolicy: 'balanced',
    rtcpMuxPolicy: 'require',
    iceTransportPolicy: 'all',
});

const isImageFile = (file: File): boolean =>
    file.type.startsWith('image/') || /\.(png|jpe?g|gif|webp)$/i.test(file.name);

const compressImageFile = async (file: File, quality = 0.8, maxEdge = 1600): Promise<File> => {
    const url = URL.createObjectURL(file);
    try {
        const image = await new Promise<HTMLImageElement>((resolve, reject) => {
            const img = new Image();
            img.onload = () => resolve(img);
            img.onerror = () => reject(new Error('Failed to load image.'));
            img.src = url;
        });

        const ratio = Math.min(1, maxEdge / Math.max(image.width, image.height));
        const width = Math.max(1, Math.round(image.width * ratio));
        const height = Math.max(1, Math.round(image.height * ratio));

        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d');
        if (!ctx) {
            return file;
        }
        ctx.drawImage(image, 0, 0, width, height);

        const blob = await new Promise<Blob | null>((resolve) => {
            canvas.toBlob((result) => resolve(result), 'image/jpeg', quality);
        });
        if (!blob) {
            return file;
        }

        return new File([blob], file.name.replace(/\.[^.]+$/, '') + '-compressed.jpg', {
            type: 'image/jpeg',
            lastModified: Date.now(),
        });
    } finally {
        URL.revokeObjectURL(url);
    }
};

const renderRichBody = (body: string | null, selfUsername: string) => {
    if (!body) return null;

    const chunks = body.split(TOKEN_REGEX);

    return chunks.map((chunk, idx) => {
        if (!chunk) return null;

        if (chunk.startsWith('http') && isSafeHttpUrl(chunk)) {
            return (
                <a key={`${chunk}-${idx}`} href={chunk} target={'_blank'} rel={'noopener noreferrer'} css={tw`underline text-neutral-200`}>
                    {chunk}
                </a>
            );
        }

        if (chunk.startsWith('@')) {
            const mention = chunk.slice(1).toLowerCase();
            const isMe = mention === selfUsername.toLowerCase();

            return (
                <span key={`${chunk}-${idx}`} css={isMe ? tw`text-neutral-100 font-semibold` : tw`text-neutral-200 font-medium`}>
                    {chunk}
                </span>
            );
        }

        return <React.Fragment key={`${chunk}-${idx}`}>{chunk}</React.Fragment>;
    });
};

const safeMentions = (mentions: unknown): string[] => {
    if (!Array.isArray(mentions)) return [];

    return mentions.map((m) => String(m || '').toLowerCase()).filter(Boolean);
};

const safePollOptions = (poll: PublicChatMessage['poll']): { text: string; votes: number }[] => {
    if (!poll || !Array.isArray(poll.options)) return [];

    return poll.options.map((opt: any) => ({
        text: String(opt?.text || ''),
        votes: Number(opt?.votes || 0),
    }));
};

export default () => {
    const location = useLocation();
    const { clearFlashes, clearAndAddHttpError, addFlash } = useFlash();
    const selfUsername = useStoreState((state) => state.user.data?.username || '');
    const selfUserIdFromWindow = typeof window !== 'undefined' ? Number((window as any)?.PterodactylUser?.id || 0) : 0;

    const [conversations, setConversations] = useState<ChatConversation[]>([]);
    const [activeConversationId, setActiveConversationId] = useState<number | null>(null);

    const [messages, setMessages] = useState<PublicChatMessage[]>([]);
    const [loading, setLoading] = useState(true);
    const [sending, setSending] = useState(false);
    const [uploading, setUploading] = useState(false);
    const [dragging, setDragging] = useState(false);
    const [uploadMode, setUploadMode] = useState<'quick' | 'compressed'>('quick');
    const [pendingUploadFile, setPendingUploadFile] = useState<File | null>(null);
    const [pendingUploadTarget, setPendingUploadTarget] = useState<'composer' | 'poll'>('composer');

    const [message, setMessage] = useState('');
    const [mediaUrl, setMediaUrl] = useState('');
    const [mediaType, setMediaType] = useState<'image' | 'audio' | 'link' | ''>('');
    const [mediaName, setMediaName] = useState('');
    const [mediaMime, setMediaMime] = useState('');

    const [editingId, setEditingId] = useState<number | null>(null);
    const [editingValue, setEditingValue] = useState('');
    const [replyingTo, setReplyingTo] = useState<PublicChatMessage | null>(null);

    const [search, setSearch] = useState('');
    const [searchResult, setSearchResult] = useState<ChatUser[]>([]);

    const [groupName, setGroupName] = useState('');
    const [groupUsername, setGroupUsername] = useState('');
    const [groupCode, setGroupCode] = useState('');
    const [groupMembers, setGroupMembers] = useState<string[]>([]);
    const [groupMemberInput, setGroupMemberInput] = useState('');
    const [groupEditName, setGroupEditName] = useState('');
    const [groupEditUsername, setGroupEditUsername] = useState('');
    const [groupEditCode, setGroupEditCode] = useState('');
    const [groupEditAvatarUrl, setGroupEditAvatarUrl] = useState('');
    const [groupToolsCollapsed, setGroupToolsCollapsed] = useState(true);

    const [pollOpen, setPollOpen] = useState(false);
    const [pollQuestion, setPollQuestion] = useState('');
    const [pollOptions, setPollOptions] = useState<string[]>(['', '']);
    const [pollMediaUrl, setPollMediaUrl] = useState('');
    const [pollMediaName, setPollMediaName] = useState('');
    const [pollMediaMime, setPollMediaMime] = useState('');

    const [highlightedMessageId, setHighlightedMessageId] = useState<number | null>(null);
    const [mobilePane, setMobilePane] = useState<'chats' | 'room'>('chats');
    const [previewImageUrl, setPreviewImageUrl] = useState<string | null>(null);
    const [profilePopup, setProfilePopup] = useState<{
        id?: number;
        username: string;
        displayName: string;
        avatarUrl?: string | null;
        birthday?: string | null;
        joinedAt?: string | null;
        lastSeen?: string | null;
    } | null>(null);
    const [callState, setCallState] = useState<{
        active: boolean;
        callId: number | null;
        participants: ChatCallParticipant[];
    }>({ active: false, callId: null, participants: [] });
    const [callOpen, setCallOpen] = useState(false);
    const [inCall, setInCall] = useState(false);
    const [callLoading, setCallLoading] = useState(false);
    const [callNetState, setCallNetState] = useState<'idle' | 'connecting' | 'connected' | 'recovering'>('idle');
    const [localMicMuted, setLocalMicMuted] = useState(false);
    const [localSpeakingLevel, setLocalSpeakingLevel] = useState(0);
    const [remoteStreams, setRemoteStreams] = useState<Record<number, MediaStream>>({});
    const [isNarrowViewport, setIsNarrowViewport] = useState<boolean>(() =>
        typeof window !== 'undefined' ? window.innerWidth < 768 : false
    );
    const [incomingCallPrompt, setIncomingCallPrompt] = useState<{
        fromUserId: number;
        fromName: string;
        conversationId: number;
        callId: number | null;
    } | null>(null);

    const listRef = useRef<HTMLDivElement>(null);
    const messageRefs = useRef<Record<number, HTMLDivElement | null>>({});
    const uploadInputRef = useRef<HTMLInputElement>(null);
    const quickSendInputRef = useRef<HTMLInputElement>(null);
    const compressedSendInputRef = useRef<HTMLInputElement>(null);
    const callSinceIdRef = useRef(0);
    const callSignalProcessedRef = useRef<Set<number>>(new Set());
    const callPeersRef = useRef<Record<number, RTCPeerConnection>>({});
    const pendingIceRef = useRef<Record<number, RTCIceCandidateInit[]>>({});
    const localStreamRef = useRef<MediaStream | null>(null);
    const audioContextRef = useRef<AudioContext | null>(null);
    const analyserRef = useRef<AnalyserNode | null>(null);
    const analyserTimerRef = useRef<number | null>(null);
    const micSyncLastRef = useRef<{ muted: boolean; level: number; at: number }>({ muted: false, level: -1, at: 0 });
    const inCallRef = useRef(false);
    const callRestartRef = useRef<Record<number, { count: number; startedAt: number }>>({});
    const callRestartTimerRef = useRef<Record<number, number>>({});
    const activeConversationRef = useRef<number | null>(null);
    const loadMessagesSeqRef = useRef<number>(0);
    const initialConversationAppliedRef = useRef(false);

    const activeConversation = useMemo(
        () => conversations.find((item) => item.id === activeConversationId) || null,
        [conversations, activeConversationId]
    );
    const selfUserId = useMemo(() => {
        if (selfUserIdFromWindow > 0) return selfUserIdFromWindow;
        const me = selfUsername.toLowerCase();
        if (!me) return 0;

        const search = activeConversation ? [activeConversation, ...conversations] : conversations;
        for (const conversation of search) {
            const found = (conversation.members || []).find((member) => String(member.username || '').toLowerCase() === me);
            if (found?.id && Number(found.id) > 0) {
                return Number(found.id);
            }
        }

        return 0;
    }, [selfUserIdFromWindow, selfUsername, activeConversation, conversations]);
    const myGroupRole = useMemo(() => {
        if (!activeConversation) return null;
        const me = activeConversation.members.find((m) => m.username.toLowerCase() === selfUsername.toLowerCase());

        return me?.role || null;
    }, [activeConversation, selfUsername]);
    const foundGroups = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (q.length < 2) return [];

        return conversations
            .filter((c) => c.type === 'group')
            .filter((c) => (c.name || '').toLowerCase().includes(q) || (c.groupUsername || '').toLowerCase().includes(q) || (c.groupCode || '').toLowerCase().includes(q))
            .slice(0, 6);
    }, [search, conversations]);
    const userLastSeenMap = useMemo(() => {
        const map: Record<string, number> = {};
        for (const item of messages) {
            const key = String(item.username || '').toLowerCase();
            if (!key) continue;
            const seen = toUnixMs(item.createdAt);
            if (!seen) continue;
            map[key] = Math.max(map[key] || 0, seen);
        }

        return map;
    }, [messages]);
    const memberPresenceMap = useMemo(() => {
        const map: Record<string, { lastSeenMs: number; online: boolean }> = {};
        for (const conversation of conversations) {
            for (const member of conversation.members || []) {
                const key = String(member.username || '').toLowerCase();
                if (!key) continue;
                const fromMember = toUnixMs(member.lastSeenAt || null);
                const current = map[key];
                if (!current) {
                    map[key] = { lastSeenMs: fromMember, online: Boolean(member.isOnline) };
                    continue;
                }
                map[key] = {
                    lastSeenMs: Math.max(current.lastSeenMs, fromMember),
                    online: current.online || Boolean(member.isOnline),
                };
            }
        }

        return map;
    }, [conversations]);

    const lastId = useMemo(() => (messages.length ? messages[messages.length - 1].id : undefined), [messages]);

    const scrollToBottom = () => {
        if (!listRef.current) return;
        listRef.current.scrollTop = listRef.current.scrollHeight;
    };

    const scrollToMessage = (id: number) => {
        const node = messageRefs.current[id];
        if (!node) return;

        node.scrollIntoView({ behavior: 'smooth', block: 'center' });
        setHighlightedMessageId(id);
        window.setTimeout(() => {
            setHighlightedMessageId((current) => (current === id ? null : current));
        }, 1400);
    };

    const loadConvoList = async (preserveActive = true) => {
        const list = await getConversations();
        setConversations(list);
        const currentActive = activeConversationRef.current;
        const requestedConversation = Number(new URLSearchParams(location.search).get('conversation') || 0);

        if (list.length === 0) {
            setActiveConversationId(null);
            return;
        }

        if (!initialConversationAppliedRef.current && requestedConversation > 0 && list.some((c) => c.id === requestedConversation)) {
            initialConversationAppliedRef.current = true;
            setActiveConversationId(requestedConversation);
            return;
        }
        if (!initialConversationAppliedRef.current) {
            initialConversationAppliedRef.current = true;
        }

        if (!preserveActive || !currentActive || !list.some((c) => c.id === currentActive)) {
            setActiveConversationId(list[0].id);
        }
    };

    const loadMessages = async (conversationId: number) => {
        const seq = ++loadMessagesSeqRef.current;
        setLoading(true);
        try {
            const list = await getPublicMessages({ conversationId, limit: 80 });
            if (activeConversationRef.current !== conversationId || loadMessagesSeqRef.current !== seq) return;
            setMessages(list);
            if (list.length) {
                await markPublicMessagesRead(conversationId, list.map((m) => m.id));
            }
            clearFlashes('dashboard');
            requestAnimationFrame(scrollToBottom);
        } finally {
            if (loadMessagesSeqRef.current === seq) {
                setLoading(false);
            }
        }
    };

    const pollIncoming = async () => {
        const conversationId = activeConversationRef.current;
        if (!conversationId || !lastId) return;

        try {
            const incoming = await getPublicMessages({ conversationId, sinceId: lastId, limit: 100 });
            if (!incoming.length) return;
            if (activeConversationRef.current !== conversationId) return;

            setMessages((current) => {
                const known = new Set(current.map((m) => m.id));

                return [...current, ...incoming.filter((m) => !known.has(m.id))];
            });
            await markPublicMessagesRead(conversationId, incoming.map((m) => m.id));
            requestAnimationFrame(scrollToBottom);
        } catch {
            // silent poll failure
        }
    };

    useEffect(() => {
        (async () => {
            try {
                await loadConvoList(false);
            } catch (error) {
                clearAndAddHttpError({ key: 'dashboard', error });
                setLoading(false);
            }
        })();
    }, []);

    useEffect(() => {
        if (typeof window === 'undefined' || !('Notification' in window)) return;
        if (Notification.permission === 'default') {
            Notification.requestPermission().catch(() => {
                // ignore blocked browsers
            });
        }
    }, []);

    useEffect(() => {
        let cancelled = false;
        const beat = async () => {
            try {
                await sendPresenceHeartbeat();
            } catch {
                // keep silent, presence is best-effort
            }
            if (!cancelled) {
                loadConvoList(true).catch(() => {
                    // ignore
                });
            }
        };

        beat();
        const timer = window.setInterval(beat, PRESENCE_HEARTBEAT_MS);
        const onVisibility = () => {
            if (!document.hidden) {
                beat();
            }
        };

        document.addEventListener('visibilitychange', onVisibility);

        return () => {
            cancelled = true;
            window.clearInterval(timer);
            document.removeEventListener('visibilitychange', onVisibility);
        };
    }, []);

    useEffect(() => {
        const onResize = () => setIsNarrowViewport(window.innerWidth < 768);
        onResize();
        window.addEventListener('resize', onResize);

        return () => window.removeEventListener('resize', onResize);
    }, []);

    useEffect(() => {
        activeConversationRef.current = activeConversationId;
    }, [activeConversationId]);

    useEffect(() => {
        inCallRef.current = inCall;
    }, [inCall]);

    useEffect(() => {
        if (!activeConversationId) {
            setMessages([]);
            setLoading(false);
            setCallState({ active: false, callId: null, participants: [] });
            return;
        }

        setMessages([]);
        loadMessages(activeConversationId).catch((error) => {
            clearAndAddHttpError({ key: 'dashboard', error });
            setLoading(false);
        });

        setReplyingTo(null);
        setMobilePane('room');
        callSinceIdRef.current = 0;
        callSignalProcessedRef.current = new Set();
        setIncomingCallPrompt(null);
        setCallOpen(false);
        setCallNetState('idle');
        callRestartRef.current = {};
        Object.values(callRestartTimerRef.current).forEach((timer) => window.clearTimeout(timer));
        callRestartTimerRef.current = {};
        refreshCallState().catch(() => {
            // ignore initial call state failure
        });
    }, [activeConversationId]);

    useEffect(() => {
        return () => {
            if (!activeConversationId || !inCall) return;
            leaveConversationCall(activeConversationId).catch(() => {
                // ignore
            });
            stopAllCallMedia().catch(() => {
                // ignore
            });
        };
    }, [activeConversationId, inCall]);

    useEffect(() => {
        const timer = setInterval(() => {
            pollIncoming();
        }, 3000);

        return () => clearInterval(timer);
    }, [activeConversationId, lastId]);

    useEffect(() => {
        if (!activeConversationId) return;

        const timer = window.setInterval(() => {
            refreshCallState().catch(() => {
                // silent poll failure
            });
        }, CALL_POLL_MS);

        return () => window.clearInterval(timer);
    }, [activeConversationId, inCall, selfUserId]);

    useEffect(() => {
        return () => {
            stopAllCallMedia().catch(() => {
                // ignore
            });
        };
    }, []);

    useEffect(() => {
        if (search.trim().length < 2) {
            setSearchResult([]);
            return;
        }

        const timer = window.setTimeout(async () => {
            try {
                const list = await searchChatUsers(search.trim());
                setSearchResult(list.slice(0, 8));
            } catch {
                setSearchResult([]);
            }
        }, 280);

        return () => window.clearTimeout(timer);
    }, [search]);

    useEffect(() => {
        if (!activeConversation || activeConversation.type !== 'group') {
            setGroupEditName('');
            setGroupEditUsername('');
            setGroupEditCode('');
            setGroupEditAvatarUrl('');
            setGroupToolsCollapsed(true);
            return;
        }

        setGroupEditName(activeConversation.name || '');
        setGroupEditUsername(activeConversation.groupUsername || '');
        setGroupEditCode(activeConversation.groupCode || '');
        setGroupEditAvatarUrl(activeConversation.avatarUrl || '');
        setGroupToolsCollapsed(true);
    }, [activeConversation?.id]);

    const normalizeUploadedType = (mediaType: string): 'image' | 'audio' | 'link' =>
        mediaType === 'audio' ? 'audio' : mediaType === 'image' ? 'image' : 'link';

    const uploadPreparedFile = async (file: File, compressed = false) => {
        const prepared = compressed && isImageFile(file) ? await compressImageFile(file) : file;

        return uploadPublicMedia(prepared);
    };

    const sendUploadedAsMessage = async (
        uploaded: { url: string; mediaType: string; mediaName: string | null; mediaMime: string | null },
        fallbackName: string
    ) => {
        if (!activeConversationId) return;

        const created = await postPublicMessage({
            conversationId: activeConversationId,
            message: '',
            mediaUrl: uploaded.url,
            mediaType: normalizeUploadedType(uploaded.mediaType),
            mediaName: uploaded.mediaName || fallbackName,
            mediaMime: uploaded.mediaMime || undefined,
            replyToId: replyingTo?.id,
        });
        setMessages((current) => [...current, created]);
        setReplyingTo(null);
        requestAnimationFrame(scrollToBottom);
        await loadConvoList(true);
    };

    const onUpload = async (
        file: File | undefined,
        options?: { compressed?: boolean; quickSend?: boolean; forPoll?: boolean }
    ) => {
        if (!file) return;

        const compressed = Boolean(options?.compressed);
        const quickSend = Boolean(options?.quickSend);
        const forPoll = Boolean(options?.forPoll);

        try {
            setUploading(true);
            if (quickSend) setSending(true);

            const uploaded = await uploadPreparedFile(file, compressed);
            if (forPoll) {
                if (uploaded.mediaType !== 'image') return;
                setPollMediaUrl(uploaded.url);
                setPollMediaName(uploaded.mediaName || file.name);
                setPollMediaMime(uploaded.mediaMime || file.type || '');
                return;
            }

            if (quickSend) {
                await sendUploadedAsMessage(uploaded, file.name);
                return;
            }

            setMediaUrl(uploaded.url);
            setMediaType(normalizeUploadedType(uploaded.mediaType));
            setMediaName(uploaded.mediaName || file.name);
            setMediaMime(uploaded.mediaMime || file.type || '');
        } catch (error) {
            clearAndAddHttpError({ key: 'dashboard', error });
        } finally {
            setUploading(false);
            setSending(false);
        }
    };

    const resetComposer = () => {
        setMessage('');
        setMediaUrl('');
        setMediaType('');
        setMediaName('');
        setMediaMime('');
        setReplyingTo(null);
    };

    const uploadPollImage = async (file?: File, compressed = false) => {
        await onUpload(file, { forPoll: true, compressed });
    };

    const handleSend = async (event?: React.FormEvent) => {
        event?.preventDefault();
        if (!activeConversationId) return;
        if (!message.trim() && !mediaUrl.trim()) return;

        try {
            setSending(true);
            const created = await postPublicMessage({
                conversationId: activeConversationId,
                message: message.trim(),
                mediaUrl: mediaUrl.trim() || undefined,
                mediaType: mediaType || undefined,
                mediaName: mediaName || undefined,
                mediaMime: mediaMime || undefined,
                replyToId: replyingTo?.id,
            });

            setMessages((current) => [...current, created]);
            resetComposer();
            requestAnimationFrame(scrollToBottom);
            await loadConvoList(true);
        } catch (error) {
            clearAndAddHttpError({ key: 'dashboard', error });
        } finally {
            setSending(false);
        }
    };

    const handleCreatePoll = async () => {
        if (!activeConversationId) return;

        const cleanQuestion = pollQuestion.trim();
        const cleanOptions = pollOptions.map((v) => v.trim()).filter(Boolean);
        if (!cleanQuestion || cleanOptions.length < 2) return;

        try {
            setSending(true);
            const created = await createPollMessage({
                conversationId: activeConversationId,
                question: cleanQuestion,
                options: cleanOptions,
                mediaUrl: pollMediaUrl || undefined,
                mediaName: pollMediaName || undefined,
                mediaMime: pollMediaMime || undefined,
            });
            setMessages((current) => [...current, created]);
            setPollQuestion('');
            setPollOptions(['', '']);
            setPollMediaUrl('');
            setPollMediaName('');
            setPollMediaMime('');
            setPollOpen(false);
            requestAnimationFrame(scrollToBottom);
            await loadConvoList(true);
        } catch (error) {
            clearAndAddHttpError({ key: 'dashboard', error });
        } finally {
            setSending(false);
        }
    };

    const handleVote = async (messageId: number, optionIndex: number) => {
        try {
            const updated = await votePoll(messageId, optionIndex);
            setMessages((current) => current.map((m) => (m.id === messageId ? updated : m)));
        } catch (error) {
            clearAndAddHttpError({ key: 'dashboard', error });
        }
    };

    const reactToMessage = async (messageId: number, emoji: string) => {
        try {
            const updated = await toggleReaction(messageId, emoji);
            setMessages((current) => current.map((m) => (m.id === messageId ? updated : m)));
        } catch (error) {
            clearAndAddHttpError({ key: 'dashboard', error });
        }
    };

    const startEdit = (item: PublicChatMessage) => {
        setEditingId(item.id);
        setEditingValue(item.body || '');
    };

    const saveEdit = async (id: number) => {
        try {
            const updated = await editPublicMessage(id, editingValue);
            setMessages((current) => current.map((m) => (m.id === id ? updated : m)));
            setEditingId(null);
            setEditingValue('');
        } catch (error) {
            clearAndAddHttpError({ key: 'dashboard', error });
        }
    };

    const removeMessage = async (id: number) => {
        try {
            await deletePublicMessage(id);
            setMessages((current) => current.filter((m) => m.id !== id));
            if (replyingTo?.id === id) setReplyingTo(null);
        } catch (error) {
            clearAndAddHttpError({ key: 'dashboard', error });
        }
    };

    const openPrivateChat = async (username: string) => {
        if (username.toLowerCase() === selfUsername.toLowerCase()) {
            return;
        }
        try {
            const id = await createPrivateConversation(username);
            await loadConvoList(false);
            if (id > 0) setActiveConversationId(id);
            setSearch('');
            setSearchResult([]);
        } catch (error) {
            const status = (error as any)?.response?.status;
            if (status === 422) {
                return;
            }
            clearAndAddHttpError({ key: 'dashboard', error });
        }
    };

    const toggleMember = (username: string) => {
        setGroupMembers((current) =>
            current.includes(username) ? current.filter((name) => name !== username) : [...current, username]
        );
    };

    const makeGroup = async () => {
        const name = groupName.trim();
        const username = groupUsername.trim().toLowerCase();
        if (!name || !username) return;

        try {
            const id = await createGroupConversation(name, username, groupMembers, groupCode.trim() || undefined);
            setGroupName('');
            setGroupUsername('');
            setGroupCode('');
            setGroupMembers([]);
            setSearch('');
            setSearchResult([]);
            await loadConvoList(false);
            if (id > 0) setActiveConversationId(id);
        } catch (error) {
            clearAndAddHttpError({ key: 'dashboard', error });
        }
    };

    const manageGroup = async (action: 'add' | 'kick' | 'ban' | 'promote' | 'demote' | 'rename', payload: any) => {
        if (!activeConversationId) return;
        try {
            if (action === 'add') {
                await addGroupMember(activeConversationId, String(payload.username || ''));
            } else if (action === 'kick') {
                await kickGroupMember(activeConversationId, Number(payload.memberId));
            } else if (action === 'ban') {
                await banGroupMember(activeConversationId, Number(payload.memberId));
            } else if (action === 'promote') {
                await setGroupAdmin(activeConversationId, Number(payload.memberId), true);
            } else if (action === 'demote') {
                await setGroupAdmin(activeConversationId, Number(payload.memberId), false);
            } else if (action === 'rename') {
                await updateGroupConversation(activeConversationId, payload);
            }
            await loadConvoList(true);
        } catch (error) {
            clearAndAddHttpError({ key: 'dashboard', error });
        }
    };

    const convLabel = (conversation: ChatConversation) => {
        if (conversation.type === 'global') return 'GLOBAL';
        if (conversation.type === 'private') return 'DM';

        return 'GROUP';
    };

    const toggleConversationNotificationMute = async () => {
        if (!activeConversationId || !activeConversation) return;
        try {
            if (activeConversation.notificationMutedUntil) {
                await unmuteConversationNotifications(activeConversationId);
            } else {
                await muteConversationNotifications(activeConversationId, 60 * 24 * 7);
            }
            await loadConvoList(true);
        } catch (error) {
            clearAndAddHttpError({ key: 'dashboard', error });
        }
    };

    const resolvePresence = (username: string, fallback?: string | null) => {
        const key = username.toLowerCase();
        if (key === selfUsername.toLowerCase()) {
            return {
                lastSeenMs: Date.now(),
                online: true,
                text: 'online',
            };
        }
        const fromMember = memberPresenceMap[key];
        if (fromMember) {
            const lastSeenMs = Math.max(fromMember.lastSeenMs || 0, toUnixMs(fallback));
            if (fromMember.online) {
                return {
                    lastSeenMs,
                    online: true,
                    text: 'online',
                };
            }
            if (!lastSeenMs) {
                return {
                    lastSeenMs: 0,
                    online: false,
                    text: 'last seen unknown',
                };
            }
            const date = new Date(lastSeenMs);
            if (Number.isNaN(date.getTime())) {
                return {
                    lastSeenMs: 0,
                    online: false,
                    text: 'last seen unknown',
                };
            }
            const today = format(new Date(), 'yyyy-MM-dd');
            const day = format(date, 'yyyy-MM-dd');
            const stamp = day === today ? format(date, 'HH:mm') : format(date, 'MMM d, yyyy HH:mm');

            return {
                lastSeenMs,
                online: false,
                text: `last seen ${stamp}`,
            };
        }
        const lastSeenMs = Math.max(userLastSeenMap[key] || 0, toUnixMs(fallback));

        return {
            lastSeenMs,
            ...presenceLabel(lastSeenMs),
        };
    };

    const openProfile = (payload: {
        id?: number;
        username: string;
        displayName: string;
        avatarUrl?: string | null;
        birthday?: string | null;
        joinedAt?: string | null;
        lastSeen?: string | null;
    }) => {
        setProfilePopup({
            id: payload.id,
            username: payload.username,
            displayName: payload.displayName,
            avatarUrl: payload.avatarUrl || null,
            birthday: payload.birthday || null,
            joinedAt: payload.joinedAt || null,
            lastSeen: payload.lastSeen || null,
        });
    };

    const stopLocalAudioAnalyser = () => {
        if (analyserTimerRef.current !== null) {
            window.clearInterval(analyserTimerRef.current);
            analyserTimerRef.current = null;
        }
        if (audioContextRef.current) {
            try {
                audioContextRef.current.close();
            } catch {
                // ignore
            }
            audioContextRef.current = null;
        }
        analyserRef.current = null;
    };

    const clearPeerRestartTimer = (peerUserId: number) => {
        const timer = callRestartTimerRef.current[peerUserId];
        if (timer) {
            window.clearTimeout(timer);
            delete callRestartTimerRef.current[peerUserId];
        }
    };

    const canRestartPeer = (peerUserId: number): boolean => {
        const now = Date.now();
        const meta = callRestartRef.current[peerUserId];
        if (!meta || now - meta.startedAt > CALL_RESTART_WINDOW_MS) {
            callRestartRef.current[peerUserId] = { count: 0, startedAt: now };
            return true;
        }

        return meta.count < CALL_MAX_RESTART_PER_PEER;
    };

    const markPeerRestartAttempt = (peerUserId: number) => {
        const now = Date.now();
        const meta = callRestartRef.current[peerUserId];
        if (!meta || now - meta.startedAt > CALL_RESTART_WINDOW_MS) {
            callRestartRef.current[peerUserId] = { count: 1, startedAt: now };
            return;
        }
        meta.count += 1;
    };

    const tuneAudioSender = async (sender: RTCRtpSender) => {
        try {
            const params = sender.getParameters?.();
            if (!params) return;
            params.encodings = params.encodings && params.encodings.length ? params.encodings : [{}];
            params.encodings = params.encodings.map((encoding) => ({
                ...encoding,
                maxBitrate: Math.max(24_000, Number(encoding.maxBitrate || 0)),
                dtx: 'enabled',
                ptime: 20,
            }));
            params.degradationPreference = 'maintain-framerate';
            await sender.setParameters?.(params);
        } catch {
            // browser can reject unsupported fields
        }
    };

    const cleanupPeer = (peerUserId: number) => {
        clearPeerRestartTimer(peerUserId);
        delete callRestartRef.current[peerUserId];
        const peer = callPeersRef.current[peerUserId];
        if (peer) {
            try {
                peer.ontrack = null;
                peer.onicecandidate = null;
                peer.onconnectionstatechange = null;
                peer.oniceconnectionstatechange = null;
                peer.close();
            } catch {
                // ignore
            }
            delete callPeersRef.current[peerUserId];
        }
        delete pendingIceRef.current[peerUserId];
        setRemoteStreams((current) => {
            const next = { ...current };
            delete next[peerUserId];

            return next;
        });
    };

    const stopAllCallMedia = async () => {
        Object.values(callRestartTimerRef.current).forEach((timer) => window.clearTimeout(timer));
        callRestartTimerRef.current = {};
        callRestartRef.current = {};
        Object.keys(callPeersRef.current).forEach((id) => cleanupPeer(Number(id)));
        if (localStreamRef.current) {
            localStreamRef.current.getTracks().forEach((track) => track.stop());
            localStreamRef.current = null;
        }
        stopLocalAudioAnalyser();
        setRemoteStreams({});
        setLocalSpeakingLevel(0);
        setLocalMicMuted(false);
        setInCall(false);
        setCallNetState('idle');
    };

    const syncMicStatus = async (force = false, speakingLevel = localSpeakingLevel) => {
        if (!activeConversationId || !inCall) return;
        const now = Date.now();
        const roundedLevel = clampLevel(speakingLevel);
        const last = micSyncLastRef.current;
        const shouldSend =
            force ||
            last.muted !== localMicMuted ||
            Math.abs(last.level - roundedLevel) >= 6 ||
            now - last.at >= MIC_SYNC_MS;
        if (!shouldSend) return;

        micSyncLastRef.current = { muted: localMicMuted, level: roundedLevel, at: now };
        try {
            await updateCallMic({
                conversationId: activeConversationId,
                muted: localMicMuted,
                speakingLevel: roundedLevel,
            });
        } catch {
            // no-op
        }
    };

    const beginLocalAudioAnalyser = () => {
        if (!localStreamRef.current || analyserTimerRef.current !== null) return;

        const Ctx = window.AudioContext || (window as any).webkitAudioContext;
        if (!Ctx) return;

        try {
            const context = new Ctx();
            const source = context.createMediaStreamSource(localStreamRef.current);
            const analyser = context.createAnalyser();
            analyser.fftSize = 512;
            source.connect(analyser);
            audioContextRef.current = context;
            analyserRef.current = analyser;

            const data = new Uint8Array(analyser.frequencyBinCount);
            analyserTimerRef.current = window.setInterval(() => {
                if (!analyserRef.current) return;
                analyserRef.current.getByteFrequencyData(data);
                let sum = 0;
                for (let i = 0; i < data.length; i++) sum += data[i];
                const avg = sum / Math.max(1, data.length);
                const level = localMicMuted ? 0 : clampLevel((avg / 255) * 100);
                setLocalSpeakingLevel(level);
                syncMicStatus(false, level);
            }, 220);
        } catch {
            // browser can block audio context until interaction; ignore
        }
    };

    const ensureLocalAudio = async (): Promise<MediaStream> => {
        if (localStreamRef.current) return localStreamRef.current;

        let stream: MediaStream;
        try {
            stream = await navigator.mediaDevices.getUserMedia({
                audio: {
                    echoCancellation: true,
                    noiseSuppression: true,
                    autoGainControl: true,
                    channelCount: 1,
                    sampleRate: 48000,
                    sampleSize: 16,
                    latency: 0.02,
                },
                video: false,
            });
        } catch {
            stream = await navigator.mediaDevices.getUserMedia({
                audio: true,
                video: false,
            });
        }
        localStreamRef.current = stream;
        stream.getAudioTracks().forEach((track) => {
            track.enabled = !localMicMuted;
            track.contentHint = 'speech';
        });
        beginLocalAudioAnalyser();

        return stream;
    };

    const restartIceWithOffer = async (peerUserId: number): Promise<void> => {
        const conversationId = activeConversationRef.current;
        if (!conversationId || !inCallRef.current || peerUserId === selfUserId) return;
        if (!canRestartPeer(peerUserId)) return;

        const pc = await ensurePeerConnection(peerUserId);
        if (pc.signalingState !== 'stable') return;

        markPeerRestartAttempt(peerUserId);
        setCallNetState('recovering');
        const offer = await pc.createOffer({ iceRestart: true });
        await pc.setLocalDescription(offer);
        await sendCallSignal({
            conversationId,
            type: 'offer',
            toUserId: peerUserId,
            signalPayload: { sdp: offer.sdp, type: offer.type, ice_restart: true },
        });
    };

    const schedulePeerRestart = (peerUserId: number) => {
        clearPeerRestartTimer(peerUserId);
        callRestartTimerRef.current[peerUserId] = window.setTimeout(() => {
            restartIceWithOffer(peerUserId).catch(() => {
                // keep call loop alive
            });
        }, CALL_RESTART_DEBOUNCE_MS);
    };

    const ensurePeerConnection = async (peerUserId: number): Promise<RTCPeerConnection> => {
        const existing = callPeersRef.current[peerUserId];
        if (existing) return existing;

        const stream = await ensureLocalAudio();
        const pc = new RTCPeerConnection(buildRtcConfiguration());
        callPeersRef.current[peerUserId] = pc;
        setCallNetState('connecting');

        stream.getAudioTracks().forEach((track) => {
            const sender = pc.addTrack(track, stream);
            tuneAudioSender(sender).catch(() => {
                // ignore unsupported sender tuning
            });
        });
        pc.ontrack = (event) => {
            const streamIn = event.streams?.[0];
            if (!streamIn) return;
            setRemoteStreams((current) => ({ ...current, [peerUserId]: streamIn }));
        };
        pc.onicecandidate = (event) => {
            if (!event.candidate || !activeConversationId) return;
            sendCallSignal({
                conversationId: activeConversationId,
                type: 'ice',
                toUserId: peerUserId,
                signalPayload: event.candidate.toJSON(),
            }).catch(() => {
                // silent
            });
        };
        pc.onconnectionstatechange = () => {
            if (pc.connectionState === 'connected') {
                setCallNetState('connected');
                clearPeerRestartTimer(peerUserId);
                return;
            }
            if (pc.connectionState === 'disconnected') {
                schedulePeerRestart(peerUserId);
                return;
            }
            if (pc.connectionState === 'failed') {
                restartIceWithOffer(peerUserId).catch(() => {
                    cleanupPeer(peerUserId);
                });
                return;
            }
            if (pc.connectionState === 'closed') {
                cleanupPeer(peerUserId);
                return;
            }
        };
        pc.oniceconnectionstatechange = () => {
            if (pc.iceConnectionState === 'failed') {
                restartIceWithOffer(peerUserId).catch(() => {
                    // ignore
                });
            } else if (pc.iceConnectionState === 'connected' || pc.iceConnectionState === 'completed') {
                setCallNetState('connected');
                clearPeerRestartTimer(peerUserId);
            }
        };

        const pending = pendingIceRef.current[peerUserId] || [];
        if (pending.length > 0) {
            for (const candidate of pending) {
                try {
                    await pc.addIceCandidate(new RTCIceCandidate(candidate));
                } catch {
                    // ignore
                }
            }
            pendingIceRef.current[peerUserId] = [];
        }

        return pc;
    };

    const sendOfferToPeer = async (peerUserId: number) => {
        if (!activeConversationId || peerUserId === selfUserId) return;

        const pc = await ensurePeerConnection(peerUserId);
        const offer = await pc.createOffer();
        await pc.setLocalDescription(offer);
        await sendCallSignal({
            conversationId: activeConversationId,
            type: 'offer',
            toUserId: peerUserId,
            signalPayload: { sdp: offer.sdp, type: offer.type },
        });
    };

    const handleIncomingCallSignal = async (signal: any) => {
        if (!activeConversationId || !signal || !signal.type) return;
        const fromUserId = Number(signal.fromUserId || 0);
        if (!fromUserId) return;
        const fromMemberById = activeConversation?.members.find((m) => m.id === fromUserId) || null;
        const isSelfSignal =
            fromUserId === selfUserId ||
            (fromMemberById && String(fromMemberById.username || '').toLowerCase() === selfUsername.toLowerCase());
        if (isSelfSignal) return;

        try {
            if (signal.type === 'join') {
                if (selfUserId < fromUserId && inCall) {
                    await sendOfferToPeer(fromUserId);
                }
                return;
            }

            if (signal.type === 'ring') {
                if (activeConversation?.type !== 'private') return;
                if (inCall) {
                    await sendCallSignal({
                        conversationId: activeConversationId,
                        type: 'ring_response',
                        toUserId: fromUserId,
                        signalPayload: { status: 'busy' },
                    });
                    return;
                }
                const fromMember = fromMemberById || activeConversation.members.find((m) => m.id === fromUserId);
                const fromName = fromMember?.displayName || fromMember?.username || `User ${fromUserId}`;
                setIncomingCallPrompt({
                    fromUserId,
                    fromName,
                    conversationId: activeConversationId,
                    callId: callState.callId,
                });
                notifyIncomingCall(fromName);
                return;
            }

            if (signal.type === 'ring_response') {
                const status = String(signal.payload?.status || '').toLowerCase();
                if (status === 'accepted') {
                    addFlash({
                        key: 'dashboard',
                        type: 'success',
                        title: 'Call',
                        message: 'Call accepted.',
                    });
                    return;
                }
                if (status === 'denied' || status === 'busy') {
                    addFlash({
                        key: 'dashboard',
                        type: 'error',
                        title: 'Call',
                        message: status === 'busy' ? 'User is busy right now.' : 'Call was denied.',
                    });
                    await leaveCurrentCall(true);
                    return;
                }
                return;
            }

            if (signal.type === 'leave') {
                cleanupPeer(fromUserId);
                return;
            }

            if (signal.type === 'end') {
                await stopAllCallMedia();
                setCallState({ active: false, callId: null, participants: [] });
                setCallOpen(false);
                callSinceIdRef.current = 0;
                callSignalProcessedRef.current = new Set();
                return;
            }

            if (signal.type === 'offer') {
                const pc = await ensurePeerConnection(fromUserId);
                const remote = signal.payload || {};
                if (!remote?.sdp) return;
                await pc.setRemoteDescription(new RTCSessionDescription({ type: 'offer', sdp: String(remote.sdp) }));
                const answer = await pc.createAnswer();
                await pc.setLocalDescription(answer);
                await sendCallSignal({
                    conversationId: activeConversationId,
                    type: 'answer',
                    toUserId: fromUserId,
                    signalPayload: { sdp: answer.sdp, type: answer.type },
                });
                return;
            }

            if (signal.type === 'answer') {
                const pc = await ensurePeerConnection(fromUserId);
                const remote = signal.payload || {};
                if (!remote?.sdp) return;
                await pc.setRemoteDescription(new RTCSessionDescription({ type: 'answer', sdp: String(remote.sdp) }));
                return;
            }

            if (signal.type === 'ice') {
                const candidate = signal.payload || null;
                if (!candidate) return;
                const peer = callPeersRef.current[fromUserId];
                if (!peer || !peer.remoteDescription) {
                    pendingIceRef.current[fromUserId] = [...(pendingIceRef.current[fromUserId] || []), candidate];
                    return;
                }
                await peer.addIceCandidate(new RTCIceCandidate(candidate));
            }
        } catch {
            // keep signaling loop resilient
        }
    };

    const refreshCallState = async () => {
        if (!activeConversationId) return;

        const state = await getCallState(activeConversationId, callSinceIdRef.current || undefined);
        setCallState({
            active: Boolean(state.active && state.call),
            callId: state.call?.id || null,
            participants: state.call?.participants || [],
        });
        callSinceIdRef.current = Math.max(callSinceIdRef.current, state.lastSignalId || 0);

        if (!state.active || !state.call) {
            setCallNetState('idle');
            if (inCall) {
                await stopAllCallMedia();
            }
            return;
        }

        if (inCall) {
            for (const member of state.call.participants) {
                if (member.id !== selfUserId && selfUserId < member.id && !callPeersRef.current[member.id]) {
                    sendOfferToPeer(member.id).catch(() => {
                        // silent
                    });
                }
            }
        }

        for (const signal of state.signals || []) {
            const sid = Number(signal.id || 0);
            if (!sid || callSignalProcessedRef.current.has(sid)) continue;
            callSignalProcessedRef.current.add(sid);
            await handleIncomingCallSignal(signal);
        }
    };

    const activeDmPeer = (): ChatUser | null => {
        if (!activeConversation || activeConversation.type !== 'private') return null;
        if (!selfUsername.trim()) return null;

        const me = selfUsername.toLowerCase();
        return activeConversation.members.find((m) => String(m.username || '').toLowerCase() !== me) || null;
    };

    const notifyIncomingCall = (fromName: string) => {
        if (typeof window === 'undefined' || !('Notification' in window)) return;
        if (Notification.permission !== 'granted') return;
        try {
            const n = new Notification('Incoming Direct Call', {
                body: `${fromName} is calling you`,
                tag: `dm-call-${Date.now()}`,
                renotify: true,
            });
            window.setTimeout(() => n.close(), 6000);
        } catch {
            // ignore
        }
    };

    const startOrJoinCall = async () => {
        if (!activeConversationId) return;
        setCallLoading(true);
        try {
            setCallNetState('connecting');
            await ensureLocalAudio();
            const current = await getCallState(activeConversationId, callSinceIdRef.current || undefined);
            if (current.active && current.call) {
                await joinConversationCall(activeConversationId);
            } else {
                await startConversationCall(activeConversationId);
            }
            setInCall(true);
            setCallOpen(true);
            await syncMicStatus(true, 0);
            await refreshCallState();
        } catch (error) {
            clearAndAddHttpError({ key: 'dashboard', error });
        } finally {
            setCallLoading(false);
        }
    };

    const startDirectCallInvite = async () => {
        if (!activeConversationId) return;
        const peer = activeDmPeer();
        if (!peer || Number(peer.id || 0) <= 0) return;
        if (selfUserId > 0 && Number(peer.id) === selfUserId) return;

        setCallLoading(true);
        try {
            setCallNetState('connecting');
            await ensureLocalAudio();
            const current = await getCallState(activeConversationId, callSinceIdRef.current || undefined);
            if (current.active && current.call) {
                await joinConversationCall(activeConversationId);
            } else {
                await startConversationCall(activeConversationId);
            }
            await sendCallSignal({
                conversationId: activeConversationId,
                type: 'ring',
                toUserId: peer.id,
                signalPayload: {
                    from_user_id: selfUserId,
                    from_name: selfUsername,
                },
            });
            setInCall(true);
            setCallOpen(true);
            await syncMicStatus(true, 0);
            await refreshCallState();
        } catch (error) {
            clearAndAddHttpError({ key: 'dashboard', error });
        } finally {
            setCallLoading(false);
        }
    };

    const respondIncomingCall = async (decision: 'accept' | 'denied' | 'busy' | 'ignore') => {
        const prompt = incomingCallPrompt;
        setIncomingCallPrompt(null);
        if (!prompt || !activeConversationId || prompt.conversationId !== activeConversationId) return;

        if (decision === 'ignore') {
            return;
        }

        if (decision === 'accept') {
            try {
                setCallNetState('connecting');
                await ensureLocalAudio();
                await joinConversationCall(prompt.conversationId);
                await sendCallSignal({
                    conversationId: prompt.conversationId,
                    type: 'ring_response',
                    toUserId: prompt.fromUserId,
                    signalPayload: { status: 'accepted' },
                });
                setInCall(true);
                setCallOpen(true);
                await syncMicStatus(true, 0);
                await refreshCallState();
            } catch (error) {
                clearAndAddHttpError({ key: 'dashboard', error });
            }
            return;
        }

        try {
            await sendCallSignal({
                conversationId: prompt.conversationId,
                type: 'ring_response',
                toUserId: prompt.fromUserId,
                signalPayload: { status: decision },
            });
        } catch {
            // ignore
        }
    };

    const leaveCurrentCall = async (endForAll = false) => {
        if (!activeConversationId) return;
        try {
            if (endForAll) {
                await endConversationCall(activeConversationId);
            } else {
                await leaveConversationCall(activeConversationId);
            }
        } catch {
            // ignore network error, still cleanup local resources
        } finally {
            await stopAllCallMedia();
            setCallOpen(false);
            setCallNetState('idle');
            await refreshCallState().catch(() => {
                // ignore
            });
        }
    };

    const toggleLocalMic = async () => {
        const next = !localMicMuted;
        setLocalMicMuted(next);
        if (localStreamRef.current) {
            localStreamRef.current.getAudioTracks().forEach((track) => {
                track.enabled = !next;
            });
        }
        if (next) setLocalSpeakingLevel(0);
        await syncMicStatus(true, next ? 0 : localSpeakingLevel);
    };

    const popupTargetMuted = useMemo(() => {
        if (!profilePopup || !activeConversation) return false;
        const member = activeConversation.members.find((m) => (profilePopup.id ? m.id === profilePopup.id : m.username === profilePopup.username));
        if (!member?.mutedUntil) return false;
        const expires = toUnixMs(member.mutedUntil);
        if (!expires) return true;

        return expires > Date.now();
    }, [profilePopup, activeConversation]);

    const handlePopupMessage = async () => {
        if (!profilePopup?.username) return;
        await openPrivateChat(profilePopup.username);
        setProfilePopup(null);
    };

    const handlePopupCall = async () => {
        if (!activeConversationId) return;
        if (inCall) {
            setCallOpen(true);
        } else if (activeConversation?.type === 'private') {
            await startDirectCallInvite();
        } else {
            await startOrJoinCall();
        }
        setProfilePopup(null);
    };
    const handleHeaderCall = async () => {
        if (!activeConversationId) return;
        if (inCall) {
            setCallOpen((value) => !value);
            return;
        }
        if (activeConversation?.type === 'private' && !callState.active) {
            await startDirectCallInvite();
            return;
        }
        await startOrJoinCall();
    };

    const handlePopupMuteToggle = async () => {
        if (!activeConversation || !profilePopup?.id) return;
        if (profilePopup.username.toLowerCase() === selfUsername.toLowerCase()) return;
        try {
            if (popupTargetMuted) {
                await unmuteChatMember(activeConversation.id, profilePopup.id);
            } else {
                await muteChatMember(activeConversation.id, profilePopup.id);
            }
            await loadConvoList(true);
        } catch (error) {
            clearAndAddHttpError({ key: 'dashboard', error });
        }
    };

    const conversationAvatar = (conversation: ChatConversation): { label: string; src: string | null; online: boolean } => {
        if (conversation.avatarUrl) {
            return {
                label: conversation.name,
                src: conversation.avatarUrl,
                online: false,
            };
        }
        if (conversation.type === 'private') {
            const other = conversation.members.find((m) => m.username.toLowerCase() !== selfUsername.toLowerCase());
            if (other) {
                const presence = resolvePresence(other.username, conversation.lastMessageAt);
                return {
                    label: other.displayName || other.username,
                    src: other.avatarUrl || null,
                    online: presence.online,
                };
            }
        }

        return {
            label: conversation.name,
            src: null,
            online: false,
        };
    };
    const dmPeer = useMemo(() => {
        if (!activeConversation || activeConversation.type !== 'private') return null;

        return activeConversation.members.find((m) => m.username.toLowerCase() !== selfUsername.toLowerCase()) || null;
    }, [activeConversation, selfUsername]);
    const dmPresence = useMemo(() => {
        if (!dmPeer) return null;

        return resolvePresence(dmPeer.username, activeConversation?.lastMessageAt || null);
    }, [dmPeer, activeConversation?.lastMessageAt, userLastSeenMap]);
    const popupPresence = useMemo(() => {
        if (!profilePopup) return null;

        return resolvePresence(profilePopup.username, profilePopup.lastSeen || null);
    }, [profilePopup, userLastSeenMap]);
    const callParticipants = useMemo(() => callState.participants || [], [callState.participants]);
    const callMaxVisible = isNarrowViewport ? 3 : 5;
    const visibleCallParticipants = useMemo(() => {
        const sorted = [...callParticipants].sort((a, b) => {
            const aLevel = a.id === selfUserId ? localSpeakingLevel : clampLevel(a.speakingLevel || 0);
            const bLevel = b.id === selfUserId ? localSpeakingLevel : clampLevel(b.speakingLevel || 0);
            if (bLevel !== aLevel) return bLevel - aLevel;

            return toUnixMs(a.joinedAt || null) - toUnixMs(b.joinedAt || null);
        });

        return sorted.slice(0, callMaxVisible);
    }, [callParticipants, callMaxVisible, selfUserId, localSpeakingLevel]);
    const callSelfParticipant = useMemo(
        () => callParticipants.find((participant) => participant.id === selfUserId) || null,
        [callParticipants, selfUserId]
    );
    const callNetLabel = useMemo(() => {
        if (callNetState === 'connected') return 'Connected';
        if (callNetState === 'recovering') return 'Reconnecting...';
        if (callNetState === 'connecting') return 'Connecting...';
        return 'Idle';
    }, [callNetState]);

    return (
        <Root>
            <Sidebar css={mobilePane === 'room' ? tw`hidden lg:flex` : undefined}>
                <SideHeader>
                    <div css={tw`flex items-center justify-between gap-2`}>
                        <SideTitle>Chats</SideTitle>
                    </div>
                </SideHeader>

                <SideBlock>
                    <Input
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder={'Find username...'}
                    />
                    {searchResult.length > 0 && (
                        <div css={tw`mt-2 space-y-1 max-h-40 overflow-y-auto`}>
                            {searchResult.map((user) => (
                                <div key={user.id} css={tw`flex items-center justify-between rounded bg-neutral-900 px-2 py-1`}>
                                    <button
                                        type={'button'}
                                        css={tw`min-w-0 flex items-center gap-2 text-left`}
                                        onClick={() =>
                                            openProfile({
                                                id: user.id,
                                                username: user.username,
                                                displayName: user.displayName,
                                                avatarUrl: user.avatarUrl,
                                                birthday: user.birthday || null,
                                                joinedAt: user.createdAt || null,
                                                lastSeen: null,
                                            })
                                        }
                                    >
                                        <div css={tw`w-7 h-7 rounded-full overflow-hidden`}>
                                            {user.avatarUrl ? (
                                                <AvatarImage src={user.avatarUrl} alt={user.displayName || user.username} />
                                            ) : (
                                                <AvatarFallback>{avatarForName(user.displayName || user.username)}</AvatarFallback>
                                            )}
                                        </div>
                                        <div css={tw`min-w-0`}>
                                            <p css={tw`text-xs text-neutral-100 truncate`}>{user.displayName}</p>
                                            <p css={tw`text-[11px] text-neutral-400 truncate`}>@{user.username}</p>
                                        </div>
                                    </button>
                                    <div css={tw`flex items-center gap-1 ml-2`}>
                                        {user.username.toLowerCase() !== selfUsername.toLowerCase() && (
                                            <Small type={'button'} onClick={() => openPrivateChat(user.username)}>
                                                DM
                                            </Small>
                                        )}
                                        <Small type={'button'} onClick={() => toggleMember(user.username)}>
                                            {groupMembers.includes(user.username) ? 'Unpick' : 'Group'}
                                        </Small>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                    {foundGroups.length > 0 && (
                        <div css={tw`mt-2 space-y-1 max-h-36 overflow-y-auto`}>
                            {foundGroups.map((group) => (
                                <div key={`found-group-${group.id}`} css={tw`flex items-center justify-between rounded bg-neutral-900 px-2 py-1`}>
                                    <div css={tw`min-w-0`}>
                                        <p css={tw`text-xs text-neutral-100 truncate`}>{group.name}</p>
                                        <p css={tw`text-[11px] text-neutral-400 truncate`}>@{group.groupUsername || 'group'} • {group.groupCode || '-'}</p>
                                    </div>
                                    <Small
                                        type={'button'}
                                        onClick={() => {
                                            setActiveConversationId(group.id);
                                            setMobilePane('room');
                                        }}
                                    >
                                        Open
                                    </Small>
                                </div>
                            ))}
                        </div>
                    )}
                    <div css={tw`mt-2 space-y-2`}>
                        <p css={tw`text-[11px] text-neutral-400`}>Create group</p>
                        <Input
                            value={groupName}
                            onChange={(e) => setGroupName(e.target.value)}
                            placeholder={'Group name (e.g. Developer Team)'}
                        />
                        <Input
                            value={groupUsername}
                            onChange={(e) => setGroupUsername(e.target.value)}
                            placeholder={'Group username (e.g. devteam)'}
                        />
                        <div css={tw`flex items-stretch gap-2`}>
                            <Input
                                value={groupCode}
                                onChange={(e) => setGroupCode(e.target.value)}
                                placeholder={'Group code (optional)'}
                            />
                            <Button
                                type={'button'}
                                size={'xsmall'}
                                onClick={makeGroup}
                                disabled={!groupName.trim() || !groupUsername.trim()}
                            >
                                Create
                            </Button>
                        </div>
                    </div>
                    {groupMembers.length > 0 && (
                        <div css={tw`mt-2 flex flex-wrap gap-1`}>
                            {groupMembers.map((username) => (
                                <Tag key={username}>@{username}</Tag>
                            ))}
                        </div>
                    )}
                </SideBlock>

                <SideList>
                    {conversations.map((conversation) => (
                        <ConvButton
                            key={conversation.id}
                            type={'button'}
                            style={
                                activeConversationId === conversation.id
                                    ? {
                                          background: 'rgba(139, 92, 246, 0.12)',
                                          borderColor: 'rgba(139, 92, 246, 0.58)',
                                          boxShadow: 'inset 2px 0 0 #8b5cf6',
                                      }
                                    : undefined
                            }
                            onClick={() => {
                            setActiveConversationId(conversation.id);
                            setMobilePane('room');
                        }}
                        >
                            <div css={tw`flex items-center justify-between gap-2`}>
                                <div css={tw`flex items-center gap-2 min-w-0`}>
                                    <div css={tw`w-8 h-8 rounded-full overflow-hidden flex-shrink-0 relative`}>
                                        {conversationAvatar(conversation).src ? (
                                            <AvatarImage src={conversationAvatar(conversation).src || ''} alt={conversationAvatar(conversation).label} />
                                        ) : (
                                            <AvatarFallback>{avatarForName(conversationAvatar(conversation).label)}</AvatarFallback>
                                        )}
                                        {conversationAvatar(conversation).online && <OnlineDot />}
                                    </div>
                                    <p css={tw`text-sm text-neutral-100 truncate`}>{conversation.name}</p>
                                </div>
                                <Tag>{convLabel(conversation)}</Tag>
                            </div>
                            <p css={tw`text-[11px] text-neutral-400 mt-1`}>
                                {conversation.type === 'private' && conversation.members.length
                                    ? resolvePresence(
                                          (conversation.members.find((m) => m.username.toLowerCase() !== selfUsername.toLowerCase()) || conversation.members[0]).username,
                                          conversation.lastMessageAt
                                      ).text
                                    : conversation.lastMessageAt
                                    ? safeTime(conversation.lastMessageAt)
                                    : 'No messages yet'}
                            </p>
                        </ConvButton>
                    ))}
                </SideList>
            </Sidebar>

            <Main css={mobilePane === 'chats' ? tw`hidden lg:flex` : undefined}>
                <MainHeader>
                    <div css={tw`min-w-0 flex-1 flex items-center gap-3`}>
                        {activeConversation && (
                            <div css={tw`w-9 h-9 rounded-full overflow-hidden flex-shrink-0 relative`}>
                                {conversationAvatar(activeConversation).src ? (
                                    <AvatarImage
                                        src={conversationAvatar(activeConversation).src || ''}
                                        alt={conversationAvatar(activeConversation).label}
                                    />
                                ) : (
                                    <AvatarFallback>{avatarForName(conversationAvatar(activeConversation).label)}</AvatarFallback>
                                )}
                                {conversationAvatar(activeConversation).online && <OnlineDot />}
                            </div>
                        )}
                        <div css={tw`min-w-0`}>
                            <HeaderTitle>{activeConversation?.name || 'Select chat'}</HeaderTitle>
                            <HeaderMeta>
                                {activeConversation?.type === 'private' && dmPresence
                                    ? dmPresence.text
                                    : activeConversation
                                    ? `${Array.isArray(activeConversation.members) ? activeConversation.members.length : 0} member${
                                          Array.isArray(activeConversation.members) && activeConversation.members.length === 1 ? '' : 's'
                                      }`
                                    : 'No conversation selected'}
                            </HeaderMeta>
                        </div>
                    </div>
                    <div css={tw`w-full lg:w-auto flex flex-wrap items-center justify-end gap-1.5 lg:gap-2`}>
                        {activeConversation && (
                            <Tiny type={'button'} onClick={handleHeaderCall} disabled={callLoading}>
                                Call
                            </Tiny>
                        )}
                        {activeConversation && (
                            <Tiny type={'button'} onClick={toggleConversationNotificationMute}>
                                {activeConversation.notificationMutedUntil ? 'Unmute Notif' : 'Mute Notif'}
                            </Tiny>
                        )}
                        <Tiny type={'button'} css={tw`lg:hidden`} onClick={() => setMobilePane('chats')}>
                            Back
                        </Tiny>
                        <div css={tw`hidden lg:block text-xs text-neutral-400`}>{messages.length} msgs</div>
                    </div>
                </MainHeader>

                {activeConversation?.type === 'group' && (myGroupRole === 'owner' || myGroupRole === 'admin') && (
                    <div css={tw`px-3 py-2 border-b space-y-2`} style={panelHeaderStyle}>
                        <div css={tw`flex items-center justify-between gap-2`}>
                            <p css={tw`text-xs text-neutral-300`}>Group controls</p>
                            <Small type={'button'} onClick={() => setGroupToolsCollapsed((v) => !v)}>
                                {groupToolsCollapsed ? 'Expand' : 'Collapse'}
                            </Small>
                        </div>
                        {!groupToolsCollapsed && (
                            <>
                                {myGroupRole === 'owner' && (
                                    <div css={tw`flex flex-wrap items-center gap-2`}>
                                        <Input
                                            value={groupEditName}
                                            onChange={(e) => setGroupEditName(e.target.value)}
                                            placeholder={'Group name'}
                                            css={tw`max-w-xs`}
                                        />
                                        <Input
                                            value={groupEditUsername}
                                            onChange={(e) => setGroupEditUsername(e.target.value)}
                                            placeholder={'Group username'}
                                            css={tw`max-w-xs`}
                                        />
                                        <Input
                                            value={groupEditCode}
                                            onChange={(e) => setGroupEditCode(e.target.value)}
                                            placeholder={'Group code'}
                                            css={tw`max-w-xs`}
                                        />
                                        <Input
                                            value={groupEditAvatarUrl}
                                            onChange={(e) => setGroupEditAvatarUrl(e.target.value)}
                                            placeholder={'Group avatar URL'}
                                            css={tw`max-w-xs`}
                                        />
                                        <Small
                                            type={'button'}
                                            onClick={() =>
                                                manageGroup('rename', {
                                                    name: groupEditName,
                                                    groupUsername: groupEditUsername,
                                                    groupCode: groupEditCode,
                                                    avatarUrl: groupEditAvatarUrl,
                                                })
                                            }
                                        >
                                            Save Identity
                                        </Small>
                                    </div>
                                )}
                                <div css={tw`flex flex-wrap items-center gap-2`}>
                                    <Input
                                        value={groupMemberInput}
                                        onChange={(e) => setGroupMemberInput(e.target.value)}
                                        placeholder={'Add member by username'}
                                        css={tw`max-w-xs`}
                                    />
                                    <Button
                                        type={'button'}
                                        size={'xsmall'}
                                        onClick={() => {
                                            if (!groupMemberInput.trim()) return;
                                            manageGroup('add', { username: groupMemberInput.trim() });
                                            setGroupMemberInput('');
                                        }}
                                    >
                                        Add
                                    </Button>
                                </div>
                                <div css={tw`flex flex-wrap gap-1`}>
                                    {activeConversation.members.map((member) => (
                                        <div key={`gm-${member.id}`} css={tw`px-2 py-1 rounded bg-neutral-900 text-xs flex items-center gap-1`}>
                                            <button
                                                type={'button'}
                                                css={tw`text-neutral-200`}
                                                onClick={() =>
                                                    openProfile({
                                                        id: member.id,
                                                        username: member.username,
                                                        displayName: member.displayName,
                                                        avatarUrl: member.avatarUrl,
                                                        birthday: member.birthday || null,
                                                        joinedAt: member.createdAt || null,
                                                        lastSeen: null,
                                                    })
                                                }
                                            >
                                                @{member.username}
                                            </button>
                                            <span css={tw`text-neutral-400`}>({member.role || 'member'})</span>
                                            {member.username !== selfUsername && (
                                                <>
                                                    <Small type={'button'} onClick={() => manageGroup('kick', { memberId: member.id })}>
                                                        kick
                                                    </Small>
                                                    <Small type={'button'} onClick={() => manageGroup('ban', { memberId: member.id })}>
                                                        ban
                                                    </Small>
                                                    {myGroupRole === 'owner' && member.role !== 'owner' && (
                                                        <Small
                                                            type={'button'}
                                                            onClick={() => manageGroup(member.role === 'admin' ? 'demote' : 'promote', { memberId: member.id })}
                                                        >
                                                            {member.role === 'admin' ? 'demote' : 'admin'}
                                                        </Small>
                                                    )}
                                                </>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            </>
                        )}
                    </div>
                )}

                <Body
                    ref={listRef}
                    onDragOver={(e) => {
                        e.preventDefault();
                        if (!dragging) setDragging(true);
                    }}
                    onDragLeave={() => setDragging(false)}
                    onDrop={(e) => {
                        e.preventDefault();
                        setDragging(false);
                        const file = e.dataTransfer?.files?.[0];
                        if (file) {
                            setPendingUploadTarget(pollOpen ? 'poll' : 'composer');
                            setPendingUploadFile(file);
                        }
                    }}
                    css={dragging ? tw`ring-2 ring-neutral-500` : undefined}
                >
                    {loading ? (
                        <p css={tw`text-sm text-neutral-400 text-center py-6`}>Loading...</p>
                    ) : messages.length === 0 ? (
                        <p css={tw`text-sm text-neutral-400 text-center py-6`}>No messages yet.</p>
                    ) : (
                        messages.map((item) => {
                            const safeOptions = safePollOptions(item.poll);
                            const isPollImage = Boolean(item.poll && item.mediaType === 'image' && item.mediaUrl);
                            const previewLink = item.mediaType === 'link' && item.mediaUrl ? item.mediaUrl : firstUrlInText(item.body || '');
                            const mentionsMe = safeMentions(item.mentions).includes(selfUsername.toLowerCase());

                            return (
                                <BubbleWrap
                                    key={item.id}
                                    css={item.isOwn ? tw`justify-end` : tw`justify-start`}
                                    ref={(el) => {
                                        messageRefs.current[item.id] = el;
                                    }}
                                >
                                    <Bubble
                                        css={[
                                            item.isOwn
                                                ? tw`border text-neutral-100`
                                                : tw`border text-neutral-100`,
                                            mentionsMe ? tw`ring-1 ring-neutral-400` : undefined,
                                            highlightedMessageId === item.id ? tw`ring-2 ring-neutral-500` : undefined,
                                        ]}
                                        style={{
                                            background: item.isOwn ? '#14111f' : '#0b0b10',
                                            borderColor: item.isOwn ? 'rgba(139, 92, 246, 0.42)' : 'rgba(139, 92, 246, 0.18)',
                                            boxShadow: item.isOwn
                                                ? '0 12px 28px rgba(0, 0, 0, 0.38), inset 2px 0 0 rgba(139, 92, 246, 0.62)'
                                                : '0 12px 28px rgba(0, 0, 0, 0.32)',
                                        }}
                                    >
                                        {isPollImage && (
                                            <button
                                                type={'button'}
                                                css={tw`absolute top-2 right-2 text-[10px] px-2 py-1 rounded border text-neutral-200`}
                                                style={chipButtonStyle}
                                                onClick={() => setPreviewImageUrl(item.mediaUrl!)}
                                            >
                                                See Full
                                            </button>
                                        )}
                                        <button
                                            type={'button'}
                                            css={tw`flex items-center gap-2 mb-1 text-left`}
                                            onClick={() =>
                                                openProfile({
                                                    id: item.userId,
                                                    username: item.username,
                                                    displayName: item.displayName,
                                                    avatarUrl: item.avatarUrl,
                                                    birthday: item.birthday,
                                                    joinedAt: item.joinedAt,
                                                    lastSeen: item.createdAt,
                                                })
                                            }
                                        >
                                            <div css={tw`w-7 h-7 rounded-full overflow-hidden relative`}>
                                                {item.avatarUrl ? (
                                                    <AvatarImage src={item.avatarUrl} alt={item.displayName || item.username} />
                                                ) : (
                                                    <AvatarFallback>{avatarForName(item.displayName)}</AvatarFallback>
                                                )}
                                                {resolvePresence(item.username, item.createdAt).online && <OnlineDot />}
                                            </div>
                                            <div css={tw`leading-tight min-w-0`}>
                                                <div css={tw`text-[11px] font-semibold opacity-90 truncate`}>{item.displayName}</div>
                                                <div css={tw`text-[10px] opacity-60 truncate`}>@{item.username}</div>
                                            </div>
                                        </button>

                                        {item.reply && (
                                                <div
                                                    css={tw`mb-2 rounded-md border-l-2 px-2 py-1`}
                                                    style={{ background: '#111117', borderColor: 'rgba(139, 92, 246, 0.48)' }}
                                                >
                                                <button type={'button'} css={tw`text-[11px] text-neutral-200`} onClick={() => scrollToMessage(item.reply!.id)}>
                                                    Reply to @{item.reply.username}: {item.reply.body || '[empty]'}
                                                </button>
                                            </div>
                                        )}

                                        {editingId === item.id ? (
                                            <div css={tw`space-y-1`}>
                                                <TextArea value={editingValue} onChange={(e) => setEditingValue(e.target.value)} />
                                                <div css={tw`flex gap-2 justify-end`}>
                                                    <Button type={'button'} size={'xsmall'} onClick={() => saveEdit(item.id)}>
                                                        Save
                                                    </Button>
                                                    <Button type={'button'} size={'xsmall'} color={'secondary'} onClick={() => setEditingId(null)}>
                                                        Cancel
                                                    </Button>
                                                </div>
                                            </div>
                                        ) : (
                                            <>
                                                <div>{renderRichBody(item.body, selfUsername)}</div>

                                                {item.mediaUrl && item.mediaType === 'image' && !isPollImage && (
                                                    <button type={'button'} onClick={() => setPreviewImageUrl(item.mediaUrl!)} css={tw`mt-2 block text-left`}>
                                                        <img
                                                            src={item.mediaUrl}
                                                            alt={item.mediaName || 'chat image'}
                                                            css={tw`rounded-md max-h-56 lg:max-h-72 max-w-[64vw] lg:max-w-full object-contain cursor-pointer`}
                                                        />
                                                    </button>
                                                )}

                                                {item.mediaUrl && item.mediaType === 'audio' && (
                                                    <audio css={tw`mt-2 w-full`} controls preload={'none'}>
                                                        <source src={item.mediaUrl} type={item.mediaMime || undefined} />
                                                    </audio>
                                                )}

                                                {previewLink && (
                                                    <a
                                                        href={previewLink}
                                                        target={'_blank'}
                                                        rel={'noopener noreferrer'}
                                                        css={tw`mt-2 block rounded-md border p-2`}
                                                        style={{ background: '#111117', borderColor: 'rgba(139, 92, 246, 0.18)' }}
                                                    >
                                                        <p css={tw`text-[11px] text-neutral-400 uppercase tracking-wide`}>Link preview</p>
                                                        <p css={tw`text-sm text-neutral-200 break-all`}>{formatLinkLabel(previewLink)}</p>
                                                    </a>
                                                )}

                                                {item.poll && safeOptions.length > 0 && (
                                                    <div
                                                        css={tw`mt-2 rounded-md border p-2`}
                                                        style={{ background: '#111117', borderColor: 'rgba(139, 92, 246, 0.18)' }}
                                                    >
                                                        <p css={tw`text-sm font-semibold`}>{item.poll.question}</p>
                                                        {isPollImage && (
                                                            <button
                                                                type={'button'}
                                                                onClick={() => setPreviewImageUrl(item.mediaUrl!)}
                                                                css={tw`mt-2 block text-left`}
                                                            >
                                                                <img
                                                                    src={item.mediaUrl || ''}
                                                                    alt={item.mediaName || 'poll image'}
                                                                    css={tw`rounded border border-neutral-600 object-cover w-24 h-16 sm:w-28 sm:h-20`}
                                                                />
                                                            </button>
                                                        )}
                                                        <div css={tw`mt-2 space-y-1`}>
                                                            {safeOptions.map((opt, idx) => (
                                                                <button
                                                                    key={`${item.id}-opt-${idx}`}
                                                                    type={'button'}
                                                                    onClick={() => handleVote(item.id, idx)}
                                                                    css={[
                                                                        tw`w-full text-left rounded px-2 py-1 border flex items-center justify-between text-xs`,
                                                                    ]}
                                                                    style={{
                                                                        background: item.poll?.myVote === idx ? 'rgba(139, 92, 246, 0.16)' : '#0b0b10',
                                                                        borderColor:
                                                                            item.poll?.myVote === idx
                                                                                ? 'rgba(139, 92, 246, 0.52)'
                                                                                : 'rgba(139, 92, 246, 0.2)',
                                                                    }}
                                                                >
                                                                    <span>{opt.text}</span>
                                                                    <span css={tw`opacity-80`}>{opt.votes} vote</span>
                                                                </button>
                                                            ))}
                                                        </div>
                                                    </div>
                                                )}
                                            </>
                                        )}

                                        <div css={tw`mt-1 flex flex-wrap gap-1`}>
                                            {['👍', '❤️', '🔥'].map((emoji) => {
                                                const data = item.reactions.find((r) => r.emoji === emoji);
                                                const count = data?.count || 0;
                                                const mine = Boolean(data?.mine);

                                                return (
                                                    <button
                                                        key={`${item.id}-react-${emoji}`}
                                                        type={'button'}
                                                        onClick={() => reactToMessage(item.id, emoji)}
                                                        css={[
                                                            tw`text-[10px] px-1.5 py-0.5 rounded border`,
                                                        ]}
                                                        style={{
                                                            background: mine ? 'rgba(139, 92, 246, 0.18)' : '#111117',
                                                            borderColor: mine ? 'rgba(139, 92, 246, 0.5)' : 'rgba(139, 92, 246, 0.18)',
                                                        }}
                                                    >
                                                        {emoji} {count > 0 ? count : ''}
                                                    </button>
                                                );
                                            })}
                                        </div>

                                        <Meta>
                                            <span>{safeTime(item.createdAt)}</span>
                                            {item.editedAt && <span>(edited)</span>}
                                            {item.isOwn && <span>{item.isReadByOthers ? '✓✓' : '✓'}</span>}
                                            <Small type={'button'} onClick={() => setReplyingTo(item)}>
                                                Reply
                                            </Small>
                                            {item.isOwn && (
                                                <>
                                                    <Small type={'button'} onClick={() => startEdit(item)}>
                                                        Edit
                                                    </Small>
                                                    <Small type={'button'} onClick={() => removeMessage(item.id)}>
                                                        Delete
                                                    </Small>
                                                </>
                                            )}
                                        </Meta>
                                    </Bubble>
                                </BubbleWrap>
                            );
                        })
                    )}
                </Body>

                <Composer onSubmit={handleSend}>
                    {dragging && (
                        <div css={tw`rounded-md border p-2 text-xs text-neutral-200`} style={panelHeaderStyle}>
                            Drop file to choose Quick/Compressed upload...
                        </div>
                    )}
                    {replyingTo && (
                        <div css={tw`rounded-md border p-2 text-xs text-neutral-200 flex items-center justify-between gap-2`} style={panelHeaderStyle}>
                            <span css={tw`truncate`}>
                                Replying @{replyingTo.username}: {replyingTo.body || '[media]'}
                            </span>
                            <Small type={'button'} onClick={() => setReplyingTo(null)}>
                                Cancel
                            </Small>
                        </div>
                    )}

                    {pollOpen && (
                        <div
                            css={tw`rounded-md border p-2 space-y-2`}
                            style={panelHeaderStyle}
                            onDragOver={(e) => e.preventDefault()}
                            onDrop={(e) => {
                                e.preventDefault();
                                const file = e.dataTransfer?.files?.[0];
                                if (file) {
                                    setPendingUploadTarget('poll');
                                    setPendingUploadFile(file);
                                }
                            }}
                        >
                            <Input
                                value={pollQuestion}
                                onChange={(e) => setPollQuestion(e.target.value)}
                                placeholder={'Poll question'}
                            />
                            {pollOptions.map((opt, idx) => (
                                <Input
                                    key={`poll-opt-${idx}`}
                                    value={opt}
                                    onChange={(e) => {
                                        const nextValue = e.currentTarget.value;
                                        setPollOptions((current) => current.map((item, i) => (i === idx ? nextValue : item)));
                                    }}
                                    placeholder={`Option ${idx + 1}`}
                                />
                            ))}
                            <div css={tw`flex items-center gap-2 flex-wrap`}>
                                <label css={tw`text-xs text-neutral-300 cursor-pointer`}>
                                    {uploading ? 'Uploading...' : 'Add poll image'}
                                    <input
                                        type={'file'}
                                        accept={'image/*'}
                                        css={tw`hidden`}
                                        onChange={(e) => uploadPollImage(e.currentTarget.files?.[0], false)}
                                    />
                                </label>
                                <Small
                                    type={'button'}
                                    onClick={() => {
                                        const picker = document.createElement('input');
                                        picker.type = 'file';
                                        picker.accept = 'image/*';
                                        picker.onchange = () => uploadPollImage(picker.files?.[0], true);
                                        picker.click();
                                    }}
                                >
                                    Compressed upload
                                </Small>
                                {pollMediaUrl && (
                                    <button type={'button'} onClick={() => setPreviewImageUrl(pollMediaUrl)} css={tw`text-xs text-neutral-200 underline`}>
                                        preview
                                    </button>
                                )}
                            </div>
                            <div css={tw`flex items-center justify-between`}>
                                <div css={tw`flex items-center gap-2`}>
                                    <Small
                                        type={'button'}
                                        onClick={() => setPollOptions((current) => (current.length >= 8 ? current : [...current, '']))}
                                    >
                                        + Option
                                    </Small>
                                    <Small type={'button'} onClick={() => setPollOpen(false)}>
                                        Close
                                    </Small>
                                </div>
                                <Button type={'button'} size={'xsmall'} onClick={handleCreatePoll} disabled={sending}>
                                    Send Poll
                                </Button>
                            </div>
                        </div>
                    )}

                    <TextArea
                        value={message}
                        onChange={(e) => setMessage(e.target.value)}
                        placeholder={'Type message... (Enter send, Shift+Enter new line, @username for tag)'}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter' && !e.shiftKey) {
                                e.preventDefault();
                                handleSend();
                            }
                        }}
                    />

                    <div css={tw`flex flex-col lg:flex-row lg:items-center lg:justify-between gap-2`}>
                        <div css={tw`flex items-center gap-2 flex-wrap`}>
                            <label css={tw`text-xs text-neutral-300 cursor-pointer`}>
                                {uploading ? 'Uploading...' : 'Attach media'}
                                <input
                                    ref={uploadInputRef}
                                    type={'file'}
                                    accept={'image/*,audio/*'}
                                    css={tw`hidden`}
                                    onChange={(e) => onUpload(e.currentTarget.files?.[0], { compressed: false })}
                                />
                            </label>
                            <Tiny type={'button'} onClick={() => quickSendInputRef.current?.click()} disabled={uploading || sending}>
                                Quick Send
                            </Tiny>
                            <Tiny type={'button'} onClick={() => compressedSendInputRef.current?.click()} disabled={uploading || sending}>
                                Compressed
                            </Tiny>
                            <input
                                ref={quickSendInputRef}
                                type={'file'}
                                accept={'image/*,audio/*'}
                                css={tw`hidden`}
                                onChange={(e) => onUpload(e.currentTarget.files?.[0], { quickSend: true, compressed: false })}
                            />
                            <input
                                ref={compressedSendInputRef}
                                type={'file'}
                                accept={'image/*'}
                                css={tw`hidden`}
                                onChange={(e) => onUpload(e.currentTarget.files?.[0], { quickSend: true, compressed: true })}
                            />
                            {mediaUrl && <Tag>{mediaType || 'link'} ready</Tag>}
                            <Small type={'button'} onClick={() => setPollOpen((v) => !v)}>
                                {pollOpen ? 'Hide poll' : 'Create poll'}
                            </Small>
                        </div>
                        <Button type={'submit'} css={tw`w-full lg:w-auto`} disabled={sending || (!message.trim() && !mediaUrl.trim())}>
                            Send
                        </Button>
                    </div>
                </Composer>
            </Main>
            {pendingUploadFile && (
                <div
                    css={tw`fixed inset-0 z-50 bg-black/70 flex items-center justify-center p-4`}
                    onClick={() => setPendingUploadFile(null)}
                >
                    <div css={tw`w-full max-w-sm rounded-lg border border-neutral-600 bg-neutral-900 p-4 space-y-3`} onClick={(e) => e.stopPropagation()}>
                        <h4 css={tw`text-sm font-semibold text-neutral-100`}>Upload file</h4>
                        <p css={tw`text-xs text-neutral-400 break-all`}>{pendingUploadFile.name}</p>
                        {isImageFile(pendingUploadFile) && (
                            <div css={tw`flex gap-2`}>
                                <Tiny
                                    type={'button'}
                                    css={uploadMode === 'quick' ? tw`border-neutral-400 text-neutral-100` : undefined}
                                    onClick={() => setUploadMode('quick')}
                                >
                                    Quick
                                </Tiny>
                                <Tiny
                                    type={'button'}
                                    css={uploadMode === 'compressed' ? tw`border-neutral-400 text-neutral-100` : undefined}
                                    onClick={() => setUploadMode('compressed')}
                                >
                                    Compressed
                                </Tiny>
                            </div>
                        )}
                        <div css={tw`flex flex-wrap gap-2`}>
                            <Button
                                type={'button'}
                                size={'xsmall'}
                                onClick={async () => {
                                    const file = pendingUploadFile;
                                    const target = pendingUploadTarget;
                                    setPendingUploadFile(null);
                                    if (!file) return;
                                    await onUpload(file, {
                                        quickSend: true,
                                        compressed: isImageFile(file) && uploadMode === 'compressed',
                                        forPoll: target === 'poll',
                                    });
                                }}
                            >
                                Send now
                            </Button>
                            <Button
                                type={'button'}
                                size={'xsmall'}
                                color={'secondary'}
                                onClick={async () => {
                                    const file = pendingUploadFile;
                                    const target = pendingUploadTarget;
                                    setPendingUploadFile(null);
                                    if (!file) return;
                                    await onUpload(file, {
                                        quickSend: false,
                                        compressed: isImageFile(file) && uploadMode === 'compressed',
                                        forPoll: target === 'poll',
                                    });
                                }}
                            >
                                Attach only
                            </Button>
                            <Button type={'button'} size={'xsmall'} color={'secondary'} onClick={() => setPendingUploadFile(null)}>
                                Cancel
                            </Button>
                        </div>
                    </div>
                </div>
            )}
            {incomingCallPrompt && (
                <div
                    css={tw`fixed inset-0 z-50 flex items-center justify-center p-4`}
                    style={{ background: 'rgba(0, 0, 0, 0.68)', backdropFilter: 'blur(10px)' }}
                    onClick={() => respondIncomingCall('ignore')}
                >
                    <div css={tw`w-full max-w-sm rounded-lg border p-4`} style={panelStyle} onClick={(e) => e.stopPropagation()}>
                        <p css={tw`text-sm font-semibold text-neutral-100`}>Incoming Direct Call</p>
                        <p css={tw`text-xs text-neutral-300 mt-1 truncate`}>{incomingCallPrompt.fromName}</p>
                        <div css={tw`mt-4 grid grid-cols-2 gap-2`}>
                            <Button type={'button'} size={'xsmall'} onClick={() => respondIncomingCall('accept')}>
                                Accept
                            </Button>
                            <Button type={'button'} size={'xsmall'} color={'secondary'} onClick={() => respondIncomingCall('denied')}>
                                Denied
                            </Button>
                            <Button type={'button'} size={'xsmall'} color={'secondary'} onClick={() => respondIncomingCall('busy')}>
                                I'm Busy
                            </Button>
                            <Button type={'button'} size={'xsmall'} color={'secondary'} onClick={() => respondIncomingCall('ignore')}>
                                Close
                            </Button>
                        </div>
                    </div>
                </div>
            )}
            {callOpen && callState.active && (
                <div css={tw`fixed z-50 bottom-3 right-3 left-3 sm:left-auto sm:bottom-4 sm:right-4 sm:w-[24rem] rounded-lg border shadow-2xl overflow-hidden`} style={panelStyle}>
                    <div css={tw`px-3 py-2 border-b flex items-center justify-between`} style={panelHeaderStyle}>
                        <div>
                            <p css={tw`text-neutral-100 font-semibold text-xs`}>Voice Call</p>
                            <p css={tw`text-neutral-400 text-[11px] truncate`}>
                                {(activeConversation?.name || 'Conversation') + ' • ' + callNetLabel}
                            </p>
                        </div>
                        <button type={'button'} css={tw`text-neutral-300 hover:text-neutral-100 text-xs`} onClick={() => setCallOpen(false)}>
                            Hide
                        </button>
                    </div>
                    <div css={tw`px-3 py-3`}>
                        <div css={tw`flex items-start justify-between`}>
                            <div css={tw`grid grid-cols-3 sm:grid-cols-5 gap-2`}>
                                {visibleCallParticipants.map((participant) => {
                                    const isSelf = participant.id === selfUserId;
                                    const level = isSelf ? localSpeakingLevel : clampLevel(participant.speakingLevel || 0);
                                    const spread = level >= 55 ? 7 : level >= 20 ? 4 : 2;
                                    const alpha = level >= 55 ? 0.75 : level >= 20 ? 0.5 : 0.22;
                                    const ringStyle = participant.micMuted
                                        ? { boxShadow: '0 0 0 2px rgba(248,113,113,0.55)' }
                                        : {
                                              boxShadow:
                                                  level > 0
                                                      ? `0 0 0 ${spread}px rgba(34,197,94,${alpha})`
                                                      : '0 0 0 2px rgba(34,197,94,0.22)',
                                          };

                                    return (
                                        <div key={`call-user-${participant.id}`} css={tw`flex flex-col items-center text-center w-14`}>
                                            <div css={tw`w-11 h-11 rounded-full overflow-hidden border`} style={{ ...ringStyle, borderColor: 'rgba(139, 92, 246, 0.24)' }}>
                                                {participant.avatarUrl ? (
                                                    <AvatarImage src={participant.avatarUrl} alt={participant.displayName} />
                                                ) : (
                                                    <AvatarFallback>{avatarForName(participant.displayName || participant.username)}</AvatarFallback>
                                                )}
                                            </div>
                                            <p css={tw`mt-1 text-[10px] text-neutral-100 truncate w-full`}>
                                                {participant.displayName}
                                            </p>
                                        </div>
                                    );
                                })}
                            </div>
                            {callParticipants.length > callMaxVisible && (
                                <div css={tw`text-[10px] text-neutral-400 ml-2 mt-1`}>+{callParticipants.length - callMaxVisible}</div>
                            )}
                        </div>
                        <div css={tw`mt-3 flex items-center justify-between gap-2`}>
                            <Button type={'button'} size={'xsmall'} color={'secondary'} onClick={toggleLocalMic}>
                                {localMicMuted ? 'Open Mic' : 'Silent'}
                            </Button>
                            <div css={tw`flex items-center gap-2`}>
                                <Button type={'button'} size={'xsmall'} color={'secondary'} onClick={() => leaveCurrentCall(false)}>
                                    Leave
                                </Button>
                                {callState.callId && callSelfParticipant && (
                                    <Button type={'button'} size={'xsmall'} color={'secondary'} onClick={() => leaveCurrentCall(true)}>
                                        End
                                    </Button>
                                )}
                            </div>
                        </div>
                        <div css={tw`hidden`}>
                            {Object.entries(remoteStreams).map(([id, stream]) => (
                                <audio
                                    key={`remote-audio-${id}`}
                                    autoPlay
                                    playsInline
                                    ref={(el) => {
                                        if (!el || !stream) return;
                                        if (el.srcObject !== stream) {
                                            el.srcObject = stream;
                                        }
                                    }}
                                />
                            ))}
                        </div>
                    </div>
                </div>
            )}
            {profilePopup && (
                <div
                    css={tw`fixed inset-0 z-50 flex items-center justify-center p-4`}
                    style={{ background: 'rgba(0, 0, 0, 0.75)', backdropFilter: 'blur(10px)' }}
                    onClick={() => setProfilePopup(null)}
                >
                    <div css={tw`w-full max-w-md rounded-lg border overflow-hidden`} style={panelStyle} onClick={(e) => e.stopPropagation()}>
                        <div css={tw`px-4 py-3 border-b`} style={panelHeaderStyle}>
                            <div css={tw`flex items-center justify-start`}>
                                <button type={'button'} css={tw`text-neutral-300 hover:text-neutral-100 text-xs`} onClick={() => setProfilePopup(null)}>
                                    Back
                                </button>
                            </div>
                            <div css={tw`pt-4`}>
                                <div css={tw`w-20 h-20 rounded-full overflow-hidden mx-auto border-2 border-neutral-900 relative`}>
                                    {profilePopup.avatarUrl ? (
                                        <AvatarImage src={profilePopup.avatarUrl} alt={profilePopup.displayName} />
                                    ) : (
                                        <AvatarFallback>{avatarForName(profilePopup.displayName || profilePopup.username)}</AvatarFallback>
                                    )}
                                    {popupPresence?.online && <OnlineDot css={tw`border-neutral-700`} />}
                                </div>
                            </div>
                            <p css={tw`text-center text-neutral-100 font-semibold mt-3 text-xl`}>{profilePopup.displayName}</p>
                            <p css={tw`text-center text-neutral-300 text-sm`}>{popupPresence?.text || 'last seen unknown'}</p>
                            <div css={tw`grid grid-cols-4 gap-2 mt-4`}>
                                <button type={'button'} css={tw`rounded border py-2 text-xs`} style={chipButtonStyle} onClick={handlePopupMessage}>
                                    Message
                                </button>
                                <button
                                    type={'button'}
                                    css={tw`rounded border py-2 text-xs disabled:opacity-50`}
                                    style={chipButtonStyle}
                                    onClick={handlePopupMuteToggle}
                                    disabled={!activeConversation || !profilePopup.id}
                                >
                                    {popupTargetMuted ? 'Unmute' : 'Mute'}
                                </button>
                                <button type={'button'} css={tw`rounded border py-2 text-xs`} style={chipButtonStyle} onClick={handlePopupCall}>
                                    {callState.active ? (inCall ? 'Show Call' : 'Join Call') : 'Call'}
                                </button>
                                <button type={'button'} css={tw`rounded border py-2 text-xs`} style={chipButtonStyle} onClick={() => setProfilePopup(null)}>
                                    More
                                </button>
                            </div>
                        </div>
                        <div css={tw`px-5 py-4 space-y-4`} style={{ background: '#0b0b10' }}>
                            <div>
                                <p css={tw`text-neutral-200`}>{profilePopup.username ? `@${profilePopup.username}` : '-'}</p>
                                <p css={tw`text-neutral-400 text-sm`}>Username</p>
                            </div>
                            <div>
                                <p css={tw`text-neutral-200`}>{safeDate(profilePopup.birthday || null)}</p>
                                <p css={tw`text-neutral-400 text-sm`}>Birthday</p>
                            </div>
                            <div>
                                <p css={tw`text-neutral-200`}>{safeDate(profilePopup.joinedAt || null)}</p>
                                <p css={tw`text-neutral-400 text-sm`}>Joined</p>
                            </div>
                            <div css={tw`pt-1`}>
                                <Button type={'button'} size={'xsmall'} onClick={() => setProfilePopup(null)}>
                                    Close
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            )}
            {previewImageUrl && (
                <div
                    css={tw`fixed inset-0 z-50 bg-black/80 flex items-center justify-center p-4`}
                    onClick={() => setPreviewImageUrl(null)}
                >
                    <img
                        src={previewImageUrl}
                        alt={'preview'}
                        css={tw`max-w-[95vw] max-h-[90vh] rounded-lg border border-neutral-600`}
                        onClick={(e) => e.stopPropagation()}
                    />
                </div>
            )}
        </Root>
    );
};
