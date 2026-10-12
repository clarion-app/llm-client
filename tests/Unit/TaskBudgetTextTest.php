<?php

namespace Tests\Unit;

use ClarionApp\LlmClient\Services\TaskBudgetText;
use ClarionApp\LlmClient\ValueObjects\CeilingOrigin;
use ClarionApp\LlmClient\ValueObjects\TaskBudgetStanding;
use ClarionApp\LlmClient\ValueObjects\TaskBudgetState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every sentence a user reads about a task budget comes from one class.
 *
 * Two separate limits can stop work: the installation's spending quota and
 * a task's own budget. A user told "your spending limit was reached" when
 * it was the task's step ceiling — or the reverse — goes looking in the
 * wrong place. So every task-budget text names "this task's budget", and
 * none uses the quota's vocabulary. Keeping all of it in TaskBudgetText
 * makes that checkable here, by enumeration: a public method added later
 * that is not covered below fails this test until it is.
 *
 * Figures are formatted from decimal strings at the currency's two places,
 * rounded half up, without a float ever being formed.
 */
class TaskBudgetTextTest extends TestCase
{
    private const QUOTA_WORDING = ['spending limit', 'quota', 'ceiling for your account', 'spending ceiling'];

    /**
     * Methods that return something other than one task-budget sentence:
     * the choices list (three short button labels, checked for quota
     * wording only) and the run end_reason for a ceiling stop (a fixed
     * record value, "Task budget ceiling reached").
     */
    private const EXEMPT_FROM_NAMING = ['choices', 'ceilingReachedReason'];

    private TaskBudgetText $text;

    protected function setUp(): void
    {
        parent::setUp();
        $this->text = new TaskBudgetText();
    }

    /** @param array<string, mixed> $overrides */
    private function standing(array $overrides = []): TaskBudgetStanding
    {
        return new TaskBudgetStanding(...array_merge([
            'taskBudgetId' => '11111111-1111-4111-8111-111111111111',
            'conversationId' => '22222222-2222-4222-8222-222222222222',
            'state' => TaskBudgetState::Running,
            'promptState' => null,
            'promptQuestionId' => null,
            'stepsUsed' => 16,
            'maxSteps' => 20,
            'stepsOrigin' => CeilingOrigin::Default,
            'maximumSteps' => 200,
            'stepsApproachNoticed' => false,
            'spendUsed' => '0.4120000000',
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

    /**
     * Every public method, with every variant it has, mapped to the texts
     * it produces.
     *
     * @return array<string, list<string>>
     */
    private function everyText(): array
    {
        $t = $this->text;
        $s = $this->standing();
        $reachedSteps = $this->standing(['stepsUsed' => 20]);
        $overSpend = $this->standing(['spendUsed' => '1.0600000000']);

        return [
            'standing' => [
                $t->standing($s),
                $t->standing($this->standing(['approximate' => true])),
                $t->standing($this->standing(['unpricedRequestCount' => 2])),
                $t->standing($this->standing(['approximate' => true, 'unpricedRequestCount' => 1])),
            ],
            'approachNotice' => [
                $t->approachNotice($s, ['steps']),
                $t->approachNotice($this->standing(['spendUsed' => '0.8200000000']), ['spend']),
                $t->approachNotice($this->standing(['spendUsed' => '0.8200000000']), ['steps', 'spend']),
            ],
            'promptQuestion' => [$t->promptQuestion($reachedSteps)],
            'choices' => array_merge(
                $t->choices(20, null, 'USD'),
                $t->choices(null, '1.0000000000', 'USD'),
                $t->choices(20, '1.0000000000', 'USD'),
                $t->choices(0, null, 'USD'),
            ),
            'stoppedByChoice' => [$t->stoppedByChoice()],
            'expiredStopped' => [$t->expiredStopped()],
            'alreadyStopped' => [$t->alreadyStopped()],
            'decisionGuidance' => [
                $t->decisionGuidance(),
                $t->decisionGuidance(TaskBudgetText::PROBLEM_UNRECOGNISED, ['reply' => 'maybe']),
                $t->decisionGuidance(TaskBudgetText::PROBLEM_UNRECOGNISED, ['reply' => '']),
                $t->decisionGuidance(TaskBudgetText::PROBLEM_ALLOWANCE_WITHOUT_CONTINUE),
                $t->decisionGuidance(TaskBudgetText::PROBLEM_REPEATED_KEY, ['key' => 'steps']),
                $t->decisionGuidance(TaskBudgetText::PROBLEM_INVALID_STEPS, ['value' => '1.5']),
                $t->decisionGuidance(TaskBudgetText::PROBLEM_INVALID_SPEND, ['value' => 'abc']),
                $t->decisionGuidance(TaskBudgetText::PROBLEM_NO_ROOM_STEPS, ['given' => 5, 'used' => 20]),
                $t->decisionGuidance(TaskBudgetText::PROBLEM_NO_ROOM_SPEND, ['given' => '0.10', 'used' => '1.2000000000', 'currency' => 'USD']),
            ],
            'reducedToMaximum' => [
                $t->reducedToMaximum(1000, 200, null, null, 'USD'),
                $t->reducedToMaximum(null, null, '50.00', '25.0000000000', 'USD'),
                $t->reducedToMaximum(1000, 200, '50.00', '25.0000000000', 'USD'),
            ],
            'helperStopped' => [$t->helperStopped()],
            'managedShortfall' => [$t->managedShortfall(), $t->managedShortfall($reachedSteps)],
            'sequenceStopped' => [$t->sequenceStopped()],
            'consensusStopped' => [$t->consensusStopped()],
            'unavailable' => [$t->unavailable()],
            'noTaskInProgress' => [$t->noTaskInProgress()],
            'budgetUpdated' => [
                $t->budgetUpdated($s),
                $t->budgetUpdated($s, $t->reducedToMaximum(1000, 200, null, null, 'USD')),
            ],
            'directiveMalformed' => [$t->directiveMalformed()],
            'halted' => [$t->halted()],
            'concludedReason' => [$t->concludedReason()],
            'ceilingReachedReason' => [$t->ceilingReachedReason()],
            'reportHeadline' => [
                $t->reportHeadline($reachedSteps),
                $t->reportHeadline($overSpend),
                $t->reportHeadline($this->standing(['stepsUsed' => 20, 'spendUsed' => '1.0600000000'])),
                $t->reportHeadline($this->standing(['stepsUsed' => 12, 'maxSteps' => 10])),
                $t->reportHeadline($s),
            ],
            'reportConsumption' => [
                $t->reportConsumption($reachedSteps),
                $t->reportConsumption($this->standing(['approximate' => true])),
                $t->reportConsumption($this->standing(['unpricedRequestCount' => 1])),
                $t->reportConsumption($this->standing(['approximate' => true, 'unpricedRequestCount' => 3])),
            ],
            'reportDone' => [
                $t->reportDone([]),
                $t->reportDone(['execute_operation' => 3, 'list_applications' => 1]),
            ],
            'reportLatestProgress' => [$t->reportLatestProgress('Two of the three files are converted.')],
            'reportNothingOutstanding' => [$t->reportNothingOutstanding()],
            'reportNext' => [
                $t->reportNext('paused'),
                $t->reportNext('accepted'),
                $t->reportNext('stopped'),
                $t->reportNext('expired'),
                $t->reportNext('halted'),
            ],
        ];
    }

    #[Test]
    public function every_public_text_method_is_covered_by_this_test(): void
    {
        $public = array_values(array_filter(
            array_map(fn (\ReflectionMethod $m) => $m->getName(), (new \ReflectionClass(TaskBudgetText::class))->getMethods(\ReflectionMethod::IS_PUBLIC)),
            fn (string $name) => !str_starts_with($name, '__')
        ));
        sort($public);

        $covered = array_keys($this->everyText());
        sort($covered);

        $this->assertSame($covered, $public, 'a public TaskBudgetText method must be added to everyText() with all its variants');
    }

    #[Test]
    public function every_text_names_this_tasks_budget(): void
    {
        foreach ($this->everyText() as $method => $texts) {
            if (in_array($method, self::EXEMPT_FROM_NAMING, true)) {
                continue;
            }

            foreach ($texts as $text) {
                $this->assertTrue(
                    str_contains($text, "this task's budget") || str_contains($text, "This task's budget"),
                    "{$method}() must name this task's budget: {$text}"
                );
            }
        }
    }

    #[Test]
    public function no_text_uses_the_quotas_vocabulary(): void
    {
        foreach ($this->everyText() as $method => $texts) {
            foreach ($texts as $text) {
                foreach (self::QUOTA_WORDING as $phrase) {
                    $this->assertStringNotContainsStringIgnoringCase($phrase, $text, "{$method}(): {$text}");
                }
            }
        }
    }

    #[Test]
    public function the_standing_sentence_matches_the_api_example_byte_for_byte(): void
    {
        $this->assertSame(
            "This task's budget: 16 of 20 steps, 0.41 of 1.00 USD (incomplete: 2 requests had no price)",
            $this->text->standing($this->standing(['unpricedRequestCount' => 2]))
        );
        $this->assertSame(
            "This task's budget: 16 of 20 steps, 0.41 of 1.00 USD",
            $this->text->standing($this->standing())
        );
        $this->assertSame(
            "This task's budget: 16 of 20 steps, 0.41 of 1.00 USD (approximate: some usage was estimated)",
            $this->text->standing($this->standing(['approximate' => true]))
        );
        $this->assertSame(
            "This task's budget: 16 of 20 steps, 0.41 of 1.00 USD (approximate: some usage was estimated; incomplete: 1 request had no price)",
            $this->text->standing($this->standing(['approximate' => true, 'unpricedRequestCount' => 1]))
        );
    }

    #[Test]
    public function figures_are_formatted_from_decimal_strings_at_two_places(): void
    {
        $this->assertStringContainsString('0.41 of 1.00 USD', $this->text->standing($this->standing(['spendUsed' => '0.4120000000'])));
        $this->assertStringContainsString('0.42 of 1.00 USD', $this->text->standing($this->standing(['spendUsed' => '0.4150000000'])), 'half up');
        $this->assertStringContainsString('0.00 of 1.00 USD', $this->text->standing($this->standing(['spendUsed' => '0.0000000000'])));

        // 12345678901.2345678901 has more significant digits than a double holds.
        $this->assertStringContainsString(
            '12345678901.23 of 99999999999.99 EUR',
            $this->text->standing($this->standing(['spendUsed' => '12345678901.2345678901', 'maxSpend' => '99999999999.9900000000', 'currency' => 'EUR']))
        );
    }

    #[Test]
    public function the_approach_notice_matches_the_documented_example(): void
    {
        $this->assertSame(
            "Heads up — this task's budget is 80% used: 16 of 20 steps, 0.41 of 1.00 USD. It will pause for your decision at the ceiling. You can raise it now with '/budget steps=40' or from the conversation's limits.",
            $this->text->approachNotice($this->standing(), ['steps'])
        );
    }

    #[Test]
    public function the_approach_notice_suggests_raising_each_ceiling_it_names(): void
    {
        $spend = $this->text->approachNotice($this->standing(['spendUsed' => '0.8200000000']), ['spend']);
        $this->assertStringContainsString("'/budget spend=2.00'", $spend);
        $this->assertStringContainsString('0.82 of 1.00 USD', $spend);

        $both = $this->text->approachNotice($this->standing(['spendUsed' => '0.8200000000']), ['steps', 'spend']);
        $this->assertStringContainsString("'/budget steps=40 spend=2.00'", $both);

        $threshold = $this->text->approachNotice($this->standing(['approachThreshold' => '0.7500', 'stepsUsed' => 15]), ['steps']);
        $this->assertStringContainsString("this task's budget is 75% used", $threshold);
    }

    #[Test]
    public function the_prompt_question_is_fixed(): void
    {
        $this->assertSame(
            "This task's budget ceiling was reached. What should happen next?",
            $this->text->promptQuestion($this->standing(['stepsUsed' => 20]))
        );
    }

    #[Test]
    public function the_choices_are_continue_accept_stop_in_that_order(): void
    {
        $this->assertSame(
            ['Continue with more allowance (+20 steps)', 'Accept what has been done', 'Stop'],
            $this->text->choices(20, null, 'USD')
        );
        $this->assertSame(
            ['Continue with more allowance (+1.00 USD)', 'Accept what has been done', 'Stop'],
            $this->text->choices(null, '1.0000000000', 'USD')
        );
        $this->assertSame(
            ['Continue with more allowance (+20 steps, +1.00 USD)', 'Accept what has been done', 'Stop'],
            $this->text->choices(20, '1.0000000000', 'USD')
        );
        $this->assertSame(
            ['Continue with more allowance (+1 step)', 'Accept what has been done', 'Stop'],
            $this->text->choices(1, null, 'USD')
        );
    }

    #[Test]
    public function continue_says_when_no_allowance_is_available(): void
    {
        $unavailable = 'Continue with more allowance (not available — this task is at the installation maximum)';

        $this->assertSame([$unavailable, 'Accept what has been done', 'Stop'], $this->text->choices(0, null, 'USD'));
        $this->assertSame([$unavailable, 'Accept what has been done', 'Stop'], $this->text->choices(0, '0.0000000000', 'USD'));
        $this->assertSame(
            ['Continue with more allowance (+0.50 USD)', 'Accept what has been done', 'Stop'],
            $this->text->choices(0, '0.5000000000', 'USD'),
            'a ceiling at the maximum is left out while the other is still offered'
        );
    }

    #[Test]
    public function the_fixed_sentences(): void
    {
        $t = $this->text;

        $this->assertSame(
            "Stopped at this task's budget ceiling, as you asked. Nothing more will run until you decide: reply 'continue' to resume with more allowance or 'accept' to close the task with what has been done.",
            $t->stoppedByChoice()
        );
        $this->assertSame(
            "No answer arrived to this task's budget prompt, so work has stopped at the ceiling until you decide. Reply 'continue' to resume with more allowance, 'accept' to close the task with what has been done, or 'stop' to leave it stopped.",
            $t->expiredStopped()
        );
        $this->assertSame(
            "Already stopped at this task's budget ceiling. Reply 'continue' to resume it or 'accept' to close it.",
            $t->alreadyStopped()
        );
        $this->assertSame("Stopped by this task's budget before finishing.", $t->helperStopped());
        $this->assertSame("This task's budget ceiling was reached before this part could be completed.", $t->managedShortfall());
        $this->assertSame("This task's budget ceiling was reached, so this sequence stopped before this stage.", $t->sequenceStopped());
        $this->assertSame("Stopped by this task's budget before answering.", $t->consensusStopped());
        $this->assertSame("This task's budget could not be checked, so no further work was started.", $t->unavailable());
        $this->assertSame(
            "No task is running or paused in this conversation, so this task's budget cannot be shown or changed. To set it for your next request, start that request with '/budget steps=50 spend=2.00' on its first line.",
            $t->noTaskInProgress()
        );
        $this->assertSame(
            "That '/budget' line was not understood, so this task's budget was not changed. Use the form '/budget steps=50 spend=2.00' (either part may be left out).",
            $t->directiveMalformed()
        );
        $this->assertSame(
            "This task's budget ceiling was reached and nobody could be asked, so the task stopped. The work above is kept.",
            $t->halted()
        );
        $this->assertSame("Concluded at this task's budget ceiling (accepted)", $t->concludedReason());
        $this->assertSame('Task budget ceiling reached', $t->ceilingReachedReason());
    }

    #[Test]
    public function a_managed_shortfall_with_a_standing_states_the_figures(): void
    {
        $this->assertSame(
            "This task's budget ceiling was reached before this part could be completed. This task's budget: 20 of 20 steps, 0.41 of 1.00 USD.",
            $this->text->managedShortfall($this->standing(['stepsUsed' => 20]))
        );
    }

    #[Test]
    public function decision_guidance_names_the_accepted_replies_and_the_specific_problem(): void
    {
        $base = "This task's budget prompt accepts: 1 or 'continue' (optionally 'steps=N' and/or 'spend=X' for the additional allowance), 2 or 'accept', 3 or 'stop'. To start something new, accept or stop this task first.";
        $t = $this->text;

        $this->assertSame($base, $t->decisionGuidance());
        $this->assertSame($base." This reply was not accepted: 'maybe' is not one of the choices.", $t->decisionGuidance(TaskBudgetText::PROBLEM_UNRECOGNISED, ['reply' => 'maybe']));
        $this->assertSame($base.' This reply was not accepted: the reply was empty.', $t->decisionGuidance(TaskBudgetText::PROBLEM_UNRECOGNISED, ['reply' => '']));
        $this->assertSame($base." This reply was not accepted: an additional allowance can only be given with 'continue'.", $t->decisionGuidance(TaskBudgetText::PROBLEM_ALLOWANCE_WITHOUT_CONTINUE));
        $this->assertSame($base." This reply was not accepted: 'steps' was given more than once.", $t->decisionGuidance(TaskBudgetText::PROBLEM_REPEATED_KEY, ['key' => 'steps']));
        $this->assertSame($base.' This reply was not accepted: steps=1.5 is not a whole number of at least 1.', $t->decisionGuidance(TaskBudgetText::PROBLEM_INVALID_STEPS, ['value' => '1.5']));
        $this->assertSame($base.' This reply was not accepted: spend=abc is not an amount greater than 0.', $t->decisionGuidance(TaskBudgetText::PROBLEM_INVALID_SPEND, ['value' => 'abc']));
        $this->assertSame($base.' This reply was not accepted: steps=5 would not allow another step: 20 already used.', $t->decisionGuidance(TaskBudgetText::PROBLEM_NO_ROOM_STEPS, ['given' => 5, 'used' => 20]));
        $this->assertSame($base.' This reply was not accepted: spend=0.10 would not allow another step: 1.20 USD already spent.', $t->decisionGuidance(TaskBudgetText::PROBLEM_NO_ROOM_SPEND, ['given' => '0.10', 'used' => '1.2000000000', 'currency' => 'USD']));
    }

    #[Test]
    public function an_unknown_problem_code_is_a_programming_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->text->decisionGuidance('no_such_problem');
    }

    #[Test]
    public function reduced_to_maximum_names_each_reduced_ceiling(): void
    {
        $this->assertSame(
            "The ceiling you stated for this task's budget (1000 steps) was reduced to the installation maximum (200 steps).",
            $this->text->reducedToMaximum(1000, 200, null, null, 'USD')
        );
        $this->assertSame(
            "The ceiling you stated for this task's budget (50.00 USD) was reduced to the installation maximum (25.00 USD).",
            $this->text->reducedToMaximum(null, null, '50.00', '25.0000000000', 'USD')
        );
        $this->assertSame(
            "The ceiling you stated for this task's budget (1000 steps, 50.00 USD) was reduced to the installation maximum (200 steps, 25.00 USD).",
            $this->text->reducedToMaximum(1000, 200, '50.00', '25.0000000000', 'USD')
        );
    }

    #[Test]
    public function budget_updated_states_the_new_figures(): void
    {
        $standing = $this->standing(['maxSteps' => 40, 'stepsOrigin' => CeilingOrigin::User]);

        $this->assertSame("This task's budget was updated: 16 of 40 steps, 0.41 of 1.00 USD.", $this->text->budgetUpdated($standing));
        $this->assertSame(
            "This task's budget was updated: 16 of 40 steps, 0.41 of 1.00 USD. The ceiling you stated for this task's budget (1000 steps) was reduced to the installation maximum (200 steps).",
            $this->text->budgetUpdated($standing, $this->text->reducedToMaximum(1000, 200, null, null, 'USD'))
        );
        $this->assertSame(
            "This task's budget was updated: 16 of 40 steps, 0.41 of 1.00 USD (incomplete: 2 requests had no price).",
            $this->text->budgetUpdated($this->standing(['maxSteps' => 40, 'unpricedRequestCount' => 2]))
        );
    }

    #[Test]
    public function report_headlines_name_each_reached_ceiling(): void
    {
        $t = $this->text;

        $this->assertSame("This task's budget ceiling was reached: steps (20 of 20).", $t->reportHeadline($this->standing(['stepsUsed' => 20])));
        $this->assertSame(
            "This task's budget ceiling was reached: spend (1.06 of 1.00 USD — 0.06 over, from the step that was already running).",
            $t->reportHeadline($this->standing(['spendUsed' => '1.0600000000']))
        );
        $this->assertSame(
            "This task's budget ceiling was reached: spend (1.00 of 1.00 USD).",
            $t->reportHeadline($this->standing(['spendUsed' => '1.0000000000'])),
            'no overshoot clause when spend landed exactly on the ceiling'
        );
        $this->assertSame(
            "This task's budget ceiling was reached: steps (20 of 20) and spend (1.06 of 1.00 USD — 0.06 over, from the step that was already running).",
            $t->reportHeadline($this->standing(['stepsUsed' => 20, 'spendUsed' => '1.0600000000']))
        );
        $this->assertSame(
            "This task's budget ceiling was reached: the ceiling was lowered to 10 while 12 steps were already used.",
            $t->reportHeadline($this->standing(['stepsUsed' => 12, 'maxSteps' => 10]))
        );
        $this->assertSame($t->unavailable(), $t->reportHeadline($this->standing()), 'nothing reached: the budget could not be checked');
    }

    #[Test]
    public function the_report_sections(): void
    {
        $t = $this->text;

        $this->assertSame(
            "Used under this task's budget: 20 of 20 steps, 0.41 of 1.00 USD.",
            $t->reportConsumption($this->standing(['stepsUsed' => 20]))
        );
        $this->assertSame(
            "Used under this task's budget: 16 of 20 steps, 0.41 of 1.00 USD. Spend is approximate (some usage was estimated).",
            $t->reportConsumption($this->standing(['approximate' => true]))
        );
        $this->assertSame(
            "Used under this task's budget: 16 of 20 steps, 0.41 of 1.00 USD. Spend is incomplete (1 request had no configured price).",
            $t->reportConsumption($this->standing(['unpricedRequestCount' => 1]))
        );
        $this->assertSame(
            "Used under this task's budget: 16 of 20 steps, 0.41 of 1.00 USD. Spend is approximate (some usage was estimated). Spend is incomplete (3 requests had no configured price).",
            $t->reportConsumption($this->standing(['approximate' => true, 'unpricedRequestCount' => 3]))
        );
        $this->assertSame("Nothing was completed under this task's budget yet.", $t->reportDone([]));
        $this->assertSame(
            "Completed under this task's budget: execute_operation (3), list_applications (1).",
            $t->reportDone(['execute_operation' => 3, 'list_applications' => 1])
        );
        $this->assertSame(
            "Latest progress under this task's budget: Two of the three files are converted.",
            $t->reportLatestProgress('Two of the three files are converted.')
        );
        $this->assertSame(
            "Anything the agent had not started yet under this task's budget is not recorded.",
            $t->reportNothingOutstanding()
        );
        $this->assertSame(
            "This task's budget needs your decision: continue with more allowance, accept what has been done, or stop.",
            $t->reportNext('paused')
        );
        $this->assertSame(
            "This task is closed at this task's budget ceiling; its completed work above is the result.",
            $t->reportNext('accepted')
        );
        $stopped = "Work has stopped at this task's budget ceiling. Reply 'continue' to resume with more allowance or 'accept' to close the task with what has been done.";
        $this->assertSame($stopped, $t->reportNext('stopped'));
        $this->assertSame($stopped, $t->reportNext('expired'));
        $this->assertSame(
            "Nobody could be asked about this task's budget, so the task stopped. The work above is kept.",
            $t->reportNext('halted')
        );
    }

    #[Test]
    public function an_unknown_report_occasion_is_a_programming_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->text->reportNext('finished');
    }

    #[Test]
    public function task_budget_text_needs_no_container(): void
    {
        // Constructed directly above, with no arguments, outside any
        // application: the value objects can render their own label.
        $this->assertInstanceOf(TaskBudgetText::class, new TaskBudgetText());
    }
}
