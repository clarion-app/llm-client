<?php

namespace ClarionApp\LlmClient\Models;

use ClarionApp\LlmClient\ValueObjects\EvalCaseOutcome;
use Illuminate\Database\Eloquent\Model;

/**
 * The durable, operator-facing outcome of one case within one run
 * (FR-003, FR-009, data-model.md §3). Written exactly once per
 * (run_id, eval_case_id) pair by EvalCaseExecutor, immediately after the
 * case finishes — never updated afterward (the EvalCaseVersion
 * append-once discipline). No EloquentMultiChainBridge, no SoftDeletes,
 * no updated_at (research.md D14; the UsageRecord/ToolInvocationRecord
 * precedent for an insert-only telemetry row).
 */
class EvalCaseResult extends Model
{
    protected $table = 'eval_case_results';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'run_id',
        'eval_run_case_id',
        'eval_case_id',
        'eval_case_version_id',
        'conversation_id',
        'outcome',
        'produced_response',
        'attempted_actions',
        'expectation_results',
        'error_message',
        'created_at',
    ];

    protected $casts = [
        'outcome' => EvalCaseOutcome::class,
        'attempted_actions' => 'array',
        'expectation_results' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (!$model->id) {
                $model->id = (string) \Illuminate\Support\Str::uuid();
            }

            // $timestamps = false above means Eloquent's HasTimestamps
            // trait never sets created_at automatically — capture it
            // explicitly here, the UsageRecord precedent, so it can never
            // drift from the DB's own useCurrent() default under a frozen
            // test clock.
            if (!$model->created_at) {
                $model->created_at = now();
            }
        });
    }
}
