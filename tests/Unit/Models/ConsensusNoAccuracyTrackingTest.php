<?php

namespace ClarionApp\LlmClient\Tests\Unit\Models;

use ClarionApp\LlmClient\Models\ConsensusRequest;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 104-multi-agent-consensus, Phase 8 (Polish), tasks.md T056.
 *
 * FR-017: "System MUST NOT record or expose any comparison of individual
 * contributors' general accuracy over time." A one-time manual audit of
 * this feature's schema/model is not a durable guarantee -- this is a
 * PERMANENT regression test asserting the consensus_requests schema and the
 * ConsensusRequest model's own $fillable/$casts contain no per-agent/
 * per-contributor win-rate, accuracy-score, or leaderboard-style column, so
 * a future change that reintroduces one (even accidentally, e.g. while
 * adding an unrelated analytics feature) is caught automatically rather
 * than relying on a repeat of this one-time reconciliation.
 *
 * Deliberately keyword-based rather than an exhaustive allowlist of the
 * columns that DO exist: a keyword match is more likely to catch a
 * not-yet-imagined future column name than a list that has to be
 * maintained by hand every time a legitimate new column is added.
 */
class ConsensusNoAccuracyTrackingTest extends TestCase
{
    /**
     * Any column name containing one of these substrings would name exactly
     * the kind of per-contributor "who is generally better" signal FR-017
     * forbids -- as opposed to this request's own one-time
     * agreement_classification/disagreement_detail, which are scoped to a
     * single question, not a contributor's track record across questions.
     */
    private const FORBIDDEN_KEYWORDS = [
        'accuracy',
        'win_rate',
        'winrate',
        'leaderboard',
        'score',
        'rank',
        'reputation',
        'track_record',
        'performance_history',
        'success_rate',
    ];

    #[Test]
    public function the_consensus_requests_schema_has_no_per_contributor_accuracy_column(): void
    {
        $columns = Schema::getColumnListing('consensus_requests');

        $this->assertNotEmpty($columns, 'fixture sanity: the consensus_requests schema must be defined for this test to mean anything');

        foreach ($columns as $column) {
            foreach (self::FORBIDDEN_KEYWORDS as $keyword) {
                $this->assertStringNotContainsString(
                    $keyword,
                    strtolower($column),
                    "consensus_requests.{$column} looks like a per-contributor accuracy/leaderboard column, which FR-017 forbids -- multi-opinion outcomes must stay scoped to the single question being answered, never a cross-question comparison of contributors",
                );
            }
        }
    }

    #[Test]
    public function the_consensus_request_models_fillable_has_no_per_contributor_accuracy_field(): void
    {
        $model = new ConsensusRequest();
        $fillable = $model->getFillable();

        $this->assertNotEmpty($fillable, 'fixture sanity: ConsensusRequest must declare $fillable for this test to mean anything');

        foreach ($fillable as $field) {
            foreach (self::FORBIDDEN_KEYWORDS as $keyword) {
                $this->assertStringNotContainsString(
                    $keyword,
                    strtolower($field),
                    "ConsensusRequest::\$fillable contains \"{$field}\", which looks like a per-contributor accuracy/leaderboard field -- FR-017 forbids recording or exposing any comparison of individual contributors' general accuracy over time",
                );
            }
        }
    }

    #[Test]
    public function the_consensus_request_models_casts_has_no_per_contributor_accuracy_field(): void
    {
        $model = new ConsensusRequest();
        $casts = array_keys($model->getCasts());

        $this->assertNotEmpty($casts, 'fixture sanity: ConsensusRequest must declare $casts for this test to mean anything');

        foreach ($casts as $field) {
            foreach (self::FORBIDDEN_KEYWORDS as $keyword) {
                $this->assertStringNotContainsString(
                    $keyword,
                    strtolower($field),
                    "ConsensusRequest::\$casts contains \"{$field}\", which looks like a per-contributor accuracy/leaderboard field -- FR-017 forbids recording or exposing any comparison of individual contributors' general accuracy over time",
                );
            }
        }
    }

    /**
     * agent_delegations (098/099/101, reused unmodified by this feature per
     * research.md D1/D2 -- no new column added to it) is the OTHER table a
     * consensus request's data lives in (Contributor Response, data-model.md
     * §2). This feature adds no column there at all, but the guard is
     * cheap and closes the same risk on the table this feature's own
     * contributorsForRequest()/finalize() actually read from.
     */
    #[Test]
    public function agent_delegations_has_no_per_contributor_accuracy_column_added_by_this_feature(): void
    {
        $columns = Schema::getColumnListing('agent_delegations');

        $this->assertNotEmpty($columns, 'fixture sanity: the agent_delegations schema must be defined for this test to mean anything');

        foreach ($columns as $column) {
            foreach (self::FORBIDDEN_KEYWORDS as $keyword) {
                $this->assertStringNotContainsString(
                    $keyword,
                    strtolower($column),
                    "agent_delegations.{$column} looks like a per-contributor accuracy/leaderboard column -- FR-017 forbids this feature (or any future one) from persisting a cross-question comparison of contributors on the table consensus reads its Contributor Responses from",
                );
            }
        }
    }
}
