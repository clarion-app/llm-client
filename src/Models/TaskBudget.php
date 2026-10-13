<?php

namespace ClarionApp\LlmClient\Models;

use ClarionApp\LlmClient\Casts\PlainDecimalCast;
use ClarionApp\LlmClient\ValueObjects\CeilingOrigin;
use ClarionApp\LlmClient\ValueObjects\TaskBudgetState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One task's budget: its ceilings, the steps it has claimed and its
 * lifecycle state. Spend is not stored here — it is always summed from the
 * usage records stamped with the task's id. steps_used is written only by
 * TaskBudgetGate's conditional claim (and the one-off seed when a task is
 * opened late for a run already under way); every state change is a
 * conditional UPDATE on the current state, so racing writers have one
 * winner. Operational state, so not bridged.
 */
class TaskBudget extends Model
{
    public const SCOPE_REQUEST = 'request';
    public const SCOPE_MANAGED_TASK = 'managed_task';
    public const SCOPE_SEQUENCE_RUN = 'sequence_run';
    public const SCOPE_CONSENSUS = 'consensus';

    protected $table = 'task_budgets';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'user_id', 'root_conversation_id', 'root_run_id', 'managed_task_id', 'scope', 'unattended', 'state', 'end_reason',
        'max_steps', 'max_steps_origin', 'original_max_steps', 'max_spend', 'max_spend_origin', 'original_max_spend',
        'steps_used', 'approach_threshold', 'steps_approach_noticed_at', 'spend_approach_noticed_at', 'paused_at',
        'pause_reason', 'paused_run_id', 'paused_iteration', 'resume_mode', 'paused_steps_used', 'paused_spend',
        'prompt_question_id', 'ended_at',
    ];

    protected $casts = [
        'state' => TaskBudgetState::class,
        'max_steps_origin' => CeilingOrigin::class,
        'max_spend_origin' => CeilingOrigin::class,
        'unattended' => 'boolean',
        'max_spend' => PlainDecimalCast::class.':10',
        'original_max_spend' => PlainDecimalCast::class.':10',
        'paused_spend' => PlainDecimalCast::class.':10',
        'approach_threshold' => PlainDecimalCast::class.':4',
        'steps_approach_noticed_at' => 'datetime',
        'spend_approach_noticed_at' => 'datetime',
        'paused_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (!$model->id) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function events()
    {
        return $this->hasMany(TaskBudgetEvent::class, 'task_budget_id');
    }

    public function rootConversation()
    {
        return $this->belongsTo(Conversation::class, 'root_conversation_id');
    }
}
