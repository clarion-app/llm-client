<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use ClarionApp\LlmClient\ValueObjects\RunEndState;

class AgentRun extends Model
{
    protected $table = 'agent_runs';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'kind',
        'user_id',
        'conversation_id',
        'source',
        'end_state',
        'end_reason',
        'started_at',
        'ended_at',
        'duration_ms',
        'step_count',
        'created_at',
        'is_streamed',
        'first_output_ms',
        'model',
        'agent_id',
        'model_wait_ms',
        'tool_exec_ms',
        'confirm_wait_ms',
        'product_ms',
        'checkpoint_version',
        'resume_mode',
        'resume_driver',
        'resume_options',
        'resume_state',
        'dispatch_id',
        'lease_holder',
        'lease_expires_at',
        'last_progress_at',
        'interrupted_at',
        'consecutive_interruptions',
        'resume_count',
        'provider_attempts',
        'provider_first_failure_at',
        'next_attempt_at',
        'cancelled_at',
    ];

    protected $casts = [
        'kind' => RunKind::class,
        'end_state' => RunEndState::class,
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'duration_ms' => 'integer',
        'step_count' => 'integer',
        'created_at' => 'datetime',
        'is_streamed' => 'boolean',
        'first_output_ms' => 'integer',
        'model_wait_ms' => 'integer',
        'tool_exec_ms' => 'integer',
        'confirm_wait_ms' => 'integer',
        'product_ms' => 'integer',
        'resume_options' => 'array',
        'lease_expires_at' => 'datetime',
        'last_progress_at' => 'datetime',
        'interrupted_at' => 'datetime',
        'provider_first_failure_at' => 'datetime',
        'next_attempt_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'consecutive_interruptions' => 'integer',
        'resume_count' => 'integer',
        'provider_attempts' => 'integer',
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
     * @return HasMany<AgentRunStep>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(AgentRunStep::class, 'run_id')->orderBy('position');
    }
}
