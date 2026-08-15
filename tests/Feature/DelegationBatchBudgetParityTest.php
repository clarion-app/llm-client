<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\ModelPrice;
use ClarionApp\LlmClient\Models\RateLimit;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentHelperService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\DelegationService;
use ClarionApp\LlmClient\Services\RateLimitCounter;
use ClarionApp\LlmClient\Services\RateLimitService;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\Services\SpendingCeilingService;
use ClarionApp\LlmClient\ValueObjects\BudgetScope;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use ClarionApp\LlmClient\ValueObjects\RateLimitScope;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 101-parallel-subagent-execution, Phase 6 (US5), tasks.md T034.
 *
 * FR-008/SC-005: a batch member goes through the exact same
 * RateLimitGate::admit()/BudgetGate::admit() admission a sequential
 * delegation already goes through (research.md D5) -- no batch-aware
 * branch, discount, or separate allowance anywhere in that chain. Unlike
 * every other test file in this feature, AgentLoopService is deliberately
 * NEVER mocked here (contrast ParallelDelegationJourneyTest's own
 * `Mockery::mock(AgentLoopService::class)` throughout) -- admitInteractiveWork()
 * is the very first statement inside the real run() (grounding note item
 * 14), so mocking run() away would mock away the exact code path this file
 * exists to exercise. Instead, only the outbound LLM call is faked, at the
 * ProviderRegistry level, exactly as EntryPathCoverageJourneyTest already
 * establishes for driving a real admission-gated turn end to end.
 *
 * A second, less obvious substitution this file makes deliberately: rather
 * than letting delegateBatch() dispatch RunDelegationBatchMemberJob onto a
 * real async queue (out of reach for a PHPUnit process), the queue
 * connection is left at the ordinary 'sync' default -- but with one
 * addition no other test in this package needs. Laravel's real
 * queue:work Worker calls $app->forgetScopedInstances() between every job
 * (QueueServiceProvider::registerWorker()'s $resetScope closure) --
 * research.md D5's own flagged nuance is that this is *why* a batch member
 * gets a fresh RateLimitGate/BudgetGate instance rather than reusing the
 * per-instance admission memo a same-request nested call might hit. The
 * 'sync' driver bypasses Worker entirely (SyncQueue::executeJob() calls
 * the job directly), so it does not reproduce that reset on its own -- an
 * uncorrected 'sync' queue would let three batch members share one scoped
 * container and, via the very memo research.md D5 discusses, admit all
 * three for the price of one. That would be a false failure of THIS
 * TEST'S OWN HARNESS, not the production defect FR-008 cares about (a
 * real worker always resets). SyncQueue does fire the same JobProcessed
 * event a real worker's own job loop fires, so setUp() below listens for
 * it and calls forgetScopedInstances() itself -- reproducing, for the
 * 'sync' driver, exactly the boundary a real queue worker already
 * guarantees for free.
 *
 * Every delegation in this file targets a brand-new, ephemeral helper
 * Conversation with zero prior messages (DelegationService::createDelegationRow()
 * always creates one fresh) -- so CostEstimator::estimate()'s own input-token
 * component is always exactly zero at admission time, and the reservation
 * estimate for every single call in this file is the same deterministic
 * figure: config('llm-client.budget.reservation.estimated_output_tokens_default')
 * (1000) output tokens priced at the configured ModelPrice output_rate,
 * with no input-token contribution at all. That determinism is what makes
 * scenario 13's exact 2-admitted/1-refused boundary reproducible rather
 * than approximate.
 */
class DelegationBatchBudgetParityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00', 'UTC'));
        config(['llm-client.run_trace.enabled' => true]);
        config(['queue.default' => 'sync']);

        $this->createSupportingTables();
        $this->seedOperationCatalog();

        // 064-model-setup-interface rewrote several provider call sites
        // (OpenAIGenerateConversationTitleRequest among them) to issue raw
        // HTTP requests directly rather than through ProviderRegistry -- a
        // brand-new conversation's first exchange fires an untitled-title
        // generation request on this separate path (DelegationService's own
        // helperRunIdFor() docblock notes this exact "second, system
        // initiated run" side effect). Faking the ProviderRegistry-routed
        // chat call alone (fakeProviderRegistry(), below) leaves this one
        // making a genuine outbound connection -- Http::fake() catches it,
        // matching EntryPathCoverageJourneyTest's own established setUp().
        \Illuminate\Support\Facades\Http::fake([
            '*' => \Illuminate\Support\Facades\Http::response([
                'choices' => [['message' => ['content' => 'Untitled conversation']]],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ], 200),
        ]);

        // See the class docblock: reproduces, for the 'sync' queue driver,
        // the scoped-container reset a real queue worker already performs
        // between every job (QueueServiceProvider::registerWorker()'s own
        // $resetScope closure) -- without this, every batch member in this
        // file's own tests would share one RateLimitGate/BudgetGate
        // instance and its per-instance admission memo, which is a defect
        // of testing under 'sync' rather than of the production code this
        // file exists to prove correct.
        Event::listen(JobProcessed::class, function () {
            $this->app->forgetScopedInstances();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('agent_delegations')->delete();
        DB::table('messages')->delete();
        if (Schema::hasTable('mcp_sessions')) {
            DB::table('mcp_sessions')->delete();
        }
        if (Schema::hasTable('agent_run_actions')) {
            DB::table('agent_run_actions')->delete();
        }
        if (Schema::hasTable('agent_run_steps')) {
            DB::table('agent_run_steps')->delete();
        }
        if (Schema::hasTable('agent_runs')) {
            DB::table('agent_runs')->delete();
        }
        if (Schema::hasTable('cost_reservations')) {
            DB::table('cost_reservations')->delete();
        }
        if (Schema::hasTable('budget_reservation_ledger')) {
            DB::table('budget_reservation_ledger')->delete();
        }
        DB::table('cost_summaries')->delete();
        DB::table('spending_ceilings')->delete();
        DB::table('rate_limits')->delete();
        DB::table('model_prices')->delete();
        DB::table('usage_records')->delete();
        DB::table('agent_helper_assignments')->delete();
        DB::table('llm_role_assignments')->delete();
        DB::table('conversations')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Fixture scaffolding (mirrors ParallelDelegationJourneyTest's own
    // established recipe for an agent-bound conversation with helpers)
    // -----------------------------------------------------------------

    private function createSupportingTables(): void
    {
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

    private function seedOperationCatalog(): void
    {
        $doc = ['paths' => []];

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
     * Fakes only the outbound LLM call (ProviderRegistry level), never
     * AgentLoopService itself -- see the class docblock. Every delegated
     * helper's turn returns immediately, in one iteration, with content
     * that satisfies the mandatory delegation_result schema
     * (DelegationResultPreset) so runDelegatedTask() maps it to a genuine
     * 'completed'/'success' Delegation with no retry.
     */
    private function fakeProviderRegistry(int $promptTokens, int $completionTokens): void
    {
        $provider = Mockery::mock(LlmProvider::class);
        // 'output' must serialize as a JSON OBJECT ({}), never a JSON array
        // ([]) -- DelegationResultPreset's schema declares it type: object,
        // and PHP's json_encode([]) produces [], which the schema validator
        // correctly rejects as the wrong type (a real, easy-to-hit mistake,
        // not schema pedantry: (object) [] forces the {} encoding).
        $provider->shouldReceive('chat')->andReturn([
            'choices' => [['message' => ['content' => json_encode([
                'status' => 'success',
                'summary' => 'Task complete.',
                'output' => (object) [],
                'undone' => '',
            ])]]],
            'usage' => [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $promptTokens + $completionTokens,
            ],
        ]);
        $provider->shouldReceive('embed')->andReturn(['embeddings' => [[0.1, 0.2, 0.3]]]);
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);
        $this->app->instance(ProviderRegistry::class, $registry);
    }

    private function makeAgent(User $owner, string $name): Agent
    {
        return app(AgentService::class)->create($owner->id, "name: {$name}\ninstructions: I am {$name}.");
    }

    private function makeConversation(User $owner, Server $server, ?Agent $agent): Conversation
    {
        return Conversation::factory()->create([
            'user_id' => $owner->id,
            'server_id' => $server->id,
            'model' => 'test-model',
            'title' => 'Already titled',
            'agent_id' => $agent?->id,
            'agent_version_id' => $agent?->current_version_id,
        ]);
    }

    private function delegateCall(string $toolCallId, string $helperAgentId, string $task): array
    {
        return [
            'tool_call_id' => $toolCallId,
            'helper_agent_id' => $helperAgentId,
            'task' => $task,
            'context' => null,
        ];
    }

    /**
     * A fresh "installation" for this scenario: one user, its own Server,
     * inference role pointing at it, a per-user rate limit generous enough
     * to never itself be the refusing axis, one parent Agent with N
     * assigned helper Agents, and a conversation bound to the parent.
     */
    private function makeInstallation(int $helperCount, string $label): array
    {
        $user = User::factory()->create();

        $server = Server::create([
            'name' => "Test Server {$label}",
            'server_url' => 'https://api.example.test/v1/chat/completions',
            'token' => 'sk-test',
            'provider_type' => 'openai',
        ]);

        app(RoleAssignmentService::class)->set(ModelRole::Inference, $user->id, $server->id, 'test-model');

        // Generous: never the refusing axis in either scenario below --
        // both scenarios are about BudgetGate, not RateLimitGate.
        app(RateLimitService::class)->upsert(RateLimitScope::UserDefault, RateLimit::INSTALLATION_SCOPE_ID, [
            'max_requests' => 1000,
            'window_seconds' => 3600,
        ]);

        $parent = $this->makeAgent($user, "parent-{$label}");
        $helpers = [];
        for ($i = 0; $i < $helperCount; $i++) {
            $helper = $this->makeAgent($user, "helper-{$label}-{$i}");
            app(AgentHelperService::class)->assign($user->id, $parent->id, $helper->id);
            $helpers[] = $helper;
        }

        return [
            'user' => $user,
            'server' => $server,
            'conversation' => $this->makeConversation($user, $server, $parent),
            'helpers' => $helpers,
        ];
    }

    private function declareCeiling(string $amount): void
    {
        app(SpendingCeilingService::class)->upsert(
            BudgetScope::UserDefault,
            SpendingCeiling::INSTALLATION_SCOPE_ID,
            ['amount' => $amount, 'period_type' => 'month', 'enforcement_mode' => 'stop'],
        );
    }

    private function declarePricing(string $rate): void
    {
        ModelPrice::create([
            'provider_type' => 'openai',
            'model' => 'test-model',
            'reused_input_rate' => $rate,
            'fresh_input_rate' => $rate,
            'output_rate' => $rate,
            'effective_from' => Carbon::now()->subDay(),
            'effective_until' => null,
        ]);
    }

    private function rateLimitCount(string $userId): int
    {
        return app(RateLimitCounter::class)->peek($userId, 3600)->count;
    }

    private function totalSpend(string $userId): string
    {
        $value = DB::table('cost_summaries')
            ->where('entity_type', 'user')
            ->where('entity_id', $userId)
            ->value('priced_cost_total');

        return $value === null ? '0.0000000000' : (string) $value;
    }

    private function reservationCount(string $userId): int
    {
        return DB::table('cost_reservations')->where('user_id', $userId)->count();
    }

    /** A genuinely separate turn: a new HTTP request would resolve a fresh
     *  RateLimitGate/BudgetGate instance -- forgetScopedInstances() is the
     *  same technique EntryPathCoverageJourneyTest's own newRequestBoundary()
     *  helper uses to reproduce that for the sequential ("today's existing
     *  098 path") arm of both scenarios below. */
    private function newRequestBoundary(): void
    {
        $this->app->forgetScopedInstances();
    }

    // =================================================================
    // Scenario 12 (FR-008, SC-005): the same N delegations, once
    // sequentially and once as one concurrent batch, must charge
    // arithmetically identical totals.
    // =================================================================

    #[Test]
    public function scenario_12_sequential_and_concurrent_totals_are_arithmetically_identical(): void
    {
        $this->declareCeiling('1000.00');
        $this->declarePricing('3000.00000000');
        $this->fakeProviderRegistry(promptTokens: 100, completionTokens: 50);

        $sequential = $this->makeInstallation(3, 'seq12');
        $concurrent = $this->makeInstallation(3, 'conc12');

        // Sequential: today's existing 098 path -- N separate turns, one
        // delegate_to_helper call each, each its own request boundary.
        $sequentialStatuses = [];
        foreach ($sequential['helpers'] as $i => $helper) {
            $this->newRequestBoundary();
            $result = app(DelegationService::class)->delegate(
                $sequential['conversation'],
                $helper->id,
                "Sequential task {$i}.",
                null,
            );
            $sequentialStatuses[] = $result['status'] ?? null;
        }

        // Concurrent: one batch of the same N calls in one turn.
        $calls = [];
        foreach ($concurrent['helpers'] as $i => $helper) {
            $calls[] = $this->delegateCall("call_{$i}", $helper->id, "Concurrent task {$i}.");
        }
        $batchResults = app(DelegationService::class)->delegateBatch($concurrent['conversation'], $calls);
        $concurrentStatuses = array_map(fn ($r) => $r['status'] ?? null, array_values($batchResults));

        $this->assertSame(['success', 'success', 'success'], $sequentialStatuses, 'fixture sanity: every sequential delegation must genuinely succeed for this comparison to mean anything');
        $this->assertSame(['success', 'success', 'success'], $concurrentStatuses, 'fixture sanity: every batch member must genuinely succeed for this comparison to mean anything');

        $sequentialRows = Delegation::where('owner_user_id', $sequential['user']->id)->get();
        $concurrentRows = Delegation::where('owner_user_id', $concurrent['user']->id)->get();
        $this->assertCount(3, $sequentialRows);
        $this->assertCount(3, $concurrentRows);
        $this->assertCount(1, $concurrentRows->pluck('batch_id')->unique(), 'the concurrent arm must genuinely be one batch');
        $this->assertNull($sequentialRows->pluck('batch_id')->unique()->first(), 'the sequential arm must never carry a batch_id');

        $sequentialRateCount = $this->rateLimitCount((string) $sequential['user']->id);
        $concurrentRateCount = $this->rateLimitCount((string) $concurrent['user']->id);

        $this->assertSame(3, $sequentialRateCount, 'three real admissions must consume three units of the sequential user\'s rate limit');
        $this->assertSame(
            $sequentialRateCount,
            $concurrentRateCount,
            'FR-008/SC-005: running the same delegations concurrently must never be counted differently against RateLimitGate than running them sequentially',
        );

        $sequentialSpend = $this->totalSpend((string) $sequential['user']->id);
        $concurrentSpend = $this->totalSpend((string) $concurrent['user']->id);

        $this->assertNotSame('0.0000000000', $sequentialSpend, 'fixture sanity: real priced usage must have been recorded');
        $this->assertSame(
            0,
            bccomp($sequentialSpend, $concurrentSpend, 10),
            'FR-008/SC-005: the total BudgetGate-recorded spend for the same N delegations must be arithmetically identical whether run sequentially or as one concurrent batch -- not merely both under ceiling',
        );
    }

    // =================================================================
    // Scenario 13 (US5 AC2): a ceiling that admits exactly two of three
    // delegations must refuse the third the same way in both arms -- no
    // "concurrent work admitted for free," no all-or-nothing refusal.
    // =================================================================

    #[Test]
    public function scenario_13_a_ceiling_with_room_for_exactly_two_refuses_the_third_identically_in_both_arms(): void
    {
        // Every admission's reservation ESTIMATE is the same fixed figure
        // regardless of task text (class docblock: input tokens are always
        // zero for a brand-new helper conversation) --
        // 1000 default output tokens * 3000/1e6 = 3.0 exactly. Real
        // recorded cost per completed call, from the fixed usage below, is
        // 500 tokens * 3000/1e6 = 1.5 exactly. A ceiling of 4.5 therefore
        // admits call 1 (projected 0 + 3.0 = 3.0), admits call 2 (projected
        // 1.5 + 3.0 = 4.5, the boundary itself, <=), and refuses call 3
        // (projected 3.0 + 3.0 = 6.0) -- identically in both arms, because
        // each call in this harness fully resolves (including
        // reconciliation) before the next one's admission check runs, in
        // BOTH the sequential and the sync-driven "concurrent" arm alike.
        $this->declareCeiling('4.50');
        $this->declarePricing('3000.00000000');
        $this->fakeProviderRegistry(promptTokens: 300, completionTokens: 200);

        $sequential = $this->makeInstallation(3, 'seq13');
        $concurrent = $this->makeInstallation(3, 'conc13');

        $sequentialStatuses = [];
        foreach ($sequential['helpers'] as $i => $helper) {
            $this->newRequestBoundary();
            $result = app(DelegationService::class)->delegate(
                $sequential['conversation'],
                $helper->id,
                "Sequential task {$i}.",
                null,
            );
            $sequentialStatuses[] = $result['status'] ?? null;
        }

        $calls = [];
        foreach ($concurrent['helpers'] as $i => $helper) {
            $calls[] = $this->delegateCall("call_{$i}", $helper->id, "Concurrent task {$i}.");
        }
        $batchResults = app(DelegationService::class)->delegateBatch($concurrent['conversation'], $calls);
        $concurrentStatuses = array_map(fn ($r) => $r['status'] ?? null, array_values($batchResults));

        $this->assertSame(
            ['success', 'success', 'failure'],
            $sequentialStatuses,
            'the 3rd of 3 sequential delegations must be refused by the ceiling exactly like today',
        );
        $this->assertSame(
            $sequentialStatuses,
            $concurrentStatuses,
            'US5 AC2: the batch\'s own per-member admission-time budget check must refuse the 3rd member the same way 3 sequential calls would have -- no partial "2 admitted for free because concurrent", no all-or-nothing refusal of the whole batch',
        );

        $sequentialRows = Delegation::where('owner_user_id', $sequential['user']->id)->get();
        $concurrentRows = Delegation::where('owner_user_id', $concurrent['user']->id)->get();

        $this->assertSame(2, $sequentialRows->where('status', 'completed')->count());
        $this->assertSame(1, $sequentialRows->where('status', 'failed')->count());
        $this->assertSame(2, $concurrentRows->where('status', 'completed')->count());
        $this->assertSame(1, $concurrentRows->where('status', 'failed')->count());

        $sequentialReservations = $this->reservationCount((string) $sequential['user']->id);
        $concurrentReservations = $this->reservationCount((string) $concurrent['user']->id);

        $this->assertSame(2, $sequentialReservations, 'exactly two of three admission attempts must have genuinely placed a reservation');
        $this->assertSame(
            $sequentialReservations,
            $concurrentReservations,
            'the number of real reservations placed must be identical between the two arms -- not 3 in the batch (a discount) and not 0 (an all-or-nothing refusal)',
        );
    }
}
