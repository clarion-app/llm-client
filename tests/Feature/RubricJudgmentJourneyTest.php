<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Jobs\RunEvalCaseJob;
use ClarionApp\LlmClient\Models\EvalJudgment;
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
 * spec.md US1 Acceptance Scenarios 1-3, end to end through real HTTP
 * endpoints: an operator writes plain-language rubric criteria for a case
 * (the existing, unmodified authoring routes — FR-016), and a real run
 * produces an automated integer score with a written justification,
 * without any human grading it first, while a second case using only the
 * pre-existing checkable expectation kinds is judged exactly as before.
 *
 * The run is driven through the real, individually-queued RunEvalCaseJob
 * and the real EvalCaseExecutor/AgentLoopService — not Queue::fake(), which
 * would prove nothing about the real per-case code path — matching the
 * "real queue-synchronous test harness" precedent already established for
 * this suite of run-execution journey tests. Only the LlmProvider itself is
 * faked (no real HTTP), with two distinct fixture servers so the agent's
 * own inference calls and the judge's calls can be told apart and answered
 * differently, exactly like a real installation with two independently
 * configured roles.
 */
class RubricJudgmentJourneyTest extends TestCase
{
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
            'name' => 'Rubric journey agent server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'provider_type' => 'openai',
        ]);

        $this->judgeServer = Server::create([
            'name' => 'Rubric journey judge server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'provider_type' => 'openai',
        ]);

        RoleAssignment::create([
            'role' => 'inference',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $this->agentServer->id,
            'model' => 'agent-test-model',
        ]);

        RoleAssignment::create([
            'role' => 'judge',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $this->judgeServer->id,
            'model' => 'judge-test-model',
        ]);

        $this->fakeProviders();
        $this->seedApiDocsCache();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->seedApiDocsCache(null);

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
     * AgentLoopService::run() consults ConversationCondenser on every call,
     * unconditionally — the EvaluationIsolationJourneyTest precedent — so
     * this table must exist even though neither of this file's cases ever
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
     * Two distinct fixture providers, resolved per-server (the way a real
     * installation with two independently configured roles would be) —
     * never distinguished by inspecting message content, which would be
     * fragile and would not prove the two roles are genuinely independent.
     */
    private function fakeProviders(): void
    {
        Http::fake();

        $agentProvider = Mockery::mock(LlmProvider::class);
        $agentProvider->shouldReceive('chat')->andReturnUsing(function (array $messages) {
            $firstUser = collect($messages)->firstWhere('role', 'user')['content'] ?? '';

            if (str_contains($firstUser, 'three days late')) {
                return $this->textChatResponse(
                    "I understand this has been frustrating, and I'm sorry for the delay. Let me help make this right for you."
                );
            }

            if (str_contains($firstUser, '2 + 2')) {
                return $this->textChatResponse('4');
            }

            return $this->textChatResponse('Acknowledged.');
        });
        $agentProvider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $judgeProvider = Mockery::mock(LlmProvider::class);
        $judgeProvider->shouldReceive('chat')->andReturnUsing(function () {
            return $this->textChatResponse(json_encode([
                'score' => 9,
                'justification' => "The response opens by acknowledging the customer's frustration before offering to help, matching the criteria.",
            ]));
        });
        $judgeProvider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $agentServerId = $this->agentServer->id;
        $judgeServerId = $this->judgeServer->id;

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturnUsing(
            function (Server $server) use ($agentServerId, $judgeServerId, $agentProvider, $judgeProvider) {
                return $server->id === $judgeServerId ? $judgeProvider : $agentProvider;
            }
        );
        $registry->shouldReceive('resolveByType')->andReturn($agentProvider);
        $this->app->instance(ProviderRegistry::class, $registry);
    }

    private function createSuite(): string
    {
        return $this->actingAs($this->operator)->postJson($this->suitesBase(), [
            'name' => 'Rubric judgment journey fixture suite',
            'agent_identifier' => 'customer-support-agent',
        ])->assertStatus(200)->json('id');
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

    private function getRunCases(string $runId): array
    {
        return $this->actingAs($this->operator)->getJson($this->runsBase().'/'.$runId.'/cases')->assertStatus(200)->json();
    }

    // ---------------------------------------------------------------
    // Scenario 1 (authoring half) / FR-001: writing a case with a
    // rubric_judgment expectation succeeds, and the version-listing
    // route surfaces requires_rubric_judgment alongside the pre-existing
    // requires_human_judgment flag.
    // ---------------------------------------------------------------

    #[Test]
    public function authoring_a_case_with_a_rubric_judgment_expectation_succeeds_and_versions_shows_requires_rubric_judgment(): void
    {
        $suiteId = $this->createSuite();

        $case = $this->actingAs($this->operator)->postJson($this->suitesBase().'/'.$suiteId.'/cases', [
            'given' => 'The customer says the delivery was three days late and is very upset.',
            'expected_behavior' => "Acknowledge the customer's frustration before offering a solution.",
            'expectations' => [[
                'kind' => 'rubric_judgment',
                'criteria' => "The response must acknowledge the customer's frustration before offering a solution.",
            ]],
        ])->assertStatus(200)->json();

        $versions = $this->actingAs($this->operator)
            ->getJson($this->suitesBase().'/'.$suiteId.'/cases/'.$case['id'].'/versions')
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(1, $versions);
        $this->assertTrue($versions[0]['requires_rubric_judgment']);
        $this->assertArrayHasKey('requires_human_judgment', $versions[0]);
        $this->assertFalse($versions[0]['requires_human_judgment']);
    }

    // ---------------------------------------------------------------
    // The suite-detail listing (EvalSuiteController::formatCase()) must
    // show the identical flag, not only the per-case versions() route.
    // ---------------------------------------------------------------

    #[Test]
    public function the_suite_detail_listing_also_shows_requires_rubric_judgment_for_the_same_case(): void
    {
        $suiteId = $this->createSuite();

        $this->actingAs($this->operator)->postJson($this->suitesBase().'/'.$suiteId.'/cases', [
            'given' => 'The customer says the delivery was three days late and is very upset.',
            'expected_behavior' => "Acknowledge the customer's frustration before offering a solution.",
            'expectations' => [[
                'kind' => 'rubric_judgment',
                'criteria' => "The response must acknowledge the customer's frustration before offering a solution.",
            ]],
        ])->assertStatus(200);

        $suite = $this->actingAs($this->operator)->getJson($this->suitesBase().'/'.$suiteId)->assertStatus(200)->json();

        $this->assertCount(1, $suite['cases']);
        $this->assertTrue($suite['cases'][0]['requires_rubric_judgment']);
    }

    // ---------------------------------------------------------------
    // Scenario 3 / FR-002: a rubric_judgment expectation with no
    // criteria is rejected before a case is ever written.
    // ---------------------------------------------------------------

    #[Test]
    public function authoring_a_rubric_judgment_expectation_without_criteria_is_rejected(): void
    {
        $suiteId = $this->createSuite();

        $response = $this->actingAs($this->operator)->postJson($this->suitesBase().'/'.$suiteId.'/cases', [
            'given' => 'The customer says the delivery was three days late.',
            'expected_behavior' => 'Acknowledge the frustration.',
            'expectations' => [['kind' => 'rubric_judgment']],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, DB::table('eval_cases')->count());
    }

    // ---------------------------------------------------------------
    // Scenarios 1 (judging half) + 2, FR-004/SC-001: a real run produces
    // a judged score with a justification for the rubric-judged case, and
    // a second, ordinary case is judged exactly as 078 already built it —
    // rubric judging never touches a case that did not opt in.
    // ---------------------------------------------------------------

    #[Test]
    public function a_full_run_judges_the_rubric_case_automatically_and_leaves_the_ordinary_case_unaffected(): void
    {
        $suiteId = $this->createSuite();

        $criteria = "The response must acknowledge the customer's frustration before offering a solution.";

        $rubricCase = $this->actingAs($this->operator)->postJson($this->suitesBase().'/'.$suiteId.'/cases', [
            'given' => 'The customer says the delivery was three days late and is very upset.',
            'expected_behavior' => "Acknowledge the customer's frustration before offering a solution.",
            'expectations' => [['kind' => 'rubric_judgment', 'criteria' => $criteria]],
        ])->assertStatus(200)->json();

        $ordinaryCase = $this->actingAs($this->operator)->postJson($this->suitesBase().'/'.$suiteId.'/cases', [
            'given' => 'What is 2 + 2?',
            'expected_behavior' => 'State the correct sum.',
            'expectations' => [['kind' => 'text_match', 'expected_text' => '4']],
        ])->assertStatus(200)->json();

        $started = $this->startRun($suiteId);
        $this->driveDispatchedCaseJobsToCompletion();

        $cases = $this->getRunCases($started['id']);
        $byCaseId = collect($cases['data'])->keyBy('eval_case_id');

        // --- the rubric-judged case: an automated score + justification,
        // no human involved ---
        $rubricResult = $byCaseId[$rubricCase['id']];
        $rubricExpectation = $rubricResult['expectation_results'][0];

        $this->assertSame('rubric_judgment', $rubricExpectation['kind']);
        $this->assertSame('judged', $rubricExpectation['status']);
        $this->assertIsInt($rubricExpectation['score']);
        $this->assertGreaterThanOrEqual(1, $rubricExpectation['score']);
        $this->assertLessThanOrEqual((int) config('llm-client.eval_judging.score_scale_max', 10), $rubricExpectation['score']);
        $this->assertArrayHasKey('judgment_id', $rubricExpectation);
        $this->assertNotEmpty($rubricExpectation['judgment_id']);

        // The written justification lives on the eval_judgments row this
        // run wrote (research.md D12/data-model.md §1) — read directly,
        // since the operator-facing GET /eval-judgments/{id} endpoint is
        // not part of this user story.
        $judgment = EvalJudgment::find($rubricExpectation['judgment_id']);
        $this->assertNotNull($judgment, 'the case execution must have written an eval_judgments row for this expectation');
        $this->assertSame('judged', $judgment->status);
        $this->assertNotEmpty($judgment->justification);
        $this->assertStringContainsString('frustration', $judgment->justification);
        $this->assertSame($rubricResult['id'], $judgment->eval_case_result_id);

        // --- the ordinary case: byte-identical to 078's own shape,
        // rubric judging never touched it ---
        $ordinaryResult = $byCaseId[$ordinaryCase['id']];
        $this->assertSame('pass', $ordinaryResult['outcome']);
        $this->assertTrue($ordinaryResult['expectation_results'][0]['met']);
        $this->assertSame('text_match', $ordinaryResult['expectation_results'][0]['kind']);
        $this->assertArrayNotHasKey('status', $ordinaryResult['expectation_results'][0]);
        $this->assertArrayNotHasKey('judgment_id', $ordinaryResult['expectation_results'][0]);

        $this->assertSame(
            0,
            EvalJudgment::where('eval_case_result_id', $ordinaryResult['id'])->count(),
            'a case with no rubric_judgment expectation must never produce an eval_judgments row',
        );
    }
}
