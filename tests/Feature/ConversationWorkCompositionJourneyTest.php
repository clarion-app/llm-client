<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Exceptions\BudgetExceededException;
use ClarionApp\LlmClient\Exceptions\RateLimitExceededException;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\CostSummary;
use ClarionApp\LlmClient\Models\RateLimit;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\ConversationWorkCeilingService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\RateLimitService;
use ClarionApp\LlmClient\Services\SpendingCeilingService;
use ClarionApp\LlmClient\ValueObjects\BudgetScope;
use ClarionApp\LlmClient\ValueObjects\ConversationWorkScope;
use ClarionApp\LlmClient\ValueObjects\RateLimitScope;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * A caller who reads "why did my response end the way it did" must never
 * confuse this feature's own stop with either of its two siblings — the
 * per-user rate limit or the spending ceiling — or with the pre-existing
 * max_iterations stop this feature deliberately leaves untouched. Every one
 * of those four outcomes is triggered here for real, against the shipped
 * code, and compared by direct string/status equality, never by inference.
 */
class ConversationWorkCompositionJourneyTest extends TestCase
{
    private User $user;
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        // Only the real-HTTP sub-case (a container-resolved AgentLoopService,
        // fully wired) reaches this lookup — the manually-constructed
        // instances the other sub-cases use skip it entirely because they
        // omit that optional collaborator.
        if (!Schema::hasTable('condensation_states')) {
            Schema::create('condensation_states', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id')->unique();
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->timestamp('cooldown_until')->nullable();
                $table->timestamps();
            });
        }

        $this->user = User::factory()->create();

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'http://localhost:11434',
            'provider_type' => 'llama_cpp',
        ]);

        Http::fake();
    }

    protected function tearDown(): void
    {
        DB::table('conversation_work_ceilings')->delete();
        DB::table('rate_limits')->delete();
        DB::table('spending_ceilings')->delete();
        DB::table('cost_summaries')->delete();
        DB::table('agent_runs')->delete();

        Mockery::close();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function newConversation(): Conversation
    {
        return Conversation::create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'character' => 'Clarion',
            // Already titled, so a normally-completed turn's first-exchange
            // title generation stays out of the way of what is under test.
            'title' => 'Already titled',
        ]);
    }

    private function toolCallBurst(int $count): array
    {
        $calls = [];
        for ($i = 1; $i <= $count; $i++) {
            $calls[] = [
                'id' => "call_{$i}",
                'type' => 'function',
                'function' => ['name' => 'list_applications', 'arguments' => '{}'],
            ];
        }

        return $calls;
    }

    private function serviceWithScriptedProvider(array $responses): AgentLoopService
    {
        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturn(...$responses);
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);

        return new AgentLoopService(
            app(McpToolRegistry::class),
            app(McpToolExecutor::class),
            app(OperationCache::class),
            $registry,
        );
    }

    private function recordUserSpend(string $userId, string $amount): void
    {
        DB::table('cost_summaries')->insert([
            'id' => (string) Str::uuid(),
            'entity_type' => CostSummary::ENTITY_USER,
            'entity_id' => $userId,
            'user_id' => $userId,
            'period_date' => now()->toDateString(),
            'request_count' => 1,
            'priced_cost_total' => $amount,
            'zero_priced_request_count' => 0,
            'unpriced_request_count' => 0,
            'unpriced_total_tokens' => 0,
            'estimated_request_count' => 0,
            'updated_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------
    // Scenario 1 — every sibling limit's code and HTTP status are
    // mutually distinguishable, each triggered for real
    // ---------------------------------------------------------------

    #[Test]
    public function each_sibling_limit_produces_its_own_distinguishable_code_and_http_status(): void
    {
        // --- This feature's own stop: conversation_work_ceiling_reached / 200 ---
        app(ConversationWorkCeilingService::class)->upsert(
            ConversationWorkScope::ConversationDefault,
            RateLimit::INSTALLATION_SCOPE_ID,
            ['max_work_units' => 1, 'window_seconds' => 60],
        );

        $this->app->forgetScopedInstances();
        $conversationA = $this->newConversation();
        $serviceA = $this->serviceWithScriptedProvider([
            ['choices' => [['message' => ['content' => '', 'tool_calls' => $this->toolCallBurst(2)]]]],
        ]);
        $resultA = $serviceA->run($conversationA, 'Do two things in one go.');

        $this->assertSame('stopped', $resultA['status']);
        $this->assertSame('conversation_work_ceiling_reached', $resultA['code'] ?? null);

        // The same policy stop through the real HTTP controller, to get a
        // genuine, live status code rather than one asserted by reading
        // AgentController's source.
        $providerHttp = Mockery::mock(LlmProvider::class);
        $providerHttp->shouldReceive('chat')->once()->andReturn([
            'choices' => [['message' => ['content' => '', 'tool_calls' => $this->toolCallBurst(2)]]],
        ]);
        $providerHttp->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));
        $registryHttp = Mockery::mock(ProviderRegistry::class);
        $registryHttp->shouldReceive('resolve')->andReturn($providerHttp);
        $registryHttp->shouldReceive('resolveByType')->andReturn($providerHttp);
        $this->app->instance(ProviderRegistry::class, $registryHttp);

        $this->app->forgetScopedInstances();
        $conversationAHttp = $this->newConversation();

        $httpResponse = $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/agent', [
            'message' => 'Do two things over HTTP.',
            'conversation_id' => $conversationAHttp->id,
        ]);
        $statusA = $httpResponse->getStatusCode();
        $this->assertSame('stopped', $httpResponse->json('status'));

        DB::table('conversation_work_ceilings')->delete();

        // --- The per-user rate limit: rate_limit_exceeded / 429 ---
        app(RateLimitService::class)->upsert(
            RateLimitScope::UserDefault,
            RateLimit::INSTALLATION_SCOPE_ID,
            ['max_requests' => 1, 'window_seconds' => 60],
        );

        $this->app->forgetScopedInstances();
        $conversationB = $this->newConversation();
        $serviceB1 = $this->serviceWithScriptedProvider([
            ['choices' => [['message' => ['content' => 'First reply.', 'tool_calls' => []]]]],
        ]);
        $resultB1 = $serviceB1->run($conversationB, 'First message.');
        $this->assertSame(
            'completed',
            $resultB1['status'],
            'The single allowance must be consumed by a genuinely successful request, not assumed'
        );

        $this->app->forgetScopedInstances();

        $codeB = null;
        $statusB = null;

        try {
            $serviceB2 = $this->serviceWithScriptedProvider([
                ['choices' => [['message' => ['content' => 'unused', 'tool_calls' => []]]]],
            ]);
            $serviceB2->run($conversationB, 'Second message, over the allowance.');
            $this->fail('Expected RateLimitExceededException once the single-request allowance was exhausted');
        } catch (RateLimitExceededException $e) {
            $codeB = $e->decision->toArray($e->workKind)['code'];
            $statusB = $e->render(request())->getStatusCode();
        }

        DB::table('rate_limits')->delete();
        // The successful request above genuinely called the (mocked) LLM,
        // so it recorded its own real usage-metric row for today — cleared
        // here so the budget sub-case below can seed its own spend without
        // colliding with it on cost_summaries' (entity, period_date) key.
        DB::table('cost_summaries')->delete();

        // --- The spending ceiling: budget_ceiling_reached / 402 ---
        app(SpendingCeilingService::class)->upsert(
            BudgetScope::UserDefault,
            SpendingCeiling::INSTALLATION_SCOPE_ID,
            ['amount' => '10.00', 'period_type' => 'month', 'enforcement_mode' => 'stop'],
        );
        $this->recordUserSpend((string) $this->user->id, '20.0000000000');

        $this->app->forgetScopedInstances();
        $conversationC = $this->newConversation();

        $codeC = null;
        $statusC = null;

        try {
            $serviceC = $this->serviceWithScriptedProvider([
                ['choices' => [['message' => ['content' => 'unused', 'tool_calls' => []]]]],
            ]);
            $serviceC->run($conversationC, 'Should be refused by the already-exceeded budget ceiling.');
            $this->fail('Expected BudgetExceededException once the ceiling was already exceeded');
        } catch (BudgetExceededException $e) {
            $codeC = $e->decision->toArray($e->workKind)['code'];
            $statusC = $e->render(request())->getStatusCode();
        }

        // --- Codes: no two of the four ever read the same ---
        $this->assertSame('conversation_work_ceiling_reached', $resultA['code']);
        $this->assertSame('rate_limit_exceeded', $codeB);
        $this->assertSame('budget_ceiling_reached', $codeC);

        $this->assertNotSame($resultA['code'], $codeB);
        $this->assertNotSame($resultA['code'], $codeC);
        $this->assertNotSame($codeB, $codeC);

        // --- HTTP status / outcome shape: no two of the three coincide ---
        $this->assertSame(200, $statusA, 'A conversation work ceiling stop is not a server failure and not an admission refusal');
        $this->assertSame(429, $statusB);
        $this->assertSame(402, $statusC);

        $this->assertNotSame($statusA, $statusB);
        $this->assertNotSame($statusA, $statusC);
        $this->assertNotSame($statusB, $statusC);
    }

    // ---------------------------------------------------------------
    // Scenario 2 — the work ceiling and max_iterations are different
    // axes, neither substituting for the other, both live-triggered
    // ---------------------------------------------------------------

    #[Test]
    public function the_work_ceiling_and_max_iterations_are_independent_axes_neither_substitutes_for_the_other(): void
    {
        // Case 1 — with no conversation_work_ceilings row at all, a
        // response that performs exactly one tool call per round-trip runs
        // out the pre-existing max_iterations ceiling on its own, via
        // run() specifically (not resumeSync(), whose own max-iterations
        // return carries no code key at all — an existing asymmetry this
        // feature does not fix, so comparing against it would not exercise
        // a real code value).
        config(['llm-client.agent_loop.max_iterations' => 2]);

        $this->app->forgetScopedInstances();
        $conversationMaxIterations = $this->newConversation();

        $oneToolCallPerTurn = ['choices' => [['message' => ['content' => '', 'tool_calls' => $this->toolCallBurst(1)]]]];
        $serviceMaxIterations = $this->serviceWithScriptedProvider([$oneToolCallPerTurn, $oneToolCallPerTurn]);

        $maxIterationsResult = $serviceMaxIterations->run($conversationMaxIterations, 'Keep going, one small step at a time.');

        $this->assertSame('error', $maxIterationsResult['status']);
        $this->assertSame('max_iterations', $maxIterationsResult['code'] ?? null);

        // Case 2 — a tight conversation_default ceiling, a generous
        // max_iterations restored to its normal default, and a single LLM
        // turn requesting more tool calls than the ceiling allows. The
        // provider mock permits exactly one call: if the work ceiling had
        // not tripped mid-batch, the loop would have needed a second
        // round-trip to keep going, and that unexpected second call would
        // fail this test when Mockery verifies its expectations. The
        // ceiling is therefore proven to stop the response within its
        // first iteration, long before a 20-iteration max_iterations
        // ceiling could ever have mattered.
        config(['llm-client.agent_loop.max_iterations' => 20]);

        app(ConversationWorkCeilingService::class)->upsert(
            ConversationWorkScope::ConversationDefault,
            RateLimit::INSTALLATION_SCOPE_ID,
            ['max_work_units' => 2, 'window_seconds' => 60],
        );

        $this->app->forgetScopedInstances();
        $conversationWorkCeiling = $this->newConversation();

        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')
            ->once()
            ->andReturn(['choices' => [['message' => ['content' => '', 'tool_calls' => $this->toolCallBurst(5)]]]]);
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);

        $serviceWorkCeiling = new AgentLoopService(
            app(McpToolRegistry::class),
            app(McpToolExecutor::class),
            app(OperationCache::class),
            $registry,
        );

        $workCeilingResult = $serviceWorkCeiling->run($conversationWorkCeiling, 'Do five things in one go.');

        $this->assertSame('stopped', $workCeilingResult['status']);
        $this->assertSame('conversation_work_ceiling_reached', $workCeilingResult['code'] ?? null);
        $this->assertNotSame('max_iterations', $workCeilingResult['code'] ?? null);

        // Together, the two cases show the two ceilings compose without
        // overlap: max_iterations alone stops a response that stays within
        // the (unconfigured) work ceiling, and the work ceiling alone stops
        // a response that stays comfortably within a generous
        // max_iterations — each catching a case the other structurally
        // cannot.
        $this->assertNotSame($maxIterationsResult['code'], $workCeilingResult['code']);
    }
}
