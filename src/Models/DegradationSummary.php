<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Aggregate counts of degraded responses, per user and per installation
 * (data-model.md §4). ContextManagementSummary's exact shape and write
 * idiom (insertOrIgnore + column = column + n, atomic, no
 * read-modify-write) — not bridged.
 */
class DegradationSummary extends Model
{
    protected $table = 'degradation_summaries';

    protected $keyType = 'string';
    public $incrementing = false;

    // Only updated_at is tracked (no created_at column in migration),
    // matching ContextManagementSummary.
    public $timestamps = false;

    public const ENTITY_USER = 'user';
    public const ENTITY_INSTALLATION = 'installation';

    protected $fillable = [
        'entity_type',
        'entity_id',
        'degraded_response_count',
        'last_degraded_at',
    ];

    protected $casts = [
        'last_degraded_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (!$model->id) {
                $model->id = (string) Str::uuid();
            }
        });
    }
}
