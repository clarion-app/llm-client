<?php

namespace ClarionApp\LlmClient\Tests\Feature;

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
 * spec.md US5 Acceptance Scenarios 1-3 and quickstart.md step 6: a suite
 * with one ordinary case, one case whose agent call fails outright, and
 * one case whose transport never responds. FR-012/FR-013's containment
 * guarantee is already implemented by EvalCaseExecutor::execute()'s own
 * try/catch-and-record-errored path (T024) and by RunEvalCaseJob's
 * $timeout/tries=1/failed() hook (T025) — this file's job is to prove
 * both paths independently rather than add a new one.
 *
 * The outright-failure case drives the real RunEvalCaseJob::handle() with
 * a fixture LLM transport that throws, exercising EvalCaseExecutor's own
 * catch block. The hanging case is never driven through handle() at all —
 * a real queue worker enforces $timeout via a process-level alarm that
 * kills the worker and calls the job's failed() hook, which cannot be
 * reproduced synchronously inside a single PHPUnit process without
 * spinning a real subprocess. Instead this test invokes
 * RunEvalCaseJob::failed() directly, the exact hook a real worker's
 * timeout-kill invokes, and separately asserts the job's own $tries/
 * $timeout properties so a mutation that removed the "one bounded
 * timeout, not tries x timeout" guarantee (tries raised above 1) is still
 * caught even though nothing here waits out a real clock.
 */
class CaseFailureContainmentJourneyTest extends TestCase
{
    private User $operator;
    private string $suiteId;

    /** @var array<string, string> case label => eval_case id */
    private array $caseIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // AgentLoopService::run() (driven here for real, via
        // EvalCaseExecutor) consults ConversationCondenser on every call —
        // the RunSuiteJourneyTest precedent.
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

        // A short, test-configured timeout (contrast with the 300s
        // production default) so RunEvalCaseJob::$timeout — read from
        // this config at construction time, before the run is even
        // started — is itself demonstrably bounded, even though this
        // test never waits one out.
        config(['llm-client.eval_runs.case_timeout_seconds' => 2]);

        $server = Server::create([
            'name' => 'Case failure containment test server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'provider_type' => 'openai',
        ]);

        RoleAssignment::create([
            'role' => 'inference',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $server->id,
            'model' => 'test-model',
        ]);

        $this->fakeProvider();

        $this->suiteId = $this->createSuiteWithThreeCases();
    }

    protected function tearDown(): void
    {
        Mockery::close();

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

    private function createSuiteWithThreeCases(): string
    {
        $suite = $this->actingAs($this->operator)->postJson($this->suitesBase(), [
            'name' => 'Case failure containment fixture suite',
            'agent_identifier' => 'home-automation-agent',
        ])->assertStatus(200)->json();

        $cases = [
            'ordinary' => [
                'given' => 'What is 2+2?',
                'expected_behavior' => 'Answer with the number 4.',
                'expectations' => [['kind' => 'text_match', 'expected_text' => '4']],
            ],
            'outright_failure' => [
                'given' => 'Trigger an outright transport failure.',
                'expected_behavior' => 'Never reached — the fixture transport throws for this prompt.',
                'expectations' => [['kind' => 'text_match', 'expected_text' => 'unreachable']],
            ],
            'hanging' => [
                'given' => 'This case never gets a response back.',
                'expected_behavior' => 'Never reached — the fixture transport never responds for this prompt.',
                'expectations' => [['kind' => 'text_match', 'expected_text' => 'unreachable']],
            ],
        ];

        foreach ($cases as $label => $payload) {
            $case = $this->actingAs($this->operator)
                ->postJson($this->suitesBase().'/'.$suite['id'].'/cases', $payload)
                ->assertStatus(200)->json();
            $this->caseIds[$label] = $case['id'];
        }

        return $suite['id'];
    }

    /**
     * The ordinary case answers normally; the outright-failure case's
     * prompt makes the mocked transport throw synchronously (standing in
     * for "an operation the resolver can't find, or a fixture LLM
     * transport that throws" per tasks.md T066); the hanging case's
     * prompt is never actually sent — its job is never driven through
     * handle() — so no branch for it is needed here at all.
     */
    private function fakeProvider(): void
    {
        Http::fake();

        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function (array $messages, array $tools = [], array $options = []) {
            $firstUser = collect($messages)->firstWhere('role', 'user')['content'] ?? '';

            if (str_contains($firstUser, 'outright transport failure')) {
                throw new \RuntimeException('Simulated outright transport failure: upstream call blew up.');
            }

            if (str_contains($firstUser, 'What is 2+2')) {
                return $this->textChatResponse('4');
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
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];
    }

    /**
     * @return array{run: array, jobsByCase: array<string, RunEvalCaseJob>}
     */
    private function startRun(string $suiteId): array
    {
        Bus::fake([RunEvalCaseJob::class]);

        $run = $this->actingAs($this->operator)
            ->postJson($this->suitesBase().'/'.$suiteId.'/runs')
            ->assertStatus(201)
            ->json();

        $dispatched = Bus::dispatched(RunEvalCaseJob::class)->values()->all();

        // Map each dispatched job back to its own case label via the
        // eval_run_cases row it was dispatched for, so the three
        // scenarios below can be driven independently regardless of
        // dispatch order.
        $evalRunCaseToEvalCase = DB::table('eval_run_cases')
            ->where('run_id', $run['id'])
            ->pluck('eval_case_id', 'id');

        $jobsByCase = [];
        foreach ($dispatched as $job) {
            $evalCaseId = $evalRunCaseToEvalCase[$job->evalRunCaseId] ?? null;
            $label = array_search($evalCaseId, $this->caseIds, true);
            if ($label !== false) {
                $jobsByCase[$label] = $job;
            }
        }

        return ['run' => $run, 'jobsByCase' => $jobsByCase];
    }

    private function getRun(string $runId): array
    {
        return $this->actingAs($this->operator)->getJson($this->runsBase().'/'.$runId)->assertStatus(200)->json();
    }

    private function getRunCases(string $runId): array
    {
        return $this->actingAs($this->operator)->getJson($this->runsBase().'/'.$runId.'/cases')->assertStatus(200)->json();
    }

    // ---------------------------------------------------------------
    // US5 Acceptance Scenarios 1-3 / SC-004
    // ---------------------------------------------------------------

    #[Test]
    public function an_outright_failing_case_and_a_hanging_case_are_both_recorded_errored_while_the_run_completes_and_the_ordinary_case_is_unaffected(): void
    {
        $started = $this->startRun($this->suiteId);
        $runId = $started['run']['id'];
        $jobs = $started['jobsByCase'];

        $this->assertArrayHasKey('ordinary', $jobs);
        $this->assertArrayHasKey('outright_failure', $jobs);
        $this->assertArrayHasKey('hanging', $jobs);

        // Sanity check on the "one bounded timeout, not tries x timeout"
        // guarantee (mutation-checklist row 7): no automatic retry, and
        // the per-case wait is the short, test-configured value, not the
        // 300s production default.
        $this->assertSame(1, $jobs['hanging']->tries, 'RunEvalCaseJob must never retry — this feature\'s own resumption mechanism, not Laravel retry, is the recovery path');
        $this->assertSame(2, $jobs['hanging']->timeout, 'RunEvalCaseJob::$timeout must reflect the short, test-configured case_timeout_seconds');

        // Scenario 1 — an outright agent-call failure, driven through the
        // real job handle() and EvalCaseExecutor's own catch block.
        $jobs['outright_failure']->handle(app(EvalCaseExecutor::class));

        // Scenario 2 — a case that never produces a response. A real
        // queue worker enforces $timeout via a process-level alarm that
        // this single-process test cannot reproduce; what it *can* prove
        // is the contract a real worker relies on: when the alarm fires,
        // the worker never lets handle() return and instead calls the
        // job's failed() hook directly (mutation-checklist row 8) — so
        // this simulates exactly that kill, without ever calling handle()
        // for this job at all.
        $jobs['hanging']->failed(new \RuntimeException('Simulated worker timeout kill: case exceeded case_timeout_seconds.'));

        // The ordinary case, driven last, proves the two problem cases
        // above never aborted or otherwise disturbed the run (FR-012).
        $jobs['ordinary']->handle(app(EvalCaseExecutor::class));

        $run = $this->getRun($runId);
        $this->assertSame('completed', $run['status'], 'the run as a whole must reach completed, not stuck or aborted by either problem case (SC-004)');
        $this->assertSame(['pass' => 1, 'fail' => 0, 'needs_human_review' => 0, 'errored' => 2], $run['outcome_counts']);

        $cases = $this->getRunCases($runId);
        $byCaseId = collect($cases['data'])->keyBy('eval_case_id');

        $ordinary = $byCaseId[$this->caseIds['ordinary']];
        $this->assertSame('pass', $ordinary['outcome'], 'the ordinary case must be completely unaffected by the other two cases');
        $this->assertSame('4', trim($ordinary['produced_response']));
        $this->assertNull($ordinary['error_message']);

        $outrightFailure = $byCaseId[$this->caseIds['outright_failure']];
        $this->assertSame('errored', $outrightFailure['outcome']);
        $this->assertNotEmpty($outrightFailure['error_message']);
        $this->assertStringContainsString('Simulated outright transport failure', $outrightFailure['error_message']);
        $this->assertNull($outrightFailure['produced_response']);
        $this->assertEmpty($outrightFailure['expectation_results']);

        $hanging = $byCaseId[$this->caseIds['hanging']];
        $this->assertSame('errored', $hanging['outcome']);
        $this->assertNotEmpty($hanging['error_message']);
        $this->assertStringContainsString('Simulated worker timeout kill', $hanging['error_message']);
        $this->assertNull($hanging['produced_response']);
        $this->assertEmpty($hanging['expectation_results']);
    }
}
