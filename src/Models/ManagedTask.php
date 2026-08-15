<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Model;

class ManagedTask extends Model
{
    protected $table = 'managed_tasks';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'conversation_id',
        'owner_user_id',
        'manager_agent_id',
        'original_request',
        'status',
        'round_ceiling',
        'rounds_used',
        'max_seconds',
        'last_progress_at',
        'final_response',
        'shortfall_note',
        'conflict_note',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'status' => 'string',
        'round_ceiling' => 'integer',
        'rounds_used' => 'integer',
        'max_seconds' => 'integer',
        'last_progress_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (!$model->id) {
                $model->id = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }
}
