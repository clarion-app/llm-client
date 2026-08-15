<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Model;

class ConsensusRequest extends Model
{
    protected $table = 'consensus_requests';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'conversation_id',
        'owner_user_id',
        'coordinator_agent_id',
        'question',
        'answer_message_id',
        'batch_id',
        'dispatched_count',
        'quorum_required',
        'successful_count',
        'status',
        'agreement_classification',
        'reconciled_answer',
        'disagreement_detail',
        'independence_note',
        'estimated_additional_cost',
        'actual_additional_cost',
        'failure_reason',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'dispatched_count' => 'integer',
        'quorum_required' => 'integer',
        'successful_count' => 'integer',
        'status' => 'string',
        'agreement_classification' => 'string',
        'disagreement_detail' => 'array',
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
