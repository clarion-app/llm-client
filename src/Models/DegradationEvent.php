<?php

namespace ClarionApp\LlmClient\Models;

use ClarionApp\LlmClient\Casts\PlainDecimalCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One row per response that was actually degraded — written exactly once,
 * by DegradationGate::linkRun(), at the same moment RunTraceRecorder::
 * openRun() mints a fresh run (data-model.md §3, research.md D3).
 *
 * Derived, enforcement-path bookkeeping — no EloquentMultiChainBridge, not
 * bridged (Constitution §III), matching CostReservation's own shape.
 */
class DegradationEvent extends Model
{
    protected $table = 'degradation_events';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'run_id',
        'conversation_id',
        'user_id',
        'reduction_step_id',
        'axis',
        'ratio',
        'applied_at',
    ];

    protected $casts = [
        'ratio' => PlainDecimalCast::class.':4',
        'applied_at' => 'datetime',
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
