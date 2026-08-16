<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * 108-shared-task-workspace (data-model.md §1). A single, immutable
 * finding/decision/open-question recorded into a managed task's shared
 * working area. No relationships declared -- managed_task_id/
 * author_agent_id are id columns resolved via query classes, not
 * Eloquent belongsTo (mirrors Delegation's own no-FK-object posture).
 */
class TaskWorkspaceEntry extends Model
{
    protected $table = 'task_workspace_entries';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'managed_task_id',
        'owner_user_id',
        'author_agent_id',
        'content',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (!$model->id) {
                $model->id = (string) Str::uuid();
            }

            if (!$model->created_at) {
                $model->created_at = now();
            }
        });
    }
}
