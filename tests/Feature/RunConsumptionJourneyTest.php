<?php

namespace ClarionApp\LlmClient\Tests\Feature;

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
 * spec.md US4 Acceptance Scenarios 1-2 and quickstart.md step 5: an
 * operator can see what a run has consumed — cost, tokens — both while it
 * is still underway and after it completes, and a second, independent run
 * never has its own consumption summed with another's (research.md D11,
 * proven at the unit level by EvalRunConsumptionQueryTest, proven here
 * end-to-end through the real GET /eval-runs/{id} response).
 *
 * Each driven case in this file's fixtures makes exactly one LLM call with
 * a fixed, known usage shape (15 total tokens), so completed_count and
 * consumed tokens can be compared 1:1 without needing to know
 * EvalRunConsumptionQuery's internals.
 */
class RunConsumptionJourneyTest extends TestCase
{
    private const CASE_COUNT = 6;
    private const TOKENS_PER_CASE = 15;

    private User $operator;
    private string $suiteId;

    protected function setUp(): void
    {
        parent::setUp();

        // AgentLoopService::run() (driven here for real, via
        // EvalCaseExecutor) consults ConversationCondenser on every call —
        // the RunSuiteJourneyTest/RunProgressVisibilityJourneyTest
        // precedent, needed by every eval-run test that drives a case
        // job's real handle().
        if (!Schema::hasTable('condensation_states')) {
            Schema::create('condensation_states', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id')->unique();
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->timestamp('cooldown_until')->nullable();
                $table->timestamps();
            });
        }

        $this->operator = User::factory()->create();
        config(['llm-client.cost.operator_user_ids' => [$this->operator->id]]);

        $server = Server::create([
            'name' => 'Consumption journey test server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'provider_type' => 'openai',
        ]);

        RoleAssignment::create([
            'role' => 'inference',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $server->id,
            'model' => 'test-model',
        ]);

        // A priced model — without this, every UsageRecord this run
        // produces would be cost_unpriced with a zero total_cost, and
        // FR-011's "non-zero consumption.total_cost" could never be
        // observed regardless of whether EvalRunConsumptionQuery is
        // correct (the CostRollupJourneyTest precedent).
        ModelPrice::create([
            'provider_type' => 'openai',
            'model' => 'test-model',
            'reused_input_rate' => '0.30000000',
            'fresh_input_rate' => '3.00000000',
            'output_rate' => '15.00000000',
            'effective_from' => Carbon::now()->subDay(),
            'effective_until' => null,
        ]);

        $this->fakeProvider();

        $this->suiteId = $this->createSuiteWithCases(self::CASE_COUNT);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        DB::table('usage_records')->delete();
        DB::table('tool_invocation_records')->delete();
        DB::table('model_prices')->delete();
        DB::table('eval_case_results')->delete();
        DB::table('eval_run_cases')->delete();
        DB::table('eval_runs')->delete();
        DB::table('eval_case_versions')->delete();
        DB::table('eval_cases')->delete();
        DB::table('eval_suites')->delete();
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

    private function createSuiteWithCases(int $count): string
    {
        $suite = $this->actingAs($this->operator)->postJson($this->suitesBase(), [
            'name' => 'Consumption journey fixture suite',
            'agent_identifier' => 'home-automation-agent',
        ])->assertStatus(200)->json();

        for ($i = 1; $i <= $count; $i++) {
            $this->actingAs($this->operator)->postJson($this->suitesBase().'/'.$suite['id'].'/cases', [
                'given' => "Case number {$i}: what is your favorite number?",
                'expected_behavior' => "Answer with the number {$i}.",
                'expectations' => [['kind' => 'text_match', 'expected_text' => (string) $i]],
            ])->assertStatus(200);
        }

        return $suite['id'];
    }

    private function fakeProvider(): void
    {
        Http::fake();

        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function (array $messages, array $tools = [], array $options = []) {
            $firstUser = collect($messages)->firstWhere('role', 'user')['content'] ?? '';

            if (preg_match('/Case number (\d+)/', $firstUser, $m)) {
                return $this->textChatResponse($m[1]);
            }

            return $this->textChatResponse('Acknowledged.');
        });
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);
        $this->app->instance(ProviderRegistry::class, $registry);
    }

    private function textChatResponse(string $content): array
    {
        return [
            'choices' => [['message' => ['content' => $content]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => self::TOKENS_PER_CASE],
        ];
    }

    /**
     * Starts a run with dispatch captured (not executed) by Bus::fake() —
     * the RunSuiteJourneyTest/RunProgressVisibilityJourneyTest precedent.
     */
    private function startRun(string $suiteId): array
    {
        Bus::fake([RunEvalCaseJob::class]);

        return $this->actingAs($this->operator)
            ->postJson($this->suitesBase().'/'.$suiteId.'/runs')
            ->assertStatus(201)
            ->json();
    }

    private function driveOnly(int $count): void
    {
        $jobs = Bus::dispatched(RunEvalCaseJob::class)->all();

        foreach (array_slice($jobs, 0, $count) as $job) {
            $job->handle(app(EvalCaseExecutor::class));
        }
    }

    private function driveAll(): void
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
    // Scenario 1 — consumption is visible and non-zero while the run is
    // still in_progress, reflecting only the cases completed so far
    // ---------------------------------------------------------------

    #[Test]
    public function mid_run_consumption_is_non_zero_and_reflects_only_the_cases_completed_so_far(): void
    {
        $started = $this->startRun($this->suiteId);
        $this->driveOnly(3);

        $run = $this->getRun($started['id']);

        $this->assertArrayHasKey('consumption', $run, 'the run detail response must carry a consumption key while in_progress (FR-011)');
        $this->assertSame('in_progress', $run['status']);
        $this->assertSame(3, $run['completed_count']);

        $this->assertSame(
            3 * self::TOKENS_PER_CASE,
            $run['consumption']['total_tokens'],
            'mid-run consumption must reflect exactly the 3 cases driven so far, not all 6'
        );
        $this->assertGreaterThan(0, (float) $run['consumption']['total_cost']);
        $this->assertArrayHasKey('tool_invocation_count', $run['consumption']);
        $this->assertArrayHasKey('total_duration_ms', $run['consumption']);
        $this->assertArrayHasKey('cost_currency', $run['consumption']);
        $this->assertFalse($run['consumption']['cost_unpriced'], 'a priced ModelPrice fixture is configured for every case in this suite');
    }

    // ---------------------------------------------------------------
    // Scenario 2 — once the run finishes, consumption reflects the full
    // run and neither resets nor double-counts relative to the mid-run
    // read (FR-011, SC-003)
    // ---------------------------------------------------------------

    #[Test]
    public function post_run_consumption_reflects_the_full_run_without_resetting_or_double_counting_the_mid_run_figures(): void
    {
        $started = $this->startRun($this->suiteId);
        $this->driveOnly(3);
        $midRun = $this->getRun($started['id']);
        $this->assertArrayHasKey('consumption', $midRun, 'the run detail response must carry a consumption key while in_progress (FR-011)');
        $midRunTokens = $midRun['consumption']['total_tokens'];
        $midRunCost = (float) $midRun['consumption']['total_cost'];

        // driveOnly($count) (see its docblock precedent in
        // RunProgressVisibilityJourneyTest) always re-slices from the start
        // of the full dispatched-job list, so calling driveOnly(3) a second
        // time would just re-run the same first 3 jobs (an idempotent
        // no-op) rather than reach the run's other 3 cases. driveAll() runs
        // every dispatched job — the already-completed 3 are a harmless
        // no-op via EvalCaseExecutor's idempotency guard, and the remaining
        // 3 actually execute, reaching all 6.
        $this->driveAll();
        $finished = $this->getRun($started['id']);

        $this->assertSame('completed', $finished['status']);
        $this->assertSame(self::CASE_COUNT, $finished['completed_count']);
        $this->assertArrayHasKey('consumption', $finished, 'the run detail response must carry a consumption key once completed (FR-011)');

        $this->assertSame(
            self::CASE_COUNT * self::TOKENS_PER_CASE,
            $finished['consumption']['total_tokens'],
            'the final total must be exactly all 6 cases worth of tokens — neither reset to the mid-run figure nor double-counted past it'
        );
        $this->assertGreaterThan(
            $midRunTokens,
            $finished['consumption']['total_tokens'],
            'consumption must not have reset when the run finished'
        );
        $this->assertGreaterThan(
            $midRunCost,
            (float) $finished['consumption']['total_cost'],
            'cost must have grown, not reset, as the remaining cases completed'
        );
    }

    // ---------------------------------------------------------------
    // Scenario — a second, independent run's consumption is scoped to
    // itself, never summed with the first run's (research.md D11, proven
    // end-to-end through the real HTTP response)
    // ---------------------------------------------------------------

    #[Test]
    public function a_second_independent_runs_consumption_is_scoped_to_itself_not_summed_with_the_first_runs(): void
    {
        $firstStarted = $this->startRun($this->suiteId);
        $this->driveAll();
        $firstFinished = $this->getRun($firstStarted['id']);
        $this->assertArrayHasKey('consumption', $firstFinished, 'the run detail response must carry a consumption key once completed (FR-011)');
        $this->assertSame(self::CASE_COUNT * self::TOKENS_PER_CASE, $firstFinished['consumption']['total_tokens']);

        $secondStarted = $this->startRun($this->suiteId);
        $this->driveAll();
        $secondFinished = $this->getRun($secondStarted['id']);
        $this->assertArrayHasKey('consumption', $secondFinished, 'the run detail response must carry a consumption key once completed (FR-011)');

        $this->assertSame(
            self::CASE_COUNT * self::TOKENS_PER_CASE,
            $secondFinished['consumption']['total_tokens'],
            "the second run's own consumption must equal exactly its own 6 cases worth of tokens, not the sum of both runs"
        );

        // Re-reading the first run afterward must be unaffected by the
        // second run's existence — the same conversation_id-scoping
        // property EvalRunConsumptionQueryTest proves at the unit level.
        $firstAfterSecond = $this->getRun($firstStarted['id']);
        $this->assertSame(
            $firstFinished['consumption']['total_tokens'],
            $firstAfterSecond['consumption']['total_tokens'],
            "the first run's consumption must be unchanged by a second run starting and finishing afterward"
        );
    }
}
