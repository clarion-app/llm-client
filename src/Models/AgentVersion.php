<?php

namespace ClarionApp\LlmClient\Models;

use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One immutable, timestamped, attributed snapshot of an agent's definition
 * as it existed at a specific point (data-model.md §2). Append-only in
 * practice — no controller, service, or route in this feature ever issues
 * an UPDATE/DELETE against this table. AgentService only ever INSERTs a new
 * row here; nothing ever calls delete() on one.
 *
 * The explicit `SoftDeletes` listing (and the migration's `deleted_at`
 * column) is redundant with what EloquentMultiChainBridge already provides
 * internally, but it is not optional: the bridge trait declares
 * `use SoftDeletes;` inside its own definition, so any model using it
 * registers Eloquent's soft-delete global scope regardless of whether the
 * model re-lists the trait. Omitting the column from the migration would
 * make every query against this table fail — the eval_case_versions
 * precedent this mirrors exactly. deleted_at stays NULL for every row this
 * feature ever writes.
 */
class AgentVersion extends Model
{
    use HasFactory, EloquentMultiChainBridge, SoftDeletes;

    protected $table = 'agent_versions';

    protected $fillable = [
        'agent_id',
        'version_number',
        'raw_definition',
        'content_hash',
        'source',
        'changed_by_user_id',
        'restored_from_version_id',
        'git_commit_hash',
        'git_author_name',
        'git_committed_at',
    ];

    protected $casts = [
        'git_committed_at' => 'datetime',
    ];

    /** The agent identity this version belongs to. */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    /**
     * The version this one was restored from, when source = restoration.
     * Self-referential, nullable.
     */
    public function restoredFrom(): BelongsTo
    {
        return $this->belongsTo(AgentVersion::class, 'restored_from_version_id');
    }
}
