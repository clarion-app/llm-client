<?php

namespace ClarionApp\LlmClient\Models;

use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An operator-authored per-user request-rate limit. One entity covers both
 * scope kinds — the default that applies to a user with no override, and a
 * single user's override (a waiver being an override with waived = true) —
 * because a raise, a lower, and a waiver carry exactly the same columns as
 * the default they replace.
 *
 * Uses EloquentMultiChainBridge like SpendingCeiling/ModelPrice/Server/
 * RoleAssignment: operator-authored, low-volume, durable configuration
 * rather than derived high-volume data. The request count itself is not
 * modeled here at all — it lives in a Cache key, read and written solely
 * by RateLimitCounter.
 *
 * There is no unique constraint on (scope_type, scope_id) — see the
 * migration's own comment. RateLimitService is the sole write path and is
 * what keeps exactly one live row per scope.
 */
class RateLimit extends Model
{
    use HasFactory, EloquentMultiChainBridge, SoftDeletes;

    protected $table = 'rate_limits';

    /**
     * The all-zeros sentinel carried by user_default rows. Reuses
     * SpendingCeiling's (itself reused from RoleAssignment) rather than
     * redeclaring a second literal, so the two can never drift apart.
     */
    public const INSTALLATION_SCOPE_ID = SpendingCeiling::INSTALLATION_SCOPE_ID;

    protected $fillable = [
        'scope_type',
        'scope_id',
        'max_requests',
        'window_seconds',
        'waived',
    ];

    protected $casts = [
        'max_requests' => 'integer',
        'window_seconds' => 'integer',
        'waived' => 'boolean',
    ];
}
