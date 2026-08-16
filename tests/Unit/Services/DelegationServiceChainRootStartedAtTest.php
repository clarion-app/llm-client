<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Services\DelegationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 110-delegation-deadlock-timeout, Phase 2 (Foundational), tasks.md T004
 * (research.md D1, data-model.md "Chain root start time").
 *
 * DelegationService::chainRootStartedAt() does not exist yet -- it is added
 * by T005, mirroring ancestorAgentIds()'s existing backward walk (same
 * parent_conversation_id-follows-helper_conversation_id traversal) but
 * returning the EARLIEST started_at seen (the chain's root hop) rather than
 * a list of agent ids. Every test below is expected to fail right now with
 * a reflection "method does not exist" error -- that failure is the
 * correct, expected state for this phase, until T005 lands.
 *
 * The method is private, so it is invoked here via reflection, mirroring
 * EffectiveBoundResolverIdentityGuardTest's own established
 * resolveAndValidate() reflection-invocation pattern -- there is no shared
 * reflection helper trait in this suite, so each test file that needs one
 * defines its own small private wrapper.
 */
class DelegationServiceChainRootStartedAtTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::table('agent_delegations')->delete();
        DB::table('conversations')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function delegationService(): DelegationService
    {
        return app(DelegationService::class);
    }

    private function user(): User
    {
        return User::factory()->create();
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
     * column the migration requires with a sensible default so a test only
     * has to override the fields it actually cares about (mirrors
     * EffectiveBoundResolverIdentityGuardTest's own seedDelegationRow()).
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
     * Invokes the not-yet-created private chainRootStartedAt() directly.
     */
    private function chainRootStartedAt(Conversation $conversation): ?\Carbon\Carbon
    {
        $method = new \ReflectionMethod(DelegationService::class, 'chainRootStartedAt');
        $method->setAccessible(true);

        return $method->invoke($this->delegationService(), $conversation);
    }

    // =================================================================
    // Tests
    // =================================================================

    #[Test]
    public function no_enclosing_delegation_returns_null(): void
    {
        $owner = $this->user();
        $conversation = $this->conversation($owner);

        // No Delegation row anywhere names this conversation as a helper --
        // it is not part of any chain at all.
        $result = $this->chainRootStartedAt($conversation);

        $this->assertNull($result, 'a conversation with no enclosing delegation is not part of a chain, so there is no root start time to report');
    }

    #[Test]
    public function a_single_hop_chain_returns_that_hops_own_started_at(): void
    {
        $owner = $this->user();
        $parentConversation = $this->conversation($owner);
        $helperConversation = $this->conversation($owner);

        $hopStartedAt = \Carbon\Carbon::parse('2026-01-01 10:00:00');

        $this->seedDelegationRow([
            'parent_conversation_id' => $parentConversation->id,
            'helper_conversation_id' => $helperConversation->id,
            'owner_user_id' => $owner->id,
            'depth' => 1,
            'started_at' => $hopStartedAt,
        ]);

        $result = $this->chainRootStartedAt($helperConversation);

        $this->assertNotNull($result);
        $this->assertTrue(
            $hopStartedAt->equalTo($result),
            "a single-hop chain's root IS that hop -- the returned started_at must be the hop's own, got {$result->toDateTimeString()}",
        );
    }

    #[Test]
    public function a_multi_hop_chain_returns_the_roots_started_at_not_the_immediate_parents(): void
    {
        $owner = $this->user();

        // conv1 --(hop 1, the ROOT)--> conv2 --(hop 2, the immediate
        // parent)--> conv3. chainRootStartedAt(conv3) must walk all the way
        // back to hop 1's own started_at, not stop at hop 2's.
        $conv1 = $this->conversation($owner);
        $conv2 = $this->conversation($owner);
        $conv3 = $this->conversation($owner);

        // Deliberately far apart and unambiguous -- if the wrong hop's
        // timestamp were returned, these assertions could not both pass.
        $rootStartedAt = \Carbon\Carbon::parse('2020-06-01 08:00:00');
        $immediateParentStartedAt = \Carbon\Carbon::parse('2026-08-16 09:30:00');

        $this->seedDelegationRow([
            'parent_conversation_id' => $conv1->id,
            'helper_conversation_id' => $conv2->id,
            'owner_user_id' => $owner->id,
            'depth' => 1,
            'started_at' => $rootStartedAt,
        ]);
        $this->seedDelegationRow([
            'parent_conversation_id' => $conv2->id,
            'helper_conversation_id' => $conv3->id,
            'owner_user_id' => $owner->id,
            'depth' => 2,
            'started_at' => $immediateParentStartedAt,
        ]);

        $result = $this->chainRootStartedAt($conv3);

        $this->assertNotNull($result);
        $this->assertTrue(
            $rootStartedAt->equalTo($result),
            "must return hop 1's (the ROOT's) started_at ({$rootStartedAt->toDateTimeString()}), got {$result->toDateTimeString()}",
        );
        $this->assertFalse(
            $immediateParentStartedAt->equalTo($result),
            "must NOT return hop 2's (the immediate parent's) started_at ({$immediateParentStartedAt->toDateTimeString()})",
        );
    }

    /**
     * Reconciliation: the visited-conversation-id guard is what keeps this
     * walk terminating against a data-level cycle in agent_delegations -- a
     * row naming its own helper conversation as its parent, or any longer
     * loop. Nothing at the database level forbids either (agent_delegations
     * carries no FKs at all, by design), and the walk is driven by a bare
     * `while (true)`, so a refactor that dropped the guard, or applied it to
     * the wrong variable (the delegation's id rather than the conversation
     * id it actually follows), would hang the delegating request forever
     * rather than fail. The bound is asserted here so that regression is
     * caught by a test rather than by a stuck worker.
     */
    #[Test]
    public function a_delegation_naming_itself_as_its_own_parent_terminates_instead_of_looping_forever(): void
    {
        $owner = $this->user();
        $selfReferential = $this->conversation($owner);

        $startedAt = \Carbon\Carbon::parse('2026-02-02 12:00:00');

        // A data anomaly: parent_conversation_id === helper_conversation_id,
        // so following parent_conversation_id lands straight back on the row
        // the walk just visited.
        $this->seedDelegationRow([
            'parent_conversation_id' => $selfReferential->id,
            'helper_conversation_id' => $selfReferential->id,
            'owner_user_id' => $owner->id,
            'depth' => 1,
            'started_at' => $startedAt,
        ]);

        $result = $this->chainRootStartedAt($selfReferential);

        $this->assertNotNull($result, 'the walk must still report the one row it did see');
        $this->assertTrue(
            $startedAt->equalTo($result),
            'a self-parenting row is its own chain root -- the walk must stop there rather than following the same edge again',
        );
    }
}
