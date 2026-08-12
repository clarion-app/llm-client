<?php

namespace ClarionApp\LlmClient\Models;

use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;
use ClarionApp\LlmClient\Casts\PlainDecimalCast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One rung of the operator-authored reduction ladder (data-model.md §1).
 *
 * Uses EloquentMultiChainBridge like SpendingCeiling/RateLimit/
 * ConversationWorkCeiling: operator-authored, low-volume, durable
 * configuration rather than derived high-volume data.
 *
 * Carries no explicit ordering column — `axis` + `threshold_ratio` together
 * are both the configuration and DegradationGate::evaluate()'s own
 * margin-based selection key (research.md D7/D12). ReductionLadderService
 * is the sole write path and is what keeps the live set free of ambiguous
 * (axis, threshold_ratio) ties.
 */
class ReductionStep extends Model
{
    use HasFactory, EloquentMultiChainBridge, SoftDeletes;

    protected $table = 'reduction_steps';

    protected $fillable = [
        'axis',
        'threshold_ratio',
        'substitute_model',
        'substitute_server_id',
        'withheld_tools',
        'history_budget_ratio',
        'enabled',
    ];

    protected $casts = [
        'threshold_ratio' => PlainDecimalCast::class.':4',
        'history_budget_ratio' => PlainDecimalCast::class.':4',
        'withheld_tools' => 'array',
        'enabled' => 'boolean',
    ];
}
