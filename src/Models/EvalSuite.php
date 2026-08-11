<?php

namespace ClarionApp\LlmClient\Models;

use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An operator-authored, named, installation-shared collection of test
 * cases for one agent (FR-001). Durable configuration — the
 * ModelPrice/SpendingCeiling/RoleAssignment shape (Constitution §III).
 *
 * EvalSuiteService is the sole write path; this model has no behavior of
 * its own beyond the relation below.
 */
class EvalSuite extends Model
{
    use HasFactory, EloquentMultiChainBridge, SoftDeletes;

    protected $table = 'eval_suites';

    protected $fillable = ['name', 'agent_identifier'];

    /**
     * Every case this suite owns, oldest first — the AgentRun::steps()
     * precedent for a parent→children relation. Shared by index()'s
     * case_count, show()'s live case list, and
     * EvalSuiteExporter::export()'s live-case iteration, so all three call
     * sites share one relation rather than each hand-rolling
     * EvalCase::where('suite_id', ...).
     */
    public function cases(): HasMany
    {
        return $this->hasMany(EvalCase::class, 'suite_id')->orderBy('created_at');
    }
}
