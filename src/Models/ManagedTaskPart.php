<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Model;

class ManagedTaskPart extends Model
{
    protected $table = 'managed_task_parts';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = true;

    protected $fillable = [
        'managed_task_id',
        'sequence',
        'description',
        'state',
        'current_delegation_id',
        'accepted_delegation_id',
        'accepted_summary',
        'shortfall_reason',
        'assignment_count',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'state' => 'string',
        'assignment_count' => 'integer',
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
