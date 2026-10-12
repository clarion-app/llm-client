<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\InteractiveQuestionAnswers;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\Services\AnswerParser;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\InteractiveAnswerService;
use ClarionApp\LlmClient\ValueObjects\AnswerMethod;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswer;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswerOutcome;
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
 * InteractiveAnswerService is the one place an interactive answer — a web
 * form submission or a tapped option — is applied. It names the question it
 * answers by id, checks ownership, refuses anything not pending without
 * writing, refuses empty and malformed answers, then turns the answer into
 * the equivalent typed reply and records it through the same parser and
 * compare-and-set a typed reply uses. submit() also queues the resume.
 */
class InteractiveAnswerServiceTest extends TestCase
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

    private function conversation(): Conversation
    {
        return $this->questionConversation($this->user, 'web');
    }

    private function pending(array $choices = ['Backend', 'Frontend', 'Docs'], bool $allowMultiple = false): AgentQuestion
    {
        return $this->pendingQuestionIn($this->conversation(), choices: $choices, allowMultiple: $allowMultiple);
    }

    private function resolved(QuestionState $state): AgentQuestion
    {
        $question = $this->pending();
        if ($state === QuestionState::Expired) {
            // A question asked before unanswered policies existed: it expires as it always did.
            DB::table('agent_questions')->where('id', $question->id)->update(['unanswered_policy' => null, 'effective_wait_minutes' => null]);
            $question->refresh();
        }
        $service = app(AgentQuestionService::class);

        $won = match ($state) {
            QuestionState::Answered => $service->answer($question, app(AnswerParser::class)->parse('1', $question), (string) $this->user->id, '1') !== null,
            QuestionState::Expired => $service->expire($question),
            QuestionState::Withdrawn => $service->withdrawForConversation(Conversation::findOrFail($question->conversation_id)),
            QuestionState::Undeliverable => $service->markUndeliverable($question, 'chat not found'),
            QuestionState::Pending => throw new \LogicException('not a resolution'),
        };
        $this->assertTrue($won, "precondition: the question became {$state->value}");

        return $question->fresh();
    }

    private function messageCount(string $conversationId): int
    {
        return Message::where('conversation_id', $conversationId)->count();
    }

    /** @return array<string, mixed> */
    private function row(string $questionId): array
    {
        return (array) DB::table('agent_questions')->where('id', $questionId)->first();
    }

    private function assertUnwritten(AgentQuestion $question, array $rowBefore, int $messagesBefore): void
    {
        $this->assertSame($rowBefore, $this->row($question->id), 'the question row is untouched');
        $this->assertSame($messagesBefore, $this->messageCount($question->conversation_id), 'no message was written');
        Queue::assertNotPushed(ResumeAfterQuestionJob::class);
    }

    // ---------------------------------------------------------------
    // Step 1: not found
    // ---------------------------------------------------------------

    #[Test]
    public function an_unknown_question_is_not_found(): void
    {
        $outcome = $this->service()->record((string) Str::uuid(), (string) $this->user->id, InteractiveAnswer::selection([2]), AnswerMethod::WebForm);

        $this->assertSame(InteractiveAnswerStatus::NotFound, $outcome->status);
        $this->assertNull($outcome->state);
        $this->assertNull($outcome->question);
        $this->assertNull($outcome->message);
        $this->assertNull($outcome->selectedSummary);
    }

    #[Test]
    public function another_users_question_is_not_found_exactly_like_an_unknown_one(): void
    {
        $question = $this->pending();
        $rowBefore = $this->row($question->id);
        $messagesBefore = $this->messageCount($question->conversation_id);

        $foreign = $this->service()->record($question->id, (string) $this->stranger->id, InteractiveAnswer::selection([2]), AnswerMethod::WebForm);
        $absent = $this->service()->record((string) Str::uuid(), (string) $this->stranger->id, InteractiveAnswer::selection([2]), AnswerMethod::WebForm);

        $this->assertSame(InteractiveAnswerStatus::NotFound, $foreign->status);
        $this->assertEquals($absent, $foreign, 'the outcome reveals nothing about whether the question exists');
        $this->assertUnwritten($question, $rowBefore, $messagesBefore);
    }

    // ---------------------------------------------------------------
    // Step 2: not pending
    // ---------------------------------------------------------------

    #[Test]
    public function a_resolved_question_is_not_pending_and_nothing_is_written(): void
    {
        foreach ([QuestionState::Answered, QuestionState::Expired, QuestionState::Withdrawn, QuestionState::Undeliverable] as $state) {
            $question = $this->resolved($state);
            Queue::fake();
            $rowBefore = $this->row($question->id);
            $messagesBefore = $this->messageCount($question->conversation_id);

            $outcome = $this->service()->record($question->id, (string) $this->user->id, InteractiveAnswer::selection([2]), AnswerMethod::WebForm);

            $this->assertSame(InteractiveAnswerStatus::NotPending, $outcome->status, $state->value);
            $this->assertSame($state, $outcome->state, 'the outcome reports the question\'s current state');
            $this->assertNull($outcome->message);
            $this->assertUnwritten($question, $rowBefore, $messagesBefore);
        }
    }

    #[Test]
    public function a_resolved_question_is_reported_before_an_empty_answer_is(): void
    {
        $question = $this->resolved(QuestionState::Expired);

        $outcome = $this->service()->record($question->id, (string) $this->user->id, InteractiveAnswer::selection([]), AnswerMethod::WebForm);

        $this->assertSame(InteractiveAnswerStatus::NotPending, $outcome->status);
        $this->assertSame(QuestionState::Expired, $outcome->state);
    }

    // ---------------------------------------------------------------
    // Steps 3 and 4: refused answers leave the question waiting
    // ---------------------------------------------------------------

    #[Test]
    public function an_empty_answer_is_required_to_be_filled_and_the_question_keeps_waiting(): void
    {
        $question = $this->pending();
        $rowBefore = $this->row($question->id);
        $messagesBefore = $this->messageCount($question->conversation_id);

        foreach ([InteractiveAnswer::selection([]), InteractiveAnswer::freeText(''), InteractiveAnswer::freeText("  \n")] as $answer) {
            $outcome = $this->service()->record($question->id, (string) $this->user->id, $answer, AnswerMethod::WebForm);

            $this->assertSame(InteractiveAnswerStatus::AnswerRequired, $outcome->status);
        }

        $this->assertUnwritten($question, $rowBefore, $messagesBefore);
        $this->assertSame('pending', $this->row($question->id)['state']);
    }

    #[Test]
    public function malformed_selections_are_invalid_and_the_question_keeps_waiting(): void
    {
        $cases = [
            'selection on a question without choices' => [[], false, [1]],
            'number above the range' => [['Backend', 'Frontend', 'Docs'], false, [4]],
            'zero' => [['Backend', 'Frontend', 'Docs'], false, [0]],
            'negative' => [['Backend', 'Frontend', 'Docs'], true, [-1]],
            'duplicate numbers' => [['Backend', 'Frontend', 'Docs'], true, [2, 2]],
            'two numbers on a single-select question' => [['Backend', 'Frontend', 'Docs'], false, [1, 2]],
        ];

        foreach ($cases as $name => [$choices, $allowMultiple, $selected]) {
            $question = $this->pending($choices, $allowMultiple);
            $rowBefore = $this->row($question->id);
            $messagesBefore = $this->messageCount($question->conversation_id);

            $outcome = $this->service()->record($question->id, (string) $this->user->id, InteractiveAnswer::selection($selected), AnswerMethod::WebForm);

            $this->assertSame(InteractiveAnswerStatus::InvalidAnswer, $outcome->status, $name);
            $this->assertUnwritten($question, $rowBefore, $messagesBefore);
        }
    }

    // ---------------------------------------------------------------
    // Step 5: recorded through the parser and the compare-and-set
    // ---------------------------------------------------------------

    #[Test]
    public function a_single_selection_is_recorded_as_its_canonical_reply(): void
    {
        $question = $this->pending();

        $outcome = $this->service()->record($question->id, (string) $this->user->id, InteractiveAnswer::selection([2]), AnswerMethod::WebForm);

        $this->assertSame(InteractiveAnswerStatus::Answered, $outcome->status);
        $this->assertSame(QuestionState::Answered, $outcome->state);
        $this->assertSame('2. Frontend', $outcome->selectedSummary);

        $row = AgentQuestion::findOrFail($question->id);
        $this->assertSame(QuestionState::Answered, $row->state);
        $this->assertSame('2', $row->answer_raw);
        $this->assertSame([['number' => 2, 'text' => 'Frontend']], $row->answer_selected);
        $this->assertSame('web_form', DB::table('agent_questions')->where('id', $question->id)->value('answer_method'));
        $this->assertSame((string) $this->user->id, (string) $row->answered_by);

        $this->assertInstanceOf(Message::class, $outcome->message);
        $this->assertSame($row->answer_message_id, $outcome->message->id);
        $this->assertSame('2', $outcome->message->content, 'the conversation reads as if "2" had been typed');
        $this->assertSame('agent_question_answer', $outcome->message->tool_data['kind'] ?? null);
        $this->assertSame($question->id, $outcome->message->tool_data['question_id'] ?? null);
        $this->assertSame($question->id, $outcome->question?->id);

        Queue::assertNotPushed(ResumeAfterQuestionJob::class);
    }

    #[Test]
    public function a_multi_selection_is_recorded_sorted_and_comma_separated(): void
    {
        $question = $this->pending(allowMultiple: true);

        $outcome = $this->service()->record($question->id, (string) $this->user->id, InteractiveAnswer::selection([3, 1]), AnswerMethod::WebForm);

        $this->assertSame(InteractiveAnswerStatus::Answered, $outcome->status);
        $this->assertSame('1. Backend, 3. Docs', $outcome->selectedSummary);
        $row = AgentQuestion::findOrFail($question->id);
        $this->assertSame('1, 3', $row->answer_raw);
        $this->assertSame([['number' => 1, 'text' => 'Backend'], ['number' => 3, 'text' => 'Docs']], $row->answer_selected);
        $this->assertSame('1, 3', $outcome->message?->content);
    }

    #[Test]
    public function free_text_is_recorded_verbatim(): void
    {
        $question = $this->pending();
        $text = 'none of these — use a fourth approach';

        $outcome = $this->service()->record($question->id, (string) $this->user->id, InteractiveAnswer::freeText($text), AnswerMethod::WebForm);

        $this->assertSame(InteractiveAnswerStatus::Answered, $outcome->status);
        $this->assertSame($text, $outcome->selectedSummary);
        $row = AgentQuestion::findOrFail($question->id);
        $this->assertSame($text, $row->answer_raw);
        $this->assertSame([], $row->answer_selected);
        $this->assertSame($text, $outcome->message?->content);
    }

    #[Test]
    public function free_text_that_names_a_choice_means_that_choice_exactly_as_typing_it_would(): void
    {
        $question = $this->pending();

        $outcome = $this->service()->record($question->id, (string) $this->user->id, InteractiveAnswer::freeText('2'), AnswerMethod::WebForm);

        $this->assertSame(InteractiveAnswerStatus::Answered, $outcome->status);
        $this->assertSame([['number' => 2, 'text' => 'Frontend']], AgentQuestion::findOrFail($question->id)->answer_selected);
    }

    #[Test]
    public function losing_the_compare_and_set_to_an_expiry_reports_the_winners_state_and_writes_nothing(): void
    {
        $question = $this->pending();
        // A question asked before unanswered policies existed: it expires as it always did.
        DB::table('agent_questions')->where('id', $question->id)->update(['unanswered_policy' => null, 'effective_wait_minutes' => null]);
        $question->refresh();
        $expired = new \ArrayObject(['done' => false]);
        Event::listen('eloquent.retrieved: '.AgentQuestion::class, function (AgentQuestion $retrieved) use ($expired, $question) {
            if ($expired['done'] || $retrieved->id !== $question->id) {
                return;
            }
            $expired['done'] = true;
            $this->assertTrue(app(AgentQuestionService::class)->expire($retrieved), 'precondition: the expiry won');
        });
        $userMessagesBefore = Message::where('conversation_id', $question->conversation_id)->where('role', 'user')->count();

        $outcome = $this->service()->record($question->id, (string) $this->user->id, InteractiveAnswer::selection([2]), AnswerMethod::WebForm);

        $this->assertTrue($expired['done'], 'precondition: the expiry ran between loading the question and its compare-and-set');
        $this->assertSame(InteractiveAnswerStatus::NotPending, $outcome->status);
        $this->assertSame(QuestionState::Expired, $outcome->state);
        $this->assertNull($outcome->message);

        $row = $this->row($question->id);
        $this->assertSame('expired', $row['state']);
        $this->assertNull($row['answer_raw']);
        $this->assertNull($row['answer_method'], 'only an answered question has an answer method');
        $this->assertSame(
            $userMessagesBefore,
            Message::where('conversation_id', $question->conversation_id)->where('role', 'user')->count(),
            'the losing answer stored no message',
        );
    }

    // ---------------------------------------------------------------
    // submit(): record, then queue the resume
    // ---------------------------------------------------------------

    #[Test]
    public function submit_records_a_tapped_option_and_queues_exactly_one_resume(): void
    {
        $question = $this->pending();

        $outcome = $this->service()->submit($question->id, (string) $this->user->id, InteractiveAnswer::selection([2]), AnswerMethod::MessagingOption);

        $this->assertInstanceOf(InteractiveAnswerOutcome::class, $outcome);
        $this->assertSame(InteractiveAnswerStatus::Answered, $outcome->status);
        $this->assertSame('messaging_option', DB::table('agent_questions')->where('id', $question->id)->value('answer_method'));
        Queue::assertPushed(ResumeAfterQuestionJob::class, 1);
        Queue::assertPushed(ResumeAfterQuestionJob::class, fn (ResumeAfterQuestionJob $job) => $job->questionId === $question->id);
        $this->assertNull(AgentQuestion::findOrFail($question->id)->resumed_at, 'the agent is never run inline');
    }

    #[Test]
    public function submit_queues_nothing_when_the_answer_was_not_applied(): void
    {
        $pending = $this->pending();
        $resolved = $this->resolved(QuestionState::Expired);
        Queue::fake();

        $outcomes = [
            $this->service()->submit((string) Str::uuid(), (string) $this->user->id, InteractiveAnswer::selection([2]), AnswerMethod::MessagingOption),
            $this->service()->submit($pending->id, (string) $this->stranger->id, InteractiveAnswer::selection([2]), AnswerMethod::MessagingOption),
            $this->service()->submit($resolved->id, (string) $this->user->id, InteractiveAnswer::selection([2]), AnswerMethod::MessagingOption),
            $this->service()->submit($pending->id, (string) $this->user->id, InteractiveAnswer::selection([]), AnswerMethod::MessagingOption),
            $this->service()->submit($pending->id, (string) $this->user->id, InteractiveAnswer::selection([9]), AnswerMethod::MessagingOption),
        ];

        $this->assertSame(
            [InteractiveAnswerStatus::NotFound, InteractiveAnswerStatus::NotFound, InteractiveAnswerStatus::NotPending, InteractiveAnswerStatus::AnswerRequired, InteractiveAnswerStatus::InvalidAnswer],
            array_map(fn (InteractiveAnswerOutcome $outcome) => $outcome->status, $outcomes),
        );
        Queue::assertNotPushed(ResumeAfterQuestionJob::class);
        $this->assertSame('pending', $this->row($pending->id)['state']);
    }

    // ---------------------------------------------------------------
    // stateFor()
    // ---------------------------------------------------------------

    #[Test]
    public function state_for_reports_the_owners_question_and_nothing_else(): void
    {
        $pending = $this->pending();
        $expired = $this->resolved(QuestionState::Expired);

        $this->assertSame(QuestionState::Pending, $this->service()->stateFor($pending->id, (string) $this->user->id));
        $this->assertSame(QuestionState::Expired, $this->service()->stateFor($expired->id, (string) $this->user->id));
        $this->assertNull($this->service()->stateFor($pending->id, (string) $this->stranger->id), 'another user\'s question');
        $this->assertNull($this->service()->stateFor((string) Str::uuid(), (string) $this->user->id), 'no such question');
    }

    // ---------------------------------------------------------------
    // Typed replies and wiring
    // ---------------------------------------------------------------

    #[Test]
    public function a_typed_reply_through_the_message_endpoint_is_recorded_as_typed(): void
    {
        $question = $this->pending();

        $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/message', [
            'conversation_id' => $question->conversation_id,
            'content' => '2',
        ])->assertStatus(201);

        $this->assertSame('answered', $this->row($question->id)['state']);
        $this->assertSame('typed_reply', $this->row($question->id)['answer_method']);
    }

    #[Test]
    public function the_contract_resolves_to_this_service(): void
    {
        $this->assertInstanceOf(InteractiveAnswerService::class, app(InteractiveQuestionAnswers::class));
        $this->assertInstanceOf(InteractiveQuestionAnswers::class, app(InteractiveAnswerService::class));
    }
}
