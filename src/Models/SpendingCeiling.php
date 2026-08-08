<?php

namespace ClarionApp\LlmClient\Models;

use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;
use ClarionApp\LlmClient\Casts\PlainDecimalCast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An operator-authored spending ceiling. One entity covers all three scope
 * kinds — the installation-wide ceiling, the default that applies to a user
 * with no override, and a single user's override (a waiver being an
 * override with waived = true) — because a raise, a lower, and a waiver
 * carry exactly the same columns as the default they replace.
 *
 * Uses EloquentMultiChainBridge like ModelPrice/Server/RoleAssignment:
 * operator-authored, low-volume, durable configuration rather than derived
 * high-volume data.
 *
 * There is no unique constraint on (scope_type, scope_id) — see the
 * migration's own comment. SpendingCeilingService is the sole write path
 * and is what keeps exactly one live row per scope.
 */
class SpendingCeiling extends Model
{
    use HasFactory, EloquentMultiChainBridge, SoftDeletes;

    protected $table = 'spending_ceilings';

    /**
     * The all-zeros sentinel carried by installation and user_default rows.
     * Reuses RoleAssignment's rather than redeclaring a second literal, so
     * the two can never drift apart.
     */
    public const INSTALLATION_SCOPE_ID = RoleAssignment::INSTALLATION_SCOPE_ID;

    protected $fillable = [
        'scope_type',
        'scope_id',
        'amount',
        'period_type',
        'enforcement_mode',
        'approach_threshold',
        'waived',
    ];

    protected $casts = [
        // PlainDecimalCast, not a native numeric cast: both of these feed
        // bccomp()/bcmul() directly, and bcmath has no tolerance for the
        // scientific-notation string SQLite's NUMERIC storage affinity can
        // produce for a small decimal. This is the same reason ModelPrice
        // casts its three rates this way.
        'amount' => PlainDecimalCast::class.':10',
        'approach_threshold' => PlainDecimalCast::class.':4',
        'waived' => 'boolean',
    ];
}
