<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatPollVote extends Model
{
    public $timestamps = false;

    protected $table = 'chat_poll_votes';

    protected $guarded = [];

    /** @return BelongsTo<PublicChatMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(PublicChatMessage::class, 'message_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
