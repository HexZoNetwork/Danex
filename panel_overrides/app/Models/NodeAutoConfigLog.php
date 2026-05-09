<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NodeAutoConfigLog extends Model
{
    use HasFactory;

    protected $table = 'node_auto_config_logs';

    protected $fillable = [
        'run_id',
        'level',
        'step',
        'event',
        'message',
        'context',
        'created_at_override',
    ];

    protected $casts = [
        'context' => 'array',
        'created_at_override' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(NodeAutoConfigRun::class, 'run_id');
    }
}
