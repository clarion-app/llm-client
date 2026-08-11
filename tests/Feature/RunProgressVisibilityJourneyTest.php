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
 * spec.md US3 Acceptance Scenarios 1-2 and quickstart.md step 3: while a
 * run is still underway, an operator can see how many cases have
 * completed and how many remain, and an already-completed case's full
 * result is visible immediately, without waiting for the whole run to
 * finish (SC-002).
 *
 * This story needs no new production code of its own — it is a direct
 * consequence of US1's per-case async dispatch (Phase 3) plus its
 * already-built read endpoints (this phase's own Goal note) — so every
 * assertion in this file is expected to already be green against the
 * code Phase 3/4 already built, not red.
 */
class RunProgressVisibilityJourneyTest extends TestCase
{
    private const CASE_COUNT = 12;

    private User $operator;
    private string $suiteId;

    protected function setUp(): void
    {
        parent::setUp();

        // AgentLoopService::run() (driven here for real, via
        // EvalCaseExecutor) consults ConversationCondenser on every call —
        // the RunSuiteJourneyTest precedent, needed by every eval-run test
        // that drives a case job's real handle().
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
            'name' => 'Progress visibility test server',
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

        $this->suiteId = $this->createSuiteWithTwelveCases();
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

    /**
     * A suite with more cases than can plausibly finish "instantly", so a
     * test can meaningfully observe a run mid-flight by driving only some
     * of its dispatched jobs (spec.md US3's own "enough cases to take
     * noticeable time" framing, simulated here by partial driving rather
     * than by actually waiting).
     */
    private function createSuiteWithTwelveCases(): string
    {
        $suite = $this->actingAs($this->operator)->postJson($this->suitesBase(), [
            'name' => 'Progress visibility fixture suite',
            'agent_identifier' => 'home-automation-agent',
        ])->assertStatus(200)->json();

        for ($i = 1; $i <= self::CASE_COUNT; $i++) {
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
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];
    }

    /**
     * Starts a run with dispatch captured (not executed) by Bus::fake(), so
     * the response reflects the true "just started" state rather than one
     * already advanced by same-process job execution — the
     * RunSuiteJourneyTest precedent.
     */
    private function startRun(string $suiteId): array
    {
        Bus::fake([RunEvalCaseJob::class]);

        return $this->actingAs($this->operator)
            ->postJson($this->suitesBase().'/'.$suiteId.'/runs')
            ->assertStatus(201)
            ->json();
    }

    /**
     * Drives exactly $count of the captured case jobs to completion,
     * simulating a run that is genuinely still underway — the rest remain
     * dispatched-but-not-yet-executed, exactly like a real in-flight run a
     * mid-run operator would observe.
     */
    private function driveOnly(int $count): void
    {
        $jobs = Bus::dispatched(RunEvalCaseJob::class)->all();

        foreach (array_slice($jobs, 0, $count) as $job) {
            $job->handle(app(EvalCaseExecutor::class));
        }
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
    // US3 Acceptance Scenario 1 — how many completed, how many remain
    // ---------------------------------------------------------------

    #[Test]
    public function progress_counts_reflect_exactly_the_cases_completed_so_far_while_the_run_is_still_in_progress(): void
    {
        $started = $this->startRun($this->suiteId);
        $this->driveOnly(5);

        $run = $this->getRun($started['id']);

        $this->assertSame('in_progress', $run['status'], 'the run must not appear completed while 7 of 12 cases are still outstanding');
        $this->assertSame(self::CASE_COUNT, $run['case_count']);
        $this->assertSame(5, $run['completed_count']);
        $this->assertSame(self::CASE_COUNT - 5, $run['remaining_count']);
        $this->assertGreaterThan(0, $run['completed_count']);
        $this->assertLessThan($run['case_count'], $run['completed_count']);

        // FR-007: no overall verdict until the run actually finishes.
        $this->assertNull($run['overall']);
    }

    // ---------------------------------------------------------------
    // US3 Acceptance Scenario 2 — a completed case's result is visible
    // immediately, without waiting for the whole run to finish
    // ---------------------------------------------------------------

    #[Test]
    public function completed_case_results_are_visible_immediately_with_their_full_produced_response_before_the_run_finishes(): void
    {
        $started = $this->startRun($this->suiteId);
        $this->driveOnly(5);

        $cases = $this->getRunCases($started['id']);

        $this->assertCount(
            5,
            $cases['data'],
            'only rows with a completed result are ever returned (contracts §3) — exactly the 5 already-driven cases'
        );

        foreach ($cases['data'] as $case) {
            $this->assertArrayHasKey('outcome', $case);
            $this->assertNotNull($case['outcome']);
            $this->assertArrayHasKey('produced_response', $case);
            $this->assertNotEmpty(
                $case['produced_response'],
                'the full produced_response — not just a count or a placeholder — must already be visible mid-run'
            );
        }
    }

    // ---------------------------------------------------------------
    // Both signals together, across two successive mid-run observations —
    // the operator's actual "check on it more than once while it runs"
    // workflow (spec.md US3 Independent Test)
    // ---------------------------------------------------------------

    #[Test]
    public function progress_and_visible_results_both_advance_correctly_as_more_cases_complete_across_two_mid_run_checks(): void
    {
        $started = $this->startRun($this->suiteId);

        $this->driveOnly(3);
        $firstCheck = $this->getRun($started['id']);
        $this->assertSame(3, $firstCheck['completed_count']);
        $firstCases = $this->getRunCases($started['id']);
        $this->assertCount(3, $firstCases['data']);

        $this->driveOnly(9);
        $secondCheck = $this->getRun($started['id']);
        $this->assertSame(9, $secondCheck['completed_count']);
        $this->assertSame('in_progress', $secondCheck['status']);
        $secondCases = $this->getRunCases($started['id']);
        $this->assertCount(9, $secondCases['data']);

        // The first check's already-produced results must still be present,
        // unchanged, among the second check's results (US3 scenario 3's
        // durability guarantee, observed here across two live reads rather
        // than across an interruption).
        $firstIds = collect($firstCases['data'])->pluck('id')->all();
        $secondIds = collect($secondCases['data'])->pluck('id')->all();
        foreach ($firstIds as $id) {
            $this->assertContains($id, $secondIds);
        }
    }
}
