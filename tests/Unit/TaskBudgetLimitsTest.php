<?php

namespace Tests\Unit;

use ClarionApp\LlmClient\Services\QuestionWaitingPeriod;
use ClarionApp\LlmClient\Services\TaskBudgetLimits;
use ClarionApp\LlmClient\ValueObjects\CeilingOrigin;
use ClarionApp\LlmClient\ValueObjects\StatedCeilings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every task ceiling is finite by construction.
 *
 * TaskBudgetLimits is the one place the installation's task-budget
 * configuration is turned into numbers. Whatever an operator writes — a
 * zero, a negative, a word, nothing at all, or something absurdly large —
 * the result is a positive, finite ceiling no larger than the hard cap
 * written in code. A configuration that could produce "unlimited" would
 * reopen the very hole the task budget exists to close, so a maximum of 0
 * is never read as "no maximum": it is an invalid value and the shipped
 * maximum applies.
 *
 * Spend is carried as a decimal string at scale 10 (the scale of the
 * usage_records cost columns) and never passes through a float.
 */
class TaskBudgetLimitsTest extends TestCase
{
    private function limits(): TaskBudgetLimits
    {
        return app(TaskBudgetLimits::class);
    }

    #[Test]
    public function the_shipped_defaults_resolve_to_the_documented_values(): void
    {
        $limits = $this->limits();

        $this->assertSame(20, (int) config('llm-client.agent_loop.max_iterations'), 'precondition: the shipped iteration bound');
        $this->assertSame(20, $limits->defaultSteps(), 'the default step ceiling is agent_loop.max_iterations');
        $this->assertSame('1.0000000000', $limits->defaultSpend());
        $this->assertSame(200, $limits->maximumSteps());
        $this->assertSame('25.0000000000', $limits->maximumSpend());
        $this->assertSame('0.8000', $limits->approachThreshold(), 'falls back to budget.default_approach_threshold');
        $this->assertSame(90, $limits->retentionDays(), 'falls back to run_trace.retention_days');
    }

    #[Test]
    public function the_hard_caps_are_public_constants(): void
    {
        $this->assertSame(1000000, TaskBudgetLimits::HARD_MAX_STEPS);
        $this->assertSame(0, bccomp(TaskBudgetLimits::HARD_MAX_SPEND, '1000000', 10));
    }

    #[Test]
    public function the_prompt_wait_follows_the_question_waiting_period_rules(): void
    {
        config(['llm-client.agent_questions.expiry_minutes' => 1440]);
        $this->assertSame(
            app(QuestionWaitingPeriod::class)->effectiveMinutes(null),
            $this->limits()->promptWaitMinutes(),
            'with no prompt_wait_minutes the installation default question period applies'
        );
        $this->assertSame(1440, $this->limits()->promptWaitMinutes());

        config(['llm-client.task_budget.prompt_wait_minutes' => 120]);
        $this->assertSame(120, $this->limits()->promptWaitMinutes());

        config(['llm-client.task_budget.prompt_wait_minutes' => 5, 'llm-client.agent_questions.min_expiry_minutes' => 60]);
        $this->assertSame(60, $this->limits()->promptWaitMinutes(), 'never below the installation minimum');

        config(['llm-client.task_budget.prompt_wait_minutes' => 999999, 'llm-client.agent_questions.max_expiry_minutes' => 10080]);
        $this->assertSame(10080, $this->limits()->promptWaitMinutes(), 'never above the installation maximum');
    }

    public static function invalidValues(): array
    {
        return [
            'zero' => [0],
            'zero string' => ['0'],
            'negative' => [-5],
            'negative string' => ['-0.50'],
            'non-numeric' => ['lots'],
            'null' => [null],
            'empty string' => [''],
        ];
    }

    #[Test]
    #[DataProvider('invalidValues')]
    public function an_invalid_maximum_steps_resolves_to_the_shipped_maximum_never_to_unlimited(mixed $value): void
    {
        config(['llm-client.task_budget.maximum_steps' => $value]);

        $this->assertSame(200, $this->limits()->maximumSteps());
        $this->assertSame(20, $this->limits()->defaultSteps(), 'the default stays finite and within the maximum');
    }

    #[Test]
    #[DataProvider('invalidValues')]
    public function an_invalid_maximum_spend_resolves_to_the_shipped_maximum(mixed $value): void
    {
        config(['llm-client.task_budget.maximum_spend' => $value]);

        $this->assertSame('25.0000000000', $this->limits()->maximumSpend());
    }

    #[Test]
    #[DataProvider('invalidValues')]
    public function an_invalid_default_spend_resolves_to_the_shipped_default(mixed $value): void
    {
        config(['llm-client.task_budget.default_max_spend' => $value]);

        $this->assertSame('1.0000000000', $this->limits()->defaultSpend());
    }

    #[Test]
    #[DataProvider('invalidValues')]
    public function an_invalid_iteration_bound_still_yields_a_finite_default_step_ceiling(mixed $value): void
    {
        config(['llm-client.agent_loop.max_iterations' => $value]);

        $this->assertSame(20, $this->limits()->defaultSteps());
    }

    #[Test]
    public function maximum_steps_zero_is_not_unlimited(): void
    {
        config(['llm-client.task_budget.maximum_steps' => 0]);

        $resolved = $this->limits()->clamp(new StatedCeilings(maxSteps: 5000));

        $this->assertSame(200, $resolved['steps'], 'a stated 5000 is reduced to the shipped maximum, not allowed through');
        $this->assertSame(CeilingOrigin::UserReducedToMaximum, $resolved['steps_origin']);
        $this->assertTrue($resolved['steps_reduced']);
    }

    #[Test]
    public function absurd_maxima_are_capped_by_the_hard_caps(): void
    {
        config([
            'llm-client.task_budget.maximum_steps' => 1000000000000,
            'llm-client.task_budget.maximum_spend' => '1000000000000',
        ]);

        $this->assertSame(1000000, $this->limits()->maximumSteps());
        $this->assertSame('1000000.0000000000', $this->limits()->maximumSpend());
    }

    #[Test]
    public function a_default_above_its_maximum_is_reduced_to_the_maximum(): void
    {
        config([
            'llm-client.agent_loop.max_iterations' => 500,
            'llm-client.task_budget.maximum_steps' => 200,
            'llm-client.task_budget.default_max_spend' => '40.00',
            'llm-client.task_budget.maximum_spend' => '25.00',
        ]);

        $this->assertSame(200, $this->limits()->defaultSteps());
        $this->assertSame('25.0000000000', $this->limits()->defaultSpend());
    }

    #[Test]
    public function a_valid_configuration_is_used_as_given(): void
    {
        config([
            'llm-client.agent_loop.max_iterations' => 7,
            'llm-client.task_budget.maximum_steps' => 50,
            'llm-client.task_budget.default_max_spend' => '0.25',
            'llm-client.task_budget.maximum_spend' => '3.5',
            'llm-client.task_budget.approach_threshold' => 0.9,
            'llm-client.task_budget.retention_days' => 30,
        ]);

        $limits = $this->limits();
        $this->assertSame(7, $limits->defaultSteps());
        $this->assertSame(50, $limits->maximumSteps());
        $this->assertSame('0.2500000000', $limits->defaultSpend());
        $this->assertSame('3.5000000000', $limits->maximumSpend());
        $this->assertSame('0.9000', $limits->approachThreshold());
        $this->assertSame(30, $limits->retentionDays());
    }

    public static function thresholdsOutsideTheOpenUnitInterval(): array
    {
        return [
            'zero' => [0],
            'one' => [1],
            'above one' => [1.5],
            'negative' => [-0.2],
            'non-numeric' => ['most'],
        ];
    }

    #[Test]
    #[DataProvider('thresholdsOutsideTheOpenUnitInterval')]
    public function a_threshold_outside_zero_to_one_falls_back(mixed $value): void
    {
        config(['llm-client.task_budget.approach_threshold' => $value]);
        $this->assertSame('0.8000', $this->limits()->approachThreshold());

        config(['llm-client.budget.default_approach_threshold' => 0.75]);
        $this->assertSame('0.7500', $this->limits()->approachThreshold(), 'the fallback is budget.default_approach_threshold');

        config(['llm-client.budget.default_approach_threshold' => 7]);
        $this->assertSame('0.8000', $this->limits()->approachThreshold(), 'and when that is invalid too, the shipped 0.80');
    }

    #[Test]
    public function configuration_is_read_at_call_time(): void
    {
        $limits = $this->limits();
        $this->assertSame(200, $limits->maximumSteps());

        config(['llm-client.task_budget.maximum_steps' => 75]);
        $this->assertSame(75, $limits->maximumSteps(), 'the same instance sees the new value');

        config(['llm-client.agent_loop.max_iterations' => 9]);
        $this->assertSame(9, $limits->defaultSteps());
    }

    #[Test]
    public function clamp_with_nothing_stated_gives_the_defaults_with_default_origins(): void
    {
        foreach ([null, new StatedCeilings()] as $stated) {
            $resolved = $this->limits()->clamp($stated);

            $this->assertSame(20, $resolved['steps']);
            $this->assertSame('1.0000000000', $resolved['spend']);
            $this->assertSame(CeilingOrigin::Default, $resolved['steps_origin']);
            $this->assertSame(CeilingOrigin::Default, $resolved['spend_origin']);
            $this->assertFalse($resolved['steps_reduced']);
            $this->assertFalse($resolved['spend_reduced']);
        }
    }

    #[Test]
    public function clamp_keeps_a_stated_ceiling_within_the_maximum_with_user_origin(): void
    {
        $resolved = $this->limits()->clamp(new StatedCeilings(maxSteps: 50, maxSpend: '2.00'));

        $this->assertSame(50, $resolved['steps']);
        $this->assertSame('2.0000000000', $resolved['spend']);
        $this->assertSame(CeilingOrigin::User, $resolved['steps_origin']);
        $this->assertSame(CeilingOrigin::User, $resolved['spend_origin']);
        $this->assertFalse($resolved['steps_reduced']);
        $this->assertFalse($resolved['spend_reduced']);
    }

    #[Test]
    public function clamp_reduces_a_stated_ceiling_above_the_maximum_and_says_so(): void
    {
        $resolved = $this->limits()->clamp(new StatedCeilings(maxSteps: 1000, maxSpend: '50.00'));

        $this->assertSame(200, $resolved['steps']);
        $this->assertSame('25.0000000000', $resolved['spend']);
        $this->assertSame(CeilingOrigin::UserReducedToMaximum, $resolved['steps_origin']);
        $this->assertSame(CeilingOrigin::UserReducedToMaximum, $resolved['spend_origin']);
        $this->assertTrue($resolved['steps_reduced']);
        $this->assertTrue($resolved['spend_reduced']);
    }

    #[Test]
    public function clamp_takes_each_ceiling_independently(): void
    {
        $resolved = $this->limits()->clamp(new StatedCeilings(maxSteps: 5));

        $this->assertSame(5, $resolved['steps']);
        $this->assertSame(CeilingOrigin::User, $resolved['steps_origin']);
        $this->assertSame('1.0000000000', $resolved['spend']);
        $this->assertSame(CeilingOrigin::Default, $resolved['spend_origin']);
    }

    public static function unusableStatedValues(): array
    {
        return [
            'zero steps' => [new StatedCeilings(maxSteps: 0)],
            'negative steps' => [new StatedCeilings(maxSteps: -3)],
            'zero spend' => [new StatedCeilings(maxSpend: '0')],
            'negative spend' => [new StatedCeilings(maxSpend: '-1.00')],
            'non-numeric spend' => [new StatedCeilings(maxSpend: 'abc')],
        ];
    }

    /**
     * Validation of a stated ceiling is the entry point's job (422); a value
     * that reaches clamp() unusable is treated as not stated, so the result
     * is never zero, negative or non-finite.
     */
    #[Test]
    #[DataProvider('unusableStatedValues')]
    public function clamp_never_returns_a_non_positive_or_non_finite_ceiling(StatedCeilings $stated): void
    {
        $resolved = $this->limits()->clamp($stated);

        $this->assertGreaterThanOrEqual(1, $resolved['steps']);
        $this->assertLessThanOrEqual(200, $resolved['steps']);
        $this->assertMatchesRegularExpression('/^\d+\.\d{10}$/', $resolved['spend']);
        $this->assertSame(1, bccomp($resolved['spend'], '0', 10), 'spend ceiling > 0');
        $this->assertLessThanOrEqual(0, bccomp($resolved['spend'], '25', 10));
        $this->assertSame(20, $resolved['steps']);
        $this->assertSame('1.0000000000', $resolved['spend']);
        $this->assertSame(CeilingOrigin::Default, $resolved['steps_origin']);
        $this->assertSame(CeilingOrigin::Default, $resolved['spend_origin']);
    }

    #[Test]
    public function clamp_allowance_adds_to_the_current_ceiling_within_the_maximum(): void
    {
        $result = $this->limits()->clampAllowance(20, 20, '1.0000000000', '1.00');

        $this->assertSame(20, $result['add_steps']);
        $this->assertSame(40, $result['steps']);
        $this->assertSame('1.0000000000', $result['add_spend']);
        $this->assertSame('2.0000000000', $result['spend']);
        $this->assertFalse($result['steps_reduced']);
        $this->assertFalse($result['spend_reduced']);
    }

    #[Test]
    public function clamp_allowance_is_reduced_to_what_the_maximum_leaves(): void
    {
        $result = $this->limits()->clampAllowance(190, 20, '24.5000000000', '1.00');

        $this->assertSame(10, $result['add_steps']);
        $this->assertSame(200, $result['steps']);
        $this->assertTrue($result['steps_reduced']);
        $this->assertSame('0.5000000000', $result['add_spend']);
        $this->assertSame('25.0000000000', $result['spend']);
        $this->assertTrue($result['spend_reduced']);
    }

    #[Test]
    public function clamp_allowance_at_the_maximum_offers_nothing(): void
    {
        $result = $this->limits()->clampAllowance(200, 20, '25.0000000000', '1.00');

        $this->assertSame(0, $result['add_steps'], 'nothing is available at the installation maximum');
        $this->assertSame(200, $result['steps']);
        $this->assertTrue($result['steps_reduced']);
        $this->assertSame('0.0000000000', $result['add_spend']);
        $this->assertSame('25.0000000000', $result['spend']);
        $this->assertTrue($result['spend_reduced']);
    }

    #[Test]
    public function clamp_allowance_leaves_a_ceiling_with_no_allowance_unchanged(): void
    {
        $result = $this->limits()->clampAllowance(20, null, '1.0000000000', null);

        $this->assertNull($result['add_steps']);
        $this->assertSame(20, $result['steps']);
        $this->assertNull($result['add_spend']);
        $this->assertSame('1.0000000000', $result['spend']);
        $this->assertFalse($result['steps_reduced']);
        $this->assertFalse($result['spend_reduced']);
    }

    #[Test]
    public function spend_arithmetic_is_exact_decimal_never_float(): void
    {
        // 0.1 + 0.2 is 0.30000000000000004 in binary floating point.
        $result = $this->limits()->clampAllowance(20, null, '0.1', '0.2');

        $this->assertSame('0.3000000000', $result['spend']);
        $this->assertSame('0.2000000000', $result['add_spend']);

        $resolved = $this->limits()->clamp(new StatedCeilings(maxSpend: '0.3'));
        $this->assertSame('0.3000000000', $resolved['spend']);
    }
}
