<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Services\AgentQuestionValidator;
use ClarionApp\LlmClient\ValueObjects\AskUserRequest;
use ClarionApp\LlmClient\ValueObjects\ParsedAnswer;
use ClarionApp\LlmClient\ValueObjects\QuestionResolution;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The `ask_user` tool result the agent is resumed with, for a question whose
 * work stopped at its deadline and was answered later: it says the work
 * stopped, how long the question waited, when it stopped and when it was
 * answered, and carries the answer exactly as an on-time answer does.
 *
 * Every other result — an on-time answer, an expiry under the original
 * rules, a withdrawal (whether or not the work had stopped first) and an
 * undeliverable question — is byte-for-byte what it has always been. A
 * question that is still waiting, or stopped, has no result at all.
 */
class QuestionResolutionPolicyTest extends TestCase
{
    private const ID = '6f1c2a40-0000-4000-8000-0000000000f1';

    private const LATE_MESSAGE = 'The question expired without an answer after 60 minutes and work stopped. '
        .'The user answered later; continue with this answer. Nothing was done while the work was stopped.';

    private string $defaultTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->defaultTimezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->defaultTimezone);
        parent::tearDown();
    }

    private function question(array $extra = []): AgentQuestion
    {
        $askedAt = Carbon::parse('2026-10-13 13:00:00', 'UTC');

        return (new AgentQuestion())->forceFill($extra + [
            'id' => self::ID,
            'question' => 'Which record should I keep?',
            'choices' => ['Record A', 'Record B'],
            'allow_multiple' => false,
            'unanswered_policy' => 'stop',
            'consequential' => false,
            'requested_wait_minutes' => 60,
            'effective_wait_minutes' => 60,
            'asked_at' => $askedAt,
            'expires_at' => $askedAt->copy()->addMinutes(60),
        ]);
    }

    private function answeredLate(array $extra = []): AgentQuestion
    {
        return $this->question($extra + [
            'state' => 'answered',
            'stopped_at' => Carbon::parse('2026-10-13 14:00:03.456789', 'UTC'),
            'resolved_at' => Carbon::parse('2026-10-15 09:12:44', 'UTC'),
            'answer_raw' => '2',
            'answer_selected' => [['number' => 2, 'text' => 'Record B']],
        ]);
    }

    private function selectionOfB(): ParsedAnswer
    {
        return new ParsedAnswer('2', true, [['number' => 2, 'text' => 'Record B']]);
    }

    // ---------------------------------------------------------------
    // Answered after the work stopped
    // ---------------------------------------------------------------

    #[Test]
    public function an_answer_after_the_work_stopped_is_reported_as_late(): void
    {
        $result = (new QuestionResolution(QuestionState::Answered, $this->selectionOfB()))->toToolResult($this->answeredLate());

        $this->assertSame(
            '{"outcome":"answered_late","question_id":"'.self::ID.'","waiting_period_minutes":60,'
            .'"stopped_at":"2026-10-13T14:00:03Z","answered_at":"2026-10-15T09:12:44Z",'
            .'"answer":{"raw_reply":"2","is_selection":true,"selected":[{"number":2,"text":"Record B"}]},'
            .'"message":"'.self::LATE_MESSAGE.'"}',
            $result,
        );
    }

    #[Test]
    public function a_late_answer_in_the_users_own_words_carries_the_text(): void
    {
        $question = $this->answeredLate(['answer_raw' => 'keep both', 'answer_selected' => []]);

        $decoded = json_decode(
            (new QuestionResolution(QuestionState::Answered, new ParsedAnswer('keep both', false, [])))->toToolResult($question),
            true,
        );

        $this->assertSame(
            ['outcome', 'question_id', 'waiting_period_minutes', 'stopped_at', 'answered_at', 'answer', 'message'],
            array_keys($decoded),
        );
        $this->assertSame('answered_late', $decoded['outcome']);
        $this->assertSame(['raw_reply' => 'keep both', 'is_selection' => false, 'selected' => []], $decoded['answer']);
    }

    #[Test]
    public function the_late_result_states_the_period_fixed_when_the_question_was_asked(): void
    {
        // The stored effective period, not the gap between the stored
        // timestamps, and not any configuration read now.
        config(['llm-client.agent_questions.expiry_minutes' => 5]);
        $question = $this->answeredLate([
            'effective_wait_minutes' => 600,
            'expires_at' => Carbon::parse('2026-10-13 23:00:00', 'UTC'),
        ]);

        $decoded = json_decode((new QuestionResolution(QuestionState::Answered, $this->selectionOfB()))->toToolResult($question), true);

        $this->assertSame(600, $decoded['waiting_period_minutes']);
        $this->assertSame(
            'The question expired without an answer after 600 minutes and work stopped. '
            .'The user answered later; continue with this answer. Nothing was done while the work was stopped.',
            $decoded['message'],
        );
    }

    #[Test]
    public function the_late_result_gives_its_times_in_utc_whatever_the_application_timezone(): void
    {
        config(['app.timezone' => 'America/New_York']);
        date_default_timezone_set('America/New_York');

        $question = $this->answeredLate([
            'stopped_at' => Carbon::parse('2026-10-13 10:00:03', 'America/New_York'),
            'resolved_at' => Carbon::parse('2026-10-15 05:12:44', 'America/New_York'),
        ]);

        $decoded = json_decode((new QuestionResolution(QuestionState::Answered, $this->selectionOfB()))->toToolResult($question), true);

        $this->assertSame('2026-10-13T14:00:03Z', $decoded['stopped_at']);
        $this->assertSame('2026-10-15T09:12:44Z', $decoded['answered_at']);
    }

    // ---------------------------------------------------------------
    // Proceeded on the stated assumption
    // ---------------------------------------------------------------

    private const ASSUMED_MESSAGE_1440 = 'No answer arrived within 1440 minutes. Continue using the answer you said you would assume. '
        .'This is your own assumption, not the user\'s answer: never treat it as the user\'s confirmation, approval or '
        .'authorization of any action. The user has been told what was assumed.';

    /** A proceed question that expired on its one-day deadline, assuming $raw / $selected. */
    private function expiredAssumed(string $raw = '1', array $selected = [['number' => 1, 'text' => 'Record A']], array $extra = []): AgentQuestion
    {
        $askedAt = Carbon::parse('2026-10-13 13:00:00', 'UTC');

        return $this->question($extra + [
            'state' => 'expired_assumed',
            'unanswered_policy' => 'proceed_on_assumption',
            'assumed_answer_raw' => $raw,
            'assumed_answer_selected' => $selected,
            'requested_wait_minutes' => null,
            'effective_wait_minutes' => 1440,
            'expires_at' => $askedAt->copy()->addMinutes(1440),
            'resolved_at' => $askedAt->copy()->addMinutes(1441),
        ]);
    }

    private function resultFor(AgentQuestion $question): string
    {
        // The resolution a resumed run is handed for an assumed expiry.
        return (new QuestionResolution(QuestionState::ExpiredAssumed, $question->assumedAnswer()))->toToolResult($question);
    }

    #[Test]
    public function an_expiry_on_an_assumed_choice_hands_the_agent_its_assumption_flagged_as_assumed(): void
    {
        $this->assertSame(
            '{"outcome":"expired_assumed","question_id":"'.self::ID.'","waiting_period_minutes":1440,'
            .'"answer_source":"assumed",'
            .'"assumed_answer":{"raw_reply":"1","is_selection":true,"selected":[{"number":1,"text":"Record A"}]},'
            .'"message":"'.self::ASSUMED_MESSAGE_1440.'"}',
            $this->resultFor($this->expiredAssumed()),
        );
    }

    #[Test]
    public function the_assumption_is_carried_under_its_own_key_never_as_an_answer(): void
    {
        $decoded = json_decode($this->resultFor($this->expiredAssumed()), true);

        $this->assertSame(
            ['outcome', 'question_id', 'waiting_period_minutes', 'answer_source', 'assumed_answer', 'message'],
            array_keys($decoded),
        );
        $this->assertArrayNotHasKey('answer', $decoded, 'an assumption is never reported as the user\'s answer');
        $this->assertSame('assumed', $decoded['answer_source']);
    }

    #[Test]
    public function an_assumption_in_the_agents_own_words_is_carried_as_free_text(): void
    {
        $decoded = json_decode($this->resultFor($this->expiredAssumed('keep both records', [])), true);

        $this->assertSame('expired_assumed', $decoded['outcome']);
        $this->assertSame(['raw_reply' => 'keep both records', 'is_selection' => false, 'selected' => []], $decoded['assumed_answer']);
        $this->assertSame(self::ASSUMED_MESSAGE_1440, $decoded['message']);
    }

    #[Test]
    public function an_assumed_multi_selection_carries_every_choice(): void
    {
        $question = $this->expiredAssumed('1, 2', [['number' => 1, 'text' => 'Record A'], ['number' => 2, 'text' => 'Record B']], ['allow_multiple' => true]);

        $decoded = json_decode($this->resultFor($question), true);

        $this->assertSame(
            ['raw_reply' => '1, 2', 'is_selection' => true, 'selected' => [['number' => 1, 'text' => 'Record A'], ['number' => 2, 'text' => 'Record B']]],
            $decoded['assumed_answer'],
        );
    }

    #[Test]
    public function an_assumed_number_typed_as_text_is_delivered_as_that_choice(): void
    {
        // The agent wrote the number as its own words; the assumption is
        // read exactly as the same typed reply would be, so the agent gets
        // back the selection of choice 2.
        $request = (new AgentQuestionValidator())->validate([
            'question' => 'Which record should I keep?',
            'choices' => ['Record A', 'Record B'],
            'assumed_text' => '2',
        ]);
        $this->assertInstanceOf(AskUserRequest::class, $request, 'precondition: the ask is accepted');

        $question = $this->expiredAssumed($request->assumedAnswer->rawReply, $request->assumedAnswer->selected);
        $decoded = json_decode($this->resultFor($question), true);

        $this->assertSame(
            ['raw_reply' => '2', 'is_selection' => true, 'selected' => [['number' => 2, 'text' => 'Record B']]],
            $decoded['assumed_answer'],
        );
    }

    #[Test]
    public function the_assumed_result_states_the_period_fixed_when_the_question_was_asked(): void
    {
        config(['llm-client.agent_questions.expiry_minutes' => 5]);
        $question = $this->expiredAssumed(extra: [
            'effective_wait_minutes' => 600,
            'expires_at' => Carbon::parse('2026-10-13 23:00:00', 'UTC'),
        ]);

        $decoded = json_decode($this->resultFor($question), true);

        $this->assertSame(600, $decoded['waiting_period_minutes']);
        $this->assertStringStartsWith('No answer arrived within 600 minutes. ', $decoded['message']);
    }

    // ---------------------------------------------------------------
    // Everything else is unchanged
    // ---------------------------------------------------------------

    #[Test]
    public function an_answer_given_in_time_is_reported_exactly_as_before(): void
    {
        $question = $this->question([
            'state' => 'answered',
            'resolved_at' => Carbon::parse('2026-10-13 13:20:00', 'UTC'),
            'answer_raw' => '2',
            'answer_selected' => [['number' => 2, 'text' => 'Record B']],
        ]);

        $this->assertSame(
            '{"outcome":"answered","question_id":"'.self::ID.'",'
            .'"answer":{"raw_reply":"2","is_selection":true,"selected":[{"number":2,"text":"Record B"}]}}',
            (new QuestionResolution(QuestionState::Answered, $this->selectionOfB()))->toToolResult($question),
        );
    }

    #[Test]
    public function an_expiry_under_the_original_rules_is_reported_exactly_as_before(): void
    {
        $askedAt = Carbon::parse('2026-10-13 13:00:00', 'UTC');
        $legacy = $this->question([
            'state' => 'expired',
            'unanswered_policy' => null,
            'requested_wait_minutes' => null,
            'effective_wait_minutes' => null,
            'asked_at' => $askedAt,
            'expires_at' => $askedAt->copy()->addMinutes(90),
            'resolved_at' => $askedAt->copy()->addMinutes(91),
        ]);

        $this->assertSame(
            '{"outcome":"expired","question_id":"'.self::ID.'",'
            .'"message":"The question expired without an answer after 90 minutes. No answer will arrive. Decide how to proceed."}',
            (new QuestionResolution(QuestionState::Expired))->toToolResult($legacy),
        );
    }

    #[Test]
    public function a_withdrawal_is_reported_exactly_as_before_whether_or_not_the_work_had_stopped(): void
    {
        $expected = '{"outcome":"withdrawn","question_id":"'.self::ID.'",'
            .'"message":"The user stopped this task while the question was pending."}';

        $whileWaiting = $this->question(['state' => 'withdrawn', 'resolved_at' => Carbon::parse('2026-10-13 13:30:00', 'UTC')]);
        $discarded = $this->question([
            'state' => 'withdrawn',
            'stopped_at' => Carbon::parse('2026-10-13 14:00:03', 'UTC'),
            'resolved_at' => Carbon::parse('2026-10-14 08:00:00', 'UTC'),
        ]);

        $this->assertSame($expected, (new QuestionResolution(QuestionState::Withdrawn))->toToolResult($whileWaiting));
        $this->assertSame($expected, (new QuestionResolution(QuestionState::Withdrawn))->toToolResult($discarded));
    }

    #[Test]
    public function an_undeliverable_question_is_reported_exactly_as_before(): void
    {
        $question = $this->question([
            'state' => 'undeliverable',
            'delivery_failure_reason' => 'chat not found',
            'resolved_at' => Carbon::parse('2026-10-13 13:00:01', 'UTC'),
        ]);

        $this->assertSame(
            '{"outcome":"undeliverable","question_id":"'.self::ID.'",'
            .'"message":"The question could not be delivered to the user: chat not found. The user has not seen it."}',
            (new QuestionResolution(QuestionState::Undeliverable, null, 'chat not found'))->toToolResult($question),
        );
    }

    // ---------------------------------------------------------------
    // No result while still waiting
    // ---------------------------------------------------------------

    #[Test]
    public function a_stopped_question_has_no_result(): void
    {
        $stopped = $this->question(['state' => 'stopped', 'stopped_at' => Carbon::parse('2026-10-13 14:00:03', 'UTC')]);

        $this->expectException(\LogicException::class);

        (new QuestionResolution(QuestionState::Stopped))->toToolResult($stopped);
    }

    #[Test]
    public function a_pending_question_has_no_result(): void
    {
        $this->expectException(\LogicException::class);

        (new QuestionResolution(QuestionState::Pending))->toToolResult($this->question(['state' => 'pending']));
    }
}
