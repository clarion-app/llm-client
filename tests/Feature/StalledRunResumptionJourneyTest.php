<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Jobs\RunEvalCaseJob;
use ClarionApp\LlmClient\Models\EvalCaseResult;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\EvalCaseExecutor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * spec.md US3 Acceptance Scenario 3, FR-017, and research.md D8: a run
 * interrupted partway is always either resumable to completion or clearly
 * marked incomplete — never presented as an ordinary completed result —
 * and results already produced before the interruption survive it
 * untouched.
 *
 * Four sub-scenarios, per research.md D8's own two-mechanism split:
 *
 *  - Redelivery safety (Phase 3's idempotency guard, EvalCaseExecutor/
 *    RunEvalCaseJob T024/T025) — already built, this file is its dedicated
 *    proof under an actual redelivery, expected GREEN today.
 *  - Worker-death simulation via the scheduled sweep
 *    (ResolveStalledEvalRunsCommand) — does not exist yet, expected RED.
 *  - Manual resume via POST /eval-runs/{runId}/resume
 *    (EvalRunService::resume() + the route) — does not exist yet, expected
 *    RED.
 *  - Exhausted recovery (the sweep's own give-up threshold,
 *    config('llm-client.eval_runs.max_stale_sweeps')) — does not exist
 *    yet, expected RED.
 */
class StalledRunResumptionJourneyTest extends TestCase
{
    private User $operator;
    private string $suiteId;

    /** @var array<int, string> case index (0-2) => eval_case id, in suite/position order */
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
        config(['llm-client.eval_runs.stale_after_minutes' => 30]);
        config(['llm-client.eval_runs.max_stale_sweeps' => 3]);

        $server = Server::create([
            'name' => 'Stalled run resumption test server',
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
            'name' => 'Stalled run resumption fixture suite',
            'agent_identifier' => 'home-automation-agent',
        ])->assertStatus(200)->json();

        for ($i = 0; $i < 3; $i++) {
            $case = $this->actingAs($this->operator)->postJson($this->suitesBase().'/'.$suite['id'].'/cases', [
                'given' => "Case number {$i}: what is your favorite number?",
                'expected_behavior' => "Answer with the number {$i}.",
                'expectations' => [['kind' => 'text_match', 'expected_text' => (string) $i]],
            ])->assertStatus(200)->json();
            $this->caseIds[$i] = $case['id'];
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
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];
    }

    /**
     * Starts a run with dispatch captured (not executed) by Bus::fake() —
     * the RunSuiteJourneyTest precedent — and returns both the response
     * body and the captured jobs, in the run's own case (position) order.
     *
     * @return array{run: array, jobs: array<int, RunEvalCaseJob>}
     */
    private function startRun(string $suiteId): array
    {
        Bus::fake([RunEvalCaseJob::class]);

        $run = $this->actingAs($this->operator)
            ->postJson($this->suitesBase().'/'.$suiteId.'/runs')
            ->assertStatus(201)
            ->json();

        return ['run' => $run, 'jobs' => Bus::dispatched(RunEvalCaseJob::class)->values()->all()];
    }

    /**
     * Drives every case job Bus::fake() has captured so far to completion —
     * safe to call more than once, since EvalCaseExecutor's own idempotency
     * guard (Phase 3) no-ops an already-completed case's redelivery.
     */
    private function driveAllDispatchedJobs(): void
    {
        foreach (Bus::dispatched(RunEvalCaseJob::class) as $job) {
            $job->handle(app(EvalCaseExecutor::class));
        }
    }

    private function ageRunPastStaleThreshold(string $runId): void
    {
        $minutes = (int) config('llm-client.eval_runs.stale_after_minutes', 30);

        DB::table('eval_runs')
            ->where('id', $runId)
            ->update(['updated_at' => now()->subMinutes($minutes + 5)]);
    }

    private function getRun(string $runId): array
    {
        return $this->actingAs($this->operator)->getJson($this->runsBase().'/'.$runId)->assertStatus(200)->json();
    }

    private function getRunCases(string $runId): array
    {
        return $this->actingAs($this->operator)->getJson($this->runsBase().'/'.$runId.'/cases')->assertStatus(200)->json();
    }

    // =================================================================
    // Sub-scenario 1: redelivery safety — already built in Phase 3,
    // expected GREEN today (this file's dedicated proof, not a new
    // implementation).
    // =================================================================

    #[Test]
    public function redelivering_the_identical_job_for_an_already_completed_case_produces_no_duplicate_result_row(): void
    {
        $started = $this->startRun($this->suiteId);
        $this->driveAllDispatchedJobs();

        $run = $this->getRun($started['run']['id']);
        $this->assertSame('completed', $run['status']);

        $firstCaseJob = $started['jobs'][0];
        $this->assertSame(
            1,
            EvalCaseResult::where('run_id', $started['run']['id'])
                ->where('eval_case_id', $this->caseIds[0])
                ->count(),
            'exactly one result row must exist after the first, real completion'
        );

        // Simulate an at-least-once queue redelivery: the identical
        // (runId, evalRunCaseId) payload, dispatched and handled a second
        // time (research.md D8's "sibling attempt" scenario).
        (new RunEvalCaseJob($firstCaseJob->runId, $firstCaseJob->evalRunCaseId))
            ->handle(app(EvalCaseExecutor::class));

        $this->assertSame(
            1,
            EvalCaseResult::where('run_id', $started['run']['id'])
                ->where('eval_case_id', $this->caseIds[0])
                ->count(),
            'a redelivered job for an already-completed case must not produce a second eval_case_results row (mutation-checklist row 10)'
        );
    }

    // =================================================================
    // Sub-scenario 2: worker-death simulation — requires
    // ResolveStalledEvalRunsCommand (does not exist yet), expected RED.
    // =================================================================

    #[Test]
    public function a_case_left_dispatched_by_a_dead_worker_is_redispatched_by_the_sweep_and_the_run_reaches_completed_without_operator_action(): void
    {
        $started = $this->startRun($this->suiteId);
        $runId = $started['run']['id'];

        // Simulate a dead worker: cases 0 and 1 were actually processed,
        // case 2's job was pulled off the queue (status = dispatched, per
        // EvalRunService::start()) but the worker died before it ever
        // called handle() — no eval_case_results row exists for it.
        $started['jobs'][0]->handle(app(EvalCaseExecutor::class));
        $started['jobs'][1]->handle(app(EvalCaseExecutor::class));

        $staleRow = DB::table('eval_run_cases')
            ->where('run_id', $runId)
            ->where('eval_case_id', $this->caseIds[2])
            ->first();
        $this->assertSame('dispatched', $staleRow->status, 'sanity check: the third case must still be dispatched, never completed, before the sweep runs');
        $this->assertSame(0, EvalCaseResult::where('run_id', $runId)->where('eval_case_id', $this->caseIds[2])->count());

        $this->ageRunPastStaleThreshold($runId);

        Artisan::call('llm-client:resolve-stalled-eval-runs');

        // The sweep's own redispatch is itself captured by the still-active
        // Bus::fake() — drive it to completion the same way a real worker
        // eventually would (already-completed cases are safely re-driven
        // too, per the idempotency guard proven above).
        $this->driveAllDispatchedJobs();

        $run = $this->getRun($runId);
        $this->assertSame(
            'completed',
            $run['status'],
            'the run must reach completed once the sweep redispatches the never-processed case, with no operator action'
        );
        $this->assertSame(3, $run['completed_count']);

        $this->assertSame(
            1,
            EvalCaseResult::where('run_id', $runId)->where('eval_case_id', $this->caseIds[2])->count(),
            'the previously-stale case must now have exactly one result row'
        );
    }

    // =================================================================
    // Sub-scenario 3: manual resume — requires EvalRunService::resume()
    // and POST /eval-runs/{runId}/resume (neither exists yet), expected
    // RED.
    // =================================================================

    #[Test]
    public function manually_resuming_a_stalled_run_recovers_it_immediately_without_waiting_for_the_sweep(): void
    {
        $started = $this->startRun($this->suiteId);
        $runId = $started['run']['id'];

        // Identical dead-worker setup to the sweep scenario above, but this
        // time the operator does not wait for the scheduled sweep at all.
        $started['jobs'][0]->handle(app(EvalCaseExecutor::class));
        $started['jobs'][1]->handle(app(EvalCaseExecutor::class));

        $this->assertSame(0, EvalCaseResult::where('run_id', $runId)->where('eval_case_id', $this->caseIds[2])->count());

        $resumeResponse = $this->actingAs($this->operator)
            ->postJson($this->runsBase().'/'.$runId.'/resume');

        $resumeResponse->assertStatus(200);

        $this->driveAllDispatchedJobs();

        $run = $this->getRun($runId);
        $this->assertSame(
            'completed',
            $run['status'],
            'a manual resume must recover the stalled case immediately, under Cache::lock (research.md D8), with the identical outcome the sweep produces'
        );
        $this->assertSame(3, $run['completed_count']);
        $this->assertSame(
            1,
            EvalCaseResult::where('run_id', $runId)->where('eval_case_id', $this->caseIds[2])->count()
        );
    }

    // =================================================================
    // Sub-scenario 4: exhausted recovery — requires
    // ResolveStalledEvalRunsCommand's give-up threshold (does not exist
    // yet), expected RED.
    // =================================================================

    #[Test]
    public function a_case_that_exhausts_its_recovery_attempts_is_marked_errored_and_the_run_is_marked_incomplete_not_left_in_progress_forever(): void
    {
        $started = $this->startRun($this->suiteId);
        $runId = $started['run']['id'];

        $started['jobs'][0]->handle(app(EvalCaseExecutor::class));
        $started['jobs'][1]->handle(app(EvalCaseExecutor::class));

        // Case 2 has already been redispatched by prior sweep cycles
        // config('llm-client.eval_runs.max_stale_sweeps') times, with no
        // progress each time — a genuine, not transient, stall.
        DB::table('eval_run_cases')
            ->where('run_id', $runId)
            ->where('eval_case_id', $this->caseIds[2])
            ->update(['dispatch_attempts' => (int) config('llm-client.eval_runs.max_stale_sweeps', 3)]);

        $this->ageRunPastStaleThreshold($runId);

        Artisan::call('llm-client:resolve-stalled-eval-runs');

        $result = EvalCaseResult::where('run_id', $runId)
            ->where('eval_case_id', $this->caseIds[2])
            ->first();

        $this->assertNotNull($result, 'an exhausted case must still get a terminal result row so the run can reach a terminal state');
        $this->assertSame('errored', $result->outcome->value);
        $this->assertNotEmpty($result->error_message);

        $run = $this->getRun($runId);
        $this->assertSame(
            'incomplete',
            $run['status'],
            'a run that gave up recovering one genuinely stalled case must be marked incomplete, never completed and never left in_progress forever (FR-017, research.md D8)'
        );
    }

    // =================================================================
    // Cross-cutting: already-produced results survive a stale-recovery
    // sweep byte-identical (US3 scenario 3 / SC-002) — requires the sweep
    // command to actually run, expected RED today for the same reason
    // sub-scenario 2 is.
    // =================================================================

    #[Test]
    public function results_already_recorded_before_the_interruption_remain_byte_identical_after_the_sweep_recovers_the_run(): void
    {
        $started = $this->startRun($this->suiteId);
        $runId = $started['run']['id'];

        $started['jobs'][0]->handle(app(EvalCaseExecutor::class));
        $started['jobs'][1]->handle(app(EvalCaseExecutor::class));

        $beforeCase0 = EvalCaseResult::where('run_id', $runId)->where('eval_case_id', $this->caseIds[0])->firstOrFail()->getAttributes();
        $beforeCase1 = EvalCaseResult::where('run_id', $runId)->where('eval_case_id', $this->caseIds[1])->firstOrFail()->getAttributes();

        $this->ageRunPastStaleThreshold($runId);
        Artisan::call('llm-client:resolve-stalled-eval-runs');
        $this->driveAllDispatchedJobs();

        $afterCase0 = EvalCaseResult::where('run_id', $runId)->where('eval_case_id', $this->caseIds[0])->firstOrFail()->getAttributes();
        $afterCase1 = EvalCaseResult::where('run_id', $runId)->where('eval_case_id', $this->caseIds[1])->firstOrFail()->getAttributes();

        $this->assertSame($beforeCase0, $afterCase0, "case 0's already-recorded result must be byte-identical before and after the sweep");
        $this->assertSame($beforeCase1, $afterCase1, "case 1's already-recorded result must be byte-identical before and after the sweep");
    }
}
