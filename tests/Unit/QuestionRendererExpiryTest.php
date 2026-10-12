<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Services\QuestionPresentationPlanner;
use ClarionApp\LlmClient\Services\QuestionRenderer;
use ClarionApp\LlmClient\ValueObjects\ChannelQuestionCapabilities;
use ClarionApp\LlmClient\ValueObjects\PresentationMode;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\QuestionChannels\RecordingPresentingDelivery;
use Tests\TestCase;

/**
 * What a question says, in advance, about what happens if nobody answers it
 * — and the notices and outcome lines once a question that stops the work
 * has stopped, been answered late, or been discarded.
 *
 * The advance statement is the last block of every rendering of a question
 * asked with a policy, so the stored display text, every plain-text
 * delivery and the rich body all carry it. A question asked before policies
 * existed renders exactly as it always did. Deadlines are always shown in
 * UTC, whatever the application's timezone.
 */
class QuestionRendererExpiryTest extends TestCase
{
    private const QUESTION = 'Which record should I keep?';

    private const STOP_STATEMENT_60 = 'If there is no answer by 13:00 UTC on 12 Oct 2026 (1 hour after this was asked), '
        .'work on this task will pause until you reply.';

    private const CONSEQUENTIAL_STATEMENT_60 = 'Work on this task will not continue without your answer. '
        .'If there is no answer by 13:00 UTC on 12 Oct 2026 (1 hour after this was asked), it will pause until you reply.';

    /** The plain-text question exactly as it rendered before policies existed. */
    private const LEGACY_PLAIN = "Which record should I keep?\n"
        ."\n"
        ."1. Record A\n"
        ."2. Record B\n"
        ."\n"
        .'Reply with the number of your choice, or answer in your own words.';

    private const LEGACY_WITH_OPTIONS = "Which record should I keep?\n"
        ."\n"
        ."1. Record A\n"
        ."2. Record B\n"
        ."\n"
        .'Choose an option below, or reply in your own words.';

    private string $defaultTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->defaultTimezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->defaultTimezone);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function renderer(): QuestionRenderer
    {
        return new QuestionRenderer();
    }

    /**
     * A question asked at 12:00 UTC on 12 Oct 2026 with the given policy
     * columns. The deadline is the ask time plus the effective period unless
     * $extra says otherwise.
     */
    private function question(?string $policy = 'stop', int $minutes = 60, array $extra = []): AgentQuestion
    {
        $askedAt = Carbon::parse('2026-10-12 12:00:00', 'UTC');

        return (new AgentQuestion())->forceFill($extra + [
            'id' => '6f1c2a40-0000-4000-8000-0000000000e1',
            'question' => self::QUESTION,
            'choices' => ['Record A', 'Record B'],
            'allow_multiple' => false,
            'state' => 'pending',
            'unanswered_policy' => $policy,
            'consequential' => false,
            'requested_wait_minutes' => $policy === null ? null : $minutes,
            'effective_wait_minutes' => $policy === null ? null : $minutes,
            'asked_at' => $askedAt,
            'expires_at' => $askedAt->copy()->addMinutes($minutes),
        ]);
    }

    // ---------------------------------------------------------------
    // The advance statement
    // ---------------------------------------------------------------

    #[Test]
    public function a_question_that_stops_says_work_will_pause_until_the_user_replies(): void
    {
        $this->assertSame(self::STOP_STATEMENT_60, $this->renderer()->expiryStatement($this->question()));
    }

    #[Test]
    public function a_consequential_question_says_work_will_not_continue_without_an_answer(): void
    {
        $question = $this->question(extra: ['consequential' => true]);

        $this->assertSame(self::CONSEQUENTIAL_STATEMENT_60, $this->renderer()->expiryStatement($question));
    }

    #[Test]
    public function a_question_asked_before_policies_existed_has_no_statement_and_renders_as_it_always_did(): void
    {
        $legacy = $this->question(policy: null);

        $this->assertNull($this->renderer()->expiryStatement($legacy));
        $this->assertSame(self::LEGACY_PLAIN, $this->renderer()->render($legacy));
        $this->assertSame(self::LEGACY_WITH_OPTIONS, $this->renderer()->renderWithOptions($legacy));
    }

    #[Test]
    public function the_statement_is_the_last_block_of_the_plain_text_question(): void
    {
        $this->assertSame(
            self::LEGACY_PLAIN."\n\n".self::STOP_STATEMENT_60,
            $this->renderer()->render($this->question()),
        );
    }

    #[Test]
    public function the_statement_is_the_last_block_of_the_question_shown_with_options(): void
    {
        $this->assertSame(
            self::LEGACY_WITH_OPTIONS."\n\n".self::STOP_STATEMENT_60,
            $this->renderer()->renderWithOptions($this->question()),
        );
    }

    #[Test]
    public function the_statement_follows_a_preamble_and_a_free_text_question_too(): void
    {
        $question = $this->question(extra: ['choices' => []]);

        $this->assertSame(
            "I need one decision first.\n\nWhich record should I keep?\n\nReply with your answer.\n\n".self::STOP_STATEMENT_60,
            $this->renderer()->render($question, 'I need one decision first.'),
        );
    }

    #[Test]
    public function the_consequential_statement_ends_both_renderings(): void
    {
        $question = $this->question(extra: ['consequential' => true]);

        $this->assertSame(self::LEGACY_PLAIN."\n\n".self::CONSEQUENTIAL_STATEMENT_60, $this->renderer()->render($question));
        $this->assertSame(self::LEGACY_WITH_OPTIONS."\n\n".self::CONSEQUENTIAL_STATEMENT_60, $this->renderer()->renderWithOptions($question));
    }

    #[Test]
    public function the_rich_body_and_its_plain_text_fallback_both_carry_the_statement(): void
    {
        $plan = app(QuestionPresentationPlanner::class)->plan(
            $this->question(),
            new RecordingPresentingDelivery('telegram', ChannelQuestionCapabilities::unlimited()),
            null,
        );

        $this->assertSame(PresentationMode::Rich, $plan->mode);
        $this->assertSame(self::LEGACY_WITH_OPTIONS."\n\n".self::STOP_STATEMENT_60, $plan->presentation->bodyText);
        $this->assertSame(self::LEGACY_PLAIN."\n\n".self::STOP_STATEMENT_60, $plan->presentation->plainText);
    }

    // ---------------------------------------------------------------
    // The deadline
    // ---------------------------------------------------------------

    /** @return array<string, array{int, string}> */
    public static function periods(): array
    {
        return [
            '1 minute' => [1, '1 minute'],
            '2 minutes' => [2, '2 minutes'],
            '59 minutes' => [59, '59 minutes'],
            '60 minutes' => [60, '1 hour'],
            '61 minutes' => [61, '1 hour 1 minute'],
            '62 minutes' => [62, '1 hour 2 minutes'],
            '90 minutes' => [90, '1 hour 30 minutes'],
            '120 minutes' => [120, '2 hours'],
            '121 minutes' => [121, '2 hours 1 minute'],
            '150 minutes' => [150, '2 hours 30 minutes'],
            '1440 minutes' => [1440, '1 day'],
            '1500 minutes' => [1500, '25 hours'],
            '2880 minutes' => [2880, '2 days'],
            '10080 minutes' => [10080, '7 days'],
        ];
    }

    #[Test]
    #[DataProvider('periods')]
    public function the_deadline_names_the_time_in_utc_and_the_period_in_words(int $minutes, string $words): void
    {
        $deadline = Carbon::parse('2026-10-12 12:00:00', 'UTC')->addMinutes($minutes);

        $this->assertSame(
            $deadline->format('H:i').' UTC on '.$deadline->format('j M Y')." ({$words} after this was asked)",
            $this->renderer()->deadlineText($this->question(minutes: $minutes)),
        );
    }

    #[Test]
    public function the_deadline_of_a_day_long_wait_reads_as_a_day(): void
    {
        $this->assertSame(
            '12:00 UTC on 13 Oct 2026 (1 day after this was asked)',
            $this->renderer()->deadlineText($this->question(minutes: 1440)),
        );
    }

    #[Test]
    public function the_period_is_the_one_fixed_when_the_question_was_asked(): void
    {
        // The deadline is the stored one; the words come from the stored
        // effective period, never from a clock or a configuration read now.
        Carbon::setTestNow(Carbon::parse('2026-11-30 08:00:00', 'UTC'));
        config(['llm-client.agent_questions.expiry_minutes' => 5]);

        $this->assertSame(
            '14:30 UTC on 12 Oct 2026 (2 hours 30 minutes after this was asked)',
            $this->renderer()->deadlineText($this->question(minutes: 150)),
        );
    }

    #[Test]
    public function without_a_stored_period_the_words_come_from_the_ask_and_deadline_times(): void
    {
        $legacy = $this->question(policy: null, minutes: 90);

        $this->assertSame(
            '13:30 UTC on 12 Oct 2026 (1 hour 30 minutes after this was asked)',
            $this->renderer()->deadlineText($legacy),
        );
    }

    #[Test]
    public function the_deadline_is_shown_in_utc_whatever_the_application_timezone(): void
    {
        // Stored and read back in the application's own timezone, as every
        // timestamp is: 22:30 in New York on 13 Oct is 02:30 UTC on 14 Oct.
        config(['app.timezone' => 'America/New_York']);
        date_default_timezone_set('America/New_York');

        $askedAt = Carbon::parse('2026-10-13 21:30:00', 'America/New_York');
        $question = $this->question(extra: [
            'asked_at' => $askedAt,
            'expires_at' => $askedAt->copy()->addMinutes(60),
        ]);

        $this->assertSame('02:30 UTC on 14 Oct 2026 (1 hour after this was asked)', $this->renderer()->deadlineText($question));
        $this->assertSame(
            'If there is no answer by 02:30 UTC on 14 Oct 2026 (1 hour after this was asked), work on this task will pause until you reply.',
            $this->renderer()->expiryStatement($question),
        );
    }

    // ---------------------------------------------------------------
    // Notices
    // ---------------------------------------------------------------

    #[Test]
    public function the_stop_notice_names_the_question_and_its_deadline_and_says_how_to_resume(): void
    {
        $stopped = $this->question(extra: ['state' => 'stopped', 'stopped_at' => Carbon::parse('2026-10-12 13:00:20', 'UTC')]);

        $this->assertSame(
            'No answer to "Which record should I keep?" was received by the deadline '
            .'(13:00 UTC on 12 Oct 2026 (1 hour after this was asked)). '
            .'Work on this task has stopped. It will continue when you answer the question above.',
            $this->renderer()->renderNotice($stopped),
        );
    }

    #[Test]
    public function the_discard_notice_says_the_stopped_task_no_longer_needs_a_reply(): void
    {
        $discarded = $this->question(extra: [
            'state' => 'withdrawn',
            'stopped_at' => Carbon::parse('2026-10-12 13:00:20', 'UTC'),
        ]);

        $this->assertSame(
            'The stopped task waiting on "Which record should I keep?" was discarded because the conversation was ended or deleted. '
            .'It no longer needs a reply.',
            $this->renderer()->renderNotice($discarded),
        );
    }

    #[Test]
    public function a_question_withdrawn_while_still_waiting_keeps_its_withdrawal_notice(): void
    {
        $withdrawn = $this->question(extra: ['state' => 'withdrawn']);

        $this->assertSame(
            'The question "Which record should I keep?" was withdrawn because the task was stopped. It no longer needs a reply.',
            $this->renderer()->renderNotice($withdrawn),
        );
    }

    // ---------------------------------------------------------------
    // Outcome lines
    // ---------------------------------------------------------------

    #[Test]
    public function a_stopped_question_reads_as_answerable_to_resume(): void
    {
        $stopped = $this->question(extra: ['state' => 'stopped', 'stopped_at' => Carbon::parse('2026-10-12 13:00:20', 'UTC')]);

        $this->assertSame('Expired — work stopped. Answer to resume.', $this->renderer()->renderOutcome($stopped));
    }

    #[Test]
    public function a_late_selection_names_every_chosen_choice_in_full(): void
    {
        $answered = $this->question(extra: [
            'state' => 'answered',
            'stopped_at' => Carbon::parse('2026-10-12 13:00:20', 'UTC'),
            'answer_raw' => '2',
            'answer_selected' => [['number' => 2, 'text' => 'Record B']],
        ]);

        $this->assertSame('Answered after work stopped: 2. Record B', $this->renderer()->renderOutcome($answered));
    }

    #[Test]
    public function a_late_multi_selection_lists_its_choices_separated_by_commas(): void
    {
        $answered = $this->question(extra: [
            'state' => 'answered',
            'allow_multiple' => true,
            'stopped_at' => Carbon::parse('2026-10-12 13:00:20', 'UTC'),
            'answer_raw' => '1 2',
            'answer_selected' => [['number' => 1, 'text' => 'Record A'], ['number' => 2, 'text' => 'Record B']],
        ]);

        $this->assertSame('Answered after work stopped: 1. Record A, 2. Record B', $this->renderer()->renderOutcome($answered));
    }

    #[Test]
    public function a_late_free_text_answer_is_not_repeated(): void
    {
        $answered = $this->question(extra: [
            'state' => 'answered',
            'stopped_at' => Carbon::parse('2026-10-12 13:00:20', 'UTC'),
            'answer_raw' => 'keep neither',
            'answer_selected' => [],
        ]);

        $this->assertSame('Answered in your own words after work stopped.', $this->renderer()->renderOutcome($answered));
    }

    #[Test]
    public function a_discarded_stopped_task_reads_as_discarded(): void
    {
        $discarded = $this->question(extra: [
            'state' => 'withdrawn',
            'stopped_at' => Carbon::parse('2026-10-12 13:00:20', 'UTC'),
        ]);

        $this->assertSame(
            'Discarded — the conversation was ended or deleted while work was stopped.',
            $this->renderer()->renderOutcome($discarded),
        );
    }

    // ---------------------------------------------------------------
    // Proceeding on an assumption
    // ---------------------------------------------------------------

    private const FRAMEWORK_QUESTION = 'Which test framework should I set up?';

    /** The plain-text framework question exactly as it renders without a statement. */
    private const FRAMEWORK_PLAIN = "Which test framework should I set up?\n"
        ."\n"
        ."1. PHPUnit\n"
        ."2. Pest\n"
        ."\n"
        .'Reply with the number of your choice, or answer in your own words.';

    private const FRAMEWORK_WITH_OPTIONS = "Which test framework should I set up?\n"
        ."\n"
        ."1. PHPUnit\n"
        ."2. Pest\n"
        ."\n"
        .'Choose an option below, or reply in your own words.';

    private const PROCEED_STATEMENT_60 = 'If there is no answer by 13:00 UTC on 12 Oct 2026 (1 hour after this was asked), '
        .'the agent will continue assuming: 1. PHPUnit';

    /**
     * A question that proceeds on an assumption, asked at 12:00 UTC on
     * 12 Oct 2026 with a one-hour period. By default it assumes choice 1.
     */
    private function proceedQuestion(array $extra = []): AgentQuestion
    {
        return $this->question('proceed_on_assumption', 60, $extra + [
            'question' => self::FRAMEWORK_QUESTION,
            'choices' => ['PHPUnit', 'Pest'],
            'assumed_answer_raw' => '1',
            'assumed_answer_selected' => [['number' => 1, 'text' => 'PHPUnit']],
        ]);
    }

    #[Test]
    public function the_assumption_of_a_single_choice_is_its_full_label(): void
    {
        $this->assertSame('1. PHPUnit', $this->renderer()->assumptionText($this->proceedQuestion()));
    }

    #[Test]
    public function the_assumption_of_several_choices_lists_each_full_label_separated_by_commas(): void
    {
        $question = $this->proceedQuestion([
            'question' => 'Which parts should I change?',
            'choices' => ['Backend', 'Frontend', 'Docs'],
            'allow_multiple' => true,
            'assumed_answer_raw' => '1, 3',
            'assumed_answer_selected' => [['number' => 1, 'text' => 'Backend'], ['number' => 3, 'text' => 'Docs']],
        ]);

        $this->assertSame('1. Backend, 3. Docs', $this->renderer()->assumptionText($question));
    }

    #[Test]
    public function an_assumption_in_the_agents_own_words_is_quoted_in_full(): void
    {
        $long = 'use Pest, with the Laravel plugin, parallel runs on, coverage reported per file and the slowest ten tests listed';
        $question = $this->proceedQuestion(['assumed_answer_raw' => $long, 'assumed_answer_selected' => []]);

        $this->assertSame('"'.$long.'"', $this->renderer()->assumptionText($question), 'quoted, never shortened');

        $short = $this->proceedQuestion(['assumed_answer_raw' => 'use Pest', 'assumed_answer_selected' => []]);
        $this->assertSame('"use Pest"', $this->renderer()->assumptionText($short));
    }

    #[Test]
    public function a_question_that_assumes_nothing_has_no_assumption_text(): void
    {
        $this->assertNull($this->renderer()->assumptionText($this->question()), 'a stop question assumes nothing');
        $this->assertNull($this->renderer()->assumptionText($this->question(policy: null)), 'nor does a legacy question');
    }

    #[Test]
    public function a_question_that_proceeds_says_what_the_agent_will_assume_and_by_when(): void
    {
        $this->assertSame(self::PROCEED_STATEMENT_60, $this->renderer()->expiryStatement($this->proceedQuestion()));
    }

    #[Test]
    public function the_proceed_statement_quotes_an_assumption_in_the_agents_own_words(): void
    {
        $question = $this->proceedQuestion(['assumed_answer_raw' => 'use Pest', 'assumed_answer_selected' => []]);

        $this->assertSame(
            'If there is no answer by 13:00 UTC on 12 Oct 2026 (1 hour after this was asked), the agent will continue assuming: "use Pest"',
            $this->renderer()->expiryStatement($question),
        );
    }

    #[Test]
    public function the_proceed_statement_is_the_last_block_of_both_renderings(): void
    {
        $question = $this->proceedQuestion();

        $this->assertSame(self::FRAMEWORK_PLAIN."\n\n".self::PROCEED_STATEMENT_60, $this->renderer()->render($question));
        $this->assertSame(self::FRAMEWORK_WITH_OPTIONS."\n\n".self::PROCEED_STATEMENT_60, $this->renderer()->renderWithOptions($question));
        $this->assertSame(
            "Before I start:\n\n".self::FRAMEWORK_PLAIN."\n\n".self::PROCEED_STATEMENT_60,
            $this->renderer()->render($question, 'Before I start:'),
        );
    }

    #[Test]
    public function the_rich_body_and_every_plain_text_presentation_carry_the_proceed_statement(): void
    {
        $question = $this->proceedQuestion();
        $planner = app(QuestionPresentationPlanner::class);

        // Rich: the body shown with the buttons, and its plain fallback (sent
        // when the channel rejects the rich message).
        $rich = $planner->plan($question, new RecordingPresentingDelivery('telegram', ChannelQuestionCapabilities::unlimited()), null);
        $this->assertSame(PresentationMode::Rich, $rich->mode);
        $this->assertSame(self::FRAMEWORK_WITH_OPTIONS."\n\n".self::PROCEED_STATEMENT_60, $rich->presentation->bodyText);
        $this->assertSame(self::FRAMEWORK_PLAIN."\n\n".self::PROCEED_STATEMENT_60, $rich->presentation->plainText);

        // A channel that cannot show options, and one that cannot show this
        // many, receive the plain-text question, which is render()'s.
        foreach ([ChannelQuestionCapabilities::none(), new ChannelQuestionCapabilities(true, 1)] as $capabilities) {
            $plain = $planner->plan($question, new RecordingPresentingDelivery('telegram', $capabilities), null);
            $this->assertSame(PresentationMode::PlainText, $plain->mode, 'precondition: this channel degrades to plain text');
        }
        $this->assertStringEndsWith("\n\n".self::PROCEED_STATEMENT_60, $this->renderer()->render($question));
    }

    #[Test]
    public function an_assumed_label_longer_than_a_buttons_limit_is_still_stated_in_full(): void
    {
        $long = 'PHPUnit with the strict configuration, random order and every deprecation reported';
        $question = $this->proceedQuestion([
            'choices' => [$long, 'Pest'],
            'assumed_answer_selected' => [['number' => 1, 'text' => $long]],
        ]);

        $plan = app(QuestionPresentationPlanner::class)->plan(
            $question,
            new RecordingPresentingDelivery('telegram', new ChannelQuestionCapabilities(true, null, 30)),
            null,
        );

        $this->assertSame(PresentationMode::Rich, $plan->mode);
        $this->assertSame(30, mb_strlen($plan->presentation->options[0]->label), 'precondition: the button label is shortened');
        $this->assertStringEndsWith('the agent will continue assuming: 1. '.$long, $plan->presentation->bodyText, 'the statement names the whole assumption');
        $this->assertStringEndsWith('the agent will continue assuming: 1. '.$long, $plan->presentation->plainText);
    }

    #[Test]
    public function the_assumed_notice_names_the_question_its_deadline_and_the_assumption(): void
    {
        $expired = $this->proceedQuestion(['state' => 'expired_assumed']);

        $this->assertSame(
            'No answer to "Which test framework should I set up?" was received by the deadline '
            .'(13:00 UTC on 12 Oct 2026 (1 hour after this was asked)). '
            .'The agent is continuing on the assumption it stated: 1. PHPUnit. '
            .'Replies sent now are treated as new messages and will not change that assumption.',
            $this->renderer()->renderNotice($expired),
        );
    }

    #[Test]
    public function the_assumed_outcome_line_names_the_assumption(): void
    {
        $this->assertSame(
            'Expired — no answer arrived in time; the agent continued assuming: 1. PHPUnit',
            $this->renderer()->renderOutcome($this->proceedQuestion(['state' => 'expired_assumed'])),
        );

        $freeText = $this->proceedQuestion(['state' => 'expired_assumed', 'assumed_answer_raw' => 'use Pest', 'assumed_answer_selected' => []]);
        $this->assertSame(
            'Expired — no answer arrived in time; the agent continued assuming: "use Pest"',
            $this->renderer()->renderOutcome($freeText),
        );
    }

    #[Test]
    public function a_late_typed_reply_is_told_what_was_assumed_and_that_it_is_a_new_message(): void
    {
        $choice = $this->proceedQuestion(['state' => 'expired_assumed']);
        $ownWords = $this->proceedQuestion([
            'state' => 'expired_assumed',
            'assumed_answer_raw' => 'whichever is already installed',
            'assumed_answer_selected' => [],
        ]);
        $several = $this->proceedQuestion([
            'state' => 'expired_assumed',
            'question' => 'Which parts should I change?',
            'choices' => ['Backend', 'Frontend', 'Docs'],
            'allow_multiple' => true,
            'assumed_answer_raw' => '1, 3',
            'assumed_answer_selected' => [['number' => 1, 'text' => 'Backend'], ['number' => 3, 'text' => 'Docs']],
        ]);

        $this->assertSame(
            'Your reply arrived after the question "'.self::FRAMEWORK_QUESTION.'" had expired. '
            .'The agent had already continued assuming: 1. PHPUnit. Your reply is being handled as a new message.',
            $this->renderer()->renderLateReplyNotice($choice),
        );
        $this->assertSame(
            'Your reply arrived after the question "'.self::FRAMEWORK_QUESTION.'" had expired. '
            .'The agent had already continued assuming: "whichever is already installed". Your reply is being handled as a new message.',
            $this->renderer()->renderLateReplyNotice($ownWords),
            'an assumption in the agent\'s own words is quoted in full',
        );
        $this->assertSame(
            'Your reply arrived after the question "Which parts should I change?" had expired. '
            .'The agent had already continued assuming: 1. Backend, 3. Docs. Your reply is being handled as a new message.',
            $this->renderer()->renderLateReplyNotice($several),
            'every assumed choice by its full label',
        );
    }

    #[Test]
    public function the_legacy_expiry_notice_and_outcome_are_unchanged(): void
    {
        $legacy = $this->question(policy: null, extra: ['state' => 'expired']);

        $this->assertSame(
            'The question "Which record should I keep?" expired without an answer. '
            .'The agent will continue without it; replies sent now are treated as new messages.',
            $this->renderer()->renderNotice($legacy),
        );
        $this->assertSame('This question expired and is no longer awaiting a reply.', $this->renderer()->renderOutcome($legacy));
    }

    #[Test]
    public function questions_answered_or_withdrawn_without_stopping_keep_their_outcome_lines(): void
    {
        $answered = $this->question(extra: [
            'state' => 'answered',
            'answer_raw' => '2',
            'answer_selected' => [['number' => 2, 'text' => 'Record B']],
        ]);
        $withdrawn = $this->question(extra: ['state' => 'withdrawn']);

        $this->assertSame('Answered: 2. Record B', $this->renderer()->renderOutcome($answered));
        $this->assertSame('This question was withdrawn and is no longer awaiting a reply.', $this->renderer()->renderOutcome($withdrawn));
    }
}
