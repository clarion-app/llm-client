<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Jobs\RunEvalCaseJob;
use ClarionApp\LlmClient\Models\EvalRun;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\EvalCaseExecutor;
use ClarionApp\LlmClient\ValueObjects\EvalRunStatus;
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
 * FR-011/research.md D5: both the reference run and the compared run may
 * be `incomplete` (a run that gave up recovering a stalled case), not only
 * `completed` — a comparison must never pretend an unfinished run's gap in
 * evidence is a clean pass, a clean fail, or a reason to refuse comparing
 * altogether. FR-014/contracts §3: only a run genuinely still `in_progress`
 * or one that never started at all (`failed_to_start`) is refused, with a
 * 422, at both the run-level and case-level comparison routes.
 */
class IncompleteReferenceComparisonJourneyTest extends TestCase
{
    private User $operator;
    private Server $agentServer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->declareSupportingSchema();

        $this->operator = User::factory()->create();
        config(['llm-client.cost.operator_user_ids' => [$this->operator->id]]);

        $this->agentServer = Server::create([
            'name' => 'Incomplete reference fixture agent server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'provider_type' => 'openai',
        ]);

        RoleAssignment::create([
            'role' => 'inference',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $this->agentServer->id,
            'model' => 'agent-test-model',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        DB::table('eval_judgments')->delete();
        DB::table('eval_reference_designations')->delete();
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

    private function referenceUrl(string $runId): string
    {
        return $this->runsBase().'/'.$runId.'/reference';
    }

    private function comparisonUrl(string $runId): string
    {
        return $this->runsBase().'/'.$runId.'/comparison';
    }

    private function caseComparisonUrl(string $runId, string $evalCaseId): string
    {
        return $this->comparisonUrl($runId).'/cases/'.$evalCaseId;
    }

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

    private function textChatResponse(string $content): array
    {
        return [
            'choices' => [['message' => ['content' => $content]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            'model' => 'test-model',
        ];
    }

    /**
     * The agent always echoes a "Say the word X" case correctly and gives
     * a fixed apology response for a rubric_judgment case's prompt. Judge
     * calls (when a judge role/server is registered at all) always return
     * a passing score — the reference-side unjudged scenario is produced
     * by leaving the judge role entirely unassigned, not by a failing
     * judge response.
     */
    private function fakeProviders(?Server $judgeServer): void
    {
        Http::fake();

        $agentProvider = Mockery::mock(LlmProvider::class);
        $agentProvider->shouldReceive('chat')->andReturnUsing(function (array $messages) {
            $firstUser = collect($messages)->firstWhere('role', 'user')['content'] ?? '';

            if (preg_match('/Say the word (\S+)/', $firstUser, $m)) {
                return $this->textChatResponse($m[1]);
            }

            return $this->textChatResponse(
                "I understand this has been frustrating, and I'm sorry for the delay. Let me help make this right for you."
            );
        });
        $agentProvider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $judgeProvider = null;
        if ($judgeServer !== null) {
            $judgeProvider = Mockery::mock(LlmProvider::class);
            $judgeProvider->shouldReceive('chat')->andReturnUsing(
                fn () => $this->textChatResponse(json_encode(['score' => 8, 'justification' => 'Fine.']))
            );
            $judgeProvider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));
        }

        $agentServerId = $this->agentServer->id;
        $judgeServerId = $judgeServer?->id;

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturnUsing(
            function (Server $server) use ($agentServerId, $judgeServerId, $agentProvider, $judgeProvider) {
                if ($judgeServerId !== null && $server->id === $judgeServerId) {
                    return $judgeProvider;
                }

                return $agentProvider;
            }
        );
        $registry->shouldReceive('resolveByType')->andReturn($agentProvider);
        $this->app->instance(ProviderRegistry::class, $registry);
    }

    private function createSuiteWithTwoCheckableCases(string $agentIdentifier): array
    {
        $suite = $this->actingAs($this->operator)->postJson($this->suitesBase(), [
            'name' => 'Incomplete reference fixture suite',
            'agent_identifier' => $agentIdentifier,
        ])->assertStatus(200)->json();

        $caseIds = [];
        foreach (['alpha' => 'alpha', 'bravo' => 'bravo'] as $name => $word) {
            $case = $this->actingAs($this->operator)->postJson($this->suitesBase().'/'.$suite['id'].'/cases', [
                'given' => "Say the word {$word}",
                'expected_behavior' => "Answer with the single word {$word}.",
                'expectations' => [['kind' => 'text_match', 'expected_text' => $word]],
            ])->assertStatus(200)->json();
            $caseIds[$name] = $case['id'];
        }

        return ['suite_id' => $suite['id'], 'case_ids' => $caseIds];
    }

    private function createSuiteWithOneRubricCase(string $agentIdentifier): array
    {
        $suite = $this->actingAs($this->operator)->postJson($this->suitesBase(), [
            'name' => 'Incomplete reference rubric fixture suite',
            'agent_identifier' => $agentIdentifier,
        ])->assertStatus(200)->json();

        $case = $this->actingAs($this->operator)->postJson($this->suitesBase().'/'.$suite['id'].'/cases', [
            'given' => 'The customer says the delivery was three days late and is very upset.',
            'expected_behavior' => "Acknowledge the customer's frustration before offering a solution.",
            'expectations' => [[
                'kind' => 'rubric_judgment',
                'criteria' => "The response must acknowledge the customer's frustration before offering a solution.",
            ]],
        ])->assertStatus(200)->json();

        return ['suite_id' => $suite['id'], 'case_id' => $case['id']];
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

    private function runToCompletion(string $suiteId): array
    {
        $started = $this->startRun($suiteId);

        foreach ($started['jobs'] as $job) {
            $job->handle(app(EvalCaseExecutor::class));
        }

        return $this->getRun($started['run']['id']);
    }

    private function getRun(string $runId): array
    {
        return $this->actingAs($this->operator)->getJson($this->runsBase().'/'.$runId)->assertStatus(200)->json();
    }

    /**
     * Directly engineers a run's terminal state to `incomplete` with one
     * case genuinely never completed (no eval_case_results row at all) —
     * the end state 078's own stale-sweep exhaustion mechanism can also
     * reach (StalledRunResumptionJourneyTest), applied here directly since
     * this file's own concern is RunComparisonService's read behavior
     * against that end state, not re-proving 078's stall-detection
     * mechanism itself.
     */
    private function leaveOneCaseForeverIncompleteAndMarkRunIncomplete(array $started, string $caseIdToLeaveIncomplete): array
    {
        foreach ($started['jobs'] as $job) {
            if ($job->evalRunCaseId === $this->evalRunCaseIdFor($started['run']['id'], $caseIdToLeaveIncomplete)) {
                continue;
            }
            $job->handle(app(EvalCaseExecutor::class));
        }

        EvalRun::find($started['run']['id'])->update(['status' => EvalRunStatus::Incomplete]);

        return $this->getRun($started['run']['id']);
    }

    private function evalRunCaseIdFor(string $runId, string $evalCaseId): string
    {
        return DB::table('eval_run_cases')
            ->where('run_id', $runId)
            ->where('eval_case_id', $evalCaseId)
            ->value('id');
    }

    // =================================================================
    // Scenario A: the reference run itself is incomplete, with one case
    // that never produced a result at all.
    // =================================================================

    #[Test]
    public function an_incomplete_reference_run_is_designated_and_its_never_completed_case_is_inconclusive_with_no_result_reason(): void
    {
        $this->fakeProviders(null);
        $fixture = $this->createSuiteWithTwoCheckableCases('incomplete-reference-agent-a');

        $started = $this->startRun($fixture['suite_id']);
        $referenceRun = $this->leaveOneCaseForeverIncompleteAndMarkRunIncomplete($started, $fixture['case_ids']['bravo']);
        $this->assertSame('incomplete', $referenceRun['status']);

        $designateResponse = $this->actingAs($this->operator)->postJson($this->referenceUrl($referenceRun['id']));
        $designateResponse->assertStatus(201, 'an incomplete run is accepted as a reference, not refused (research.md D5)');

        $comparedRun = $this->runToCompletion($fixture['suite_id']);

        $comparison = $this->actingAs($this->operator)->getJson($this->comparisonUrl($comparedRun['id']));
        $comparison->assertStatus(200);
        $this->assertTrue($comparison->json('reference_incomplete'));
        $this->assertFalse($comparison->json('compared_incomplete'));

        $byCaseId = collect($comparison->json('cases'))->keyBy('eval_case_id');

        $bravo = $byCaseId[$fixture['case_ids']['bravo']];
        $this->assertSame('inconclusive', $bravo['category']);
        $this->assertSame('reference_no_result', $bravo['inconclusive_reason']);
        $this->assertNull($bravo['reference_outcome']);
        $this->assertNull($bravo['confidence']);

        // alpha genuinely completed on both sides — classified normally.
        $alpha = $byCaseId[$fixture['case_ids']['alpha']];
        $this->assertSame('unchanged', $alpha['category']);
    }

    // =================================================================
    // Scenario B: the reference run's own status is `completed`, but one
    // case's rubric expectation could never be judged — a distinct
    // inconclusive reason, and the run-level `reference_incomplete` flag
    // stays false.
    // =================================================================

    #[Test]
    public function a_completed_but_unjudged_reference_case_is_inconclusive_with_reference_unjudged_distinct_from_the_incomplete_flag(): void
    {
        // Cause 1 of JudgingServiceUnavailableJourneyTest's own precedent:
        // the judge role is entirely unassigned — the case's agent
        // response is produced normally, but its rubric expectation
        // converges on `unjudged`.
        $this->fakeProviders(null);
        $fixture = $this->createSuiteWithOneRubricCase('incomplete-reference-agent-b');

        $referenceRun = $this->runToCompletion($fixture['suite_id']);
        $this->assertSame('completed', $referenceRun['status']);

        $referenceCases = $this->actingAs($this->operator)->getJson($this->runsBase().'/'.$referenceRun['id'].'/cases')->assertStatus(200)->json();
        $referenceResult = collect($referenceCases['data'])->firstWhere('eval_case_id', $fixture['case_id']);
        $this->assertSame('unjudged', $referenceResult['outcome'], 'sanity: the reference-side result must genuinely be unjudged');

        $designateResponse = $this->actingAs($this->operator)->postJson($this->referenceUrl($referenceRun['id']));
        $designateResponse->assertStatus(201);

        // Restore the judge role for the second run — the rubric
        // expectation is now genuinely scored.
        $judgeServer = Server::create([
            'name' => 'Incomplete reference fixture judge server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'provider_type' => 'openai',
        ]);
        RoleAssignment::create([
            'role' => 'judge',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $judgeServer->id,
            'model' => 'judge-test-model',
        ]);
        $this->fakeProviders($judgeServer);

        $comparedRun = $this->runToCompletion($fixture['suite_id']);
        $this->assertSame('completed', $comparedRun['status']);

        $comparison = $this->actingAs($this->operator)->getJson($this->comparisonUrl($comparedRun['id']));
        $comparison->assertStatus(200);
        $this->assertFalse(
            $comparison->json('reference_incomplete'),
            'the reference run\'s own status was completed — the run-level flag must stay false even though this one case is unjudged'
        );

        $entry = collect($comparison->json('cases'))->firstWhere('eval_case_id', $fixture['case_id']);
        $this->assertSame('inconclusive', $entry['category']);
        $this->assertSame('reference_unjudged', $entry['inconclusive_reason']);
        $this->assertNull($entry['confidence']);
    }

    // =================================================================
    // Scenario C: symmetric widening — a *compared* run ending incomplete
    // still returns 200 with real classifications for every case that did
    // complete, never a blanket refusal.
    // =================================================================

    #[Test]
    public function a_compared_run_ending_incomplete_still_returns_real_classifications_plus_compared_incomplete_true(): void
    {
        $this->fakeProviders(null);
        $fixture = $this->createSuiteWithTwoCheckableCases('incomplete-reference-agent-c');

        $referenceRun = $this->runToCompletion($fixture['suite_id']);
        $this->assertSame('completed', $referenceRun['status']);
        $this->actingAs($this->operator)->postJson($this->referenceUrl($referenceRun['id']))->assertStatus(201);

        $started = $this->startRun($fixture['suite_id']);
        $comparedRun = $this->leaveOneCaseForeverIncompleteAndMarkRunIncomplete($started, $fixture['case_ids']['bravo']);
        $this->assertSame('incomplete', $comparedRun['status']);

        $comparison = $this->actingAs($this->operator)->getJson($this->comparisonUrl($comparedRun['id']));
        $comparison->assertStatus(200, 'an incomplete compared run must never be refused outright — only in_progress/failed_to_start are (research.md D5)');
        $this->assertFalse($comparison->json('reference_incomplete'));
        $this->assertTrue($comparison->json('compared_incomplete'));

        $byCaseId = collect($comparison->json('cases'))->keyBy('eval_case_id');

        $alpha = $byCaseId[$fixture['case_ids']['alpha']];
        $this->assertSame('unchanged', $alpha['category'], 'a case that did complete on the incomplete side must still be classified normally');

        $bravo = $byCaseId[$fixture['case_ids']['bravo']];
        $this->assertSame('inconclusive', $bravo['category']);
        $this->assertSame('compared_no_result', $bravo['inconclusive_reason']);
        $this->assertNull($bravo['compared_outcome']);
    }

    // =================================================================
    // Scenario D: FR-014 — only in_progress/failed_to_start are refused,
    // with 422, at the run-level comparison route. The identical rule at
    // the case-level route (.../comparison/cases/{evalCaseId}) is proved
    // separately once that route exists, alongside its own full coverage.
    // =================================================================

    #[Test]
    public function an_in_progress_run_is_refused_with_422_at_the_run_level_comparison_route(): void
    {
        $this->fakeProviders(null);
        $fixture = $this->createSuiteWithTwoCheckableCases('incomplete-reference-agent-d1');

        $started = $this->startRun($fixture['suite_id']);
        $this->assertSame('in_progress', $started['run']['status']);

        $runResponse = $this->actingAs($this->operator)->getJson($this->comparisonUrl($started['run']['id']));
        $runResponse->assertStatus(422);
        $this->assertSame('This run has not finished yet.', $runResponse->json('message'));
    }

    #[Test]
    public function a_failed_to_start_run_is_refused_with_422_at_the_run_level_comparison_route(): void
    {
        $this->fakeProviders(null);
        $fixture = $this->createSuiteWithTwoCheckableCases('incomplete-reference-agent-d2');

        RoleAssignment::where('role', 'inference')->delete();

        $failedRun = $this->actingAs($this->operator)
            ->postJson($this->suitesBase().'/'.$fixture['suite_id'].'/runs')
            ->assertStatus(201)
            ->json();
        $this->assertSame('failed_to_start', $failedRun['status']);

        $runResponse = $this->actingAs($this->operator)->getJson($this->comparisonUrl($failedRun['id']));
        $runResponse->assertStatus(422);
    }

    // =================================================================
    // Scenario E: 404 for a run id that resolves to nothing at all.
    // =================================================================

    #[Test]
    public function an_unknown_run_id_returns_404_for_the_comparison_endpoint(): void
    {
        $unknownId = (string) Str::uuid();

        $this->actingAs($this->operator)->getJson($this->comparisonUrl($unknownId))->assertStatus(404);
    }
}
