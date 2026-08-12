<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Day-bucketed rollup of pass/fail/needs_human_review/errored/unjudged
 * counts, upserted at write time via the same insertOrIgnore +
 * atomic-increment idiom CostSummary/ToolReliabilitySummary already use.
 * Derived, frequently-written data — no EloquentMultiChainBridge, no
 * SoftDeletes, matching the CostSummary/ToolReliabilitySummary/UsageSummary
 * precedent (Constitution §III).
 */
class EvalPassRateSummary extends Model
{
    protected $table = 'eval_pass_rate_summaries';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false; // only updated_at, useCurrent() — matches CostSummary

    protected $fillable = [
        'id',
        'agent_label',
        'period_date',
        'pass_count',
        'fail_count',
        'needs_human_review_count',
        'errored_count',
        'unjudged_count',
        'total_count',
        'updated_at',
    ];

    protected $casts = [
        // Explicit Y-m-d format: a bare 'date' cast still persists via the
        // connection's full datetime format on write (Eloquent's own
        // fromDateTime()/getDateFormat() behavior), which would otherwise
        // desynchronize this column's stored value from every plain
        // 'Y-m-d' string this table's raw-query writers (the rollup
        // service, the recompute command) already use.
        'period_date' => 'date:Y-m-d',
        'pass_count' => 'integer',
        'fail_count' => 'integer',
        'needs_human_review_count' => 'integer',
        'errored_count' => 'integer',
        'unjudged_count' => 'integer',
        'total_count' => 'integer',
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
