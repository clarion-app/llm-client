<?php

namespace ClarionApp\LlmClient\Models;

use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;
use ClarionApp\LlmClient\ValueObjects\ExpectationKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The immutable content half of a case (research.md D3): what the agent is
 * given, what a correct response should look like or do, and the set of
 * expectations it is judged against. Append-only in practice — no
 * controller, service, or route in this feature ever issues an
 * UPDATE/DELETE against this table. EvalCaseService::editCase() only ever
 * INSERTs a new row here; nothing ever calls delete() on one.
 *
 * The explicit `SoftDeletes` listing (and the migration's `deleted_at`
 * column) is redundant with what EloquentMultiChainBridge already provides
 * internally, but it is not optional: the bridge trait declares
 * `use SoftDeletes;` inside its own definition, so any model using it
 * registers Eloquent's soft-delete global scope regardless of whether the
 * model re-lists the trait. Omitting the column from the migration would
 * make every query against this table fail — the messages.deleted_at
 * column exists for the identical reason. deleted_at stays NULL for every
 * row this feature ever writes.
 */
class EvalCaseVersion extends Model
{
    use HasFactory, EloquentMultiChainBridge, SoftDeletes;

    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = null;

    protected $table = 'eval_case_versions';

    protected $fillable = ['case_id', 'version_number', 'given', 'expected_behavior', 'expectations'];

    protected $casts = [
        'expectations' => 'array',
    ];

    /**
     * True iff any expectation on this version has kind = human_judgment.
     * Computed, never a stored column (research.md D5) — a case can hold
     * a human_judgment expectation alongside any number of checkable ones,
     * and a stored flag would be a second place the same fact could be
     * asserted, able to drift the moment editCase() changed one without
     * the other.
     */
    public function requiresHumanJudgment(): bool
    {
        return collect($this->expectations)
            ->contains(fn ($expectation) => ($expectation['kind'] ?? null) === ExpectationKind::HumanJudgment->value);
    }
}
