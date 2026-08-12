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
 * FR-010: when the suite itself changed between the reference run and the
 * compared run, that difference is reported honestly rather than being
 * folded into pass/fail. A case added since the reference is `added`, a
 * case removed (archived) since the reference is `removed`, and a case
 * whose content was edited (a new EvalCaseVersion, same eval_case_id) is
 * `edited` — none of the three is ever scored as regressed, improved,
 * unchanged, or carries a confidence verdict, because "did it pass" is not
 * a comparable question when the suite's own definition of the case
 * changed underneath it.
 */
class SuiteDriftComparisonJourneyTest extends TestCase
{
    private User $operator;
    private string $agentLabel = 'suite-drift-agent';
    private string $suiteId;

    /** @var array<string, string> case name => eval_case id */
    private array $caseIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->declareSupportingSchema();

        $this->operator = User::factory()->create();
        config(['llm-client.cost.operator_user_ids' => [$this->operator->id]]);

        $server = Server::create([
            'name' => 'Suite drift fixture server',
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

        $this->suiteId = $this->createSuiteWithThreeCases();
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

    private function createSuiteWithThreeCases(): string
    {
        $suite = $this->actingAs($this->operator)->postJson($this->suitesBase(), [
            'name' => 'Suite drift fixture suite',
            'agent_identifier' => $this->agentLabel,
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

    private function textChatResponse(string $content): array
    {
        return [
            'choices' => [['message' => ['content' => $content]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];
    }

    /**
     * Generic "Say the word X" echo — every case, past or future version,
     * always passes as long as its given text follows this shape. Suite
     * drift is about the shape of the comparison (added/removed/edited),
     * not about engineering a pass/fail flip, so keeping every case a
     * clean pass on both sides keeps the fixture focused.
     */
    private function fakeProvider(): void
    {
        Http::fake();

        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function (array $messages) {
            $firstUser = collect($messages)->firstWhere('role', 'user')['content'] ?? '';

            if (preg_match('/Say the word (\S+)/', $firstUser, $m)) {
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

    private function runToCompletion(string $suiteId): array
    {
        Bus::fake([RunEvalCaseJob::class]);

        $started = $this->actingAs($this->operator)
            ->postJson($this->suitesBase().'/'.$suiteId.'/runs')
            ->assertStatus(201)
            ->json();

        foreach (Bus::dispatched(RunEvalCaseJob::class) as $job) {
            $job->handle(app(EvalCaseExecutor::class));
        }

        return $this->actingAs($this->operator)
            ->getJson($this->runsBase().'/'.$started['id'])
            ->assertStatus(200)
            ->json();
    }

    // ---------------------------------------------------------------
    // FR-010: added / removed / edited, never scored as pass or fail
    // ---------------------------------------------------------------

    #[Test]
    public function a_new_case_an_archived_case_and_an_edited_case_are_reported_as_added_removed_and_edited_never_as_pass_or_fail(): void
    {
        $referenceRun = $this->runToCompletion($this->suiteId);
        $this->actingAs($this->operator)->postJson($this->referenceUrl($referenceRun['id']))->assertStatus(201);

        // Capture the reference run's own eval_run_cases rows before the
        // suite changes, so the removed/edited cases' reference-side
        // version ids can be compared against the compared run's below.
        $referenceRunCases = DB::table('eval_run_cases')->where('run_id', $referenceRun['id'])->get()->keyBy('eval_case_id');

        // 1. A brand-new case, added after the reference run.
        $deltaCase = $this->actingAs($this->operator)->postJson($this->suitesBase().'/'.$this->suiteId.'/cases', [
            'given' => 'Say the word delta',
            'expected_behavior' => 'Answer with the single word delta.',
            'expectations' => [['kind' => 'text_match', 'expected_text' => 'delta']],
        ])->assertStatus(200)->json();

        // 2. An existing case, archived (never appears in a future run
        // again).
        $this->actingAs($this->operator)
            ->deleteJson($this->suitesBase().'/'.$this->suiteId.'/cases/'.$this->caseIds['bravo'])
            ->assertStatus(204);

        // 3. An existing case, edited — same eval_case_id, a new
        // EvalCaseVersion.
        $this->actingAs($this->operator)
            ->putJson($this->suitesBase().'/'.$this->suiteId.'/cases/'.$this->caseIds['charlie'], [
                'given' => 'Say the word charlie-edited',
                'expected_behavior' => 'Answer with the single word charlie-edited.',
                'expectations' => [['kind' => 'text_match', 'expected_text' => 'charlie-edited']],
            ])
            ->assertStatus(200);

        $comparedRun = $this->runToCompletion($this->suiteId);
        $comparedRunCases = DB::table('eval_run_cases')->where('run_id', $comparedRun['id'])->get()->keyBy('eval_case_id');

        $comparison = $this->actingAs($this->operator)->getJson($this->comparisonUrl($comparedRun['id']));
        $comparison->assertStatus(200);
        $byCaseId = collect($comparison->json('cases'))->keyBy('eval_case_id');

        // --- added ---
        $delta = $byCaseId[$deltaCase['id']];
        $this->assertSame('added', $delta['category']);
        $this->assertNull($delta['reference_eval_run_case_id']);
        $this->assertNotNull($delta['compared_eval_run_case_id']);
        $this->assertNull($delta['confidence']);

        // --- removed ---
        $bravo = $byCaseId[$this->caseIds['bravo']];
        $this->assertSame('removed', $bravo['category']);
        $this->assertNull($bravo['compared_eval_run_case_id']);
        $this->assertNotNull($bravo['reference_eval_run_case_id'], 'a removed case must still be listed from the reference side');
        $this->assertSame(
            $referenceRunCases[$this->caseIds['bravo']]->id,
            $bravo['reference_eval_run_case_id'],
        );
        $this->assertNull($bravo['confidence']);

        // --- edited ---
        $charlie = $byCaseId[$this->caseIds['charlie']];
        $this->assertSame('edited', $charlie['category']);
        $this->assertNotNull($charlie['reference_eval_run_case_id']);
        $this->assertNotNull($charlie['compared_eval_run_case_id']);
        $this->assertNull($charlie['confidence']);

        $referenceVersionId = $referenceRunCases[$this->caseIds['charlie']]->eval_case_version_id;
        $comparedVersionId = $comparedRunCases[$this->caseIds['charlie']]->eval_case_version_id;
        $this->assertNotSame(
            $referenceVersionId,
            $comparedVersionId,
            'an edited case must be pinned to two different eval_case_version_id values, one per side'
        );

        // --- none of the three drift categories is ever mistaken for a
        // pass/fail/no-change verdict ---
        foreach (['added' => $delta, 'removed' => $bravo, 'edited' => $charlie] as $label => $entry) {
            $this->assertNotContains(
                $entry['category'],
                ['regressed', 'improved', 'unchanged'],
                "a {$label} case must never be scored as pass or fail (FR-010)"
            );
            $this->assertNull($entry['confidence'], "a {$label} case must never carry a confidence verdict");
        }

        // --- sanity: the untouched alpha case is still classified
        // normally, so this suite change did not somehow break ordinary
        // matching ---
        $alpha = $byCaseId[$this->caseIds['alpha']];
        $this->assertSame('unchanged', $alpha['category']);

        // --- the documented response ordering: every case carrying a
        // compared-run position first, in that position order, then every
        // removed case (which has no compared-run position at all) after
        // them, in its own reference-run position order. An operator
        // reading this list sees the same order the compared run's own
        // case list already shows, with nothing silently dropped off the
        // end because it no longer exists in the suite.
        $orderedCaseIds = collect($comparison->json('cases'))->pluck('eval_case_id')->all();

        $expectedComparedOrder = $comparedRunCases
            ->sortBy('position')
            ->pluck('eval_case_id')
            ->values()
            ->all();

        $this->assertSame(
            array_merge($expectedComparedOrder, [$this->caseIds['bravo']]),
            $orderedCaseIds,
            'cases must be ordered by compared-run position, with removed cases appended after every case that has one'
        );
    }
}
