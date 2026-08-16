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
 * 110-delegation-deadlock-timeout, Phase 3 (US1, tasks.md T010-T013).
 *
 * REGRESSION coverage only -- no new production code accompanies this
 * file. Its four tests exercise `DelegationService::resolveAndValidate()`'s
 * pre-existing direct/indirect cycle backstop (`agent_already_active_in_chain`,
 * 109-agent-as-capability) and pre-existing depth ceiling
 * (`delegation_depth_exceeded`, 098-delegation-protocol), confirming both
 * are completely unaffected by this feature's own (as yet unimplemented)
 * chain-time bound, and that cycle detection stays correctly scoped to a
 * single chain's own ancestor walk rather than any global "is this agent
 * busy" notion (spec.md Edge Cases). Every test below is expected to PASS
 * against the current, unmodified `DelegationService` -- there is no
 * red phase here.
 *
 * Fixture and invocation style mirrors
 * EffectiveBoundResolverIdentityGuardTest.php's own established
 * convention exactly: `Delegation` rows are seeded DIRECTLY at the model
 * layer via seedDelegationRow() (never through delegate()/delegateBatch(),
 * which this file never calls), and the still-private
 * `resolveAndValidate()` is invoked directly via reflection -- the
 * refusal/success cases exercised here never need to reach the nested
 * `AgentLoopService::run()` call at all, so no scripted `LlmProvider`
 * double is required.
 */
class DelegationServiceCycleRegressionTest extends TestCase
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
    // Operation-catalog scaffolding (DelegationServiceTest.php's own
    // established precedent -- kept even though none of these fixtures
    // build agents with a `tools:` section, matching this package's
    // convention of always seeding an empty catalog rather than leaving
    // it uninitialized).
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
    // Fixture helpers (mirrors EffectiveBoundResolverIdentityGuardTest's
    // own fixture helpers)
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
     * only has to override the fields it actually cares about. Defaults
     * to `status: 'in_progress'` -- a LIVE chain, matching every scenario
     * this file exercises (mirrors EffectiveBoundResolverIdentityGuardTest's
     * own seedDelegationRow()).
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
     * method (eligibility, depth, ancestor-identity), never reaching the
     * nested run() call, so this avoids needing a scripted LlmProvider
     * for what is genuinely a Unit-level concern (mirrors
     * EffectiveBoundResolverIdentityGuardTest's own identical helper).
     */
    private function resolveAndValidate(Conversation $parentConversation, string $helperAgentId): array
    {
        $method = new \ReflectionMethod(DelegationService::class, 'resolveAndValidate');
        $method->setAccessible(true);

        return $method->invoke($this->delegationService(), $parentConversation, $helperAgentId);
    }

    // =================================================================
    // T010 -- direct self-delegation within a live chain
    // =================================================================

    #[Test]
    public function a_participant_delegating_directly_to_itself_within_a_live_chain_is_refused(): void
    {
        $owner = $this->user();
        $agentA = $this->makeAgent($owner, 'cycle-regress-direct-self-a');

        // A self-assignment, inserted directly at the model layer.
        // AgentHelperService::assign() itself already refuses "An agent
        // cannot be assigned as its own helper" at config time (spec.md's
        // own Non-goals names this a separate, existing check) -- but
        // this feature's runtime cycle backstop must independently refuse
        // a LIVE self-delegation attempt regardless of how the underlying
        // eligibility row came to exist, exactly like
        // EffectiveBoundResolverIdentityGuardTest's own "data anomaly"
        // fixtures bypass AgentHelperService::assign()'s config-time DFS.
        AgentHelperAssignment::create([
            'parent_agent_id' => $agentA->id,
            'helper_agent_id' => $agentA->id,
            'owner_user_id' => $owner->id,
        ]);

        $conv0 = $this->conversation($owner, $agentA);
        $conv1 = $this->conversation($owner, $agentA);

        // A already delegated to itself once -- $conv1 is that hop's own
        // helper conversation, now live (in_progress) and attempting to
        // delegate to A again.
        $this->seedDelegationRow([
            'parent_conversation_id' => $conv0->id,
            'parent_agent_id' => $agentA->id,
            'helper_conversation_id' => $conv1->id,
            'owner_user_id' => $owner->id,
            'depth' => 1,
        ]);

        $before = Delegation::count();

        $result = $this->resolveAndValidate($conv1, $agentA->id);

        $this->assertSame(
            'agent_already_active_in_chain',
            $result['error'] ?? null,
            'agent A, already active earlier in this live chain, must be refused when it attempts to delegate to itself again',
        );
        $this->assertSame(
            $before,
            Delegation::count(),
            'a refused self-delegation must never write a new Delegation row',
        );
    }

    // =================================================================
    // T011 -- indirect cycle A -> B -> C -> A
    // =================================================================

    #[Test]
    public function an_indirect_three_hop_cycle_is_refused_well_under_the_configured_max_chain_depth(): void
    {
        // Deliberately comfortably larger than 3, so a refusal here can
        // only be the identity-based cycle backstop, never a
        // depth-exhaustion refusal wearing the wrong error code.
        config(['llm-client.delegation.max_chain_depth' => 10]);

        $owner = $this->user();
        $agentA = $this->makeAgent($owner, 'cycle-regress-indirect-a');
        $agentB = $this->makeAgent($owner, 'cycle-regress-indirect-b');
        $agentC = $this->makeAgent($owner, 'cycle-regress-indirect-c');

        // C must be eligible to (re-)invoke A via delegate_to_helper.
        AgentHelperAssignment::create([
            'parent_agent_id' => $agentC->id,
            'helper_agent_id' => $agentA->id,
            'owner_user_id' => $owner->id,
        ]);

        $conv1 = $this->conversation($owner, $agentA);
        $conv2 = $this->conversation($owner, $agentB);
        $conv3 = $this->conversation($owner, $agentC);

        // conv1(A) -> conv2(B)
        $this->seedDelegationRow([
            'parent_conversation_id' => $conv1->id,
            'parent_agent_id' => $agentA->id,
            'helper_conversation_id' => $conv2->id,
            'owner_user_id' => $owner->id,
            'depth' => 1,
        ]);
        // conv2(B) -> conv3(C)
        $this->seedDelegationRow([
            'parent_conversation_id' => $conv2->id,
            'parent_agent_id' => $agentB->id,
            'helper_conversation_id' => $conv3->id,
            'owner_user_id' => $owner->id,
            'depth' => 2,
        ]);

        $before = Delegation::count();

        // Now C attempts to (re-)invoke A -- completing A -> B -> C -> A.
        $result = $this->resolveAndValidate($conv3, $agentA->id);

        $this->assertSame(
            'agent_already_active_in_chain',
            $result['error'] ?? null,
            'C attempting to delegate back to A, already active earlier in this same chain, must be refused as a cycle',
        );
        $this->assertNotSame('delegation_depth_exceeded', $result['error'] ?? null);

        // Confirm this genuinely was NOT a depth refusal in disguise: the
        // resulting depth would only be 3, well under the configured
        // max_chain_depth of 10.
        $enclosing = Delegation::where('helper_conversation_id', $conv3->id)->latest('started_at')->first();
        $this->assertNotNull($enclosing, 'fixture sanity: conv3 must have an enclosing delegation to compute depth from');
        $this->assertLessThan(
            10,
            $enclosing->depth + 1,
            'fixture sanity: the would-be depth (3) must be well under the configured ceiling (10), proving the refusal above is identity-based, not depth-based',
        );

        $this->assertSame($before, Delegation::count(), 'a refused indirect-cycle delegation must never write a new Delegation row');
    }

    // =================================================================
    // T012 -- a chain at max_chain_depth refuses one further delegation
    // (quickstart.md Scenario 4)
    // =================================================================

    #[Test]
    public function a_chain_at_max_chain_depth_refuses_one_further_delegation_attempt(): void
    {
        config(['llm-client.delegation.max_chain_depth' => 2]);

        $owner = $this->user();
        $agentA = $this->makeAgent($owner, 'cycle-regress-depth-a');
        $agentB = $this->makeAgent($owner, 'cycle-regress-depth-b');
        $agentC = $this->makeAgent($owner, 'cycle-regress-depth-c');
        $agentD = $this->makeAgent($owner, 'cycle-regress-depth-d');

        // C must be eligible to delegate to D -- a brand-new agent never
        // seen earlier in this chain, so nothing here can be mistaken
        // for the identity-based cycle backstop.
        AgentHelperAssignment::create([
            'parent_agent_id' => $agentC->id,
            'helper_agent_id' => $agentD->id,
            'owner_user_id' => $owner->id,
        ]);

        $conv0 = $this->conversation($owner, $agentA);
        $conv1 = $this->conversation($owner, $agentB);
        $conv2 = $this->conversation($owner, $agentC);

        // conv0(A) -> conv1(B): depth 1.
        $this->seedDelegationRow([
            'parent_conversation_id' => $conv0->id,
            'parent_agent_id' => $agentA->id,
            'helper_conversation_id' => $conv1->id,
            'owner_user_id' => $owner->id,
            'depth' => 1,
        ]);
        // conv1(B) -> conv2(C): depth 2, exactly at the configured limit.
        $this->seedDelegationRow([
            'parent_conversation_id' => $conv1->id,
            'parent_agent_id' => $agentB->id,
            'helper_conversation_id' => $conv2->id,
            'owner_user_id' => $owner->id,
            'depth' => 2,
        ]);

        $before = Delegation::count();

        // C attempts one further hop, to D -- computed depth 3, one past
        // the configured ceiling of 2.
        $result = $this->resolveAndValidate($conv2, $agentD->id);

        $this->assertSame(
            'delegation_depth_exceeded',
            $result['error'] ?? null,
            'a chain already 2 hops deep, at the configured max_chain_depth of 2, must refuse one further delegation',
        );
        $this->assertSame(
            $before,
            Delegation::count(),
            'no new Delegation row may be written for a depth-refused attempt',
        );
    }

    // =================================================================
    // T013 -- two unrelated, concurrently-active chains reusing the same
    // helper agent are not mistaken for a cycle (spec.md Edge Cases)
    // =================================================================

    #[Test]
    public function two_unrelated_concurrently_active_chains_reusing_the_same_helper_agent_are_not_mistaken_for_a_cycle(): void
    {
        $owner = $this->user();
        $agentA = $this->makeAgent($owner, 'cycle-regress-unrelated-a');
        $agentB = $this->makeAgent($owner, 'cycle-regress-unrelated-b-shared-helper');
        $agentX = $this->makeAgent($owner, 'cycle-regress-unrelated-x');

        AgentHelperAssignment::create([
            'parent_agent_id' => $agentA->id,
            'helper_agent_id' => $agentB->id,
            'owner_user_id' => $owner->id,
        ]);
        AgentHelperAssignment::create([
            'parent_agent_id' => $agentX->id,
            'helper_agent_id' => $agentB->id,
            'owner_user_id' => $owner->id,
        ]);

        $convA = $this->conversation($owner, $agentA);
        $convB1 = $this->conversation($owner, $agentB);
        $convX = $this->conversation($owner, $agentX);

        // Chain 1: A -> B, already live (in_progress) and mid-task -- B
        // is "concurrently active" by the time chain 2 attempts its own,
        // completely independent delegation to the same agent below.
        $chain1Row = $this->seedDelegationRow([
            'parent_conversation_id' => $convA->id,
            'parent_agent_id' => $agentA->id,
            'helper_conversation_id' => $convB1->id,
            'owner_user_id' => $owner->id,
            'depth' => 1,
        ]);

        // Chain 2: X, an entirely separate root with no ancestry
        // connecting it to chain 1 at all, independently attempts to
        // delegate to the SAME agent B.
        $resultChain2 = $this->resolveAndValidate($convX, $agentB->id);

        $this->assertArrayNotHasKey(
            'error',
            $resultChain2,
            'chain 2 delegating to B, concurrently active in a completely unrelated chain 1, must not be mistaken for a cycle -- cycle detection is scoped to a single chain\'s own ancestor walk, not global agent-usage tracking',
        );
        $this->assertSame($agentB->id, $resultChain2['helperAgent']->id ?? null);
        $this->assertSame(
            1,
            $resultChain2['depth'] ?? null,
            'chain 2 must compute its own, independent depth (1) -- unaffected by chain 1\'s own depth',
        );

        // And chain 1's own live row must be entirely untouched by chain
        // 2's independent attempt.
        $this->assertSame(
            'in_progress',
            $chain1Row->fresh()->status,
            'chain 1\'s own live delegation must be completely unaffected by an unrelated chain 2 reusing the same helper agent',
        );
    }
}
