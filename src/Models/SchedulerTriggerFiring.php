<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The record that one logical trigger event has already been claimed, and the
 * run it produced.
 *
 * IMPORTANT: the once-per-event latch is won via
 * DB::table('scheduler_trigger_firings')->insertOrIgnore([...]) returning 1 —
 * the unique index on (trigger_id, fire_key) IS the atomic test-and-set. It is
 * NOT won through this model: an Eloquent create() would throw on the
 * duplicate rather than reporting "someone else already claimed it", and a
 * SELECT-then-INSERT would race. This class exists for reads and audit only.
 *
 * Derived, append-only bookkeeping — deliberately not bridged and not soft
 * deleted, matching BudgetThresholdNotification.
 */
class SchedulerTriggerFiring extends Model
{
    protected $table = 'scheduler_trigger_firings';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false; // only created_at, useCurrent() — matches BudgetThresholdNotification

    protected $fillable = [
        'id',
        'trigger_id',
        'fire_key',
        'run_id',
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
