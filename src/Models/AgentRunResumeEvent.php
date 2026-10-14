<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only record of the resume/retry events of a resumable run
 * (interruption detected, takeover, resume started, provider retry
 * scheduled/exhausted, stopped unresumable, …). Written by the lease,
 * the detection sweep and the resumer; never updated or deleted by
 * ordinary code — only by the run-trace purge, alongside its run.
 */
class AgentRunResumeEvent extends Model
{
    use HasUuids;

    protected $table = 'agent_run_resume_events';

    public $timestamps = false;

    protected $fillable = ['run_id', 'kind', 'step_position', 'attempt', 'payload', 'created_at'];

    protected $casts = ['payload' => 'array'];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if ($model->created_at === null) {
                $model->created_at = now();
            }
        });
    }
}
