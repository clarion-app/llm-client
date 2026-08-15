<?php

namespace ClarionApp\LlmClient\Models;

use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The stable, editable-in-place identity for a stored agent (data-model.md
 * §1) — the identity a listing is keyed on, unaffected by how many times
 * its definition has changed. The content itself lives on AgentVersion,
 * pointed at by current_version_id.
 *
 * AgentService is the sole write path for this table and for
 * agent_versions.
 */
class Agent extends Model
{
    use HasFactory, EloquentMultiChainBridge, SoftDeletes;

    protected $table = 'agents';

    protected $casts = [
        'is_active' => 'boolean',
        'is_default_handler' => 'boolean',
    ];

    protected $fillable = [
        'user_id',
        'name',
        'current_version_id',
        'linked_repository_path',
        'linked_file_path',
        'linked_synced_file_hash',
        'cloned_from_agent_id',
    ];

    /** The version currently in effect for this agent. */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(AgentVersion::class, 'current_version_id');
    }

    /**
     * Every version this agent has ever had, oldest first — the shape
     * `GET /agents/{id}/versions` returns.
     */
    public function versions(): HasMany
    {
        return $this->hasMany(AgentVersion::class, 'agent_id')->orderBy('version_number');
    }

    /**
     * The agent this one was cloned from, if any (091, data-model.md §1).
     *
     * A plain belongsTo with no withTrashed() baked in — a since-removed
     * origin will not resolve through this relation. Display code that
     * needs a since-removed origin to still resolve uses
     * AgentQuery::findAgentIncludingTrashed() instead, never this relation
     * directly.
     */
    public function clonedFrom(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'cloned_from_agent_id');
    }

    /**
     * AgentHelperAssignment rows where this agent is the parent
     * (097-subagent-model, data-model.md §2). Purely for eager-load
     * convenience — never required for correctness, since AgentHelperQuery
     * is the actual read path.
     */
    public function helperAssignments(): HasMany
    {
        return $this->hasMany(AgentHelperAssignment::class, 'parent_agent_id');
    }

    /**
     * AgentHelperAssignment rows where this agent is the helper
     * (097-subagent-model, data-model.md §2) — i.e. every parent this
     * agent currently helps. Purely for eager-load convenience — never
     * required for correctness, since AgentHelperQuery is the actual read
     * path.
     */
    public function parentAssignments(): HasMany
    {
        return $this->hasMany(AgentHelperAssignment::class, 'helper_agent_id');
    }
}
