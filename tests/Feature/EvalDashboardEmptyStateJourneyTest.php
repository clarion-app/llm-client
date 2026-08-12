<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Jobs\RunEvalCaseJob;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\EvalCaseExecutor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * US1 Acceptance Scenario 4 / FR-011 / SC-006 (quickstart step 1): an agent
 * with zero runs -- whether it has no suite at all, or a suite whose cases
 * have simply never been run -- shows the explanatory empty state
 * (current_pass_rate: null, trend.buckets: [], persistent_failures: []),
 * never an error and never a computed 0. An agent with a run still
 * in_progress (no Completed run yet) shows the identical null
 * current_pass_rate, since an in-progress run's partial outcome mix is
 * never consulted for "current" (research.md D8). Positively, US1
 * Acceptance Scenarios 1-3: an agent with several completed runs with a
 * mix of outcomes shows a non-null current_pass_rate, a non-empty
 * trend.buckets, and a non-empty persistent_failures, all in one GET call.
 */
class EvalDashboardEmptyStateJourneyTest extends TestCase
{
    private User $operator;
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->declareSupportingSchema();

        $this->operator = User::factory()->create();
        config(['llm-client.cost.operator_user_ids' => [$this->operator->id]]);

        $this->server = Server::create([
            'name' => 'Empty state fixture server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'provider_type' => 'openai',
        ]);

        RoleAssignment::create([
            'role' => 'inference',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $this->server->id,
            'model' => 'test-model',
        ]);

        $this->fakeProvider();
        $this->seedApiDocsCache();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->seedApiDocsCache(null);

        DB::table('eval_pass_rate_summaries')->delete();
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

    private function dashboardUrl(string $agentLabel): string
    {
        return '/api/clarion-app/llm-client/agent-eval-dashboard/'.$agentLabel;
    }

    /**
     * AgentLoopService::run() consults ConversationCondenser on every call,
     * unconditionally -- the RunSuiteJourneyTest/RubricJudgmentJourneyTest
     * precedent -- so this table must exist even though no case here ever
     * triggers a real tool call.
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

    private function textChatResponse(string $content): array
    {
        return [
            'choices' => [['message' => ['content' => $content]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            'model' => 'test-model',
        ];
    }

    /**
     * Answers "4" for the passing case's prompt and something else for the
     * failing case's prompt, so a single suite can produce a deliberate mix
     * of pass/fail outcomes.
     */
    private function fakeProvider(): void
    {
        Http::fake();

        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function (array $messages) {
            $firstUser = collect($messages)->firstWhere('role', 'user')['content'] ?? '';

            if (str_contains($firstUser, 'What is 2+2')) {
                return $this->textChatResponse('4');
            }

            // Deliberately wrong response for the failing case's prompt.
            return $this->textChatResponse('I have no idea.');
        });
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);
        $this->app->instance(ProviderRegistry::class, $registry);
    }

    private function createSuite(string $name, string $agentLabel): string
    {
        return $this->actingAs($this->operator)->postJson($this->suitesBase(), [
            'name' => $name,
            'agent_identifier' => $agentLabel,
        ])->assertStatus(200)->json('id');
    }

    private function addTextMatchCase(string $suiteId, string $given, string $expectedText): void
    {
        $this->actingAs($this->operator)->postJson($this->suitesBase().'/'.$suiteId.'/cases', [
            'given' => $given,
            'expected_behavior' => "Answer with {$expectedText}.",
            'expectations' => [['kind' => 'text_match', 'expected_text' => $expectedText]],
        ])->assertStatus(200);
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

    private function getDashboard(string $agentLabel): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->operator)->getJson($this->dashboardUrl($agentLabel));
    }

    // ---------------------------------------------------------------
    // Zero suites, zero runs -- the empty state
    // ---------------------------------------------------------------

    #[Test]
    public function an_agent_label_with_zero_suites_and_zero_runs_returns_200_with_the_empty_state_shape(): void
    {
        $response = $this->getDashboard('never-seen-agent');

        $response->assertStatus(200);
        $this->assertNull($response->json('current_pass_rate'));
        $this->assertSame([], $response->json('trend.buckets'));
        $this->assertSame([], $response->json('persistent_failures'));
    }

    // ---------------------------------------------------------------
    // A suite with authored cases, but never run
    // ---------------------------------------------------------------

    #[Test]
    public function a_suite_with_cases_authored_but_never_run_produces_the_identical_empty_shape(): void
    {
        $agentLabel = 'authored-not-run-agent';
        $suiteId = $this->createSuite('Authored, never run', $agentLabel);
        $this->addTextMatchCase($suiteId, 'What is 2+2?', '4');

        $response = $this->getDashboard($agentLabel);

        $response->assertStatus(200);
        $this->assertNull(
            $response->json('current_pass_rate'),
            'the FR-011 empty-state condition is "zero runs," not "zero suites" -- an authored-but-never-run suite is still the empty state'
        );
        $this->assertSame([], $response->json('trend.buckets'));
        $this->assertSame([], $response->json('persistent_failures'));
    }

    // ---------------------------------------------------------------
    // Only an in_progress run -- still the empty state for "current"
    // ---------------------------------------------------------------

    #[Test]
    public function an_agent_with_only_an_in_progress_run_still_shows_a_null_current_pass_rate(): void
    {
        $agentLabel = 'in-progress-only-agent';
        $suiteId = $this->createSuite('In-progress only', $agentLabel);
        $this->addTextMatchCase($suiteId, 'What is 2+2?', '4');

        $started = $this->startRun($suiteId);
        $this->assertSame('in_progress', $started['status'], 'fixture precondition: the run must genuinely be in_progress, not already completed');

        $response = $this->getDashboard($agentLabel);

        $response->assertStatus(200);
        $this->assertNull(
            $response->json('current_pass_rate'),
            'an in-progress run has no final, stable outcome set yet and must never be consulted for "current" (research.md D8)'
        );
    }

    // ---------------------------------------------------------------
    // Positive: completed runs with a mix of outcomes
    // ---------------------------------------------------------------

    #[Test]
    public function an_agent_with_completed_runs_with_a_mix_of_outcomes_shows_a_non_null_pass_rate_a_non_empty_trend_and_non_empty_persistent_failures(): void
    {
        $agentLabel = 'mixed-outcomes-agent';
        $suiteId = $this->createSuite('Mixed outcomes', $agentLabel);
        $this->addTextMatchCase($suiteId, 'What is 2+2?', '4');
        $this->addTextMatchCase($suiteId, 'Name the capital of Nowhereland.', 'Nowhereton');

        // Run the suite a few times so both a completed run exists and the
        // failing case accumulates enough history to surface as a
        // persistent failure.
        for ($i = 0; $i < 3; $i++) {
            $this->startRun($suiteId);
            $this->driveDispatchedCaseJobsToCompletion();
        }

        $response = $this->getDashboard($agentLabel);

        $response->assertStatus(200);
        $this->assertNotNull($response->json('current_pass_rate'), 'a completed run with results must produce a non-null current_pass_rate');
        $this->assertNotEmpty($response->json('trend.buckets'), 'a completed run\'s results must contribute at least one trend bucket');
        $this->assertNotEmpty(
            $response->json('persistent_failures'),
            'the consistently-wrong "capital of Nowhereland" case must surface in persistent_failures'
        );
    }
}
