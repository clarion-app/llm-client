<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Events\EvalRunUpdated;
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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quality and cost belong in the same view: what a run's results look
 * like is never shown without what that run consumed to produce them,
 * for a run at any status. GET /eval-runs/{runId} already computes and
 * returns this alongside outcome_counts for every status (a run detail
 * consumer, like the run breakdown screen, needs no second call and no
 * separate screen); the same figure is also what a live viewer receives
 * over the private channel, so a pushed update and a direct read can
 * never disagree at the same instant.
 *
 * Each driven case in this file's fixtures makes exactly one LLM call
 * with a fixed, known usage shape, so the growth of consumption across
 * partially- and fully-completed runs can be checked without needing to
 * know the consumption query's own internals.
 */
class EvalDashboardConsumptionVisibilityJourneyTest extends TestCase
{
    private const CASE_COUNT = 4;
    private const TOKENS_PER_CASE = 15;

    private User $operator;
    private string $suiteId;

    protected function setUp(): void
    {
        parent::setUp();

        // AgentLoopService::run() (driven here for real, via
        // EvalCaseExecutor) consults ConversationCondenser on every call.
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
            'name' => 'Consumption visibility fixture server',
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
        // "non-zero" could never be observed regardless of whether the
        // consumption figures themselves are wired up correctly.
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
        DB::table('eval_pass_rate_summaries')->delete();
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
            'name' => 'Consumption visibility fixture suite',
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
    // Scenario 1 — a completed run's results are shown alongside a
    // non-zero consumption block, not only pass/fail
    // ---------------------------------------------------------------

    #[Test]
    public function a_completed_runs_consumption_is_non_zero_and_returned_alongside_outcome_counts(): void
    {
        $started = $this->startRun($this->suiteId);
        $this->driveAll();

        $run = $this->getRun($started['id']);

        $this->assertSame('completed', $run['status']);
        $this->assertArrayHasKey('outcome_counts', $run);
        $this->assertArrayHasKey('consumption', $run);

        $this->assertSame(self::CASE_COUNT * self::TOKENS_PER_CASE, $run['consumption']['total_tokens']);
        $this->assertGreaterThan(0, (float) $run['consumption']['total_cost']);
        $this->assertFalse($run['consumption']['cost_unpriced']);
    }

    // ---------------------------------------------------------------
    // Scenario 2 — a run still in progress shows non-zero, growing
    // consumption reflecting only the cases completed so far
    // ---------------------------------------------------------------

    #[Test]
    public function an_in_progress_runs_consumption_is_non_zero_and_grows_with_only_the_cases_completed_so_far(): void
    {
        $started = $this->startRun($this->suiteId);

        $this->driveOnly(1);
        $afterOne = $this->getRun($started['id']);
        $this->assertSame('in_progress', $afterOne['status']);
        $this->assertSame(1, $afterOne['completed_count']);
        $this->assertSame(1 * self::TOKENS_PER_CASE, $afterOne['consumption']['total_tokens']);
        $this->assertGreaterThan(0, (float) $afterOne['consumption']['total_cost']);

        $this->driveOnly(3);
        $afterThree = $this->getRun($started['id']);
        $this->assertSame('in_progress', $afterThree['status']);
        $this->assertSame(3, $afterThree['completed_count']);
        $this->assertSame(3 * self::TOKENS_PER_CASE, $afterThree['consumption']['total_tokens']);

        $this->assertGreaterThan(
            $afterOne['consumption']['total_tokens'],
            $afterThree['consumption']['total_tokens'],
            'consumption must grow as more cases complete while the run is still in progress'
        );
        $this->assertGreaterThan(
            (float) $afterOne['consumption']['total_cost'],
            (float) $afterThree['consumption']['total_cost'],
            'cost must grow as more cases complete while the run is still in progress'
        );
    }

    // ---------------------------------------------------------------
    // Scenario 3 — a live push carries the identical consumption block
    // a direct read of the same run returns at the same instant
    // ---------------------------------------------------------------

    #[Test]
    public function eval_run_updated_broadcasts_the_identical_consumption_block_a_direct_read_returns(): void
    {
        $started = $this->startRun($this->suiteId);

        Event::fake([EvalRunUpdated::class]);

        $this->driveAll();

        $direct = $this->getRun($started['id']);
        $this->assertSame('completed', $direct['status']);

        $fired = collect(Event::dispatched(EvalRunUpdated::class))
            ->filter(fn (array $args) => $args[0]->runId === $started['id'])
            ->values();

        $this->assertCount(
            1,
            $fired,
            'the run completing must fire exactly one EvalRunUpdated for this run'
        );

        $broadcastConsumption = $fired->first()[0]->broadcastWith()['consumption'];

        $this->assertSame(
            $direct['consumption'],
            $broadcastConsumption,
            'the broadcast payload and a direct GET of the same run must carry an identical consumption block at the same instant'
        );
    }
}
