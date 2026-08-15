<?php

namespace ClarionApp\LlmClient\Models;

use ClarionApp\LlmClient\Casts\PlainDecimalCast;
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
        // Not a native numeric cast, which would form a float -- these are
        // decimal(20,10) columns (the same scale as CostReservation's own
        // estimated_amount/actual_amount), and a native cast would risk a
        // lossy float re-entering a monetary figure on read, including
        // under SQLite's NUMERIC storage-affinity quirk the test harness
        // runs under (ModelPrice's own docblock, Decimal's own docblock).
        // Phase 3 (T004/T006) omitted this cast; Phase 4's costForRequest()
        // read-path test (T027/T031) is what surfaced the gap.
        'estimated_additional_cost' => PlainDecimalCast::class.':10',
        'actual_additional_cost' => PlainDecimalCast::class.':10',
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
