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
    ];

    protected $casts = [
        'kind' => RunKind::class,
        'end_state' => RunEndState::class,
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'duration_ms' => 'integer',
        'step_count' => 'integer',
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
     * @return HasMany<AgentRunStep>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(AgentRunStep::class, 'run_id')->orderBy('position');
    }
}
