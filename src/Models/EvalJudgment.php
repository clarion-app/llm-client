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

    /**
     * Oldest correction first — the order a reader wants when looking at
     * a judgment's full history. `id` is the tie-break because
     * eval_judgment_overrides.created_at is only second-precision while
     * its id is time-ordered, so two corrections recorded within the same
     * second still sort in the order they were actually made.
     */
    public function overrides(): HasMany
    {
        return $this->hasMany(EvalJudgmentOverride::class, 'judgment_id')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * This judgment's current effective (score, justification): the
     * latest override if one exists, else the judgment's own original
     * values. Computed from the eager-loaded overrides() relation — no
     * new query per judgment when the caller has already eager-loaded it
     * with with('overrides').
     *
     * @return array{score: ?int, justification: ?string, overridden: bool, overridden_by: ?string, overridden_at: ?string}
     */
    public function effective(): array
    {
        // Sorted here rather than trusting the caller's load order, and
        // by (created_at, id) rather than created_at alone — a plain
        // created_at sort leaves two corrections made within the same
        // wall-clock second tied, and a stable sort then hands back the
        // *earliest* of them, silently reinstating a correction the
        // operator has already superseded.
        $latest = $this->overrides
            ->sortBy([['created_at', 'asc'], ['id', 'asc']])
            ->last();

        return [
            'score' => $latest->score ?? $this->score,
            'justification' => $latest->justification ?? $this->justification,
            'overridden' => $latest !== null,
            'overridden_by' => $latest->user_id ?? null,
            'overridden_at' => optional($latest?->created_at)->toJSON(),
        ];
    }
}
