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
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * US2 Acceptance Scenarios 2-3 / FR-005, through the real HTTP endpoint
 * GET /eval-runs/{runId}/cases/{caseResultId}/detail: what a case was
 * given, what a correct response should have looked like, what the agent
 * actually produced, and why it was scored that way are all visible in
 * one call. A rubric-judged expectation carries a nested judgment object
 * (score/justification/overridden/overridden_by/overridden_at); a
 * checkable expectation carries none at all -- never a fabricated null
 * placeholder. outcome/outcome_override on this endpoint must agree
 * exactly with what the existing run-cases listing already returns for
 * the same row -- this endpoint adds fields, it never redefines them.
 * A caseResultId that is absent, belongs to a different run, or is
 * combined with a nonexistent runId all converge on one uniform 404
 * shape, indistinguishable from each other to the caller.
 */
class EvalCaseDetailJourneyTest extends TestCase
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
            'name' => 'Case detail journey agent server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'provider_type' => 'openai',
        ]);

        $this->judgeServer = Server::create([
            'name' => 'Case detail journey judge server',
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

        DB::table('eval_pass_rate_summaries')->delete();
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

    private function detailUrl(string $runId, string $caseResultId): string
    {
        return $this->runsBase().'/'.$runId.'/cases/'.$caseResultId.'/detail';
    }

    /**
     * AgentLoopService::run() consults ConversationCondenser on every call,
     * unconditionally -- so this table must exist even though neither of
     * this file's cases ever triggers a real tool call.
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
     * Two distinct fixture providers, resolved per-server -- the agent
     * always answers plainly, the judge always returns a fixed passing
     * score with a written justification, mirroring RubricJudgmentJourneyTest.
     */
    private function fakeProviders(): void
    {
        Http::fake();

        $agentProvider = Mockery::mock(LlmProvider::class);
        $agentProvider->shouldReceive('chat')->andReturnUsing(function (array $messages) {
            $firstUser = collect($messages)->firstWhere('role', 'user')['content'] ?? '';

            if (str_contains($firstUser, '2 + 2')) {
                return $this->textChatResponse('4');
            }

            return $this->textChatResponse(
                "I understand this has been frustrating, and I'm sorry for the delay. Let me help make this right for you."
            );
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

    private function createSuite(string $name): string
    {
        return $this->actingAs($this->operator)->postJson($this->suitesBase(), [
            'name' => $name,
            'agent_identifier' => 'customer-support-agent',
        ])->assertStatus(200)->json('id');
    }

    private function addRubricCase(string $suiteId, string $criteria): array
    {
        return $this->actingAs($this->operator)->postJson($this->suitesBase().'/'.$suiteId.'/cases', [
            'given' => 'The customer says the delivery was three days late and is very upset.',
            'expected_behavior' => "Acknowledge the customer's frustration before offering a solution.",
            'expectations' => [['kind' => 'rubric_judgment', 'criteria' => $criteria]],
        ])->assertStatus(200)->json();
    }

    private function addCheckableCase(string $suiteId): array
    {
        return $this->actingAs($this->operator)->postJson($this->suitesBase().'/'.$suiteId.'/cases', [
            'given' => 'What is 2 + 2?',
            'expected_behavior' => 'State the correct sum.',
            'expectations' => [['kind' => 'text_match', 'expected_text' => '4']],
        ])->assertStatus(200)->json();
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

    private function getDetail(string $runId, string $caseResultId): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->operator)->getJson($this->detailUrl($runId, $caseResultId));
    }

    private function assertUniformNotFoundShape(\Illuminate\Testing\TestResponse $response): void
    {
        $response->assertStatus(404);
        $response->assertExactJson([
            'error' => 'Case result not found',
            'code' => 'case_result_not_found',
        ]);
    }

    // ---------------------------------------------------------------
    // A rubric-judged case: given/expected_behavior/produced_response/
    // attempted_actions plus a nested judgment object for the rubric
    // expectation.
    // ---------------------------------------------------------------

    #[Test]
    public function a_rubric_judged_case_returns_the_full_detail_shape_with_a_nested_judgment(): void
    {
        $criteria = "The response must acknowledge the customer's frustration before offering a solution.";
        $suiteId = $this->createSuite('Case detail journey — rubric suite');
        $rubricCase = $this->addRubricCase($suiteId, $criteria);

        $started = $this->startRun($suiteId);
        $this->driveDispatchedCaseJobsToCompletion();

        $cases = $this->getRunCases($started['id']);
        $listedResult = collect($cases['data'])->firstWhere('eval_case_id', $rubricCase['id']);
        $this->assertNotNull($listedResult, 'fixture precondition: the run-cases listing must already show this result');

        $response = $this->getDetail($started['id'], $listedResult['id']);

        $response->assertStatus(200);
        $detail = $response->json();

        $this->assertSame($listedResult['id'], $detail['id']);
        $this->assertSame($started['id'], $detail['run_id']);
        $this->assertSame($rubricCase['id'], $detail['eval_case_id']);
        $this->assertSame($listedResult['eval_case_version_id'], $detail['eval_case_version_id']);

        // given/expected_behavior come from the pinned EvalCaseVersion.
        $this->assertSame('The customer says the delivery was three days late and is very upset.', $detail['given']);
        $this->assertSame("Acknowledge the customer's frustration before offering a solution.", $detail['expected_behavior']);

        // produced_response/attempted_actions are present.
        $this->assertNotEmpty($detail['produced_response']);
        $this->assertIsArray($detail['attempted_actions']);

        // outcome/outcome_override must agree exactly with what the
        // existing run-cases listing already returns for this row -- this
        // endpoint adds fields, it never redefines existing ones.
        $this->assertSame($listedResult['outcome'], $detail['outcome']);
        $this->assertSame($listedResult['outcome_override'], $detail['outcome_override']);

        $this->assertCount(1, $detail['expectation_results']);
        $expectation = $detail['expectation_results'][0];
        $this->assertSame('rubric_judgment', $expectation['kind']);
        $this->assertArrayHasKey('judgment_id', $expectation);
        $this->assertNotEmpty($expectation['judgment_id']);

        $this->assertArrayHasKey('judgment', $expectation, 'a rubric_judgment expectation carrying a judgment_id must carry a nested judgment object');
        $judgment = $expectation['judgment'];
        $this->assertSame(9, $judgment['score']);
        $this->assertStringContainsString('frustration', $judgment['justification']);
        $this->assertFalse($judgment['overridden']);
        $this->assertNull($judgment['overridden_by']);
        $this->assertNull($judgment['overridden_at']);
    }

    // ---------------------------------------------------------------
    // A checkable-only case: its expectation carries no judgment key at
    // all, never a fabricated null object.
    // ---------------------------------------------------------------

    #[Test]
    public function a_checkable_expectation_carries_no_judgment_key_at_all(): void
    {
        $suiteId = $this->createSuite('Case detail journey — checkable suite');
        $checkableCase = $this->addCheckableCase($suiteId);

        $started = $this->startRun($suiteId);
        $this->driveDispatchedCaseJobsToCompletion();

        $cases = $this->getRunCases($started['id']);
        $listedResult = collect($cases['data'])->firstWhere('eval_case_id', $checkableCase['id']);

        $response = $this->getDetail($started['id'], $listedResult['id']);

        $response->assertStatus(200);
        $detail = $response->json();

        $this->assertCount(1, $detail['expectation_results']);
        $expectation = $detail['expectation_results'][0];
        $this->assertSame('text_match', $expectation['kind']);
        $this->assertArrayNotHasKey('judgment_id', $expectation);
        $this->assertArrayNotHasKey(
            'judgment',
            $expectation,
            'a checkable expectation with no judgment_id must never carry a fabricated null judgment object'
        );
    }

    // ---------------------------------------------------------------
    // 404s: absent, mismatched-run, and nonexistent-run all converge on
    // one uniform shape.
    // ---------------------------------------------------------------

    #[Test]
    public function an_absent_case_result_id_returns_the_uniform_not_found_shape(): void
    {
        $suiteId = $this->createSuite('Case detail journey — 404 suite');
        $this->addCheckableCase($suiteId);

        $started = $this->startRun($suiteId);
        $this->driveDispatchedCaseJobsToCompletion();

        $response = $this->getDetail($started['id'], (string) Str::uuid());

        $this->assertUniformNotFoundShape($response);
    }

    #[Test]
    public function a_case_result_belonging_to_a_different_run_returns_the_uniform_not_found_shape(): void
    {
        $suiteId = $this->createSuite('Case detail journey — mismatched run suite');
        $checkableCase = $this->addCheckableCase($suiteId);

        $firstRun = $this->startRun($suiteId);
        $this->driveDispatchedCaseJobsToCompletion();

        $firstRunCases = $this->getRunCases($firstRun['id']);
        $firstResult = collect($firstRunCases['data'])->firstWhere('eval_case_id', $checkableCase['id']);

        $secondRun = $this->startRun($suiteId);
        $this->driveDispatchedCaseJobsToCompletion();

        // firstResult['id'] genuinely exists, but under the second run's id
        // it must be indistinguishable from an outright absent id.
        $response = $this->getDetail($secondRun['id'], $firstResult['id']);

        $this->assertUniformNotFoundShape($response);
    }

    #[Test]
    public function a_syntactically_valid_but_nonexistent_run_id_returns_the_uniform_not_found_shape(): void
    {
        $suiteId = $this->createSuite('Case detail journey — nonexistent run suite');
        $checkableCase = $this->addCheckableCase($suiteId);

        $started = $this->startRun($suiteId);
        $this->driveDispatchedCaseJobsToCompletion();

        $cases = $this->getRunCases($started['id']);
        $listedResult = collect($cases['data'])->firstWhere('eval_case_id', $checkableCase['id']);

        $response = $this->getDetail((string) Str::uuid(), $listedResult['id']);

        $this->assertUniformNotFoundShape($response);
    }
}
