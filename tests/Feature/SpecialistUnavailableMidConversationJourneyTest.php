<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\ConversationHandoff;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 102-router-pattern, Phase 6 (US4, T045).
 *
 * spec.md US4's own "specialist becomes unavailable mid-conversation" edge
 * case, FR-008/FR-011, SC-007, research.md D7, contracts/routing-mechanism.md
 * §3/§5. Mirrors AgentHandoffJourneyTest.php's own handoff()/makeConversation()
 * helpers (for building a real conversation_handoffs chain via the existing
 * `handoff_to_agent` meta-tool) and AutomaticRoutingJourneyTest.php's own
 * scripted-provider scaffolding for driving run() end-to-end.
 *
 * Written before AgentLoopService::ensureSpecialistAvailable() exists and
 * before it is wired at any of run()'s/start()'s/resumeSync()'s three entry
 * points (Phase 6's own T051/T052) — every test in this file is expected to
 * FAIL: a conversation's currently-effective agent, once deactivated, keeps
 * silently "answering" (i.e. Message.agent_id keeps resolving to it via the
 * unmodified ConversationHandoff::currentAgentIdentityFor()) forever, with
 * no new conversation_handoffs row ever written and no distinct disclosure
 * ever composed, because nothing yet checks is_active on the conversation's
 * current effective agent at any turn boundary.
 */
class SpecialistUnavailableMidConversationJourneyTest extends TestCase
{
    private User $user;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->seedOperationCatalog();

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'http://localhost:11434',
            'provider_type' => 'llama_cpp',
        ]);

        config(['llm-client.budget.on_unpriced_model' => 'admit_untracked']);

        if (!Schema::hasTable('mcp_sessions')) {
            Schema::create('mcp_sessions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->string('protocol_version');
                $table->string('client_name')->nullable();
                $table->string('client_version')->nullable();
                $table->json('capabilities')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index('user_id');
            });
        }

        if (!Schema::hasTable('episodic_memories')) {
            Schema::create('episodic_memories', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->uuid('conversation_id');
                $table->text('summary');
                $table->json('topics');
                $table->boolean('protected')->default(false);
                $table->unsignedInteger('word_count');
                $table->unsignedInteger('summary_word_count');
                $table->json('embedding')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (!Schema::hasTable('condensation_states')) {
            Schema::create('condensation_states', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id')->unique();
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->timestamp('cooldown_until')->nullable();
                $table->timestamps();
            });
        }
    }

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('conversation_handoffs')->delete();
        DB::table('messages')->delete();
        if (Schema::hasTable('mcp_sessions')) {
            DB::table('mcp_sessions')->delete();
        }
        DB::table('agent_runs')->delete();
        DB::table('conversations')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Operation-catalog scaffolding
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
    // Fixtures (AgentHandoffJourneyTest's own precedent)
    // -----------------------------------------------------------------

    private function makeConversation(Agent $agent, string $userId): Conversation
    {
        return Conversation::factory()->create([
            'user_id' => $userId,
            'server_id' => $this->server->id,
            'title' => 'Already titled',
            'agent_id' => $agent->id,
            'agent_version_id' => $agent->current_version_id,
        ]);
    }

    /** @return array<string, mixed> */
    private function handoff(Conversation $conversation, string $targetAgentId): array
    {
        $result = app(AgentLoopService::class)->executeMetaTool(
            'handoff_to_agent',
            ['agent_id' => $targetAgentId],
            $conversation,
        );

        return json_decode($result, true);
    }

    // -----------------------------------------------------------------
    // Scripted-provider scaffolding (AutomaticRoutingJourneyTest precedent)
    // -----------------------------------------------------------------

    private function serviceWithScriptedProvider(array $responses): AgentLoopService
    {
        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function ($messages, $tools, $options = []) use (&$responses) {
            return array_shift($responses);
        });
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);

        return new AgentLoopService(
            app(McpToolRegistry::class),
            app(McpToolExecutor::class),
            app(OperationCache::class),
            $registry,
            runTraceRecorder: config('llm-client.run_trace.enabled', false) ? app(RunTraceRecorder::class) : null,
        );
    }

    private function plainReply(string $content): array
    {
        return ['choices' => [['message' => ['content' => $content, 'tool_calls' => []]]]];
    }

    // =================================================================
    // 1. The currently-effective agent is deactivated mid-conversation —
    //    the next turn is answered by a fallback, a new conversation_handoffs
    //    row is written with reason = 'unavailable', disclosed with the
    //    distinct "became unavailable" wording naming the new specialist —
    //    never a stall, never a hard failure (FR-008/FR-011, SC-007).
    // =================================================================

    #[Test]
    public function a_specialist_deactivated_mid_conversation_is_replaced_by_a_fallback_with_a_distinct_disclosure(): void
    {
        $agentA = app(AgentService::class)->create($this->user->id, "name: agent-a\ninstructions: I am agent A, the original specialist.");
        $agentB = app(AgentService::class)->create($this->user->id, "name: agent-b\ninstructions: I am agent B, the only other active specialist.");

        $conversation = $this->makeConversation($agentA, $this->user->id);

        // Turn 1: agent A answers normally — an ordinary, already-ongoing
        // conversation.
        $service = $this->serviceWithScriptedProvider([
            $this->plainReply('First reply, from agent A.'),
        ]);
        $firstResult = $service->run($conversation->fresh(), 'My first question.');
        $this->assertSame('completed', $firstResult['status'], 'fixture sanity: the first turn must complete normally');
        $firstMessage = Message::find($firstResult['message_id']);
        $this->assertSame($agentA->id, $firstMessage->agent_id, 'fixture sanity: the first reply must be attributed to agent A');

        // Agent A is deactivated mid-conversation.
        app(AgentService::class)->deactivate($agentA->fresh(), true);
        $this->assertFalse($agentA->fresh()->is_active, 'fixture sanity: agent A must actually be deactivated');

        // Turn 2: the next turn must be answered by a fallback — never a
        // stall, never a hard failure.
        $service = $this->serviceWithScriptedProvider([
            $this->plainReply('Second reply, from whichever fallback took over.'),
        ]);
        $secondResult = $service->run($conversation->fresh(), 'My second question, after my specialist went away.');

        $this->assertSame('completed', $secondResult['status'], 'a specialist going unavailable mid-conversation must never fail or stall the next turn');

        $row = ConversationHandoff::where('conversation_id', $conversation->id)->orderByDesc('position')->first();
        $this->assertNotNull($row, 'an automatic fallback must write a new conversation_handoffs row');
        $this->assertSame('unavailable', $row->reason, 'the automatic fallback\'s row must be distinguishable from an ordinary agent-initiated handoff via reason = unavailable');
        $this->assertSame($agentB->id, $row->to_agent_id, 'the only other active specialist must be the one taking over');
        $this->assertSame($agentA->id, $row->from_agent_id);

        $this->assertStringContainsString(
            'became unavailable',
            $secondResult['content'],
            'the disclosure must use the distinct "became unavailable" wording (data-model.md §3), never the ordinary handoff sentence',
        );
        $this->assertStringContainsString(
            'agent-b',
            $secondResult['content'],
            'the disclosure must name the newly assigned specialist',
        );

        $secondMessage = Message::find($secondResult['message_id']);
        $this->assertNotNull($secondMessage);
        $this->assertSame(
            $agentB->id,
            $secondMessage->agent_id,
            'the produced Message must be attributed to the new fallback specialist, never the deactivated original',
        );
        $this->assertNotSame($agentA->id, $secondMessage->agent_id, 'the deactivated agent must never answer again');
    }

    // =================================================================
    // 2. The automatic fallback correctly excludes BOTH the unavailable
    //    agent AND every agent already in the conversation's own handoff
    //    chain (093's cycle-prevention, reused) — no cycle is ever
    //    produced, even when the excluded original agent would otherwise
    //    clearly win the rescoring.
    // =================================================================

    #[Test]
    public function the_automatic_fallback_excludes_the_unavailable_agent_and_every_agent_already_in_the_chain(): void
    {
        $agentA = app(AgentService::class)->create(
            $this->user->id,
            "name: agent-a\ninstructions: billing invoice payment problem charge repeated twice month",
        );
        $agentB = app(AgentService::class)->create($this->user->id, "name: agent-b\ninstructions: I am agent B.");
        $agentC = app(AgentService::class)->create($this->user->id, "name: agent-c\ninstructions: billing topics only, a distant secondary match.");

        $conversation = $this->makeConversation($agentA, $this->user->id);

        // An existing chain: A -> B, via the ordinary agent-initiated
        // handoff mechanism (093, unchanged).
        $handoffResult = $this->handoff($conversation, $agentB->id);
        $this->assertTrue($handoffResult['success'] ?? false, 'fixture sanity: the A -> B handoff must succeed');
        $this->assertSame(1, ConversationHandoff::where('conversation_id', $conversation->id)->count(), 'fixture sanity: the chain must have exactly one row before B becomes unavailable');

        // B, the conversation's currently-effective agent, becomes
        // unavailable.
        app(AgentService::class)->deactivate($agentB->fresh(), true);
        $this->assertFalse($agentB->fresh()->is_active, 'fixture sanity: agent B must actually be deactivated');

        $service = $this->serviceWithScriptedProvider([
            $this->plainReply('Handled by whichever fallback correctly excluded the chain.'),
        ]);
        $trigger = 'billing invoice payment problem charge repeated twice month, a serious problem.';
        $result = $service->run($conversation->fresh(), $trigger);

        $this->assertSame('completed', $result['status']);

        $rows = ConversationHandoff::where('conversation_id', $conversation->id)->orderBy('position')->get();
        $this->assertCount(2, $rows, 'exactly one new row must be written for the automatic fallback — no cycle, no duplicate rows');

        $newRow = $rows->last();
        $this->assertSame('unavailable', $newRow->reason);
        $this->assertSame(
            $agentC->id,
            $newRow->to_agent_id,
            'agent C — the only candidate NOT already in the chain and NOT the unavailable agent — must be the one chosen',
        );
        $this->assertNotSame(
            $agentA->id,
            $newRow->to_agent_id,
            'agent A must never be re-selected: it is both the conversation\'s own original agent_id AND already a member of the handoff chain, even though its instructions score highest against the trigger text',
        );
        $this->assertNotSame($agentB->id, $newRow->to_agent_id, 'the unavailable agent itself must never be re-selected');

        // The original A -> B row (an ordinary agent-initiated handoff,
        // 093-unchanged) must be completely untouched by the automatic
        // fallback — its own reason must stay null, never retroactively
        // rewritten.
        $originalRow = ConversationHandoff::find($rows->first()->id);
        $this->assertNull($originalRow->reason, 'the pre-existing A -> B handoff row must never be retroactively rewritten to reason = unavailable');
        $this->assertSame($agentB->id, $originalRow->to_agent_id);
    }

    // =================================================================
    // 3. A chain already at max_chain_length whose current agent is then
    //    ALSO deactivated — the turn proceeds unchanged (the documented
    //    last-resort degrade, research.md D7) rather than throwing or
    //    looping past the bound.
    // =================================================================

    #[Test]
    public function a_chain_already_at_its_configured_bound_whose_current_agent_is_deactivated_proceeds_unchanged(): void
    {
        config(['llm-client.handoff.max_chain_length' => 2]);

        $agentA = app(AgentService::class)->create($this->user->id, "name: agent-a\ninstructions: I am agent A.");
        $agentB = app(AgentService::class)->create($this->user->id, "name: agent-b\ninstructions: I am agent B.");
        $agentC = app(AgentService::class)->create($this->user->id, "name: agent-c\ninstructions: I am agent C.");

        $conversation = $this->makeConversation($agentA, $this->user->id);

        $first = $this->handoff($conversation, $agentB->id);
        $this->assertTrue($first['success'] ?? false, 'fixture sanity: the A -> B handoff must succeed');
        $second = $this->handoff($conversation->fresh(), $agentC->id);
        $this->assertTrue($second['success'] ?? false, 'fixture sanity: the B -> C handoff, reaching the configured bound of 2, must still succeed');
        $this->assertSame(2, ConversationHandoff::where('conversation_id', $conversation->id)->count(), 'fixture sanity: the chain must be exactly at its configured bound before C is deactivated');

        // C, the conversation's currently-effective agent, is now ALSO
        // deactivated, with the chain already exhausted.
        app(AgentService::class)->deactivate($agentC->fresh(), true);
        $this->assertFalse($agentC->fresh()->is_active, 'fixture sanity: agent C must actually be deactivated');

        $service = $this->serviceWithScriptedProvider([
            $this->plainReply('The turn must still complete, unchanged, even though no fallback can be applied.'),
        ]);
        $result = $service->run($conversation->fresh(), 'One more question, after the chain is exhausted and my agent is gone.');

        $this->assertSame(
            'completed',
            $result['status'],
            'the turn must proceed unchanged (the documented last-resort degrade) rather than throwing or looping past the configured bound',
        );

        $this->assertSame(
            2,
            ConversationHandoff::where('conversation_id', $conversation->id)->count(),
            'no third conversation_handoffs row may be written once the chain has already reached its configured bound',
        );
    }
}
