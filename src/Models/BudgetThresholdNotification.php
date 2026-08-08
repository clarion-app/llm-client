<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The record that a budget warning of a given kind already fired for a
 * given scope in a given period.
 *
 * IMPORTANT: the once-per-period latch is won via
 * DB::table('budget_threshold_notifications')->insertOrIgnore([...])
 * returning 1 — the unique index on
 * (scope_type, scope_id, period_type, period_start, kind) IS the atomic
 * test-and-set. It is NOT won through this model: an Eloquent create()
 * would throw on the duplicate rather than reporting "someone else already
 * fired it", and a SELECT-then-INSERT would race. This class exists for
 * reads and audit only.
 *
 * Derived, per-period bookkeeping — deliberately not bridged and not soft
 * deleted, matching CostSummary/UsageSummary.
 */
class BudgetThresholdNotification extends Model
{
    protected $table = 'budget_threshold_notifications';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false; // only created_at, useCurrent() — matches CostSummary

    public const KIND_APPROACH = 'approach';
    public const KIND_REACHED = 'reached';

    protected $fillable = [
        'id',
        'scope_type',
        'scope_id',
        'period_type',
        'period_start',
        'kind',
        'ceiling_id',
        'consumption_at_fire',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (!$model->id) {
                $model->id = (string) Str::uuid();
            }
        });
    }
}
