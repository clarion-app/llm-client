<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\LlmClient\ValueObjects\QuestionState;
use ClarionApp\LlmClient\ValueObjects\UnansweredPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A question has two waiting states: `pending` (inside its answerable
 * period) and `stopped` (the period passed under the stop policy, and the
 * task waits for a late answer or a discard). Both keep the conversation's
 * question slot and both accept the owner's answer; neither continues the
 * run. Every other state is terminal.
 *
 * `state` is a 16-character column and `unanswered_policy` a 32-character
 * one; every value must fit, or MySQL refuses the write that sets it
 * (SQLite would not notice).
 */
class QuestionStateTest extends TestCase
{
    /**
     * @return array<string, array{0: QuestionState, 1: bool, 2: bool, 3: bool, 4: bool}>
     *   state => [isTerminal, acceptsAnswers, holdsSlot, continuesRun]
     */
    public static function truthTable(): array
    {
        return [
            'pending' => [QuestionState::Pending, false, true, true, false],
            'stopped' => [QuestionState::Stopped, false, true, true, false],
            'answered' => [QuestionState::Answered, true, false, false, true],
            'expired' => [QuestionState::Expired, true, false, false, true],
            'expired_assumed' => [QuestionState::ExpiredAssumed, true, false, false, true],
            'undeliverable' => [QuestionState::Undeliverable, true, false, false, true],
            'withdrawn' => [QuestionState::Withdrawn, true, false, false, false],
        ];
    }

    #[Test]
    #[DataProvider('truthTable')]
    public function each_state_answers_every_question_about_itself(
        QuestionState $state,
        bool $terminal,
        bool $acceptsAnswers,
        bool $holdsSlot,
        bool $continuesRun,
    ): void {
        $this->assertSame($terminal, $state->isTerminal(), "{$state->value}: isTerminal");
        $this->assertSame($acceptsAnswers, $state->acceptsAnswers(), "{$state->value}: acceptsAnswers");
        $this->assertSame($holdsSlot, $state->holdsSlot(), "{$state->value}: holdsSlot");
        $this->assertSame($continuesRun, $state->continuesRun(), "{$state->value}: continuesRun");
    }

    #[Test]
    public function the_truth_table_covers_every_state(): void
    {
        $covered = array_map(fn (array $row) => $row[0], self::truthTable());

        $this->assertEqualsCanonicalizing(QuestionState::cases(), array_values($covered));
        $this->assertCount(7, QuestionState::cases());
    }

    #[Test]
    public function the_new_states_have_their_stored_values(): void
    {
        $this->assertSame('expired_assumed', QuestionState::ExpiredAssumed->value);
        $this->assertSame('stopped', QuestionState::Stopped->value);
        $this->assertSame(QuestionState::Stopped, QuestionState::from('stopped'));
        $this->assertSame(QuestionState::ExpiredAssumed, QuestionState::from('expired_assumed'));
    }

    #[Test]
    public function every_state_value_fits_the_state_column(): void
    {
        foreach (QuestionState::cases() as $state) {
            $this->assertLessThanOrEqual(16, strlen($state->value), "'{$state->value}' must fit string(16)");
        }

        $this->assertSame(15, strlen(QuestionState::ExpiredAssumed->value), 'the longest value');
    }

    #[Test]
    public function the_policy_has_exactly_two_values(): void
    {
        $this->assertSame(UnansweredPolicy::Stop, UnansweredPolicy::from('stop'));
        $this->assertSame(UnansweredPolicy::ProceedOnAssumption, UnansweredPolicy::from('proceed_on_assumption'));
        $this->assertSame(['proceed_on_assumption', 'stop'], array_map(
            fn (UnansweredPolicy $p) => $p->value,
            UnansweredPolicy::cases(),
        ));
        $this->assertNull(UnansweredPolicy::tryFrom('ask_again'));
    }

    #[Test]
    public function every_policy_value_fits_the_policy_column(): void
    {
        foreach (UnansweredPolicy::cases() as $policy) {
            $this->assertLessThanOrEqual(32, strlen($policy->value), "'{$policy->value}' must fit string(32)");
        }
    }
}
