<?php

namespace ClarionApp\LlmClient\Models;

use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An operator-authored per-conversation work ceiling. One entity covers
 * both scope kinds — the default that applies to a conversation with no
 * override, and a single conversation's override (a waiver being an
 * override with waived = true) — because a raise, a lower, and a waiver
 * carry exactly the same columns as the default they replace.
 *
 * Uses EloquentMultiChainBridge like RateLimit/SpendingCeiling/ModelPrice/
 * Server: operator-authored, low-volume, durable configuration rather than
 * derived high-volume data. The work count itself is not modeled here at
 * all — it lives in a Cache key, read and written solely by
 * ConversationWorkCounter.
 *
 * There is no unique constraint on (scope_type, scope_id) — see the
 * migration's own comment. ConversationWorkCeilingService is the sole
 * write path and is what keeps exactly one live row per scope.
 */
class ConversationWorkCeiling extends Model
{
    use HasFactory, EloquentMultiChainBridge, SoftDeletes;

    protected $table = 'conversation_work_ceilings';

    protected $fillable = [
        'scope_type',
        'scope_id',
        'max_work_units',
        'window_seconds',
        'waived',
    ];

    protected $casts = [
        'max_work_units' => 'integer',
        'window_seconds' => 'integer',
        'waived' => 'boolean',
    ];
}
