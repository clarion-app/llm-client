<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The persisted record of every attempted inter-agent message send —
 * delivered or not (107-agent-message-protocol, data-model.md §1). Plain
 * Eloquent, not EloquentMultiChainBridge-backed, no SoftDeletes (research.md
 * D7 — matches Delegation's/AgentRun's own established precedent for
 * write-once inter-agent audit rows). AgentMessageService is the single
 * write path for this table.
 */
class AgentMessage extends Model
{
    protected $table = 'agent_messages';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'from_agent_id',
        'to_agent_id',
        'owner_user_id',
        'conversation_id',
        'run_id',
        'content',
        'context',
        'expected_response',
        'status',
        'refusal_reason',
        'size_bytes',
    ];

    protected $casts = [
        'content' => 'array',
        'context' => 'array',
        'size_bytes' => 'integer',
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
