<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 121-workspace-boundary-hardening, US2 (data-model.md §3, research.md D2).
 * An append-only, ephemeral record of a boundary refusal -- shaped exactly
 * like AgentRunAction: UUID primary key generated in a creating listener,
 * $timestamps = false with a single created_at column carrying its own
 * database-level default (the migration's useCurrent()), no
 * EloquentMultiChainBridge trait (Constitution §III -- ephemeral,
 * frequently-changing audit data, not persistent user-owned
 * configuration).
 *
 * Never related to AgentRun/AgentRunStep/AgentRunAction in any way -- no
 * foreign key, no shared identity, no dependency on an open run existing at
 * all. Written exclusively via WorkspaceRefusalRecorder::record().
 */
class CodingWorkspaceRefusal extends Model
{
    protected $table = 'coding_workspace_refusals';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'coding_project_id',
        'operation',
        'reason',
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
