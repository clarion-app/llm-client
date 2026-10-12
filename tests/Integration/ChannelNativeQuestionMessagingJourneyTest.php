<?php

namespace Tests\Integration;

use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\LlmClient\Contracts\InteractiveQuestionAnswers;
use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\QuestionRenderer;
use ClarionApp\LlmClient\ValueObjects\AnswerMethod;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswer;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswerStatus;
use ClarionApp\LlmClient\ValueObjects\PresentationMode;
use ClarionApp\LlmClient\ValueObjects\QuestionOption;
use ClarionApp\LlmClient\ValueObjects\QuestionOutcomeView;
use ClarionApp\LlmClient\ValueObjects\QuestionPresentation;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\CapturedPayload;
use Tests\Integration\Harness\RecordingRichChannelDelivery;

/**
 * A messaging-app conversation whose channel can show buttons, end to end:
 * the agent asks on the synchronous path `/agent` (and so Telegram) uses,
 * the channel is handed the question with every option to present as
 * buttons, and the user answers by tapping — through the interactive-answer
 * contract the Telegram package calls. The tap is recorded at once and the
 * agent continues in a queued job, whose reply is pushed back to the chat.
 * A typed reply still answers, and however the question is resolved, the
 * channel is told exactly once so it can take its buttons away.
 *
 * Real wiring throughout — AgentController, the agent loop, the question
 * service, the presenter, the interactive-answer service, the resumer and
 * its job — with the model scripted at the HTTP boundary and a recording
 * adapter that presents questions registered for the `telegram` channel.
 */
class ChannelNativeQuestionMessagingJourneyTest extends AssembledSystemTestCase
{
    private const AGENT_ENDPOINT = '/api/clarion-app/llm-client/agent';

    private const QUESTION = 'Which part should I change first?';

    private const CHOICES = ['Backend', 'Frontend', 'Docs'];

    private RecordingRichChannelDelivery $telegram;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entryPath = 'sync';
        config(['llm-client.run_trace.enabled' => true]);

        // The resume is queued, as a queue worker would run it: tap() (or
        // the test) works the job after submit() has returned.
        Queue::fake([SendHttpStreamRequest::class, ResumeAfterQuestionJob::class]);

        $this->telegram = new RecordingRichChannelDelivery('telegram');
        $this->app->make(ConversationChannelRegistry::class)->register($this->telegram);
    }

    // ---------------------------------------------------------------
    // Driving the conversation
    // ---------------------------------------------------------------

    private function telegramConversation(): Conversation
    {
        $fixture = $this->fixture()->build();
        $fixture->conversation->update(['channel' => 'telegram']);

        return $fixture->conversation->fresh();
    }

    private function userId(): string
    {
        return (string) $this->fixture()->user->id;
    }

    /** A Telegram message, sent the way TelegramChatService sends it. */
    private function send(string $message, ?string $conversationId = null): TestResponse
    {
        $body = ['message' => $message, 'channel' => 'telegram'];
        if ($conversationId !== null) {
            $body['conversation_id'] = $conversationId;
        }

        return $this->actingAs($this->fixture()->user, 'api')->postJson(self::AGENT_ENDPOINT, $body);
    }

    /** Start the task; the agent asks, and the run waits on the user. */
    private function ask(Conversation $conversation): AgentQuestion
    {
        $response = $this->send('Help me tidy up the project.', $conversation->id);

        $response->assertStatus(202);
        $question = AgentQuestion::where('conversation_id', $conversation->id)->latest('asked_at')->first();
        $this->assertNotNull($question, 'the agent\'s ask must have opened a question');
        $response->assertJson(['status' => 'awaiting_user', 'question_id' => $question->id]);
        $this->assertSame(QuestionState::Pending, $question->state);
        $this->assertSame('sync', $question->resume_mode);
        $this->assertCount(1, $this->transport()->capturedChatPayloads(), 'the run waits: no further model request');

        return $question;
    }

    /** @return array<string, mixed>|null the `ask_user` tool result the continuation sent the model */
    private function askUserResult(AgentQuestion $question, int $payloadIndex = 1): ?array
    {
        $payloads = $this->transport()->capturedChatPayloads();
        $this->assertArrayHasKey($payloadIndex, $payloads, "no model request #{$payloadIndex} was made");

        /** @var CapturedPayload $payload */
        $payload = $payloads[$payloadIndex];
        foreach (json_decode(json_encode($payload->messages), true) as $message) {
            if (($message['role'] ?? null) === 'tool' && ($message['tool_call_id'] ?? null) === $question->tool_call_id) {
                return json_decode(is_string($message['content']) ? $message['content'] : json_encode($message['content']), true);
            }
        }

        return null;
    }

    private function onlyResolution(AgentQuestion $question): QuestionOutcomeView
    {
        $resolutions = $this->telegram->resolutionsFor($question->id);
        $this->assertCount(1, $resolutions, 'the channel is told of the resolution exactly once');

        return $resolutions[0];
    }

    // ---------------------------------------------------------------
    // Scenarios
    // ---------------------------------------------------------------

    #[Test]
    public function the_channel_is_handed_every_option_to_present_as_buttons(): void
    {
        $this->scenario = 'channel_native_question_presented';
        $conversation = $this->telegramConversation();
        $this->script()->toolRequest('ask_user', ['question' => self::QUESTION, 'choices' => self::CHOICES]);

        $question = $this->ask($conversation);

        $presentations = $this->telegram->presentationsFor($conversation->id);
        $this->assertCount(1, $presentations, 'the question is presented once, richly');
        $presentation = $presentations[0];
        $this->assertInstanceOf(QuestionPresentation::class, $presentation);
        $this->assertSame($question->id, $presentation->questionId);
        $this->assertSame(self::QUESTION, $presentation->question);
        $this->assertFalse($presentation->allowMultiple);
        $this->assertEquals([
            new QuestionOption(1, 'Backend', '1. Backend'),
            new QuestionOption(2, 'Frontend', '2. Frontend'),
            new QuestionOption(3, 'Docs', '3. Docs'),
        ], $presentation->options, 'every choice, in order, with its full text');

        $renderer = $this->app->make(QuestionRenderer::class);
        $question->refresh();
        $this->assertSame($renderer->renderWithOptions($question, $question->preamble), $presentation->bodyText);
        $this->assertSame(AgentQuestionWebJourneyTest::GOLDEN_THREE_CHOICES, $presentation->plainText, 'the plain fallback is the plain-text question');
        $this->assertSame([], $this->telegram->deliveries, 'nothing was sent as plain text');

        $this->assertSame(PresentationMode::Rich, $question->presentation);
        $this->assertNull($question->presentation_fallback_reason);
        $this->assertNotNull($question->delivered_at);
        $this->assertSame(
            AgentQuestionWebJourneyTest::GOLDEN_THREE_CHOICES,
            Message::findOrFail($question->display_message_id)->content,
            'the conversation stores the same question text as before',
        );
        $this->assertSame([], $this->telegram->resolutionsFor($question->id), 'nothing resolved yet');
    }

    #[Test]
    public function a_tap_is_recorded_at_once_and_the_agent_continues_in_a_queued_job_replying_in_the_chat(): void
    {
        $this->scenario = 'channel_native_question_tapped';
        $conversation = $this->telegramConversation();
        $this->script()
            ->toolRequest('ask_user', ['question' => self::QUESTION, 'choices' => self::CHOICES])
            ->finalAnswer('Starting with the frontend.');
        $question = $this->ask($conversation);

        // The tap, as the Telegram package makes it.
        $outcome = $this->app->make(InteractiveQuestionAnswers::class)->submit(
            $question->id,
            $this->userId(),
            InteractiveAnswer::selection([2]),
            AnswerMethod::MessagingOption,
        );

        $this->assertSame(InteractiveAnswerStatus::Answered, $outcome->status);
        $this->assertSame('2. Frontend', $outcome->selectedSummary);
        $question->refresh();
        $this->assertSame(QuestionState::Answered, $question->state, 'recorded before submit() returned');
        $this->assertSame(AnswerMethod::MessagingOption, $question->answer_method);
        $this->assertNull($question->resumed_at, 'the agent does not run inside the tap');
        $this->assertCount(1, $this->transport()->capturedChatPayloads(), 'no model request yet');
        Queue::assertPushed(ResumeAfterQuestionJob::class, 1);
        Queue::assertPushed(ResumeAfterQuestionJob::class, fn (ResumeAfterQuestionJob $job) => $job->questionId === $question->id);

        // The queue worker runs the resume.
        foreach (Queue::pushed(ResumeAfterQuestionJob::class) as $job) {
            $this->app->call([$job, 'handle']);
        }

        $this->assertSame([
            'outcome' => 'answered',
            'question_id' => $question->id,
            'answer' => [
                'raw_reply' => '2',
                'is_selection' => true,
                'selected' => [['number' => 2, 'text' => 'Frontend']],
            ],
        ], $this->askUserResult($question), 'the agent receives choice 2, exactly as a typed "2"');
        $this->assertSame(['Starting with the frontend.'], $this->telegram->textsFor($conversation->id), 'the reply is pushed to the chat');

        $question->refresh();
        $this->assertNotNull($question->resumed_at);
        $this->assertSame('completed', DB::table('agent_runs')->where('id', $question->run_id)->value('end_state'));
        $this->assertFalse((bool) $conversation->fresh()->is_processing);

        $answer = Message::where('conversation_id', $conversation->id)->get()
            ->filter(fn (Message $m) => ($m->tool_data['kind'] ?? null) === 'agent_question_answer')
            ->values();
        $this->assertCount(1, $answer, 'the answer is stored once, as a typed reply would be');
        $this->assertSame('2', $answer[0]->content);

        $resolution = $this->onlyResolution($question);
        $this->assertSame(QuestionState::Answered, $resolution->state);
        $this->assertSame([['number' => 2, 'text' => 'Frontend']], $resolution->selected);
        $this->assertTrue($resolution->isSelection);
        $this->assertSame(AnswerMethod::MessagingOption, $resolution->method);
        $this->assertSame('Answered: 2. Frontend', $resolution->summary);
    }

    #[Test]
    public function several_options_selected_and_submitted_answer_with_all_of_them(): void
    {
        $this->scenario = 'channel_native_question_multi';
        $conversation = $this->telegramConversation();
        $this->script()
            ->toolRequest('ask_user', ['question' => 'Which parts should I change?', 'choices' => self::CHOICES, 'allow_multiple' => true])
            ->finalAnswer('Backend and docs it is.');
        $question = $this->ask($conversation);
        $this->assertTrue($this->telegram->presentationsFor($conversation->id)[0]->allowMultiple ?? false, 'presented as a multi-select');

        $outcome = $this->telegram->tap($question->id, $this->userId(), [3, 1]);

        $this->assertSame(InteractiveAnswerStatus::Answered, $outcome->status);
        $this->assertSame('1. Backend, 3. Docs', $outcome->selectedSummary);
        $this->assertSame([
            'outcome' => 'answered',
            'question_id' => $question->id,
            'answer' => [
                'raw_reply' => '1, 3',
                'is_selection' => true,
                'selected' => [['number' => 1, 'text' => 'Backend'], ['number' => 3, 'text' => 'Docs']],
            ],
        ], $this->askUserResult($question));
        $this->assertSame(['Backend and docs it is.'], $this->telegram->textsFor($conversation->id));

        $resolution = $this->onlyResolution($question);
        $this->assertSame([['number' => 1, 'text' => 'Backend'], ['number' => 3, 'text' => 'Docs']], $resolution->selected);
        $this->assertSame('Answered: 1. Backend, 3. Docs', $resolution->summary);
    }

    #[Test]
    public function an_answer_in_the_users_own_words_is_free_text(): void
    {
        $this->scenario = 'channel_native_question_free_text';
        $conversation = $this->telegramConversation();
        $this->script()
            ->toolRequest('ask_user', ['question' => self::QUESTION, 'choices' => self::CHOICES])
            ->finalAnswer('A fourth approach, then.');
        $question = $this->ask($conversation);

        $outcome = $this->telegram->tap($question->id, $this->userId(), 'none of these — use a fourth approach');

        $this->assertSame(InteractiveAnswerStatus::Answered, $outcome->status);
        $this->assertSame([
            'outcome' => 'answered',
            'question_id' => $question->id,
            'answer' => [
                'raw_reply' => 'none of these — use a fourth approach',
                'is_selection' => false,
                'selected' => [],
            ],
        ], $this->askUserResult($question));
        $this->assertSame(['A fourth approach, then.'], $this->telegram->textsFor($conversation->id));

        $resolution = $this->onlyResolution($question);
        $this->assertFalse($resolution->isSelection);
        $this->assertSame([], $resolution->selected);
        $this->assertSame('Answered in your own words.', $resolution->summary);
        $this->assertSame(AnswerMethod::MessagingOption, $resolution->method);
    }

    #[Test]
    public function a_typed_reply_still_answers_and_takes_the_buttons_away(): void
    {
        $this->scenario = 'channel_native_question_typed';
        $conversation = $this->telegramConversation();
        $this->script()
            ->toolRequest('ask_user', ['question' => self::QUESTION, 'choices' => self::CHOICES])
            ->finalAnswer('Docs first.');
        $question = $this->ask($conversation);

        $reply = $this->send('3');

        $reply->assertStatus(200);
        $reply->assertJson(['conversation_id' => $conversation->id, 'status' => 'completed', 'content' => 'Docs first.']);
        $this->assertSame([
            'outcome' => 'answered',
            'question_id' => $question->id,
            'answer' => [
                'raw_reply' => '3',
                'is_selection' => true,
                'selected' => [['number' => 3, 'text' => 'Docs']],
            ],
        ], $this->askUserResult($question));

        $question->refresh();
        $this->assertSame(AnswerMethod::TypedReply, $question->answer_method);
        $this->assertSame([], $this->telegram->deliveries, 'the reply went back in the HTTP response, not through the adapter');
        Queue::assertNotPushed(ResumeAfterQuestionJob::class);

        $resolution = $this->onlyResolution($question);
        $this->assertSame(QuestionState::Answered, $resolution->state);
        $this->assertSame(AnswerMethod::TypedReply, $resolution->method);
        $this->assertSame('Answered: 3. Docs', $resolution->summary);
    }

    #[Test]
    public function an_expired_question_is_resolved_on_the_channel_exactly_once(): void
    {
        $this->scenario = 'channel_native_question_expired';
        $conversation = $this->telegramConversation();
        $this->script()->toolRequest('ask_user', ['question' => self::QUESTION, 'choices' => self::CHOICES]);
        $question = $this->ask($conversation);
        // A question asked before unanswered policies existed: it expires as it always did.
        DB::table('agent_questions')->where('id', $question->id)->update(['unanswered_policy' => null, 'effective_wait_minutes' => null]);

        $service = $this->app->make(AgentQuestionService::class);
        $this->assertTrue($service->expire($question->fresh()));
        $this->assertFalse($service->expire($question->fresh()), 'a second resolution loses');

        $resolution = $this->onlyResolution($question);
        $this->assertSame(QuestionState::Expired, $resolution->state);
        $this->assertSame([], $resolution->selected);
        $this->assertFalse($resolution->isSelection);
        $this->assertNull($resolution->method);
        $this->assertSame('This question expired and is no longer awaiting a reply.', $resolution->summary);

        // A tap on the still-visible button afterwards changes nothing.
        $late = $this->telegram->tap($question->id, $this->userId(), [2]);
        $this->assertSame(InteractiveAnswerStatus::NotPending, $late->status);
        $this->assertSame(QuestionState::Expired, $late->state);
        $this->assertCount(1, $this->telegram->resolutionsFor($question->id));
    }

    #[Test]
    public function a_question_without_choices_is_presented_with_no_options_and_answered_in_words(): void
    {
        $this->scenario = 'channel_native_question_no_choices';
        $conversation = $this->telegramConversation();
        $this->script()
            ->toolRequest('ask_user', ['question' => 'What should the release be called?'])
            ->finalAnswer('Aurora it is.');
        $question = $this->ask($conversation);

        $presentation = $this->telegram->presentationsFor($conversation->id)[0] ?? null;
        $this->assertNotNull($presentation, 'presented through the channel\'s rich path');
        $this->assertSame([], $presentation->options, 'no options to show as buttons');
        $this->assertSame('What should the release be called?', $presentation->question);
        $this->assertSame(PresentationMode::Rich, $question->fresh()->presentation);

        $outcome = $this->telegram->tap($question->id, $this->userId(), 'Aurora');

        $this->assertSame(InteractiveAnswerStatus::Answered, $outcome->status);
        $this->assertSame(['raw_reply' => 'Aurora', 'is_selection' => false, 'selected' => []], $this->askUserResult($question)['answer'] ?? null);
        $this->assertSame(['Aurora it is.'], $this->telegram->textsFor($conversation->id));
        $this->assertSame('Answered in your own words.', $this->onlyResolution($question)->summary);
    }
}
