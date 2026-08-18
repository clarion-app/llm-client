<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * McpClientServerStatus -- local-only model tracking the reachability
 * and refresh state of one McpClientServer. A field-for-field mirror of
 * ServerStatus, with tool_count standing in for model_count.
 *
 * Does NOT use EloquentMultiChainBridge, matching ServerStatus's own
 * precedent for this category of derived, frequently-changing data.
 */
class McpClientServerStatus extends Model
{
    use HasFactory;

    protected $table = 'mcp_client_server_statuses';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'server_id',
        'connection_status',
        'last_error',
        'tool_count',
        'refresh_started_at',
        'refresh_finished_at',
        'last_reachable_at',
        'triggered_by',
    ];

    protected $casts = [
        'tool_count' => 'integer',
        'refresh_started_at' => 'datetime',
        'refresh_finished_at' => 'datetime',
        'last_reachable_at' => 'datetime',
    ];

    /**
     * Generate a UUID before the model is first saved.
     */
    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });
    }

    /**
     * The server this status row belongs to.
     * Cascade delete is enforced by the migration foreign key.
     */
    public function server()
    {
        return $this->belongsTo(McpClientServer::class, 'server_id');
    }
}
