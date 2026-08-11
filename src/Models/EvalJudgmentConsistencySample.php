<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per operator-requested consistency check — a fixed response,
 * judged sample_size times against one rubric expectation's pinned
 * criteria. Written once, in full, when the request completes; there is
 * no in-progress state.
 *
 * No EloquentMultiChainBridge, no SoftDeletes. Same id-minting shape as
 * EvalJudgmentOverride — this table's own id is never pre-minted by a
 * caller.
 */
class EvalJudgmentConsistencySample extends Model
{
    protected $table = 'eval_judgment_consistency_samples';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'id',
        'eval_case_id',
        'eval_case_version_id',
        'expectation_index',
        'source_eval_case_result_id',
        'response_text',
        'sample_size',
        'judged_count',
        'unjudged_count',
        'scores',
        'score_min',
        'score_max',
        'score_mean',
        'flag_threshold_used',
        'flagged_unstable',
        'requested_by',
        'created_at',
    ];

    protected $casts = [
        'scores' => 'array',
        'flagged_unstable' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (!$model->id) {
                $model->id = (string) \Illuminate\Support\Str::uuid();
            }

            if (!$model->created_at) {
                $model->created_at = now();
            }
        });
    }
}
