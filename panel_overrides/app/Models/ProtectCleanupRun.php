<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProtectCleanupRun extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    protected $table = 'protect_cleanup_runs';

    protected $fillable = [
        'user_id',
        'status',
        'checked_servers',
        'deleted_servers',
        'skipped_online',
        'skipped_unverified',
        'deleted_users',
        'skipped_admins',
        'skipped_users',
        'reset_admin_markers',
        'failed_count',
        'messages',
        'last_error_message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'messages' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
