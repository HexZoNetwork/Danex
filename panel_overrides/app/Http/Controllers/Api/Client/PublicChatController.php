<?php

namespace Pterodactyl\Http\Controllers\Api\Client;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Pterodactyl\Models\ChatConversation;
use Pterodactyl\Models\PublicChatMessage;
use Pterodactyl\Models\User;

class PublicChatController extends ClientApiController
{
    private const GLOBAL_CONVERSATION_ID = 1;
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 100;
    private const MAX_MESSAGE_LENGTH = 2000;
    private const REACTION_ALLOWLIST = ['👍', '❤️', '🔥', '😂', '😮', '😢'];
    private static ?bool $hasParticipantRoleColumn = null;
    private static ?bool $hasReactionTable = null;

    public function conversations(Request $request): JsonResponse
    {
        $user = $request->user();
        $global = $this->globalConversation();

        $conversations = ChatConversation::query()
            ->with('participants:id,username,name_first,name_last')
            ->where('id', $global->id)
            ->orWhere(function ($query) use ($user) {
                $query->where('id', '!=', self::GLOBAL_CONVERSATION_ID)
                    ->whereHas('participants', fn ($q) => $q->where('users.id', $user->id));
            })
            ->orderBy('id')
            ->get();

        $latest = PublicChatMessage::query()
            ->whereIn('conversation_id', $conversations->pluck('id')->all())
            ->orderByDesc('id')
            ->get(['id', 'conversation_id', 'created_at'])
            ->groupBy('conversation_id')
            ->map(fn ($items) => $items->first());

        return new JsonResponse([
            'data' => $conversations->map(function (ChatConversation $conversation) use ($user, $latest) {
                $memberList = $conversation->participants->map(fn ($member) => [
                    'id' => (int) $member->id,
                    'username' => (string) $member->username,
                    'display_name' => trim((string) ($member->name_first . ' ' . $member->name_last)) ?: (string) $member->username,
                    'role' => (string) ($member->pivot->role ?? 'member'),
                ])->values();

                $name = (string) ($conversation->name ?? '');
                if ($conversation->type === 'private' && $name === '') {
                    $other = $conversation->participants->firstWhere('id', '!=', $user->id);
                    $name = $other
                        ? (trim((string) ($other->name_first . ' ' . $other->name_last)) ?: (string) $other->username)
                        : 'Private Chat';
                }

                $last = $latest->get($conversation->id);

                return [
                    'id' => (int) $conversation->id,
                    'type' => (string) $conversation->type,
                    'name' => $name !== '' ? $name : ucfirst((string) $conversation->type),
                    'group_username' => $conversation->group_username,
                    'group_code' => $conversation->group_code,
                    'members' => $memberList,
                    'last_message_at' => $last?->created_at?->toIso8601String(),
                ];
            })->values(),
        ]);
    }

    public function searchUsers(Request $request): JsonResponse
    {
        $viewer = $request->user();
        $validated = $request->validate([
            'query' => 'required|string|min:2|max:40',
        ]);

        $query = trim((string) $validated['query']);

        $users = User::query()
            ->select(['id', 'username', 'name_first', 'name_last'])
            ->where('id', '!=', (int) $viewer->id)
            ->where(function ($q) use ($query) {
                $q->where('username', 'like', '%' . $query . '%')
                    ->orWhere('name_first', 'like', '%' . $query . '%')
                    ->orWhere('name_last', 'like', '%' . $query . '%');
            })
            ->orderBy('username')
            ->limit(20)
            ->get();

        return new JsonResponse([
            'data' => $users->map(fn (User $user) => [
                'id' => (int) $user->id,
                'username' => (string) $user->username,
                'display_name' => trim((string) ($user->name_first . ' ' . $user->name_last)) ?: (string) $user->username,
            ])->values(),
        ]);
    }

    public function createPrivate(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'username' => 'required|string|exists:users,username',
        ]);

        $target = User::query()->where('username', $validated['username'])->firstOrFail();
        if ((int) $target->id === (int) $user->id) {
            return new JsonResponse(['error' => 'Cannot create private chat with yourself.'], 422);
        }

        $pair = [(int) min($user->id, $target->id), (int) max($user->id, $target->id)];
        $existing = ChatConversation::query()
            ->where('type', 'private')
            ->where('private_user_low', $pair[0])
            ->where('private_user_high', $pair[1])
            ->first();

        if (!$existing) {
            $existing = ChatConversation::query()->create([
                'type' => 'private',
                'name' => null,
                'private_user_low' => $pair[0],
                'private_user_high' => $pair[1],
                'created_by' => (int) $user->id,
            ]);

            $rows = [
                ['conversation_id' => (int) $existing->id, 'user_id' => (int) $user->id, 'joined_at' => now()],
                ['conversation_id' => (int) $existing->id, 'user_id' => (int) $target->id, 'joined_at' => now()],
            ];
            if ($this->hasParticipantRoleColumn()) {
                $rows[0]['role'] = 'owner';
                $rows[1]['role'] = 'member';
            }
            DB::table('chat_conversation_participants')->insert($rows);
        }

        return new JsonResponse(['data' => ['conversation_id' => (int) $existing->id]], 201);
    }

    public function createGroup(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'name' => 'required|string|min:2|max:80',
            'group_username' => 'required|string|min:3|max:32|regex:/^[a-zA-Z0-9._-]+$/',
            'group_code' => 'sometimes|nullable|string|min:4|max:32|regex:/^[a-zA-Z0-9._-]+$/',
            'member_usernames' => 'sometimes|array|max:50',
            'member_usernames.*' => 'string|exists:users,username',
        ]);
        $groupUsername = strtolower(trim((string) $validated['group_username']));
        if (User::query()->where('username', $groupUsername)->exists()) {
            return new JsonResponse(['error' => 'Group username already used by a user.'], 422);
        }
        if (ChatConversation::query()->where('group_username', $groupUsername)->exists()) {
            return new JsonResponse(['error' => 'Group username is already taken.'], 422);
        }
        $groupCode = trim((string) ($validated['group_code'] ?? ''));
        if ($groupCode === '') {
            $groupCode = strtolower(Str::random(8));
        }
        if (ChatConversation::query()->where('group_code', $groupCode)->exists()) {
            return new JsonResponse(['error' => 'Group code is already taken.'], 422);
        }

        $members = User::query()
            ->whereIn('username', (array) ($validated['member_usernames'] ?? []))
            ->get(['id']);

        $conversation = ChatConversation::query()->create([
            'type' => 'group',
            'name' => trim((string) $validated['name']),
            'group_username' => $groupUsername,
            'group_code' => $groupCode,
            'created_by' => (int) $user->id,
        ]);

        $participantIds = array_unique(array_merge([(int) $user->id], $members->pluck('id')->map(fn ($id) => (int) $id)->all()));
        $rows = array_map(fn ($id) => [
            'conversation_id' => (int) $conversation->id,
            'user_id' => $id,
            'joined_at' => now(),
        ], $participantIds);
        if ($this->hasParticipantRoleColumn()) {
            $rows = array_map(function (array $row) use ($user) {
                $row['role'] = (int) $row['user_id'] === (int) $user->id ? 'owner' : 'member';

                return $row;
            }, $rows);
        }
        DB::table('chat_conversation_participants')->insert($rows);

        return new JsonResponse(['data' => ['conversation_id' => (int) $conversation->id]], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'conversation_id' => 'sometimes|integer|min:1',
            'limit' => 'sometimes|integer|min:1|max:' . self::MAX_LIMIT,
            'since_id' => 'sometimes|integer|min:1',
        ]);

        $conversation = $this->conversationForUser($user, (int) ($validated['conversation_id'] ?? self::GLOBAL_CONVERSATION_ID));
        $limit = (int) ($validated['limit'] ?? self::DEFAULT_LIMIT);
        $sinceId = (int) ($validated['since_id'] ?? 0);

        $query = PublicChatMessage::query()
            ->where('conversation_id', $conversation->id)
            ->with('user:id,username,name_first,name_last', 'replyTo.user:id,username,name_first,name_last')
            ->orderByDesc('id');

        if ($sinceId > 0) {
            $query->where('id', '>', $sinceId);
        }

        $messages = $query->limit($limit)->get();
        if ($sinceId <= 0) {
            $messages = $messages->reverse()->values();
        }

        $this->markMessagesRead($messages->pluck('id')->all(), (int) $user->id, $messages->pluck('user_id')->all());
        $stats = $this->readStats($messages->pluck('id')->all());
        $poll = $this->pollStats($messages->pluck('id')->all(), (int) $user->id);
        $reactions = $this->reactionStats($messages->pluck('id')->all(), (int) $user->id);

        return new JsonResponse([
            'data' => $messages->map(fn (PublicChatMessage $m) => $this->transformMessage($m, (int) $user->id, $stats, $poll, $reactions))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'conversation_id' => 'required|integer|min:1',
            'message' => 'nullable|string|max:' . self::MAX_MESSAGE_LENGTH,
            'media_url' => 'nullable|url|max:2000',
            'media_type' => 'nullable|in:text,image,audio,link',
            'media_name' => 'nullable|string|max:255',
            'media_mime' => 'nullable|string|max:120',
            'reply_to_id' => 'nullable|integer|min:1|exists:public_chat_messages,id',
        ]);

        $conversation = $this->conversationForUser($user, (int) $validated['conversation_id']);

        $body = $this->sanitizeBody((string) ($validated['message'] ?? ''));
        $mediaUrl = $this->sanitizeMediaUrl((string) ($validated['media_url'] ?? ''));
        if ($body === '' && $mediaUrl === '') {
            return new JsonResponse(['error' => 'Message or media is required.'], 422);
        }

        $replyToId = (int) ($validated['reply_to_id'] ?? 0) ?: null;
        if ($replyToId) {
            $replyMessage = PublicChatMessage::query()->whereKey($replyToId)->first();
            if (!$replyMessage || (int) $replyMessage->conversation_id !== (int) $conversation->id) {
                return new JsonResponse(['error' => 'Reply target must be in same conversation.'], 422);
            }
        }

        $message = PublicChatMessage::query()->create([
            'conversation_id' => (int) $conversation->id,
            'user_id' => (int) $user->id,
            'reply_to_id' => $replyToId,
            'mention_usernames' => $this->extractMentions($body),
            'body' => $body !== '' ? $body : null,
            'media_url' => $mediaUrl !== '' ? $mediaUrl : null,
            'media_type' => $this->inferMediaType($validated['media_type'] ?? null, $mediaUrl, (string) ($validated['media_mime'] ?? '')),
            'media_name' => $this->sanitizeFilename((string) ($validated['media_name'] ?? '')),
            'media_mime' => $this->sanitizeMime((string) ($validated['media_mime'] ?? '')),
        ]);

        $message->load('user:id,username,name_first,name_last', 'replyTo.user:id,username,name_first,name_last');

        return new JsonResponse([
            'data' => $this->transformMessage($message, (int) $user->id, ['counts' => [], 'readByOthers' => []], ['byMessage' => [], 'mine' => []], ['byMessage' => [], 'mine' => []]),
        ], 201);
    }

    public function storePoll(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'conversation_id' => 'required|integer|min:1',
            'question' => 'required|string|min:2|max:255',
            'options' => 'required|array|min:2|max:8',
            'options.*' => 'required|string|min:1|max:80',
            'media_url' => 'nullable|url|max:2000',
            'media_name' => 'nullable|string|max:255',
            'media_mime' => 'nullable|string|max:120',
        ]);

        $conversation = $this->conversationForUser($user, (int) $validated['conversation_id']);
        $options = array_values(array_map(fn ($v) => trim((string) $v), $validated['options']));
        $mediaUrl = $this->sanitizeMediaUrl((string) ($validated['media_url'] ?? ''));

        $message = PublicChatMessage::query()->create([
            'conversation_id' => (int) $conversation->id,
            'user_id' => (int) $user->id,
            'media_url' => $mediaUrl !== '' ? $mediaUrl : null,
            'media_type' => $mediaUrl !== '' ? 'image' : 'text',
            'media_name' => $this->sanitizeFilename((string) ($validated['media_name'] ?? '')),
            'media_mime' => $this->sanitizeMime((string) ($validated['media_mime'] ?? '')),
            'poll_question' => trim((string) $validated['question']),
            'poll_options' => $options,
        ]);

        $message->load('user:id,username,name_first,name_last');

        return new JsonResponse([
            'data' => $this->transformMessage($message, (int) $user->id, ['counts' => [], 'readByOthers' => []], ['byMessage' => [], 'mine' => []], ['byMessage' => [], 'mine' => []]),
        ], 201);
    }

    public function votePoll(Request $request, int $message): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'option_index' => 'required|integer|min:0|max:100',
        ]);

        $model = PublicChatMessage::query()->whereKey($message)->firstOrFail();
        $this->conversationForUser($user, (int) $model->conversation_id);

        $options = is_array($model->poll_options) ? array_values($model->poll_options) : [];
        if ($model->poll_question === null || $options === []) {
            return new JsonResponse(['error' => 'This message is not a poll.'], 422);
        }

        $idx = (int) $validated['option_index'];
        if (!array_key_exists($idx, $options)) {
            return new JsonResponse(['error' => 'Invalid poll option.'], 422);
        }

        DB::table('chat_poll_votes')->upsert([
            'message_id' => (int) $model->id,
            'user_id' => (int) $user->id,
            'option_index' => $idx,
            'created_at' => now(),
        ], ['message_id', 'user_id'], ['option_index', 'created_at']);

        $model->load('user:id,username,name_first,name_last', 'replyTo.user:id,username,name_first,name_last');
        $stats = $this->readStats([$model->id]);
        $poll = $this->pollStats([$model->id], (int) $user->id);

        return new JsonResponse([
            'data' => $this->transformMessage($model, (int) $user->id, $stats, $poll, $this->reactionStats([$model->id], (int) $user->id)),
        ]);
    }

    public function updateGroup(Request $request, int $conversation): JsonResponse
    {
        $user = $request->user();
        $model = $this->conversationForUser($user, $conversation);
        if ($model->type !== 'group') {
            return new JsonResponse(['error' => 'Only group can be updated.'], 422);
        }
        if (!$this->isGroupOwner((int) $model->id, (int) $user->id)) {
            return new JsonResponse(['error' => 'Only group owner can change group identity.'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|min:2|max:80',
            'group_username' => 'sometimes|string|min:3|max:32|regex:/^[a-zA-Z0-9._-]+$/',
            'group_code' => 'sometimes|string|min:4|max:32|regex:/^[a-zA-Z0-9._-]+$/',
        ]);

        if (array_key_exists('name', $validated)) {
            $model->name = trim((string) $validated['name']);
        }
        if (array_key_exists('group_username', $validated)) {
            $groupUsername = strtolower(trim((string) $validated['group_username']));
            if (User::query()->where('username', $groupUsername)->exists()) {
                return new JsonResponse(['error' => 'Group username already used by a user.'], 422);
            }
            if (ChatConversation::query()->where('group_username', $groupUsername)->where('id', '!=', $model->id)->exists()) {
                return new JsonResponse(['error' => 'Group username is already taken.'], 422);
            }
            $model->group_username = $groupUsername;
        }
        if (array_key_exists('group_code', $validated)) {
            $groupCode = trim((string) $validated['group_code']);
            if (ChatConversation::query()->where('group_code', $groupCode)->where('id', '!=', $model->id)->exists()) {
                return new JsonResponse(['error' => 'Group code is already taken.'], 422);
            }
            $model->group_code = $groupCode;
        }
        $model->save();

        return new JsonResponse(['ok' => true]);
    }

    public function addGroupMember(Request $request, int $conversation): JsonResponse
    {
        $user = $request->user();
        $model = $this->conversationForUser($user, $conversation);
        if ($model->type !== 'group') {
            return new JsonResponse(['error' => 'Only group supports members.'], 422);
        }
        if (!$this->isGroupAdminOrOwner((int) $model->id, (int) $user->id)) {
            return new JsonResponse(['error' => 'Only admin/owner can add member.'], 403);
        }

        $validated = $request->validate([
            'username' => 'required|string|exists:users,username',
        ]);
        $target = User::query()->where('username', $validated['username'])->firstOrFail();

        DB::table('chat_conversation_bans')
            ->where('conversation_id', (int) $model->id)
            ->where('user_id', (int) $target->id)
            ->delete();

        DB::table('chat_conversation_participants')->upsert([
            'conversation_id' => (int) $model->id,
            'user_id' => (int) $target->id,
            'joined_at' => now(),
        ], ['conversation_id', 'user_id'], ['joined_at']);
        if ($this->hasParticipantRoleColumn()) {
            DB::table('chat_conversation_participants')
                ->where('conversation_id', (int) $model->id)
                ->where('user_id', (int) $target->id)
                ->update(['role' => 'member']);
        }

        return new JsonResponse(['ok' => true]);
    }

    public function kickGroupMember(Request $request, int $conversation, int $member): JsonResponse
    {
        return $this->removeMemberInternal($request, $conversation, $member, false);
    }

    public function banGroupMember(Request $request, int $conversation, int $member): JsonResponse
    {
        return $this->removeMemberInternal($request, $conversation, $member, true);
    }

    public function setGroupAdmin(Request $request, int $conversation, int $member): JsonResponse
    {
        $user = $request->user();
        $model = $this->conversationForUser($user, $conversation);
        if ($model->type !== 'group') {
            return new JsonResponse(['error' => 'Only group supports role change.'], 422);
        }
        if (!$this->isGroupOwner((int) $model->id, (int) $user->id)) {
            return new JsonResponse(['error' => 'Only owner can grant/revoke admin.'], 403);
        }
        if (!$this->hasParticipantRoleColumn()) {
            return new JsonResponse(['error' => 'Role feature not ready. Run chat migration.'], 409);
        }
        $targetRole = DB::table('chat_conversation_participants')
            ->where('conversation_id', (int) $model->id)
            ->where('user_id', $member)
            ->value('role');
        if (!$targetRole) {
            return new JsonResponse(['error' => 'Member not found.'], 404);
        }
        if ($member === (int) $user->id) {
            return new JsonResponse(['error' => 'Owner role cannot be changed.'], 422);
        }

        $validated = $request->validate([
            'admin' => 'required|boolean',
        ]);
        DB::table('chat_conversation_participants')
            ->where('conversation_id', (int) $model->id)
            ->where('user_id', $member)
            ->update(['role' => $validated['admin'] ? 'admin' : 'member']);

        return new JsonResponse(['ok' => true]);
    }

    public function react(Request $request, int $message): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'emoji' => 'required|string|min:1|max:16',
        ]);
        $emoji = trim((string) $validated['emoji']);
        if (!in_array($emoji, self::REACTION_ALLOWLIST, true)) {
            return new JsonResponse(['error' => 'Unsupported reaction.'], 422);
        }
        if (!$this->hasReactionTable()) {
            return new JsonResponse(['error' => 'Reaction feature not ready. Run chat migration.'], 409);
        }

        $model = PublicChatMessage::query()->whereKey($message)->firstOrFail();
        $this->conversationForUser($user, (int) $model->conversation_id);

        $exists = DB::table('public_chat_message_reactions')
            ->where('message_id', (int) $model->id)
            ->where('user_id', (int) $user->id)
            ->where('emoji', $emoji)
            ->exists();

        if ($exists) {
            DB::table('public_chat_message_reactions')
                ->where('message_id', (int) $model->id)
                ->where('user_id', (int) $user->id)
                ->where('emoji', $emoji)
                ->delete();
        } else {
            DB::table('public_chat_message_reactions')->insert([
                'message_id' => (int) $model->id,
                'user_id' => (int) $user->id,
                'emoji' => $emoji,
                'created_at' => now(),
            ]);
        }

        $model->load('user:id,username,name_first,name_last', 'replyTo.user:id,username,name_first,name_last');
        $stats = $this->readStats([$model->id]);
        $poll = $this->pollStats([$model->id], (int) $user->id);
        $reactions = $this->reactionStats([$model->id], (int) $user->id);

        return new JsonResponse([
            'data' => $this->transformMessage($model, (int) $user->id, $stats, $poll, $reactions),
        ]);
    }

    public function update(Request $request, int $message): JsonResponse
    {
        $user = $request->user();
        $model = PublicChatMessage::query()->whereKey($message)->firstOrFail();
        $this->conversationForUser($user, (int) $model->conversation_id);

        if ((int) $model->user_id !== (int) $user->id) {
            return new JsonResponse(['error' => 'You can only edit your own messages.'], 403);
        }

        $validated = $request->validate([
            'message' => 'required|string|max:' . self::MAX_MESSAGE_LENGTH,
        ]);

        $body = $this->sanitizeBody((string) $validated['message']);
        if ($body === '') {
            return new JsonResponse(['error' => 'Message cannot be empty.'], 422);
        }

        $model->body = $body;
        $model->mention_usernames = $this->extractMentions($body);
        $model->edited_at = now();
        $model->save();

        $model->load('user:id,username,name_first,name_last', 'replyTo.user:id,username,name_first,name_last');
        $stats = $this->readStats([$model->id]);
        $poll = $this->pollStats([$model->id], (int) $user->id);

        return new JsonResponse([
            'data' => $this->transformMessage($model, (int) $user->id, $stats, $poll, $this->reactionStats([$model->id], (int) $user->id)),
        ]);
    }

    public function destroy(Request $request, int $message): JsonResponse
    {
        $user = $request->user();
        $model = PublicChatMessage::query()->whereKey($message)->firstOrFail();
        $this->conversationForUser($user, (int) $model->conversation_id);

        if ((int) $model->user_id !== (int) $user->id) {
            return new JsonResponse(['error' => 'You can only delete your own messages.'], 403);
        }

        $model->delete();

        return new JsonResponse([], 204);
    }

    public function markRead(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'conversation_id' => 'required|integer|min:1',
            'message_ids' => 'sometimes|array|max:200',
            'message_ids.*' => 'integer|min:1',
        ]);

        $conversation = $this->conversationForUser($user, (int) $validated['conversation_id']);

        $ids = array_values(array_unique(array_map('intval', $validated['message_ids'] ?? [])));
        $recordsQuery = PublicChatMessage::query()
            ->where('conversation_id', (int) $conversation->id);

        if ($ids !== []) {
            $records = (clone $recordsQuery)
                ->whereIn('id', $ids)
                ->get(['id', 'user_id']);
        } else {
            $records = (clone $recordsQuery)
                ->orderByDesc('id')
                ->limit(self::MAX_LIMIT)
                ->get(['id', 'user_id']);
        }

        $ids = $records->pluck('id')->map(fn ($id) => (int) $id)->all();
        $userIds = $records->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $this->markMessagesRead($ids, (int) $user->id, $userIds);

        return new JsonResponse(['ok' => true]);
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'max:10240',
                'mimetypes:image/jpeg,image/png,image/gif,image/webp,audio/mpeg,audio/ogg,audio/wav,audio/webm,audio/mp4,audio/x-m4a',
            ],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $storedPath = $file->storePublicly('chat-media', 'public');
        $url = Storage::disk('public')->url($storedPath);
        $mime = (string) $file->getMimeType();

        return new JsonResponse([
            'data' => [
                'url' => $url,
                'media_type' => Str::startsWith($mime, 'audio/') ? 'audio' : 'image',
                'media_name' => $this->sanitizeFilename($file->getClientOriginalName()),
                'media_mime' => $this->sanitizeMime($mime),
            ],
        ], 201);
    }

    private function globalConversation(): ChatConversation
    {
        return ChatConversation::query()->firstOrCreate(
            ['id' => self::GLOBAL_CONVERSATION_ID],
            ['type' => 'global', 'name' => 'Global', 'created_by' => null]
        );
    }

    private function conversationForUser(User $user, int $conversationId): ChatConversation
    {
        if ($conversationId <= 0) {
            $conversationId = self::GLOBAL_CONVERSATION_ID;
        }

        $conversation = ChatConversation::query()->whereKey($conversationId)->firstOrFail();
        if ($conversation->type === 'global') {
            return $conversation;
        }

        $isMember = DB::table('chat_conversation_participants')
            ->where('conversation_id', (int) $conversation->id)
            ->where('user_id', (int) $user->id)
            ->exists();

        if (!$isMember) {
            abort(403, 'Not allowed to access this conversation.');
        }

        return $conversation;
    }

    private function isGroupOwner(int $conversationId, int $userId): bool
    {
        if (!$this->hasParticipantRoleColumn()) {
            return ChatConversation::query()->whereKey($conversationId)->where('created_by', $userId)->exists();
        }

        return DB::table('chat_conversation_participants')
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->where('role', 'owner')
            ->exists();
    }

    private function isGroupAdminOrOwner(int $conversationId, int $userId): bool
    {
        if (!$this->hasParticipantRoleColumn()) {
            return $this->isGroupOwner($conversationId, $userId);
        }

        return DB::table('chat_conversation_participants')
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->whereIn('role', ['owner', 'admin'])
            ->exists();
    }

    private function removeMemberInternal(Request $request, int $conversation, int $member, bool $ban): JsonResponse
    {
        $user = $request->user();
        $model = $this->conversationForUser($user, $conversation);
        if ($model->type !== 'group') {
            return new JsonResponse(['error' => 'Only group supports member management.'], 422);
        }
        if (!$this->isGroupAdminOrOwner((int) $model->id, (int) $user->id)) {
            return new JsonResponse(['error' => 'Only admin/owner can manage member.'], 403);
        }
        if (!$this->hasParticipantRoleColumn()) {
            if (!$this->isGroupOwner((int) $model->id, (int) $user->id)) {
                return new JsonResponse(['error' => 'Only group owner can manage member.'], 403);
            }
            if ((int) $member === (int) $model->created_by) {
                return new JsonResponse(['error' => 'Owner cannot be kicked or banned.'], 422);
            }
        }

        $targetRole = DB::table('chat_conversation_participants')
            ->where('conversation_id', (int) $model->id)
            ->where('user_id', $member)
            ->value($this->hasParticipantRoleColumn() ? 'role' : 'user_id');
        if ($targetRole === null) {
            return new JsonResponse(['error' => 'Member not found.'], 404);
        }
        if ($this->hasParticipantRoleColumn() && $targetRole === 'owner') {
            return new JsonResponse(['error' => 'Owner cannot be kicked or banned.'], 422);
        }

        if ($this->hasParticipantRoleColumn()) {
            $actorRole = DB::table('chat_conversation_participants')
                ->where('conversation_id', (int) $model->id)
                ->where('user_id', (int) $user->id)
                ->value('role');
            if ($actorRole === 'admin' && $targetRole === 'admin') {
                return new JsonResponse(['error' => 'Admin cannot manage another admin.'], 403);
            }
        }

        DB::table('chat_conversation_participants')
            ->where('conversation_id', (int) $model->id)
            ->where('user_id', $member)
            ->delete();

        if ($ban) {
            DB::table('chat_conversation_bans')->upsert([
                'conversation_id' => (int) $model->id,
                'user_id' => $member,
                'banned_by' => (int) $user->id,
                'reason' => 'group moderation',
                'created_at' => now(),
            ], ['conversation_id', 'user_id'], ['banned_by', 'reason', 'created_at']);
        }

        return new JsonResponse(['ok' => true]);
    }

    /**
     * @param array<int,int> $messageIds
     * @param array<int,int> $authorIds
     */
    private function markMessagesRead(array $messageIds, int $readerUserId, array $authorIds): void
    {
        if ($messageIds === []) {
            return;
        }

        $authorMap = [];
        foreach ($messageIds as $idx => $id) {
            $authorMap[(int) $id] = (int) ($authorIds[$idx] ?? 0);
        }

        $now = now();
        $rows = [];
        foreach ($messageIds as $id) {
            $id = (int) $id;
            if (($authorMap[$id] ?? 0) === $readerUserId) {
                continue;
            }
            $rows[] = [
                'message_id' => $id,
                'user_id' => $readerUserId,
                'read_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('public_chat_message_reads')->upsert($rows, ['message_id', 'user_id'], ['read_at']);
        }
    }

    /**
     * @param array<int,int> $messageIds
     * @return array{counts: array<int,int>, readByOthers: array<int,bool>}
     */
    private function readStats(array $messageIds): array
    {
        if ($messageIds === []) {
            return ['counts' => [], 'readByOthers' => []];
        }

        $counts = DB::table('public_chat_message_reads')
            ->selectRaw('message_id, COUNT(*) as aggregate')
            ->whereIn('message_id', $messageIds)
            ->groupBy('message_id')
            ->pluck('aggregate', 'message_id')
            ->map(fn ($v) => (int) $v)
            ->toArray();

        $readByOthers = DB::table('public_chat_message_reads as r')
            ->join('public_chat_messages as m', 'm.id', '=', 'r.message_id')
            ->whereIn('r.message_id', $messageIds)
            ->whereColumn('r.user_id', '!=', 'm.user_id')
            ->groupBy('r.message_id')
            ->pluck('r.message_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->toArray();

        return ['counts' => $counts, 'readByOthers' => $readByOthers];
    }

    /**
     * @param array<int,int> $messageIds
     * @return array{byMessage: array<int,array<int,int>>, mine: array<int,int>}
     */
    private function pollStats(array $messageIds, int $viewerId): array
    {
        if ($messageIds === []) {
            return ['byMessage' => [], 'mine' => []];
        }

        $rows = DB::table('chat_poll_votes')
            ->select(['message_id', 'option_index', DB::raw('COUNT(*) as aggregate')])
            ->whereIn('message_id', $messageIds)
            ->groupBy(['message_id', 'option_index'])
            ->get();

        $byMessage = [];
        foreach ($rows as $row) {
            $mid = (int) $row->message_id;
            $opt = (int) $row->option_index;
            $byMessage[$mid] = $byMessage[$mid] ?? [];
            $byMessage[$mid][$opt] = (int) $row->aggregate;
        }

        $mine = DB::table('chat_poll_votes')
            ->whereIn('message_id', $messageIds)
            ->where('user_id', $viewerId)
            ->pluck('option_index', 'message_id')
            ->map(fn ($v) => (int) $v)
            ->toArray();

        return ['byMessage' => $byMessage, 'mine' => $mine];
    }

    /**
     * @param array<int,int> $messageIds
     * @return array{byMessage: array<int,array<string,int>>, mine: array<int,array<string,bool>>}
     */
    private function reactionStats(array $messageIds, int $viewerId): array
    {
        if ($messageIds === [] || !$this->hasReactionTable()) {
            return ['byMessage' => [], 'mine' => []];
        }

        $rows = DB::table('public_chat_message_reactions')
            ->select(['message_id', 'emoji', DB::raw('COUNT(*) as aggregate')])
            ->whereIn('message_id', $messageIds)
            ->groupBy(['message_id', 'emoji'])
            ->get();

        $byMessage = [];
        foreach ($rows as $row) {
            $mid = (int) $row->message_id;
            $emoji = (string) $row->emoji;
            $byMessage[$mid] = $byMessage[$mid] ?? [];
            $byMessage[$mid][$emoji] = (int) $row->aggregate;
        }

        $mineRows = DB::table('public_chat_message_reactions')
            ->whereIn('message_id', $messageIds)
            ->where('user_id', $viewerId)
            ->get(['message_id', 'emoji']);

        $mine = [];
        foreach ($mineRows as $row) {
            $mid = (int) $row->message_id;
            $emoji = (string) $row->emoji;
            $mine[$mid] = $mine[$mid] ?? [];
            $mine[$mid][$emoji] = true;
        }

        return ['byMessage' => $byMessage, 'mine' => $mine];
    }

    private function hasReactionTable(): bool
    {
        if (self::$hasReactionTable === null) {
            self::$hasReactionTable = Schema::hasTable('public_chat_message_reactions');
        }

        return self::$hasReactionTable;
    }

    private function hasParticipantRoleColumn(): bool
    {
        if (self::$hasParticipantRoleColumn === null) {
            self::$hasParticipantRoleColumn = Schema::hasColumn('chat_conversation_participants', 'role');
        }

        return self::$hasParticipantRoleColumn;
    }

    /**
     * @param array{counts: array<int,int>, readByOthers: array<int,bool>} $stats
     * @param array{byMessage: array<int,array<int,int>>, mine: array<int,int>} $poll
     */
    private function transformMessage(PublicChatMessage $message, int $viewerUserId, array $stats, array $poll, array $reactions): array
    {
        $username = (string) ($message->user?->username ?? 'unknown');
        $displayName = trim((string) (($message->user?->name_first ?? '') . ' ' . ($message->user?->name_last ?? '')));

        $pollPayload = null;
        $options = is_array($message->poll_options) ? array_values($message->poll_options) : [];
        if ($message->poll_question !== null && $options !== []) {
            $counts = $poll['byMessage'][(int) $message->id] ?? [];
            $pollPayload = [
                'question' => (string) $message->poll_question,
                'options' => array_map(
                    fn ($text, $idx) => ['text' => (string) $text, 'votes' => (int) ($counts[(int) $idx] ?? 0)],
                    $options,
                    array_keys($options)
                ),
                'my_vote' => $poll['mine'][(int) $message->id] ?? null,
            ];
        }

        return [
            'id' => (int) $message->id,
            'conversation_id' => (int) $message->conversation_id,
            'user_id' => (int) $message->user_id,
            'username' => $username,
            'display_name' => $displayName !== '' ? $displayName : $username,
            'mentions' => array_values(array_unique(array_map('strval', (array) ($message->mention_usernames ?? [])))),
            'body' => $message->body,
            'media_url' => $message->media_url,
            'media_type' => $message->media_type,
            'media_mime' => $message->media_mime,
            'media_name' => $message->media_name,
            'edited_at' => optional($message->edited_at)?->toIso8601String(),
            'created_at' => $message->created_at->toIso8601String(),
            'updated_at' => $message->updated_at->toIso8601String(),
            'is_own' => (int) $message->user_id === $viewerUserId,
            'read_count' => (int) ($stats['counts'][(int) $message->id] ?? 0),
            'is_read_by_others' => (bool) ($stats['readByOthers'][(int) $message->id] ?? false),
            'reply' => $message->replyTo ? [
                'id' => (int) $message->replyTo->id,
                'username' => (string) ($message->replyTo->user?->username ?? 'unknown'),
                'display_name' => trim((string) (($message->replyTo->user?->name_first ?? '') . ' ' . ($message->replyTo->user?->name_last ?? '')))
                    ?: (string) ($message->replyTo->user?->username ?? 'unknown'),
                'body' => (string) ($message->replyTo->body ?? ''),
            ] : null,
            'poll' => $pollPayload,
            'reactions' => collect($reactions['byMessage'][(int) $message->id] ?? [])->map(function ($count, $emoji) use ($reactions, $message) {
                return [
                    'emoji' => (string) $emoji,
                    'count' => (int) $count,
                    'mine' => (bool) (($reactions['mine'][(int) $message->id] ?? [])[$emoji] ?? false),
                ];
            })->values()->toArray(),
        ];
    }

    /** @return array<int,string> */
    private function extractMentions(string $body): array
    {
        if ($body === '') {
            return [];
        }

        preg_match_all('/@([a-zA-Z0-9._-]{3,32})/', $body, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[1] ?? [])));
    }

    private function sanitizeBody(string $body): string
    {
        $body = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $body) ?? '';

        return mb_substr(trim($body), 0, self::MAX_MESSAGE_LENGTH);
    }

    private function sanitizeMediaUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return '';
        }

        return mb_substr($url, 0, 2000);
    }

    private function sanitizeFilename(string $name): ?string
    {
        $clean = trim(str_replace(["\r", "\n", "\t"], '', $name));

        return $clean === '' ? null : mb_substr($clean, 0, 255);
    }

    private function sanitizeMime(string $mime): ?string
    {
        $clean = trim(strtolower($mime));
        if ($clean === '' || preg_match('/^[a-z0-9.+\/-]+$/', $clean) !== 1) {
            return null;
        }

        return mb_substr($clean, 0, 120);
    }

    private function inferMediaType(?string $requestedType, string $mediaUrl, string $mime): string
    {
        if (in_array($requestedType, ['text', 'image', 'audio', 'link'], true)) {
            return $requestedType;
        }

        $mime = strtolower(trim($mime));
        if (Str::startsWith($mime, 'image/')) {
            return 'image';
        }
        if (Str::startsWith($mime, 'audio/')) {
            return 'audio';
        }
        if ($mediaUrl === '') {
            return 'text';
        }
        if (preg_match('/\.(png|jpg|jpeg|gif|webp)(\?.*)?$/i', $mediaUrl) === 1) {
            return 'image';
        }
        if (preg_match('/\.(mp3|ogg|wav|m4a|webm|mp4)(\?.*)?$/i', $mediaUrl) === 1) {
            return 'audio';
        }

        return 'link';
    }
}
