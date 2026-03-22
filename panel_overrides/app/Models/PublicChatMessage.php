<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $conversation_id
 * @property int $user_id
 * @property int|null $reply_to_id
 * @property array<int,string>|null $mention_usernames
 * @property string|null $body
 * @property string|null $media_url
 * @property string $media_type
 * @property string|null $media_mime
 * @property string|null $media_name
 * @property string|null $poll_question
 * @property array<int,string>|null $poll_options
 * @property \Illuminate\Support\Carbon|null $edited_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class PublicChatMessage extends Model
{
    protected $table = 'public_chat_messages';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'edited_at' => 'datetime',
        'mention_usernames' => 'array',
        'poll_options' => 'array',
    ];

    /**
     * @return BelongsTo<ChatConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<PublicChatMessage, $this>
     */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    /**
     * @return HasMany<PublicChatMessageRead, $this>
     */
    public function reads(): HasMany
    {
        return $this->hasMany(PublicChatMessageRead::class, 'message_id');
    }
}
