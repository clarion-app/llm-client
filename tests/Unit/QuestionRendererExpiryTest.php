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
