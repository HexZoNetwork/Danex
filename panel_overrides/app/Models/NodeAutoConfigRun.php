<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NodeAutoConfigRun extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELED = 'canceled';

    protected $table = 'node_auto_config_runs';

    protected $fillable = [
        'node_id',
        'user_id',
        'status',
        'target_host',
        'target_port',
        'target_username',
        'host_fingerprint',
        'bootstrap_auth_type',
        'encrypted_password',
        'encrypted_private_key',
        'wings_port',
        'fallback_port_range',
        'host_key_policy',
        'max_ssh_timeout_sec',
        'correlation_id',
        'last_error_code',
        'last_error_message',
        'started_at',
        'finished_at',
        'requested_payload',
    ];

    protected $casts = [
        'requested_payload' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(NodeAutoConfigLog::class, 'run_id');
    }
}
