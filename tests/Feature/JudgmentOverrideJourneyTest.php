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
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * spec.md US3 Acceptance Scenarios 1-3, through real HTTP endpoints: an
 * operator can read a produced judgment's score and justification, correct
 * it, and have the correction recorded as a new, attributed override — the
 * original automated judgment stays visible unchanged, and the case's
 * downstream pass/fail reporting reflects the correction via a separate,
 * additive column rather than rewriting anything 078 already wrote once.
 *
 * A real judgment is produced through the same queue-driven run harness
 * RubricJudgmentJourneyTest already established (real EvalCaseExecutor,
 * real RunEvalCaseJob, only the LlmProvider itself faked) so this file
 * exercises the override endpoints against a genuine, end-to-end-produced
 * row rather than one hand-assembled purely for the override call.
 */
class JudgmentOverrideJourneyTest extends TestCase
{
    private User $operator;
    private Server $agentServer;
    private Server $judgeServer;

    /** @var array<int, int> consumed in order by successive judge chat() calls */
    private array $judgeScores = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->declareSupportingSchema();

        $this->operator = User::factory()->create();
        config(['llm-client.cost.operator_user_ids' => [$this->operator->id]]);

        $this->agentServer = Server::create([
            'name' => 'Override journey agent server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'provider_type' => 'openai',
        ]);

        $this->judgeServer = Server::create([
            'name' => 'Override journey judge server',
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

        DB::table('eval_judgment_overrides')->delete();
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

    private function judgmentUrl(string $judgmentId): string
    {
        return '/api/clarion-app/llm-client/eval-judgments/'.$judgmentId;
    }

    private function overrideUrl(string $judgmentId): string
    {
        return $this->judgmentUrl($judgmentId).'/override';
    }

    /**
     * AgentLoopService::run() consults ConversationCondenser on every call,
     * unconditionally — the RubricJudgmentJourneyTest precedent — so this
     * table must exist even though no case here ever triggers a real tool
     * call.
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
     * Two distinct fixture providers, resolved per-server exactly like
     * RubricJudgmentJourneyTest: the agent always acknowledges the
     * customer's frustration (a fixed, unconditional response — no test in
     * this file cares about the agent's own wording), while the judge
     * consumes $this->judgeScores in order, one score per chat() call,
     * defaulting to 9 once the queue is empty so an unscripted call never
     * silently fails the fixture.
     */
    private function fakeProviders(): void
    {
        Http::fake();

        $agentProvider = Mockery::mock(LlmProvider::class);
        $agentProvider->shouldReceive('chat')->andReturnUsing(function () {
            return $this->textChatResponse(
                "I understand this has been frustrating, and I'm sorry for the delay. Let me help make this right for you."
            );
        });
        $agentProvider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $judgeProvider = Mockery::mock(LlmProvider::class);
        $judgeProvider->shouldReceive('chat')->andReturnUsing(function () {
            $score = array_shift($this->judgeScores) ?? 9;

            return $this->textChatResponse(json_encode([
                'score' => $score,
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

    private function createRubricCase(string $suiteId, string $criteria): array
    {
        return $this->actingAs($this->operator)->postJson($this->suitesBase().'/'.$suiteId.'/cases', [
            'given' => 'The customer says the delivery was three days late and is very upset.',
            'expected_behavior' => "Acknowledge the customer's frustration before offering a solution.",
            'expectations' => [['kind' => 'rubric_judgment', 'criteria' => $criteria]],
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

    /**
     * Authors a single-case suite with one rubric_judgment expectation,
     * runs it to completion with the given judge score, and returns
     * everything a test below needs to exercise the override endpoints
     * against a genuinely produced judgment.
     *
     * @return array{suite_id: string, case_id: string, run_id: string, case_result_id: string, judgment_id: string, criteria: string, score: int, justification: string}
     */
    private function produceJudgedCase(string $suiteName, string $criteria, int $judgeScore): array
    {
        $suiteId = $this->createSuite($suiteName);
        $case = $this->createRubricCase($suiteId, $criteria);

        $this->judgeScores = [$judgeScore];

        $started = $this->startRun($suiteId);
        $this->driveDispatchedCaseJobsToCompletion();

        $cases = $this->getRunCases($started['id']);
        $result = collect($cases['data'])->firstWhere('eval_case_id', $case['id']);
        $expectation = $result['expectation_results'][0];

        return [
            'suite_id' => $suiteId,
            'case_id' => $case['id'],
            'run_id' => $started['id'],
            'case_result_id' => $result['id'],
            'judgment_id' => $expectation['judgment_id'],
            'criteria' => $criteria,
            'score' => $expectation['score'],
            'justification' => EvalJudgment::find($expectation['judgment_id'])->justification,
        ];
    }

    private function passingScoreThreshold(): int
    {
        return (int) config('llm-client.eval_judging.passing_score', 7);
    }

    private function scoreScaleMax(): int
    {
        return (int) config('llm-client.eval_judging.score_scale_max', 10);
    }

    // ---------------------------------------------------------------
    // Scenario 1 / FR-007: reading a judgment shows its score and
    // justification.
    // ---------------------------------------------------------------

    #[Test]
    public function get_judgment_shows_its_score_and_justification(): void
    {
        $produced = $this->produceJudgedCase('Override journey — read suite', 'Acknowledge the frustration first.', 9);

        $response = $this->actingAs($this->operator)->getJson($this->judgmentUrl($produced['judgment_id']));

        $response->assertStatus(200);
        $this->assertSame(9, $response->json('score'));
        $this->assertNotEmpty($response->json('justification'));
        $this->assertSame($produced['criteria'], $response->json('criteria'));
        $this->assertSame('judged', $response->json('status'));
        $this->assertFalse($response->json('effective.overridden'));
    }

    // ---------------------------------------------------------------
    // Override with a corrected score returns 200 with the correction
    // reflected in `effective`.
    // ---------------------------------------------------------------

    #[Test]
    public function post_override_returns_200_with_the_correction_reflected_in_effective(): void
    {
        $produced = $this->produceJudgedCase('Override journey — correct suite', 'Acknowledge the frustration first.', 9);

        $response = $this->actingAs($this->operator)->postJson($this->overrideUrl($produced['judgment_id']), [
            'score' => 3,
            'justification' => 'Too generous — no concrete next step was actually offered.',
        ]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('effective.overridden'));
        $this->assertSame(3, $response->json('effective.score'));
        $this->assertSame(
            'Too generous — no concrete next step was actually offered.',
            $response->json('effective.justification'),
        );
    }

    // ---------------------------------------------------------------
    // Scenario 2 / FR-009: the original judgment stays visible unchanged
    // alongside the override's own attribution.
    // ---------------------------------------------------------------

    #[Test]
    public function overriding_never_changes_the_original_score_and_the_override_shows_who_made_it(): void
    {
        $produced = $this->produceJudgedCase('Override journey — attribution suite', 'Acknowledge the frustration first.', 9);

        $this->actingAs($this->operator)->postJson($this->overrideUrl($produced['judgment_id']), [
            'score' => 3,
            'justification' => 'Too generous — no concrete next step was actually offered.',
        ])->assertStatus(200);

        $reGet = $this->actingAs($this->operator)->getJson($this->judgmentUrl($produced['judgment_id']));
        $reGet->assertStatus(200);

        $this->assertSame(9, $reGet->json('score'), 'the original judgment score must remain visible unchanged at the top level');
        $this->assertSame($produced['justification'], $reGet->json('justification'));

        $overrides = $reGet->json('overrides');
        $this->assertCount(1, $overrides);
        $this->assertSame(3, $overrides[0]['score']);
        $this->assertSame('Too generous — no concrete next step was actually offered.', $overrides[0]['justification']);
        $this->assertSame((string) $this->operator->id, (string) $overrides[0]['user_id']);
    }

    // ---------------------------------------------------------------
    // FR-010: eval-runs/{id}/cases surfaces outcome_override alongside
    // the untouched original outcome.
    // ---------------------------------------------------------------

    #[Test]
    public function run_cases_endpoint_shows_outcome_override_while_the_original_outcome_stays_unchanged(): void
    {
        $produced = $this->produceJudgedCase('Override journey — outcome suite', 'Acknowledge the frustration first.', 9);

        $cases = $this->getRunCases($produced['run_id']);
        $before = collect($cases['data'])->firstWhere('id', $produced['case_result_id']);
        $this->assertSame('pass', $before['outcome'], 'a judged score of 9 (>= the passing threshold) must originally pass');

        $threshold = $this->passingScoreThreshold();
        $this->actingAs($this->operator)->postJson($this->overrideUrl($produced['judgment_id']), [
            'score' => $threshold - 1,
        ])->assertStatus(200);

        $after = $this->getRunCases($produced['run_id']);
        $updated = collect($after['data'])->firstWhere('id', $produced['case_result_id']);

        $this->assertSame('pass', $updated['outcome'], 'the original outcome column must never be rewritten by an override');
        $this->assertSame('fail', $updated['outcome_override'], 'the corrected pass/fail must be visible as outcome_override');
    }

    // ---------------------------------------------------------------
    // Scenario 3 / quickstart step 6: unrelated override/run activity
    // elsewhere must never prune or alter this judgment's own history.
    // ---------------------------------------------------------------

    #[Test]
    public function unrelated_override_activity_on_a_different_judgment_never_alters_this_judgments_history(): void
    {
        $primary = $this->produceJudgedCase('Override journey — isolation suite A', 'Acknowledge the frustration first.', 9);

        $this->actingAs($this->operator)->postJson($this->overrideUrl($primary['judgment_id']), [
            'score' => 3,
            'justification' => 'Primary correction.',
        ])->assertStatus(200);

        $unrelated = $this->produceJudgedCase('Override journey — isolation suite B', 'Offer a concrete refund amount.', 6);

        $this->actingAs($this->operator)->postJson($this->overrideUrl($unrelated['judgment_id']), [
            'score' => 10,
            'justification' => 'Unrelated correction, different case entirely.',
        ])->assertStatus(200);

        $reGet = $this->actingAs($this->operator)->getJson($this->judgmentUrl($primary['judgment_id']));
        $reGet->assertStatus(200);

        $this->assertSame(9, $reGet->json('score'));
        $this->assertSame($primary['justification'], $reGet->json('justification'));

        $overrides = $reGet->json('overrides');
        $this->assertCount(1, $overrides, 'unrelated activity elsewhere must never add to or prune this judgment\'s own history');
        $this->assertSame(3, $overrides[0]['score']);
        $this->assertSame('Primary correction.', $overrides[0]['justification']);
    }

    // ---------------------------------------------------------------
    // quickstart step 10 / contracts §2: an unjudged judgment cannot be
    // overridden.
    // ---------------------------------------------------------------

    #[Test]
    public function overriding_an_unjudged_judgment_returns_422(): void
    {
        $unjudged = EvalJudgment::create([
            'id' => (string) Str::uuid(),
            'eval_case_result_id' => null,
            'eval_case_version_id' => (string) Str::uuid(),
            'expectation_index' => 0,
            'criteria' => 'The response must offer a concrete next step.',
            'response_text' => null,
            'status' => 'unjudged',
            'score' => null,
            'justification' => null,
            'unjudged_reason' => 'judge role unassigned',
            'model' => null,
            'server_id' => null,
            'conversation_id' => null,
            'consistency_sample_id' => null,
        ]);

        $response = $this->actingAs($this->operator)->postJson($this->overrideUrl($unjudged->id), [
            'score' => 5,
            'justification' => 'Trying to override an unjudged judgment.',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, DB::table('eval_judgment_overrides')->count());
    }

    // ---------------------------------------------------------------
    // A supplied score outside [1, score_scale_max] is rejected.
    // ---------------------------------------------------------------

    #[Test]
    public function overriding_with_an_out_of_range_score_returns_422(): void
    {
        $produced = $this->produceJudgedCase('Override journey — range suite', 'Acknowledge the frustration first.', 9);

        $response = $this->actingAs($this->operator)->postJson($this->overrideUrl($produced['judgment_id']), [
            'score' => $this->scoreScaleMax() + 5,
            'justification' => 'Out of range on purpose.',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, DB::table('eval_judgment_overrides')->count());
    }

    // ---------------------------------------------------------------
    // Both fields omitted is rejected.
    // ---------------------------------------------------------------

    #[Test]
    public function overriding_with_neither_score_nor_justification_returns_422(): void
    {
        $produced = $this->produceJudgedCase('Override journey — empty body suite', 'Acknowledge the frustration first.', 9);

        $response = $this->actingAs($this->operator)->postJson($this->overrideUrl($produced['judgment_id']), []);

        $response->assertStatus(422);
        $this->assertSame(0, DB::table('eval_judgment_overrides')->count());
    }

    // ---------------------------------------------------------------
    // FR-015 / quickstart step 13: a judgment's criteria snapshot must
    // survive a later edit to the live case's rubric wording.
    // ---------------------------------------------------------------

    #[Test]
    public function a_judgments_criteria_snapshot_is_unaffected_by_a_later_edit_to_the_live_case(): void
    {
        $originalCriteria = "The response must acknowledge the customer's frustration before offering a solution.";
        $produced = $this->produceJudgedCase('Override journey — pinning suite', $originalCriteria, 9);

        $noted = $this->actingAs($this->operator)->getJson($this->judgmentUrl($produced['judgment_id']))
            ->assertStatus(200)
            ->json('criteria');
        $this->assertSame($originalCriteria, $noted);

        $this->actingAs($this->operator)->putJson(
            $this->suitesBase().'/'.$produced['suite_id'].'/cases/'.$produced['case_id'],
            [
                'given' => 'The customer says the delivery was three days late and is very upset.',
                'expected_behavior' => "Acknowledge the customer's frustration before offering a solution.",
                'expectations' => [[
                    'kind' => 'rubric_judgment',
                    'criteria' => 'A completely reworded rubric that bears no resemblance to the original.',
                ]],
            ],
        )->assertStatus(200);

        $reGet = $this->actingAs($this->operator)->getJson($this->judgmentUrl($produced['judgment_id']));
        $reGet->assertStatus(200);

        $this->assertSame(
            $originalCriteria,
            $reGet->json('criteria'),
            "a judgment's criteria snapshot must never be re-read from the (now-edited) live case",
        );
    }
}
