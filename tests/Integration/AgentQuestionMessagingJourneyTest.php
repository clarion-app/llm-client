<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\CapturedPayload;
use Tests\Integration\Harness\RecordingChannelDelivery;

/**
 * A messaging-channel conversation, end to end: the agent asks a question
 * on the synchronous path that `/agent` (and so Telegram) uses, the question
 * is pushed to the user through the conversation's channel adapter, and the
 * user's next message on that channel answers it and resumes the agent in
 * the same request.
 *
 * Real wiring throughout — AgentController, the agent loop, the question
 * service, the intake, the resumer — with the model scripted at the HTTP
 * boundary and a recording adapter registered for the `telegram` channel.
 * The asking side is identical to the web journey: same `ask_user`
 * arguments, same rendered text.
 */
class AgentQuestionMessagingJourneyTest extends AssembledSystemTestCase
{
    private const AGENT_ENDPOINT = '/api/clarion-app/llm-client/agent';

    private RecordingChannelDelivery $telegram;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entryPath = 'sync';
        config(['llm-client.run_trace.enabled' => true]);
        config(['llm-client.conversation.inactivity_threshold_hours' => 4]);

        $this->telegram = new RecordingChannelDelivery('telegram');
        $this->app->make(ConversationChannelRegistry::class)->register($this->telegram);
    }

    // ---------------------------------------------------------------
    // Driving the conversation
    // ---------------------------------------------------------------

    /** Build the fixture with its conversation on the Telegram channel. */
    private function telegramFixture(): Conversation
    {
        $fixture = $this->fixture()->build();
        $fixture->conversation->update(['channel' => 'telegram']);

        return $fixture->conversation->fresh();
    }

    /** Send a Telegram message the way TelegramChatService does: no conversation id. */
    private function send(?string $message, ?string $conversationId = null): \Illuminate\Testing\TestResponse
    {
        $body = ['message' => $message, 'channel' => 'telegram'];
        if ($conversationId !== null) {
            $body['conversation_id'] = $conversationId;
        }

        return $this->actingAs($this->fixture()->user, 'api')->postJson(self::AGENT_ENDPOINT, $body);
    }

    private function questionIn(Conversation $conversation): AgentQuestion
    {
        $question = AgentQuestion::where('conversation_id', $conversation->id)->latest('asked_at')->first();
        $this->assertNotNull($question, 'the agent\'s ask must have opened a question');

        return $question;
    }

    /** @return list<array<string, mixed>> */
    private function chatPayloadMessages(int $index): array
    {
        $payloads = $this->transport()->capturedChatPayloads();
        $this->assertArrayHasKey($index, $payloads, "no model request #{$index} was made");

        /** @var CapturedPayload $payload */
        $payload = $payloads[$index];

        return json_decode(json_encode($payload->messages), true);
    }

    private function toolResultFor(array $messages, string $toolCallId): ?string
    {
        foreach ($messages as $message) {
            if (($message['role'] ?? null) === 'tool' && ($message['tool_call_id'] ?? null) === $toolCallId) {
                return is_string($message['content'] ?? null) ? $message['content'] : json_encode($message['content']);
            }
        }

        return null;
    }

    /** Ask the three-choice question on the first message and return it. */
    private function askThreeChoiceQuestion(Conversation $conversation): AgentQuestion
    {
        $response = $this->send('Help me tidy up the project.', $conversation->id);

        $response->assertStatus(202);
        $question = $this->questionIn($conversation);
        $response->assertJson([
            'conversation_id' => $conversation->id,
            'message_id' => $question->display_message_id,
            'status' => 'awaiting_user',
            'question_id' => $question->id,
        ]);
        $this->assertSame('', (string) $response->json('content'), 'the question is pushed through the channel, not returned');
        $this->assertSame(QuestionState::Pending, $question->state);
        $this->assertSame('sync', $question->resume_mode);
        $this->assertSame('telegram', $question->channel);
        $this->assertFalse((bool) $conversation->fresh()->is_processing, 'a conversation waiting on its user is not processing');

        return $question;
    }

    // ---------------------------------------------------------------
    // Scenarios
    // ---------------------------------------------------------------

    public function test_question_is_pushed_to_the_chat_and_a_numbered_reply_resumes_the_agent(): void
    {
        $this->scenario = 'messaging_question_answered_by_number';
        $conversation = $this->telegramFixture();

        // Another Telegram conversation of the same user, which must never
        // receive this question.
        $other = Conversation::create([
            'id' => (string) Str::uuid(),
            'user_id' => $conversation->user_id,
            'server_id' => $conversation->server_id,
            'model' => $conversation->model,
            'title' => 'Another Telegram conversation',
            'channel' => 'telegram',
        ]);

        $this->script()
            ->toolRequest('ask_user', ['question' => AgentQuestionWebJourneyTest::QUESTION, 'choices' => AgentQuestionWebJourneyTest::CHOICES])
            ->finalAnswer('Starting with the backend.');

        $question = $this->askThreeChoiceQuestion($conversation);

        // The chat received exactly the rendered question, numbered — the
        // same text the web conversation shows.
        $this->assertSame(
            [['conversation_id' => $conversation->id, 'text' => AgentQuestionWebJourneyTest::GOLDEN_THREE_CHOICES]],
            $this->telegram->deliveries,
            'one delivery, to this conversation only, of the rendered question',
        );
        $this->assertSame([], $this->telegram->textsFor($other->id));
        $this->assertSame(AgentQuestionWebJourneyTest::GOLDEN_THREE_CHOICES, Message::findOrFail($question->display_message_id)->content);

        // The model's ask is byte-identical to the web journey's.
        $toolCall = Message::findOrFail($question->paused_message_id)->tool_data['tool_calls'][0] ?? [];
        $this->assertSame('ask_user', $toolCall['function']['name'] ?? null);
        $this->assertSame(AgentQuestionWebJourneyTest::threeChoiceAskArguments(), $toolCall['function']['arguments'] ?? null);
        $this->assertCount(1, $this->transport()->capturedChatPayloads(), 'the run waits for the user: no further model request');

        // The user replies "1" in the chat.
        $reply = $this->send('1');

        $reply->assertStatus(200);
        $reply->assertJson([
            'conversation_id' => $conversation->id,
            'status' => 'completed',
            'content' => 'Starting with the backend.',
        ]);

        $raw = $this->toolResultFor($this->chatPayloadMessages(1), $question->tool_call_id);
        $this->assertNotNull($raw, 'the continuation answers the ask_user call');
        $this->assertSame([
            'outcome' => 'answered',
            'question_id' => $question->id,
            'answer' => [
                'raw_reply' => '1',
                'is_selection' => true,
                'selected' => [['number' => 1, 'text' => 'Backend']],
            ],
        ], json_decode($raw, true));

        $question->refresh();
        $this->assertSame(QuestionState::Answered, $question->state);
        $this->assertNotNull($question->resumed_at);
        $this->assertSame('completed', DB::table('agent_runs')->where('id', $question->run_id)->value('end_state'));
        $this->assertSame('success', DB::table('agent_run_actions')->where('id', $question->action_id)->value('outcome'));
        $this->assertFalse((bool) $conversation->fresh()->is_processing);
        $this->assertCount(1, $this->telegram->deliveries, 'the reply goes back in the HTTP response, not through the adapter');
    }

    public function test_oversized_question_is_handed_to_the_adapter_whole(): void
    {
        $this->scenario = 'messaging_question_oversized';
        $conversation = $this->telegramFixture();

        $questionText = str_repeat('Some background the user needs before choosing. ', 70).'Which option should I take?';
        $choices = [];
        for ($n = 1; $n <= 10; $n++) {
            // No trailing whitespace: choices are shown trimmed.
            $choices[] = "Option {$n}: ".rtrim(str_repeat("consideration {$n} ", 20));
        }

        $this->script()->toolRequest('ask_user', ['question' => $questionText, 'choices' => $choices]);

        $this->send('Plan the migration.', $conversation->id)->assertStatus(202);

        $this->assertCount(1, $this->telegram->deliveries, 'the whole question is handed over in one delivery; splitting is the adapter\'s job');
        $delivered = $this->telegram->deliveries[0]['text'];
        $this->assertGreaterThan(4096, mb_strlen($delivered), 'precondition: longer than one Telegram message');
        $this->assertStringContainsString($questionText, $delivered);
        foreach ($choices as $index => $choice) {
            $this->assertStringContainsString(($index + 1).'. '.$choice, $delivered, 'choice '.($index + 1).' is present');
        }
        $this->assertSame($delivered, Message::findOrFail($this->questionIn($conversation)->display_message_id)->content);
    }

    #[Test]
    public function reply_after_threshold_answers_existing_conversation(): void
    {
        $this->scenario = 'messaging_question_reply_after_threshold';
        $conversation = $this->telegramFixture();

        $this->script()
            ->toolRequest('ask_user', ['question' => AgentQuestionWebJourneyTest::QUESTION, 'choices' => AgentQuestionWebJourneyTest::CHOICES])
            ->finalAnswer('Frontend first.');

        $question = $this->askThreeChoiceQuestion($conversation);

        // Five hours pass: past the four-hour window in which a Telegram
        // message continues the latest conversation.
        DB::table('conversations')->where('id', $conversation->id)->update(['updated_at' => now()->subHours(5)]);

        $reply = $this->send('2');

        $reply->assertStatus(200);
        $reply->assertJson(['conversation_id' => $conversation->id, 'status' => 'completed', 'content' => 'Frontend first.']);
        $this->assertSame(1, Conversation::where('user_id', $conversation->user_id)->count(), 'no new conversation was started');

        $question->refresh();
        $this->assertSame(QuestionState::Answered, $question->state);
        $this->assertSame('2', $question->answer_raw);
        $this->assertSame([['number' => 2, 'text' => 'Frontend']], $question->answer_selected);
    }

    #[Test]
    public function undeliverable_question_resumes_agent(): void
    {
        $this->scenario = 'messaging_question_undeliverable';
        // The failed question and the failed follow-up notice are both
        // logged; nothing else may degrade.
        $this->ledger()->expect('AgentQuestionService:*');

        $conversation = $this->telegramFixture();
        $this->telegram->failWith('telegram account not linked');

        $this->script()
            ->toolRequest('ask_user', ['question' => AgentQuestionWebJourneyTest::QUESTION, 'choices' => AgentQuestionWebJourneyTest::CHOICES])
            ->finalAnswer('I could not reach you, so I will start with the backend.');

        $response = $this->send('Help me tidy up the project.', $conversation->id);

        // The run did not wait for an answer that cannot come: it was
        // resumed in the same request and its reply is the response.
        $response->assertStatus(200);
        $response->assertJson([
            'conversation_id' => $conversation->id,
            'status' => 'completed',
            'content' => 'I could not reach you, so I will start with the backend.',
        ]);

        $question = $this->questionIn($conversation);
        $this->assertSame(QuestionState::Undeliverable, $question->state, 'a failed delivery resolves the question');
        $this->assertSame('telegram account not linked', $question->delivery_failure_reason);
        $this->assertNull($question->delivered_at);
        $this->assertNull($question->pending_conversation_id, 'the conversation\'s question slot is free');
        $this->assertNotNull($question->resolved_at);
        $this->assertNotNull($question->resumed_at);

        $this->assertCount(2, $this->transport()->capturedChatPayloads(), 'the ask and exactly one continuation');
        $raw = $this->toolResultFor($this->chatPayloadMessages(1), $question->tool_call_id);
        $this->assertNotNull($raw, 'the continuation answers the ask_user call');
        $this->assertSame([
            'outcome' => 'undeliverable',
            'question_id' => $question->id,
            'message' => 'The question could not be delivered to the user: telegram account not linked. The user has not seen it.',
        ], json_decode($raw, true));

        // The question was attempted, then the follow-up notice was
        // attempted once; the reply went back in the HTTP response.
        $notice = 'The question "Which part should I change first?" could not be delivered (telegram account not linked). '
            .'The agent was told and will continue without an answer.';
        $this->assertSame(
            [AgentQuestionWebJourneyTest::GOLDEN_THREE_CHOICES, $notice],
            $this->telegram->textsFor($conversation->id),
        );
        $notices = Message::where('conversation_id', $conversation->id)->get()
            ->filter(fn (Message $m) => ($m->tool_data['kind'] ?? null) === 'agent_question_notice')
            ->values();
        $this->assertCount(1, $notices);
        $this->assertSame($notice, $notices[0]->content);
        $this->assertSame('undeliverable', $notices[0]->tool_data['state'] ?? null);
        $this->assertSame('undeliverable', Message::findOrFail($question->display_message_id)->tool_data['state'] ?? null);

        $this->assertSame('completed', DB::table('agent_runs')->where('id', $question->run_id)->value('end_state'));
        $action = DB::table('agent_run_actions')->where('id', $question->action_id)->first();
        $this->assertSame('failure', $action->outcome);
        $this->assertSame('question undeliverable', $action->failure_reason);
        $this->assertFalse((bool) $conversation->fresh()->is_processing);

        // The freed slot accepts the next ask.
        $this->assertFalse(AgentQuestion::where('pending_conversation_id', $conversation->id)->exists());
    }
}
