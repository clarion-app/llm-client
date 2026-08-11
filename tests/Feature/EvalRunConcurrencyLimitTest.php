<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Jobs\RunEvalCaseJob;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\Server;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * research.md D9 / quickstart.md mutation-checklist row 13: a run's case
 * throughput is bounded by a named 'eval-run-cases' RateLimiter, attached
 * to every RunEvalCaseJob via Laravel's own RateLimited job middleware —
 * independent of BudgetGate (money, research.md D10) and independent of
 * how many cases a single run enqueues at once (a run with 500 cases
 * enqueues all 500 immediately; the limiter throttles how fast they
 * actually execute).
 *
 * Neither RunEvalCaseJob::middleware() (T052) nor the named limiter
 * registration in LlmClientServiceProvider (T053) exist yet — every
 * assertion in this file is expected RED today.
 */
class EvalRunConcurrencyLimitTest extends TestCase
{
    private User $operator;
    private string $suiteId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::factory()->create();
        config(['llm-client.cost.operator_user_ids' => [$this->operator->id]]);

        $server = Server::create([
            'name' => 'Concurrency limit test server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'provider_type' => 'openai',
        ]);

        RoleAssignment::create([
            'role' => 'inference',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $server->id,
            'model' => 'test-model',
        ]);

        $this->suiteId = $this->createSuiteWithFiveCases();
    }

    protected function tearDown(): void
    {
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

    /**
     * Five cases — deliberately more than this file's configured
     * max_cases_per_minute (set to 2 in the tests that need it below), per
     * this task's own instruction to "dispatch more cases in one run than
     * config('llm-client.eval_runs.max_cases_per_minute')". Jobs are never
     * driven to completion in this file — only dispatch/middleware
     * attachment is under test, not case execution.
     */
    private function createSuiteWithFiveCases(): string
    {
        $suite = $this->actingAs($this->operator)->postJson($this->suitesBase(), [
            'name' => 'Concurrency limit fixture suite',
            'agent_identifier' => 'home-automation-agent',
        ])->assertStatus(200)->json();

        for ($i = 1; $i <= 5; $i++) {
            $this->actingAs($this->operator)->postJson($this->suitesBase().'/'.$suite['id'].'/cases', [
                'given' => "Case number {$i}",
                'expected_behavior' => 'Acknowledge the case.',
                'expectations' => [['kind' => 'text_match', 'expected_text' => 'OK']],
            ])->assertStatus(200);
        }

        return $suite['id'];
    }

    private function limiterNameOf(RateLimited $middleware): string
    {
        $property = new \ReflectionProperty($middleware, 'limiterName');
        $property->setAccessible(true);

        return (string) $property->getValue($middleware);
    }

    // ---------------------------------------------------------------
    // RunEvalCaseJob::middleware() attaches the named RateLimited
    // middleware — proven directly against the job class in isolation.
    // ---------------------------------------------------------------

    #[Test]
    public function run_eval_case_job_declares_a_rate_limited_middleware_for_the_named_eval_run_cases_limiter(): void
    {
        $job = new RunEvalCaseJob('dummy-run-id', 'dummy-case-id');

        $middleware = $job->middleware();

        $this->assertIsArray($middleware, "RunEvalCaseJob::middleware() must exist and return an array (research.md D9)");
        $this->assertCount(1, $middleware, 'exactly one RateLimited middleware instance is expected');
        $this->assertInstanceOf(RateLimited::class, $middleware[0]);
        $this->assertSame(
            'eval-run-cases',
            $this->limiterNameOf($middleware[0]),
            'the middleware must be bound to the named eval-run-cases limiter, not an unnamed/default one'
        );
    }

    // ---------------------------------------------------------------
    // Every case job a real, over-the-limit run dispatches carries the
    // middleware — proven end-to-end through EvalRunService::start(),
    // not just against a bare job instance.
    // ---------------------------------------------------------------

    #[Test]
    public function every_case_job_a_real_run_dispatches_is_admitted_immediately_but_still_carries_the_rate_limited_middleware(): void
    {
        config(['llm-client.eval_runs.max_cases_per_minute' => 2]);

        Bus::fake([RunEvalCaseJob::class]);

        $response = $this->actingAs($this->operator)
            ->postJson($this->suitesBase().'/'.$this->suiteId.'/runs');
        $response->assertStatus(201);

        // Admission is not throttled — all 5 cases (more than the
        // configured cap of 2) are dispatched in the same run start, per
        // research.md D9: "a run with 500 cases enqueues all 500 jobs
        // immediately, but the rate limiter throttles how fast they
        // actually execute". Throughput is the middleware's job, not the
        // dispatcher's.
        Bus::assertDispatchedTimes(RunEvalCaseJob::class, 5);

        Bus::assertDispatched(RunEvalCaseJob::class, function (RunEvalCaseJob $job) {
            return collect($job->middleware())->contains(
                fn ($m) => $m instanceof RateLimited && $this->limiterNameOf($m) === 'eval-run-cases'
            );
        });
    }

    // ---------------------------------------------------------------
    // The named limiter itself is registered with the configured cap
    // ---------------------------------------------------------------

    #[Test]
    public function the_named_eval_run_cases_rate_limiter_is_registered_and_reads_the_configured_per_minute_cap(): void
    {
        config(['llm-client.eval_runs.max_cases_per_minute' => 7]);

        $limiterCallback = RateLimiter::limiter('eval-run-cases');

        $this->assertNotNull(
            $limiterCallback,
            "a named 'eval-run-cases' RateLimiter must be registered via RateLimiter::for() in LlmClientServiceProvider (research.md D9)"
        );

        $limit = $limiterCallback(new RunEvalCaseJob('dummy-run-id', 'dummy-case-id'));
        $limit = is_array($limit) || $limit instanceof \Illuminate\Support\Collection ? collect($limit)->first() : $limit;

        $this->assertInstanceOf(Limit::class, $limit);
        $this->assertSame(
            7,
            $limit->maxAttempts,
            "the limiter must read config('llm-client.eval_runs.max_cases_per_minute') at call time, not a hardcoded cap"
        );
        $this->assertSame(60, $limit->decaySeconds, 'a "per minute" cap must decay over 60 seconds');
    }

    #[Test]
    public function the_named_eval_run_cases_rate_limiter_falls_back_to_the_documented_default_of_thirty_per_minute(): void
    {
        // Genuinely unset (not merely null) the config key, so
        // config('...max_cases_per_minute', 30) is actually exercising its
        // own default argument rather than reading an explicit null.
        config()->offsetUnset('llm-client.eval_runs.max_cases_per_minute');

        $limiterCallback = RateLimiter::limiter('eval-run-cases');
        $this->assertNotNull($limiterCallback);

        $limit = $limiterCallback(new RunEvalCaseJob('dummy-run-id', 'dummy-case-id'));
        $limit = is_array($limit) || $limit instanceof \Illuminate\Support\Collection ? collect($limit)->first() : $limit;

        $this->assertInstanceOf(Limit::class, $limit);
        $this->assertSame(30, $limit->maxAttempts, "config('llm-client.eval_runs.max_cases_per_minute', 30) must fall back to 30 when unset");
    }
}
