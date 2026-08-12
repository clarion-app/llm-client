<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Services\CaseVarianceAnalyzer;
use ClarionApp\LlmClient\ValueObjects\VarianceConfidence;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pure, deterministic decision rules — no I/O, no database. Given a
 * case's own historical evidence (already resolved and filtered by
 * EvalCaseHistoryQuery elsewhere), decides whether a new regression-shaped
 * difference looks like this agent's ordinary run-to-run variation or a
 * genuine first-ever occurrence, on two independent axes: a boolean
 * pass/fail transition, and a numeric rubric-score drop. Below the
 * configured floor, the answer is always insufficient_history regardless
 * of what the (too-small) sample shows — this class never lets a small
 * sample masquerade as either kind of confident verdict.
 */
class CaseVarianceAnalyzerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['llm-client.eval_regression.min_history_for_variance' => 5]);
    }

    private function analyzer(): CaseVarianceAnalyzer
    {
        return new CaseVarianceAnalyzer();
    }

    // ---------------------------------------------------------------
    // Boolean transition axis: has this case ever failed before?
    // ---------------------------------------------------------------

    #[Test]
    public function a_regression_with_at_least_one_prior_fail_in_a_full_sized_history_is_ordinary_variation(): void
    {
        $priorOutcomes = ['pass', 'pass', 'fail', 'pass', 'pass'];

        $this->assertSame(
            VarianceConfidence::OrdinaryVariation,
            $this->analyzer()->classifyBooleanTransition($priorOutcomes),
        );
    }

    #[Test]
    public function a_regression_with_zero_prior_fails_in_a_full_sized_history_is_likely_regression(): void
    {
        $priorOutcomes = ['pass', 'pass', 'pass', 'pass', 'pass'];

        $this->assertSame(
            VarianceConfidence::LikelyRegression,
            $this->analyzer()->classifyBooleanTransition($priorOutcomes),
        );
    }

    // ---------------------------------------------------------------
    // Numeric drift axis: has the new score ever been this low before?
    // ---------------------------------------------------------------

    #[Test]
    public function a_numeric_drift_at_or_above_the_lowest_previously_seen_score_is_ordinary_variation(): void
    {
        $priorScores = [8, 6, 9, 7, 8];

        $this->assertSame(
            VarianceConfidence::OrdinaryVariation,
            $this->analyzer()->classifyNumericDrift($priorScores, 6),
        );
    }

    #[Test]
    public function a_numeric_drift_to_a_score_never_seen_before_is_likely_regression(): void
    {
        $priorScores = [8, 6, 9, 7, 8];

        $this->assertSame(
            VarianceConfidence::LikelyRegression,
            $this->analyzer()->classifyNumericDrift($priorScores, 5),
        );
    }

    // ---------------------------------------------------------------
    // min_history_for_variance floor, asserted on both sides and both
    // axes independently: below the floor the verdict is
    // insufficient_history regardless of what the sample would otherwise
    // decide.
    // ---------------------------------------------------------------

    #[Test]
    public function a_boolean_history_below_the_floor_is_insufficient_history_even_though_a_fail_is_present(): void
    {
        // Would be ordinary_variation at or above the floor of 5 — only
        // 4 entries here.
        $priorOutcomes = ['pass', 'fail', 'pass', 'pass'];

        $this->assertSame(
            VarianceConfidence::InsufficientHistory,
            $this->analyzer()->classifyBooleanTransition($priorOutcomes),
        );
    }

    #[Test]
    public function a_boolean_history_below_the_floor_is_insufficient_history_with_no_fail_present_either(): void
    {
        // Would be likely_regression at or above the floor — only 4
        // entries here.
        $priorOutcomes = ['pass', 'pass', 'pass', 'pass'];

        $this->assertSame(
            VarianceConfidence::InsufficientHistory,
            $this->analyzer()->classifyBooleanTransition($priorOutcomes),
        );
    }

    #[Test]
    public function a_numeric_history_below_the_floor_is_insufficient_history_even_when_the_new_score_is_within_range(): void
    {
        $priorScores = [8, 6, 9, 7];

        $this->assertSame(
            VarianceConfidence::InsufficientHistory,
            $this->analyzer()->classifyNumericDrift($priorScores, 6),
        );
    }

    #[Test]
    public function a_numeric_history_below_the_floor_is_insufficient_history_even_at_a_brand_new_low(): void
    {
        $priorScores = [8, 6, 9, 7];

        $this->assertSame(
            VarianceConfidence::InsufficientHistory,
            $this->analyzer()->classifyNumericDrift($priorScores, 3),
        );
    }

    #[Test]
    public function exactly_at_the_floor_the_boolean_rule_decides_normally_rather_than_insufficient_history(): void
    {
        $priorOutcomes = ['pass', 'pass', 'pass', 'pass', 'fail'];

        $this->assertSame(
            VarianceConfidence::OrdinaryVariation,
            $this->analyzer()->classifyBooleanTransition($priorOutcomes),
        );
    }

    #[Test]
    public function exactly_at_the_floor_the_numeric_rule_decides_normally_rather_than_insufficient_history(): void
    {
        $priorScores = [8, 6, 9, 7, 8];

        $this->assertSame(
            VarianceConfidence::LikelyRegression,
            $this->analyzer()->classifyNumericDrift($priorScores, 5),
        );
    }

    #[Test]
    public function the_floor_is_read_from_configuration_not_hardcoded(): void
    {
        config(['llm-client.eval_regression.min_history_for_variance' => 2]);

        $this->assertSame(
            VarianceConfidence::OrdinaryVariation,
            $this->analyzer()->classifyBooleanTransition(['fail', 'pass']),
        );

        config(['llm-client.eval_regression.min_history_for_variance' => 5]);

        $this->assertSame(
            VarianceConfidence::InsufficientHistory,
            $this->analyzer()->classifyBooleanTransition(['fail', 'pass']),
        );
    }

    // ---------------------------------------------------------------
    // Never throws — a malformed/empty history simply yields
    // insufficient_history, never an exception a caller has to handle
    // mid-comparison.
    // ---------------------------------------------------------------

    #[Test]
    public function an_empty_history_never_throws_and_is_insufficient_history_on_both_axes(): void
    {
        $this->assertSame(
            VarianceConfidence::InsufficientHistory,
            $this->analyzer()->classifyBooleanTransition([]),
        );

        $this->assertSame(
            VarianceConfidence::InsufficientHistory,
            $this->analyzer()->classifyNumericDrift([], 5),
        );
    }
}
