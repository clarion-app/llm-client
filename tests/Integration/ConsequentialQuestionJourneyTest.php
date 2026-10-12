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
use ClarionApp\LlmClient\ValueObjects\UnansweredPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\CapturedPayload;
use Tests\Integration\Harness\RecordingChannelDelivery;
use Tests\Integration\Harness\RecordingRichChannelDelivery;

/**
 * A question with real-world consequences never proceeds on an assumed
 * answer, end to end.
 *
 * The agent is about to delete a batch of drafts and asks first, marking the
 * question consequential. Asked together with the proceed policy or any
 * assumed answer, the ask is refused with the reason, and nothing is shown
 * to the user on either channel. Asked properly — consequential, naming no
 * fallback — the question tells the user up front that work will not
 * continue without their answer, on the web and in the chat alike; and when
 * nobody answers by the deadline the work stops — no model call, no tool
 * call, nothing queued — the user is told, and only their late answer
 * resumes it.
 *
 * Real wiring throughout — controllers, the agent loop, the validator, the
 * question service, the sweep command and the resumer — with the model
 * scripted at the streaming and HTTP boundaries and recording adapters
 * standing in for both channels.
 */
class ConsequentialQuestionJourneyTest extends AssembledSystemTestCase
{
    private const API = '/api/clarion-app/llm-client';

    private const MESSAGE_ENDPOINT = self::API.'/message';

    private const AGENT_ENDPOINT = self::API.'/agent';

    private const SWEEP = 'llm-client:resolve-expired-questions';

    private const QUESTION = 'Should I delete the 214 stale drafts?';

    private const CHOICES = ['Delete them', 'Keep them'];

    private const WAIT_MINUTES = 60;

    /** The clock is frozen at each of these instants in turn. */
    private const ASKED_AT = '2026-10-12 09:00:00';

    private const JUST_BEFORE_DEADLINE = '2026-10-12 09:59:30';

    private const SWEPT_AT = '2026-10-12 10:01:00';

    private const ANSWERED_AT = '2026-10-12 11:30:00';

    private const DEADLINE_TEXT = '10:00 UTC on 12 Oct 2026 (1 hour after this was asked)';

    private const CONSEQUENTIAL_STATEMENT = 'Work on this task will not continue without your answer. '
        .'If there is no answer by '.self::DEADLINE_TEXT.', it will pause until you reply.';

    private const REFUSAL_REASON = 'a consequential question cannot proceed on an assumed answer; it always stops until the user answers';

    /** The question as typed replies answer it, before the statement. */
    private const PLAIN = "Should I delete the 214 stale drafts?\n"
        ."\n"
        ."1. Delete them\n"
        ."2. Keep them\n"
        ."\n"
        .'Reply with the number of your choice, or answer in your own words.';

    /** The question as shown alongside its choices as buttons, before the statement. */
    private const WITH_OPTIONS = "Should I delete the 214 stale drafts?\n"
        ."\n"
        ."1. Delete them\n"
        ."2. Keep them\n"
        ."\n"
        .'Choose an option below, or reply in your own words.';

    private const STOP_NOTICE = 'No answer to "'.self::QUESTION.'" was received by the deadline ('.self::DEADLINE_TEXT.'). '
        .'Work on this task has stopped. It will continue when you answer the question above.';

    private const ANSWERED_LATE_MESSAGE = 'The question expired without an answer after 60 minutes and work stopped. '
        .'The user answered later; continue with this answer. Nothing was done while the work was stopped.';

    private const FINAL_ANSWER = 'Keeping the drafts.';

    private RecordingChannelDelivery $web;

    private RecordingRichChannelDelivery $telegram;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::ASKED_AT, 'UTC'));

        config(['llm-client.run_trace.enabled' => true]);
        config(['llm-client.conversation.inactivity_threshold_hours' => 4]);

        // Resume jobs are held on the queue, so the test sees exactly what
        // the sweep queued (nothing, for a question that stops).
        Queue::fake([SendHttpStreamRequest::class, ResumeAfterQuestionJob::class]);

        Event::fake([
            NewConversationMessageEvent::class,
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            ToolExecutionEvent::class,
        ]);

        $this->web = new RecordingChannelDelivery('web');
        $this->telegram = new RecordingRichChannelDelivery('telegram');
        $registry = $this->app->make(ConversationChannelRegistry::class);
        $registry->register($this->web);
        $registry->register($this->telegram);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function at(string $instant): void
    {
        $this->travelTo(Carbon::parse($instant, 'UTC'));
    }

    private function sweep(): void
    {
        $this->assertSame(0, Artisan::call(self::SWEEP), 'the sweep succeeds');
    }

    private function ask(array $policyArguments): array
    {
        return ['question' => self::QUESTION, 'choices' => self::CHOICES] + $policyArguments;
    }

    /** A consequential question naming no fallback: the only way to ask one. */
    private function consequentialAsk(): array
    {
        return $this->ask(['consequential' => true, 'wait_minutes' => self::WAIT_MINUTES]);
    }

    private function telegramConversation(): Conversation
    {
        $fixture = $this->fixture()->build();
        $fixture->conversation->update(['channel' => 'telegram']);

        return $fixture->conversation->fresh();
    }

    private function telegramSend(string $message, ?string $conversationId = null): TestResponse
    {
        $body = ['message' => $message, 'channel' => 'telegram'];
        if ($conversationId !== null) {
            $body['conversation_id'] = $conversationId;
        }

        return $this->actingAs($this->fixture()->user, 'api')->postJson(self::AGENT_ENDPOINT, $body);
    }

    private function webReply(Conversation $conversation, string $content): TestResponse
    {
        return $this->actingAs($this->fixture()->user, 'api')->postJson(self::MESSAGE_ENDPOINT, [
            'conversation_id' => $conversation->id,
            'content' => $content,
        ]);
    }

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

    private function toolResultFor(array $messages, string $toolCallId): ?array
    {
        foreach ($messages as $message) {
            if (($message['role'] ?? null) === 'tool' && ($message['tool_call_id'] ?? null) === $toolCallId) {
                $content = $message['content'] ?? null;

                return json_decode(is_string($content) ? $content : json_encode($content), true);
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

    /** @return array<string, mixed> the question view on the question's display message, as its owner's screen loads it */
    private function questionView(AgentQuestion $question): array
    {
        $listed = $this->actingAs($this->fixture()->user, 'api')
            ->getJson(self::API."/conversation/{$question->conversation_id}/message")
            ->assertStatus(200)
            ->json();

        foreach ($listed as $message) {
            if (($message['id'] ?? null) === $question->display_message_id) {
                $this->assertIsArray($message['question'] ?? null, 'the display message carries a question view');

                return $message['question'];
            }
        }
        $this->fail('the question\'s display message is not listed');
    }

    /** @return list<array<string, mixed>> the notices listed in $conversation */
    private function listedNotices(Conversation $conversation): array
    {
        $listed = $this->actingAs($this->fixture()->user, 'api')
            ->getJson(self::API."/conversation/{$conversation->id}/message")
            ->assertStatus(200)
            ->json();

        return array_values(array_filter($listed, fn (array $m) => ($m['tool_data']['kind'] ?? null) === 'agent_question_notice'));
    }

    /** Stored as a consequential stop question, with no assumption, an hour from now. */
    private function assertAskedAsConsequential(AgentQuestion $question, string $resumeMode): void
    {
        $question = $question->fresh();
        $this->assertSame(QuestionState::Pending, $question->state);
        $this->assertSame($resumeMode, $question->resume_mode);
        $this->assertTrue($question->consequential, 'the question is stored as consequential');
        $this->assertSame(UnansweredPolicy::Stop, $question->unanswered_policy, 'a consequential question can only stop');
        $this->assertNull($question->assumed_answer_raw, 'it assumes nothing');
        $this->assertNull($question->assumed_answer_selected);
        $this->assertSame(self::WAIT_MINUTES, $question->effective_wait_minutes);
        $this->assertSame('2026-10-12 10:00:00', $question->expires_at->copy()->utc()->format('Y-m-d H:i:s'));
        $this->assertNotNull($question->delivered_at);
    }

    /** The stored question and its web view say, up front, that work will not continue without an answer. */
    private function assertSaidUpFront(AgentQuestion $question): void
    {
        $this->assertSame(
            self::PLAIN."\n\n".self::CONSEQUENTIAL_STATEMENT,
            Message::findOrFail($question->display_message_id)->content,
            'the stored question ends with the consequential statement',
        );

        $view = $this->questionView($question);
        $this->assertSame('pending', $view['state']);
        $this->assertTrue($view['answerable']);
        $this->assertSame('stop', $view['if_unanswered']);
        $this->assertTrue($view['consequential']);
        $this->assertNull($view['assumed_answer']);
        $this->assertSame(self::CONSEQUENTIAL_STATEMENT, $view['expiry_statement'], 'the web screen shows the same statement');
    }

    /** What the model, the queue and the question have seen so far, to prove nothing more happens. */
    private function activity(): array
    {
        return [
            'model_requests' => count($this->transport()->capturedChatPayloads()),
            'stream_requests' => $this->turnCount(),
            'queued_resumes' => Queue::pushed(ResumeAfterQuestionJob::class)->count(),
        ];
    }

    /**
     * Nobody answers. Just before the deadline the sweep leaves it alone;
     * just after, the task stops — and nothing else happens.
     */
    private function letTheDeadlinePass(AgentQuestion $question): void
    {
        $this->at(self::JUST_BEFORE_DEADLINE);
        $this->sweep();
        $this->assertSame(QuestionState::Pending, $question->fresh()->state, 'precondition: still waiting inside its period');

        $before = $this->activity();

        $this->at(self::SWEPT_AT);
        $this->sweep();

        $stopped = $question->fresh();
        $this->assertSame(QuestionState::Stopped, $stopped->state, 'an unanswered consequential question stops the task');
        $this->assertSame($question->conversation_id, $stopped->pending_conversation_id, 'it keeps the conversation\'s question slot');
        $this->assertSame('2026-10-12 10:01:00', $stopped->stopped_at?->copy()->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($stopped->resolved_at, 'nothing was decided for the user');
        $this->assertNull($stopped->resumed_at);
        $this->assertNull($stopped->answer_raw);
        $this->assertSame($before, $this->activity(), 'no model call, no tool call, nothing queued');

        $conversation = Conversation::findOrFail($question->conversation_id);
        $this->assertFalse((bool) $conversation->is_processing);

        $notices = $this->listedNotices($conversation);
        $this->assertCount(1, $notices, 'the stop notice is listed in the conversation, once');
        $this->assertSame(self::STOP_NOTICE, $notices[0]['content']);
        $this->assertSame(
            ['kind' => 'agent_question_notice', 'question_id' => $question->id, 'state' => 'stopped'],
            array_intersect_key($notices[0]['tool_data'], ['kind' => 1, 'question_id' => 1, 'state' => 1]),
        );

        $run = $this->actingAs($this->fixture()->user, 'api')
            ->getJson(self::API."/agent-runs/{$question->run_id}")
            ->assertStatus(200)
            ->json();
        $this->assertSame('in_progress', $run['end_state'], 'the run waits on its user');
        $this->assertTrue($run['awaiting_user']);

        $view = $this->questionView($question);
        $this->assertSame('stopped', $view['state']);
        $this->assertTrue($view['answerable'], 'only the user\'s answer can continue it');
    }

    /** The exact result the agent receives for a late answer of choice 2. */
    private function answeredLate(AgentQuestion $question): array
    {
        return [
            'outcome' => 'answered_late',
            'question_id' => $question->id,
            'waiting_period_minutes' => self::WAIT_MINUTES,
            'stopped_at' => '2026-10-12T10:01:00Z',
            'answered_at' => '2026-10-12T11:30:00Z',
            'answer' => ['raw_reply' => '2', 'is_selection' => true, 'selected' => [['number' => 2, 'text' => 'Keep them']]],
            'message' => self::ANSWERED_LATE_MESSAGE,
        ];
    }

    private function assertResumedByTheUsersAnswer(AgentQuestion $question): void
    {
        $question = $question->fresh();
        $this->assertSame(QuestionState::Answered, $question->state);
        $this->assertNotNull($question->stopped_at, 'answered after it stopped');
        $this->assertSame('2', $question->answer_raw, 'the user\'s own answer');
        $this->assertNotNull($question->resumed_at);
        $this->assertSame('completed', DB::table('agent_runs')->where('id', $question->run_id)->value('end_state'));
        $this->assertSame('success', DB::table('agent_run_actions')->where('id', $question->action_id)->value('outcome'));
    }

    // ---------------------------------------------------------------
    // A consequential ask with an assumption is refused, on both channels
    // ---------------------------------------------------------------

    /** @return array<string, array{array<string, mixed>}> */
    public static function consequentialAsksWithAnAssumption(): array
    {
        return [
            'the proceed policy and an assumed choice' => [
                ['consequential' => true, 'if_unanswered' => 'proceed_on_assumption', 'assumed_choices' => [1]],
            ],
            'only an assumed text' => [
                ['consequential' => true, 'assumed_text' => 'Delete them'],
            ],
            'the proceed policy and no assumption' => [
                ['consequential' => true, 'if_unanswered' => 'proceed_on_assumption'],
            ],
        ];
    }

    #[Test]
    #[DataProvider('consequentialAsksWithAnAssumption')]
    public function in_the_chat_a_consequential_ask_with_an_assumption_is_refused_and_nothing_is_shown(array $policyArguments): void
    {
        $this->scenario = 'consequential_ask_refused_chat';
        $this->entryPath = 'sync';
        $conversation = $this->telegramConversation();

        $this->script()
            ->toolRequest('ask_user', $this->ask($policyArguments))
            ->finalAnswer('I will not delete anything without asking properly.');

        $this->telegramSend('Clean up my drafts.', $conversation->id)
            ->assertStatus(200)
            ->assertJson(['status' => 'completed']);

        $result = $this->toolResultFor($this->chatPayloadMessages(1), $this->askCallIdIn($this->chatPayloadMessages(1)));
        $this->assertSame(
            ['outcome' => 'refused', 'code' => 'invalid_question', 'reason' => self::REFUSAL_REASON],
            $result,
            'the agent is told exactly why, in the next request of the same run',
        );
        $this->assertSame(0, AgentQuestion::count(), 'no question is written');
        $this->assertSame([], $this->telegram->presentationsFor($conversation->id), 'nothing is shown in the chat');
        $this->assertSame([], $this->telegram->textsFor($conversation->id), 'nothing is sent as text either');
        $this->assertSame([], $this->web->deliveries, 'nothing is delivered on the web');
        $this->assertNoQuestionInConversation($conversation);

        $action = DB::table('agent_run_actions')->where('action_type', 'tool_invocation')->where('target', 'ask_user')->first(['outcome', 'failure_reason']);
        $this->assertNotNull($action, 'the refused ask is in the run record');
        $this->assertSame('failure', $action->outcome);
        $this->assertSame('ask refused: invalid_question', $action->failure_reason);
    }

    #[Test]
    #[DataProvider('consequentialAsksWithAnAssumption')]
    public function on_the_web_a_consequential_ask_with_an_assumption_is_refused_and_nothing_is_shown(array $policyArguments): void
    {
        $this->scenario = 'consequential_ask_refused_web';
        $this->entryPath = 'stream';
        $conversation = $this->fixture()->build()->conversation;

        $this->webReply($conversation, 'Clean up my drafts.')->assertStatus(201);
        $this->playTurn(0, '', [['id' => 'call_ask', 'name' => 'ask_user', 'arguments' => json_encode($this->ask($policyArguments))]]);

        $this->assertSame(2, $this->turnCount(), 'the refusal goes straight back to the model: the run does not wait');
        $this->assertSame(
            ['outcome' => 'refused', 'code' => 'invalid_question', 'reason' => self::REFUSAL_REASON],
            $this->toolResultFor($this->streamPayloadMessages(1), 'call_ask'),
        );
        $this->playTurn(1, 'I will not delete anything without asking properly.');

        $this->assertSame(0, AgentQuestion::count(), 'no question is written');
        $this->assertSame([], $this->web->deliveries, 'nothing is delivered on the web');
        $this->assertSame([], $this->telegram->deliveries, 'nothing is delivered on the messaging channel');
        $this->assertSame([], $this->telegram->presentations, 'nothing is shown in a chat');
        $this->assertNoQuestionInConversation($conversation);
    }

    private function askCallIdIn(array $messages): string
    {
        foreach ($messages as $message) {
            foreach ((array) ($message['tool_calls'] ?? []) as $call) {
                if (($call['function']['name'] ?? null) === 'ask_user') {
                    return (string) ($call['id'] ?? '');
                }
            }
        }
        $this->fail('the payload carries no ask_user call');
    }

    private function assertNoQuestionInConversation(Conversation $conversation): void
    {
        $listed = $this->actingAs($this->fixture()->user, 'api')
            ->getJson(self::API."/conversation/{$conversation->id}/message")
            ->assertStatus(200)
            ->json();
        $this->assertSame(
            [],
            array_values(array_filter($listed, fn (array $m) => isset($m['question'])
                || in_array($m['tool_data']['kind'] ?? null, ['agent_question', 'agent_question_notice'], true))),
            'no question or notice is listed in the conversation',
        );
    }

    // ---------------------------------------------------------------
    // Asked properly, it says so up front and only ever stops
    // ---------------------------------------------------------------

    #[Test]
    public function on_the_web_a_consequential_question_says_work_will_not_continue_without_an_answer_and_stops(): void
    {
        $this->scenario = 'consequential_question_web_stream';
        $this->entryPath = 'stream';
        $conversation = $this->fixture()->build()->conversation;

        $this->webReply($conversation, 'Clean up my drafts.')->assertStatus(201);
        $this->playTurn(0, '', [['id' => 'call_ask', 'name' => 'ask_user', 'arguments' => json_encode($this->consequentialAsk())]]);
        $this->assertSame(1, $this->turnCount(), 'precondition: the run waits for the user');

        $question = $this->questionIn($conversation);
        $this->assertAskedAsConsequential($question, 'stream');

        // Said up front.
        $this->assertSaidUpFront($question);
        $this->assertSame([self::PLAIN."\n\n".self::CONSEQUENTIAL_STATEMENT], $this->web->textsFor($conversation->id), 'delivered with the statement');

        // Nobody answers: the work stops, and the user is told.
        $this->letTheDeadlinePass($question);
        $this->assertSame(
            [self::PLAIN."\n\n".self::CONSEQUENTIAL_STATEMENT, self::STOP_NOTICE],
            $this->web->textsFor($conversation->id),
            'the stop notice is delivered on the web, once',
        );

        // Hours later, nothing has moved on its own.
        $this->travel(3)->hours();
        $before = $this->activity();
        $this->sweep();
        $this->assertSame($before, $this->activity());
        $this->assertSame(QuestionState::Stopped, $question->fresh()->state);

        // Only the user's own answer continues it.
        $this->at(self::ANSWERED_AT);
        $this->webReply($conversation, '2')->assertStatus(201);
        $this->assertSame(2, $this->turnCount(), 'the late answer resumes the run with exactly one continuation request');
        $this->assertSame($this->answeredLate($question), $this->toolResultFor($this->streamPayloadMessages(1), 'call_ask'));
        $this->playTurn(1, self::FINAL_ANSWER);

        $this->assertResumedByTheUsersAnswer($question);
    }

    #[Test]
    public function in_the_chat_a_consequential_question_says_work_will_not_continue_without_an_answer_and_stops(): void
    {
        $this->scenario = 'consequential_question_chat_sync';
        $this->entryPath = 'sync';
        $conversation = $this->telegramConversation();

        $this->script()
            ->toolRequest('ask_user', $this->consequentialAsk())
            ->finalAnswer(self::FINAL_ANSWER);

        $this->telegramSend('Clean up my drafts.', $conversation->id)
            ->assertStatus(202)
            ->assertJson(['status' => 'awaiting_user']);
        $this->assertCount(1, $this->transport()->capturedChatPayloads(), 'precondition: the run waits for the user');

        $question = $this->questionIn($conversation);
        $this->assertAskedAsConsequential($question, 'sync');

        // Said up front: in the stored text, the buttons' message and its plain fallback.
        $this->assertSaidUpFront($question);
        $presentations = $this->telegram->presentationsFor($conversation->id);
        $this->assertCount(1, $presentations, 'the question is shown in the chat with its choices as buttons');
        $this->assertSame(self::WITH_OPTIONS."\n\n".self::CONSEQUENTIAL_STATEMENT, $presentations[0]->bodyText);
        $this->assertSame(self::PLAIN."\n\n".self::CONSEQUENTIAL_STATEMENT, $presentations[0]->plainText);

        // Nobody answers: the work stops, and the chat is told.
        $this->letTheDeadlinePass($question);
        $this->assertSame([self::STOP_NOTICE], $this->telegram->textsFor($conversation->id), 'the chat is told, once');
        $this->assertSame([], $this->web->deliveries, 'nothing went to the web');

        $resolutions = $this->telegram->resolutionsFor($question->id);
        $this->assertCount(1, $resolutions, 'the chat\'s buttons are refreshed once for the stop');
        $this->assertSame(QuestionState::Stopped, $resolutions[0]->state);
        $this->assertTrue($resolutions[0]->answerable, 'the buttons stay: only the user\'s answer can continue the work');

        // Only the user's own answer continues it — typed, as Telegram sends it.
        $this->at(self::ANSWERED_AT);
        $this->telegramSend('2')
            ->assertStatus(200)
            ->assertJson(['conversation_id' => $conversation->id, 'status' => 'completed', 'content' => self::FINAL_ANSWER]);
        $this->assertCount(2, $this->transport()->capturedChatPayloads(), 'exactly one continuation request');
        $this->assertSame($this->answeredLate($question), $this->toolResultFor($this->chatPayloadMessages(1), (string) $question->tool_call_id));

        $this->assertResumedByTheUsersAnswer($question);
    }
}
