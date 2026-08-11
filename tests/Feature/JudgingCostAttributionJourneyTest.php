<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Jobs\RunEvalCaseJob;
use ClarionApp\LlmClient\Models\ModelPrice;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\EvalCaseExecutor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * spec.md US4 Acceptance Scenarios 1-2, through real HTTP endpoints: an
 * operator can see what rubric-based judging itself consumed for a run —
 * cost, tokens, invocation count — kept visibly separate from what the
 * agent under test consumed for the same run, incurred only for the cases
 * actually configured to use rubric judging.
 *
 * Two suites are driven to completion in this file, each with 6 cases and
 * identical "given"/expected_behavior wording per case number so the agent
 * fixture provider answers every case identically regardless of which
 * suite it belongs to. Only 2 of the 6 cases in the first suite carry a
 * rubric_judgment expectation (one each); the second suite is otherwise
 * identical but uses only text_match expectations everywhere, so it never
 * invokes the judge at all. Comparing the two runs' consumption.total_cost
 * proves the agent-under-test's own figure is unaffected by whether
 * judging happened at all for the same underlying case work (FR-011).
 *
 * Only the LlmProvider itself is faked (no real HTTP), with two distinct
 * fixture servers so the agent's own inference calls and the judge's calls
 * can be told apart and priced independently — the RubricJudgmentJourneyTest
 * precedent, extended here with priced ModelPrice fixtures (the
 * RunConsumptionJourneyTest precedent) so both figures are provably
 * non-zero rather than merely present.
 */
class JudgingCostAttributionJourneyTest extends TestCase
{
    private const CASE_COUNT = 6;
    private const AGENT_TOKENS_PER_CASE = 15;
    private const JUDGE_TOKENS_PER_CASE = 40;

    /** 1-indexed case numbers that carry a rubric_judgment expectation in the "judged" suite. */
    private const RUBRIC_CASE_NUMBERS = [1, 2];

    private User $operator;
    private Server $agentServer;
    private Server $judgeServer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->declareSupportingSchema();

        $this->operator = User::factory()->create();
        config(['llm-client.cost.operator_user_ids' => [$this->operator->id]]);

        $this->agentServer = Server::create([
            'name' => 'Judging cost journey agent server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'provider_type' => 'openai',
        ]);

        $this->judgeServer = Server::create([
            'name' => 'Judging cost journey judge server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'provider_type' => 'openai',
        ]);

        RoleAssignment::create([
            'role' => 'inference',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $this->agentServer->id,
            'model' => 'judging-cost-agent-model',
        ]);

        RoleAssignment::create([
            'role' => 'judge',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $this->judgeServer->id,
            'model' => 'judging-cost-judge-model',
        ]);

        // Priced models for both roles — without this, every UsageRecord
        // would be cost_unpriced with a zero total_cost, and this test
        // could never observe a genuinely non-zero
        // consumption.judging.total_cost regardless of whether
        // EvalRunConsumptionQuery::summarizeJudging() is correct (the
        // RunConsumptionJourneyTest / CostRollupJourneyTest precedent).
        ModelPrice::create([
            'provider_type' => 'openai',
            'model' => 'judging-cost-agent-model',
            'reused_input_rate' => '0.30000000',
            'fresh_input_rate' => '3.00000000',
            'output_rate' => '15.00000000',
            'effective_from' => Carbon::now()->subDay(),
            'effective_until' => null,
        ]);

        ModelPrice::create([
            'provider_type' => 'openai',
            'model' => 'judging-cost-judge-model',
            'reused_input_rate' => '0.30000000',
            'fresh_input_rate' => '3.00000000',
            'output_rate' => '15.00000000',
            'effective_from' => Carbon::now()->subDay(),
            'effective_until' => null,
        ]);

        $this->fakeProviders();
        $this->seedApiDocsCache();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->seedApiDocsCache(null);

        DB::table('usage_records')->delete();
        DB::table('tool_invocation_records')->delete();
        DB::table('model_prices')->delete();
        DB::table('eval_judgments')->delete();
        DB::table('eval_case_results')->delete();
        DB::table('eval_run_cases')->delete();
        DB::table('eval_runs')->delete();
        DB::table('eval_case_versions')->delete();
        DB::table('eval_cases')->delete();
        DB::table('eval_suites')->delete();
        DB::table('messages')->delete();
        DB::table('conversations')->delete();
        DB::table('llm_role_assignments')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Fixture helpers
    // ---------------------------------------------------------------

    private function suitesBase(): string
    {
        return '/api/clarion-app/llm-client/agent-eval-suites';
    }

    private function runsBase(): string
    {
        return '/api/clarion-app/llm-client/eval-runs';
    }

    /**
     * AgentLoopService::run() consults ConversationCondenser on every
     * call, unconditionally — the RubricJudgmentJourneyTest/
     * RunConsumptionJourneyTest precedent, needed by every eval-run test
     * that drives a case job's real handle().
     */
    private function declareSupportingSchema(): void
    {
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

    private function seedApiDocsCache(?array $doc = ['paths' => []]): void
    {
        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, $doc);
    }

    private function agentChatResponse(string $content): array
    {
        return [
            'choices' => [['message' => ['content' => $content]]],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
                'total_tokens' => self::AGENT_TOKENS_PER_CASE,
            ],
            'model' => 'judging-cost-agent-model',
        ];
    }

    private function judgeChatResponse(): array
    {
        return [
            'choices' => [['message' => ['content' => json_encode([
                'score' => 8,
                'justification' => 'The response directly states a number, matching the criteria.',
            ])]]],
            'usage' => [
                'prompt_tokens' => 30,
                'completion_tokens' => 10,
                'total_tokens' => self::JUDGE_TOKENS_PER_CASE,
            ],
            'model' => 'judging-cost-judge-model',
        ];
    }

    /**
     * Two distinct fixture providers, resolved per-server, exactly the
     * RubricJudgmentJourneyTest precedent: the agent's response is driven
     * by the case number embedded in the given text (so text_match cases
     * pass identically in both suites); the judge always returns a fixed,
     * well-formed score/justification.
     */
    private function fakeProviders(): void
    {
        Http::fake();

        $agentProvider = Mockery::mock(LlmProvider::class);
        $agentProvider->shouldReceive('chat')->andReturnUsing(function (array $messages) {
            $firstUser = collect($messages)->firstWhere('role', 'user')['content'] ?? '';

            if (preg_match('/Case number (\d+)/', $firstUser, $m)) {
                return $this->agentChatResponse($m[1]);
            }

            return $this->agentChatResponse('Acknowledged.');
        });
        $agentProvider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $judgeProvider = Mockery::mock(LlmProvider::class);
        $judgeProvider->shouldReceive('chat')->andReturnUsing(fn () => $this->judgeChatResponse());
        $judgeProvider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $agentServerId = $this->agentServer->id;
        $judgeServerId = $this->judgeServer->id;

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturnUsing(
            function (Server $server) use ($judgeServerId, $agentProvider, $judgeProvider) {
                return $server->id === $judgeServerId ? $judgeProvider : $agentProvider;
            }
        );
        $registry->shouldReceive('resolveByType')->andReturn($agentProvider);
        $this->app->instance(ProviderRegistry::class, $registry);
    }

    /**
     * Builds a suite of self::CASE_COUNT cases, all sharing the identical
     * "Case number {i}: ..." given text across every call site so the
     * fixture agent answers case i identically in every suite this file
     * builds. Cases whose 1-indexed number is in $rubricCaseNumbers get a
     * single rubric_judgment expectation; every other case gets a single
     * text_match expectation that the fixed agent response always
     * satisfies.
     *
     * @param  array<int, int>  $rubricCaseNumbers
     */
    private function createSuite(string $name, array $rubricCaseNumbers): string
    {
        $suiteId = $this->actingAs($this->operator)->postJson($this->suitesBase(), [
            'name' => $name,
            'agent_identifier' => 'home-automation-agent',
        ])->assertStatus(200)->json('id');

        for ($i = 1; $i <= self::CASE_COUNT; $i++) {
            $expectations = in_array($i, $rubricCaseNumbers, true)
                ? [['kind' => 'rubric_judgment', 'criteria' => 'The response must directly state a number.']]
                : [['kind' => 'text_match', 'expected_text' => (string) $i]];

            $this->actingAs($this->operator)->postJson($this->suitesBase().'/'.$suiteId.'/cases', [
                'given' => "Case number {$i}: what is your favorite number?",
                'expected_behavior' => "Answer with the number {$i}.",
                'expectations' => $expectations,
            ])->assertStatus(200);
        }

        return $suiteId;
    }

    private function startRun(string $suiteId): array
    {
        Bus::fake([RunEvalCaseJob::class]);

        return $this->actingAs($this->operator)
            ->postJson($this->suitesBase().'/'.$suiteId.'/runs')
            ->assertStatus(201)
            ->json();
    }

    private function driveDispatchedCaseJobsToCompletion(): void
    {
        foreach (Bus::dispatched(RunEvalCaseJob::class) as $job) {
            $job->handle(app(EvalCaseExecutor::class));
        }
    }

    private function getRun(string $runId): array
    {
        return $this->actingAs($this->operator)->getJson($this->runsBase().'/'.$runId)->assertStatus(200)->json();
    }

    // ---------------------------------------------------------------
    // Scenarios 1-2 / FR-011, FR-012, SC-005, SC-006: judging consumption
    // is scoped to exactly the cases that opted into rubric judging, shown
    // separately, and never inflates the agent-under-test's own figure.
    // ---------------------------------------------------------------

    #[Test]
    public function judging_consumption_reflects_only_the_rubric_judged_cases_and_leaves_the_agents_own_total_cost_unchanged(): void
    {
        // The "judged" run: 2 of 6 cases carry a rubric_judgment
        // expectation.
        $judgedSuiteId = $this->createSuite('Judging cost journey (judged)', self::RUBRIC_CASE_NUMBERS);
        $judgedRun = $this->startRun($judgedSuiteId);
        $this->driveDispatchedCaseJobsToCompletion();
        $judgedResult = $this->getRun($judgedRun['id']);

        // The control run: an otherwise-identical suite (same 6
        // "given"/expected_behavior cases, same fixed agent responses) with
        // no rubric_judgment expectations anywhere — the judge is never
        // invoked at all.
        $controlSuiteId = $this->createSuite('Judging cost journey (control)', []);
        $controlRun = $this->startRun($controlSuiteId);
        $this->driveDispatchedCaseJobsToCompletion();
        $controlResult = $this->getRun($controlRun['id']);

        $this->assertSame('completed', $judgedResult['status']);
        $this->assertSame('completed', $controlResult['status']);
        $this->assertSame(self::CASE_COUNT, $judgedResult['completed_count']);
        $this->assertSame(self::CASE_COUNT, $controlResult['completed_count']);

        // --- FR-012/SC-005: judging invocation count is exactly the 2
        // cases configured to use rubric judging, not all 6 cases in the
        // run. ---
        $this->assertArrayHasKey('judging', $judgedResult['consumption'], 'consumption.judging must always be present (contracts §1.4), even before this feature is implemented it must appear as a key');
        $this->assertSame(
            count(self::RUBRIC_CASE_NUMBERS),
            $judgedResult['consumption']['judging']['invocation_count'],
            'judging invocation_count must equal exactly the number of rubric-judged cases, not the total case count'
        );

        // --- judging cost/tokens are non-zero and reflect only the 2
        // judge calls (self::JUDGE_TOKENS_PER_CASE each). ---
        $this->assertSame(
            count(self::RUBRIC_CASE_NUMBERS) * self::JUDGE_TOKENS_PER_CASE,
            $judgedResult['consumption']['judging']['total_tokens']
        );
        $this->assertGreaterThan(0.0, (float) $judgedResult['consumption']['judging']['total_cost']);
        $this->assertArrayHasKey('cost_unpriced', $judgedResult['consumption']['judging']);
        $this->assertFalse($judgedResult['consumption']['judging']['cost_unpriced']);

        // --- The control run's own judging figures are all-zero — an
        // explicit object, never an absent key (contracts §1.4). ---
        $this->assertArrayHasKey('judging', $controlResult['consumption']);
        $this->assertSame(0, $controlResult['consumption']['judging']['invocation_count']);
        $this->assertSame(0, $controlResult['consumption']['judging']['total_tokens']);
        $this->assertSame('0.0000000000', $controlResult['consumption']['judging']['total_cost']);

        // --- FR-011/SC-006: the agent-under-test's own top-level
        // consumption.total_cost is unchanged by whether judging happened
        // at all for the same underlying 6-case work — both runs invoke
        // the agent identically (self::AGENT_TOKENS_PER_CASE per case,
        // priced with the same ModelPrice), so their agent-side totals
        // must match exactly, byte for byte. ---
        $this->assertSame(
            self::CASE_COUNT * self::AGENT_TOKENS_PER_CASE,
            $judgedResult['consumption']['total_tokens']
        );
        $this->assertSame(
            $controlResult['consumption']['total_tokens'],
            $judgedResult['consumption']['total_tokens'],
            "the judged run's agent-side total_tokens must equal the control run's — judging consumption is never folded into it"
        );
        $this->assertSame(
            $controlResult['consumption']['total_cost'],
            $judgedResult['consumption']['total_cost'],
            "the judged run's agent-side total_cost must equal the control run's, byte for byte — rubric judging must never silently inflate the agent-under-test's own figure"
        );
    }
}
