import React, { useEffect, useMemo, useRef, useState } from 'react';
import tw from 'twin.macro';
import { format } from 'date-fns';
import useFlash from '@/plugins/useFlash';
import { useStoreState } from 'easy-peasy';
import {
    addGroupMember,
    banGroupMember,
    ChatConversation,
    ChatUser,
    createGroupConversation,
    createPollMessage,
    createPrivateConversation,
    deletePublicMessage,
    editPublicMessage,
    getConversations,
    getPublicMessages,
    markPublicMessagesRead,
    postPublicMessage,
    PublicChatMessage,
    searchChatUsers,
    setGroupAdmin,
    toggleReaction,
    updateGroupConversation,
    uploadPublicMedia,
    votePoll,
    kickGroupMember,
} from '@/api/chat/publicChat';
import { Button } from '@/components/elements/button/index';

const Root = tw.div`bg-neutral-900 rounded-lg border border-neutral-700 h-full min-h-[70vh] max-h-[85vh] flex flex-col lg:flex-row overflow-hidden`;
const Sidebar = tw.div`w-full lg:w-[21rem] border-b lg:border-b-0 lg:border-r border-neutral-700 bg-[#111c2d] flex flex-col`;
const SideHeader = tw.div`px-4 py-3 border-b border-neutral-700`;
const SideTitle = tw.h2`text-base font-semibold text-neutral-100`;
const SideBlock = tw.div`p-3 border-b border-neutral-800`;
const SideList = tw.div`flex-1 overflow-y-auto p-2 space-y-2`;
const ConvButton = tw.button`w-full text-left p-2 rounded-md border border-neutral-700 bg-neutral-900 hover:bg-neutral-800 transition`;
const Input = tw.input`w-full rounded-md border border-neutral-600 bg-neutral-800 text-neutral-100 px-3 py-2 text-sm focus:outline-none focus:border-cyan-400`;
const TextArea = tw.textarea`w-full rounded-md border border-neutral-600 bg-neutral-800 text-neutral-100 px-3 py-2 text-sm min-h-[66px] focus:outline-none focus:border-cyan-400`;
const Small = tw.button`text-xs text-cyan-300 hover:text-cyan-200`;

const Main = tw.div`flex-1 flex flex-col min-w-0 min-h-0 bg-[#0d1625]`;
const MainHeader = tw.div`px-4 py-3 border-b border-neutral-700 flex items-center justify-between`;
const HeaderTitle = tw.h3`text-base font-semibold text-neutral-100 truncate`;
const HeaderMeta = tw.div`text-xs text-neutral-400`;
const Body = tw.div`flex-1 min-h-0 overflow-y-auto px-4 py-3 space-y-2`;
const Composer = tw.form`flex-shrink-0 p-3 border-t border-neutral-700 bg-neutral-900 space-y-2`;

const BubbleWrap = tw.div`flex`;
const Bubble = tw.div`relative max-w-[94%] lg:max-w-[84%] rounded-xl px-3 py-2 text-sm whitespace-pre-wrap break-words`;
const Meta = tw.div`mt-1 text-[11px] text-neutral-400 flex items-center gap-2`;
const Tag = tw.span`px-1.5 py-0.5 rounded bg-neutral-700 text-[10px] text-neutral-200`;

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

const avatarForName = (name: string): string => {
    const clean = name.trim();
    if (!clean) return '?';
    const parts = clean.split(/\s+/).filter(Boolean);
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();

    return `${parts[0][0] || ''}${parts[1][0] || ''}`.toUpperCase();
};

const renderRichBody = (body: string | null, selfUsername: string) => {
    if (!body) return null;

    const chunks = body.split(TOKEN_REGEX);

    return chunks.map((chunk, idx) => {
        if (!chunk) return null;

        if (chunk.startsWith('http') && isSafeHttpUrl(chunk)) {
            return (
                <a key={`${chunk}-${idx}`} href={chunk} target={'_blank'} rel={'noopener noreferrer'} css={tw`underline text-cyan-300`}>
                    {chunk}
                </a>
            );
        }

        if (chunk.startsWith('@')) {
            const mention = chunk.slice(1).toLowerCase();
            const isMe = mention === selfUsername.toLowerCase();

            return (
                <span key={`${chunk}-${idx}`} css={isMe ? tw`text-yellow-300 font-semibold` : tw`text-cyan-200 font-medium`}>
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
    const { clearFlashes, clearAndAddHttpError } = useFlash();
    const selfUsername = useStoreState((state) => state.user.data?.username || '');

    const [conversations, setConversations] = useState<ChatConversation[]>([]);
    const [activeConversationId, setActiveConversationId] = useState<number | null>(null);

    const [messages, setMessages] = useState<PublicChatMessage[]>([]);
    const [loading, setLoading] = useState(true);
    const [sending, setSending] = useState(false);
    const [uploading, setUploading] = useState(false);
    const [dragging, setDragging] = useState(false);

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

    const listRef = useRef<HTMLDivElement>(null);
    const messageRefs = useRef<Record<number, HTMLDivElement | null>>({});

    const activeConversation = useMemo(
        () => conversations.find((item) => item.id === activeConversationId) || null,
        [conversations, activeConversationId]
    );
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

        if (list.length === 0) {
            setActiveConversationId(null);
            return;
        }

        if (!preserveActive || !activeConversationId || !list.some((c) => c.id === activeConversationId)) {
            setActiveConversationId(list[0].id);
        }
    };

    const loadMessages = async (conversationId: number) => {
        setLoading(true);
        try {
            const list = await getPublicMessages({ conversationId, limit: 80 });
            setMessages(list);
            if (list.length) {
                await markPublicMessagesRead(conversationId, list.map((m) => m.id));
            }
            clearFlashes('dashboard');
            requestAnimationFrame(scrollToBottom);
        } finally {
            setLoading(false);
        }
    };

    const pollIncoming = async () => {
        if (!activeConversationId || !lastId) return;

        try {
            const incoming = await getPublicMessages({ conversationId: activeConversationId, sinceId: lastId, limit: 100 });
            if (!incoming.length) return;

            setMessages((current) => {
                const known = new Set(current.map((m) => m.id));

                return [...current, ...incoming.filter((m) => !known.has(m.id))];
            });
            await markPublicMessagesRead(activeConversationId, incoming.map((m) => m.id));
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
        if (!activeConversationId) {
            setMessages([]);
            setLoading(false);
            return;
        }

        loadMessages(activeConversationId).catch((error) => {
            clearAndAddHttpError({ key: 'dashboard', error });
            setLoading(false);
        });

        setReplyingTo(null);
        setMobilePane('room');
    }, [activeConversationId]);

    useEffect(() => {
        const timer = setInterval(() => {
            pollIncoming();
        }, 3000);

        return () => clearInterval(timer);
    }, [activeConversationId, lastId]);

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
            setGroupToolsCollapsed(true);
            return;
        }

        setGroupEditName(activeConversation.name || '');
        setGroupEditUsername(activeConversation.groupUsername || '');
        setGroupEditCode(activeConversation.groupCode || '');
        setGroupToolsCollapsed(true);
    }, [activeConversation?.id]);

    const onUpload = async (file?: File) => {
        if (!file) return;

        try {
            setUploading(true);
            const uploaded = await uploadPublicMedia(file);
            setMediaUrl(uploaded.url);
            setMediaType(uploaded.mediaType === 'audio' ? 'audio' : uploaded.mediaType === 'image' ? 'image' : 'link');
            setMediaName(uploaded.mediaName || file.name);
            setMediaMime(uploaded.mediaMime || file.type || '');
        } catch (error) {
            clearAndAddHttpError({ key: 'dashboard', error });
        } finally {
            setUploading(false);
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

    const uploadPollImage = async (file?: File) => {
        if (!file) return;

        try {
            setUploading(true);
            const uploaded = await uploadPublicMedia(file);
            if (uploaded.mediaType !== 'image') {
                return;
            }
            setPollMediaUrl(uploaded.url);
            setPollMediaName(uploaded.mediaName || file.name);
            setPollMediaMime(uploaded.mediaMime || file.type || '');
        } catch (error) {
            clearAndAddHttpError({ key: 'dashboard', error });
        } finally {
            setUploading(false);
        }
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
                                <div key={user.id} css={tw`flex items-center justify-between rounded bg-neutral-800 px-2 py-1`}>
                                    <div css={tw`min-w-0`}>
                                        <p css={tw`text-xs text-neutral-100 truncate`}>{user.displayName}</p>
                                        <p css={tw`text-[11px] text-neutral-400 truncate`}>@{user.username}</p>
                                    </div>
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
                                <div key={`found-group-${group.id}`} css={tw`flex items-center justify-between rounded bg-neutral-800 px-2 py-1`}>
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
                            css={activeConversationId === conversation.id ? tw`border-cyan-500 bg-cyan-900/20` : undefined}
                            onClick={() => {
                                setActiveConversationId(conversation.id);
                                setMobilePane('room');
                            }}
                        >
                            <div css={tw`flex items-center justify-between gap-2`}>
                                <p css={tw`text-sm text-neutral-100 truncate`}>{conversation.name}</p>
                                <Tag>{convLabel(conversation)}</Tag>
                            </div>
                            <p css={tw`text-[11px] text-neutral-400 mt-1`}>
                                {conversation.lastMessageAt ? safeTime(conversation.lastMessageAt) : 'No messages yet'}
                            </p>
                        </ConvButton>
                    ))}
                </SideList>
            </Sidebar>

            <Main css={mobilePane === 'chats' ? tw`hidden lg:flex` : undefined}>
                <MainHeader>
                    <div css={tw`min-w-0`}>
                        <HeaderTitle>{activeConversation?.name || 'Select chat'}</HeaderTitle>
                        <HeaderMeta>
                            {activeConversation
                                ? `${Array.isArray(activeConversation.members) ? activeConversation.members.length : 0} member${
                                      Array.isArray(activeConversation.members) && activeConversation.members.length === 1 ? '' : 's'
                                  }`
                                : 'No conversation selected'}
                        </HeaderMeta>
                    </div>
                    <div css={tw`flex items-center gap-2`}>
                        <Button type={'button'} size={'xsmall'} css={tw`lg:hidden`} onClick={() => setMobilePane('chats')}>
                            Back
                        </Button>
                        <div css={tw`text-xs text-neutral-400`}>{messages.length} msgs</div>
                    </div>
                </MainHeader>

                {activeConversation?.type === 'group' && (myGroupRole === 'owner' || myGroupRole === 'admin') && (
                    <div css={tw`px-3 py-2 border-b border-neutral-700 bg-neutral-900 space-y-2`}>
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
                                        <Small
                                            type={'button'}
                                            onClick={() =>
                                                manageGroup('rename', {
                                                    name: groupEditName,
                                                    groupUsername: groupEditUsername,
                                                    groupCode: groupEditCode,
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
                                        <div key={`gm-${member.id}`} css={tw`px-2 py-1 rounded bg-neutral-800 text-xs flex items-center gap-1`}>
                                            <span>@{member.username}</span>
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
                            if (pollOpen) {
                                uploadPollImage(file);
                            } else {
                                onUpload(file);
                            }
                        }
                    }}
                    css={dragging ? tw`ring-2 ring-cyan-400` : undefined}
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
                                            item.isOwn ? tw`bg-cyan-800/75 text-cyan-50` : tw`bg-neutral-800 text-neutral-100`,
                                            mentionsMe ? tw`ring-1 ring-yellow-300` : undefined,
                                            highlightedMessageId === item.id ? tw`ring-2 ring-cyan-300` : undefined,
                                        ]}
                                    >
                                        {isPollImage && (
                                            <button
                                                type={'button'}
                                                css={tw`absolute top-2 right-2 text-[10px] px-2 py-1 rounded border border-cyan-400/60 text-cyan-200 hover:bg-cyan-900/40`}
                                                onClick={() => setPreviewImageUrl(item.mediaUrl!)}
                                            >
                                                See Full
                                            </button>
                                        )}
                                        <div css={tw`flex items-center gap-2 mb-1`}>
                                            <div css={tw`w-6 h-6 rounded-full bg-neutral-700 text-[10px] font-bold text-neutral-100 flex items-center justify-center`}>
                                                {avatarForName(item.displayName)}
                                            </div>
                                            <div css={tw`leading-tight min-w-0`}>
                                                <div css={tw`text-[11px] font-semibold opacity-80 truncate`}>{item.displayName}</div>
                                                <div css={tw`text-[10px] opacity-60 truncate`}>@{item.username}</div>
                                            </div>
                                        </div>

                                        {item.reply && (
                                            <div css={tw`mb-2 rounded-md border-l-2 border-cyan-400 bg-black/20 px-2 py-1`}>
                                                <button type={'button'} css={tw`text-[11px] text-cyan-200`} onClick={() => scrollToMessage(item.reply!.id)}>
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
                                                            css={tw`rounded-md max-h-72 object-cover cursor-pointer`}
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
                                                        css={tw`mt-2 block rounded-md border border-neutral-600 bg-neutral-900/70 p-2 hover:border-cyan-400`}
                                                    >
                                                        <p css={tw`text-[11px] text-neutral-400 uppercase tracking-wide`}>Link preview</p>
                                                        <p css={tw`text-sm text-cyan-300 break-all`}>{formatLinkLabel(previewLink)}</p>
                                                    </a>
                                                )}

                                                {item.poll && safeOptions.length > 0 && (
                                                    <div css={tw`mt-2 rounded-md border border-neutral-600 bg-black/20 p-2`}>
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
                                                                        tw`w-full text-left rounded px-2 py-1 border border-neutral-600 hover:border-cyan-400 flex items-center justify-between text-xs`,
                                                                        item.poll?.myVote === idx ? tw`bg-cyan-900/40 border-cyan-500` : undefined,
                                                                    ]}
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
                                                            tw`text-[10px] px-1.5 py-0.5 rounded border border-neutral-600`,
                                                            mine ? tw`bg-cyan-900/40 border-cyan-400` : tw`hover:border-cyan-300`,
                                                        ]}
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
                        <div css={tw`rounded-md border border-cyan-400/50 bg-cyan-900/20 p-2 text-xs text-cyan-100`}>
                            Drop file to upload...
                        </div>
                    )}
                    {replyingTo && (
                        <div css={tw`rounded-md border border-cyan-500/40 bg-cyan-900/20 p-2 text-xs text-cyan-100 flex items-center justify-between gap-2`}>
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
                            css={tw`rounded-md border border-neutral-600 bg-neutral-900 p-2 space-y-2`}
                            onDragOver={(e) => e.preventDefault()}
                            onDrop={(e) => {
                                e.preventDefault();
                                const file = e.dataTransfer?.files?.[0];
                                if (file) uploadPollImage(file);
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
                                <label css={tw`text-xs text-cyan-300 cursor-pointer`}>
                                    {uploading ? 'Uploading...' : 'Add poll image'}
                                    <input
                                        type={'file'}
                                        accept={'image/*'}
                                        css={tw`hidden`}
                                        onChange={(e) => uploadPollImage(e.currentTarget.files?.[0])}
                                    />
                                </label>
                                {pollMediaUrl && (
                                    <button type={'button'} onClick={() => setPreviewImageUrl(pollMediaUrl)} css={tw`text-xs text-cyan-200 underline`}>
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

                    <div css={tw`flex items-center justify-between gap-2 flex-wrap`}>
                        <div css={tw`flex items-center gap-2`}>
                            <label css={tw`text-xs text-cyan-300 cursor-pointer`}>
                                {uploading ? 'Uploading...' : 'Upload media'}
                                <input
                                    type={'file'}
                                    accept={'image/*,audio/*'}
                                    css={tw`hidden`}
                                    onChange={(e) => onUpload(e.currentTarget.files?.[0])}
                                />
                            </label>
                            {mediaUrl && <Tag>{mediaType || 'link'} ready</Tag>}
                            <Small type={'button'} onClick={() => setPollOpen((v) => !v)}>
                                {pollOpen ? 'Hide poll' : 'Create poll'}
                            </Small>
                        </div>
                        <Button type={'submit'} disabled={sending || (!message.trim() && !mediaUrl.trim())}>
                            Send
                        </Button>
                    </div>
                </Composer>
            </Main>
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
