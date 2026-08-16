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
 * 110-delegation-deadlock-timeout, Phase 6 (US4, tasks.md T038-T039,
 * research.md D4, spec.md User Story 4, contracts/delegation-chain-bounds.md
 * §2).
 *
 * T038 proves resolveAndValidate()'s existing `agent_already_active_in_chain`
 * refusal message names only the ONE agent being re-invoked -- not the full
 * cycle path -- against a three-hop indirect cycle A -> B -> C -> A, mirroring
 * DelegationServiceCycleRegressionTest.php's own indirect-cycle fixture
 * (T011) exactly, and invoking the still-private resolveAndValidate() via
 * reflection the same way that file already does.
 *
 * T039 proves forceFinalizeStalledDelegation()'s composed result_summary
 * (added Phase 5, tasks.md T035) currently only ever reads the helper
 * conversation's own last assistant message (or a generic placeholder) --
 * it never names the parent agent the stalled delegation was working for,
 * nor (when there is no assistant message to fall back on) the helper agent
 * either. The helper conversation is deliberately seeded with NO assistant
 * messages at all, forcing the generic-placeholder branch, so this is
 * unambiguously red against today's behavior rather than accidentally
 * passing because a coincidentally-named assistant message happened to
 * mention an agent's name.
 *
 * Neither test's fixture setup is expected to change once T040 lands --
 * only forceFinalizeStalledDelegation()'s summary composition and
 * resolveAndValidate()'s message text are expected to change.
 */
class DelegationServiceStopReportTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('agent_delegations')->delete();
        DB::table('agent_helper_assignments')->delete();
        DB::table('messages')->delete();
        DB::table('conversations')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Operation-catalog scaffolding (DelegationServiceCycleRegressionTest's
    // own established precedent).
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
    // fixture helpers).
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
     * column the migration requires -- mirrors
     * DelegationServiceCycleRegressionTest's own seedDelegationRow().
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
     * directly -- mirrors DelegationServiceCycleRegressionTest's own
     * identical helper.
     */
    private function resolveAndValidate(Conversation $parentConversation, string $helperAgentId): array
    {
        $method = new \ReflectionMethod(DelegationService::class, 'resolveAndValidate');
        $method->setAccessible(true);

        return $method->invoke($this->delegationService(), $parentConversation, $helperAgentId);
    }

    // =================================================================
    // T038 -- a refused indirect cycle A -> B -> C -> A names all three
    // agents involved, in the order the loop would have formed.
    // =================================================================

    #[Test]
    public function a_refused_indirect_cycle_names_all_three_agents_in_the_order_the_loop_would_have_formed(): void
    {
        // Deliberately comfortably larger than 3, so the refusal below can
        // only be the identity-based cycle backstop, never a
        // depth-exhaustion refusal wearing the wrong message.
        config(['llm-client.delegation.max_chain_depth' => 10]);

        $owner = $this->user();
        $agentA = $this->makeAgent($owner, 'stop-report-cycle-a');
        $agentB = $this->makeAgent($owner, 'stop-report-cycle-b');
        $agentC = $this->makeAgent($owner, 'stop-report-cycle-c');

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

        // C attempts to (re-)invoke A -- completing A -> B -> C -> A.
        $result = $this->resolveAndValidate($conv3, $agentA->id);

        $this->assertSame(
            'agent_already_active_in_chain',
            $result['error'] ?? null,
            'fixture sanity: this must still be the existing identity-based cycle refusal',
        );

        $message = (string) ($result['message'] ?? '');

        $this->assertStringContainsString(
            $agentA->name,
            $message,
            'the refusal message must name agent A, the agent being re-invoked',
        );
        $this->assertStringContainsString(
            $agentB->name,
            $message,
            'US4 AC1/FR-008: a refused cycle must name every participant involved in the cycle, not only the one being re-invoked -- agent B (an intermediate participant in the loop) is currently absent from the message',
        );
        $this->assertStringContainsString(
            $agentC->name,
            $message,
            'US4 AC1/FR-008: a refused cycle must name every participant involved in the cycle -- agent C (the participant attempting the looping delegation) is currently absent from the message',
        );

        // "the order in which the loop would have formed" (US4 AC1): B's
        // name must appear before C's in the composed message, reflecting
        // A -> B -> C -> A rather than an arbitrary ordering.
        $posB = strpos($message, $agentB->name);
        $posC = strpos($message, $agentC->name);
        $this->assertNotFalse($posB, 'agent B\'s name must be findable in the message for an order comparison to be meaningful');
        $this->assertNotFalse($posC, 'agent C\'s name must be findable in the message for an order comparison to be meaningful');
        $this->assertLessThan(
            $posC,
            $posB,
            'the message must reflect the order the loop would have formed (A -> B -> C -> A): B must be named before C',
        );
    }

    // =================================================================
    // T039 -- a stalled solo delegation's composed result_summary names
    // BOTH the helper agent and the parent agent it was working for.
    // =================================================================

    #[Test]
    public function a_stalled_delegations_result_summary_names_both_the_helper_and_parent_agent(): void
    {
        $owner = $this->user();
        $parentAgent = $this->makeAgent($owner, 'stop-report-summary-parent');
        $helperAgent = $this->makeAgent($owner, 'stop-report-summary-helper');

        $parentConversation = $this->conversation($owner, $parentAgent);
        $helperConversation = $this->conversation($owner, $helperAgent);

        // Deliberately NO assistant messages at all on the helper
        // conversation -- forces forceFinalizeStalledDelegation()'s
        // current generic-placeholder fallback branch, so this test is
        // unambiguously red against today's behavior rather than
        // accidentally passing because a coincidentally-named assistant
        // message happened to mention either agent's name.
        $this->assertSame(
            0,
            $helperConversation->messages()->count(),
            'fixture sanity: the helper conversation must have no messages at all',
        );

        // Stale+idle, mirroring DelegationChainUnwindJourneyTest's own
        // fixture convention for a solo delegation whose owning process
        // died -- staleness/idleness are not actually re-checked by
        // forceFinalizeStalledDelegation() itself (only by the sweep's own
        // eligibility query), but the timestamp is set to match the
        // scenario this test represents.
        $delegation = $this->seedDelegationRow([
            'parent_conversation_id' => $parentConversation->id,
            'parent_agent_id' => $parentAgent->id,
            'helper_agent_id' => $helperAgent->id,
            'helper_conversation_id' => $helperConversation->id,
            'owner_user_id' => $owner->id,
            'depth' => 1,
            'started_at' => now()->subMinutes(30),
        ]);

        $this->delegationService()->forceFinalizeStalledDelegation($delegation);

        $delegation->refresh();

        $this->assertSame('exhausted', $delegation->status, 'fixture sanity: the row must reach the terminal stalled state');

        $summary = (string) $delegation->result_summary;

        $this->assertStringContainsString(
            $helperAgent->name,
            $summary,
            'US4 AC2/FR-008: a stalled chain\'s report must name the helper agent it was running -- currently the summary falls back to a generic placeholder that names no agent at all when the helper produced no assistant message',
        );
        $this->assertStringContainsString(
            $parentAgent->name,
            $summary,
            'US4 AC2/FR-008: a stalled chain\'s report must name the parent agent the delegation was working for at the point of the stop -- currently never named at all, regardless of whether an assistant message exists',
        );
    }
}
