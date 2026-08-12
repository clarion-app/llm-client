<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One designate-or-move event for an agent's reference run — append-only,
 * never a mutation of an earlier designation. "Current reference" and
 * "reference active as of timestamp T" are both derived reads over this
 * table (latest row wins), never a separate stored pointer.
 *
 * No EloquentMultiChainBridge, no SoftDeletes. This is operational
 * telemetry about which run an operator picked as a baseline, not
 * persistent user-owned data — the EvalJudgmentOverride/UsageRecord
 * shape, not the EvalSuite/EvalCase shape. Like EvalJudgmentOverride,
 * this table's own id is never pre-minted by a caller, so a creating
 * listener mints it here.
 */
class EvalReferenceDesignation extends Model
{
    protected $table = 'eval_reference_designations';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'id',
        'agent_label',
        'run_id',
        'designated_by',
        'created_at',
    ];

    // $timestamps = false above means Eloquent's default date-casting for
    // created_at (which only kicks in when timestamps are enabled) never
    // applies here — cast it explicitly so callers reading created_at get
    // a Carbon instance, not a raw DB string (the EvalJudgmentOverride
    // precedent).
    protected $casts = [
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (!$model->id) {
                // A time-ordered UUID rather than a plain random one:
                // created_at is only second-precision, so two
                // designations for the same agent within one wall-clock
                // second (an operator double-clicking "designate," or two
                // API calls racing) must still sort in the order they
                // actually happened for "latest wins" to be well-defined.
                $model->id = (string) \Illuminate\Support\Str::orderedUuid();
            }

            if (!$model->created_at) {
                $model->created_at = now();
            }
        });
    }
}
