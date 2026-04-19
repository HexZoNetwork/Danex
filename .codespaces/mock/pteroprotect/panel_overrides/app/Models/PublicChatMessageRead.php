<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $message_id
 * @property int $user_id
 * @property \Illuminate\Support\Carbon $read_at
 */
class PublicChatMessageRead extends Model
{
    public $timestamps = false;

    protected $table = 'public_chat_message_reads';

    protected $guarded = [];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<PublicChatMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(PublicChatMessage::class, 'message_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
