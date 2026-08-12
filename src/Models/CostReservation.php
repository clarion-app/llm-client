<?php

namespace ClarionApp\LlmClient\Models;

use ClarionApp\LlmClient\Casts\PlainDecimalCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One row per admitted unit of work's reservation: the identity a release,
 * a reconciliation, and the abandonment sweep all key on (data-model.md §2).
 *
 * Derived, frequently-written bookkeeping — no EloquentMultiChainBridge,
 * not bridged (Constitution §III), matching CostSummary/
 * BudgetThresholdNotification's own precedent, one level down from
 * BudgetReservationLedger.
 */
class CostReservation extends Model
{
    protected $table = 'cost_reservations';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    public const STATUS_HELD = 'held';
    public const STATUS_RECONCILED = 'reconciled';
    public const STATUS_RELEASED = 'released';
    public const STATUS_ABANDONED = 'abandoned';

    protected $fillable = [
        'scope_keys',
        'user_id',
        'conversation_id',
        'run_id',
        'work_kind',
        'estimated_amount',
        'actual_amount',
        'status',
        'held_at',
        'resolved_at',
    ];

    protected $casts = [
        'scope_keys' => 'array',
        // Not a native numeric cast — see ModelPrice's PlainDecimalCast
        // docblock; these figures feed bcadd()/bcsub() directly.
        'estimated_amount' => PlainDecimalCast::class.':10',
        'actual_amount' => PlainDecimalCast::class.':10',
        'held_at' => 'datetime',
        'resolved_at' => 'datetime',
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
