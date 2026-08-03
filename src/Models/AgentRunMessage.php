<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Model;
use ClarionApp\LlmClient\ValueObjects\RunRelation;

class AgentRunMessage extends Model
{
    protected $table = 'agent_run_messages';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'run_id',
        'message_id',
        'relation',
        'created_at',
    ];

    protected $casts = [
        'relation' => RunRelation::class,
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
}
