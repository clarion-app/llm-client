<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\ResultAggregationService;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 099-result-aggregation, Phase 5 (US3), tasks.md T031.
 *
 * Unit tests for the new
 * `AgentLoopService::buildCombinedHelperResultsSection(?string $runId): ?string`
 * (data-model.md §5, contracts/result-aggregation-meta-tool.md §3) --
 * mirrors `buildKnownHelpersSection()`'s own established shape, and
 * exercises it exactly the way `AgentLoopServiceTest::invokeBuildKnownOperationsSection()`
 * exercises its own sibling section builder: directly, via reflection,
 * since these builders are not part of the class's public surface.
 * `ResultAggregationService` is bound into the container as a full
 * Mockery double -- no real Delegation/Agent fixtures, no LLM call,
 * matching research.md D5's "fully testable... independent of any LLM
 * call" claim for the combination machinery this method only renders,
 * never computes.
 *
 * Design note (see `ResultAggregationServiceTest`'s own T030 docblock for
 * the full rationale): each mocked `contributors` entry below carries an
 * `output` field beyond the six data-model.md §4 names, since
 * contracts §3's own literal example attributes each `combined_output`
 * entry to the specific helper name(s) that produced it -- data neither
 * `combined_output` (a flat map) nor a bare `contributors` entry (without
 * `output`) can reconstruct on its own.
 *
 * Written before `buildCombinedHelperResultsSection()` exists at all --
 * every assertion below is expected to FAIL red (method does not exist on
 * `AgentLoopService`).
 */
class AgentLoopServiceCombinedResultsSectionTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function service(): AgentLoopService
    {
        return new AgentLoopService(
            app(McpToolRegistry::class),
            app(McpToolExecutor::class),
            app(OperationCache::class),
        );
    }

    /**
     * Invokes the (expected-private) buildCombinedHelperResultsSection()
     * via reflection -- AgentLoopServiceTest's own established
     * invokeBuildKnownOperationsSection() precedent for a system-prompt
     * section builder that is not part of the class's public surface.
     * setAccessible(true) also tolerates the method turning out public.
     */
    private function invoke(AgentLoopService $service, ?string $runId): ?string
    {
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('buildCombinedHelperResultsSection');
        $method->setAccessible(true);

        return $method->invoke($service, $runId);
    }

    /**
     * 109-agent-as-capability: buildCombinedHelperResultsSection() now
     * calls combineForRun($runId, callerFacing: true) -- the mock
     * expectation is updated to match this exact two-argument call shape
     * (not merely the run id), so this helper stays non-vacuous about
     * *how* the section builder calls the service, not only *that* it
     * does.
     */
    private function mockCombineForRun(?array $return, ?string $expectedRunId = null): void
    {
        $mock = Mockery::mock(ResultAggregationService::class);
        $expectation = $mock->shouldReceive('combineForRun')->once();
        if ($expectedRunId !== null) {
            $expectation->with($expectedRunId, true);
        }
        $expectation->andReturn($return);
        $this->app->instance(ResultAggregationService::class, $mock);
    }

    // =================================================================
    // null when combineForRun() returns null
    // =================================================================

    #[Test]
    public function returns_null_when_combine_for_run_returns_null(): void
    {
        $runId = 'run-with-fewer-than-two-delegations';
        $this->mockCombineForRun(null, $runId);

        $this->assertNull($this->invoke($this->service(), $runId));
    }

    #[Test]
    public function passes_the_run_id_through_to_combine_for_run_unchanged(): void
    {
        $runId = 'exact-run-id-1234';
        $this->mockCombineForRun(null, $runId);

        // Mockery's own with()/once() expectations set up in
        // mockCombineForRun() are the assertion here -- Mockery::close()
        // in tearDown() verifies the run id actually reached the service
        // call unchanged, not merely that *some* string was passed.
        $this->invoke($this->service(), $runId);
        $this->assertTrue(true);
    }

    // =================================================================
    // Non-null combined view -> rendered "## Combined Helper Results"
    // section with per-key provenance (contracts §3)
    // =================================================================

    #[Test]
    public function renders_the_combined_helper_results_section_with_provenance_when_combine_for_run_is_non_null(): void
    {
        $runId = 'run-with-two-delegations';

        $this->mockCombineForRun([
            'contributors' => [
                [
                    'delegation_id' => 'delegation-extractor',
                    'helper_agent_id' => 'agent-extractor',
                    'helper_agent_name' => 'Invoice Line-Item Extractor',
                    'status' => 'success',
                    'summary' => 'Extracted all five line items from the invoice.',
                    'undone' => '',
                    'output' => ['line_items' => ['Widget A', 'Widget B'], 'currency' => 'USD'],
                ],
                [
                    'delegation_id' => 'delegation-normalizer',
                    'helper_agent_id' => 'agent-normalizer',
                    'helper_agent_name' => 'Currency Normalizer',
                    'status' => 'success',
                    'summary' => 'Normalized all currency fields to USD.',
                    'undone' => '',
                    'output' => ['currency' => 'USD'],
                ],
            ],
            'combined_output' => [
                'line_items' => ['Widget A', 'Widget B'],
                'currency' => 'USD',
            ],
            'conflicts' => [],
            'truncated' => false,
        ], $runId);

        $section = $this->invoke($this->service(), $runId);

        $this->assertNotNull($section);
        $this->assertStringContainsString('## Combined Helper Results', $section);
        $this->assertStringContainsString('line_items', $section);
        $this->assertStringContainsString(
            '(from "Invoice Line-Item Extractor")',
            $section,
            'a key produced by exactly one contributor must name that one contributor',
        );
        $this->assertStringContainsString('currency', $section);
        $this->assertStringContainsString(
            '(from "Invoice Line-Item Extractor", "Currency Normalizer")',
            $section,
            'a key produced identically by two contributors must name both, in contributor order',
        );
        $this->assertStringNotContainsString(
            'Conflicting',
            $section,
            'no conflicts block belongs in the Phase 5 baseline case -- conflicts is empty (Phase 6 adds the conflicting case)',
        );
    }

    // =================================================================
    // 099-result-aggregation, Phase 6 (US4), tasks.md T039 -- a non-empty
    // `conflicts` renders a distinct "Conflicting values" block
    // (contracts §3's exact format, mutation-checklist row 8, quickstart
    // scenario 4's system-prompt assertion). Sequenced after T031's own
    // test above, not [P].
    // =================================================================

    #[Test]
    public function renders_a_conflicting_values_block_naming_both_values_and_their_originating_helpers_when_conflicts_is_non_empty(): void
    {
        $runId = 'run-with-a-conflict';

        $this->mockCombineForRun([
            'contributors' => [
                [
                    'delegation_id' => 'delegation-extractor',
                    'helper_agent_id' => 'agent-extractor',
                    'helper_agent_name' => 'Invoice Line-Item Extractor',
                    'status' => 'success',
                    'summary' => 'Computed the total.',
                    'undone' => '',
                    'output' => ['line_items' => ['Widget A', 'Widget B']],
                ],
                [
                    'delegation_id' => 'delegation-normalizer',
                    'helper_agent_id' => 'agent-normalizer',
                    'helper_agent_name' => 'Currency Normalizer',
                    'status' => 'success',
                    'summary' => 'Recomputed the total.',
                    'undone' => '',
                    'output' => ['currency' => 'USD'],
                ],
            ],
            'combined_output' => [
                'line_items' => ['Widget A', 'Widget B'],
                'currency' => 'USD',
            ],
            'conflicts' => [
                [
                    'key' => 'total',
                    'values' => [
                        [
                            'value' => '1042.50',
                            'delegation_id' => 'delegation-extractor',
                            'helper_agent_id' => 'agent-extractor',
                            'helper_agent_name' => 'Invoice Line-Item Extractor',
                        ],
                        [
                            'value' => '1024.50',
                            'delegation_id' => 'delegation-normalizer',
                            'helper_agent_id' => 'agent-normalizer',
                            'helper_agent_name' => 'Currency Normalizer',
                        ],
                    ],
                ],
            ],
            'truncated' => false,
        ], $runId);

        $section = $this->invoke($this->service(), $runId);

        $this->assertNotNull($section);
        $this->assertStringContainsString(
            '⚠ Conflicting values — not resolved automatically:',
            $section,
            'contracts §3\'s exact heading text for the conflicts block',
        );
        $this->assertStringContainsString(
            '- total: "1042.50" (from "Invoice Line-Item Extractor") vs "1024.50" (from "Currency Normalizer")',
            $section,
            'contracts §3\'s exact per-conflict line format, naming both values and their originating helpers',
        );

        $conflictBlockPos = strpos($section, 'Conflicting values');
        $combinedOutputPos = strpos($section, 'The following facts were produced');
        $this->assertNotFalse($conflictBlockPos);
        $this->assertNotFalse($combinedOutputPos);
        $this->assertGreaterThan(
            $combinedOutputPos,
            $conflictBlockPos,
            'the conflicts block is distinct from, and rendered after, the plain combined_output listing',
        );
    }

    // =================================================================
    // Phase 8 (Polish) gap closure, mutation-checklist row 8: every test
    // above invokes buildCombinedHelperResultsSection() directly via
    // reflection, so none of them would notice the method's own call site
    // being deleted from buildMessagesPayload() -- confirmed by manually
    // applying that exact mutation (deleting the `$combinedHelperResultsSection
    // = $this->buildCombinedHelperResultsSection($runId); ...` block at
    // AgentLoopService.php's own buildMessagesPayload()) and observing the
    // full suite, including every test in this file, stay green. This test
    // drives the real public buildMessagesPayload($conversation, $runId)
    // entry point instead -- mirroring AgentLoopServiceTest's own
    // `build_messages_payload_includes_known_operations_section` precedent
    // for its sibling section builder -- so a deleted call site is caught
    // here even though the section-rendering logic itself is exercised
    // only above.
    // =================================================================

    #[Test]
    public function build_messages_payload_includes_the_combined_helper_results_section_when_wired(): void
    {
        $conversation = Conversation::factory()->create();
        $runId = 'run-wired-through-build-messages-payload';

        $this->mockCombineForRun([
            'contributors' => [
                [
                    'delegation_id' => 'delegation-a',
                    'helper_agent_id' => 'agent-a',
                    'helper_agent_name' => 'Helper A',
                    'status' => 'success',
                    'summary' => 'Did the thing.',
                    'undone' => '',
                    'output' => ['result_field' => 'value-a'],
                ],
                [
                    'delegation_id' => 'delegation-b',
                    'helper_agent_id' => 'agent-b',
                    'helper_agent_name' => 'Helper B',
                    'status' => 'success',
                    'summary' => 'Did the other thing.',
                    'undone' => '',
                    'output' => ['other_field' => 'value-b'],
                ],
            ],
            'combined_output' => [
                'result_field' => 'value-a',
                'other_field' => 'value-b',
            ],
            'conflicts' => [],
            'truncated' => false,
        ], $runId);

        $messages = $this->service()->buildMessagesPayload($conversation, $runId);

        $systemMsg = collect($messages)->firstWhere('role', 'system');
        $this->assertNotNull($systemMsg, 'buildMessagesPayload() must emit a system message carrying the combined section');
        $this->assertStringContainsString(
            '## Combined Helper Results',
            $systemMsg['content'],
            'buildMessagesPayload() must actually call buildCombinedHelperResultsSection($runId) and append its result -- mutation-checklist row 8',
        );
        $this->assertStringContainsString('result_field', $systemMsg['content']);
        $this->assertStringContainsString('other_field', $systemMsg['content']);
    }

}
