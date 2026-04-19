<?php

namespace Pterodactyl\Models;

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatConversation extends Model
{
    private static ?bool $hasParticipantRoleColumn = null;

    protected $table = 'chat_conversations';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'private_user_low' => 'integer',
        'private_user_high' => 'integer',
    ];

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsToMany<User> */
    public function participants(): BelongsToMany
    {
        $relation = $this->belongsToMany(User::class, 'chat_conversation_participants', 'conversation_id', 'user_id')
            ->withPivot('joined_at');

        if (self::$hasParticipantRoleColumn === null) {
            self::$hasParticipantRoleColumn = Schema::hasColumn('chat_conversation_participants', 'role');
        }

        if (self::$hasParticipantRoleColumn) {
            $relation->withPivot('role');
        }

        return $relation;
    }

    /** @return HasMany<PublicChatMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(PublicChatMessage::class, 'conversation_id');
    }
}
