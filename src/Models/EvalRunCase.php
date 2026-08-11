<?php

namespace ClarionApp\LlmClient\Models;

use ClarionApp\LlmClient\ValueObjects\EvalRunCaseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The pinned list of what a run is executing, captured once at start
 * (research.md D7). An internal orchestration row, not directly
 * operator-facing — an operator reads EvalCaseResult instead
 * (data-model.md §2). No EloquentMultiChainBridge, no SoftDeletes
 * (research.md D14).
 *
 * EvalRunService is the sole write path for eval_runs/eval_run_cases.
 */
class EvalRunCase extends Model
{
    protected $table = 'eval_run_cases';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'run_id',
        'eval_case_id',
        'eval_case_version_id',
        'position',
        'status',
        'dispatch_attempts',
    ];

    protected $casts = [
        'status' => EvalRunCaseStatus::class,
        'position' => 'integer',
        'dispatch_attempts' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (!$model->id) {
                $model->id = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(EvalRun::class, 'run_id');
    }
}
