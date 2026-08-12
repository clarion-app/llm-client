<?php

namespace ClarionApp\LlmClient\Models;

use ClarionApp\LlmClient\Casts\PlainDecimalCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The atomic anchor: one durable row per scope that has ever had an
 * applicable ceiling. This is what ReservationLedger::reserve()'s
 * compare-and-set UPDATE (research.md D5) contends on.
 *
 * Derived, frequently-written bookkeeping — no EloquentMultiChainBridge,
 * not bridged (Constitution §III), matching CostSummary/
 * BudgetThresholdNotification's own precedent, not the
 * SpendingCeiling/ModelPrice operator-configuration shape.
 */
class BudgetReservationLedger extends Model
{
    protected $table = 'budget_reservation_ledger';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false; // only updated_at, useCurrent() — matches CostSummary

    protected $fillable = [
        'scope_type',
        'scope_id',
        'reserved_total',
    ];

    protected $casts = [
        // Not a native numeric cast, which would form a float — this
        // figure feeds bcadd()/bcsub() directly (see ModelPrice's own
        // PlainDecimalCast docblock for why).
        'reserved_total' => PlainDecimalCast::class.':10',
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
