<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\AgentLoopStreamHandler;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\ConversationHandoff;
use ClarionApp\LlmClient\Models\CostSummary;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\ReductionStep;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\BudgetGate;
use ClarionApp\LlmClient\Services\DegradationGate;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\RateLimitGate;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\Services\SpendingCeilingService;
use ClarionApp\LlmClient\ValueObjects\BudgetScope;
use ClarionApp\LlmClient\ValueObjects\BudgetWorkKind;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 093-agent-handoff, Phase 4 (US2, T022).
 *
 * spec.md US2 Acceptance Scenarios 1-3, FR-004/FR-005, SC-002, contracts
 * §2, research.md D6, quickstart steps 4-6/18.
 *
 * Written before AgentLoopService::composeHandoffDisclosure() exists and
 * before any of its three call sites (run()'s and resumeSync()'s
 * plain-text completion branches, AgentLoopStreamHandler::finish()'s
 * plain-text branch) invoke it. Every test in this file is expected to
 * FAIL red: composeHandoffDisclosure() is undefined (a fatal Error calling
 * a method that does not exist on AgentLoopService), or — where a test
 * calls the method indirectly through run()/resumeSync()/finish() rather
 * than probing it in isolation — the persisted Message.content never
 * carries the disclosure sentence because no call site invokes it yet.
 *
 * Mirrors DisclosureJourneyTest.php's own setUp()/tearDown()/scripted-
 * provider/admitAndOpenStreamedRun()/runStreamedFinish() scaffolding for
 * the streaming case, ConversationBindingSurvivesAgentEditJourneyTest.php's
 * own synchronous-path table scaffolding, and AgentHandoffJourneyTest.php's
 * own handoff() helper (duplicated here per this package's own established
 * precedent of small helper duplication across sibling test files, per
 * tasks.md's own T022 wording — not extracted to a shared trait).
 */
class AgentHandoffDisclosureJourneyTest extends TestCase
{
    private User $user;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-12 10:00:00', 'UTC'));

        $this->user = User::factory()->create();
        $this->seedOperationCatalog();

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'http://localhost:11434',
            'provider_type' => 'llama_cpp',
        ]);

        config(['llm-client.budget.on_unpriced_model' => 'admit_untracked']);

        // executeApiCall()'s own getOrCreateSession() (reached whenever an
        // execute_operation call is actually permitted) needs an MCP
        // session row — EntryPathCoverageJourneyTest's/AgentHandoffJourneyTest's
        // own established precedent for this exact table.
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

        // buildMessagesPayload()/applyContextWindowTrim() (both in the
        // run()/resumeSync()/start() funnel) read these tables regardless
        // of whether auto-memory retrieval or condensation ever actually
        // triggers — ConversationBindingSurvivesAgentEditJourneyTest's own
        // established precedent.
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

        Carbon::setTestNow();

        DB::table('conversation_handoffs')->delete();
        DB::table('messages')->delete();
        if (Schema::hasTable('mcp_sessions')) {
            DB::table('mcp_sessions')->delete();
        }
        DB::table('agent_runs')->delete();
        DB::table('cost_summaries')->delete();
        DB::table('spending_ceilings')->delete();
        DB::table('reduction_steps')->delete();
        DB::table('degradation_events')->delete();
        DB::table('degradation_summaries')->delete();
        DB::table('conversations')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Operation-catalog scaffolding (AgentHandoffJourneyTest precedent)
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
    // Handoff helper (AgentHandoffJourneyTest's own T013 precedent)
    // -----------------------------------------------------------------

    private function makeConversation(?Agent $agent, string $userId): Conversation
    {
        // 'title' is pre-set (mirrors DisclosureJourneyTest::newConversation())
        // so run()'s own title-generation dispatch is never triggered — the
        // SyncQueue driver would otherwise execute
        // OpenAIGenerateConversationTitleRequest inline and attempt a real
        // HTTP call to the fixture server_url, unrelated to what this test
        // is about.
        return Conversation::factory()->create([
            'user_id' => $userId,
            'server_id' => $this->server->id,
            'title' => 'Already titled',
            'agent_id' => $agent?->id,
            'agent_version_id' => $agent?->current_version_id,
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
    // Scripted-provider scaffolding (DisclosureJourneyTest precedent)
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

    /**
     * DisclosureJourneyTest's own degradation-forcing fixture, reused
     * verbatim to force a reduced response alongside a handoff for the
     * "both disclosures compose together, correctly ordered" scenario.
     */
    private function declareCeiling(string $amount): void
    {
        app(SpendingCeilingService::class)->upsert(
            BudgetScope::UserDefault,
            SpendingCeiling::INSTALLATION_SCOPE_ID,
            ['amount' => $amount, 'period_type' => 'month', 'enforcement_mode' => 'stop'],
        );
    }

    private function recordSpend(string $amount): void
    {
        DB::table('cost_summaries')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'entity_type' => CostSummary::ENTITY_USER,
            'entity_id' => $this->user->id,
            'user_id' => $this->user->id,
            'period_date' => '2026-08-12',
            'request_count' => 1,
            'priced_cost_total' => $amount,
            'zero_priced_request_count' => 0,
            'unpriced_request_count' => 0,
            'unpriced_total_tokens' => 0,
            'estimated_request_count' => 0,
            'updated_at' => Carbon::now(),
        ]);
    }

    private function substituteModelRung(): ReductionStep
    {
        return ReductionStep::create([
            'axis' => 'budget_user',
            'threshold_ratio' => '0.7500',
            'substitute_model' => 'small-model',
            'enabled' => true,
        ]);
    }

    /** Crosses the rung's threshold without reaching the ceiling. */
    private function crossThreshold(): void
    {
        $this->declareCeiling('100.0000000000');
        $this->recordSpend('80.0000000000');
    }

    /**
     * Mirrors DisclosureJourneyTest::admitAndOpenStreamedRun() exactly —
     * replicates AgentLoopService::admitInteractiveWork()'s own admission
     * + RunTraceRecorder::openRun() sequence (private, not directly
     * callable from a test) so the streamed path can be driven the same
     * way production's start() drives it.
     */
    private function admitAndOpenStreamedRun(Conversation $conversation): string
    {
        $rateLimitDecision = app(RateLimitGate::class)->admit(
            (string) $conversation->user_id,
            BudgetWorkKind::Interactive,
            $conversation->id,
        );
        $budgetDecision = app(BudgetGate::class)->admit(
            (string) $conversation->user_id,
            BudgetWorkKind::Interactive,
            $conversation->id,
        );
        app(DegradationGate::class)->evaluate(
            (string) $conversation->user_id,
            $conversation->id,
            $rateLimitDecision,
            $budgetDecision,
        );

        $runId = app(RunTraceRecorder::class)->openRun(
            RunKind::Interactive,
            (string) $conversation->user_id,
            $conversation->id,
            streamed: true,
            model: $conversation->model,
            agentId: $conversation->character ?? $conversation->id,
        );

        $this->assertNotNull($runId, 'run tracing must be enabled for this test to exercise the streamed handoff');

        return $runId;
    }

    private function runStreamedFinish(Conversation $conversation, string $runId, string $reply): Message
    {
        Event::fake([FinishOpenAIConversationResponseEvent::class]);

        $recorder = app(RunTraceRecorder::class);
        $stepId = $recorder->openStep($runId, 1, (string) \Illuminate\Support\Str::uuid());

        $handler = new AgentLoopStreamHandler(null, null, $recorder);
        $handler->toolCalls = [];
        $handler->reply = $reply;
        $handler->message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
        ]);

        $data = json_encode([
            'conversation_id' => $conversation->id,
            'iteration' => 1,
            'run_id' => $runId,
            'step_id' => $stepId,
        ]);

        $handler->finish($data, 2);

        $handler->message->refresh();

        return $handler->message;
    }

    // =================================================================
    // 1. Synchronous path — AgentLoopService::run()
    // =================================================================

    #[Test]
    public function run_after_a_handoff_prefixes_the_next_response_with_a_disclosure_naming_the_receiving_agent(): void
    {
        $agentA = app(AgentService::class)->create($this->user->id, "name: agent-a\ninstructions: I am agent A.");
        $agentB = app(AgentService::class)->create($this->user->id, "name: agent-b\ninstructions: I am agent B.");

        $conversation = $this->makeConversation($agentA, $this->user->id);

        $handoffResult = $this->handoff($conversation, $agentB->id);
        $this->assertTrue($handoffResult['success'] ?? false, 'fixture sanity: the handoff itself must succeed');

        $service = $this->serviceWithScriptedProvider([
            $this->plainReply('Here is your answer.'),
        ]);

        $result = $service->run($conversation->fresh(), 'Please help.');

        $this->assertSame('completed', $result['status']);
        $this->assertStringContainsString(
            'handed off to "agent-b"',
            $result['content'],
            'the returned content must begin with a disclosure sentence naming the receiving agent',
        );
        $this->assertStringEndsWith(
            'Here is your answer.',
            $result['content'],
            'the original reply must still follow the disclosure, unmodified',
        );

        $message = Message::find($result['message_id']);
        $this->assertNotNull($message);
        $this->assertStringContainsString(
            'handed off to "agent-b"',
            $message->content,
            'the persisted assistant Message.content must carry the disclosure permanently, not just the returned array',
        );

        $rereadLater = Message::find($message->id);
        $this->assertStringContainsString(
            'handed off to "agent-b"',
            $rereadLater->content,
            'a later, independent re-read of the same message must still show the disclosure sentence — it is not a transient notice',
        );

        $row = ConversationHandoff::where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($row->disclosed_at, 'the ConversationHandoff row must be marked disclosed once the disclosure has fired');
    }

    // =================================================================
    // 2. Synchronous path — AgentLoopService::resumeSync()
    // =================================================================

    #[Test]
    public function resume_sync_after_a_handoff_discloses_identically_to_run(): void
    {
        $agentA = app(AgentService::class)->create($this->user->id, "name: agent-a\ninstructions: I am agent A.");
        $agentB = app(AgentService::class)->create($this->user->id, "name: agent-b\ninstructions: I am agent B.");

        $conversation = $this->makeConversation($agentA, $this->user->id);
        $conversation->update(['is_processing' => true]);

        $handoffResult = $this->handoff($conversation, $agentB->id);
        $this->assertTrue($handoffResult['success'] ?? false, 'fixture sanity: the handoff itself must succeed');

        // Mirrors DisclosureJourneyTest's own resumeSync() scaffolding —
        // $approved = false so executeApiCall() is never reached, and no
        // operation catalog seeding is required for this scenario.
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => '',
            'user' => 'Clarion',
            'tool_data' => [
                'tool_calls' => [[
                    'id' => 'call_confirmed',
                    'function' => ['name' => 'execute_operation', 'arguments' => '{}'],
                ]],
                'iteration' => 1,
                'pending_confirmation' => [
                    'operationId' => 'contacts.destroy',
                    'method' => 'DELETE',
                    'path' => '/api/contacts/1',
                    'arguments' => [],
                    'expires_at' => now()->addMinutes(5)->toIso8601String(),
                ],
            ],
        ]);

        $service = $this->serviceWithScriptedProvider([
            $this->plainReply('Confirmed and answered.'),
        ]);

        $result = $service->resumeSync($conversation->fresh(), $message, false);

        $this->assertSame('completed', $result['status']);
        $this->assertStringContainsString(
            'handed off to "agent-b"',
            $result['content'] ?? '',
            'resumeSync() must prepend the identical disclosure sentence shape as run()',
        );

        $savedMessage = Message::find($result['message_id'] ?? null);
        $this->assertNotNull($savedMessage);
        $this->assertStringContainsString('handed off to "agent-b"', $savedMessage->content);

        $row = ConversationHandoff::where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($row->disclosed_at);
    }

    // =================================================================
    // 3. Streaming path — AgentLoopStreamHandler::finish()
    // =================================================================

    #[Test]
    public function streamed_finish_after_a_handoff_discloses_not_lost_or_delayed_relative_to_the_synchronous_path(): void
    {
        $agentA = app(AgentService::class)->create($this->user->id, "name: agent-a\ninstructions: I am agent A.");
        $agentB = app(AgentService::class)->create($this->user->id, "name: agent-b\ninstructions: I am agent B.");

        $conversation = $this->makeConversation($agentA, $this->user->id);
        $conversation->update(['is_processing' => true]);

        $handoffResult = $this->handoff($conversation, $agentB->id);
        $this->assertTrue($handoffResult['success'] ?? false, 'fixture sanity: the handoff itself must succeed');

        $runId = $this->admitAndOpenStreamedRun($conversation->fresh());
        $message = $this->runStreamedFinish($conversation->fresh(), $runId, 'Streamed answer.');

        $this->assertStringContainsString(
            'handed off to "agent-b"',
            $message->content,
            'the streamed plain-reply branch must carry the identical disclosure sentence shape as the synchronous path',
        );
        $this->assertStringEndsWith('Streamed answer.', $message->content);

        $row = ConversationHandoff::where('conversation_id', $conversation->id)->first();
        $this->assertNotNull(
            $row->disclosed_at,
            'the ConversationHandoff row\'s disclosed_at must be non-null after the streamed finish() call',
        );
    }

    // =================================================================
    // 4. Disclosed exactly once
    // =================================================================

    #[Test]
    public function a_disclosed_handoff_is_not_disclosed_again_on_a_later_turn(): void
    {
        $agentA = app(AgentService::class)->create($this->user->id, "name: agent-a\ninstructions: I am agent A.");
        $agentB = app(AgentService::class)->create($this->user->id, "name: agent-b\ninstructions: I am agent B.");

        $conversation = $this->makeConversation($agentA, $this->user->id);

        $handoffResult = $this->handoff($conversation, $agentB->id);
        $this->assertTrue($handoffResult['success'] ?? false, 'fixture sanity: the handoff itself must succeed');

        $service = $this->serviceWithScriptedProvider([
            $this->plainReply('First reply after handoff.'),
            $this->plainReply('Second, unrelated later reply.'),
        ]);

        $first = $service->run($conversation->fresh(), 'Please help.');
        $this->assertStringContainsString('handed off to "agent-b"', $first['content']);

        $second = $service->run($conversation->fresh(), 'Another message, no new handoff.');

        $this->assertSame(
            'Second, unrelated later reply.',
            $second['content'],
            'a second turn after the disclosure has already fired must NOT repeat the disclosure sentence',
        );
        $this->assertStringNotContainsString('handed off to', $second['content']);
    }

    // =================================================================
    // 5. No handoffs at all — completely unaffected
    // =================================================================

    #[Test]
    public function a_conversation_with_no_handoffs_at_all_never_carries_disclosure_text(): void
    {
        $agentA = app(AgentService::class)->create($this->user->id, "name: agent-a\ninstructions: I am agent A.");
        $conversation = $this->makeConversation($agentA, $this->user->id);

        $this->assertSame(
            0,
            ConversationHandoff::where('conversation_id', $conversation->id)->count(),
            'fixture sanity: this conversation must never have been handed off',
        );

        $service = $this->serviceWithScriptedProvider([
            $this->plainReply('An ordinary reply.'),
        ]);

        $result = $service->run($conversation->fresh(), 'Please help.');

        $this->assertSame(
            'An ordinary reply.',
            $result['content'],
            'a conversation with no handoffs must never have any disclosure text prepended',
        );

        $message = Message::find($result['message_id']);
        $this->assertNotNull($message);
        $this->assertSame('An ordinary reply.', $message->content);
    }

    // =================================================================
    // 6. Soft-deleted receiving agent — graceful fallback naming
    // =================================================================

    #[Test]
    public function disclosure_composition_tolerates_a_receiving_agent_soft_deleted_after_the_handoff(): void
    {
        $agentA = app(AgentService::class)->create($this->user->id, "name: agent-a\ninstructions: I am agent A.");
        $agentB = app(AgentService::class)->create($this->user->id, "name: agent-b\ninstructions: I am agent B.");
        $agentBId = $agentB->id;

        $conversation = $this->makeConversation($agentA, $this->user->id);

        $handoffResult = $this->handoff($conversation, $agentBId);
        $this->assertTrue($handoffResult['success'] ?? false, 'fixture sanity: the handoff itself must succeed');

        // Soft-delete B between the handoff succeeding and the disclosure
        // firing — the same fixture idiom AgentActivationJourneyTest/
        // AgentCloneJourneyTest already use.
        Agent::find($agentBId)->delete();

        $service = $this->serviceWithScriptedProvider([
            $this->plainReply('Here is your answer.'),
        ]);

        $result = $service->run($conversation->fresh(), 'Please help.');

        $this->assertSame(
            'completed',
            $result['status'],
            'the turn must complete normally even though the receiving agent has since been soft-deleted',
        );
        $this->assertStringContainsString(
            'a retired agent',
            $result['content'],
            'composeHandoffDisclosure() must fall back to a generic name rather than crashing when the receiving agent is gone',
        );

        $message = Message::find($result['message_id']);
        $this->assertNotNull($message);
        $this->assertStringContainsString('a retired agent', $message->content);

        $row = ConversationHandoff::where('conversation_id', $conversation->id)->first();
        $this->assertNotNull(
            $row->disclosed_at,
            'the handoff row must still be marked disclosed even when the receiving agent has been soft-deleted',
        );
    }

    // =================================================================
    // 7. Handoff disclosure + degradation disclosure compose together,
    //    handoff disclosure reading first (contracts §2's own ordering)
    // =================================================================

    #[Test]
    public function a_handoff_disclosure_and_a_degradation_disclosure_compose_together_with_handoff_reading_first(): void
    {
        $this->crossThreshold();
        $this->substituteModelRung();

        $agentA = app(AgentService::class)->create($this->user->id, "name: agent-a\ninstructions: I am agent A.");
        $agentB = app(AgentService::class)->create($this->user->id, "name: agent-b\ninstructions: I am agent B.");

        $conversation = $this->makeConversation($agentA, $this->user->id);

        $handoffResult = $this->handoff($conversation, $agentB->id);
        $this->assertTrue($handoffResult['success'] ?? false, 'fixture sanity: the handoff itself must succeed');

        $service = $this->serviceWithScriptedProvider([
            $this->plainReply('Here is your answer.'),
        ]);

        $result = $service->run($conversation->fresh(), 'Please help.');

        $this->assertSame('completed', $result['status']);
        $this->assertTrue($result['degraded'] ?? false, 'fixture sanity: this scenario must itself be reduced for the ordering assertion to be meaningful');

        $content = $result['content'];
        $this->assertStringContainsString('handed off to "agent-b"', $content);
        $this->assertStringContainsString('reduced mode', $content);

        $handoffPosition = strpos($content, 'handed off to "agent-b"');
        $degradationPosition = strpos($content, 'reduced mode');

        $this->assertNotFalse($handoffPosition);
        $this->assertNotFalse($degradationPosition);
        $this->assertLessThan(
            $degradationPosition,
            $handoffPosition,
            'the handoff disclosure must read BEFORE the degradation disclosure in the final persisted content (contracts §2)',
        );

        $message = Message::find($result['message_id']);
        $this->assertNotNull($message);
        $this->assertLessThan(
            strpos($message->content, 'reduced mode'),
            strpos($message->content, 'handed off to "agent-b"'),
            'the persisted Message.content must preserve the same handoff-first ordering',
        );
    }
}
