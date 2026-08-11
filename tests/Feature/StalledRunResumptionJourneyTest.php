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

    /**
     * research.md D8's own two-mechanism split, read carefully: manual
     * resume dispatches only rows still `pending` — a case whose job was
     * *dispatched* but whose worker died mid-execution is explicitly left
     * to standard queue redelivery or the scheduled sweep (which itself
     * resets `dispatched` -> `pending` before calling resume() — a step
     * resume() itself deliberately does not take, since a `dispatched` row
     * might just be a case a live worker is still genuinely processing,
     * not a dead one; contracts §2 frames exactly this as "a harmless
     * no-op"). This test previously simulated a dead-worker (`dispatched`,
     * never handled) case and asserted resume() recovered it — it passed,
     * but vacuously: the assertion only held because this file's own
     * driveAllDispatchedJobs() helper re-scans *every* job Bus::fake() has
     * ever captured, including the original, never-driven job for case 2,
     * so that job finally got driven regardless of what resume() itself
     * did. resume() was in fact a no-op for a `dispatched` row (confirmed
     * by reading EvalRunService::resume(), which only queries
     * status = Pending). Rewritten to exercise the actual mechanism
     * resume() exists for (D8 mechanism 2: "a case's job was never
     * dispatched at all," e.g. EvalRunService::start() died after writing
     * the eval_run_cases snapshot but before dispatching every job) — the
     * one scenario nothing else in this test suite (or
     * EvalRunServiceTest's unit coverage) exercised.
     */
    #[Test]
    public function manually_resuming_a_run_dispatches_a_case_whose_job_never_reached_the_queue_at_all(): void
    {
        $started = $this->startRun($this->suiteId);
        $runId = $started['run']['id'];

        $started['jobs'][0]->handle(app(EvalCaseExecutor::class));
        $started['jobs'][1]->handle(app(EvalCaseExecutor::class));

        // Simulate D8 mechanism 2 directly: case 2's eval_run_cases row was
        // snapshotted but its RunEvalCaseJob::dispatch() call never actually
        // happened (e.g. start() died mid-loop) — status reset to `pending`,
        // the one signal resume() acts on. Left undriven in Bus::fake()'s
        // own dispatched list, standing in for "nothing ever reached the
        // queue for this case."
        DB::table('eval_run_cases')
            ->where('run_id', $runId)
            ->where('eval_case_id', $this->caseIds[2])
            ->update(['status' => 'pending']);

        $this->assertSame(0, EvalCaseResult::where('run_id', $runId)->where('eval_case_id', $this->caseIds[2])->count());

        $dispatchedBefore = Bus::dispatched(RunEvalCaseJob::class)->count();

        $resumeResponse = $this->actingAs($this->operator)
            ->postJson($this->runsBase().'/'.$runId.'/resume');

        $resumeResponse->assertStatus(200);

        $this->assertSame(
            $dispatchedBefore + 1,
            Bus::dispatched(RunEvalCaseJob::class)->count(),
            'resume() must itself dispatch exactly one new job for the case that was snapshotted but never reached the queue'
        );

        // Drive only the newly-dispatched job — proving resume() itself,
        // not a stale pre-existing captured job, is what completes case 2.
        Bus::dispatched(RunEvalCaseJob::class)->last()->handle(app(EvalCaseExecutor::class));

        $run = $this->getRun($runId);
        $this->assertSame(
            'completed',
            $run['status'],
            'a manual resume must recover a never-dispatched case immediately, under Cache::lock (research.md D8)'
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

    // =================================================================
    // Reconciliation finding: data-model.md §1 states eval_runs.updated_at
    // is load-bearing for staleness detection and that "every
    // case-completion write that touches this row must bump it" — but
    // EvalCaseExecutor::maybeCompleteRun() previously only touched the run
    // row on the *final* case (the transition to `completed`), never on an
    // intermediate case completion. A run that legitimately takes longer
    // than stale_after_minutes (exactly the "runs may take a long time"/
    // 500+-case shape this feature is designed for) would then have every
    // one of its still-`dispatched`, genuinely-in-flight cases falsely
    // reset to `pending` and redispatched by the next sweep tick — racing
    // a live worker actually processing that case right now, with no
    // single-flight guard anywhere in AgentLoopService::run() to prevent
    // it. Fixed by having maybeCompleteRun() touch() the run on every case
    // completion, not only the last one.
    // =================================================================

    #[Test]
    public function a_case_completing_normally_bumps_the_runs_updated_at_so_an_actively_progressing_run_is_never_falsely_treated_as_stalled(): void
    {
        $started = $this->startRun($this->suiteId);
        $runId = $started['run']['id'];

        // Age the run past the stale threshold *before* any case completes
        // — simulating a run that has legitimately been executing longer
        // than stale_after_minutes, not one whose worker actually died.
        $this->ageRunPastStaleThreshold($runId);

        // Case 0 completes normally, exactly as a live, healthy worker
        // would while the run is still genuinely in progress.
        $started['jobs'][0]->handle(app(EvalCaseExecutor::class));

        $freshUpdatedAt = \Carbon\Carbon::parse(
            DB::table('eval_runs')->where('id', $runId)->value('updated_at')
        );
        $this->assertTrue(
            $freshUpdatedAt->gt(now()->subMinutes(5)),
            'a case completion must bump eval_runs.updated_at (data-model.md §1) so the sweep never mistakes active progress for a stall'
        );

        // Cases 1 and 2 are still legitimately `dispatched` — a live worker
        // is presumably still processing them. The sweep must find nothing
        // to do for this run at all now that its updated_at is fresh.
        Artisan::call('llm-client:resolve-stalled-eval-runs');

        $caseOneStatus = DB::table('eval_run_cases')
            ->where('run_id', $runId)->where('eval_case_id', $this->caseIds[1])->value('status');
        $caseTwoStatus = DB::table('eval_run_cases')
            ->where('run_id', $runId)->where('eval_case_id', $this->caseIds[2])->value('status');

        $this->assertSame('dispatched', $caseOneStatus, 'a genuinely in-flight case must not be reset to pending by a false-positive stale sweep');
        $this->assertSame('dispatched', $caseTwoStatus, 'a genuinely in-flight case must not be reset to pending by a false-positive stale sweep');

        $this->assertCount(
            3,
            Bus::dispatched(RunEvalCaseJob::class),
            'no additional job should have been dispatched by a sweep that correctly found nothing stale'
        );
    }
}
