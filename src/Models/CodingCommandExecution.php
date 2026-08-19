<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 123-sandboxed-shell-execution, US1 (data-model.md §3). The durable,
 * append-only "Command execution request" key entity -- one row per
 * runCommand() invocation, written once the outcome is known, never
 * updated in place. Shaped exactly like CodingWorkspaceChange/
 * CodingWorkspaceRefusal: UUID primary key generated in a creating
 * listener, $timestamps = false with a single database-defaulted
 * created_at (useCurrent()), no EloquentMultiChainBridge trait
 * (Constitution §III -- ephemeral, frequently-written audit data, not
 * persistent user-owned configuration).
 *
 * No foreign key to coding_projects/users/conversations -- every
 * relationship is a plain, unconstrained UUID column, matching
 * CodingWorkspaceChange's own "structurally incapable of being
 * cascade-deleted or blocked by any other table's own lifecycle" shape.
 *
 * `status` is one of completed/stopped_timeout/sandbox_unavailable/refused
 * (data-model.md §3a) -- never a fifth, ambiguous state.
 */
class CodingCommandExecution extends Model
{
    protected $table = 'coding_command_executions';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'coding_project_id',
        'user_id',
        'command',
        'status',
        'exit_code',
        'timed_out',
        'stdout',
        'stderr',
        'output_truncated',
        'network_enabled',
        'duration_ms',
        'agent_id',
        'agent_name',
        'conversation_id',
    ];

    protected $casts = [
        'exit_code' => 'integer',
        'timed_out' => 'boolean',
        'output_truncated' => 'boolean',
        'network_enabled' => 'boolean',
        'duration_ms' => 'integer',
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
