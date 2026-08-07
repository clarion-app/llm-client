<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Day-bucketed rollup of cost, upserted at write time via the same
 * insertOrIgnore + atomic-increment idiom UsageSummary/usage_summaries uses
 * (data-model.md §3, research.md D7). Derived, frequently-written data — no
 * EloquentMultiChainBridge, matching the UsageSummary/ContextManagementSummary
 * precedent (Constitution §III).
 */
class CostSummary extends Model
{
    protected $table = 'cost_summaries';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false; // only updated_at, useCurrent() — matches UsageSummary

    public const ENTITY_CONVERSATION = 'conversation';
    public const ENTITY_USER = 'user';
    public const ENTITY_AGENT = 'agent';

    /**
     * Reserved entity_id sentinel for entity_type='agent' rows whenever the
     * contributing usage_records.agent_id is null (FR-022, research.md D8).
     * Deliberately distinct from RoleAssignment::INSTALLATION_SCOPE_ID's
     * all-zeros sentinel so the two are never visually/programmatically
     * confused. Never exposed over the API — the HTTP layer maps this to/from
     * the literal string "unattributed".
     */
    public const UNATTRIBUTED_AGENT_BUCKET = '00000000-0000-0000-0000-000000000001';

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (!$model->id) {
                $model->id = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }
}
