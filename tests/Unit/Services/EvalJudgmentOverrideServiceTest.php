<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Models\EvalCaseResult;
use ClarionApp\LlmClient\Models\EvalJudgment;
use ClarionApp\LlmClient\Models\EvalJudgmentOverride;
use ClarionApp\LlmClient\Services\EvalJudgmentOverrideService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An override is a new, append-only fact about a judgment, never a
 * mutation of it — the judgment's own score/justification must stay
 * exactly as originally produced no matter how many times it is
 * corrected. The "current effective" value for a judgment is the latest
 * override if one exists, else the original judgment itself, and each
 * override row is always a complete, self-contained (score, justification)
 * pair even when the operator only supplied one of the two fields. The
 * one side effect an override has beyond its own table is recomputing and
 * writing eval_case_results.outcome_override via the same
 * EvalCaseOutcome::aggregate() rule used everywhere else in this feature
 * — never touching that row's original outcome/expectation_results
 * columns.
 */
class EvalJudgmentOverrideServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::table('eval_judgment_overrides')->delete();
        DB::table('eval_judgments')->delete();
        DB::table('eval_case_results')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Fixture helpers
    // ---------------------------------------------------------------

    private function service(): EvalJudgmentOverrideService
    {
        return app(EvalJudgmentOverrideService::class);
    }

    /**
     * A judged judgment, not yet attached to any case result unless the
     * caller supplies one — most tests below only care about the
     * judgment/override relationship in isolation.
     */
    private function makeJudgment(
        int $score = 8,
        string $justification = 'Original automated justification.',
        ?string $evalCaseResultId = null,
        int $expectationIndex = 0,
    ): EvalJudgment {
        return EvalJudgment::create([
            'id' => (string) Str::uuid(),
            'eval_case_result_id' => $evalCaseResultId,
            'eval_case_version_id' => (string) Str::uuid(),
            'expectation_index' => $expectationIndex,
            'criteria' => 'The response must acknowledge the frustration before offering a solution.',
            'response_text' => 'I understand this has been frustrating — here is what I can do.',
            'status' => 'judged',
            'score' => $score,
            'justification' => $justification,
            'unjudged_reason' => null,
            'model' => 'gpt-4o-mini',
            'server_id' => (string) Str::uuid(),
            'conversation_id' => (string) Str::uuid(),
            'consistency_sample_id' => null,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $expectationResults
     */
    private function makeCaseResult(array $expectationResults, string $outcome = 'pass'): EvalCaseResult
    {
        return EvalCaseResult::create([
            'run_id' => (string) Str::uuid(),
            'eval_run_case_id' => (string) Str::uuid(),
            'eval_case_id' => (string) Str::uuid(),
            'eval_case_version_id' => (string) Str::uuid(),
            'conversation_id' => (string) Str::uuid(),
            'outcome' => $outcome,
            'produced_response' => 'I understand this has been frustrating — here is what I can do.',
            'attempted_actions' => [],
            'expectation_results' => $expectationResults,
        ]);
    }

    private function passingScoreThreshold(): int
    {
        return (int) config('llm-client.eval_judging.passing_score', 7);
    }

    // ---------------------------------------------------------------
    // Append-only: a new row, never a mutation of the judgment
    // ---------------------------------------------------------------

    #[Test]
    public function override_writes_a_new_row_and_the_judgment_keeps_its_original_score_and_justification(): void
    {
        $judgment = $this->makeJudgment(score: 8, justification: 'Original automated justification.');
        $userId = (string) Str::uuid();

        $override = $this->service()->override($judgment, 5, 'Too generous — no concrete next step.', $userId);

        $this->assertInstanceOf(EvalJudgmentOverride::class, $override);
        $this->assertSame(1, DB::table('eval_judgment_overrides')->count());
        $this->assertSame($judgment->id, $override->judgment_id);
        $this->assertSame($userId, $override->user_id);
        $this->assertSame(5, $override->score);
        $this->assertSame('Too generous — no concrete next step.', $override->justification);

        $refreshed = $judgment->fresh();
        $this->assertSame(8, $refreshed->score, 'the original judgment score must never be mutated by an override');
        $this->assertSame(
            'Original automated justification.',
            $refreshed->justification,
            'the original judgment justification must never be mutated by an override',
        );
    }

    // ---------------------------------------------------------------
    // Always-populated rule: an omitted field defaults from the
    // judgment's current effective value, never left null
    // ---------------------------------------------------------------

    #[Test]
    public function an_override_supplying_only_score_defaults_justification_from_the_original_judgment(): void
    {
        $judgment = $this->makeJudgment(score: 8, justification: 'Original automated justification.');

        $override = $this->service()->override($judgment, 5, null, (string) Str::uuid());

        $this->assertSame(5, $override->score);
        $this->assertSame(
            'Original automated justification.',
            $override->justification,
            'a supplied score with no justification must default justification from the current effective value, never null',
        );
    }

    #[Test]
    public function an_override_supplying_only_justification_defaults_score_from_the_original_judgment(): void
    {
        $judgment = $this->makeJudgment(score: 8, justification: 'Original automated justification.');

        $override = $this->service()->override($judgment, null, 'Justification corrected only.', (string) Str::uuid());

        $this->assertSame(
            8,
            $override->score,
            'a supplied justification with no score must default score from the current effective value, never null',
        );
        $this->assertSame('Justification corrected only.', $override->justification);
    }

    // ---------------------------------------------------------------
    // "Current effective" chaining: a second override defaults from the
    // first override, not from the original judgment
    // ---------------------------------------------------------------

    #[Test]
    public function a_second_override_defaults_its_omitted_score_from_the_first_override_not_the_original_judgment(): void
    {
        $judgment = $this->makeJudgment(score: 8, justification: 'Original automated justification.');

        $this->service()->override($judgment, 5, 'First correction.', (string) Str::uuid());

        $second = $this->service()->override($judgment, null, 'Second correction.', (string) Str::uuid());

        $this->assertSame(
            5,
            $second->score,
            'the second override must default its omitted score from the first override (5), not the original judgment (8)',
        );
        $this->assertSame('Second correction.', $second->justification);
        $this->assertSame(2, DB::table('eval_judgment_overrides')->count());
    }

    #[Test]
    public function a_second_override_defaults_its_omitted_justification_from_the_first_override_not_the_original_judgment(): void
    {
        $judgment = $this->makeJudgment(score: 8, justification: 'Original automated justification.');

        $this->service()->override($judgment, 5, 'First correction.', (string) Str::uuid());

        $second = $this->service()->override($judgment, 3, null, (string) Str::uuid());

        $this->assertSame(3, $second->score);
        $this->assertSame(
            'First correction.',
            $second->justification,
            'the second override must default its omitted justification from the first override, not the original judgment',
        );
    }

    #[Test]
    public function a_third_override_in_the_same_second_still_defaults_from_the_second_not_the_first(): void
    {
        // eval_judgment_overrides.created_at is second-precision, so all
        // three corrections below share a timestamp. "Latest override
        // wins" must still mean the one actually made last — ordering by
        // created_at alone leaves them tied and hands back the earliest.
        $judgment = $this->makeJudgment(score: 8, justification: 'Original automated justification.');

        $this->service()->override($judgment, 5, 'First correction.', (string) Str::uuid());
        $this->service()->override($judgment, 4, 'Second correction.', (string) Str::uuid());

        $third = $this->service()->override($judgment, null, 'Third correction.', (string) Str::uuid());

        $this->assertSame(
            4,
            $third->score,
            'the third override must default its omitted score from the second override (4), not the first (5)',
        );

        $judgment->load('overrides');
        $effective = $judgment->effective();

        $this->assertSame(4, $effective['score']);
        $this->assertSame('Third correction.', $effective['justification']);
        $this->assertSame(
            ['First correction.', 'Second correction.', 'Third correction.'],
            $judgment->overrides->pluck('justification')->all(),
            'the override history must read oldest-first even when every row shares a created_at second',
        );
    }

    // ---------------------------------------------------------------
    // outcome_override recomputation via EvalCaseOutcome::aggregate()
    // ---------------------------------------------------------------

    #[Test]
    public function override_dropping_the_score_below_the_passing_threshold_flips_outcome_override_from_pass_to_fail(): void
    {
        // The judgment's own expectation_index (0) is a placeholder until
        // the case result exists; the case result is created first so its
        // real, database-generated id can be used both to link the
        // judgment and to build a matching expectation_results entry —
        // EvalCaseResult's id is never caller-supplied (booted() mints it).
        $judgment = $this->makeJudgment(score: 8);

        $caseResult = $this->makeCaseResult(
            expectationResults: [[
                'kind' => 'rubric_judgment',
                'criteria' => $judgment->criteria,
                'met' => true,
                'score' => 8,
                'status' => 'judged',
                'judgment_id' => $judgment->id,
            ]],
            outcome: 'pass',
        );
        $judgment->update(['eval_case_result_id' => $caseResult->id]);

        $this->assertSame('pass', $caseResult->fresh()->outcome->value);
        $this->assertNull($caseResult->fresh()->outcome_override ?? null);

        $threshold = $this->passingScoreThreshold();
        $this->service()->override($judgment, $threshold - 1, 'No longer meets the bar.', (string) Str::uuid());

        $refreshedResult = $caseResult->fresh();
        $this->assertSame(
            'pass',
            $refreshedResult->outcome->value,
            'the original outcome column must never be touched by an override',
        );
        $this->assertSame(
            'fail',
            $refreshedResult->outcome_override,
            'outcome_override must be recomputed from the overridden met value via EvalCaseOutcome::aggregate()',
        );
    }

    #[Test]
    public function override_recomputation_leaves_an_unrelated_expectations_met_value_untouched(): void
    {
        $judgment = $this->makeJudgment(score: 8);

        $caseResult = $this->makeCaseResult(
            expectationResults: [
                [
                    'kind' => 'rubric_judgment',
                    'criteria' => $judgment->criteria,
                    'met' => true,
                    'score' => 8,
                    'status' => 'judged',
                    'judgment_id' => $judgment->id,
                ],
                [
                    'kind' => 'text_match',
                    'expected_text' => 'a concrete next step',
                    'met' => false,
                ],
            ],
            outcome: 'fail',
        );
        $judgment->update(['eval_case_result_id' => $caseResult->id]);

        // Raise the rubric score well above the passing threshold — if the
        // service correctly recomputes only the one expectation it touched,
        // the unrelated text_match expectation's own met: false must still
        // drive the aggregate outcome to Fail. A service that instead
        // resets every expectation's met to true (or ignores the unrelated
        // entry entirely) would wrongly land on Pass here.
        $this->service()->override($judgment, 10, 'Even better than originally scored.', (string) Str::uuid());

        $refreshedResult = $caseResult->fresh();
        $this->assertSame(
            'fail',
            $refreshedResult->outcome_override,
            "an unrelated expectation's own unmet result must still be honored by the recomputed aggregate outcome",
        );
    }
}
