<?php

namespace ClarionApp\LlmClient\Models;

use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The relationship, distinct from the agent itself and from any independent
 * copy, between one owned Agent, one recipient user, and a permission level
 * (data-model.md §1, 096-agent-sharing). deleted_at doubles as the
 * revocation timestamp (research.md D7) — non-null means currently revoked,
 * null means currently active; there is no separate revoked_at column.
 *
 * AgentShareService is the sole write path for this table.
 */
class AgentShareGrant extends Model
{
    use HasFactory, EloquentMultiChainBridge, SoftDeletes;

    protected $table = 'agent_share_grants';

    protected $fillable = [
        'agent_id',
        'owner_user_id',
        'recipient_user_id',
        'permission',
    ];

    /** The user this grant was made to — resolved for display names. */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(\ClarionApp\Backend\Models\User::class, 'recipient_user_id');
    }
}
