<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row per rubric-scoring attempt — one per rubric_judgment expectation
 * per case execution, plus one per repeat within a consistency sample.
 * Written once, never updated — a correction is a separate
 * EvalJudgmentOverride row, never a mutation of this one.
 *
 * No EloquentMultiChainBridge, no SoftDeletes — derived per-execution
 * telemetry, the EvalCaseResult shape not the EvalCaseVersion shape.
 *
 * Unlike every other UUID model in this package, this model's `id` is
 * never minted by a creating listener — it is always pre-minted by the
 * caller (EvalCaseExecutor / EvalJudgmentConsistencyService) so it can be
 * referenced from the sibling eval_case_results.expectation_results[]
 * entry written in the same logical operation.
 */
class EvalJudgment extends Model
{
    protected $table = 'eval_judgments';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'id',
        'eval_case_result_id',
        'eval_case_version_id',
        'expectation_index',
        'criteria',
        'response_text',
        'status',
        'score',
        'justification',
        'unjudged_reason',
        'model',
        'server_id',
        'conversation_id',
        'consistency_sample_id',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            // $timestamps = false above means Eloquent's HasTimestamps
            // trait never sets created_at automatically — capture it
            // explicitly here, the UsageRecord/EvalCaseResult precedent,
            // so it can never drift from the DB's own useCurrent()
            // default under a frozen test clock.
            if (!$model->created_at) {
                $model->created_at = now();
            }
        });
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(EvalJudgmentOverride::class, 'judgment_id')->orderBy('created_at');
    }
}
