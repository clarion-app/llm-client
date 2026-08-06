<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ClarionApp\LlmClient\ValueObjects\ActionType;
use ClarionApp\LlmClient\ValueObjects\ActionOutcome;

class AgentRunAction extends Model
{
    protected $table = 'agent_run_actions';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'run_id',
        'step_id',
        'action_type',
        'target',
        'attempt_group_id',
        'parent_action_id',
        'outcome',
        'failure_reason',
        'paused_at',
        'started_at',
        'ended_at',
        'duration_ms',
        'content',
        'created_at',
    ];

    protected $casts = [
        'action_type' => ActionType::class,
        'outcome' => ActionOutcome::class,
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'paused_at' => 'datetime',
        'duration_ms' => 'integer',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (!$model->id) {
                $model->id = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    /**
     * @return BelongsTo<AgentRunStep, $this>
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(AgentRunStep::class, 'step_id');
    }

    /**
     * @return BelongsTo<AgentRunAction, $this>|null
     */
    public function parentAction(): ?BelongsTo
    {
        if (!$this->parent_action_id) {
            return null;
        }

        return $this->belongsTo(AgentRunAction::class, 'parent_action_id');
    }

    /**
     * @return BelongsTo<AgentRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'run_id');
    }
}
