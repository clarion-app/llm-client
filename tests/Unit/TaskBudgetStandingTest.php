<?php

namespace Tests\Unit;

use ClarionApp\LlmClient\Services\TaskBudgetText;
use ClarionApp\LlmClient\ValueObjects\CeilingOrigin;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use ClarionApp\LlmClient\ValueObjects\StatedCeilings;
use ClarionApp\LlmClient\ValueObjects\TaskBudgetDecision;
use ClarionApp\LlmClient\ValueObjects\TaskBudgetStanding;
use ClarionApp\LlmClient\ValueObjects\TaskBudgetState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The value objects a task budget is read and decided through.
 *
 * TaskBudgetStanding::toArray() is the shape every surface shows — the
 * conversation's budget endpoint, the ceiling prompt's view, the run
 * record — so its keys and their order are pinned exactly. Whether a
 * paused task is awaiting a decision or stopped is derived from its
 * prompt's state at read time and never stored a second time.
 */
class TaskBudgetStandingTest extends TestCase
{
    private const TASK_ID = '11111111-1111-4111-8111-111111111111';
    private const CONVERSATION_ID = '22222222-2222-4222-8222-222222222222';
    private const PROMPT_ID = '33333333-3333-4333-8333-333333333333';

    /** @param array<string, mixed> $overrides */
    private function standing(array $overrides = []): TaskBudgetStanding
    {
        return new TaskBudgetStanding(...array_merge([
            'taskBudgetId' => self::TASK_ID,
            'conversationId' => self::CONVERSATION_ID,
            'state' => TaskBudgetState::Running,
            'promptState' => null,
            'promptQuestionId' => null,
            'stepsUsed' => 16,
            'maxSteps' => 20,
            'stepsOrigin' => CeilingOrigin::Default,
            'maximumSteps' => 200,
            'stepsApproachNoticed' => true,
            'spendUsed' => '0.4120000000',
            'maxSpend' => '1.0000000000',
            'currency' => 'USD',
            'spendOrigin' => CeilingOrigin::Default,
            'maximumSpend' => '25.0000000000',
            'approximate' => false,
            'unpricedRequestCount' => 2,
            'spendApproachNoticed' => false,
            'approachThreshold' => '0.8000',
        ], $overrides));
    }

    #[Test]
    public function task_budget_states_and_which_are_terminal(): void
    {
        $this->assertSame(
            ['running', 'paused', 'concluded', 'completed', 'halted', 'ended'],
            array_map(fn (TaskBudgetState $s) => $s->value, TaskBudgetState::cases())
        );

        $expected = [
            'running' => false,
            'paused' => false,
            'concluded' => true,
            'completed' => true,
            'halted' => true,
            'ended' => true,
        ];

        foreach ($expected as $value => $terminal) {
            $this->assertSame($terminal, TaskBudgetState::from($value)->isTerminal(), "{$value} terminal?");
        }
    }

    #[Test]
    public function every_state_fits_its_sixteen_character_column(): void
    {
        foreach (TaskBudgetState::cases() as $state) {
            $this->assertLessThanOrEqual(16, strlen($state->value), $state->value);
        }
    }

    #[Test]
    public function ceiling_origins_and_their_column_width(): void
    {
        $this->assertSame(
            ['default', 'user', 'user_reduced_to_maximum'],
            array_map(fn (CeilingOrigin $o) => $o->value, CeilingOrigin::cases())
        );

        foreach (CeilingOrigin::cases() as $origin) {
            $this->assertLessThanOrEqual(32, strlen($origin->value), $origin->value);
        }
    }

    #[Test]
    public function to_array_has_exactly_the_api_keys_in_order(): void
    {
        $array = $this->standing()->toArray();

        $this->assertSame(
            ['task_budget_id', 'conversation_id', 'state', 'sub_state', 'steps', 'spend', 'approach_threshold', 'reached', 'prompt_question_id', 'label'],
            array_keys($array)
        );
        $this->assertSame(['used', 'ceiling', 'origin', 'maximum', 'approach_noticed'], array_keys($array['steps']));
        $this->assertSame(
            ['used', 'ceiling', 'currency', 'origin', 'maximum', 'approximate', 'incomplete', 'unpriced_request_count', 'approach_noticed'],
            array_keys($array['spend'])
        );
    }

    #[Test]
    public function to_array_carries_the_figures_as_the_api_documents_them(): void
    {
        $array = $this->standing()->toArray();

        $this->assertSame(self::TASK_ID, $array['task_budget_id']);
        $this->assertSame(self::CONVERSATION_ID, $array['conversation_id']);
        $this->assertSame('running', $array['state']);
        $this->assertNull($array['sub_state']);
        $this->assertSame(['used' => 16, 'ceiling' => 20, 'origin' => 'default', 'maximum' => 200, 'approach_noticed' => true], $array['steps']);
        $this->assertSame([
            'used' => '0.4120000000',
            'ceiling' => '1.0000000000',
            'currency' => 'USD',
            'origin' => 'default',
            'maximum' => '25.0000000000',
            'approximate' => false,
            'incomplete' => true,
            'unpriced_request_count' => 2,
            'approach_noticed' => false,
        ], $array['spend']);
        $this->assertSame('0.8000', $array['approach_threshold']);
        $this->assertSame([], $array['reached']);
        $this->assertNull($array['prompt_question_id']);
        $this->assertSame("This task's budget: 16 of 20 steps, 0.41 of 1.00 USD (incomplete: 2 requests had no price)", $array['label']);
    }

    #[Test]
    public function the_label_is_the_standing_sentence_of_task_budget_text(): void
    {
        foreach ([
            $this->standing(),
            $this->standing(['approximate' => true, 'unpricedRequestCount' => 0]),
            $this->standing(['stepsUsed' => 20, 'spendUsed' => '1.0600000000']),
        ] as $standing) {
            $this->assertSame((new TaskBudgetText())->standing($standing), $standing->toArray()['label']);
            $this->assertSame((new TaskBudgetText())->standing($standing), $standing->label());
        }
    }

    public static function subStates(): array
    {
        return [
            'running, no prompt' => [TaskBudgetState::Running, null, null],
            'paused, prompt pending' => [TaskBudgetState::Paused, QuestionState::Pending, 'awaiting_decision'],
            'paused, prompt stopped' => [TaskBudgetState::Paused, QuestionState::Stopped, 'stopped'],
            'paused, no prompt yet' => [TaskBudgetState::Paused, null, null],
            'paused, prompt answered (resume in flight)' => [TaskBudgetState::Paused, QuestionState::Answered, null],
            'concluded' => [TaskBudgetState::Concluded, QuestionState::Answered, null],
            'halted' => [TaskBudgetState::Halted, null, null],
            'ended, prompt withdrawn' => [TaskBudgetState::Ended, QuestionState::Withdrawn, null],
            'completed' => [TaskBudgetState::Completed, null, null],
        ];
    }

    #[Test]
    #[DataProvider('subStates')]
    public function the_sub_state_is_derived_from_the_prompt_state(TaskBudgetState $state, ?QuestionState $prompt, ?string $expected): void
    {
        $standing = $this->standing(['state' => $state, 'promptState' => $prompt, 'promptQuestionId' => $prompt === null ? null : self::PROMPT_ID]);

        $this->assertSame($expected, $standing->subState());
        $this->assertSame($expected, $standing->toArray()['sub_state']);
        $this->assertSame($state->value, $standing->toArray()['state']);
    }

    #[Test]
    public function the_prompt_question_id_is_reported(): void
    {
        $standing = $this->standing(['state' => TaskBudgetState::Paused, 'promptState' => QuestionState::Pending, 'promptQuestionId' => self::PROMPT_ID]);

        $this->assertSame(self::PROMPT_ID, $standing->toArray()['prompt_question_id']);
    }

    public static function reachedCases(): array
    {
        return [
            'neither' => [19, 20, '0.9999999999', '1.0000000000', []],
            'steps exactly at the ceiling' => [20, 20, '0.5000000000', '1.0000000000', ['steps']],
            'steps above a lowered ceiling' => [12, 10, '0.5000000000', '1.0000000000', ['steps']],
            'spend exactly at the ceiling' => [3, 20, '1.0000000000', '1.0000000000', ['spend']],
            'spend over the ceiling' => [3, 20, '1.0600000000', '1.0000000000', ['spend']],
            'both' => [20, 20, '1.2000000000', '1.0000000000', ['steps', 'spend']],
        ];
    }

    #[Test]
    #[DataProvider('reachedCases')]
    public function reached_lists_each_ceiling_at_or_past_its_limit(int $used, int $max, string $spend, string $maxSpend, array $expected): void
    {
        $standing = $this->standing(['stepsUsed' => $used, 'maxSteps' => $max, 'spendUsed' => $spend, 'maxSpend' => $maxSpend]);

        $this->assertSame($expected, $standing->reached());
        $this->assertSame($expected, $standing->toArray()['reached']);
    }

    #[Test]
    public function reached_compares_spend_as_decimals_not_floats(): void
    {
        // As doubles these two are equal (17 significant digits round to
        // 1.0E+7); as decimals the first is one ten-billionth below.
        $standing = $this->standing(['spendUsed' => '9999999.9999999999', 'maxSpend' => '10000000.0000000000']);

        $this->assertSame([], $standing->reached());
        $this->assertSame(['spend'], $this->standing(['spendUsed' => '10000000.0000000000', 'maxSpend' => '10000000.0000000000'])->reached());
    }

    #[Test]
    public function approximate_and_incomplete_come_from_the_rollup_flags(): void
    {
        $plain = $this->standing(['approximate' => false, 'unpricedRequestCount' => 0])->toArray()['spend'];
        $this->assertFalse($plain['approximate']);
        $this->assertFalse($plain['incomplete']);
        $this->assertSame(0, $plain['unpriced_request_count']);

        $estimated = $this->standing(['approximate' => true, 'unpricedRequestCount' => 0])->toArray()['spend'];
        $this->assertTrue($estimated['approximate']);
        $this->assertFalse($estimated['incomplete']);

        $unpriced = $this->standing(['approximate' => false, 'unpricedRequestCount' => 3]);
        $this->assertTrue($unpriced->incomplete());
        $this->assertTrue($unpriced->toArray()['spend']['incomplete']);
        $this->assertSame(3, $unpriced->toArray()['spend']['unpriced_request_count']);
    }

    #[Test]
    public function decimal_figures_are_reported_at_their_column_scales(): void
    {
        $array = $this->standing(['spendUsed' => '0.412', 'maxSpend' => '1', 'maximumSpend' => '25.00', 'approachThreshold' => '0.8'])->toArray();

        $this->assertSame('0.4120000000', $array['spend']['used']);
        $this->assertSame('1.0000000000', $array['spend']['ceiling']);
        $this->assertSame('25.0000000000', $array['spend']['maximum']);
        $this->assertSame('0.8000', $array['approach_threshold']);
    }

    #[Test]
    public function decisions_allow_and_stop_with_their_reasons(): void
    {
        $allow = TaskBudgetDecision::allow();
        $this->assertTrue($allow->isAllow());
        $this->assertFalse($allow->isStop());
        $this->assertSame(TaskBudgetDecision::ALLOW, $allow->verdict);
        $this->assertNull($allow->reason);

        foreach (['steps', 'spend', 'both', 'not_running', 'unavailable'] as $reason) {
            $stop = TaskBudgetDecision::stop($reason, $this->standing());
            $this->assertTrue($stop->isStop(), $reason);
            $this->assertFalse($stop->isAllow(), $reason);
            $this->assertSame(TaskBudgetDecision::STOP, $stop->verdict);
            $this->assertSame($reason, $stop->reason);
            $this->assertSame(self::TASK_ID, $stop->standing->taskBudgetId);
        }

        $this->assertSame('steps', TaskBudgetDecision::REASON_STEPS);
        $this->assertSame('spend', TaskBudgetDecision::REASON_SPEND);
        $this->assertSame('both', TaskBudgetDecision::REASON_BOTH);
        $this->assertSame('not_running', TaskBudgetDecision::REASON_NOT_RUNNING);
        $this->assertSame('unavailable', TaskBudgetDecision::REASON_UNAVAILABLE);
    }

    #[Test]
    public function unavailable_is_a_stop_with_no_standing(): void
    {
        $decision = TaskBudgetDecision::unavailable();

        $this->assertTrue($decision->isStop());
        $this->assertSame('unavailable', $decision->reason);
        $this->assertNull($decision->standing);
    }

    #[Test]
    public function a_stop_with_an_unknown_reason_is_a_programming_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TaskBudgetDecision::stop('tired');
    }

    #[Test]
    public function stated_ceilings_carry_what_was_stated_without_validating_it(): void
    {
        $empty = new StatedCeilings();
        $this->assertNull($empty->maxSteps);
        $this->assertNull($empty->maxSpend);
        $this->assertTrue($empty->isEmpty());

        $steps = new StatedCeilings(maxSteps: 50);
        $this->assertSame(50, $steps->maxSteps);
        $this->assertFalse($steps->isEmpty());

        // Validation is TaskBudgetLimits' job: nothing is rejected here.
        $odd = new StatedCeilings(maxSteps: 0, maxSpend: '-1');
        $this->assertSame(0, $odd->maxSteps);
        $this->assertSame('-1', $odd->maxSpend);
        $this->assertFalse($odd->isEmpty());
    }

    #[Test]
    public function stated_spend_is_normalized_to_a_decimal_string(): void
    {
        $this->assertSame('2.00', (new StatedCeilings(maxSpend: '2.00'))->maxSpend, 'a string is kept as given');
        $this->assertSame('2.50', (new StatedCeilings(maxSpend: ' 2.50 '))->maxSpend, 'surrounding whitespace is trimmed');
        $this->assertSame('3', (new StatedCeilings(maxSpend: 3))->maxSpend, 'an integer becomes its decimal string');

        $fromFloat = (new StatedCeilings(maxSpend: 0.25))->maxSpend;
        $this->assertIsString($fromFloat);
        $this->assertMatchesRegularExpression('/^\d+(\.\d+)?$/', $fromFloat, 'plain notation, never scientific');
        $this->assertSame(0, bccomp($fromFloat, '0.25', 10));

        $tiny = (new StatedCeilings(maxSpend: 0.00001))->maxSpend;
        $this->assertMatchesRegularExpression('/^\d+(\.\d+)?$/', $tiny, 'a float that would print as 1.0E-5 is plain');
    }
}
