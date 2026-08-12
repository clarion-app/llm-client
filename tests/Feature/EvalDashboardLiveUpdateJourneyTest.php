<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Events\EvalRunCaseResultRecorded;
use ClarionApp\LlmClient\Events\EvalRunUpdated;
use ClarionApp\LlmClient\Jobs\RunEvalCaseJob;
use ClarionApp\LlmClient\Models\EvalCaseResult;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\EvalCaseExecutor;
use ClarionApp\LlmClient\Services\EvalPassRateRollupService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A run still in progress is never invisible to an operator watching it:
 * EvalRunUpdated announces the run's own status transitions and
 * EvalRunCaseResultRecorded announces each case as it finishes, so a
 * viewer sees a run update live rather than only once it completes. An
 * interrupted run is announced as such, never silently presented as
 * normally finished.
 *
 * Every write-adjacent broadcast call sits behind its own inner
 * try/catch, isolated from the rollup write it sits beside -- one must
 * never mask or undo the other.
 *
 * Written before the two event classes and their call sites exist --
 * every test below is expected to fail with a class-not-found error
 * (or, once the classes exist but the call sites don't, an unmet
 * dispatch-count assertion) until this phase's implementation lands.
 * That failure is the correct, expected state right now.
 */
class EvalDashboardLiveUpdateJourneyTest extends TestCase
{
    private User $operator;

    /** @var array<int, string> case index (0-2) => eval_case id, in suite/position order */
    private array $caseIds = [];

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
        config(['llm-client.eval_runs.stale_after_minutes' => 30]);
        config(['llm-client.eval_runs.max_stale_sweeps' => 3]);

        $this->fakeProvider();
    }

    protected function tearDown(): void
    {
        Mockery::close();

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

    private function assignInstallationInferenceRole(): void
    {
        $server = Server::create([
            'name' => 'Live update test server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'provider_type' => 'openai',
        ]);

        RoleAssignment::create([
            'role' => 'inference',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $server->id,
            'model' => 'test-model',
        ]);
    }

    private function createSuiteWithThreeCases(): string
    {
        $suite = $this->actingAs($this->operator)->postJson($this->suitesBase(), [
            'name' => 'Live update fixture suite',
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

    // ---------------------------------------------------------------
    // Starting a run
    // ---------------------------------------------------------------

    #[Test]
    public function starting_an_ordinary_run_fires_no_eval_run_updated(): void
    {
        // in_progress is the row's own first, only state at creation --
        // there is no prior state to notify a change from.
        $this->assignInstallationInferenceRole();
        $suiteId = $this->createSuiteWithThreeCases();

        Event::fake([EvalRunUpdated::class]);

        $run = $this->startRun($suiteId)['run'];
        $this->assertSame('in_progress', $run['status']);

        Event::assertNotDispatched(EvalRunUpdated::class);
    }

    #[Test]
    public function starting_a_run_that_fails_to_start_fires_exactly_one_eval_run_updated(): void
    {
        // No inference role assigned -- start() resolves the
        // installation's inference role, finds none, and fails the run
        // immediately with status = failed_to_start.
        $suiteId = $this->createSuiteWithThreeCases();

        Event::fake([EvalRunUpdated::class]);

        $run = $this->actingAs($this->operator)
            ->postJson($this->suitesBase().'/'.$suiteId.'/runs')
            ->assertStatus(201)
            ->json();

        $this->assertSame('failed_to_start', $run['status']);

        Event::assertDispatchedTimes(EvalRunUpdated::class, 1);
        Event::assertDispatched(EvalRunUpdated::class, function ($event) use ($run) {
            return $event->runId === $run['id'] && ($event->broadcastWith()['status'] ?? null) === 'failed_to_start';
        });
    }

    // ---------------------------------------------------------------
    // Case completion and the run's terminal transition
    // ---------------------------------------------------------------

    #[Test]
    public function each_case_completing_fires_one_case_event_and_the_terminal_transition_fires_one_more_run_event(): void
    {
        $this->assignInstallationInferenceRole();
        $suiteId = $this->createSuiteWithThreeCases();

        Event::fake([EvalRunUpdated::class, EvalRunCaseResultRecorded::class]);

        $started = $this->startRun($suiteId);
        $runId = $started['run']['id'];

        Event::assertNotDispatched(EvalRunUpdated::class);

        $this->driveAllDispatchedJobs();

        Event::assertDispatchedTimes(EvalRunCaseResultRecorded::class, 3);
        Event::assertDispatchedTimes(EvalRunUpdated::class, 1);
        Event::assertDispatched(EvalRunUpdated::class, function ($event) use ($runId) {
            return $event->runId === $runId && ($event->broadcastWith()['status'] ?? null) === 'completed';
        });
    }

    // ---------------------------------------------------------------
    // A stalled run swept by ResolveStalledEvalRunsCommand
    // ---------------------------------------------------------------

    #[Test]
    public function a_stalled_run_whose_cases_are_exhausted_fires_exactly_one_eval_run_updated_with_incomplete_status(): void
    {
        $this->assignInstallationInferenceRole();
        $suiteId = $this->createSuiteWithThreeCases();

        $started = $this->startRun($suiteId);
        $runId = $started['run']['id'];

        $started['jobs'][0]->handle(app(EvalCaseExecutor::class));
        $started['jobs'][1]->handle(app(EvalCaseExecutor::class));

        DB::table('eval_run_cases')
            ->where('run_id', $runId)
            ->where('eval_case_id', $this->caseIds[2])
            ->update(['dispatch_attempts' => (int) config('llm-client.eval_runs.max_stale_sweeps', 3)]);

        $this->ageRunPastStaleThreshold($runId);

        Event::fake([EvalRunUpdated::class, EvalRunCaseResultRecorded::class]);

        Artisan::call('llm-client:resolve-stalled-eval-runs');

        $run = $this->getRun($runId);
        $this->assertSame(
            'incomplete',
            $run['status'],
            'fixture precondition: the exhausted-case sweep must land this run in incomplete, never completed'
        );

        Event::assertDispatchedTimes(EvalRunUpdated::class, 1);
        Event::assertDispatched(EvalRunUpdated::class, function ($event) use ($runId) {
            return $event->runId === $runId && ($event->broadcastWith()['status'] ?? null) === 'incomplete';
        });
    }

    #[Test]
    public function a_stalled_run_with_only_recoverable_cases_swept_and_resumed_fires_exactly_one_eval_run_updated(): void
    {
        $this->assignInstallationInferenceRole();
        $suiteId = $this->createSuiteWithThreeCases();

        $started = $this->startRun($suiteId);
        $runId = $started['run']['id'];

        // Two cases complete normally; the third's job was pulled off the
        // queue but the worker died before completing it -- still
        // `dispatched`, dispatch_attempts under the give-up threshold, so
        // the sweep's own recoverable branch (never the exhausted one)
        // applies and redispatches it via EvalRunService::resume().
        $started['jobs'][0]->handle(app(EvalCaseExecutor::class));
        $started['jobs'][1]->handle(app(EvalCaseExecutor::class));

        $this->ageRunPastStaleThreshold($runId);

        Event::fake([EvalRunUpdated::class, EvalRunCaseResultRecorded::class]);

        Artisan::call('llm-client:resolve-stalled-eval-runs');

        $firedForThisRun = collect(Event::dispatched(EvalRunUpdated::class))
            ->filter(fn (array $args) => $args[0]->runId === $runId);

        $this->assertCount(
            1,
            $firedForThisRun,
            'the recoverable-case sweep resumes this run through EvalRunService::resume(), which must itself fire exactly one EvalRunUpdated -- neither silently skipped nor double-fired by a second call site in the sweep command'
        );
    }

    // ---------------------------------------------------------------
    // Rollup writes and broadcasts are isolated from one another
    // ---------------------------------------------------------------

    #[Test]
    public function a_rollup_failure_does_not_prevent_the_case_completion_broadcast_from_firing(): void
    {
        $this->assignInstallationInferenceRole();
        $suiteId = $this->createSuiteWithThreeCases();

        $rollup = Mockery::mock(EvalPassRateRollupService::class);
        $rollup->shouldReceive('recordResult')->andThrow(new \RuntimeException('rollup boom'));
        $this->app->instance(EvalPassRateRollupService::class, $rollup);

        Event::fake([EvalRunCaseResultRecorded::class]);

        $started = $this->startRun($suiteId);
        $started['jobs'][0]->handle(app(EvalCaseExecutor::class));

        Event::assertDispatchedTimes(EvalRunCaseResultRecorded::class, 1);

        $this->assertSame(
            1,
            EvalCaseResult::where('run_id', $started['run']['id'])->count(),
            'a rollup failure must never prevent the case result itself from being recorded'
        );
    }

    #[Test]
    public function a_broadcast_listener_failure_does_not_prevent_the_rollup_write(): void
    {
        $this->assignInstallationInferenceRole();
        $suiteId = $this->createSuiteWithThreeCases();

        Event::listen(EvalRunCaseResultRecorded::class, function () {
            throw new \RuntimeException('broadcast boom');
        });

        $started = $this->startRun($suiteId);
        $started['jobs'][0]->handle(app(EvalCaseExecutor::class));

        $this->assertSame(
            1,
            (int) DB::table('eval_pass_rate_summaries')->sum('total_count'),
            'a broadcast/listener failure must never prevent the rollup write'
        );
        $this->assertSame(
            1,
            EvalCaseResult::where('run_id', $started['run']['id'])->count(),
            'a broadcast/listener failure must never prevent the case result itself from being recorded'
        );
    }
}
