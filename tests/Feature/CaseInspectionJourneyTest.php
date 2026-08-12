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
 * An operator looking at a reported difference needs to open it and see
 * the two actual responses side by side, so the report is a starting
 * point for investigation rather than a bare category label. Covers:
 * full detail (both sides' produced_response/expectation_results) for a
 * regressed case with boolean history evidence attached; the same full
 * detail for a materially_drifted case with numeric history evidence
 * attached; that an unchanged or improved case can be opened identically
 * (not only a regressed one), with no history_used since neither carries
 * a confidence verdict; that whichever side has no result at all reports
 * as a literal null, never an object with null fields, for added,
 * removed, and matched-but-incomplete-side cases; and the 404/422 rules
 * at this specific route.
 */
class CaseInspectionJourneyTest extends TestCase
{
    private User $operator;
    private Server $agentServer;

    private bool $noisyShouldPass = true;
    private int $warmthScore = 8;

    protected function setUp(): void
    {
        parent::setUp();

        $this->declareSupportingSchema();

        $this->operator = User::factory()->create();
        config(['llm-client.cost.operator_user_ids' => [$this->operator->id]]);

        $this->agentServer = Server::create([
            'name' => 'Case inspection fixture server',
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

    private function caseDetailUrl(string $runId, string $evalCaseId): string
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

    private function createSuite(string $agentIdentifier, string $name): string
    {
        return $this->actingAs($this->operator)->postJson($this->suitesBase(), [
            'name' => $name,
            'agent_identifier' => $agentIdentifier,
        ])->assertStatus(200)->json('id');
    }

    private function addTextMatchCase(string $suiteId, string $word): string
    {
        return $this->actingAs($this->operator)->postJson($this->suitesBase().'/'.$suiteId.'/cases', [
            'given' => "Say the word {$word}",
            'expected_behavior' => "Answer with the single word {$word}.",
            'expectations' => [['kind' => 'text_match', 'expected_text' => $word]],
        ])->assertStatus(200)->json('id');
    }

    private function textChatResponse(string $content): array
    {
        return [
            'choices' => [['message' => ['content' => $content]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];
    }

    /**
     * Echoes back whichever word a "Say the word X" case prompt asks
     * for, so every text_match case built by addTextMatchCase() passes
     * by default — the tests that need a case to fail flip the relevant
     * flag/closure of their own instead.
     */
    private function echoingProvider(): \Closure
    {
        return function (string $firstUser) {
            if (preg_match('/Say the word (\S+)/', $firstUser, $m)) {
                return $m[1];
            }

            return 'Acknowledged.';
        };
    }

    /**
     * Routes the agent's own model call through $agentResponder (given
     * the first user-turn content, returns the text to answer with) and,
     * when a judge server/responder pair is supplied, the judge's model
     * call through $judgeResponder.
     */
    private function fakeProviders(\Closure $agentResponder, ?Server $judgeServer = null, ?\Closure $judgeResponder = null): void
    {
        Http::fake();

        $agentProvider = Mockery::mock(LlmProvider::class);
        $agentProvider->shouldReceive('chat')->andReturnUsing(function (array $messages) use ($agentResponder) {
            $firstUser = collect($messages)->firstWhere('role', 'user')['content'] ?? '';

            return $this->textChatResponse($agentResponder($firstUser));
        });
        $agentProvider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $judgeProvider = null;
        if ($judgeServer !== null && $judgeResponder !== null) {
            $judgeProvider = Mockery::mock(LlmProvider::class);
            $judgeProvider->shouldReceive('chat')->andReturnUsing(function (array $messages) use ($judgeResponder) {
                $systemContent = collect($messages)->firstWhere('role', 'system')['content'] ?? '';

                return $this->textChatResponse(json_encode($judgeResponder($systemContent)));
            });
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

    private function designate(string $runId)
    {
        return $this->actingAs($this->operator)->postJson($this->referenceUrl($runId));
    }

    private function getCaseDetail(string $runId, string $evalCaseId)
    {
        return $this->actingAs($this->operator)->getJson($this->caseDetailUrl($runId, $evalCaseId));
    }

    private function evalRunCaseIdFor(string $runId, string $evalCaseId): string
    {
        return DB::table('eval_run_cases')
            ->where('run_id', $runId)
            ->where('eval_case_id', $evalCaseId)
            ->value('id');
    }

    private function leaveOneCaseForeverIncompleteAndMarkRunIncomplete(array $started, string $evalCaseIdToLeaveIncomplete): array
    {
        $incompleteRunCaseId = $this->evalRunCaseIdFor($started['run']['id'], $evalCaseIdToLeaveIncomplete);

        foreach ($started['jobs'] as $job) {
            if ($job->evalRunCaseId === $incompleteRunCaseId) {
                continue;
            }
            $job->handle(app(EvalCaseExecutor::class));
        }

        EvalRun::find($started['run']['id'])->update(['status' => EvalRunStatus::Incomplete]);

        return $this->getRun($started['run']['id']);
    }

    // =================================================================
    // A regressed case's detail carries both sides' full responses plus
    // boolean-axis history evidence (contracts §1.4/§4).
    // =================================================================

    #[Test]
    public function a_regressed_cases_detail_shows_both_full_responses_and_boolean_history_evidence(): void
    {
        $suiteId = $this->createSuite('case-inspection-regressed-agent', 'Case inspection regressed fixture suite');
        $noisyCaseId = $this->addTextMatchCase($suiteId, 'noisy');

        $this->fakeProviders(function (string $firstUser) {
            if (str_contains($firstUser, 'noisy')) {
                return $this->noisyShouldPass ? 'noisy' : 'not the right word';
            }

            return 'Acknowledged.';
        });

        $this->noisyShouldPass = true;
        $referenceRun = $this->runToCompletion($suiteId);
        $this->designate($referenceRun['id'])->assertStatus(201);

        // Five history runs (the default min_history_for_variance floor):
        // two prior failures, three prior passes.
        foreach ([true, false, true, false, true] as $shouldPass) {
            $this->noisyShouldPass = $shouldPass;
            $this->runToCompletion($suiteId);
        }

        $this->noisyShouldPass = false;
        $comparedRun = $this->runToCompletion($suiteId);

        $comparison = $this->actingAs($this->operator)->getJson($this->comparisonUrl($comparedRun['id']));
        $comparison->assertStatus(200);
        $summary = collect($comparison->json('cases'))->firstWhere('eval_case_id', $noisyCaseId);
        $this->assertSame('regressed', $summary['category'], 'sanity: the fixture must actually land on regressed');
        $this->assertSame('ordinary_variation', $summary['confidence'], 'sanity: at least one prior failure makes this ordinary variation');

        $response = $this->getCaseDetail($comparedRun['id'], $noisyCaseId);
        $response->assertStatus(200);
        $detail = $response->json();

        $this->assertSame($noisyCaseId, $detail['eval_case_id']);
        $this->assertSame('regressed', $detail['category']);
        $this->assertSame('ordinary_variation', $detail['confidence']);
        $this->assertNull($detail['drifted_expectation_index']);

        $this->assertNotNull($detail['reference']);
        $this->assertSame($referenceRun['id'], $detail['reference']['run_id']);
        $this->assertSame('pass', $detail['reference']['outcome']);
        $this->assertSame('noisy', $detail['reference']['produced_response']);
        $this->assertNotEmpty($detail['reference']['eval_run_case_id']);
        $this->assertSame('text_match', $detail['reference']['expectation_results'][0]['kind']);
        $this->assertTrue($detail['reference']['expectation_results'][0]['met']);

        $this->assertNotNull($detail['compared']);
        $this->assertSame($comparedRun['id'], $detail['compared']['run_id']);
        $this->assertSame('fail', $detail['compared']['outcome']);
        $this->assertSame('not the right word', $detail['compared']['produced_response']);
        $this->assertFalse($detail['compared']['expectation_results'][0]['met']);

        $this->assertArrayHasKey('history_used', $detail);
        $this->assertSame(5, $detail['history_used']['sample_size']);
        $this->assertSame(2, $detail['history_used']['prior_fail_count']);
        $this->assertNull(
            $detail['history_used']['prior_score_range'],
            'the boolean axis populates prior_fail_count, never prior_score_range'
        );
    }

    // =================================================================
    // A materially_drifted case's detail carries the numeric-axis
    // history evidence instead — the other half of contracts §1.4's
    // "exactly one of the two is populated" rule.
    // =================================================================

    #[Test]
    public function a_materially_drifted_cases_detail_shows_both_full_responses_and_numeric_history_evidence(): void
    {
        $suiteId = $this->createSuite('case-inspection-drift-agent', 'Case inspection drift fixture suite');

        $warmthCaseId = $this->actingAs($this->operator)->postJson($this->suitesBase().'/'.$suiteId.'/cases', [
            'given' => 'The customer asks for an update on their delayed order.',
            'expected_behavior' => 'Respond with warmth and empathy for the delay.',
            'expectations' => [
                ['kind' => 'rubric_judgment', 'criteria' => 'The response must convey genuine warmth and empathy for the delay.'],
            ],
        ])->assertStatus(200)->json('id');

        $judgeServer = Server::create([
            'name' => 'Case inspection drift fixture judge server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'provider_type' => 'openai',
        ]);
        RoleAssignment::create([
            'role' => 'judge',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $judgeServer->id,
            'model' => 'judge-test-model',
        ]);

        $this->fakeProviders(
            fn () => "I'm sorry for the delay — your order will now arrive by Thursday.",
            $judgeServer,
            fn () => ['score' => $this->warmthScore, 'justification' => 'ok'],
        );

        $this->warmthScore = 9;
        $referenceRun = $this->runToCompletion($suiteId);
        $this->assertSame('completed', $referenceRun['status']);
        $this->designate($referenceRun['id'])->assertStatus(201);

        // Five history runs (the default min_history_for_variance floor);
        // the lowest prior score is 7, the highest is 9.
        foreach ([9, 7, 8, 9, 8] as $score) {
            $this->warmthScore = $score;
            $this->runToCompletion($suiteId);
        }

        // Drops by exactly the material threshold (2) from the
        // reference's 9, landing at 7 — still a clean pass (>= the
        // default passing_score of 7), and 7 sits within this case's own
        // historical range (min 7) so the axis reads ordinary_variation.
        $this->warmthScore = 7;
        $comparedRun = $this->runToCompletion($suiteId);
        $this->assertSame('completed', $comparedRun['status']);

        $comparison = $this->actingAs($this->operator)->getJson($this->comparisonUrl($comparedRun['id']));
        $comparison->assertStatus(200);
        $summary = collect($comparison->json('cases'))->firstWhere('eval_case_id', $warmthCaseId);
        $this->assertSame('materially_drifted', $summary['category'], 'sanity: the fixture must actually land on materially_drifted');

        $response = $this->getCaseDetail($comparedRun['id'], $warmthCaseId);
        $response->assertStatus(200);
        $detail = $response->json();

        $this->assertSame('materially_drifted', $detail['category']);
        $this->assertSame('ordinary_variation', $detail['confidence']);
        $this->assertSame(0, $detail['drifted_expectation_index']);

        $this->assertNotNull($detail['reference']);
        $this->assertSame('pass', $detail['reference']['outcome']);
        $this->assertSame(9, $detail['reference']['expectation_results'][0]['score']);
        $this->assertSame('judged', $detail['reference']['expectation_results'][0]['status']);

        $this->assertNotNull($detail['compared']);
        $this->assertSame('pass', $detail['compared']['outcome']);
        $this->assertSame(7, $detail['compared']['expectation_results'][0]['score']);

        $this->assertArrayHasKey('history_used', $detail);
        $this->assertSame(5, $detail['history_used']['sample_size']);
        $this->assertSame([7, 9], $detail['history_used']['prior_score_range']);
        $this->assertNull(
            $detail['history_used']['prior_fail_count'],
            'the numeric axis populates prior_score_range, never prior_fail_count'
        );
    }

    // =================================================================
    // An unchanged or improved case opens identically to a regressed
    // one — full responses both sides, and no history_used at all since
    // neither category ever carries a confidence verdict.
    // =================================================================

    #[Test]
    public function unchanged_and_improved_cases_can_be_inspected_with_full_responses_and_no_history_used(): void
    {
        $suiteId = $this->createSuite('case-inspection-clean-agent', 'Case inspection clean fixture suite');
        $alphaCaseId = $this->addTextMatchCase($suiteId, 'alpha');
        $bravoCaseId = $this->addTextMatchCase($suiteId, 'bravo');

        $bravoShouldPass = false;

        $this->fakeProviders(function (string $firstUser) use (&$bravoShouldPass) {
            if (str_contains($firstUser, 'alpha')) {
                return 'alpha';
            }
            if (str_contains($firstUser, 'bravo')) {
                return $bravoShouldPass ? 'bravo' : 'not the right word';
            }

            return 'Acknowledged.';
        });

        $referenceRun = $this->runToCompletion($suiteId);
        $this->designate($referenceRun['id'])->assertStatus(201);

        $bravoShouldPass = true;
        $comparedRun = $this->runToCompletion($suiteId);

        $comparison = $this->actingAs($this->operator)->getJson($this->comparisonUrl($comparedRun['id']));
        $comparison->assertStatus(200);
        $byCaseId = collect($comparison->json('cases'))->keyBy('eval_case_id');
        $this->assertSame('unchanged', $byCaseId[$alphaCaseId]['category'], 'sanity');
        $this->assertSame('improved', $byCaseId[$bravoCaseId]['category'], 'sanity');

        $alphaDetail = $this->getCaseDetail($comparedRun['id'], $alphaCaseId)->assertStatus(200)->json();
        $this->assertSame('unchanged', $alphaDetail['category']);
        $this->assertNull($alphaDetail['confidence']);
        $this->assertNotNull($alphaDetail['reference']);
        $this->assertSame('pass', $alphaDetail['reference']['outcome']);
        $this->assertSame('alpha', $alphaDetail['reference']['produced_response']);
        $this->assertNotNull($alphaDetail['compared']);
        $this->assertSame('pass', $alphaDetail['compared']['outcome']);
        $this->assertSame('alpha', $alphaDetail['compared']['produced_response']);
        $this->assertArrayNotHasKey(
            'history_used',
            $alphaDetail,
            'history_used is present only when confidence is non-null'
        );

        $bravoDetail = $this->getCaseDetail($comparedRun['id'], $bravoCaseId)->assertStatus(200)->json();
        $this->assertSame('improved', $bravoDetail['category']);
        $this->assertNull($bravoDetail['confidence']);
        $this->assertNotNull($bravoDetail['reference']);
        $this->assertSame('fail', $bravoDetail['reference']['outcome']);
        $this->assertSame('not the right word', $bravoDetail['reference']['produced_response']);
        $this->assertNotNull($bravoDetail['compared']);
        $this->assertSame('pass', $bravoDetail['compared']['outcome']);
        $this->assertSame('bravo', $bravoDetail['compared']['produced_response']);
        $this->assertArrayNotHasKey('history_used', $bravoDetail);
    }

    // =================================================================
    // A side with no result at all reports as a literal null, never an
    // object with null fields — added, removed, and a matched-but-
    // incomplete-side case all exercise the same rule.
    // =================================================================

    #[Test]
    public function an_added_cases_detail_has_a_null_reference_side(): void
    {
        $this->fakeProviders($this->echoingProvider());

        $suiteId = $this->createSuite('case-inspection-added-agent', 'Case inspection added fixture suite');
        $this->addTextMatchCase($suiteId, 'alpha');

        $referenceRun = $this->runToCompletion($suiteId);
        $this->designate($referenceRun['id'])->assertStatus(201);

        $freshCaseId = $this->addTextMatchCase($suiteId, 'freshly-added');
        $comparedRun = $this->runToCompletion($suiteId);

        $comparison = $this->actingAs($this->operator)->getJson($this->comparisonUrl($comparedRun['id']));
        $comparison->assertStatus(200);
        $summary = collect($comparison->json('cases'))->firstWhere('eval_case_id', $freshCaseId);
        $this->assertSame('added', $summary['category'], 'sanity');

        $detail = $this->getCaseDetail($comparedRun['id'], $freshCaseId)->assertStatus(200)->json();
        $this->assertSame('added', $detail['category']);
        $this->assertNull($detail['reference'], 'an added case has no reference-side result at all — a literal null, not an object with null fields');
        $this->assertNotNull($detail['compared']);
        $this->assertSame('pass', $detail['compared']['outcome']);
    }

    #[Test]
    public function a_removed_cases_detail_has_a_null_compared_side(): void
    {
        $this->fakeProviders($this->echoingProvider());

        $suiteId = $this->createSuite('case-inspection-removed-agent', 'Case inspection removed fixture suite');
        $keptCaseId = $this->addTextMatchCase($suiteId, 'alpha');
        $goingAwayCaseId = $this->addTextMatchCase($suiteId, 'gamma');

        $referenceRun = $this->runToCompletion($suiteId);
        $this->designate($referenceRun['id'])->assertStatus(201);

        $this->actingAs($this->operator)
            ->deleteJson($this->suitesBase().'/'.$suiteId.'/cases/'.$goingAwayCaseId)
            ->assertStatus(204);

        $comparedRun = $this->runToCompletion($suiteId);

        $comparison = $this->actingAs($this->operator)->getJson($this->comparisonUrl($comparedRun['id']));
        $comparison->assertStatus(200);
        $summary = collect($comparison->json('cases'))->firstWhere('eval_case_id', $goingAwayCaseId);
        $this->assertSame('removed', $summary['category'], 'sanity');

        $detail = $this->getCaseDetail($comparedRun['id'], $goingAwayCaseId)->assertStatus(200)->json();
        $this->assertSame('removed', $detail['category']);
        $this->assertNotNull($detail['reference']);
        $this->assertSame('pass', $detail['reference']['outcome']);
        $this->assertNull($detail['compared'], 'a removed case has no compared-side result at all — a literal null, not an object with null fields');

        // keptCaseId is unused beyond sanity that the suite still has a
        // second, unaffected case in both runs.
        $this->assertNotEmpty($keptCaseId);
    }

    #[Test]
    public function a_matched_case_missing_a_result_on_one_side_has_a_null_reference_for_that_side(): void
    {
        $this->fakeProviders($this->echoingProvider());

        $suiteId = $this->createSuite('case-inspection-incomplete-agent', 'Case inspection incomplete fixture suite');
        $alphaCaseId = $this->addTextMatchCase($suiteId, 'alpha');
        $bravoCaseId = $this->addTextMatchCase($suiteId, 'bravo');

        $started = $this->startRun($suiteId);
        $referenceRun = $this->leaveOneCaseForeverIncompleteAndMarkRunIncomplete($started, $bravoCaseId);
        $this->assertSame('incomplete', $referenceRun['status']);

        $this->designate($referenceRun['id'])->assertStatus(201, 'an incomplete run is accepted as a reference (research.md D5)');

        $comparedRun = $this->runToCompletion($suiteId);

        $comparison = $this->actingAs($this->operator)->getJson($this->comparisonUrl($comparedRun['id']));
        $comparison->assertStatus(200);
        $summary = collect($comparison->json('cases'))->firstWhere('eval_case_id', $bravoCaseId);
        $this->assertSame('inconclusive', $summary['category'], 'sanity');
        $this->assertSame('reference_no_result', $summary['inconclusive_reason'], 'sanity');

        $detail = $this->getCaseDetail($comparedRun['id'], $bravoCaseId)->assertStatus(200)->json();
        $this->assertSame('inconclusive', $detail['category']);
        $this->assertNull($detail['confidence']);
        $this->assertNull(
            $detail['reference'],
            'the reference side never produced a result at all — a literal null, not an object with null fields, even though an eval_run_cases row exists'
        );
        $this->assertNotNull($detail['compared']);
        $this->assertSame('pass', $detail['compared']['outcome']);
        $this->assertArrayNotHasKey('history_used', $detail);

        // alphaCaseId is unused beyond sanity that the suite still has a
        // second, genuinely-matched case in both runs.
        $this->assertNotEmpty($alphaCaseId);
    }

    // =================================================================
    // 404: an unresolvable run id, or an eval case id that belongs to
    // neither run's own eval_run_cases snapshot at all.
    // =================================================================

    #[Test]
    public function an_unknown_run_id_returns_404_for_the_case_detail_endpoint(): void
    {
        $this->fakeProviders($this->echoingProvider());

        $suiteId = $this->createSuite('case-inspection-404-run-agent', 'Case inspection 404 run fixture suite');
        $caseId = $this->addTextMatchCase($suiteId, 'alpha');

        $unknownRunId = (string) Str::uuid();

        $this->getCaseDetail($unknownRunId, $caseId)->assertStatus(404);
    }

    #[Test]
    public function an_eval_case_id_absent_from_either_runs_snapshot_returns_404(): void
    {
        $this->fakeProviders($this->echoingProvider());

        $suiteId = $this->createSuite('case-inspection-404-case-agent', 'Case inspection 404 case fixture suite');
        $this->addTextMatchCase($suiteId, 'alpha');

        // A genuine eval_case id, just one that belongs to an entirely
        // different, unrelated suite — never part of either run being
        // compared here.
        $unrelatedSuiteId = $this->createSuite('case-inspection-404-case-unrelated-agent', 'Case inspection 404 case unrelated fixture suite');
        $unrelatedCaseId = $this->addTextMatchCase($unrelatedSuiteId, 'zeta');

        // A real reference is designated so the comparison's own cases
        // array is genuinely non-empty (at least "alpha") — proving the
        // 404 comes from the unrelated case id being absent from either
        // run's snapshot, not merely from there being no reference at
        // all set for this agent.
        $referenceRun = $this->runToCompletion($suiteId);
        $this->designate($referenceRun['id'])->assertStatus(201);

        $comparedRun = $this->runToCompletion($suiteId);

        $comparison = $this->actingAs($this->operator)->getJson($this->comparisonUrl($comparedRun['id']));
        $comparison->assertStatus(200);
        $this->assertNotEmpty($comparison->json('cases'), 'sanity: the comparison must contain real cases for this 404 to be meaningful');

        $this->getCaseDetail($comparedRun['id'], $unrelatedCaseId)->assertStatus(404);
    }

    // =================================================================
    // 422: the identical "not finished yet" rule proven at the run-level
    // route (IncompleteReferenceComparisonJourneyTest), now proven at
    // this case-level route too.
    // =================================================================

    #[Test]
    public function an_in_progress_run_is_refused_with_422_at_the_case_level_route(): void
    {
        $this->fakeProviders($this->echoingProvider());

        $suiteId = $this->createSuite('case-inspection-422-in-progress-agent', 'Case inspection 422 in-progress fixture suite');
        $caseId = $this->addTextMatchCase($suiteId, 'alpha');

        $started = $this->startRun($suiteId);
        $this->assertSame('in_progress', $started['run']['status']);

        $response = $this->getCaseDetail($started['run']['id'], $caseId);
        $response->assertStatus(422);
        $this->assertSame('This run has not finished yet.', $response->json('message'));
    }

    #[Test]
    public function a_failed_to_start_run_is_refused_with_422_at_the_case_level_route(): void
    {
        $this->fakeProviders($this->echoingProvider());

        $suiteId = $this->createSuite('case-inspection-422-failed-agent', 'Case inspection 422 failed fixture suite');
        $caseId = $this->addTextMatchCase($suiteId, 'alpha');

        RoleAssignment::where('role', 'inference')->delete();

        $failedRun = $this->actingAs($this->operator)
            ->postJson($this->suitesBase().'/'.$suiteId.'/runs')
            ->assertStatus(201)
            ->json();
        $this->assertSame('failed_to_start', $failedRun['status']);

        $this->getCaseDetail($failedRun['id'], $caseId)->assertStatus(422);
    }
}
