<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\InteractiveAnswerService;
use ClarionApp\LlmClient\ValueObjects\AnswerMethod;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswer;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswerStatus;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\QuestionChannels\QuestionEndpointHarness;
use Tests\Integration\Harness\RecordingChannelDelivery;
use Tests\TestCase;

/**
 * A stopped question — one whose answerable period passed with work
 * stopped — still accepts its user's answer, through the web form or a
 * tapped option, exactly as a pending question does. The late answer is
 * recorded through the same checks, parser and compare-and-set; refused
 * answers leave it stopped; ownership is checked the same way.
 */
class InteractiveAnswerStoppedTest extends TestCase
{
    use QuestionEndpointHarness;

    private User $user;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->stranger = User::factory()->create();
        $this->setUpQuestionEndpoints([$this->user, $this->stranger]);

        Queue::fake();
        Event::fake([NewConversationMessageEvent::class]);
        app(ConversationChannelRegistry::class)->register(new RecordingChannelDelivery('web'));
    }

    protected function tearDown(): void
    {
        $this->tearDownQuestionEndpoints();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    private function service(): InteractiveAnswerService
    {
        return app(InteractiveAnswerService::class);
    }

    /**
     * A real question, opened the way a pause site opens it, then put into
     * `stopped` exactly as the stop transition leaves it: slot kept,
     * unresolved, `stopped_at` set.
     */
    private function stopped(array $choices = ['Backend', 'Frontend', 'Docs'], bool $allowMultiple = false): AgentQuestion
    {
        $conversation = $this->questionConversation($this->user, 'web');
        $question = $this->pendingQuestionIn($conversation, choices: $choices, allowMultiple: $allowMultiple);

        DB::table('agent_questions')->where('id', $question->id)->update([
            'state' => 'stopped',
            'stopped_at' => '2026-10-12 08:30:00',
        ]);

        $question = AgentQuestion::findOrFail($question->id);
        $this->assertSame(QuestionState::Stopped, $question->state, 'precondition: stopped');
        $this->assertSame($conversation->id, $question->pending_conversation_id, 'precondition: the slot is kept');
        $this->assertNull($question->resolved_at, 'precondition: unresolved');

        return $question;
    }

    /** @return array<string, mixed> */
    private function row(string $questionId): array
    {
        return (array) DB::table('agent_questions')->where('id', $questionId)->first();
    }

    private function messageCount(string $conversationId): int
    {
        return Message::where('conversation_id', $conversationId)->count();
    }

    // ---------------------------------------------------------------
    // The late answer is accepted
    // ---------------------------------------------------------------

    #[Test]
    public function a_form_answer_to_a_stopped_question_is_recorded_as_a_late_answer(): void
    {
        $question = $this->stopped();
        $stoppedAt = $this->row($question->id)['stopped_at'];
        $this->assertNotNull($stoppedAt, 'precondition: stopped_at is set');

        $outcome = $this->service()->record($question->id, (string) $this->user->id, InteractiveAnswer::selection([2]), AnswerMethod::WebForm);

        $this->assertSame(InteractiveAnswerStatus::Answered, $outcome->status, 'a stopped question accepts its user\'s answer');
        $this->assertSame(QuestionState::Answered, $outcome->state);
        $this->assertSame('2. Frontend', $outcome->selectedSummary);

        $row = $this->row($question->id);
        $this->assertSame('answered', $row['state']);
        $this->assertSame('2', $row['answer_raw']);
        $this->assertSame('web_form', $row['answer_method']);
        $this->assertSame($stoppedAt, $row['stopped_at'], 'the answer is late: when work stopped is kept');
        $this->assertNull($row['pending_conversation_id'], 'the slot is released');
        $this->assertNotNull($row['resolved_at']);

        $this->assertInstanceOf(Message::class, $outcome->message);
        $this->assertSame('2', $outcome->message->content, 'the conversation reads as if "2" had been typed');
        Queue::assertNotPushed(ResumeAfterQuestionJob::class);
    }

    #[Test]
    public function a_tapped_option_on_a_stopped_question_is_recorded_and_queues_exactly_one_resume(): void
    {
        $this->stopped();
        $question = $this->stopped();

        $outcome = $this->service()->submit($question->id, (string) $this->user->id, InteractiveAnswer::selection([3]), AnswerMethod::MessagingOption);

        $this->assertSame(InteractiveAnswerStatus::Answered, $outcome->status);
        $row = $this->row($question->id);
        $this->assertSame('answered', $row['state']);
        $this->assertSame('messaging_option', $row['answer_method']);
        $this->assertNotNull($row['stopped_at'], 'when work stopped is kept');
        Queue::assertPushed(ResumeAfterQuestionJob::class, 1);
        Queue::assertPushed(ResumeAfterQuestionJob::class, fn (ResumeAfterQuestionJob $job) => $job->questionId === $question->id);
        $this->assertNull($row['resumed_at'], 'the agent is never run inline');
    }

    // ---------------------------------------------------------------
    // Refused answers leave it stopped
    // ---------------------------------------------------------------

    #[Test]
    public function an_empty_answer_is_required_and_the_question_stays_stopped(): void
    {
        $question = $this->stopped();
        $rowBefore = $this->row($question->id);
        $messagesBefore = $this->messageCount($question->conversation_id);

        foreach ([InteractiveAnswer::selection([]), InteractiveAnswer::freeText(''), InteractiveAnswer::freeText("  \n")] as $answer) {
            $outcome = $this->service()->record($question->id, (string) $this->user->id, $answer, AnswerMethod::WebForm);

            $this->assertSame(InteractiveAnswerStatus::AnswerRequired, $outcome->status);
            $this->assertSame(QuestionState::Stopped, $outcome->state);
        }

        $this->assertSame($rowBefore, $this->row($question->id), 'the question row is untouched');
        $this->assertSame('stopped', $this->row($question->id)['state']);
        $this->assertSame($messagesBefore, $this->messageCount($question->conversation_id), 'no message was written');
        Queue::assertNotPushed(ResumeAfterQuestionJob::class);
    }

    #[Test]
    public function a_malformed_selection_is_invalid_and_the_question_stays_stopped(): void
    {
        $cases = [
            'number above the range' => [false, [4]],
            'zero' => [false, [0]],
            'duplicate numbers' => [true, [2, 2]],
            'two numbers on a single-select question' => [false, [1, 2]],
        ];

        foreach ($cases as $name => [$allowMultiple, $selected]) {
            $question = $this->stopped(allowMultiple: $allowMultiple);
            $rowBefore = $this->row($question->id);
            $messagesBefore = $this->messageCount($question->conversation_id);

            $outcome = $this->service()->submit($question->id, (string) $this->user->id, InteractiveAnswer::selection($selected), AnswerMethod::MessagingOption);

            $this->assertSame(InteractiveAnswerStatus::InvalidAnswer, $outcome->status, $name);
            $this->assertSame($rowBefore, $this->row($question->id), "{$name}: the question row is untouched");
            $this->assertSame($messagesBefore, $this->messageCount($question->conversation_id), "{$name}: no message was written");
        }

        Queue::assertNotPushed(ResumeAfterQuestionJob::class);
    }

    // ---------------------------------------------------------------
    // Ownership, exactly as for a pending question
    // ---------------------------------------------------------------

    #[Test]
    public function another_users_stopped_question_is_not_found_exactly_like_an_unknown_one(): void
    {
        $question = $this->stopped();
        $rowBefore = $this->row($question->id);
        $messagesBefore = $this->messageCount($question->conversation_id);

        $foreign = $this->service()->submit($question->id, (string) $this->stranger->id, InteractiveAnswer::selection([2]), AnswerMethod::MessagingOption);
        $absent = $this->service()->submit((string) Str::uuid(), (string) $this->stranger->id, InteractiveAnswer::selection([2]), AnswerMethod::MessagingOption);

        $this->assertSame(InteractiveAnswerStatus::NotFound, $foreign->status);
        $this->assertEquals($absent, $foreign, 'the outcome reveals nothing about whether the question exists');
        $this->assertSame($rowBefore, $this->row($question->id), 'the question row is untouched');
        $this->assertSame($messagesBefore, $this->messageCount($question->conversation_id));
        Queue::assertNotPushed(ResumeAfterQuestionJob::class);
    }

    #[Test]
    public function state_for_reports_a_stopped_question_to_its_owner_only(): void
    {
        $question = $this->stopped();

        $this->assertSame(QuestionState::Stopped, $this->service()->stateFor($question->id, (string) $this->user->id));
        $this->assertNull($this->service()->stateFor($question->id, (string) $this->stranger->id), 'another user\'s question');
    }
}
