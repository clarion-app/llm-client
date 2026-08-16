<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentHelperAssignment;
use ClarionApp\LlmClient\Models\CapabilityOffering;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\DelegationService;
use ClarionApp\LlmClient\Services\EffectiveBoundResolver;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 109-agent-as-capability, Phase 5 (US3), tasks.md T034 (quickstart
 * scenario 5, US3 AC2's runtime-backstop half, mutation-checklist row 6).
 *
 * The one genuinely new *runtime* production seam this feature adds
 * (data-model.md §7): a data anomaly or race that bypasses the
 * config-time union-graph DFS (AgentHelperQuery::wouldCreateCycle()/
 * wouldOfferingCreateCycle(), Phase 2/Foundational) must still be caught
 * at the moment a live call chain would actually re-invoke an agent
 * already active earlier in that same chain -- regardless of whether
 * every hop so far came from delegate_to_helper or a capability
 * offering, and regardless of how far the chain is from
 * delegation.max_chain_depth (identity-based, not depth-based).
 *
 * Every fixture below seeds `agent_delegations`/`agent_helper_assignments`/
 * `agent_capability_offerings` rows DIRECTLY at the model layer --
 * bypassing CapabilityOfferingService::offer()/AgentHelperService::assign()
 * and their own config-time DFS entirely, simulating exactly the kind of
 * data anomaly or race quickstart scenario 5 names -- mirroring
 * EffectiveBoundResolverTest.php's own established convention of seeding
 * structural fixtures directly rather than driving them through a write
 * path not under test here.
 *
 * Two layers are exercised, per data-model.md §7's own two CHANGED
 * entries: EffectiveBoundResolver::check() (the ancestor walk an inner
 * execute_operation attempt is bound by) and DelegationService's own
 * full ancestor walk inside resolveAndValidate()/invokeAsCapability() (the
 * walk performed BEFORE a new hop is ever created). Both are written
 * FIRST here and confirmed RED -- as of this file's own creation, the
 * $visited set in EffectiveBoundResolver::check() is keyed on conversation
 * id only (not agent id), and resolveAndValidate()/invokeAsCapability()
 * perform no ancestor-identity walk at all -- before T035/T036 land.
 */
class EffectiveBoundResolverIdentityGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('agent_delegations')->delete();
        DB::table('agent_capability_offerings')->delete();
        DB::table('agent_helper_assignments')->delete();
        DB::table('conversations')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers (mirrors EffectiveBoundResolverTest.php's own fixture
    // helpers)
    // ---------------------------------------------------------------

    private function resolver(): EffectiveBoundResolver
    {
        return app(EffectiveBoundResolver::class);
    }

    private function delegationService(): DelegationService
    {
        return app(DelegationService::class);
    }

    private function agentService(): AgentService
    {
        return app(AgentService::class);
    }

    private function user(): User
    {
        return User::factory()->create();
    }

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

    private function seedXOperationCatalog(): void
    {
        $this->seedOperationCatalog([
            'x.operation' => ['path' => '/api/x', 'method' => 'get', 'summary' => 'X operation'],
        ]);
    }

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
     * EffectiveBoundResolverTest.php's own seedDelegationRow()).
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
     * directly -- the refusal cases exercised below never reach the
     * nested run() call, so this avoids needing a scripted LlmProvider
     * for what is genuinely a Unit-level concern (the ancestor walk
     * itself), mirroring how this file's own EffectiveBoundResolver
     * assertions call check() directly rather than driving a full turn.
     */
    private function resolveAndValidate(Conversation $parentConversation, string $helperAgentId): array
    {
        $method = new \ReflectionMethod(DelegationService::class, 'resolveAndValidate');
        $method->setAccessible(true);

        return $method->invoke($this->delegationService(), $parentConversation, $helperAgentId);
    }

    // =================================================================
    // EffectiveBoundResolver::check() -- the ancestor-agent-identity
    // backstop.
    // =================================================================

    #[Test]
    public function an_ancestor_agent_revisited_earlier_in_the_walk_is_blocked_even_though_it_currently_permits_the_operation(): void
    {
        $owner = $this->user();
        $this->seedXOperationCatalog();

        // Both A and B permit x.operation -- so if the walk stopped here
        // for a PERMISSION reason, it would allow. The only thing that can
        // block this attempt is the new agent-IDENTITY guard.
        $agentA = $this->agentPermitting($owner, 'ebrig-revisit-agent-a', ['x.operation']);
        $agentB = $this->agentPermitting($owner, 'ebrig-revisit-agent-b', ['x.operation']);

        $conv1 = $this->conversation($owner, $agentA);
        $conv2 = $this->conversation($owner, $agentB);
        // conv3 is bound to A again -- a data anomaly bypassing the
        // config-time DFS entirely (B was never offered/assigned A back,
        // this row is inserted directly).
        $conv3 = $this->conversation($owner, $agentA);
        $conv4 = $this->conversation($owner);

        // conv1(A) -> conv2(B): a capability-offering-shaped hop.
        $this->seedDelegationRow([
            'parent_conversation_id' => $conv1->id,
            'parent_agent_id' => $agentA->id,
            'helper_conversation_id' => $conv2->id,
            'owner_user_id' => $owner->id,
            'depth' => 1,
            'origin' => 'capability_offering',
        ]);
        // conv2(B) -> conv3(A again): a delegate_to_helper-shaped hop.
        $this->seedDelegationRow([
            'parent_conversation_id' => $conv2->id,
            'parent_agent_id' => $agentB->id,
            'helper_conversation_id' => $conv3->id,
            'owner_user_id' => $owner->id,
            'depth' => 2,
            'origin' => 'delegate_to_helper',
        ]);
        // conv3(A) -> conv4: the third hop, whose one ancestor (A) is
        // where the walk starting from conv4 will eventually revisit A a
        // SECOND time (once it reaches the conv1->conv2 hop above).
        $this->seedDelegationRow([
            'parent_conversation_id' => $conv3->id,
            'parent_agent_id' => $agentA->id,
            'helper_conversation_id' => $conv4->id,
            'owner_user_id' => $owner->id,
            'depth' => 3,
            'origin' => 'capability_offering',
        ]);

        $result = $this->resolver()->check($conv4, 'x.operation');

        $this->assertFalse(
            $result['allowed'],
            'agent A appears twice as an ancestor in this chain (levels_up 1 and 3) -- the identity guard must refuse the walk the second time A is encountered, even though A currently permits x.operation',
        );
        $this->assertSame($agentA->id, $result['blocking_agent_id'], 'the blocker must be A -- the specific agent revisited');
        $this->assertSame(3, $result['levels_up'], 'A is revisited (the second time) at exactly levels_up 3');
        $this->assertLessThan(
            (int) config('llm-client.delegation.max_chain_depth', 5),
            $result['levels_up'],
            'the refusal must fire well under max_chain_depth -- detection is identity-based, not merely a depth count',
        );
    }

    #[Test]
    public function a_legitimate_non_circular_chain_of_ordinary_depth_is_not_mistaken_for_a_cycle_and_completes_normally(): void
    {
        $owner = $this->user();
        $this->seedXOperationCatalog();

        $agentA = $this->agentPermitting($owner, 'ebrig-legit-agent-a', ['x.operation']);
        $agentB = $this->agentPermitting($owner, 'ebrig-legit-agent-b', ['x.operation']);
        $agentC = $this->agentPermitting($owner, 'ebrig-legit-agent-c', ['x.operation']);

        $conv1 = $this->conversation($owner, $agentA);
        $conv2 = $this->conversation($owner, $agentB);
        $conv3 = $this->conversation($owner, $agentC);
        $conv4 = $this->conversation($owner);

        $this->seedDelegationRow([
            'parent_conversation_id' => $conv1->id,
            'parent_agent_id' => $agentA->id,
            'helper_conversation_id' => $conv2->id,
            'owner_user_id' => $owner->id,
            'depth' => 1,
            'origin' => 'capability_offering',
        ]);
        $this->seedDelegationRow([
            'parent_conversation_id' => $conv2->id,
            'parent_agent_id' => $agentB->id,
            'helper_conversation_id' => $conv3->id,
            'owner_user_id' => $owner->id,
            'depth' => 2,
            'origin' => 'delegate_to_helper',
        ]);
        $this->seedDelegationRow([
            'parent_conversation_id' => $conv3->id,
            'parent_agent_id' => $agentC->id,
            'helper_conversation_id' => $conv4->id,
            'owner_user_id' => $owner->id,
            'depth' => 3,
            'origin' => 'capability_offering',
        ]);

        $result = $this->resolver()->check($conv4, 'x.operation');

        $this->assertTrue(
            $result['allowed'],
            'three DISTINCT ancestor agents (A, B, C), none revisited, none excluding x.operation -- must be allowed, never mistaken for a cycle',
        );
        $this->assertNull($result['blocking_agent_id']);
        $this->assertNull($result['blocking_agent_name']);
        $this->assertNull($result['levels_up']);
    }

    // =================================================================
    // DelegationService::resolveAndValidate() -- the identical
    // agent-identity backstop, applied to the SPECIFIC agent about to be
    // invoked, before a new hop is ever created.
    // =================================================================

    #[Test]
    public function resolveAndValidate_refuses_re_invoking_an_agent_already_active_earlier_in_the_chain_with_a_code_distinct_from_depth_exceeded(): void
    {
        $owner = $this->user();
        $this->seedXOperationCatalog();

        $agentA = $this->agentPermitting($owner, 'ebrig-rav-agent-a', ['x.operation']);
        $agentB = $this->agentPermitting($owner, 'ebrig-rav-agent-b', ['x.operation']);
        $agentC = $this->agentPermitting($owner, 'ebrig-rav-agent-c', ['x.operation']);

        // C must be eligible to (re-)invoke A via delegate_to_helper --
        // seeded directly, bypassing AgentHelperService::assign()'s own
        // config-time cycle DFS entirely (the exact "data anomaly/race"
        // quickstart scenario 5 describes).
        AgentHelperAssignment::create([
            'parent_agent_id' => $agentC->id,
            'helper_agent_id' => $agentA->id,
            'owner_user_id' => $owner->id,
        ]);

        $conv1 = $this->conversation($owner, $agentA);
        $conv2 = $this->conversation($owner, $agentB);
        $conv3 = $this->conversation($owner, $agentC);

        // conv1(A) -> conv2(B): a capability-offering-shaped hop.
        $this->seedDelegationRow([
            'parent_conversation_id' => $conv1->id,
            'parent_agent_id' => $agentA->id,
            'helper_conversation_id' => $conv2->id,
            'owner_user_id' => $owner->id,
            'depth' => 1,
            'origin' => 'capability_offering',
        ]);
        // conv2(B) -> conv3(C): a delegate_to_helper-shaped hop.
        $this->seedDelegationRow([
            'parent_conversation_id' => $conv2->id,
            'parent_agent_id' => $agentB->id,
            'helper_conversation_id' => $conv3->id,
            'owner_user_id' => $owner->id,
            'depth' => 2,
            'origin' => 'delegate_to_helper',
        ]);

        // Now C attempts to (re-)invoke A -- completing A -> B -> C -> A.
        // The resulting depth would be 3, well under the default
        // max_chain_depth of 5 -- proving detection is identity-based,
        // not merely a depth count.
        $result = $this->resolveAndValidate($conv3, $agentA->id);

        $this->assertArrayHasKey('error', $result, 'C attempting to re-invoke A, already active earlier in this exact chain, must be refused');
        $this->assertSame(
            'agent_already_active_in_chain',
            $result['error'],
            'the refusal must carry its own distinct error code, not the generic delegation_depth_exceeded a chain merely reaching the depth ceiling produces',
        );
        $this->assertNotSame('delegation_depth_exceeded', $result['error']);

        $this->assertSame(0, Delegation::where('helper_agent_id', $agentA->id)->count(), 're-invoking A must never write a new Delegation row for it');
    }

    #[Test]
    public function resolveAndValidate_allows_a_legitimate_non_circular_chain_of_ordinary_depth(): void
    {
        $owner = $this->user();
        $this->seedXOperationCatalog();

        $agentA = $this->agentPermitting($owner, 'ebrig-rav-legit-agent-a', ['x.operation']);
        $agentB = $this->agentPermitting($owner, 'ebrig-rav-legit-agent-b', ['x.operation']);
        $agentC = $this->agentPermitting($owner, 'ebrig-rav-legit-agent-c', ['x.operation']);
        $agentD = $this->agentPermitting($owner, 'ebrig-rav-legit-agent-d', ['x.operation']);

        // C is eligible to invoke D -- a brand-new agent never seen
        // earlier in this specific chain.
        AgentHelperAssignment::create([
            'parent_agent_id' => $agentC->id,
            'helper_agent_id' => $agentD->id,
            'owner_user_id' => $owner->id,
        ]);

        $conv1 = $this->conversation($owner, $agentA);
        $conv2 = $this->conversation($owner, $agentB);
        $conv3 = $this->conversation($owner, $agentC);

        $this->seedDelegationRow([
            'parent_conversation_id' => $conv1->id,
            'parent_agent_id' => $agentA->id,
            'helper_conversation_id' => $conv2->id,
            'owner_user_id' => $owner->id,
            'depth' => 1,
            'origin' => 'capability_offering',
        ]);
        $this->seedDelegationRow([
            'parent_conversation_id' => $conv2->id,
            'parent_agent_id' => $agentB->id,
            'helper_conversation_id' => $conv3->id,
            'owner_user_id' => $owner->id,
            'depth' => 2,
            'origin' => 'delegate_to_helper',
        ]);

        $result = $this->resolveAndValidate($conv3, $agentD->id);

        $this->assertArrayNotHasKey('error', $result, 'D was never active earlier in this chain (A, B, C) -- this is an ordinary, legitimate hop and must not be mistaken for a cycle');
        $this->assertSame($agentD->id, $result['helperAgent']->id);
        $this->assertSame(3, $result['depth']);
    }

    // =================================================================
    // DelegationService::invokeAsCapability() -- the identical guard,
    // applied to $offering->offered_agent_id, seeded via a DIRECT
    // CapabilityOffering row insert (bypassing
    // CapabilityOfferingService::offer()'s own config-time DFS entirely).
    // =================================================================

    #[Test]
    public function invokeAsCapability_refuses_re_invoking_an_agent_already_active_earlier_in_the_chain(): void
    {
        $owner = $this->user();
        $this->seedXOperationCatalog();

        $agentA = $this->agentPermitting($owner, 'ebrig-iac-agent-a', ['x.operation']);
        $agentB = $this->agentPermitting($owner, 'ebrig-iac-agent-b', ['x.operation']);
        $agentC = $this->agentPermitting($owner, 'ebrig-iac-agent-c', ['x.operation']);

        $conv1 = $this->conversation($owner, $agentA);
        $conv2 = $this->conversation($owner, $agentB);
        $conv3 = $this->conversation($owner, $agentC);

        $this->seedDelegationRow([
            'parent_conversation_id' => $conv1->id,
            'parent_agent_id' => $agentA->id,
            'helper_conversation_id' => $conv2->id,
            'owner_user_id' => $owner->id,
            'depth' => 1,
            'origin' => 'capability_offering',
        ]);
        $this->seedDelegationRow([
            'parent_conversation_id' => $conv2->id,
            'parent_agent_id' => $agentB->id,
            'helper_conversation_id' => $conv3->id,
            'owner_user_id' => $owner->id,
            'depth' => 2,
            'origin' => 'delegate_to_helper',
        ]);

        // A offered directly to C -- inserted straight at the model
        // layer, bypassing CapabilityOfferingService::offer()'s own
        // union-graph cycle DFS entirely (the exact "data anomaly/race"
        // quickstart scenario 5 describes).
        $offeringForA = CapabilityOffering::create([
            'offered_agent_id' => $agentA->id,
            'caller_agent_id' => $agentC->id,
            'owner_user_id' => $owner->id,
            'capability_name' => 'do_a_thing',
            'capability_description' => 'Does a thing.',
            'input_description' => 'What to do.',
        ]);

        $result = $this->delegationService()->invokeAsCapability($conv3, $offeringForA, 'Please do the thing.');

        $this->assertArrayHasKey('error', $result, 'C invoking the capability offering for A, already active earlier in this exact chain, must be refused');
        $this->assertSame(
            ['error'],
            array_keys($result),
            'the refusal must be the plain {"error": "..."} shape execute_operation always uses, never a raw internal code leaking through',
        );
        $this->assertSame(
            0,
            Delegation::where('helper_agent_id', $agentA->id)->count(),
            're-invoking A via a capability offering must never write a new Delegation row for it',
        );
    }
}
