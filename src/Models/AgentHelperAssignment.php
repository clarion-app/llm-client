<?php

namespace ClarionApp\LlmClient\Models;

use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The relationship, distinct from either agent itself, between one parent
 * Agent and one helper Agent, both owned by the same user (data-model.md
 * §1, 097-subagent-model). Stores membership only — no permitted-operations
 * data of any kind, since that is always computed live from each agent's
 * own current definition (research.md D3). deleted_at doubles as the
 * removal timestamp (research.md D4, mirrors AgentShareGrant's own
 * deleted_at) — non-null means currently removed, null means currently
 * active; there is no separate removed_at column.
 *
 * AgentHelperService is the sole write path for this table.
 */
class AgentHelperAssignment extends Model
{
    use HasFactory, EloquentMultiChainBridge, SoftDeletes;

    protected $table = 'agent_helper_assignments';

    protected $fillable = [
        'parent_agent_id',
        'helper_agent_id',
        'owner_user_id',
    ];

    /**
     * The parent agent this assignment belongs to. Plain belongsTo, no
     * withTrashed() baked in — display code needing a since-removed
     * parent to still resolve uses AgentQuery's trash-inclusive lookup,
     * never this relation directly.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'parent_agent_id');
    }

    /**
     * The helper agent this assignment names. Plain belongsTo, no
     * withTrashed() baked in — same posture as parent() above.
     */
    public function helper(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'helper_agent_id');
    }
}
