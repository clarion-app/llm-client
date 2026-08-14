<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Model;

class Delegation extends Model
{
    protected $table = 'agent_delegations';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'parent_conversation_id',
        'parent_agent_id',
        'helper_agent_id',
        'helper_conversation_id',
        'helper_agent_version_id',
        'owner_user_id',
        'task',
        'context',
        'depth',
        'status',
        'parent_run_id',
        'parent_action_id',
        'helper_run_id',
        'outcome_summary',
        'started_at',
        'completed_at',
        'result_status',
        'result_reason',
        'result_summary',
        'result_output',
        'result_undone',
        'result_truncated',
    ];

    protected $casts = [
        'depth' => 'integer',
        'status' => 'string',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'result_truncated' => 'boolean',
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
