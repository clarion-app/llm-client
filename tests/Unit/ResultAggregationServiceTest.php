<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\ContentSanitizer;
use ClarionApp\LlmClient\Services\ResultAggregationService;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 099-result-aggregation, Phase 5 (US3), tasks.md T030.
 *
 * Unit tests for the new `ResultAggregationService::combineForRun()`
 * (data-model.md §4, research.md D5/D6) -- a pure read/compute service over
 * already-persisted `Delegation` rows, deliberately independent of
 * `AgentLoopService`/any LLM scaffolding (research.md D5's own
 * "unit-testable... exactly like DelegationQuery" claim). `Delegation`
 * rows are created directly, mirroring
 * `DelegationQueryControllerTest::makeDelegationRow()`'s own established
 * pattern but simplified further: `agent_delegations` carries no DB-level
 * FK at all (confirmed against
 * `2026_08_14_000001_create_agent_delegations_table.php`'s own "No
 * DB-level FKs anywhere on this table" comment), so `parent_conversation_id`/
 * `helper_conversation_id` can be plain UUIDs with no real `Conversation`
 * row behind them -- only `helper_agent_id` needs a real `Agent` row, since
 * `helper_agent_name` resolution reads that table.
 *
 * Design note this file establishes for Phase 5 Implementation (T033):
 * contracts/result-aggregation-meta-tool.md §3's own literal system-prompt
 * example attributes each `combined_output` entry to the specific helper
 * name(s) that produced it, e.g. `- currency: "USD" (from "Invoice
 * Line-Item Extractor", "Currency Normalizer")`. Neither `contributors`
 * (delegation_id/helper_agent_id/helper_agent_name/status/summary/undone)
 * nor the flat `combined_output` map alone carries that per-key provenance
 * once each contributor's own raw output map has been discarded -- so
 * `combineForRun()`'s `contributors` entries below carry one additional
 * field, `output` (each contributor's own decoded `result_output`), beyond
 * the six data-model.md §4 names. This is purely additive at the
 * per-contributor level: it does not change `combined_output`'s own flat
 * shape (still exactly contracts/result-aggregation-api.md §2's shape) or
 * the read endpoint's top-level five-key response shape
 * (`DelegationQueryControllerTest`'s own T032 asserts that top-level shape
 * via `assertJsonStructure`, and checks each contributor entry via
 * `assertArrayHasKey` rather than an exact key-set match, precisely to
 * leave room for this field) -- and it is what
 * `AgentLoopServiceCombinedResultsSectionTest`'s own T031 relies on to
 * render per-key provenance without a second, redundant `Delegation` query
 * inside `AgentLoopService` itself.
 *
 * Written before `ResultAggregationService` exists at all -- every
 * assertion below is expected to FAIL red (class not found).
 */
class ResultAggregationServiceTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->seedOperationCatalog();
    }

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('agent_delegations')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Operation-catalog scaffolding (DelegationServiceResultMappingTest's
    // own established precedent -- AgentService::create() resolves
    // AgentDefinitionParser -> ApiManager -> the Scramble Generator, which
    // needs this mocked out or it tries to build a real PhpParser\Parser.
    // -----------------------------------------------------------------

    private function seedOperationCatalog(): void
    {
        $doc = ['paths' => []];

        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, $doc);

        $generator = Mockery::mock(Generator::class);
        $generator->shouldReceive('__invoke')->andReturn($doc);
        $this->app->instance(Generator::class, $generator);
    }

    private function clearOperationCatalog(): void
    {
        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    // -----------------------------------------------------------------
    // Fixture helpers
    // -----------------------------------------------------------------

    private function makeAgent(string $name): Agent
    {
        return app(AgentService::class)->create($this->user->id, "name: {$name}\ninstructions: I am {$name}.");
    }

    private function service(): ResultAggregationService
    {
        return new ResultAggregationService(new ContentSanitizer());
    }

    /**
     * A `Delegation` row created directly -- no real Conversation/Server
     * fixture needed (agent_delegations carries no DB-level FK), just a
     * real Agent row for helper_agent_name resolution.
     */
    private function makeDelegationRow(string $runId, Agent $helper, array $overrides = []): Delegation
    {
        return Delegation::create(array_merge([
            'parent_conversation_id' => (string) Str::uuid(),
            'helper_agent_id' => $helper->id,
            'helper_conversation_id' => (string) Str::uuid(),
            'owner_user_id' => $this->user->id,
            'task' => 'Do the thing.',
            'context' => 'Some context.',
            'depth' => 1,
            'status' => 'completed',
            'parent_run_id' => $runId,
            'outcome_summary' => 'Completed normally.',
            'started_at' => now(),
            'completed_at' => now(),
            'result_reason' => null,
            'result_truncated' => false,
        ], $overrides));
    }

    // =================================================================
    // Fewer than two qualifying delegations -> null
    // =================================================================

    #[Test]
    public function returns_null_for_a_run_with_zero_delegations(): void
    {
        $runId = (string) Str::uuid();

        $this->assertNull($this->service()->combineForRun($runId));
    }

    #[Test]
    public function returns_null_for_a_run_with_exactly_one_qualifying_delegation(): void
    {
        $runId = (string) Str::uuid();
        $helper = $this->makeAgent('helper-one');

        $this->makeDelegationRow($runId, $helper, [
            'result_status' => 'success',
            'result_summary' => 'Done.',
            'result_output' => json_encode(['a' => 1]),
            'result_undone' => '',
        ]);

        $this->assertNull($this->service()->combineForRun($runId));
    }

    #[Test]
    public function in_progress_delegations_with_a_null_result_status_never_count_regardless_of_how_many_exist(): void
    {
        $runId = (string) Str::uuid();
        $helperOne = $this->makeAgent('helper-in-progress-one');
        $helperTwo = $this->makeAgent('helper-in-progress-two');
        $helperThree = $this->makeAgent('helper-in-progress-three');

        // Three in-progress delegations -- result_status IS NULL on all
        // three -- must still return null: "in-progress delegations...
        // never count, regardless of how many exist" (tasks.md T030).
        foreach ([$helperOne, $helperTwo, $helperThree] as $helper) {
            $this->makeDelegationRow($runId, $helper, [
                'status' => 'in_progress',
                'result_status' => null,
                'result_summary' => null,
                'result_output' => null,
                'result_undone' => null,
                'completed_at' => null,
            ]);
        }

        $this->assertNull($this->service()->combineForRun($runId));
    }

    #[Test]
    public function one_qualifying_delegation_plus_any_number_of_in_progress_ones_still_returns_null(): void
    {
        $runId = (string) Str::uuid();
        $qualifying = $this->makeAgent('helper-qualifying');
        $inProgress = $this->makeAgent('helper-still-running');

        $this->makeDelegationRow($runId, $qualifying, [
            'result_status' => 'success',
            'result_summary' => 'Done.',
            'result_output' => json_encode(['a' => 1]),
            'result_undone' => '',
        ]);
        $this->makeDelegationRow($runId, $inProgress, [
            'status' => 'in_progress',
            'result_status' => null,
            'result_summary' => null,
            'result_output' => null,
            'result_undone' => null,
            'completed_at' => null,
        ]);

        $this->assertNull(
            $this->service()->combineForRun($runId),
            'a single genuinely-qualifying delegation, plus an unrelated in-progress one, is still only one qualifying delegation',
        );
    }

    // =================================================================
    // Two or more qualifying delegations -> combined view
    // =================================================================

    #[Test]
    public function combines_two_qualifying_delegations_with_distinct_output_keys_and_full_provenance(): void
    {
        $runId = (string) Str::uuid();
        $extractor = $this->makeAgent('Invoice Line-Item Extractor');
        $normalizer = $this->makeAgent('Currency Normalizer');

        $extractorDelegation = $this->makeDelegationRow($runId, $extractor, [
            'result_status' => 'success',
            'result_summary' => 'Extracted the line items.',
            'result_output' => json_encode(['line_items' => ['Widget A', 'Widget B']]),
            'result_undone' => '',
            'started_at' => now()->subMinute(),
        ]);
        $normalizerDelegation = $this->makeDelegationRow($runId, $normalizer, [
            'result_status' => 'partial',
            'result_reason' => 'helper_reported',
            'result_summary' => 'Normalized the currency.',
            'result_output' => json_encode(['currency' => 'USD']),
            'result_undone' => 'Still need to verify the exchange rate.',
        ]);

        $combined = $this->service()->combineForRun($runId);

        $this->assertNotNull($combined);
        $this->assertSame(['contributors', 'combined_output', 'conflicts', 'truncated'], array_keys($combined));
        $this->assertCount(2, $combined['contributors']);

        $byDelegationId = collect($combined['contributors'])->keyBy('delegation_id');

        $extractorRow = $byDelegationId[$extractorDelegation->id];
        $this->assertArrayHasKey('delegation_id', $extractorRow);
        $this->assertArrayHasKey('helper_agent_id', $extractorRow);
        $this->assertArrayHasKey('helper_agent_name', $extractorRow);
        $this->assertArrayHasKey('status', $extractorRow);
        $this->assertArrayHasKey('summary', $extractorRow);
        $this->assertArrayHasKey('undone', $extractorRow);
        $this->assertSame($extractor->id, $extractorRow['helper_agent_id']);
        $this->assertSame('Invoice Line-Item Extractor', $extractorRow['helper_agent_name']);
        $this->assertSame('success', $extractorRow['status']);
        $this->assertSame('Extracted the line items.', $extractorRow['summary']);
        $this->assertSame('', $extractorRow['undone']);

        $normalizerRow = $byDelegationId[$normalizerDelegation->id];
        $this->assertSame($normalizer->id, $normalizerRow['helper_agent_id']);
        $this->assertSame('Currency Normalizer', $normalizerRow['helper_agent_name']);
        $this->assertSame('partial', $normalizerRow['status']);
        $this->assertSame('Normalized the currency.', $normalizerRow['summary']);
        $this->assertSame('Still need to verify the exchange rate.', $normalizerRow['undone']);

        $this->assertSame(
            ['line_items' => ['Widget A', 'Widget B'], 'currency' => 'USD'],
            $combined['combined_output'],
            'combined_output must be the union of every contributor\'s result_output map keyed by field name',
        );
        $this->assertSame([], $combined['conflicts']);
        $this->assertFalse($combined['truncated']);
    }

    #[Test]
    public function contributors_are_ordered_by_started_at(): void
    {
        $runId = (string) Str::uuid();
        $second = $this->makeAgent('helper-second');
        $first = $this->makeAgent('helper-first');

        $secondDelegation = $this->makeDelegationRow($runId, $second, [
            'result_status' => 'success',
            'result_summary' => 'Second.',
            'result_output' => json_encode(['b' => 2]),
            'result_undone' => '',
            'started_at' => now(),
        ]);
        $firstDelegation = $this->makeDelegationRow($runId, $first, [
            'result_status' => 'success',
            'result_summary' => 'First.',
            'result_output' => json_encode(['a' => 1]),
            'result_undone' => '',
            'started_at' => now()->subMinutes(5),
        ]);

        $combined = $this->service()->combineForRun($runId);

        $this->assertSame(
            [$firstDelegation->id, $secondDelegation->id],
            array_column($combined['contributors'], 'delegation_id'),
        );
    }

    #[Test]
    public function contributors_carry_their_own_decoded_output_map_for_downstream_provenance_rendering(): void
    {
        $runId = (string) Str::uuid();
        $extractor = $this->makeAgent('helper-output-field-one');
        $normalizer = $this->makeAgent('helper-output-field-two');

        $extractorDelegation = $this->makeDelegationRow($runId, $extractor, [
            'result_status' => 'success',
            'result_summary' => 'Done.',
            'result_output' => json_encode(['line_items' => ['A']]),
            'result_undone' => '',
        ]);
        $normalizerDelegation = $this->makeDelegationRow($runId, $normalizer, [
            'result_status' => 'success',
            'result_summary' => 'Done.',
            'result_output' => json_encode(['currency' => 'USD']),
            'result_undone' => '',
        ]);

        $combined = $this->service()->combineForRun($runId);
        $byDelegationId = collect($combined['contributors'])->keyBy('delegation_id');

        $this->assertSame(['line_items' => ['A']], $byDelegationId[$extractorDelegation->id]['output']);
        $this->assertSame(['currency' => 'USD'], $byDelegationId[$normalizerDelegation->id]['output']);
    }

    #[Test]
    public function a_failure_contributor_with_null_result_output_contributes_no_keys_but_still_appears_in_contributors(): void
    {
        $runId = (string) Str::uuid();
        $succeeding = $this->makeAgent('helper-succeeding');
        $failing = $this->makeAgent('helper-failing');

        $this->makeDelegationRow($runId, $succeeding, [
            'result_status' => 'success',
            'result_summary' => 'Done.',
            'result_output' => json_encode(['a' => 1]),
            'result_undone' => '',
        ]);
        $failingDelegation = $this->makeDelegationRow($runId, $failing, [
            'status' => 'failed',
            'result_status' => 'failure',
            'result_reason' => 'malformed_output',
            'result_summary' => 'The helper produced no output at all.',
            'result_output' => null,
            'result_undone' => '',
        ]);

        $combined = $this->service()->combineForRun($runId);

        $this->assertCount(2, $combined['contributors']);
        $this->assertSame(['a' => 1], $combined['combined_output']);

        $failingRow = collect($combined['contributors'])->firstWhere('delegation_id', $failingDelegation->id);
        $this->assertNotNull($failingRow);
        $this->assertSame('failure', $failingRow['status']);
    }

    // =================================================================
    // Phase 8 (Polish) gap closure -- spec.md's own Edge Cases: "What
    // happens when a parent combines results from helpers where one
    // succeeded, one partially succeeded, and one failed outright?" The
    // two existing fixtures above only ever pair two of the three statuses
    // at once (success+partial, success+failure); no test previously
    // exercised a genuine three-way mix in a single combineForRun() call.
    // Since the combined view carries no top-level "overall status" field
    // at all (only array_keys() === ['contributors', 'combined_output',
    // 'conflicts', 'truncated']), collapsing is structurally impossible --
    // this test proves it directly rather than by inference, confirming
    // each contributor's own status/summary/undone survives independently
    // and combined_output still unions every non-failing contributor's
    // keys.
    // =================================================================

    #[Test]
    public function a_combined_view_mixing_success_partial_and_failure_contributors_never_collapses_into_one_overall_status(): void
    {
        $runId = (string) Str::uuid();
        $succeeding = $this->makeAgent('helper-three-way-success');
        $partial = $this->makeAgent('helper-three-way-partial');
        $failing = $this->makeAgent('helper-three-way-failure');

        $succeedingDelegation = $this->makeDelegationRow($runId, $succeeding, [
            'result_status' => 'success',
            'result_summary' => 'Fully done.',
            'result_output' => json_encode(['a' => 1]),
            'result_undone' => '',
        ]);
        $partialDelegation = $this->makeDelegationRow($runId, $partial, [
            'result_status' => 'partial',
            'result_reason' => 'helper_reported',
            'result_summary' => 'Partly done.',
            'result_output' => json_encode(['b' => 2]),
            'result_undone' => 'Still need to check the edge cases.',
        ]);
        $failingDelegation = $this->makeDelegationRow($runId, $failing, [
            'status' => 'failed',
            'result_status' => 'failure',
            'result_reason' => 'exception',
            'result_summary' => 'The delegation failed due to an unexpected error.',
            'result_output' => null,
            'result_undone' => 'Everything -- the task could not be completed.',
        ]);

        $combined = $this->service()->combineForRun($runId);

        $this->assertNotNull($combined);
        $this->assertArrayNotHasKey(
            'status',
            $combined,
            'the combined view itself must never carry a top-level overall status -- only each contributor\'s own status',
        );
        $this->assertCount(3, $combined['contributors']);

        $byDelegationId = collect($combined['contributors'])->keyBy('delegation_id');
        $this->assertSame('success', $byDelegationId[$succeedingDelegation->id]['status']);
        $this->assertSame('partial', $byDelegationId[$partialDelegation->id]['status']);
        $this->assertSame('failure', $byDelegationId[$failingDelegation->id]['status']);

        $this->assertSame(
            ['a' => 1, 'b' => 2],
            $combined['combined_output'],
            'combined_output unions every non-failing contributor\'s keys regardless of the mix of statuses present',
        );
    }

    // =================================================================
    // Phase 8 (Polish) gap closure -- spec.md's own Edge Cases: "What
    // happens when the same helper is delegated to twice in the same
    // parent turn and both results are combined? Each result keeps its
    // own provenance (including which delegation/attempt it came from),
    // even though the originating helper is the same for both." No prior
    // test in this file ever created two Delegation rows sharing one
    // helper_agent_id, so `$byDelegationId` keying in every other test
    // never had two rows collide under the same helper.
    // =================================================================

    #[Test]
    public function the_same_helper_delegated_to_twice_keeps_each_delegations_own_separate_provenance(): void
    {
        $runId = (string) Str::uuid();
        $helper = $this->makeAgent('helper-delegated-to-twice');

        $firstAttempt = $this->makeDelegationRow($runId, $helper, [
            'result_status' => 'partial',
            'result_reason' => 'bound_exceeded',
            'result_summary' => 'Got through the first half before running out of time.',
            'result_output' => null,
            'result_undone' => 'Reached its time limit before finishing.',
            'started_at' => now()->subMinutes(5),
        ]);
        $secondAttempt = $this->makeDelegationRow($runId, $helper, [
            'result_status' => 'success',
            'result_summary' => 'Finished the rest on a second attempt.',
            'result_output' => json_encode(['remaining_items' => 3]),
            'result_undone' => '',
            'started_at' => now(),
        ]);

        $combined = $this->service()->combineForRun($runId);

        $this->assertNotNull($combined);
        $this->assertCount(2, $combined['contributors']);

        $delegationIds = array_column($combined['contributors'], 'delegation_id');
        $this->assertSame(
            [$firstAttempt->id, $secondAttempt->id],
            $delegationIds,
            'each delegation attempt keeps its own separate delegation_id, ordered by started_at, even though both share one helper_agent_id',
        );
        $this->assertNotSame(
            $firstAttempt->id,
            $secondAttempt->id,
            'fixture sanity: two genuinely distinct delegation rows',
        );

        $byDelegationId = collect($combined['contributors'])->keyBy('delegation_id');
        $this->assertSame($helper->id, $byDelegationId[$firstAttempt->id]['helper_agent_id']);
        $this->assertSame($helper->id, $byDelegationId[$secondAttempt->id]['helper_agent_id']);
        $this->assertSame('partial', $byDelegationId[$firstAttempt->id]['status']);
        $this->assertSame('success', $byDelegationId[$secondAttempt->id]['status']);
        $this->assertSame(
            'Got through the first half before running out of time.',
            $byDelegationId[$firstAttempt->id]['summary'],
        );
        $this->assertSame(
            'Finished the rest on a second attempt.',
            $byDelegationId[$secondAttempt->id]['summary'],
        );

        $this->assertSame(['remaining_items' => 3], $combined['combined_output']);
    }

    // =================================================================
    // 099-result-aggregation, Phase 6 (US4), tasks.md T038 -- conflict
    // detection (research.md D6). Sequenced after T030's own tests above,
    // not [P]. `combineForRun()` currently hardcodes `conflicts` to `[]`
    // and unions every key unconditionally, so every case below is
    // expected to FAIL red until Phase 6's implementation (T041) exists.
    // =================================================================

    #[Test]
    public function a_key_with_differing_values_across_contributors_is_excluded_from_combined_output_and_recorded_as_a_conflict(): void
    {
        $runId = (string) Str::uuid();
        $extractor = $this->makeAgent('Invoice Line-Item Extractor');
        $normalizer = $this->makeAgent('Currency Normalizer');

        $extractorDelegation = $this->makeDelegationRow($runId, $extractor, [
            'result_status' => 'success',
            'result_summary' => 'Computed the total.',
            'result_output' => json_encode(['total' => '1042.50']),
            'result_undone' => '',
        ]);
        $normalizerDelegation = $this->makeDelegationRow($runId, $normalizer, [
            'result_status' => 'success',
            'result_summary' => 'Recomputed the total.',
            'result_output' => json_encode(['total' => '1024.50']),
            'result_undone' => '',
        ]);

        $combined = $this->service()->combineForRun($runId);

        $this->assertArrayNotHasKey(
            'total',
            $combined['combined_output'],
            'a key with two differing values must never appear in combined_output -- mutation-checklist row 5',
        );
        $this->assertCount(1, $combined['conflicts']);

        $conflict = $combined['conflicts'][0];
        $this->assertSame('total', $conflict['key']);
        $this->assertCount(
            2,
            $conflict['values'],
            'every disagreeing value must be retained, not just the most recent -- mutation-checklist row 6',
        );

        $byDelegationId = collect($conflict['values'])->keyBy('delegation_id');

        $extractorValue = $byDelegationId[$extractorDelegation->id];
        $this->assertSame('1042.50', $extractorValue['value']);
        $this->assertSame($extractor->id, $extractorValue['helper_agent_id']);
        $this->assertSame('Invoice Line-Item Extractor', $extractorValue['helper_agent_name']);

        $normalizerValue = $byDelegationId[$normalizerDelegation->id];
        $this->assertSame('1024.50', $normalizerValue['value']);
        $this->assertSame($normalizer->id, $normalizerValue['helper_agent_id']);
        $this->assertSame('Currency Normalizer', $normalizerValue['helper_agent_name']);
    }

    #[Test]
    public function a_key_present_in_only_one_contributors_map_is_not_a_conflict(): void
    {
        $runId = (string) Str::uuid();
        $extractor = $this->makeAgent('helper-unique-key-one');
        $normalizer = $this->makeAgent('helper-unique-key-two');

        $this->makeDelegationRow($runId, $extractor, [
            'result_status' => 'success',
            'result_summary' => 'Done.',
            'result_output' => json_encode(['line_items' => ['Widget A']]),
            'result_undone' => '',
        ]);
        $this->makeDelegationRow($runId, $normalizer, [
            'result_status' => 'success',
            'result_summary' => 'Done.',
            'result_output' => json_encode(['currency' => 'USD']),
            'result_undone' => '',
        ]);

        $combined = $this->service()->combineForRun($runId);

        $this->assertSame(
            ['line_items' => ['Widget A'], 'currency' => 'USD'],
            $combined['combined_output'],
            'a key produced by exactly one contributor is not a conflict and must appear normally',
        );
        $this->assertSame([], $combined['conflicts']);
    }

    #[Test]
    public function a_key_present_in_several_contributors_with_an_identical_value_is_not_a_conflict(): void
    {
        $runId = (string) Str::uuid();
        $extractor = $this->makeAgent('helper-identical-value-one');
        $normalizer = $this->makeAgent('helper-identical-value-two');

        $this->makeDelegationRow($runId, $extractor, [
            'result_status' => 'success',
            'result_summary' => 'Done.',
            'result_output' => json_encode(['line_items' => ['Widget A'], 'currency' => 'USD']),
            'result_undone' => '',
        ]);
        $this->makeDelegationRow($runId, $normalizer, [
            'result_status' => 'success',
            'result_summary' => 'Done.',
            'result_output' => json_encode(['currency' => 'USD']),
            'result_undone' => '',
        ]);

        $combined = $this->service()->combineForRun($runId);

        $this->assertSame(
            ['line_items' => ['Widget A'], 'currency' => 'USD'],
            $combined['combined_output'],
            'the same value reported by multiple contributors is not a conflict (research.md D6\'s "differing values" qualifier) and must appear normally',
        );
        $this->assertSame([], $combined['conflicts']);
    }

    // =================================================================
    // 099-result-aggregation, Phase 7 (US6), tasks.md T046: a dedicated,
    // cap-forcing proof that the combined-view truncation (Phase 5/T033,
    // ResultAggregationService's own ContentSanitizer::truncate() call
    // against config('llm-client.delegation.combined_output_cap_bytes'))
    // applies to the ASSEMBLED WHOLE, not per-contributor -- directly
    // targeting mutation-checklist row 7's own named failure mode (quickstart
    // scenario 7). Four contributors, each individually well within its own
    // default 8192-byte result_output_cap_bytes, must still force
    // truncated: true and an assembled byte length at or under the 200-byte
    // combined cap regardless of contributor count.
    // =================================================================

    #[Test]
    public function combined_output_is_truncated_against_the_combined_cap_regardless_of_how_many_contributors_are_individually_under_their_own_cap(): void
    {
        config(['llm-client.delegation.combined_output_cap_bytes' => 200]);

        $runId = (string) Str::uuid();

        $helperNames = [
            'helper-combined-cap-one',
            'helper-combined-cap-two',
            'helper-combined-cap-three',
            'helper-combined-cap-four',
        ];

        foreach ($helperNames as $i => $name) {
            $helper = $this->makeAgent($name);
            $output = ["field_{$i}" => "a moderately descriptive value for contributor number {$i}"];

            $this->assertLessThan(
                8192,
                strlen(json_encode($output)),
                'fixture sanity: each individual contributor\'s own output must stay well within its own default result_output_cap_bytes',
            );

            $this->makeDelegationRow($runId, $helper, [
                'result_status' => 'success',
                'result_summary' => "Done ({$i}).",
                'result_output' => json_encode($output),
                'result_undone' => '',
            ]);
        }

        $combined = $this->service()->combineForRun($runId);

        $this->assertNotNull($combined);
        $this->assertTrue(
            $combined['truncated'],
            'combineForRun() must report truncated: true when the assembled combined_output/conflicts payload exceeds the 200-byte combined cap, regardless of how many individually-small contributors fed into it',
        );

        $assembled = json_encode([
            'combined_output' => $combined['combined_output'],
            'conflicts' => $combined['conflicts'],
        ]);
        $this->assertLessThanOrEqual(
            200,
            strlen($assembled),
            'the assembled combined_output/conflicts JSON must itself stay at or under the configured combined cap',
        );
    }

    // =================================================================
    // Phase 8 (Polish) gap closure -- spec.md's own Edge Cases: "What
    // happens when truncation (User Story 6) occurs on a result that is
    // also flagged partial success or conflicting? All three markers
    // (partial-success, conflict, truncation) can be present
    // simultaneously on the same result; they are independent flags, not
    // mutually exclusive states." No prior test combined all three at
    // once -- the conflict tests above never configured a low cap, and the
    // cap-forcing test above never included a partial-status contributor
    // or a conflicting key.
    // =================================================================

    #[Test]
    public function truncation_partial_status_and_a_conflict_can_all_be_present_on_the_same_combined_view_simultaneously(): void
    {
        $runId = (string) Str::uuid();
        $partialHelper = $this->makeAgent('helper-partial-with-conflict');
        $successHelper = $this->makeAgent('helper-success-with-conflict');

        $partialDelegation = $this->makeDelegationRow($runId, $partialHelper, [
            'result_status' => 'partial',
            'result_reason' => 'helper_reported',
            'result_summary' => 'Got most of the way there.',
            'result_output' => json_encode([
                'shared' => 'value-from-partial',
                'filler_partial' => str_repeat('p', 200),
            ]),
            'result_undone' => 'Still need to reconcile the totals.',
        ]);
        $this->makeDelegationRow($runId, $successHelper, [
            'result_status' => 'success',
            'result_summary' => 'Computed the shared value independently.',
            'result_output' => json_encode([
                'shared' => 'value-from-success',
                'filler_success' => str_repeat('s', 200),
            ]),
            'result_undone' => '',
        ]);

        // Large enough to still hold the conflict entry after
        // pruneToFitCap() drops both bulky filler keys, small enough that
        // the unpruned assembled payload (well over 500 bytes, between the
        // two ~200-byte filler values and the conflict's own provenance)
        // genuinely exceeds it.
        config(['llm-client.delegation.combined_output_cap_bytes' => 600]);

        $combined = $this->service()->combineForRun($runId);

        $this->assertNotNull($combined);

        $partialRow = collect($combined['contributors'])->firstWhere('delegation_id', $partialDelegation->id);
        $this->assertNotNull($partialRow);
        $this->assertSame(
            'partial',
            $partialRow['status'],
            'a contributor\'s own partial status must survive unaffected by truncation/conflict handling elsewhere in the same combined view',
        );

        $this->assertNotEmpty(
            $combined['conflicts'],
            'the "shared" key\'s conflict must still be recorded even though the combined view is also truncated',
        );
        $this->assertSame('shared', $combined['conflicts'][0]['key']);

        $this->assertTrue(
            $combined['truncated'],
            'truncation, a partial-status contributor, and a conflict are independent flags -- none suppresses another',
        );

        $assembled = json_encode([
            'combined_output' => $combined['combined_output'],
            'conflicts' => $combined['conflicts'],
        ]);
        $this->assertLessThanOrEqual(600, strlen($assembled));
    }
}
