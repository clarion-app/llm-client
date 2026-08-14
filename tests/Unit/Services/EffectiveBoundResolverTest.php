<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentHelperAssignment;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\EffectiveBoundResolver;
use ClarionApp\LlmClient\Services\GitDefinitionFileReader;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for the not-yet-built EffectiveBoundResolver::check()
 * (100-subagent-tool-restrictions, Phase 4/US2, tasks.md T018,
 * data-model.md §3, research.md D2/D8).
 *
 * check() walks `agent_delegations` upward from the acting conversation
 * (nearest ancestor first): the row where helper_conversation_id equals
 * the conversation's own id names the next ancestor
 * (parent_agent_id) and the next hop to repeat the lookup from
 * (parent_conversation_id). The first ancestor whose CURRENT
 * permittedOperationIds() excludes the operation is reported; if the
 * walk exhausts the chain (or the conversation was never part of one at
 * all) without finding a violation, the operation is allowed.
 *
 * Every fixture below seeds `agent_delegations` rows directly at the
 * model layer, bypassing DelegationService::delegate() entirely — this
 * file is testing the READ side (the resolver's own walk) in isolation,
 * mirroring AgentHelperQueryTest.php's own established convention of
 * seeding structural fixtures directly rather than driving them through
 * the write path they are not under test here.
 *
 * Written first, confirmed RED: EffectiveBoundResolver does not exist
 * yet — every case below currently fails with "Class
 * \"ClarionApp\LlmClient\Services\EffectiveBoundResolver\" not found".
 */
class EffectiveBoundResolverTest extends TestCase
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

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function resolver(): EffectiveBoundResolver
    {
        return app(EffectiveBoundResolver::class);
    }

    private function agentService(): AgentService
    {
        return app(AgentService::class);
    }

    private function user(): User
    {
        return User::factory()->create();
    }

    /**
     * Seeds both of ApiManager's live-catalog seams — required before
     * any *valid* AgentDefinitionParser::parse() call
     * (AgentSummaryQueryTest's own established convention, reused by
     * every sibling test file in this feature).
     */
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

    /**
     * Two disjoint, single-operation groups — X and Y in the quickstart
     * scenarios' own shorthand — mirroring AgentHelperQueryTest.php's
     * own seedXyOperationCatalog().
     */
    private function seedXyOperationCatalog(): void
    {
        $this->seedOperationCatalog([
            'x.operation' => ['path' => '/api/x', 'method' => 'get', 'summary' => 'X operation'],
            'y.operation' => ['path' => '/api/y', 'method' => 'get', 'summary' => 'Y operation'],
        ]);
    }

    /**
     * Builds an agent permitted exactly the given list of operation ids
     * — mirrors AgentHelperQueryTest.php's own agentPermitting().
     *
     * @param list<string> $operationIds
     */
    private function agentPermitting(User $owner, string $name, array $operationIds): Agent
    {
        $allowLines = implode("\n", array_map(fn (string $id) => "    - {$id}", $operationIds));

        $yaml = <<<YAML
name: {$name}
instructions: Assist customers.
tools:
  allow:
{$allowLines}
YAML;

        return $this->agentService()->create($owner->id, $yaml);
    }

    private function conversation(User $owner): Conversation
    {
        return Conversation::factory()->create([
            'user_id' => $owner->id,
            'title' => 'Already titled',
        ]);
    }

    /**
     * Seeds one agent_delegations row directly, filling every NOT NULL
     * column the migration requires with a sensible default so a test
     * only has to override the fields it actually cares about.
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
        ], $overrides));
    }

    // ---------------------------------------------------------------
    // check() — the non-delegated common case
    // ---------------------------------------------------------------

    #[Test]
    public function a_conversation_with_no_delegation_chain_at_all_is_allowed_immediately(): void
    {
        $owner = $this->user();
        $this->seedXyOperationCatalog();
        $conversation = $this->conversation($owner);

        $result = $this->resolver()->check($conversation, 'x.operation');

        $this->assertTrue($result['allowed'], 'a conversation never named as a helper_conversation_id in agent_delegations must be allowed immediately — the overwhelmingly common, non-delegated case');
        $this->assertNull($result['blocking_agent_id']);
        $this->assertNull($result['blocking_agent_name']);
        $this->assertNull($result['levels_up']);
    }

    #[Test]
    public function the_non_delegated_short_circuit_costs_exactly_one_query_and_fetches_no_agent_or_version_row(): void
    {
        $owner = $this->user();
        $this->seedXyOperationCatalog();
        $conversation = $this->conversation($owner);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $result = $this->resolver()->check($conversation, 'x.operation');

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertTrue($result['allowed'], 'fixture sanity: the non-delegated case must still be allowed');
        $this->assertCount(
            1,
            $log,
            'the non-delegated common case must cost exactly one query — the agent_delegations.helper_conversation_id miss lookup (research.md D8, quickstart scenario 9)',
        );
        $this->assertStringContainsString(
            'agent_delegations',
            $log[0]['query'],
            'the one query logged must be the agent_delegations lookup itself',
        );
        foreach ($log as $entry) {
            $this->assertStringNotContainsString(
                'agent_versions',
                $entry['query'],
                'no AgentVersion row may be fetched for the non-delegated short-circuit',
            );
        }
    }

    // ---------------------------------------------------------------
    // check() — single-hop chain
    // ---------------------------------------------------------------

    #[Test]
    public function a_single_hop_chain_where_the_ancestor_excludes_the_operation_is_blocked_naming_it_at_levels_up_one(): void
    {
        $owner = $this->user();
        $this->seedXyOperationCatalog();
        $ancestor = $this->agentPermitting($owner, 'ebr-single-hop-excludes', ['x.operation']);
        $helperConversation = $this->conversation($owner);

        $this->seedDelegationRow([
            'parent_conversation_id' => $this->conversation($owner)->id,
            'parent_agent_id' => $ancestor->id,
            'helper_conversation_id' => $helperConversation->id,
            'owner_user_id' => $owner->id,
        ]);

        $result = $this->resolver()->check($helperConversation, 'y.operation');

        $this->assertFalse(
            $result['allowed'],
            'the single ancestor in this chain permits only x.operation, so y.operation must be blocked',
        );
        $this->assertSame($ancestor->id, $result['blocking_agent_id']);
        $this->assertSame('ebr-single-hop-excludes', $result['blocking_agent_name']);
        $this->assertSame(1, $result['levels_up'], 'the immediate (and only) ancestor in the chain is exactly one level up');
    }

    #[Test]
    public function the_identical_single_hop_case_where_the_ancestor_permits_the_operation_is_allowed(): void
    {
        $owner = $this->user();
        $this->seedXyOperationCatalog();
        $ancestor = $this->agentPermitting($owner, 'ebr-single-hop-permits', ['x.operation', 'y.operation']);
        $helperConversation = $this->conversation($owner);

        $this->seedDelegationRow([
            'parent_conversation_id' => $this->conversation($owner)->id,
            'parent_agent_id' => $ancestor->id,
            'helper_conversation_id' => $helperConversation->id,
            'owner_user_id' => $owner->id,
        ]);

        $result = $this->resolver()->check($helperConversation, 'y.operation');

        $this->assertTrue(
            $result['allowed'],
            'the same single ancestor now permits y.operation too, so the attempt must be allowed',
        );
        $this->assertNull($result['blocking_agent_id']);
        $this->assertNull($result['blocking_agent_name']);
        $this->assertNull($result['levels_up']);
    }

    // ---------------------------------------------------------------
    // check() — defensive cycle/revisit-safety (research.md D2's "never
    // trust data-level acyclicity blindly," mirroring T007's own
    // structuralEffectiveBound() cycle-safety guard). agent_delegations
    // is written strictly forward by DelegationService::delegate() and
    // can never form a real cycle through the actual API — this fixture
    // seeds one directly at the model layer regardless, to prove the
    // walk terminates rather than looping forever if it somehow ever
    // did. Both agents in the seeded cycle permit the operation under
    // test, so a clean, non-hanging termination is expected to report
    // allowed: true (mirroring the depth-cap's own "reached the top of
    // what can safely be walked" posture) rather than surface any
    // violation — nothing along the traversed chain was ever skipped
    // that would have mattered.
    // ---------------------------------------------------------------

    #[Test]
    public function a_pre_existing_cycle_in_agent_delegations_terminates_the_walk_rather_than_recursing_forever(): void
    {
        $owner = $this->user();
        $this->seedXyOperationCatalog();
        $agentX = $this->agentPermitting($owner, 'ebr-cycle-x', ['x.operation']);
        $agentY = $this->agentPermitting($owner, 'ebr-cycle-y', ['x.operation']);

        $convHelper = $this->conversation($owner);
        $convB = $this->conversation($owner);

        // convHelper -> (ancestor agentX) -> convB -> (ancestor agentY) -> convHelper: a
        // closed 2-cycle, seeded directly, bypassing DelegationService's
        // own strictly-forward write path entirely.
        $this->seedDelegationRow([
            'parent_conversation_id' => $convB->id,
            'parent_agent_id' => $agentX->id,
            'helper_conversation_id' => $convHelper->id,
            'owner_user_id' => $owner->id,
        ]);
        $this->seedDelegationRow([
            'parent_conversation_id' => $convHelper->id,
            'parent_agent_id' => $agentY->id,
            'helper_conversation_id' => $convB->id,
            'owner_user_id' => $owner->id,
        ]);

        $result = $this->resolver()->check($convHelper, 'x.operation');

        $this->assertTrue(
            $result['allowed'],
            'a defensive revisit guard must terminate the walk cleanly (mutation-testing checklist row 2\'s sibling for the runtime side) — every distinct ancestor actually reachable in this cycle (both of which permit x.operation) has already been checked once by the time a revisit is detected',
        );
    }

    // ---------------------------------------------------------------
    // check() — a helper assigned to TWO different parents is bounded
    // only by the specific chain that delegated the task, not by a
    // combined/union view of every parent it is structurally assigned
    // to (100-subagent-tool-restrictions, Phase 7/US5, tasks.md T034,
    // spec.md Edge Case 2, mutation-testing checklist row 6).
    //
    // agent_helper_assignments (the full structural graph, Phase 3's own
    // concern) and agent_delegations (the actual chain a piece of work
    // was routed through, this class's own concern) must never be
    // conflated: a helper can be structurally assigned to a parent that
    // never delegated anything to it for this particular attempt, and
    // that parent's own (possibly tighter) bound must have no bearing on
    // this check.
    // ---------------------------------------------------------------

    #[Test]
    public function a_helper_assigned_to_two_different_parents_is_bounded_only_by_the_parent_that_actually_delegated_this_task(): void
    {
        $owner = $this->user();
        $this->seedXyOperationCatalog();

        $tightParent = $this->agentPermitting($owner, 'ebr-two-parents-tight', ['x.operation']);
        $looseParent = $this->agentPermitting($owner, 'ebr-two-parents-loose', ['x.operation', 'y.operation']);
        $helper = $this->agentPermitting($owner, 'ebr-two-parents-helper', ['x.operation', 'y.operation']);

        // Structural assignment (agent_helper_assignments) to BOTH
        // parents -- the full structural graph a mutation reading the
        // wrong table (row 6) would consult instead of the actual
        // delegation chain.
        AgentHelperAssignment::create([
            'parent_agent_id' => $tightParent->id,
            'helper_agent_id' => $helper->id,
            'owner_user_id' => $owner->id,
        ]);
        AgentHelperAssignment::create([
            'parent_agent_id' => $looseParent->id,
            'helper_agent_id' => $helper->id,
            'owner_user_id' => $owner->id,
        ]);

        $helperConversation = $this->conversation($owner);

        // The ACTUAL delegation chain (agent_delegations) routes this
        // specific piece of work through the LOOSE parent only -- the
        // tight parent never delegated anything here, despite the
        // structural assignment above.
        $this->seedDelegationRow([
            'parent_conversation_id' => $this->conversation($owner)->id,
            'parent_agent_id' => $looseParent->id,
            'helper_conversation_id' => $helperConversation->id,
            'owner_user_id' => $owner->id,
        ]);

        $result = $this->resolver()->check($helperConversation, 'y.operation');

        $this->assertTrue(
            $result['allowed'],
            'the tight parent (which does not permit y.operation) is structurally assigned to this helper too, but never delegated this specific task -- agent_delegations (the actual chain), not agent_helper_assignments (the full structural graph), must govern this attempt (Edge Case 2)',
        );
        $this->assertNull($result['blocking_agent_id']);
        $this->assertNull($result['blocking_agent_name']);
        $this->assertNull($result['levels_up']);
    }
}
