<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Contracts\InteractiveQuestionAnswers;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\Services\AnswerParser;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\InteractiveAnswerService;
use ClarionApp\LlmClient\Services\QuestionRenderer;
use ClarionApp\LlmClient\ValueObjects\AnswerMethod;
use ClarionApp\LlmClient\ValueObjects\AskUserRequest;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswer;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswerStatus;
use ClarionApp\LlmClient\ValueObjects\ParsedAnswer;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use ClarionApp\LlmClient\ValueObjects\UnansweredPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
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
 *
 * describeState() is what a channel tells the user when a control is used on
 * a question that no longer takes an answer: the question's outcome line,
 * naming what was assumed when it proceeded on an assumption. It says
 * nothing — identically — for a question that does not exist or is someone
 * else's, and nothing for one that still takes an answer.
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

    // ---------------------------------------------------------------
    // describeState(): what a stale control is told
    // ---------------------------------------------------------------

    /** A question in each state, reached the way that state is reached. */
    private function questionIn(string $how): AgentQuestion
    {
        $service = app(AgentQuestionService::class);

        if ($how === 'expired_assumed') {
            $conversation = $this->questionConversation($this->user, 'web');
            $question = AgentQuestion::findOrFail($this->openQuestion($conversation, request: new AskUserRequest(
                'Which part should I change first?',
                ['Backend', 'Frontend', 'Docs'],
                false,
                policy: UnansweredPolicy::ProceedOnAssumption,
                assumedAnswer: new ParsedAnswer('1', true, [['number' => 1, 'text' => 'Backend']]),
                requestedWaitMinutes: 60,
            ))->question->id);
            $this->assertSame(QuestionState::ExpiredAssumed, $service->expireWithPolicy($question), 'precondition: proceeded on its assumption');

            return $question->fresh();
        }

        if (in_array($how, ['stopped', 'answered_late', 'discarded'], true)) {
            $question = $this->stopped();
        } else {
            $question = $this->pendingQuestionIn($this->questionConversation($this->user, 'web'));
        }

        $won = match ($how) {
            'pending', 'stopped' => true,
            'answered', 'answered_late' => $service->answer($question, app(AnswerParser::class)->parse('2', $question), (string) $this->user->id, '2') !== null,
            'expired' => (function () use ($question, $service) {
                // A question asked before unanswered policies existed: it expires as it always did.
                DB::table('agent_questions')->where('id', $question->id)->update(['unanswered_policy' => null, 'effective_wait_minutes' => null]);

                return $service->expire($question->fresh());
            })(),
            'withdrawn', 'discarded' => $service->withdrawForConversation(Conversation::findOrFail($question->conversation_id)),
            'undeliverable' => $service->markUndeliverable($question, 'chat not found'),
        };
        $this->assertTrue($won, "precondition: the question became {$how}");

        return $question->fresh();
    }

    public static function statesThatNoLongerTakeAnAnswer(): array
    {
        return [
            'answered' => ['answered', 'answered', 'Answered: 2. Frontend'],
            'answered after work stopped' => ['answered_late', 'answered', 'Answered after work stopped: 2. Frontend'],
            'expired before unanswered policies existed' => ['expired', 'expired', 'This question expired and is no longer awaiting a reply.'],
            'proceeded on its assumption' => ['expired_assumed', 'expired_assumed', 'Expired — no answer arrived in time; the agent continued assuming: 1. Backend'],
            'withdrawn while waiting' => ['withdrawn', 'withdrawn', 'This question was withdrawn and is no longer awaiting a reply.'],
            'discarded while stopped' => ['discarded', 'withdrawn', 'Discarded — the conversation was ended or deleted while work was stopped.'],
            'undeliverable' => ['undeliverable', 'undeliverable', 'This question could not be delivered.'],
        ];
    }

    #[Test]
    #[DataProvider('statesThatNoLongerTakeAnAnswer')]
    public function describe_state_tells_the_owner_how_a_question_that_no_longer_takes_an_answer_ended(string $how, string $state, string $expected): void
    {
        $question = $this->questionIn($how);
        $this->assertSame($state, $question->state->value, 'precondition');

        $described = app(InteractiveQuestionAnswers::class)->describeState($question->id, (string) $this->user->id);

        $this->assertSame($expected, $described);
        $this->assertSame(app(QuestionRenderer::class)->renderOutcome($question), $described, 'the question\'s own outcome line');
    }

    #[Test]
    public function describe_state_says_nothing_about_a_question_that_still_takes_an_answer(): void
    {
        foreach (['pending', 'stopped'] as $how) {
            $question = $this->questionIn($how);
            $this->assertSame($how, $question->state->value, 'precondition');
            $rowBefore = $this->row($question->id);

            $described = $this->service()->describeState($question->id, (string) $this->user->id);

            $this->assertNull($described, "{$how}: the question still takes an answer");
            $this->assertSame($rowBefore, $this->row($question->id), "{$how}: nothing changes");
        }
    }

    #[Test]
    public function describe_state_reveals_nothing_about_an_absent_or_foreign_question(): void
    {
        $answered = $this->questionIn('answered');
        $assumed = $this->questionIn('expired_assumed');

        $absent = $this->service()->describeState((string) Str::uuid(), (string) $this->stranger->id);
        $foreignAnswered = $this->service()->describeState($answered->id, (string) $this->stranger->id);
        $foreignAssumed = $this->service()->describeState($assumed->id, (string) $this->stranger->id);

        $this->assertNull($absent, 'an unknown question');
        $this->assertNull($foreignAnswered, 'another user\'s answered question');
        $this->assertNull($foreignAssumed, 'another user\'s question, whatever it assumed');
        $this->assertNotNull($this->service()->describeState($assumed->id, (string) $this->user->id), 'precondition: its owner is told');
    }
}
