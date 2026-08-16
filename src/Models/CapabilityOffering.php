<?php

namespace ClarionApp\LlmClient\Models;

use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The configured relationship, distinct from either agent itself, stating
 * that a specific agent (offered_agent_id) stands behind a specific
 * capability entry, and which specific caller agent (caller_agent_id) may
 * see and invoke it (109-agent-as-capability, data-model.md §1). Distinct
 * from AgentHelperAssignment (097) — an agent can be offered as a
 * capability without being configured as a helper. deleted_at doubles as
 * the withdrawal timestamp (mirrors AgentHelperAssignment's own deleted_at)
 * — non-null means withdrawn, null means currently active; there is no
 * separate withdrawn_at column.
 *
 * CapabilityOfferingService is the sole write path for this table.
 */
class CapabilityOffering extends Model
{
    use HasFactory, EloquentMultiChainBridge, SoftDeletes;

    protected $table = 'agent_capability_offerings';

    protected $fillable = [
        'offered_agent_id',
        'caller_agent_id',
        'owner_user_id',
        'capability_name',
        'capability_description',
        'input_description',
    ];

    /**
     * The agent that actually runs when this capability is invoked. Plain
     * belongsTo, no withTrashed() baked in — display code needing a
     * since-removed offered agent to still resolve uses AgentQuery's
     * trash-inclusive lookup, never this relation directly.
     */
    public function offeredAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'offered_agent_id');
    }

    /**
     * The agent this offering is visible/invocable to. Plain belongsTo, no
     * withTrashed() baked in — same posture as offeredAgent() above.
     */
    public function callerAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'caller_agent_id');
    }
}
