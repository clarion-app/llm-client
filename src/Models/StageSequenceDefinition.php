<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StageSequenceDefinition extends Model
{
    protected $table = 'stage_sequence_definitions';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = true;

    protected $fillable = [
        'owner_user_id',
        'coordinator_agent_id',
        'name',
        'description',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (!$model->id) {
                $model->id = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    public function stages(): HasMany
    {
        return $this->hasMany(Stage::class, 'sequence_definition_id')->orderBy('position');
    }
}
