<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\RouterService;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 102-router-pattern, Phase 6 (US4, T044).
 *
 * spec.md US4 Acceptance Scenarios, FR-004/FR-005/FR-011/FR-016, SC-006/
 * SC-007, research.md D5/D6, contracts/routing-mechanism.md §1 step 5.
 *
 * Mirrors AutomaticRoutingJourneyTest.php's own real Conversation/Agent/
 * AgentVersion fixture style and scripted-provider scaffolding verbatim.
 * `is_default_handler` is set via a direct DB::table('agents') update in
 * every fixture here (RouterServiceTest.php's own (f) case precedent) —
 * deliberately NOT via AgentService::setDefaultHandler(), so this file's
 * own RED failures are attributable to RouterService::route()'s missing
 * step 5 and AgentLoopService's missing ensureSpecialistAvailable() wiring,
 * never to AgentServiceDefaultHandlerTest.php's separate scope (T042/T046).
 *
 * Written before RouterService::route()'s step 5 (T049) exists — every
 * case that depends on a default-handler fallback firing is expected to
 * FAIL: a message matching no specialist still resolves to
 * RouterDecision(null, null, 'none') even with a default configured (the
 * exact Phase 3 behavior RouterServiceTest.php's own (f) case already
 * locks in), so conversation.agent_id/routing_reason stay null instead of
 * binding to the default.
 */
class RouterDefaultFallbackJourneyTest extends TestCase
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
    // Fixtures
    // -----------------------------------------------------------------

    private function makeUnboundConversation(string $userId): Conversation
    {
        return Conversation::factory()->create([
            'user_id' => $userId,
            'server_id' => $this->server->id,
            'title' => 'Already titled',
            'agent_id' => null,
            'agent_version_id' => null,
        ]);
    }

    private function markAsDefaultHandler(Agent $agent): void
    {
        DB::table('agents')->where('id', $agent->id)->update(['is_default_handler' => true]);
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
    // 1. No specialist matches, a default handler is configured — the
    //    default handles it, disclosed as such (FR-004/FR-005/SC-006).
    // =================================================================

    #[Test]
    public function no_specialist_matches_but_a_default_handler_is_configured_handles_it_and_is_disclosed_as_such(): void
    {
        $billingAgent = app(AgentService::class)->create(
            $this->user->id,
            "name: billing-agent\ninstructions: Handles billing invoices, payment questions, and account charges.",
        );
        $generalAgent = app(AgentService::class)->create(
            $this->user->id,
            "name: general-agent\ninstructions: A general-purpose fallback assistant with no specific topic focus.",
        );
        $this->markAsDefaultHandler($generalAgent);

        $conversation = $this->makeUnboundConversation($this->user->id);

        $service = $this->serviceWithScriptedProvider([
            $this->plainReply('Happy to help with whatever you need.'),
        ]);

        $result = $service->run(
            $conversation->fresh(),
            'What time does the movie start tonight?',
        );

        $this->assertSame('completed', $result['status']);

        $conversation = $conversation->fresh();
        $this->assertSame(
            $generalAgent->id,
            $conversation->agent_id,
            'the designated default handler must handle a request matching no configured specialist',
        );
        $this->assertNotSame($billingAgent->id, $conversation->agent_id);
        $this->assertSame(
            'default',
            $conversation->routing_reason,
            'conversations.routing_reason must read default once the default-handler fallback has fired',
        );

        $this->assertStringContainsString(
            'the default handler',
            $result['content'],
            'the disclosure must state a default was used',
        );
        $this->assertStringContainsString(
            'no specialist was a clear match',
            $result['content'],
            'the disclosure must state WHY the default was used — no specialist was a clear match (contracts §6)',
        );

        $message = Message::find($result['message_id']);
        $this->assertNotNull($message);
        $this->assertSame($generalAgent->id, $message->agent_id, 'the produced Message must be attributed to the default handler, never left generic/null (D10)');
    }

    // =================================================================
    // 2. No default configured, no match — the explicit, accepted
    //    degrade: proceeds exactly like pre-feature behavior (D5).
    // =================================================================

    #[Test]
    public function no_default_configured_and_no_match_leaves_agent_id_and_routing_reason_null(): void
    {
        // At least two non-matching candidates, so FR-016's single-candidate
        // short-circuit (which binds unconditionally, regardless of match)
        // never fires here — this case is specifically about a genuine
        // scoring pass that finds no positive-scoring candidate.
        app(AgentService::class)->create(
            $this->user->id,
            "name: billing-agent\ninstructions: Handles billing invoices, payment questions, and account charges.",
        );
        app(AgentService::class)->create(
            $this->user->id,
            "name: technical-agent\ninstructions: Handles technical software bugs, crashes, and system errors.",
        );
        // No agent is ever marked as the default handler.

        $conversation = $this->makeUnboundConversation($this->user->id);

        $service = $this->serviceWithScriptedProvider([
            $this->plainReply('An ordinary reply, no agent involved.'),
        ]);

        $result = $service->run(
            $conversation->fresh(),
            'What time does the movie start tonight?',
        );

        $this->assertSame('completed', $result['status'], 'a request with no matching specialist and no default must never fail — it degrades, exactly like pre-feature behavior');

        $conversation = $conversation->fresh();
        $this->assertNull($conversation->agent_id, 'no default configured, no match: agent_id must stay null');
        $this->assertNull($conversation->routing_reason, 'no default configured, no match: routing_reason must stay null');

        $this->assertSame('An ordinary reply, no agent involved.', $result['content'], 'no disclosure text may be prepended when nothing was routed');
    }

    // =================================================================
    // 3. Zero specialists configured at all — falls straight to the
    //    default-or-null-degrade path, no scoring pass, no added friction
    //    (FR-016's remaining "or the default" sub-case).
    // =================================================================

    #[Test]
    public function zero_agents_configured_at_all_falls_straight_to_the_null_degrade_with_no_error(): void
    {
        // Deliberately no Agent rows at all for this user.
        $conversation = $this->makeUnboundConversation($this->user->id);

        $service = $this->serviceWithScriptedProvider([
            $this->plainReply('An ordinary reply, no agent configured at all.'),
        ]);

        $result = $service->run(
            $conversation->fresh(),
            'Anything at all.',
        );

        $this->assertSame('completed', $result['status'], 'zero candidates must never fail the turn — it degrades cleanly, with no scoring pass attempted');

        $conversation = $conversation->fresh();
        $this->assertNull($conversation->agent_id);
        $this->assertNull($conversation->routing_reason);

        $message = Message::find($result['message_id']);
        $this->assertNotNull($message);
        $this->assertNull($message->agent_id, 'with zero agents configured, the produced Message must stay unattributed — there is no agent to attribute it to');
    }

    // =================================================================
    // 4. Exactly one specialist configured — still the Phase-3-proven
    //    single-candidate short-circuit, unaffected by a default handler
    //    ALSO being configured on that same lone agent.
    // =================================================================

    #[Test]
    public function exactly_one_specialist_configured_still_short_circuits_even_when_it_is_also_the_default_handler(): void
    {
        $lone = app(AgentService::class)->create(
            $this->user->id,
            "name: lone-agent\ninstructions: Talks exclusively about tropical fish care.",
        );
        $this->markAsDefaultHandler($lone);

        $conversation = $this->makeUnboundConversation($this->user->id);

        $service = $this->serviceWithScriptedProvider([
            $this->plainReply('Sure, here is an answer.'),
        ]);

        $result = $service->run(
            $conversation->fresh(),
            'I need help launching a rocket to Mars.',
        );

        $this->assertSame('completed', $result['status']);

        $conversation = $conversation->fresh();
        $this->assertSame($lone->id, $conversation->agent_id, 'the sole candidate must win even though nothing in the trigger text matches (FR-016 short-circuit)');
        $this->assertSame(
            'automatic',
            $conversation->routing_reason,
            'the single-candidate short-circuit must win BEFORE the default-handler fallback is ever consulted, so routing_reason reads automatic, never default, even though this same agent is also flagged as the default handler',
        );
    }

    // =================================================================
    // 5. The specialist automatic routing would otherwise pick is
    //    deactivated BEFORE the conversation's first turn — never chosen
    //    (by construction), a fallback handles it instead (US4 AC2,
    //    FR-011, SC-007).
    // =================================================================

    #[Test]
    public function a_specialist_deactivated_before_the_first_turn_is_never_chosen_and_a_fallback_handles_it_instead(): void
    {
        $billingAgent = app(AgentService::class)->create(
            $this->user->id,
            "name: billing-agent\ninstructions: Handles billing invoice payment matters directly for customers with billing invoice payment questions.",
        );
        $technicalAgent = app(AgentService::class)->create(
            $this->user->id,
            "name: technical-agent\ninstructions: Handles technical software bugs, crashes, and system errors.",
        );
        $generalAgent = app(AgentService::class)->create(
            $this->user->id,
            "name: general-agent\ninstructions: A general-purpose fallback assistant with no specific topic focus.",
        );
        $this->markAsDefaultHandler($generalAgent);

        // The specialist automatic routing would otherwise clearly pick for
        // a billing-shaped message — deactivated BEFORE the conversation's
        // very first turn ever runs.
        app(AgentService::class)->deactivate($billingAgent->fresh(), true);
        $this->assertFalse($billingAgent->fresh()->is_active, 'fixture sanity: the billing specialist must actually be deactivated first');

        $conversation = $this->makeUnboundConversation($this->user->id);

        $service = $this->serviceWithScriptedProvider([
            $this->plainReply('Handled by whichever fallback was chosen.'),
        ]);

        $result = $service->run(
            $conversation->fresh(),
            'I have a question about my billing invoice and a payment that was charged twice.',
        );

        $this->assertSame('completed', $result['status'], 'the request must never fail just because the best-matching specialist happens to be deactivated');

        $conversation = $conversation->fresh();
        $this->assertNotNull($conversation->agent_id, 'a fallback (another active specialist, or the default) must still handle the request');
        $this->assertNotSame(
            $billingAgent->id,
            $conversation->agent_id,
            'the deactivated specialist must never be chosen — listActiveForUser()\'s own is_active filter excludes it by construction (D6)',
        );

        // Repeated, direct RouterService calls must also never select the
        // deactivated agent — proving the exclusion holds structurally,
        // not just for this one particular run.
        for ($i = 0; $i < 5; $i++) {
            $decision = app(RouterService::class)->route(
                (string) $this->user->id,
                'I have a question about my billing invoice and a payment that was charged twice.',
            );
            $this->assertNotSame($billingAgent->id, $decision->agentId, "attempt {$i}: the deactivated agent's id must never appear as the winner");
        }

        $message = Message::find($result['message_id']);
        $this->assertNotNull($message);
        $this->assertNotSame($billingAgent->id, $message->agent_id);
    }
}
