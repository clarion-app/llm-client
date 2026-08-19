<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 122-workspace-browser-ui, US3 (data-model.md §1, research.md D1/D6). A
 * durable, append-only entry describing one actual file mutation an agent
 * made in one workspace -- shaped exactly like CodingWorkspaceRefusal: UUID
 * primary key generated in a creating listener, $timestamps = false with a
 * single created_at column carrying its own database-level useCurrent()
 * default, no EloquentMultiChainBridge trait (Constitution §III --
 * ephemeral, frequently-written audit data, not persistent user-owned
 * configuration).
 *
 * No foreign key to coding_projects, agents, or conversations -- every
 * relationship is a plain, unconstrained UUID column, so a row here is
 * structurally incapable of being cascade-deleted or blocked by any other
 * table's own lifecycle (FR-010/FR-011). Written exclusively via
 * WorkspaceChangeRecorder::record().
 */
class CodingWorkspaceChange extends Model
{
    protected $table = 'coding_workspace_changes';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'coding_project_id',
        'user_id',
        'root_path',
        'path',
        'operation',
        'old_content',
        'old_content_truncated',
        'old_binary',
        'old_size',
        'new_content',
        'new_content_truncated',
        'new_binary',
        'new_size',
        'agent_id',
        'agent_name',
        'conversation_id',
    ];

    protected $casts = [
        'old_content_truncated' => 'boolean',
        'old_binary' => 'boolean',
        'old_size' => 'integer',
        'new_content_truncated' => 'boolean',
        'new_binary' => 'boolean',
        'new_size' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (!$model->id) {
                $model->id = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }
}
