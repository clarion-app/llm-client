<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\LlmClient\Services\QuestionWaitingPeriod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * How long a question stays answerable: the period the agent asked for, or
 * the installation default when it asked for none, always brought within
 * the installation's minimum and maximum — never refused. The bounds are
 * humane by default (one hour to one week); a maximum configured below the
 * minimum yields to the minimum, so a misconfiguration can never make a
 * question expire sooner than the floor.
 *
 * Every configured value is first capped at a hard ceiling of one year, so
 * no configuration can overflow the stored period or push the deadline past
 * what a MySQL TIMESTAMP holds.
 */
class QuestionWaitingPeriodTest extends TestCase
{
    private function configure(int $default = 1440, int $min = 60, int $max = 10080): void
    {
        config([
            'llm-client.agent_questions.expiry_minutes' => $default,
            'llm-client.agent_questions.min_expiry_minutes' => $min,
            'llm-client.agent_questions.max_expiry_minutes' => $max,
        ]);
    }

    private function period(): QuestionWaitingPeriod
    {
        return new QuestionWaitingPeriod();
    }

    #[Test]
    public function the_ceiling_is_one_year_of_minutes(): void
    {
        $this->assertSame(525600, QuestionWaitingPeriod::CEILING_MINUTES);
    }

    /** @return array<string, array{0: ?int, 1: int}> requested => effective, with the shipped bounds */
    public static function requestsWithDefaultBounds(): array
    {
        return [
            'nothing requested: the default' => [null, 1440],
            'below the minimum' => [5, 60],
            'at the minimum' => [60, 60],
            'within the bounds' => [600, 600],
            'at the maximum' => [10080, 10080],
            'above the maximum' => [20000, 10080],
            'zero' => [0, 60],
            'negative' => [-30, 60],
            'hostile' => [1_000_000_000, 10080],
            'hostile negative' => [-1_000_000_000, 60],
        ];
    }

    #[Test]
    #[DataProvider('requestsWithDefaultBounds')]
    public function a_request_is_brought_within_the_bounds(?int $requested, int $expected): void
    {
        $this->configure();

        $this->assertSame($expected, $this->period()->effectiveMinutes($requested));
    }

    #[Test]
    public function the_container_builds_it(): void
    {
        $this->configure();

        $this->assertSame(600, app(QuestionWaitingPeriod::class)->effectiveMinutes(600));
    }

    #[Test]
    public function bounds_reports_the_configured_values(): void
    {
        $this->configure();

        $this->assertSame(['default' => 1440, 'min' => 60, 'max' => 10080], $this->period()->bounds());
    }

    #[Test]
    public function a_default_below_the_minimum_is_raised_to_it(): void
    {
        $this->configure(default: 30);

        $this->assertSame(60, $this->period()->effectiveMinutes(null));
        $this->assertSame(['default' => 60, 'min' => 60, 'max' => 10080], $this->period()->bounds());
    }

    #[Test]
    public function a_default_above_the_maximum_is_lowered_to_it(): void
    {
        $this->configure(default: 20000);

        $this->assertSame(10080, $this->period()->effectiveMinutes(null));
        $this->assertSame(['default' => 10080, 'min' => 60, 'max' => 10080], $this->period()->bounds());
    }

    #[Test]
    public function a_maximum_below_the_minimum_yields_to_the_minimum(): void
    {
        $this->configure(default: 1440, min: 60, max: 10);

        foreach ([null, 5, 10, 60, 600, 20000] as $requested) {
            $this->assertSame(60, $this->period()->effectiveMinutes($requested), 'requested '.var_export($requested, true));
        }
        $this->assertSame(['default' => 60, 'min' => 60, 'max' => 60], $this->period()->bounds());
    }

    #[Test]
    public function a_minimum_below_one_is_treated_as_one(): void
    {
        $this->configure(default: 1440, min: 0, max: 10080);

        $this->assertSame(1, $this->period()->effectiveMinutes(0));
        $this->assertSame(1, $this->period()->effectiveMinutes(-30));
        $this->assertSame(1, $this->period()->bounds()['min']);

        $this->configure(default: 1440, min: -5, max: 10080);

        $this->assertSame(1, $this->period()->effectiveMinutes(-30));
        $this->assertSame(1, $this->period()->bounds()['min']);
    }

    #[Test]
    public function a_configured_maximum_above_the_ceiling_is_capped(): void
    {
        $this->configure(default: 1440, min: 60, max: 10_000_000_000);

        $this->assertSame(525600, $this->period()->effectiveMinutes(20_000_000));
        $this->assertSame(525600, $this->period()->bounds()['max']);
    }

    #[Test]
    public function a_configured_default_above_the_ceiling_is_capped(): void
    {
        $this->configure(default: 1_000_000_000, min: 60, max: 10_000_000_000);

        $this->assertSame(525600, $this->period()->effectiveMinutes(null));
        $this->assertSame(['default' => 525600, 'min' => 60, 'max' => 525600], $this->period()->bounds());
    }

    #[Test]
    public function a_configured_minimum_above_the_ceiling_is_capped(): void
    {
        $this->configure(default: 1440, min: 1_000_000_000, max: 10_000_000_000);

        $this->assertSame(525600, $this->period()->effectiveMinutes(5));
        $this->assertSame(['default' => 525600, 'min' => 525600, 'max' => 525600], $this->period()->bounds());
    }

    #[Test]
    public function configuration_is_read_at_call_time(): void
    {
        $period = $this->period();

        $this->configure();
        $this->assertSame(1440, $period->effectiveMinutes(null));
        $this->assertSame(60, $period->effectiveMinutes(5));

        $this->configure(default: 120, min: 10, max: 300);
        $this->assertSame(120, $period->effectiveMinutes(null), 'the same instance sees the new default');
        $this->assertSame(10, $period->effectiveMinutes(5), 'the same instance sees the new minimum');
        $this->assertSame(300, $period->effectiveMinutes(600), 'the same instance sees the new maximum');
    }
}
