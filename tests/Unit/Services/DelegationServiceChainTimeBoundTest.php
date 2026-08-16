<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentHelperAssignment;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\DelegationService;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 110-delegation-deadlock-timeout, Phase 4 (US2, tasks.md T017/T019,
 * research.md D1, contracts/delegation-chain-bounds.md §1).
 *
 * DelegationService::resolveAndValidate() has no `delegation_chain_time_exceeded`
 * check yet -- it is added by T023, using the already-existing (Phase 2)
 * private `chainRootStartedAt()` compared against
 * `config('llm-client.delegation.max_chain_seconds')`, checked AFTER the
 * existing depth check (data-model.md "Validation rules": depth first,
 * chain-time second, both refuse before a new Delegation row is ever
 * written). Every test in this file is expected to fail right now:
 * T017's own scenario currently returns a plain success shape (no 'error'
 * key at all) since nothing yet reads max_chain_seconds inside
 * resolveAndValidate(); T019 is a check-order guard that already holds
 * today (the depth check already exists and is the only one that can ever
 * fire) and continues to hold once T023 lands, so it is not expected to be
 * red in the way T017 is -- see this file's own doc on that test.
 *
 * Fixture and invocation style mirrors DelegationServiceCycleRegressionTest's
 * (Phase 3) own established convention: `Delegation` rows are seeded
 * DIRECTLY at the model layer via seedDelegationRow(), and the still-private
 * `resolveAndValidate()` is invoked directly via reflection -- these
 * scenarios never need to reach the nested AgentLoopService::run() call at
 * all.
 */
class DelegationServiceChainTimeBoundTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('agent_delegations')->delete();
        DB::table('agent_helper_assignments')->delete();
        DB::table('conversations')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Operation-catalog scaffolding (DelegationServiceCycleRegressionTest's
    // own established precedent)
    // -----------------------------------------------------------------

    private function seedOperationCatalog(array $operations = []): void
    {
        $paths = [];
        foreach ($operations as $operationId => $entry) {
            $paths[$entry['path']][$entry['method']] = [
                'operationId' => $operationId,
                'summary' => $entry['summary'],
            ];
        }
        $doc = ['paths' => $paths];

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
    // Fixture helpers (mirrors DelegationServiceCycleRegressionTest's own
    // fixture helpers)
    // -----------------------------------------------------------------

    private function delegationService(): DelegationService
    {
        return app(DelegationService::class);
    }

    private function user(): User
    {
        return User::factory()->create();
    }

    private function makeAgent(User $owner, string $name): Agent
    {
        $this->seedOperationCatalog();

        return app(AgentService::class)->create($owner->id, "name: {$name}\ninstructions: I am {$name}.");
    }

    private function conversation(User $owner, ?Agent $agent = null): Conversation
    {
        return Conversation::factory()->create([
            'user_id' => $owner->id,
            'title' => 'Already titled',
            'agent_id' => $agent?->id,
            'agent_version_id' => $agent?->current_version_id,
        ]);
    }

    /**
     * Seeds one agent_delegations row directly, filling every NOT NULL
     * column the migration requires with a sensible default so a test
     * only has to override the fields it actually cares about (mirrors
     * DelegationServiceCycleRegressionTest's own seedDelegationRow()).
     */
    private function seedDelegationRow(array $overrides = []): Delegation
    {
        return Delegation::create(array_merge([
            'parent_conversation_id' => (string) Str::uuid(),
            'parent_agent_id' => (string) Str::uuid(),
            'helper_agent_id' => (string) Str::uuid(),
            'helper_conversation_id' => (string) Str::uuid(),
            'owner_user_id' => (string) Str::uuid(),
            'task' => 'Do a thing.',
            'depth' => 1,
            'status' => 'in_progress',
            'started_at' => now(),
            'origin' => 'delegate_to_helper',
        ], $overrides));
    }

    /**
     * Invokes the still-private DelegationService::resolveAndValidate()
     * directly -- every scenario below is decided entirely inside this
     * method (eligibility, depth, ancestor-identity, and -- once T023
     * lands -- chain-time), never reaching the nested run() call.
     */
    private function resolveAndValidate(Conversation $parentConversation, string $helperAgentId): array
    {
        $method = new \ReflectionMethod(DelegationService::class, 'resolveAndValidate');
        $method->setAccessible(true);

        return $method->invoke($this->delegationService(), $parentConversation, $helperAgentId);
    }

    // =================================================================
    // T017 -- a chain whose cumulative elapsed time exceeds
    // max_chain_seconds is refused on its next hop, and no Delegation row
    // is created for the refused attempt.
    // =================================================================

    #[Test]
    public function a_chain_whose_cumulative_elapsed_time_exceeds_max_chain_seconds_is_refused_on_its_next_hop(): void
    {
        config(['llm-client.delegation.max_chain_seconds' => 5]);

        $owner = $this->user();
        $agentA = $this->makeAgent($owner, 'chain-time-a');
        $agentB = $this->makeAgent($owner, 'chain-time-b');
        $agentC = $this->makeAgent($owner, 'chain-time-c');

        // B must be eligible to delegate to C -- eligibility is checked
        // BEFORE the chain-time bound, so this fixture must clear it
        // cleanly for the chain-time refusal to be the one and only thing
        // that can fire.
        AgentHelperAssignment::create([
            'parent_agent_id' => $agentB->id,
            'helper_agent_id' => $agentC->id,
            'owner_user_id' => $owner->id,
        ]);

        $conv0 = $this->conversation($owner, $agentA);
        $conv1 = $this->conversation($owner, $agentB);

        // The chain's one and only (ROOT) hop began well past the
        // configured max_chain_seconds (5) ago -- 30 seconds of cumulative
        // elapsed time on a chain only 1 hop deep, so this can only be
        // caught by the chain-time bound, never the depth bound (default
        // max_chain_depth is 5, and the attempted next hop would only
        // reach depth 2).
        $this->seedDelegationRow([
            'parent_conversation_id' => $conv0->id,
            'parent_agent_id' => $agentA->id,
            'helper_conversation_id' => $conv1->id,
            'owner_user_id' => $owner->id,
            'depth' => 1,
            'started_at' => now()->subSeconds(30),
        ]);

        $before = Delegation::count();

        $result = $this->resolveAndValidate($conv1, $agentC->id);

        $this->assertSame(
            'delegation_chain_time_exceeded',
            $result['error'] ?? null,
            'a chain whose cumulative elapsed time (via chainRootStartedAt()) exceeds max_chain_seconds must be refused on its next hop',
        );
        $this->assertArrayHasKey('message', $result, 'the refusal must carry a human-readable message, matching every other resolveAndValidate() refusal shape');
        $this->assertIsString($result['message'] ?? null);
        $this->assertNotSame('', $result['message'] ?? '');

        $this->assertSame(
            $before,
            Delegation::count(),
            'a chain-time-refused attempt must never write a new Delegation row -- identical "refused before it executes" contract the existing depth/cycle refusals already honor',
        );
    }

    // =================================================================
    // T019 -- when both max_chain_depth and max_chain_seconds are
    // exceeded simultaneously, the existing, higher-priority depth
    // refusal fires, never the chain-time refusal (data-model.md
    // "Validation rules": depth checked before chain-time). This
    // invariant already holds today (the depth check is the only one of
    // the two that exists yet) and must continue to hold once T023 adds
    // the chain-time check alongside it -- a check-order regression
    // guard, in the same spirit as Phase 3's US1 regression tests, rather
    // than a scenario requiring new production code to pass for the
    // first time.
    // =================================================================

    #[Test]
    public function when_both_depth_and_chain_time_are_exceeded_simultaneously_the_existing_depth_refusal_wins(): void
    {
        config(['llm-client.delegation.max_chain_depth' => 1]);
        config(['llm-client.delegation.max_chain_seconds' => 5]);

        $owner = $this->user();
        $agentA = $this->makeAgent($owner, 'chain-time-order-a');
        $agentB = $this->makeAgent($owner, 'chain-time-order-b');
        $agentC = $this->makeAgent($owner, 'chain-time-order-c');

        AgentHelperAssignment::create([
            'parent_agent_id' => $agentB->id,
            'helper_agent_id' => $agentC->id,
            'owner_user_id' => $owner->id,
        ]);

        $conv0 = $this->conversation($owner, $agentA);
        $conv1 = $this->conversation($owner, $agentB);

        // Depth is already AT the deliberately low ceiling of 1, so one
        // further hop (to depth 2) exceeds it -- AND the same row's
        // started_at is old enough to also exceed max_chain_seconds (5).
        // Both bounds are breached by this one attempt.
        $this->seedDelegationRow([
            'parent_conversation_id' => $conv0->id,
            'parent_agent_id' => $agentA->id,
            'helper_conversation_id' => $conv1->id,
            'owner_user_id' => $owner->id,
            'depth' => 1,
            'started_at' => now()->subSeconds(30),
        ]);

        $before = Delegation::count();

        $result = $this->resolveAndValidate($conv1, $agentC->id);

        $this->assertSame(
            'delegation_depth_exceeded',
            $result['error'] ?? null,
            'depth is checked before chain-time -- the existing, higher-priority depth refusal must fire even though this same attempt also exceeds max_chain_seconds',
        );
        $this->assertNotSame(
            'delegation_chain_time_exceeded',
            $result['error'] ?? null,
            'only one refusal may ever fire for a given attempt -- the chain-time check must never win a race it is ordered after',
        );
        $this->assertSame($before, Delegation::count(), 'no new Delegation row may be written for a depth-refused attempt');
    }

    // =================================================================
    // T045 -- FR-010/SC-007: cycle detection and the new chain-time check
    // together must add work proportional only to the chain's OWN depth,
    // never to installation-wide state, so an ordinary delegation that
    // completes normally sees no additional round trips beyond a small,
    // fixed-per-hop number. This is a concrete proxy for that promise:
    // build a real chain at a known depth, run the exact same
    // resolveAndValidate() call a normal (non-refused) delegation attempt
    // makes, and assert the query count is bounded by a small constant
    // multiple of the chain's depth -- never by the total size of
    // agent_delegations or any other installation-wide table.
    //
    // This test is EXPECTED TO ALREADY PASS against current (T023/T024
    // already-landed) behavior: chainRootStartedAt() (Phase 2) and
    // ancestorAgentIds() (109-agent-as-capability) were already each a
    // single O(depth) backward walk before this feature touched either,
    // and T023/T024 only added a comparison against the value
    // chainRootStartedAt() already returns -- no new query was
    // introduced by this feature's own chain-time check itself. Matching
    // this feature's own established precedent (Phase 1-6 progress log:
    // "already green, required coverage"), this test's purpose is
    // LOCKING IN the property with a regression test that would catch a
    // future change (e.g. a naive per-hop eager-load, or a query keyed
    // off total agent_delegations size) rather than proving today's code
    // is currently broken.
    // =================================================================

    #[Test]
    public function resolve_and_validate_issues_no_more_queries_than_a_small_constant_multiple_of_the_chains_own_depth(): void
    {
        $owner = $this->user();
        $agentA = $this->makeAgent($owner, 'query-cost-a');
        $agentB = $this->makeAgent($owner, 'query-cost-b');
        $agentC = $this->makeAgent($owner, 'query-cost-c');
        $agentD = $this->makeAgent($owner, 'query-cost-d');
        $agentE = $this->makeAgent($owner, 'query-cost-e');

        // D must be eligible to delegate to E -- eligibility is checked
        // BEFORE any of the O(depth) walks this test measures, so this
        // fixture must clear it cleanly and let the attempt succeed
        // (SC-007 is specifically about chains that "complete normally").
        AgentHelperAssignment::create([
            'parent_agent_id' => $agentD->id,
            'helper_agent_id' => $agentE->id,
            'owner_user_id' => $owner->id,
        ]);

        $conv0 = $this->conversation($owner, $agentA);
        $conv1 = $this->conversation($owner, $agentB);
        $conv2 = $this->conversation($owner, $agentC);
        $conv3 = $this->conversation($owner, $agentD);

        // A real chain, 3 hops deep: A -> B -> C -> D. The attempted next
        // hop (D -> E, below) would be the chain's 4th hop -- well under
        // the default max_chain_depth (5) and, with a fresh started_at
        // below, well under the default max_chain_seconds (900) too, so
        // the attempt is expected to SUCCEED, exercising every one of
        // resolveAndValidate()'s O(depth) walks (chainRootStartedAt() and
        // ancestorAgentIds()) all the way back to the chain's root.
        $chainDepth = 3;

        $this->seedDelegationRow([
            'parent_conversation_id' => $conv0->id,
            'parent_agent_id' => $agentA->id,
            'helper_conversation_id' => $conv1->id,
            'owner_user_id' => $owner->id,
            'depth' => 1,
            'started_at' => now()->subSeconds(3),
        ]);
        $this->seedDelegationRow([
            'parent_conversation_id' => $conv1->id,
            'parent_agent_id' => $agentB->id,
            'helper_conversation_id' => $conv2->id,
            'owner_user_id' => $owner->id,
            'depth' => 2,
            'started_at' => now()->subSeconds(2),
        ]);
        $this->seedDelegationRow([
            'parent_conversation_id' => $conv2->id,
            'parent_agent_id' => $agentC->id,
            'helper_conversation_id' => $conv3->id,
            'owner_user_id' => $owner->id,
            'depth' => $chainDepth,
            'started_at' => now()->subSeconds(1),
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $result = $this->resolveAndValidate($conv3, $agentE->id);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertArrayNotHasKey(
            'error',
            $result,
            'fixture sanity: this attempt must succeed (not be refused) so every O(depth) walk actually runs all the way to the chain root -- got: '.($result['error'] ?? 'n/a'),
        );

        // A generous, still-meaningful ceiling: roughly 2 queries per
        // level of depth (one for chainRootStartedAt()'s walk, one for
        // ancestorAgentIds()'s walk) plus a small fixed constant for the
        // eligibility/agent-lookup/depth-lookup queries that run exactly
        // once regardless of depth. The point is proving the cost SCALES
        // WITH DEPTH, not with the total size of agent_delegations or any
        // other installation-wide state -- a query count anywhere near
        // this bound is fine; a query count that grows with unrelated
        // rows in the table would blow well past it.
        $ceiling = (2 * $chainDepth) + 6;

        $this->assertLessThanOrEqual(
            $ceiling,
            count($log),
            "resolveAndValidate() issued ".count($log)." queries for a chain {$chainDepth} hops deep -- expected at most {$ceiling} (a small constant multiple of depth, per FR-010/SC-007), not a cost proportional to installation-wide state. Queries: "
            .json_encode(array_map(fn ($entry) => $entry['query'], $log)),
        );
    }
}
