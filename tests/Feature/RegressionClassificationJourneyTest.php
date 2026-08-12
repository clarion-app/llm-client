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
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An operator reviewing a comparison needs to be able to tell a
 * difference that falls within this agent's own normal run-to-run
 * variation apart from one that looks like a genuine first-ever
 * occurrence — using this agent's own accumulated run history, never a
 * fabricated statistical model. Covers: the same kind of regression
 * (pass -> fail) reading differently depending on whether this exact
 * case has ever failed before; a brand-new case with no accumulated
 * history reading honestly as insufficient rather than defaulting either
 * direction; confidence staying strictly scoped to the two categories it
 * applies to even when a single comparison contains all eight
 * categories at once; and a case with two independently-drifting rubric
 * expectations resolving to its single most severe verdict, attributed
 * to the specific expectation that produced it.
 */
class RegressionClassificationJourneyTest extends TestCase
{
    private User $operator;
    private Server $agentServer;

    private bool $noisyShouldPass = true;
    private bool $cleanShouldPass = true;
    private bool $echoShouldPass = true;
    private bool $bravoShouldPass = true;
    private bool $charlieShouldPass = true;
    private int $warmthScore = 8;
    private int $precisionScore = 8;

    protected function setUp(): void
    {
        parent::setUp();

        $this->declareSupportingSchema();

        $this->operator = User::factory()->create();
        config(['llm-client.cost.operator_user_ids' => [$this->operator->id]]);

        $this->agentServer = Server::create([
            'name' => 'Regression classification fixture server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'provider_type' => 'openai',
        ]);

        RoleAssignment::create([
            'role' => 'inference',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $this->agentServer->id,
            'model' => 'test-model',
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
     * Routes the agent's own model call through $agentResponder (given
     * the first user-turn content, returns the text to answer with) and,
     * when a judge server/responder pair is supplied, the judge's model
     * call through $judgeResponder (given the judge's own system-message
     * content, returns the ['score' => int, 'justification' => string]
     * payload to encode as its JSON response).
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

    private function getComparison(string $runId)
    {
        return $this->actingAs($this->operator)->getJson($this->comparisonUrl($runId));
    }

    private function evalRunCaseIdFor(string $runId, string $evalCaseId): string
    {
        return DB::table('eval_run_cases')
            ->where('run_id', $runId)
            ->where('eval_case_id', $evalCaseId)
            ->value('id');
    }

    /**
     * Runs every dispatched case job except the one named by
     * $evalCaseIdToLeaveIncomplete, then directly marks the run
     * incomplete — the terminal state 078's own stale-sweep exhaustion
     * mechanism also reaches, applied here directly since this file's own
     * concern is the classification a comparison produces against that
     * end state, not re-proving the stall-detection mechanism itself.
     */
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
    // Scenario 1: the same shape of regression (pass -> fail) reads
    // differently depending on whether this agent's own history already
    // contains a prior failure of that exact case (spec.md US2
    // Acceptance Scenarios 1-2, SC-002).
    // =================================================================

    #[Test]
    public function a_case_with_prior_failures_in_history_is_ordinary_variation_while_an_always_clean_case_is_likely_regression_in_the_same_response(): void
    {
        $suiteId = $this->createSuite('variance-distinction-agent', 'Variance distinction fixture suite');
        $noisyCaseId = $this->addTextMatchCase($suiteId, 'noisy');
        $cleanCaseId = $this->addTextMatchCase($suiteId, 'clean');

        $this->fakeProviders(function (string $firstUser) {
            if (str_contains($firstUser, 'noisy')) {
                return $this->noisyShouldPass ? 'noisy' : 'not the right word';
            }
            if (str_contains($firstUser, 'clean')) {
                return $this->cleanShouldPass ? 'clean' : 'not the right word';
            }

            return 'Acknowledged.';
        });

        // Reference run: both cases pass.
        $this->noisyShouldPass = true;
        $this->cleanShouldPass = true;
        $referenceRun = $this->runToCompletion($suiteId);
        $this->designate($referenceRun['id'])->assertStatus(201);

        // Five history runs (the default min_history_for_variance floor):
        // "noisy" fails once along the way, "clean" never does.
        $noisyPassSequence = [true, true, false, true, true];
        foreach ($noisyPassSequence as $shouldPass) {
            $this->noisyShouldPass = $shouldPass;
            $this->cleanShouldPass = true;
            $this->runToCompletion($suiteId);
        }

        // Compared run: both cases fail — "noisy" for (at least) the
        // second time, "clean" for the first time ever.
        $this->noisyShouldPass = false;
        $this->cleanShouldPass = false;
        $comparedRun = $this->runToCompletion($suiteId);

        $comparison = $this->getComparison($comparedRun['id']);
        $comparison->assertStatus(200);
        $byCaseId = collect($comparison->json('cases'))->keyBy('eval_case_id');

        $noisy = $byCaseId[$noisyCaseId];
        $this->assertSame('regressed', $noisy['category']);
        $this->assertSame(
            'ordinary_variation',
            $noisy['confidence'],
            'a case that has failed before under this agent must not be flagged the same way as a genuine first-ever failure'
        );

        $clean = $byCaseId[$cleanCaseId];
        $this->assertSame('regressed', $clean['category']);
        $this->assertSame(
            'likely_regression',
            $clean['confidence'],
            'a case with a clean run history that fails for the first time has no precedent and must read as a likely regression'
        );

        $this->assertNotSame(
            $noisy['confidence'],
            $clean['confidence'],
            'the two must be programmatically distinguishable within the same comparison response, never presented identically'
        );
    }

    // =================================================================
    // Scenario 2: a brand-new case with fewer than min_history_for_variance
    // prior comparable results reads honestly as insufficient_history,
    // never silently defaulted either direction.
    // =================================================================

    #[Test]
    public function a_brand_new_case_with_no_accumulated_history_is_insufficient_history_not_defaulted_either_direction(): void
    {
        $suiteId = $this->createSuite('insufficient-history-agent', 'Insufficient history fixture suite');
        $echoCaseId = $this->addTextMatchCase($suiteId, 'echo');

        $this->fakeProviders(function (string $firstUser) {
            if (str_contains($firstUser, 'echo')) {
                return $this->echoShouldPass ? 'echo' : 'not the right word';
            }

            return 'Acknowledged.';
        });

        $this->echoShouldPass = true;
        $referenceRun = $this->runToCompletion($suiteId);
        $this->designate($referenceRun['id'])->assertStatus(201);

        // No intervening runs at all — this case has zero prior,
        // comparable history the moment it is compared.
        $this->echoShouldPass = false;
        $comparedRun = $this->runToCompletion($suiteId);

        $comparison = $this->getComparison($comparedRun['id']);
        $comparison->assertStatus(200);
        $entry = collect($comparison->json('cases'))->firstWhere('eval_case_id', $echoCaseId);

        $this->assertSame('regressed', $entry['category']);
        $this->assertSame('insufficient_history', $entry['confidence']);
    }

    // =================================================================
    // Scenario 3: confidence is null for every category other than
    // regressed/materially_drifted, proven inside one comparison
    // containing all eight categories at once (research.md D8's own
    // scope note, mutation-checklist row 6).
    // =================================================================

    #[Test]
    public function confidence_stays_null_for_every_category_except_regressed_and_materially_drifted_across_all_eight_categories_at_once(): void
    {
        $suiteId = $this->createSuite('eight-category-agent', 'Eight category fixture suite');

        $alphaCaseId = $this->addTextMatchCase($suiteId, 'alpha');
        $bravoCaseId = $this->addTextMatchCase($suiteId, 'bravo');
        $charlieCaseId = $this->addTextMatchCase($suiteId, 'charlie');
        $echoCaseId = $this->addTextMatchCase($suiteId, 'echo');
        $golfCaseId = $this->addTextMatchCase($suiteId, 'golf');
        $hotelCaseId = $this->addTextMatchCase($suiteId, 'hotel');

        $deltaCaseId = $this->actingAs($this->operator)->postJson($this->suitesBase().'/'.$suiteId.'/cases', [
            'given' => 'The customer asks whether their refund has been processed yet.',
            'expected_behavior' => 'Confirm the refund status clearly and courteously.',
            'expectations' => [[
                'kind' => 'rubric_judgment',
                'criteria' => 'The response must clearly state the refund status.',
            ]],
        ])->assertStatus(200)->json('id');

        $judgeServer = Server::create([
            'name' => 'Eight category fixture judge server',
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
            function (string $firstUser) {
                if (preg_match('/Say the word (\S+)/', $firstUser, $m)) {
                    $word = $m[1];
                    if ($word === 'bravo') {
                        return $this->bravoShouldPass ? 'bravo' : 'not the right word';
                    }
                    if ($word === 'charlie') {
                        return $this->charlieShouldPass ? 'charlie' : 'not the right word';
                    }

                    return $word;
                }

                return 'Your refund has been processed and the funds are on their way.';
            },
            $judgeServer,
            fn () => ['score' => $this->warmthScore, 'justification' => 'ok'],
        );

        // Reference run: bravo passes, charlie fails, delta scores high
        // (a clean pass on its rubric expectation).
        $this->bravoShouldPass = true;
        $this->charlieShouldPass = false;
        $this->warmthScore = 9;

        $referenceStarted = $this->startRun($suiteId);
        foreach ($referenceStarted['jobs'] as $job) {
            $job->handle(app(EvalCaseExecutor::class));
        }
        $referenceRun = $this->getRun($referenceStarted['run']['id']);
        $this->assertSame('completed', $referenceRun['status']);
        $this->designate($referenceRun['id'])->assertStatus(201);

        // Suite drift between the reference and compared runs: golf
        // archived, hotel edited, foxtrot added fresh.
        $this->actingAs($this->operator)
            ->deleteJson($this->suitesBase().'/'.$suiteId.'/cases/'.$golfCaseId)
            ->assertStatus(204);

        $this->actingAs($this->operator)
            ->putJson($this->suitesBase().'/'.$suiteId.'/cases/'.$hotelCaseId, [
                'given' => 'Say the word hotel-edited',
                'expected_behavior' => 'Answer with the single word hotel-edited.',
                'expectations' => [['kind' => 'text_match', 'expected_text' => 'hotel-edited']],
            ])
            ->assertStatus(200);

        $foxtrotCaseId = $this->addTextMatchCase($suiteId, 'foxtrot');

        // Compared run: bravo flips to fail (regressed), charlie flips to
        // pass (improved), delta's score drops materially but stays a
        // clean pass, echo is left without any result at all (inconclusive),
        // and the run itself ends incomplete because of it.
        $this->bravoShouldPass = false;
        $this->charlieShouldPass = true;
        $this->warmthScore = 7;

        $comparedStarted = $this->startRun($suiteId);
        $comparedRun = $this->leaveOneCaseForeverIncompleteAndMarkRunIncomplete($comparedStarted, $echoCaseId);
        $this->assertSame('incomplete', $comparedRun['status']);

        $comparison = $this->getComparison($comparedRun['id']);
        $comparison->assertStatus(200);
        $byCaseId = collect($comparison->json('cases'))->keyBy('eval_case_id');

        // The two categories confidence is meaningful for — always a
        // real (non-null) verdict, never left unset.
        $bravo = $byCaseId[$bravoCaseId];
        $this->assertSame('regressed', $bravo['category']);
        $this->assertNotNull($bravo['confidence']);

        $delta = $byCaseId[$deltaCaseId];
        $this->assertSame('materially_drifted', $delta['category']);
        $this->assertNotNull($delta['confidence']);

        // Every other category, all present in this same comparison,
        // must carry a strictly null confidence.
        $mustBeNull = [
            'unchanged' => $byCaseId[$alphaCaseId],
            'improved' => $byCaseId[$charlieCaseId],
            'inconclusive' => $byCaseId[$echoCaseId],
            'added' => $byCaseId[$foxtrotCaseId],
            'removed' => $byCaseId[$golfCaseId],
            'edited' => $byCaseId[$hotelCaseId],
        ];

        foreach ($mustBeNull as $expectedCategory => $entry) {
            $this->assertSame($expectedCategory, $entry['category'], "sanity: {$expectedCategory} case must actually land in that category");
            $this->assertNull($entry['confidence'], "confidence must be null for a {$expectedCategory} case, never populated outside regressed/materially_drifted");
        }
    }

    // =================================================================
    // Scenario 4: a case with two rubric_judgment expectations that both
    // drift materially in the same run resolves to its single most
    // severe verdict, attributed to the specific expectation that
    // produced it — never averaged, never left ambiguous between them.
    // =================================================================

    #[Test]
    public function a_case_with_two_materially_drifted_expectations_carries_the_single_most_severe_verdict_attributed_to_the_right_index(): void
    {
        $suiteId = $this->createSuite('multi-expectation-drift-agent', 'Multi expectation drift fixture suite');

        $caseId = $this->actingAs($this->operator)->postJson($this->suitesBase().'/'.$suiteId.'/cases', [
            'given' => 'The customer asks for an update on their delayed order.',
            'expected_behavior' => 'Respond with both warmth and precision.',
            'expectations' => [
                ['kind' => 'rubric_judgment', 'criteria' => 'The response must convey genuine warmth and empathy for the delay.'],
                ['kind' => 'rubric_judgment', 'criteria' => 'The response must be precise about the delay reason and the new delivery timeline.'],
            ],
        ])->assertStatus(200)->json('id');

        $judgeServer = Server::create([
            'name' => 'Multi expectation drift fixture judge server',
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
            function (string $systemContent) {
                if (str_contains($systemContent, 'warmth')) {
                    return ['score' => $this->warmthScore, 'justification' => 'ok'];
                }
                if (str_contains($systemContent, 'precise')) {
                    return ['score' => $this->precisionScore, 'justification' => 'ok'];
                }

                return ['score' => 8, 'justification' => 'ok'];
            },
        );

        // Reference run: both expectations score comfortably high.
        $this->warmthScore = 9;
        $this->precisionScore = 9;
        $referenceRun = $this->runToCompletion($suiteId);
        $this->assertSame('completed', $referenceRun['status']);
        $this->designate($referenceRun['id'])->assertStatus(201);

        // Five history runs (the default min_history_for_variance floor).
        // "warmth" dips as low as 7 along the way; "precision" never
        // drops below 8.
        $warmthHistory = [9, 7, 8, 9, 8];
        $precisionHistory = [9, 8, 10, 8, 9];
        foreach ($warmthHistory as $i => $warmth) {
            $this->warmthScore = $warmth;
            $this->precisionScore = $precisionHistory[$i];
            $this->runToCompletion($suiteId);
        }

        // Compared run: both expectations drop by exactly the material
        // threshold (2) from the reference's 9s, landing at 7 apiece —
        // still a clean pass (>= the default passing_score of 7) on
        // both, so the case itself stays materially_drifted rather than
        // becoming a plain regression. "warmth" at 7 is within its own
        // historical range (min 7) — ordinary_variation. "precision" at
        // 7 is a score this case has never produced before (min 8) —
        // likely_regression, the more severe of the two.
        $this->warmthScore = 7;
        $this->precisionScore = 7;
        $comparedRun = $this->runToCompletion($suiteId);
        $this->assertSame('completed', $comparedRun['status']);

        $comparison = $this->getComparison($comparedRun['id']);
        $comparison->assertStatus(200);
        $entry = collect($comparison->json('cases'))->firstWhere('eval_case_id', $caseId);

        $this->assertSame('materially_drifted', $entry['category']);
        $this->assertSame(
            'likely_regression',
            $entry['confidence'],
            'the more severe of the two expectations\' verdicts must win for the case as a whole'
        );
        $this->assertSame(
            1,
            $entry['drifted_expectation_index'],
            'the drifted expectation index must name the "precision" expectation (index 1), whose verdict was the severe one — never the "warmth" expectation (index 0) and never left ambiguous between them'
        );
    }
}
