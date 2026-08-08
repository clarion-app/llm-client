<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Day-bucketed rollup of tool reliability (invocation/success/failure
 * counts, plus a per-category failure breakdown), upserted at write time via
 * the same insertOrIgnore + atomic-increment idiom CostSummary/UsageSummary
 * already use (data-model.md §3). Derived, frequently-written data — no
 * EloquentMultiChainBridge, matching the CostSummary/UsageSummary/
 * ContextManagementSummary precedent (Constitution §III).
 */
class ToolReliabilitySummary extends Model
{
    protected $table = 'tool_reliability_summaries';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false; // only updated_at, useCurrent() — matches CostSummary

    protected $fillable = [
        'tool_name',
        'agent_id',
        'user_id',
        'period_date',
        'invocation_count',
        'success_count',
        'failure_count',
        'failure_timeout_count',
        'failure_connection_failure_count',
        'failure_authentication_failure_count',
        'failure_invalid_input_count',
        'failure_server_error_count',
        'failure_other_count',
        'failure_uncategorized_count',
    ];

    /**
     * Reserved agent_id sentinel for rows whenever the contributing
     * tool_invocation_records.agent_id is null (data-model.md §3). Never
     * NULL itself in this table — a NULL column participates in a unique
     * index as distinct-from-itself under MySQL/MariaDB, breaking idempotent
     * upserts. Deliberately distinct from CostSummary::UNATTRIBUTED_AGENT_BUCKET
     * ('...0001') and RoleAssignment::INSTALLATION_SCOPE_ID ('...0000') so no
     * two sentinels from unrelated domains are ever visually/programmatically
     * confused. Never exposed over the API — the HTTP layer maps this to/from
     * the literal string "unattributed".
     */
    public const UNATTRIBUTED_AGENT_BUCKET = '00000000-0000-0000-0000-000000000002';

    /**
     * Fixed by spec Clarifications — not configuration. Below this many
     * summed invocations for a scope/period, the rate is flagged as too thin
     * to be meaningful (low_sample) rather than presented at face value.
     */
    public const LOW_SAMPLE_THRESHOLD = 10;

    /**
     * ToolFailureCategory::value => matching summary column name.
     */
    public const FAILURE_CATEGORY_COLUMNS = [
        'timeout' => 'failure_timeout_count',
        'connection_failure' => 'failure_connection_failure_count',
        'authentication_failure' => 'failure_authentication_failure_count',
        'invalid_input' => 'failure_invalid_input_count',
        'server_error' => 'failure_server_error_count',
        'other' => 'failure_other_count',
    ];

    /**
     * Column for a failure recorded with no failure_category at all —
     * kept distinct from the "other" category above, which is itself a real
     * classification.
     */
    public const UNCATEGORIZED_COLUMN = 'failure_uncategorized_count';

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (!$model->id) {
                $model->id = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }
}
