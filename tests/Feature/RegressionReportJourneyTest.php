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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Acceptance Scenarios 1-5: designating a completed run as the reference
 * for its agent, reading that designation back through both the run-scoped
 * write route and the suite-scoped read routes, and seeing a later run of
 * the same suite classified against it — a case that flips from pass to
 * fail is regressed, one that flips from fail to pass is improved, and a
 * case that keeps passing both times with no change is unchanged, with no
 * confidence verdict attached to any of them yet. Also covers the
 * "no reference set" shape for an agent that has never had one (AC5), the
 * 422 refusal for designating from a run that has not genuinely finished,
 * re-designating the already-current run as a permitted no-op-in-effect
 * write, and the 404 shape across all three reference routes.
 */
class RegressionReportJourneyTest extends TestCase
{
    private User $operator;
    private Server $agentServer;
    private string $agentLabel = 'regression-report-agent';
    private string $suiteId;

    /** @var array<string, string> case name => eval_case id */
    private array $caseIds = [];

    private bool $bravoShouldPass = true;
    private bool $charlieShouldPass = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->declareSupportingSchema();

        $this->operator = User::factory()->create();
        config(['llm-client.cost.operator_user_ids' => [$this->operator->id]]);

        $this->agentServer = Server::create([
            'name' => 'Regression report fixture server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'provider_type' => 'openai',
        ]);

        RoleAssignment::create([
            'role' => 'inference',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $this->agentServer->id,
            'model' => 'test-model',
        ]);

        $this->fakeProvider();

        $this->suiteId = $this->createSuiteWithThreeCheckableCases($this->agentLabel);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        DB::table('eval_reference_designations')->delete();
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

    private function referenceUrl(string $runId): string
    {
        return $this->runsBase().'/'.$runId.'/reference';
    }

    private function suiteReferenceUrl(string $suiteId): string
    {
        return $this->suitesBase().'/'.$suiteId.'/reference';
    }

    private function suiteReferenceHistoryUrl(string $suiteId): string
    {
        return $this->suitesBase().'/'.$suiteId.'/reference/history';
    }

    private function comparisonUrl(string $runId): string
    {
        return $this->runsBase().'/'.$runId.'/comparison';
    }

    private function declareSupportingSchema(): void
    {
        // AgentLoopService::run() consults ConversationCondenser on every
        // call, unconditionally — the RunSuiteJourneyTest precedent.
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

    private function createSuiteWithThreeCheckableCases(string $agentIdentifier): string
    {
        $suite = $this->actingAs($this->operator)->postJson($this->suitesBase(), [
            'name' => 'Regression report fixture suite',
            'agent_identifier' => $agentIdentifier,
        ])->assertStatus(200)->json();

        foreach (['alpha' => 'alpha', 'bravo' => 'bravo', 'charlie' => 'charlie'] as $name => $word) {
            $case = $this->actingAs($this->operator)->postJson($this->suitesBase().'/'.$suite['id'].'/cases', [
                'given' => "Say the word {$word}",
                'expected_behavior' => "Answer with the single word {$word}.",
                'expectations' => [['kind' => 'text_match', 'expected_text' => $word]],
            ])->assertStatus(200)->json();
            $this->caseIds[$name] = $case['id'];
        }

        return $suite['id'];
    }

    private function createSuiteWithOneCheckableCase(string $agentIdentifier): string
    {
        $suite = $this->actingAs($this->operator)->postJson($this->suitesBase(), [
            'name' => 'Regression report unrelated-agent fixture suite',
            'agent_identifier' => $agentIdentifier,
        ])->assertStatus(200)->json();

        $this->actingAs($this->operator)->postJson($this->suitesBase().'/'.$suite['id'].'/cases', [
            'given' => 'Say the word delta',
            'expected_behavior' => 'Answer with the single word delta.',
            'expectations' => [['kind' => 'text_match', 'expected_text' => 'delta']],
        ])->assertStatus(200)->json();

        return $suite['id'];
    }

    private function textChatResponse(string $content): array
    {
        return [
            'choices' => [['message' => ['content' => $content]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];
    }

    /**
     * alpha always echoes correctly (always passes); bravo/charlie's
     * correctness is switched per test via $this->bravoShouldPass /
     * $this->charlieShouldPass, letting the same fixture suite produce a
     * pass in one run and a fail in another for the identical case.
     */
    private function fakeProvider(): void
    {
        Http::fake();

        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function (array $messages) {
            $firstUser = collect($messages)->firstWhere('role', 'user')['content'] ?? '';

            if (str_contains($firstUser, 'alpha')) {
                return $this->textChatResponse('alpha');
            }

            if (str_contains($firstUser, 'bravo')) {
                return $this->textChatResponse($this->bravoShouldPass ? 'bravo' : 'not the right word');
            }

            if (str_contains($firstUser, 'charlie')) {
                return $this->textChatResponse($this->charlieShouldPass ? 'charlie' : 'not the right word');
            }

            if (str_contains($firstUser, 'delta')) {
                return $this->textChatResponse('delta');
            }

            return $this->textChatResponse('Acknowledged.');
        });
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);
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

        return $this->actingAs($this->operator)
            ->getJson($this->runsBase().'/'.$started['run']['id'])
            ->assertStatus(200)
            ->json();
    }

    private function designate(string $runId)
    {
        return $this->actingAs($this->operator)->postJson($this->referenceUrl($runId));
    }

    private function getComparison(string $runId)
    {
        return $this->actingAs($this->operator)->getJson($this->comparisonUrl($runId));
    }

    // ---------------------------------------------------------------
    // Designation write + read-back (contracts §1.1/§2)
    // ---------------------------------------------------------------

    #[Test]
    public function designating_a_completed_run_as_reference_returns_the_reference_shape_and_is_readable_via_both_endpoints(): void
    {
        $run = $this->runToCompletion($this->suiteId);

        $designateResponse = $this->designate($run['id']);
        $designateResponse->assertStatus(201);
        $reference = $designateResponse->json();

        $this->assertSame($this->agentLabel, $reference['agent_label']);
        $this->assertSame($run['id'], $reference['run_id']);
        $this->assertSame($this->operator->id, $reference['designated_by']);
        $this->assertNotEmpty($reference['designated_at']);

        $current = $this->actingAs($this->operator)->getJson($this->suiteReferenceUrl($this->suiteId));
        $current->assertStatus(200);
        $this->assertSame($reference, $current->json());
    }

    // ---------------------------------------------------------------
    // AC5: no reference ever designated for this agent — a real 200,
    // never an error, never a misleadingly "empty" comparison.
    // ---------------------------------------------------------------

    #[Test]
    public function an_unrelated_agents_run_with_no_reference_set_returns_the_no_reference_shape_not_an_error(): void
    {
        $otherSuiteId = $this->createSuiteWithOneCheckableCase('regression-report-unrelated-agent');
        $otherRun = $this->runToCompletion($otherSuiteId);

        $response = $this->getComparison($otherRun['id']);

        $response->assertStatus(200);
        $this->assertNull($response->json('reference_run_id'));
        $this->assertFalse($response->json('reference_incomplete'));
        $this->assertFalse($response->json('compared_incomplete'));
        $this->assertSame([], $response->json('cases'));

        $currentReference = $this->actingAs($this->operator)->getJson($this->suiteReferenceUrl($otherSuiteId));
        $currentReference->assertStatus(200);
        // A literal JSON `null` body, not `{}`/`[]` and not a 404 — "no
        // reference set" is a real, expected state (AC5). assertContent()
        // is used rather than ->json() here because TestResponse::json()
        // structurally cannot represent a top-level null: it runs the
        // same "is the fully-decoded body null?" check both to detect a
        // genuinely invalid JSON body and to decide what to return, so it
        // fails with "Invalid JSON was returned from the route." for any
        // response whose entire decoded payload is null, valid or not.
        $currentReference->assertContent('null');

        $history = $this->actingAs($this->operator)->getJson($this->suiteReferenceHistoryUrl($otherSuiteId));
        $history->assertStatus(200);
        $this->assertSame([], $history->json('data'));
    }

    // ---------------------------------------------------------------
    // The other half of the same edge case: an agent that has only ever
    // had the reference run and nothing since. Looking at that run's own
    // comparison must say plainly that there is nothing to compare
    // against — never a report of the run measured against itself, which
    // would show every case "unchanged" and read as a clean bill of
    // health that no comparison actually produced.
    // ---------------------------------------------------------------

    #[Test]
    public function the_reference_run_itself_is_never_compared_against_itself_even_when_designated_the_same_second_it_finished(): void
    {
        // Frozen so the designation's second-precision created_at lands
        // exactly on the run's own completed_at — the timestamp collision
        // that would otherwise let this run resolve as its own baseline.
        // Laravel's own tearDown clears it again.
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $suiteId = $this->createSuiteWithOneCheckableCase('regression-report-only-reference-agent');
        $referenceRun = $this->runToCompletion($suiteId);

        $this->designate($referenceRun['id'])->assertStatus(201);

        $designatedAt = DB::table('eval_reference_designations')->where('run_id', $referenceRun['id'])->value('created_at');
        $completedAt = DB::table('eval_runs')->where('id', $referenceRun['id'])->value('completed_at');
        $this->assertSame(
            (string) $completedAt,
            (string) $designatedAt,
            'sanity: this test is only meaningful while the designation and the run completion share one second'
        );

        $response = $this->getComparison($referenceRun['id']);

        $response->assertStatus(200);
        $this->assertNull(
            $response->json('reference_run_id'),
            'a run must never resolve itself as its own reference'
        );
        $this->assertSame(
            [],
            $response->json('cases'),
            'no case may be reported as "unchanged" against its own identical result row'
        );

        // The designation itself is untouched and still fully readable —
        // this is about what a comparison resolves to, never about hiding
        // an audit record.
        $current = $this->actingAs($this->operator)->getJson($this->suiteReferenceUrl($suiteId));
        $current->assertStatus(200);
        $this->assertSame($referenceRun['id'], $current->json('run_id'));
    }

    // ---------------------------------------------------------------
    // The same skip must not swallow an older, genuinely-active
    // designation: promoting a run to reference in the same second it
    // finished still leaves it compared against whatever was the
    // reference before it.
    // ---------------------------------------------------------------

    #[Test]
    public function a_run_promoted_to_reference_the_same_second_it_finished_still_compares_against_the_previous_reference(): void
    {
        // Frozen, so every designation and both runs' completion share one
        // second — the hardest form of this case, where the self-naming
        // designation is also the newest row by the (created_at DESC, id
        // DESC) tie-break and would win outright if it were not skipped.
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $suiteId = $this->createSuiteWithOneCheckableCase('regression-report-promoted-run-agent');

        $firstRun = $this->runToCompletion($suiteId);
        $this->designate($firstRun['id'])->assertStatus(201);

        $secondRun = $this->runToCompletion($suiteId);
        $this->designate($secondRun['id'])->assertStatus(201);

        $response = $this->getComparison($secondRun['id']);

        $response->assertStatus(200);
        $this->assertSame(
            $firstRun['id'],
            $response->json('reference_run_id'),
            'skipping a self-designation must fall back to the previous reference, not to "no reference at all"'
        );
        $this->assertNotEmpty($response->json('cases'));
    }

    // ---------------------------------------------------------------
    // AC1/AC2/AC4: regressed / improved / unchanged. Confidence is
    // strictly null for improved/unchanged; a regressed case always
    // carries a real (non-null) VarianceConfidence verdict once the
    // variance-analysis phase is wired in — here it is
    // insufficient_history, since this fixture builds no prior run
    // history for "bravo" beyond the reference/compared pair themselves.
    // ---------------------------------------------------------------

    #[Test]
    public function a_second_run_after_a_change_correctly_classifies_regressed_improved_and_unchanged_cases(): void
    {
        $this->bravoShouldPass = true;
        $this->charlieShouldPass = false;

        $referenceRun = $this->runToCompletion($this->suiteId);
        $this->designate($referenceRun['id'])->assertStatus(201);

        // bravo flips pass -> fail (regressed); charlie flips fail -> pass
        // (improved); alpha keeps passing both times (unchanged).
        $this->bravoShouldPass = false;
        $this->charlieShouldPass = true;

        $comparedRun = $this->runToCompletion($this->suiteId);

        $comparison = $this->getComparison($comparedRun['id']);
        $comparison->assertStatus(200);
        $this->assertSame($referenceRun['id'], $comparison->json('reference_run_id'));
        $this->assertFalse($comparison->json('reference_incomplete'));
        $this->assertFalse($comparison->json('compared_incomplete'));

        $byCaseId = collect($comparison->json('cases'))->keyBy('eval_case_id');

        $alpha = $byCaseId[$this->caseIds['alpha']];
        $this->assertSame('unchanged', $alpha['category']);
        $this->assertNull($alpha['confidence']);
        $this->assertSame('pass', $alpha['reference_outcome']);
        $this->assertSame('pass', $alpha['compared_outcome']);

        $bravo = $byCaseId[$this->caseIds['bravo']];
        $this->assertSame('regressed', $bravo['category']);
        $this->assertSame(
            'insufficient_history',
            $bravo['confidence'],
            'a regressed case always carries a real confidence verdict; with no prior history built up for this case it is insufficient_history, never null'
        );
        $this->assertSame('pass', $bravo['reference_outcome']);
        $this->assertSame('fail', $bravo['compared_outcome']);

        $charlie = $byCaseId[$this->caseIds['charlie']];
        $this->assertSame('improved', $charlie['category']);
        $this->assertNull($charlie['confidence']);
        $this->assertSame('fail', $charlie['reference_outcome']);
        $this->assertSame('pass', $charlie['compared_outcome']);
    }

    // ---------------------------------------------------------------
    // 422: only a genuinely finished run can become a reference
    // ---------------------------------------------------------------

    #[Test]
    public function designating_a_reference_from_an_in_progress_run_is_refused_with_422(): void
    {
        $started = $this->startRun($this->suiteId);

        $response = $this->designate($started['run']['id']);

        $response->assertStatus(422);
        $this->assertSame(
            0,
            DB::table('eval_reference_designations')->where('run_id', $started['run']['id'])->count(),
            'no row may be written for a refused designation'
        );
    }

    #[Test]
    public function designating_a_reference_from_a_failed_to_start_run_is_refused_with_422(): void
    {
        RoleAssignment::where('role', 'inference')->delete();

        $failedRun = $this->actingAs($this->operator)
            ->postJson($this->suitesBase().'/'.$this->suiteId.'/runs')
            ->assertStatus(201)
            ->json();
        $this->assertSame('failed_to_start', $failedRun['status']);

        $response = $this->designate($failedRun['id']);

        $response->assertStatus(422);
        $this->assertSame(
            0,
            DB::table('eval_reference_designations')->where('run_id', $failedRun['id'])->count(),
        );
    }

    // ---------------------------------------------------------------
    // Re-designating the already-current run: permitted, still recorded
    // (data-model.md §5)
    // ---------------------------------------------------------------

    #[Test]
    public function redesignating_the_already_current_run_succeeds_and_records_a_new_history_row(): void
    {
        $run = $this->runToCompletion($this->suiteId);

        $this->designate($run['id'])->assertStatus(201);
        $this->designate($run['id'])->assertStatus(201);

        $this->assertSame(
            2,
            DB::table('eval_reference_designations')->where('run_id', $run['id'])->count(),
            'an explicit re-confirmation is not an error and must still be recorded as its own row'
        );

        $history = $this->actingAs($this->operator)->getJson($this->suiteReferenceHistoryUrl($this->suiteId));
        $history->assertStatus(200);
        $this->assertCount(2, $history->json('data'));
    }

    // ---------------------------------------------------------------
    // 404: an unresolvable id across all three reference routes
    // ---------------------------------------------------------------

    #[Test]
    public function unknown_run_or_suite_ids_return_404_across_all_three_reference_routes(): void
    {
        $unknownId = (string) Str::uuid();

        $this->designate($unknownId)->assertStatus(404);
        $this->actingAs($this->operator)->getJson($this->suiteReferenceUrl($unknownId))->assertStatus(404);
        $this->actingAs($this->operator)->getJson($this->suiteReferenceHistoryUrl($unknownId))->assertStatus(404);
    }
}
