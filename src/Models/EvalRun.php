<?php

namespace ClarionApp\LlmClient\Models;

use ClarionApp\LlmClient\ValueObjects\EvalRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One operator-initiated execution of a suite (FR-001, FR-018). Derived,
 * high-volume, per-execution telemetry — the AgentRun shape (Constitution
 * §III / research.md D14), not the EvalSuite/EvalCase/EvalCaseVersion
 * shape: no EloquentMultiChainBridge, no SoftDeletes.
 *
 * $timestamps stays on (unlike AgentRun) — updated_at is load-bearing:
 * ResolveStalledEvalRunsCommand reads it to find stale in_progress runs
 * (data-model.md §1).
 *
 * EvalRunService is the sole write path for eval_runs/eval_run_cases.
 */
class EvalRun extends Model
{
    protected $table = 'eval_runs';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'suite_id',
        'agent_label',
        'server_id',
        'model',
        'status',
        'case_count',
        'failure_reason',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'status' => EvalRunStatus::class,
        'case_count' => 'integer',
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

    /** Display only — no FK, no cascade (data-model.md §1). */
    public function suite(): BelongsTo
    {
        return $this->belongsTo(EvalSuite::class, 'suite_id');
    }

    /**
     * The pinned case snapshot this run executes, in display/execution
     * order.
     *
     * @return HasMany<EvalRunCase>
     */
    public function cases(): HasMany
    {
        return $this->hasMany(EvalRunCase::class, 'run_id')->orderBy('position');
    }
}
