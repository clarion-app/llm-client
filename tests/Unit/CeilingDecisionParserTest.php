<?php

namespace Tests\Unit;

use ClarionApp\LlmClient\Services\CeilingDecisionParser;
use ClarionApp\LlmClient\Services\TaskBudgetText;
use ClarionApp\LlmClient\ValueObjects\CeilingDecision;
use ClarionApp\LlmClient\ValueObjects\CeilingOrigin;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use ClarionApp\LlmClient\ValueObjects\TaskBudgetStanding;
use ClarionApp\LlmClient\ValueObjects\TaskBudgetState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A reply to a task's ceiling prompt, read the same way on every channel.
 *
 * Three decisions, by number or by word in any case: 1 / continue, 2 /
 * accept, 3 / stop. Continue alone may carry an additional allowance —
 * `steps=N` and/or `spend=X`, in either order, each at most once. Anything
 * else is not a decision: it is answered with guidance naming the accepted
 * replies and what was wrong with this one, and nothing is applied.
 *
 * Read against the task's standing, continue is also checked: each reached
 * ceiling must end up with room for at least one more step (a ceiling that
 * was lowered below what is already used needs more than a small
 * allowance), an allowance above the installation maximum is cut to it and
 * marked reduced, and a reached ceiling given no allowance receives the
 * allowance the prompt offered. At the installation maximum there is no
 * allowance left to give, so continue is refused as such.
 */
class CeilingDecisionParserTest extends TestCase
{
    private CeilingDecisionParser $parser;

    private TaskBudgetText $text;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new CeilingDecisionParser();
        $this->text = new TaskBudgetText();
    }

    /** @param array<string, mixed> $overrides */
    private function standing(array $overrides = []): TaskBudgetStanding
    {
        return new TaskBudgetStanding(...array_merge([
            'taskBudgetId' => '11111111-1111-4111-8111-111111111111',
            'conversationId' => '22222222-2222-4222-8222-222222222222',
            'state' => TaskBudgetState::Paused,
            'promptState' => QuestionState::Pending,
            'promptQuestionId' => '33333333-3333-4333-8333-333333333333',
            'stepsUsed' => 20,
            'maxSteps' => 20,
            'stepsOrigin' => CeilingOrigin::Default,
            'maximumSteps' => 200,
            'stepsApproachNoticed' => true,
            'spendUsed' => '0.2000000000',
            'maxSpend' => '1.0000000000',
            'currency' => 'USD',
            'spendOrigin' => CeilingOrigin::Default,
            'maximumSpend' => '25.0000000000',
            'approximate' => false,
            'unpricedRequestCount' => 0,
            'spendApproachNoticed' => false,
            'approachThreshold' => '0.8000',
        ], $overrides));
    }

    private function assertDecision(CeilingDecision $decision, string $kind, ?int $addSteps = null, ?string $addSpend = null): void
    {
        $this->assertSame($kind, $decision->kind);
        $this->assertSame($addSteps, $decision->addSteps, 'additional steps');
        $this->assertSame($addSpend, $decision->addSpend, 'additional spend');
        $this->assertNull($decision->guidance);
        $this->assertFalse($decision->isInvalid());
    }

    private function assertInvalid(CeilingDecision $decision, string $guidance): void
    {
        $this->assertTrue($decision->isInvalid());
        $this->assertSame(CeilingDecision::INVALID, $decision->kind);
        $this->assertSame($guidance, $decision->guidance);
        $this->assertNull($decision->addSteps);
        $this->assertNull($decision->addSpend);
    }

    // ---------------------------------------------------------------
    // The three decisions
    // ---------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string}> */
    public static function decisions(): array
    {
        return [
            '1' => ['1', CeilingDecision::CONTINUE],
            'continue' => ['continue', CeilingDecision::CONTINUE],
            'Continue' => ['Continue', CeilingDecision::CONTINUE],
            'CONTINUE padded' => ["  CONTINUE \n", CeilingDecision::CONTINUE],
            '2' => ['2', CeilingDecision::ACCEPT],
            'accept' => ['accept', CeilingDecision::ACCEPT],
            'Accept padded' => [' Accept ', CeilingDecision::ACCEPT],
            '3' => ['3', CeilingDecision::STOP],
            'stop' => ['stop', CeilingDecision::STOP],
            'STOP padded' => ["\tSTOP  ", CeilingDecision::STOP],
        ];
    }

    #[Test]
    #[DataProvider('decisions')]
    public function each_decision_is_read_by_number_or_by_word_in_any_case(string $reply, string $kind): void
    {
        $this->assertDecision($this->parser->parse($reply), $kind);
    }

    /** @return array<string, array{0: string, 1: ?int, 2: ?string}> */
    public static function allowances(): array
    {
        return [
            'steps' => ['continue steps=5', 5, null],
            'spend' => ['continue spend=0.50', null, '0.5000000000'],
            'both' => ['continue steps=5 spend=0.50', 5, '0.5000000000'],
            'both, reversed' => ['continue spend=0.50 steps=5', 5, '0.5000000000'],
            'by number' => ['1 steps=5', 5, null],
            'any case' => ['CONTINUE STEPS=5 Spend=2', 5, '2.0000000000'],
            'extra spaces' => ['  continue   steps=12    spend=1.25 ', 12, '1.2500000000'],
            'whole spend' => ['continue spend=3', null, '3.0000000000'],
        ];
    }

    #[Test]
    #[DataProvider('allowances')]
    public function continue_may_carry_an_additional_allowance_of_steps_and_or_spend(string $reply, ?int $steps, ?string $spend): void
    {
        $this->assertDecision($this->parser->parse($reply), CeilingDecision::CONTINUE, $steps, $spend);
    }

    // ---------------------------------------------------------------
    // Replies that are not a decision
    // ---------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string, 2: array<string, mixed>}> */
    public static function invalidReplies(): array
    {
        return [
            'allowance on accept' => ['accept steps=5', TaskBudgetText::PROBLEM_ALLOWANCE_WITHOUT_CONTINUE, []],
            'allowance on stop' => ['stop spend=1.00', TaskBudgetText::PROBLEM_ALLOWANCE_WITHOUT_CONTINUE, []],
            'allowance on 2' => ['2 steps=5', TaskBudgetText::PROBLEM_ALLOWANCE_WITHOUT_CONTINUE, []],
            'repeated steps' => ['continue steps=5 steps=6', TaskBudgetText::PROBLEM_REPEATED_KEY, ['key' => 'steps']],
            'repeated spend' => ['continue spend=1 spend=2', TaskBudgetText::PROBLEM_REPEATED_KEY, ['key' => 'spend']],
            'steps=0' => ['continue steps=0', TaskBudgetText::PROBLEM_INVALID_STEPS, ['value' => '0']],
            'steps=-1' => ['continue steps=-1', TaskBudgetText::PROBLEM_INVALID_STEPS, ['value' => '-1']],
            'steps=1.5' => ['continue steps=1.5', TaskBudgetText::PROBLEM_INVALID_STEPS, ['value' => '1.5']],
            'steps=abc' => ['continue steps=abc', TaskBudgetText::PROBLEM_INVALID_STEPS, ['value' => 'abc']],
            'spend=0' => ['continue spend=0', TaskBudgetText::PROBLEM_INVALID_SPEND, ['value' => '0']],
            'spend=0.00' => ['continue spend=0.00', TaskBudgetText::PROBLEM_INVALID_SPEND, ['value' => '0.00']],
            'spend=-1' => ['continue spend=-1', TaskBudgetText::PROBLEM_INVALID_SPEND, ['value' => '-1']],
            'spend=abc' => ['continue spend=abc', TaskBudgetText::PROBLEM_INVALID_SPEND, ['value' => 'abc']],
            'spend=1e3' => ['continue spend=1e3', TaskBudgetText::PROBLEM_INVALID_SPEND, ['value' => '1e3']],
            'maybe' => ['maybe', TaskBudgetText::PROBLEM_UNRECOGNISED, ['reply' => 'maybe']],
            'a fourth choice' => ['4', TaskBudgetText::PROBLEM_UNRECOGNISED, ['reply' => '4']],
            'a sentence' => ['continue please', TaskBudgetText::PROBLEM_UNRECOGNISED, ['reply' => 'continue please']],
            'an unknown key' => ['continue budget=5', TaskBudgetText::PROBLEM_UNRECOGNISED, ['reply' => 'continue budget=5']],
            'a choice label' => ['Accept what has been done', TaskBudgetText::PROBLEM_UNRECOGNISED, ['reply' => 'Accept what has been done']],
            'empty' => ['', TaskBudgetText::PROBLEM_UNRECOGNISED, ['reply' => '']],
            'blank' => ["   \n", TaskBudgetText::PROBLEM_UNRECOGNISED, ['reply' => '']],
        ];
    }

    #[Test]
    #[DataProvider('invalidReplies')]
    public function anything_else_is_answered_with_guidance_naming_the_accepted_replies_and_the_problem(string $reply, string $problem, array $params): void
    {
        $decision = $this->parser->parse($reply);

        $this->assertInvalid($decision, $this->text->decisionGuidance($problem, $params));
        $this->assertStringContainsString("1 or 'continue'", (string) $decision->guidance);
        $this->assertStringContainsString("2 or 'accept'", (string) $decision->guidance);
        $this->assertStringContainsString("3 or 'stop'", (string) $decision->guidance);
    }

    #[Test]
    #[DataProvider('invalidReplies')]
    public function an_invalid_reply_is_invalid_against_a_standing_too(string $reply, string $problem, array $params): void
    {
        $this->assertInvalid(
            $this->parser->parse($reply, $this->standing(), 20, null),
            $this->text->decisionGuidance($problem, $params),
        );
    }

    // ---------------------------------------------------------------
    // Continue, read against the task's standing
    // ---------------------------------------------------------------

    #[Test]
    public function an_allowance_that_makes_room_for_another_step_is_accepted(): void
    {
        // 20 of 20 used: steps=5 makes the ceiling 25.
        $decision = $this->parser->parse('continue steps=5', $this->standing(), 20, null);

        $this->assertDecision($decision, CeilingDecision::CONTINUE, 5, null);
        $this->assertFalse($decision->stepsReduced);
        $this->assertFalse($decision->spendReduced);
    }

    #[Test]
    public function an_allowance_that_leaves_a_lowered_ceiling_below_what_is_used_is_refused_with_the_figures(): void
    {
        // The ceiling was lowered to 10 after 20 steps were used: +5 makes 15, still below 20.
        $standing = $this->standing(['maxSteps' => 10, 'stepsUsed' => 20]);

        $this->assertInvalid(
            $this->parser->parse('continue steps=5', $standing, 10, null),
            $this->text->decisionGuidance(TaskBudgetText::PROBLEM_NO_ROOM_STEPS, ['given' => 5, 'used' => 20]),
        );
        $this->assertStringEndsWith('steps=5 would not allow another step: 20 already used.', (string) $this->parser->parse('continue steps=5', $standing, 10, null)->guidance);

        // Exactly reaching what is used is still no room for one more step.
        $this->assertTrue($this->parser->parse('continue steps=10', $standing, 10, null)->isInvalid());
        $this->assertDecision($this->parser->parse('continue steps=11', $standing, 10, null), CeilingDecision::CONTINUE, 11, null);
    }

    #[Test]
    public function a_spend_allowance_that_leaves_no_room_is_refused_with_the_figures(): void
    {
        // 1.02 spent against a 1.00 ceiling (the step that crossed it finished): +0.01 makes 1.01.
        $standing = $this->standing(['stepsUsed' => 4, 'spendUsed' => '1.0200000000']);

        $this->assertInvalid(
            $this->parser->parse('continue spend=0.01', $standing, null, '1.0000000000'),
            $this->text->decisionGuidance(TaskBudgetText::PROBLEM_NO_ROOM_SPEND, ['given' => '0.01', 'used' => '1.0200000000', 'currency' => 'USD']),
        );
        $this->assertDecision($this->parser->parse('continue spend=0.50', $standing, null, '1.0000000000'), CeilingDecision::CONTINUE, null, '0.5000000000');
    }

    #[Test]
    public function a_reached_ceiling_given_no_allowance_receives_the_offered_one(): void
    {
        $this->assertDecision($this->parser->parse('continue', $this->standing(), 20, null), CeilingDecision::CONTINUE, 20, null);
        $this->assertDecision($this->parser->parse('1', $this->standing(), 20, null), CeilingDecision::CONTINUE, 20, null);

        $spend = $this->standing(['stepsUsed' => 4, 'spendUsed' => '1.0000000000']);
        $this->assertDecision($this->parser->parse('continue', $spend, null, '1.0000000000'), CeilingDecision::CONTINUE, null, '1.0000000000');

        $both = $this->standing(['spendUsed' => '1.0000000000']);
        $this->assertDecision($this->parser->parse('continue', $both, 20, '1.0000000000'), CeilingDecision::CONTINUE, 20, '1.0000000000');
    }

    #[Test]
    public function a_stated_allowance_raises_its_ceiling_whether_or_not_it_was_reached(): void
    {
        // Steps reached and not stated: the offered 20. Spend not reached but stated: raised too.
        $decision = $this->parser->parse('continue spend=0.50', $this->standing(), 20, null);

        $this->assertDecision($decision, CeilingDecision::CONTINUE, 20, '0.5000000000');
    }

    #[Test]
    public function with_no_ceiling_reached_continue_alone_grants_nothing(): void
    {
        // Raised by the user while paused: nothing is reached any more.
        $standing = $this->standing(['maxSteps' => 30]);

        $this->assertDecision($this->parser->parse('continue', $standing, null, null), CeilingDecision::CONTINUE, null, null);
    }

    #[Test]
    public function an_allowance_above_the_installation_maximum_is_cut_to_it_and_marked_reduced(): void
    {
        $standing = $this->standing(['maximumSteps' => 30]);

        $decision = $this->parser->parse('continue steps=50', $standing, 10, null);

        $this->assertDecision($decision, CeilingDecision::CONTINUE, 10, null);
        $this->assertTrue($decision->stepsReduced);
        $this->assertFalse($decision->spendReduced);

        $spend = $this->standing(['stepsUsed' => 4, 'spendUsed' => '1.0000000000', 'maximumSpend' => '1.5000000000']);
        $decision = $this->parser->parse('continue spend=2', $spend, null, '0.5000000000');

        $this->assertDecision($decision, CeilingDecision::CONTINUE, null, '0.5000000000');
        $this->assertTrue($decision->spendReduced);
        $this->assertFalse($decision->stepsReduced);
    }

    #[Test]
    public function at_the_installation_maximum_continue_is_refused_because_no_allowance_is_left(): void
    {
        $standing = $this->standing(['stepsUsed' => 200, 'maxSteps' => 200, 'maximumSteps' => 200]);
        $guidance = $this->text->decisionGuidance(TaskBudgetText::PROBLEM_AT_MAXIMUM);

        // The prompt offered nothing ("not available"), and a stated allowance is cut to nothing.
        $this->assertInvalid($this->parser->parse('continue', $standing, 0, null), $guidance);
        $this->assertInvalid($this->parser->parse('1', $standing, 0, null), $guidance);
        $this->assertInvalid($this->parser->parse('continue steps=5', $standing, 0, null), $guidance);

        $spend = $this->standing(['stepsUsed' => 4, 'spendUsed' => '25.0000000000', 'maxSpend' => '25.0000000000']);
        $this->assertInvalid($this->parser->parse('continue', $spend, null, '0.0000000000'), $guidance);

        // Accept and stop are always available.
        $this->assertDecision($this->parser->parse('accept', $standing, 0, null), CeilingDecision::ACCEPT);
        $this->assertDecision($this->parser->parse('stop', $standing, 0, null), CeilingDecision::STOP);
    }

    #[Test]
    public function the_at_maximum_guidance_names_this_tasks_budget_and_the_maximum(): void
    {
        $this->assertSame(
            $this->text->decisionGuidance().' This reply was not accepted: no more allowance is available, because this task is at the installation maximum.',
            $this->text->decisionGuidance(TaskBudgetText::PROBLEM_AT_MAXIMUM),
        );
    }

    #[Test]
    public function accept_and_stop_ignore_the_standing(): void
    {
        $standing = $this->standing(['maxSteps' => 10, 'stepsUsed' => 20]);

        $this->assertDecision($this->parser->parse('2', $standing, 10, null), CeilingDecision::ACCEPT);
        $this->assertDecision($this->parser->parse('3', $standing, 10, null), CeilingDecision::STOP);
    }

    // ---------------------------------------------------------------
    // The normalized reply stored with the answer
    // ---------------------------------------------------------------

    #[Test]
    public function a_decision_is_stored_as_its_normalized_reply(): void
    {
        $this->assertSame('continue steps=20 spend=1.00', CeilingDecision::continue(20, '1.0000000000')->toReply());
        $this->assertSame('continue steps=5', CeilingDecision::continue(5)->toReply());
        $this->assertSame('continue spend=0.50', CeilingDecision::continue(null, '0.5000000000')->toReply());
        $this->assertSame('continue spend=0.005', CeilingDecision::continue(null, '0.0050000000')->toReply());
        $this->assertSame('continue spend=12.3456', CeilingDecision::continue(null, '12.3456000000')->toReply());
        $this->assertSame('continue', CeilingDecision::continue()->toReply());
        $this->assertSame('accept', CeilingDecision::accept()->toReply());
        $this->assertSame('stop', CeilingDecision::stop()->toReply());
    }

    #[Test]
    public function the_normalized_reply_reads_back_as_the_same_decision(): void
    {
        $decision = $this->parser->parse('Continue  spend=0.5 STEPS=7', $this->standing(), 20, null);
        $again = $this->parser->parse($decision->toReply());

        $this->assertSame('continue steps=7 spend=0.50', $decision->toReply());
        $this->assertDecision($again, CeilingDecision::CONTINUE, 7, '0.5000000000');
    }

    #[Test]
    public function an_invalid_reply_has_no_normalized_form(): void
    {
        $this->expectException(\LogicException::class);

        CeilingDecision::invalid('guidance')->toReply();
    }
}
