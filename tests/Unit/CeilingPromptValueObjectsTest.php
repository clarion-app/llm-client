<?php

namespace Tests\Unit;

use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\ValueObjects\DeliveryResult;
use ClarionApp\LlmClient\ValueObjects\IntakeResult;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswerOutcome;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswerStatus;
use ClarionApp\LlmClient\ValueObjects\OpenedQuestion;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the answer entries hand back for a reply to a ceiling prompt.
 *
 * Besides answered / answer required / not an answer, a typed reply to a
 * ceiling prompt can be a decision that needs restating (it carries the
 * guidance to show) or a stop that was recorded without resuming anything
 * (it carries the stored reply and the acknowledgement). An interactive
 * answer that does not fit can carry guidance too — optionally, because the
 * messaging integration builds these outcomes with the state alone and must
 * keep working unchanged.
 */
class CeilingPromptValueObjectsTest extends TestCase
{
    private function question(): AgentQuestion
    {
        $question = new AgentQuestion();
        $question->id = '33333333-3333-4333-8333-333333333333';

        return $question;
    }

    private function message(): Message
    {
        $message = new Message();
        $message->id = '44444444-4444-4444-8444-444444444444';

        return $message;
    }

    #[Test]
    public function a_decision_that_needs_restating_carries_its_guidance(): void
    {
        $question = $this->question();

        $result = IntakeResult::decisionRequired($question, 'Reply 1, 2 or 3.');

        $this->assertSame('decision_required', IntakeResult::DECISION_REQUIRED);
        $this->assertSame(IntakeResult::DECISION_REQUIRED, $result->kind);
        $this->assertSame($question, $result->question);
        $this->assertNull($result->message, 'nothing was stored');
        $this->assertSame('Reply 1, 2 or 3.', $result->guidance);
        $this->assertNull($result->acknowledgement);
    }

    #[Test]
    public function a_recorded_stop_carries_the_stored_reply_and_its_acknowledgement(): void
    {
        $question = $this->question();
        $message = $this->message();

        $result = IntakeResult::decisionRecorded($question, $message, 'Stopped, as you asked.');

        $this->assertSame('decision_recorded', IntakeResult::DECISION_RECORDED);
        $this->assertSame(IntakeResult::DECISION_RECORDED, $result->kind);
        $this->assertSame($question, $result->question);
        $this->assertSame($message, $result->message);
        $this->assertSame('Stopped, as you asked.', $result->acknowledgement);
        $this->assertNull($result->guidance);
    }

    #[Test]
    public function the_existing_intake_results_are_unchanged(): void
    {
        $question = $this->question();
        $message = $this->message();

        $answered = IntakeResult::answered($question, $message);
        $this->assertSame(IntakeResult::ANSWERED, $answered->kind);
        $this->assertSame($message, $answered->message);
        $this->assertNull($answered->guidance);
        $this->assertNull($answered->acknowledgement);

        $required = IntakeResult::answerRequired($question);
        $this->assertSame(IntakeResult::ANSWER_REQUIRED, $required->kind);
        $this->assertNull($required->guidance);

        $none = IntakeResult::notAnAnswer();
        $this->assertSame(IntakeResult::NOT_AN_ANSWER, $none->kind);
        $this->assertNull($none->question);
        $this->assertNull($none->guidance);

        // Constructed positionally, as before.
        $positional = new IntakeResult(IntakeResult::ANSWERED, $question, $message);
        $this->assertSame($question, $positional->question);
        $this->assertNull($positional->guidance);

        $this->assertSame(
            ['answered', 'answer_required', 'not_an_answer', 'decision_required', 'decision_recorded'],
            [IntakeResult::ANSWERED, IntakeResult::ANSWER_REQUIRED, IntakeResult::NOT_AN_ANSWER, IntakeResult::DECISION_REQUIRED, IntakeResult::DECISION_RECORDED],
            'five distinct kinds',
        );
    }

    #[Test]
    public function an_invalid_interactive_answer_may_carry_guidance(): void
    {
        $bare = InteractiveAnswerOutcome::invalidAnswer(QuestionState::Pending);
        $this->assertSame(InteractiveAnswerStatus::InvalidAnswer, $bare->status);
        $this->assertSame(QuestionState::Pending, $bare->state);
        $this->assertNull($bare->guidance, 'the one-argument form the messaging integration uses still works');

        $guided = InteractiveAnswerOutcome::invalidAnswer(QuestionState::Stopped, 'Reply 1, 2 or 3.');
        $this->assertSame(InteractiveAnswerStatus::InvalidAnswer, $guided->status);
        $this->assertSame(QuestionState::Stopped, $guided->state);
        $this->assertSame('Reply 1, 2 or 3.', $guided->guidance);
    }

    #[Test]
    public function every_other_interactive_outcome_carries_no_guidance(): void
    {
        $question = $this->question();

        foreach ([
            InteractiveAnswerOutcome::answered($question, $this->message(), 'Stop'),
            InteractiveAnswerOutcome::answerRequired(QuestionState::Pending),
            InteractiveAnswerOutcome::notPending(QuestionState::Answered, $question),
            InteractiveAnswerOutcome::notFound(),
        ] as $outcome) {
            $this->assertNull($outcome->guidance);
        }

        $this->assertSame('Stop', InteractiveAnswerOutcome::answered($question, $this->message(), 'Stop')->selectedSummary);
    }

    #[Test]
    public function an_opened_ceiling_prompt_exposes_its_question_display_message_and_delivery(): void
    {
        $question = $this->question();
        $display = $this->message();
        $delivery = DeliveryResult::failed('no delivery is registered');

        $opened = new OpenedQuestion($question, $display, $delivery);

        $this->assertSame($question, $opened->question);
        $this->assertSame($display, $opened->displayMessage);
        $this->assertSame($delivery, $opened->delivery);
        $this->assertFalse($opened->delivery->delivered);
        $this->assertNull($opened->resumeResult, 'a ceiling prompt never resumes inline');
    }
}
