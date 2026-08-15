<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Stage extends Model
{
    protected $table = 'stages';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = true;

    protected $fillable = [
        'sequence_definition_id',
        'position',
        'name',
        'helper_agent_id',
        'input_schema',
        'output_schema',
        'is_idempotent',
    ];

    protected $casts = [
        'position' => 'integer',
        'input_schema' => 'array',
        'output_schema' => 'array',
        'is_idempotent' => 'boolean',
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

    public function stageResults(): HasMany
    {
        return $this->hasMany(StageResult::class, 'stage_id');
    }
}
