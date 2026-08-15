<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SequenceRun extends Model
{
    protected $table = 'sequence_runs';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'sequence_definition_id',
        'owner_user_id',
        'conversation_id',
        'status',
        'starting_input',
        'current_stage_position',
        'last_progress_at',
        'failure_reason',
        'resumed_at',
        'resume_count',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'status' => 'string',
        'current_stage_position' => 'integer',
        'last_progress_at' => 'datetime',
        'resumed_at' => 'datetime',
        'resume_count' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (!$model->id) {
                $model->id = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    public function sequenceDefinition(): BelongsTo
    {
        return $this->belongsTo(StageSequenceDefinition::class, 'sequence_definition_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function stageResults(): HasMany
    {
        return $this->hasMany(StageResult::class, 'sequence_run_id');
    }
}
