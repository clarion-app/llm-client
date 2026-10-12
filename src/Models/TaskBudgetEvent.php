<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Append-only record of what happened to a task budget (opened, ceilings
 * changed, ceiling reached, decisions, …). Written best-effort by
 * TaskBudgetService; a failed write never blocks what it records.
 */
class TaskBudgetEvent extends Model
{
    protected $table = 'task_budget_events';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['task_budget_id', 'kind', 'payload', 'actor_user_id', 'created_at'];

    protected $casts = ['payload' => 'array'];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (!$model->id) {
                $model->id = (string) Str::uuid();
            }
        });
    }
}
