<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * ServerStatus — local-only model tracking the health and model cache
 * state for each LLM server.
 *
 * Does NOT use EloquentMultiChainBridge (Constitution §III).
 */
class ServerStatus extends Model
{
    use HasFactory;

    protected $table = 'llm_server_statuses';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'server_id',
        'connection_status',
        'last_outcome',
        'last_error',
        'model_count',
        'refresh_started_at',
        'refresh_finished_at',
        'triggered_by',
    ];

    protected $casts = [
        'model_count' => 'integer',
        'refresh_started_at' => 'datetime',
        'refresh_finished_at' => 'datetime',
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
        return $this->belongsTo(Server::class);
    }
}
