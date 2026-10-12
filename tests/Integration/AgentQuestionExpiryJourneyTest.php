<?php

namespace Tests\Integration;

use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Events\ToolExecutionEvent;
use ClarionApp\LlmClient\Events\UpdateOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Integration\Harness\CapturedPayload;
use Tests\Integration\Harness\RecordingChannelDelivery;

/**
 * An unanswered question expires and the agent is told, end to end, on
 * both channels.
 *
 * The question is asked with a short answerable period; time passes with
 * no answer and no process holding the wait; the every-minute sweep marks
 * it expired, tells the user on the conversation's channel and queues the
 * resume; a worker resumes the run, whose `ask_user` call receives the
 * unambiguous `expired` outcome. A reply sent after that is an ordinary new
 * message — it is never applied to the expired question.
 *
 * Real wiring throughout, with the model scripted at the streaming and HTTP
 * boundaries and a recording adapter standing in for the Telegram chat.
 */
class AgentQuestionExpiryJourneyTest extends AssembledSystemTestCase
{
    private const MESSAGE_ENDPOINT = '/api/clarion-app/llm-client/message';

    private const AGENT_ENDPOINT = '/api/clarion-app/llm-client/agent';

    private const SWEEP = 'llm-client:resolve-expired-questions';

    private const EXPIRY_MINUTES = 2;

    private const EXPIRED_NOTICE = 'The question "Which part should I change first?" expired without an answer. '
        .'The agent will continue without it; replies sent now are treated as new messages.';

    /**
     * The three-choice question exactly as shown when asked at the frozen
     * start (see setUp()) with this file's two-minute period.
     */
    private const ASKED_QUESTION = AgentQuestionWebJourneyTest::GOLDEN_THREE_CHOICES."\n\n"
        .'If there is no answer by 09:02 UTC on 12 Oct 2026 (2 minutes after this was asked), '
        .'work on this task will pause until you reply.';

    private RecordingChannelDelivery $telegram;

    protected function setUp(): void
    {
        parent::setUp();

        // Frozen, so the deadline every question states is known exactly.
        $this->travelTo(Carbon::parse(AgentQuestionWebJourneyTest::ASKED_AT, 'UTC'));

        config(['llm-client.run_trace.enabled' => true]);
        config(['llm-client.conversation.inactivity_threshold_hours' => 4]);
        config(['llm-client.agent_questions.expiry_minutes' => self::EXPIRY_MINUTES]);
        // The period under test is shorter than the shipped minimum.
        config(['llm-client.agent_questions.min_expiry_minutes' => self::EXPIRY_MINUTES]);

        // Resume jobs are held on the fake queue, as a real queue holds them
        // until a worker picks them up.
        Queue::fake([SendHttpStreamRequest::class, ResumeAfterQuestionJob::class]);

        Event::fake([
            NewConversationMessageEvent::class,
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            ToolExecutionEvent::class,
        ]);

        $this->telegram = new RecordingChannelDelivery('telegram');
        $this->app->make(ConversationChannelRegistry::class)->register($this->telegram);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /** @return list<string> SSE chunks for one streamed model turn */
    private function turnChunks(string $text, array $calls): array
    {
        $chunks = [];
        if ($text !== '') {
            $chunks[] = 'data: '.json_encode(['choices' => [['delta' => ['content' => $text], 'finish_reason' => null]]])."\n\n";
        }
        foreach (array_values($calls) as $index => $call) {
            $chunks[] = 'data: '.json_encode(['choices' => [[
                'delta' => ['tool_calls' => [[
                    'index' => $index,
                    'id' => $call['id'],
                    'type' => 'function',
                    'function' => ['name' => $call['name'], 'arguments' => $call['arguments']],
                ]]],
                'finish_reason' => null,
            ]]])."\n\n";
        }
        $chunks[] = 'data: '.json_encode(['choices' => [['delta' => [], 'finish_reason' => $calls === [] ? 'stop' : 'tool_calls']]])."\n\n";

        return $chunks;
    }

    private function playTurn(int $slot, string $text, array $calls = []): void
    {
        $stream = $this->stream();
        $stream->extractDispatchedJobs();
        $this->assertGreaterThan($slot, $stream->turnCount(), "a streaming request #{$slot} must have been dispatched");
        $stream->setCurrentSlot($slot);
        $stream->emit($this->turnChunks($text, $calls));
        $stream->finish();
    }

    private function turnCount(): int
    {
        return $this->stream()->extractDispatchedJobs()->turnCount();
    }

    /** @return list<array<string, mixed>> */
    private function streamPayloadMessages(int $slot): array
    {
        $payloads = $this->stream()->extractDispatchedJobs()->capturedPayloads();
        $this->assertArrayHasKey($slot, $payloads, "no streaming request #{$slot} was dispatched");

        /** @var CapturedPayload $payload */
        $payload = $payloads[$slot];

        return json_decode(json_encode($payload->messages), true);
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

    private function questionIn(Conversation $conversation): AgentQuestion
    {
        $question = AgentQuestion::where('conversation_id', $conversation->id)->latest('asked_at')->first();
        $this->assertNotNull($question, 'the agent\'s ask must have opened a question');

        return $question;
    }

    private function sweep(): void
    {
        $this->assertSame(0, Artisan::call(self::SWEEP), 'the sweep succeeds');
    }

    /** @return list<ResumeAfterQuestionJob> */
    private function queuedResumeJobs(): array
    {
        return Queue::pushed(ResumeAfterQuestionJob::class)->values()->all();
    }

    /** Run a queued job the way a worker does: through the container. */
    private function work(ResumeAfterQuestionJob $job): void
    {
        $this->app->call([$job, 'handle']);
    }

    /** The exact `expired` result the agent receives for $question. */
    private function expiredResult(AgentQuestion $question): array
    {
        return [
            'outcome' => 'expired',
            'question_id' => $question->id,
            'message' => 'The question expired without an answer after '.self::EXPIRY_MINUTES.' minutes. '
                .'No answer will arrive. Decide how to proceed.',
        ];
    }

    /** The question expired, its slot free, and nothing about it answered. */
    private function assertExpiredAndUnanswered(AgentQuestion $question): void
    {
        $fresh = AgentQuestion::findOrFail($question->id);
        $this->assertSame(QuestionState::Expired, $fresh->state);
        $this->assertNull($fresh->pending_conversation_id);
        $this->assertNull($fresh->answer_raw, 'nothing was ever applied to the expired question');
        $this->assertNull($fresh->answer_message_id);
        $this->assertNull($fresh->answered_by);
    }

    /** No message in the payload repeats what was only shown to the user. */
    private function assertNoUserOnlyMessages(array $messages): void
    {
        foreach ($messages as $message) {
            $this->assertNotSame(self::EXPIRED_NOTICE, $message['content'] ?? null, 'the expiry notice is shown to the user, never sent to the model');
            $this->assertNotSame(self::ASKED_QUESTION, $message['content'] ?? null, 'the rendered question is never sent to the model');
        }
    }

    // ---------------------------------------------------------------
    // Web (streaming)
    // ---------------------------------------------------------------

    public function test_web_question_expires_the_agent_is_told_and_a_later_reply_is_ordinary(): void
    {
        $this->scenario = 'expiry_web_question';
        $this->entryPath = 'stream';

        $fixture = $this->fixture()->build();
        $user = $fixture->user;
        $conversation = $fixture->conversation;

        $this->actingAs($user, 'api')->postJson(self::MESSAGE_ENDPOINT, [
            'conversation_id' => $conversation->id,
            'content' => 'Help me tidy up the project.',
        ])->assertStatus(201);
        $this->playTurn(0, '', [[
            'id' => 'call_ask',
            'name' => 'ask_user',
            'arguments' => AgentQuestionWebJourneyTest::threeChoiceAskArguments(),
        ]]);
        $question = $this->questionIn($conversation);
        // A question asked before unanswered policies existed: it expires as it always did.
        DB::table('agent_questions')->where('id', $question->id)->update(['unanswered_policy' => null, 'effective_wait_minutes' => null]);
        $this->assertSame(QuestionState::Pending, $question->state);
        $this->assertSame(self::EXPIRY_MINUTES * 60, $question->expires_at->getTimestamp() - $question->asked_at->getTimestamp());

        // Nobody answers. Inside the period the sweep leaves it alone.
        $this->travel(self::EXPIRY_MINUTES * 60 - 30)->seconds();
        $this->sweep();
        $this->assertSame(QuestionState::Pending, $question->fresh()->state);
        $this->assertSame([], $this->queuedResumeJobs());

        // The period passes; the next tick expires it.
        $this->travel(60)->seconds();
        $this->sweep();

        $this->assertExpiredAndUnanswered($question);
        $this->assertSame(1, $this->turnCount(), 'the sweep does not continue the run itself');
        $jobs = $this->queuedResumeJobs();
        $this->assertCount(1, $jobs, 'one resume is queued for the expired question');

        // The user sees that the question expired, in the conversation.
        $listed = $this->actingAs($user, 'api')
            ->getJson("/api/clarion-app/llm-client/conversation/{$conversation->id}/message")
            ->assertStatus(200)
            ->json();
        $this->assertCount(1, array_filter($listed, fn (array $m) => ($m['id'] ?? null) === $question->display_message_id), 'the question is still listed');
        $notices = array_values(array_filter($listed, fn (array $m) => ($m['tool_data']['kind'] ?? null) === 'agent_question_notice'));
        $this->assertCount(1, $notices, 'the expiry notice is listed in the conversation');
        $this->assertSame(self::EXPIRED_NOTICE, $notices[0]['content']);
        $this->assertSame($question->id, $notices[0]['tool_data']['question_id'] ?? null);
        $this->assertSame('expired', $notices[0]['tool_data']['state'] ?? null);

        // A worker runs the resume: the agent is told, unambiguously.
        $this->work($jobs[0]);

        $this->assertSame(2, $this->turnCount(), 'the run resumes with exactly one continuation request');
        $continuation = $this->streamPayloadMessages(1);
        $raw = $this->toolResultFor($continuation, 'call_ask');
        $this->assertNotNull($raw, 'the continuation answers the ask_user call');
        $this->assertSame($this->expiredResult($question), json_decode($raw, true));
        $this->assertNoUserOnlyMessages($continuation);

        $this->assertSame(
            'expired',
            Message::findOrFail($question->display_message_id)->tool_data['state'] ?? null,
            'the question is shown with the outcome the agent received',
        );

        $this->playTurn(1, 'No answer came, so I will start with the backend.');

        $resumed = $question->fresh();
        $this->assertNotNull($resumed->resumed_at);
        $this->assertSame('completed', DB::table('agent_runs')->where('id', $question->run_id)->value('end_state'));
        $action = DB::table('agent_run_actions')->where('id', $question->action_id)->first();
        $this->assertSame('failure', $action->outcome);
        $this->assertSame('question expired', $action->failure_reason);
        $this->assertFalse((bool) $conversation->fresh()->is_processing);

        // A reply after the expiry is an ordinary new message.
        $reply = $this->actingAs($user, 'api')->postJson(self::MESSAGE_ENDPOINT, [
            'conversation_id' => $conversation->id,
            'content' => '2',
        ]);
        $reply->assertStatus(201);
        $this->assertNull($reply->json('tool_data'), 'the reply is stored as an ordinary message, not an answer');

        $this->assertExpiredAndUnanswered($question);
        $this->assertSame(3, $this->turnCount(), 'the reply starts ordinary new work');
        $last = $this->streamPayloadMessages(2);
        $this->assertSame(
            ['role' => 'user', 'content' => '2'],
            array_intersect_key(end($last), ['role' => 1, 'content' => 1]),
            'the reply reaches the model as an ordinary user message',
        );

        $this->playTurn(2, 'Noted.');
        $this->assertCount(1, $this->queuedResumeJobs(), 'nothing else was queued for the expired question');
    }

    // ---------------------------------------------------------------
    // Messaging (synchronous)
    // ---------------------------------------------------------------

    public function test_telegram_question_expires_the_agent_is_told_and_a_later_reply_is_ordinary(): void
    {
        $this->scenario = 'expiry_telegram_question';
        $this->entryPath = 'sync';

        $fixture = $this->fixture()->build();
        $user = $fixture->user;
        $conversation = $fixture->conversation;
        $conversation->update(['channel' => 'telegram']);

        $this->script()
            ->toolRequest('ask_user', ['question' => AgentQuestionWebJourneyTest::QUESTION, 'choices' => AgentQuestionWebJourneyTest::CHOICES])
            ->finalAnswer('No answer came, so I will start with the backend.')
            ->finalAnswer('Noted.');

        $this->actingAs($user, 'api')->postJson(self::AGENT_ENDPOINT, [
            'message' => 'Help me tidy up the project.',
            'channel' => 'telegram',
            'conversation_id' => $conversation->id,
        ])->assertStatus(202)->assertJson(['status' => 'awaiting_user']);

        $question = $this->questionIn($conversation);
        // A question asked before unanswered policies existed: it expires as it always did.
        DB::table('agent_questions')->where('id', $question->id)->update(['unanswered_policy' => null, 'effective_wait_minutes' => null]);
        $this->assertSame([self::ASKED_QUESTION], $this->telegram->textsFor($conversation->id));

        $this->travel(self::EXPIRY_MINUTES * 60 + 30)->seconds();
        $this->sweep();

        $this->assertExpiredAndUnanswered($question);
        $this->assertSame(
            [self::ASKED_QUESTION, self::EXPIRED_NOTICE],
            $this->telegram->textsFor($conversation->id),
            'the chat is told the question expired',
        );
        $this->assertCount(1, $this->transport()->capturedChatPayloads(), 'the sweep does not continue the run itself');
        $jobs = $this->queuedResumeJobs();
        $this->assertCount(1, $jobs);

        // A worker resumes the run. No HTTP request is waiting for its
        // reply, so the reply is pushed to the chat.
        $this->work($jobs[0]);

        $this->assertCount(2, $this->transport()->capturedChatPayloads(), 'exactly one continuation request');
        $continuation = $this->chatPayloadMessages(1);
        $raw = $this->toolResultFor($continuation, $question->tool_call_id);
        $this->assertNotNull($raw, 'the continuation answers the ask_user call');
        $this->assertSame($this->expiredResult($question), json_decode($raw, true));
        $this->assertNoUserOnlyMessages($continuation);

        $this->assertSame(
            [
                self::ASKED_QUESTION,
                self::EXPIRED_NOTICE,
                'No answer came, so I will start with the backend.',
            ],
            $this->telegram->textsFor($conversation->id),
        );
        $this->assertSame('completed', DB::table('agent_runs')->where('id', $question->run_id)->value('end_state'));
        $action = DB::table('agent_run_actions')->where('id', $question->action_id)->first();
        $this->assertSame('failure', $action->outcome);
        $this->assertSame('question expired', $action->failure_reason);
        $this->assertFalse((bool) $conversation->fresh()->is_processing);

        // A later reply in the chat (no conversation id, as Telegram sends
        // it) is an ordinary message in the same conversation.
        $reply = $this->actingAs($user, 'api')->postJson(self::AGENT_ENDPOINT, [
            'message' => '1',
            'channel' => 'telegram',
        ]);
        $reply->assertStatus(200)->assertJson([
            'conversation_id' => $conversation->id,
            'status' => 'completed',
            'content' => 'Noted.',
        ]);

        $this->assertExpiredAndUnanswered($question);
        $stored = Message::where('conversation_id', $conversation->id)->where('role', 'user')->where('content', '1')->get();
        $this->assertCount(1, $stored);
        $this->assertNull($stored[0]->tool_data, 'stored as an ordinary message, not an answer');
        $last = $this->chatPayloadMessages(2);
        $this->assertSame(
            ['role' => 'user', 'content' => '1'],
            array_intersect_key(end($last), ['role' => 1, 'content' => 1]),
            'the reply reaches the model as an ordinary user message',
        );
        $this->assertCount(3, $this->telegram->deliveries, 'the reply\'s response went back over HTTP, not through the adapter');
        $this->assertCount(1, $this->queuedResumeJobs());
    }
}
