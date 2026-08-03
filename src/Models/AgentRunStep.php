<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ClarionApp\LlmClient\ValueObjects\RunEndState;

class AgentRunStep extends Model
{
    protected $table = 'agent_run_steps';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'run_id',
        'position',
        'attempt_group_id',
        'end_state',
        'end_reason',
        'started_at',
        'ended_at',
        'duration_ms',
        'wait_ms',
        'attempt_count',
    ];

    protected $casts = [
        'end_state' => RunEndState::class,
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'duration_ms' => 'integer',
        'wait_ms' => 'integer',
        'attempt_count' => 'integer',
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
     * @return BelongsTo<AgentRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'run_id');
    }
}
