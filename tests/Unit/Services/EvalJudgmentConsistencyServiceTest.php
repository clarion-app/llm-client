<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Models\EvalCase;
use ClarionApp\LlmClient\Models\EvalCaseVersion;
use ClarionApp\LlmClient\Models\EvalJudgment;
use ClarionApp\LlmClient\Models\EvalJudgmentConsistencySample;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\EvalCaseService;
use ClarionApp\LlmClient\Services\EvalJudgmentConsistencyService;
use ClarionApp\LlmClient\Services\EvalSuiteService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * EvalJudgmentConsistencyService::run() judges a fixed response repeatedly
 * against a fixed rubric expectation and summarizes the resulting scores —
 * the full spread, not only a mean — plus an automated stability flag.
 *
 * Every repeat goes through the real RubricJudge/RoleResolver/BudgetGate
 * stack, exactly like RubricJudgeTest — only the provider itself is a
 * fixture (no real HTTP), registered into the real ProviderRegistry so the
 * whole chain from "sample requested" to "eval_judgments rows written" is
 * exercised for real.
 */
class EvalJudgmentConsistencyServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $server = $this->makeServer();
        $this->assignJudgeRole($server);
    }

    protected function tearDown(): void
    {
        DB::table('eval_judgment_consistency_samples')->delete();
        DB::table('eval_judgments')->delete();
        DB::table('conversations')->delete();
        DB::table('eval_case_versions')->delete();
        DB::table('eval_cases')->delete();
        DB::table('eval_suites')->delete();
        DB::table('llm_role_assignments')->delete();
        DB::table('llm_servers')->delete();
        DB::table('cost_summaries')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Fixture helpers
    // ---------------------------------------------------------------

    private function makeServer(string $name = 'Consistency judge server'): Server
    {
        return Server::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'server_url' => 'https://api.example.com',
            'provider_type' => ProviderType::OpenAI,
        ]);
    }

    private function assignJudgeRole(Server $server, string $model = 'gpt-4o-mini'): RoleAssignment
    {
        return RoleAssignment::create([
            'role' => 'judge',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $server->id,
            'model' => $model,
        ]);
    }

    /**
     * @return array{0: EvalCase, 1: EvalCaseVersion}
     */
    private function makeCaseWithRubricExpectation(
        string $criteria = "The response must acknowledge the customer's frustration before offering a solution.",
    ): array {
        $suite = app(EvalSuiteService::class)->create('Consistency check fixture suite', 'customer-support-agent');

        $case = app(EvalCaseService::class)->addCase(
            $suite,
            'The customer says the delivery was three days late and is very upset.',
            "Acknowledge the customer's frustration before offering a solution.",
            [['kind' => 'rubric_judgment', 'criteria' => $criteria]],
        );

        $version = EvalCaseVersion::find($case->current_version_id);

        return [$case, $version];
    }

    private function runConsistencyCheck(
        EvalCase $case,
        EvalCaseVersion $version,
        ?int $sampleSize,
        string $requestedBy,
        int $expectationIndex = 0,
        string $responseText = 'I understand this has been frustrating, and I will make it right.',
    ): EvalJudgmentConsistencySample {
        return app(EvalJudgmentConsistencyService::class)->run(
            $case,
            $version,
            $expectationIndex,
            $responseText,
            null,
            $sampleSize,
            $requestedBy,
        );
    }

    /** @return array<string, mixed> the chat()-shaped return value */
    private function chatResponse(string $content): array
    {
        return [
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
            'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 20, 'total_tokens' => 60],
            'model' => 'gpt-4o-mini',
        ];
    }

    private array $chatQueue = [];

    private int $chatCallCount = 0;

    /**
     * Registers a fixture provider that answers each successive chat() call
     * with the next entry of $scriptedScores, in order — a null entry
     * produces a deliberately malformed response (RubricJudge's own
     * "malformed judge response" branch, RubricJudgeTest's own precedent),
     * exercised here to produce a deterministic unjudged repeat with no
     * reliance on a thrown exception. Every call is counted, so a test can
     * assert the exact number of repeats actually attempted.
     */
    private function registerScriptedProvider(array $scriptedScores): void
    {
        $this->chatQueue = $scriptedScores;
        $this->chatCallCount = 0;

        $provider = $this->createMock(LlmProvider::class);
        $provider->method('chat')->willReturnCallback(function () {
            $this->chatCallCount++;
            $next = array_shift($this->chatQueue);

            if ($next === null) {
                return $this->chatResponse('I cannot comply with that request.');
            }

            return $this->chatResponse(json_encode([
                'score' => $next,
                'justification' => 'Scripted judge justification.',
            ]));
        });

        app(ProviderRegistry::class)->register(ProviderType::OpenAI, fn () => $provider);
    }

    // ---------------------------------------------------------------
    // sample_size default / clamp
    // ---------------------------------------------------------------

    #[Test]
    public function sample_size_defaults_to_the_configured_default_when_unspecified(): void
    {
        [$case, $version] = $this->makeCaseWithRubricExpectation();
        $expectedDefault = (int) config('llm-client.eval_judging.consistency_sample_size', 5);

        $this->registerScriptedProvider(array_fill(0, $expectedDefault, 7));

        $sample = $this->runConsistencyCheck($case, $version, null, (string) Str::uuid());

        $this->assertSame($expectedDefault, $sample->sample_size);
        $this->assertSame($expectedDefault, $this->chatCallCount);
        $this->assertSame($expectedDefault, EvalJudgment::where('consistency_sample_id', $sample->id)->count());
    }

    #[Test]
    public function sample_size_is_clamped_to_the_configured_maximum_when_supplied_above_it(): void
    {
        [$case, $version] = $this->makeCaseWithRubricExpectation();
        $max = (int) config('llm-client.eval_judging.max_consistency_sample_size', 10);

        // Deliberately more scripted responses than the clamp allows, so an
        // unclamped implementation (running the full requested count) is
        // caught by the exact call-count assertion below, not merely by
        // running out of queued responses.
        $this->registerScriptedProvider(array_fill(0, $max + 5, 6));

        $sample = $this->runConsistencyCheck($case, $version, $max + 5, (string) Str::uuid());

        $this->assertSame($max, $sample->sample_size);
        $this->assertSame($max, $this->chatCallCount);
    }

    #[Test]
    public function sample_size_is_clamped_to_a_minimum_of_one_when_supplied_at_or_below_zero(): void
    {
        [$case, $version] = $this->makeCaseWithRubricExpectation();
        $this->registerScriptedProvider([7, 7, 7]);

        $sample = $this->runConsistencyCheck($case, $version, 0, (string) Str::uuid());

        $this->assertSame(1, $sample->sample_size);
        $this->assertSame(1, $this->chatCallCount);
    }

    // ---------------------------------------------------------------
    // Sequential, not parallel/queued execution
    // ---------------------------------------------------------------

    #[Test]
    public function judge_calls_run_sequentially_in_order_never_dispatched_to_a_queue(): void
    {
        [$case, $version] = $this->makeCaseWithRubricExpectation();
        Bus::fake();

        $this->registerScriptedProvider([7, 5, 9]);

        $sample = $this->runConsistencyCheck($case, $version, 3, (string) Str::uuid());

        Bus::assertNothingDispatched();
        $this->assertSame(3, $this->chatCallCount);
        $this->assertSame([7, 5, 9], $sample->scores, 'repeats must be recorded in the exact order they were produced');
    }

    // ---------------------------------------------------------------
    // One eval_judgments row per repeat, plus one summary row
    // ---------------------------------------------------------------

    #[Test]
    public function writes_one_eval_judgment_row_per_repeat_with_consistency_sample_id_set_and_eval_case_result_id_null_plus_one_summary_row(): void
    {
        [$case, $version] = $this->makeCaseWithRubricExpectation();
        $this->registerScriptedProvider([7, 8, 6]);

        $sample = $this->runConsistencyCheck($case, $version, 3, (string) Str::uuid());

        $this->assertSame(1, DB::table('eval_judgment_consistency_samples')->count());

        $judgmentRows = EvalJudgment::where('consistency_sample_id', $sample->id)->get();
        $this->assertCount(3, $judgmentRows);

        foreach ($judgmentRows as $row) {
            $this->assertSame($sample->id, $row->consistency_sample_id);
            $this->assertNull($row->eval_case_result_id);
            $this->assertSame('judged', $row->status);
        }
    }

    // ---------------------------------------------------------------
    // scores / judged_count / unjudged_count
    // ---------------------------------------------------------------

    #[Test]
    public function scores_is_empty_and_summary_stats_are_null_when_every_repeat_comes_back_unjudged(): void
    {
        [$case, $version] = $this->makeCaseWithRubricExpectation();
        $this->registerScriptedProvider([null, null, null, null]);

        $sample = $this->runConsistencyCheck($case, $version, 4, (string) Str::uuid());

        $this->assertSame([], $sample->scores);
        $this->assertSame(0, $sample->judged_count);
        $this->assertSame(4, $sample->unjudged_count);
        $this->assertNull($sample->score_min);
        $this->assertNull($sample->score_max);
        $this->assertNull($sample->score_mean);
        $this->assertNull($sample->flagged_unstable, 'insufficient data to assess stability must never be a fabricated false');
    }

    #[Test]
    public function judged_and_unjudged_counts_always_sum_to_sample_size_and_scores_preserves_only_judged_repeats_in_order(): void
    {
        [$case, $version] = $this->makeCaseWithRubricExpectation();
        $this->registerScriptedProvider([7, null, 9, null, 5]);

        $sample = $this->runConsistencyCheck($case, $version, 5, (string) Str::uuid());

        $this->assertSame(3, $sample->judged_count);
        $this->assertSame(2, $sample->unjudged_count);
        $this->assertSame(5, $sample->judged_count + $sample->unjudged_count);
        $this->assertSame([7, 9, 5], $sample->scores);
        $this->assertSame(5, $sample->score_min);
        $this->assertSame(9, $sample->score_max);
        $this->assertEqualsWithDelta(7.0, (float) $sample->score_mean, 0.01);
    }

    // ---------------------------------------------------------------
    // flagged_unstable: strict >, not >=
    // ---------------------------------------------------------------

    #[Test]
    public function flagged_unstable_is_false_when_the_spread_exactly_equals_the_threshold(): void
    {
        [$case, $version] = $this->makeCaseWithRubricExpectation();
        config(['llm-client.eval_judging.consistency_flag_threshold' => 3]);
        $this->registerScriptedProvider([5, 8]); // spread = 3, exactly the threshold

        $sample = $this->runConsistencyCheck($case, $version, 2, (string) Str::uuid());

        $this->assertSame(3, $sample->flag_threshold_used);
        $this->assertFalse(
            $sample->flagged_unstable,
            'a spread exactly equal to the threshold must not be flagged — strict >, not >=',
        );
    }

    #[Test]
    public function flagged_unstable_is_true_when_the_spread_exceeds_the_threshold(): void
    {
        [$case, $version] = $this->makeCaseWithRubricExpectation();
        config(['llm-client.eval_judging.consistency_flag_threshold' => 3]);
        $this->registerScriptedProvider([5, 9]); // spread = 4, beyond the threshold

        $sample = $this->runConsistencyCheck($case, $version, 2, (string) Str::uuid());

        $this->assertSame(3, $sample->flag_threshold_used);
        $this->assertTrue($sample->flagged_unstable);
    }

    // ---------------------------------------------------------------
    // flag_threshold_used: a fresh config read every call, never cached
    // ---------------------------------------------------------------

    #[Test]
    public function flag_threshold_used_is_read_fresh_from_config_at_request_time_not_cached_across_calls(): void
    {
        [$case, $version] = $this->makeCaseWithRubricExpectation();

        config(['llm-client.eval_judging.consistency_flag_threshold' => 3]);
        $this->registerScriptedProvider([5, 9]);
        $first = $this->runConsistencyCheck($case, $version, 2, (string) Str::uuid());

        config(['llm-client.eval_judging.consistency_flag_threshold' => 10]);
        $this->registerScriptedProvider([5, 9]);
        $second = $this->runConsistencyCheck($case, $version, 2, (string) Str::uuid());

        $this->assertSame(3, $first->flag_threshold_used);
        $this->assertSame(10, $second->flag_threshold_used);

        // Re-reading the first sample after the config change must not show
        // the second call's threshold retroactively applied to it — each
        // row keeps the value that was actually in effect when it was
        // computed.
        $refetchedFirst = EvalJudgmentConsistencySample::find($first->id);
        $this->assertSame(3, $refetchedFirst->flag_threshold_used);
    }
}
