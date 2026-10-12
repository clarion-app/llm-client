<?php

namespace Tests\Integration;

use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\LlmClient\Contracts\InteractiveQuestionAnswers;
use ClarionApp\LlmClient\Events\ConversationEnded;
use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Events\ToolExecutionEvent;
use ClarionApp\LlmClient\Events\UpdateOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswerStatus;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use ClarionApp\LlmClient\ValueObjects\UnansweredPolicy;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\CapturedPayload;
use Tests\Integration\Harness\RecordingChannelDelivery;
use Tests\Integration\Harness\RecordingRichChannelDelivery;

/**
 * An answer that arrives after the deadline is never applied to a decision
 * already made, and the user is told plainly, end to end.
 *
 * The agent asks "Which test framework should I set up? 1. PHPUnit
 * 2. Pest", assuming choice 1 if there is no answer within an hour. Nobody
 * answers; the sweep expires it on its assumption and the agent goes on with
 * PHPUnit. Hours later the user answers anyway:
 *
 * - typed, on the web screen or in the chat: the reply is stored as an
 *   ordinary message and reaches the agent's next turn as one; the user is
 *   told once, on the channel they replied on, that it arrived after the
 *   deadline and what was assumed; a second reply is not told again;
 * - through the stale web form: refused with the question's state, naming
 *   the assumption; nothing is written or started;
 * - by tapping a stale option in the chat: nothing is applied; the channel
 *   is given the same description to acknowledge the tap with.
 *
 * In every case the question's recorded decision and the assumption the
 * agent proceeded with are unchanged, and the paused step is never resumed
 * a second time.
 *
 * A question whose work stopped at its deadline is different: it still
 * takes a late answer — but only one. Two late answers (typed and tapped)
 * resume the work exactly once, the other being handled as a reply to an
 * already-answered question; and a late answer and ending the conversation
 * have exactly one effect between them.
 *
 * Real wiring throughout — controllers, the agent loop, the question
 * service, the sweep command, the resumer and its job (worked the way a
 * queue worker does) — with the model scripted at the streaming and HTTP
 * boundaries and recording adapters standing in for both channels.
 */
class LateAnswerJourneyTest extends AssembledSystemTestCase
{
    private const API = '/api/clarion-app/llm-client';

    private const MESSAGE_ENDPOINT = self::API.'/message';

    private const AGENT_ENDPOINT = self::API.'/agent';

    private const SWEEP = 'llm-client:resolve-expired-questions';

    private const QUESTION = 'Which test framework should I set up?';

    private const CHOICES = ['PHPUnit', 'Pest'];

    private const WAIT_MINUTES = 60;

    private const ASKED_AT = '2026-10-12 09:00:00';

    private const SWEPT_AT = '2026-10-12 10:01:00';

    /** More than the four-hour inactivity threshold after the agent last worked in the conversation. */
    private const REPLIED_AT = '2026-10-12 16:30:00';

    private const DEADLINE_TEXT = '10:00 UTC on 12 Oct 2026 (1 hour after this was asked)';

    private const ASSUMED_NOTICE = 'No answer to "'.self::QUESTION.'" was received by the deadline ('.self::DEADLINE_TEXT.'). '
        .'The agent is continuing on the assumption it stated: 1. PHPUnit. '
        .'Replies sent now are treated as new messages and will not change that assumption.';

    private const ASSUMED_OUTCOME = 'Expired — no answer arrived in time; the agent continued assuming: 1. PHPUnit';

    private const LATE_REPLY_NOTICE = 'Your reply arrived after the question "'.self::QUESTION.'" had expired. '
        .'The agent had already continued assuming: 1. PHPUnit. Your reply is being handled as a new message.';

    private const STOP_NOTICE = 'No answer to "'.self::QUESTION.'" was received by the deadline ('.self::DEADLINE_TEXT.'). '
        .'Work on this task has stopped. It will continue when you answer the question above.';

    private const ANSWERED_LATE_OUTCOME = 'Answered after work stopped: 2. Pest';

    private const PROCEEDED = 'No answer came, so I am setting up PHPUnit.';

    private RecordingChannelDelivery $web;

    private RecordingRichChannelDelivery $telegram;

    /** @var array<int, true> resume jobs already worked, by object id */
    private array $worked = [];

    /** @var list<string> every error or warning logged */
    private array $problemsLogged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->at(self::ASKED_AT);

        config(['llm-client.run_trace.enabled' => true]);
        config(['llm-client.conversation.inactivity_threshold_hours' => 4]);

        // Resume jobs are held on the queue until a worker picks them up, so
        // the test sees exactly what each step queued.
        Queue::fake([SendHttpStreamRequest::class, ResumeAfterQuestionJob::class]);

        // Ending a conversation also captures its episodic memory through a
        // model call of its own; that capture is not what these journeys are
        // about, so its trigger is held back.
        Event::fake([
            NewConversationMessageEvent::class,
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            ToolExecutionEvent::class,
            ConversationEnded::class,
        ]);

        Event::listen(MessageLogged::class, function (MessageLogged $logged) {
            if (in_array($logged->level, ['error', 'warning', 'critical', 'alert', 'emergency'], true)) {
                $this->problemsLogged[] = $logged->level.': '.$logged->message;
            }
        });

        $this->web = new RecordingChannelDelivery('web');
        $this->telegram = new RecordingRichChannelDelivery('telegram');
        $registry = $this->app->make(ConversationChannelRegistry::class);
        $registry->register($this->web);
        $registry->register($this->telegram);
    }

    // ---------------------------------------------------------------
    // Time, the sweep and the worker
    // ---------------------------------------------------------------

    private function at(string $instant): void
    {
        $this->travelTo(Carbon::parse($instant, 'UTC'));
    }

    private function sweep(): void
    {
        $this->assertSame(0, Artisan::call(self::SWEEP), 'the sweep succeeds');
    }

    /** Work every queued resume the way a queue worker does. */
    private function workQueuedResumes(): int
    {
        $worked = 0;
        foreach (Queue::pushed(ResumeAfterQuestionJob::class)->all() as $job) {
            if (isset($this->worked[spl_object_id($job)])) {
                continue;
            }
            $this->worked[spl_object_id($job)] = true;
            $this->app->call([$job, 'handle']);
            $worked++;
        }

        return $worked;
    }

    // ---------------------------------------------------------------
    // The streaming path (web)
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

    private function askArguments(bool $assume): array
    {
        return ['question' => self::QUESTION, 'choices' => self::CHOICES, 'wait_minutes' => self::WAIT_MINUTES]
            + ($assume ? ['assumed_choices' => [1]] : []);
    }

    private function webReply(Conversation $conversation, string $content): TestResponse
    {
        return $this->actingAs($this->fixture()->user, 'api')->postJson(self::MESSAGE_ENDPOINT, [
            'conversation_id' => $conversation->id,
            'content' => $content,
        ]);
    }

    /**
     * On the web: the agent asks, assuming PHPUnit after an hour; nobody
     * answers; it proceeds on the assumption and finishes its turn.
     */
    private function proceededOnTheWeb(): AgentQuestion
    {
        $conversation = $this->fixture()->build()->conversation;

        $this->webReply($conversation, 'Set up the tests for this project.')->assertStatus(201);
        $this->playTurn(0, '', [['id' => 'call_ask', 'name' => 'ask_user', 'arguments' => json_encode($this->askArguments(true))]]);
        $question = $this->questionIn($conversation);

        $this->letItProceed($question);
        $this->assertSame(2, $this->turnCount(), 'precondition: one continuation request');
        $this->playTurn(1, self::PROCEEDED);
        $this->assertFalse((bool) $conversation->fresh()->is_processing, 'precondition: the run that proceeded has finished');

        return $question->fresh();
    }

    // ---------------------------------------------------------------
    // The synchronous path (chat)
    // ---------------------------------------------------------------

    private function telegramSend(string $message, ?string $conversationId = null): TestResponse
    {
        $body = ['message' => $message, 'channel' => 'telegram'];
        if ($conversationId !== null) {
            $body['conversation_id'] = $conversationId;
        }

        return $this->actingAs($this->fixture()->user, 'api')->postJson(self::AGENT_ENDPOINT, $body);
    }

    private function chatConversation(): Conversation
    {
        $fixture = $this->fixture()->build();
        $fixture->conversation->update(['channel' => 'telegram']);

        return $fixture->conversation->fresh();
    }

    /** In the chat: the agent asks; when $assume, it assumes PHPUnit after an hour. */
    private function askInTheChat(bool $assume): AgentQuestion
    {
        $conversation = $this->chatConversation();
        $this->script()->toolRequest('ask_user', $this->askArguments($assume));

        $this->telegramSend('Set up the tests for this project.', $conversation->id)
            ->assertStatus(202)
            ->assertJson(['status' => 'awaiting_user']);

        return $this->questionIn($conversation);
    }

    /** In the chat: nobody answers, and the agent proceeds on its assumption. */
    private function proceededInTheChat(): AgentQuestion
    {
        $question = $this->askInTheChat(true);
        $this->script()->finalAnswer(self::PROCEEDED);

        $this->letItProceed($question);
        $this->assertCount(2, $this->transport()->capturedChatPayloads(), 'precondition: one continuation request');
        $this->assertSame([self::ASSUMED_NOTICE, self::PROCEEDED], $this->telegram->textsFor($question->conversation_id), 'precondition: the chat was told, then got the reply');
        $this->assertFalse((bool) Conversation::findOrFail($question->conversation_id)->is_processing, 'precondition: finished');

        return $question->fresh();
    }

    // ---------------------------------------------------------------
    // Shared steps and checks
    // ---------------------------------------------------------------

    private function questionIn(Conversation $conversation): AgentQuestion
    {
        $question = AgentQuestion::where('conversation_id', $conversation->id)->latest('asked_at')->first();
        $this->assertNotNull($question, 'the agent\'s ask must have opened a question');
        $this->assertSame(QuestionState::Pending, $question->state);

        return $question;
    }

    /** Nobody answers; the sweep expires it on its assumption and the queued resume runs. */
    private function letItProceed(AgentQuestion $question): void
    {
        $this->assertSame(UnansweredPolicy::ProceedOnAssumption, $question->fresh()->unanswered_policy, 'precondition: asked to proceed');
        $this->at(self::SWEPT_AT);
        $this->sweep();
        $this->assertSame(QuestionState::ExpiredAssumed, $question->fresh()->state, 'precondition: proceeded on its assumption');
        $this->assertSame(1, $this->workQueuedResumes(), 'precondition: one resume');
    }

    /** Nobody answers; the sweep stops the work. */
    private function letItStop(AgentQuestion $question): void
    {
        $this->assertSame(UnansweredPolicy::Stop, $question->fresh()->unanswered_policy, 'precondition: asked to stop');
        $this->at(self::SWEPT_AT);
        $this->sweep();
        $this->assertSame(QuestionState::Stopped, $question->fresh()->state, 'precondition: work stopped');
    }

    /** @return array<string, mixed> the question's decision and its consumed paused turn, which no late answer may change */
    private function decided(AgentQuestion $question): array
    {
        return [
            'question' => (array) DB::table('agent_questions')->where('id', $question->id)->first([
                'state', 'unanswered_policy', 'assumed_answer_raw', 'assumed_answer_selected', 'answer_raw', 'answer_selected',
                'answer_method', 'answer_message_id', 'answered_by', 'pending_conversation_id', 'resolved_at', 'resumed_at',
            ]),
            'paused_turn' => DB::table('messages')->where('id', $question->paused_message_id)->value('tool_data'),
        ];
    }

    /** @return list<array<string, mixed>> the conversation as its owner's screen lists it */
    private function listed(string $conversationId): array
    {
        return $this->actingAs($this->fixture()->user, 'api')
            ->getJson(self::API."/conversation/{$conversationId}/message")
            ->assertStatus(200)
            ->json();
    }

    /** @return list<array<string, mixed>> */
    private function lateReplyNotices(string $conversationId): array
    {
        return array_values(array_filter($this->listed($conversationId), fn (array $m) => ($m['tool_data']['notice'] ?? null) === 'late_reply'));
    }

    /** The user was told, once, in the conversation, what had been assumed. */
    private function assertToldOnce(AgentQuestion $question): void
    {
        $notices = $this->lateReplyNotices($question->conversation_id);
        $this->assertCount(1, $notices, 'the late reply is noticed exactly once');
        $this->assertSame(self::LATE_REPLY_NOTICE, $notices[0]['content']);
        $this->assertSame('assistant', $notices[0]['role']);
        $this->assertSame($question->id, $notices[0]['tool_data']['question_id'] ?? null);
    }

    /** The last user message the model was sent, and that nothing it was sent is a notice. */
    private function assertReachesTheModelAsAMessage(array $messages, string $content): void
    {
        $users = array_values(array_filter($messages, fn (array $m) => ($m['role'] ?? null) === 'user'));
        $this->assertNotSame([], $users);
        $this->assertSame($content, end($users)['content'] ?? null, 'the reply reaches the agent as the user\'s newest message');
        foreach ($messages as $message) {
            $this->assertStringNotContainsString('Your reply arrived after the question', (string) json_encode($message['content'] ?? null), 'the notice is for the user only');
        }
    }

    /** @return array{messages: int, resumes: int, streams: int, chat_requests: int} */
    private function footprint(): array
    {
        return [
            'messages' => Message::count(),
            'resumes' => Queue::pushed(ResumeAfterQuestionJob::class)->count(),
            'streams' => $this->turnCount(),
            'chat_requests' => count($this->transport()->capturedChatPayloads()),
        ];
    }

    // ---------------------------------------------------------------
    // (a) A typed late reply after the agent proceeded
    // ---------------------------------------------------------------

    #[Test]
    public function a_reply_typed_on_the_web_after_the_agent_proceeded_is_told_once_and_handled_as_a_new_message(): void
    {
        $this->scenario = 'late_answer_typed_web';
        $this->entryPath = 'stream';

        $question = $this->proceededOnTheWeb();
        $conversation = Conversation::findOrFail($question->conversation_id);
        $decided = $this->decided($question);
        $this->web->deliveries = [];

        $this->at(self::REPLIED_AT);
        $reply = $this->webReply($conversation, '2');

        $reply->assertStatus(201);
        $this->assertNull($reply->json('tool_data'), 'an ordinary message, not an answer');
        $this->assertSame($decided, $this->decided($question), 'the decision and the assumption stand; the paused step is not resumed again');
        $this->assertToldOnce($question);
        $listed = $this->listed($conversation->id);
        $replyAt = array_search($reply->json('id'), array_column($listed, 'id'), true);
        $this->assertSame(self::LATE_REPLY_NOTICE, $listed[$replyAt + 1]['content'] ?? null, 'the notice follows the reply');
        $this->assertSame([self::LATE_REPLY_NOTICE], $this->web->textsFor($conversation->id), 'told on the web, where the reply was typed');

        // Handled as a new message: the agent's next turn is about it.
        $this->assertSame(3, $this->turnCount(), 'the reply starts one new turn');
        $this->assertReachesTheModelAsAMessage($this->streamPayloadMessages(2), '2');
        $this->playTurn(2, 'You would rather use Pest? I will switch.');

        // A second reply is not told again.
        $this->at('2026-10-12 16:35:00');
        $this->webReply($conversation, 'Yes, switch to Pest.')->assertStatus(201);
        $this->assertToldOnce($question);
        $this->assertSame([self::LATE_REPLY_NOTICE], $this->web->textsFor($conversation->id), 'nothing more is delivered');
        $this->assertSame(4, $this->turnCount());
        $this->assertReachesTheModelAsAMessage($this->streamPayloadMessages(3), 'Yes, switch to Pest.');
        $this->playTurn(3, 'Switched to Pest.');

        $this->assertSame($decided, $this->decided($question));
        $this->assertSame([], $this->problemsLogged);
    }

    #[Test]
    public function a_reply_typed_in_the_chat_after_the_agent_proceeded_is_told_once_and_handled_as_a_new_message(): void
    {
        $this->scenario = 'late_answer_typed_chat';
        $this->entryPath = 'sync';

        $question = $this->proceededInTheChat();
        $decided = $this->decided($question);

        // As Telegram sends it — no conversation id — long after the agent
        // last worked in the conversation.
        $this->at(self::REPLIED_AT);
        $this->script()->finalAnswer('You would rather use Pest? I will switch.');
        $this->telegramSend('2')
            ->assertStatus(200)
            ->assertJson(['conversation_id' => $question->conversation_id, 'status' => 'completed']);

        $this->assertSame($decided, $this->decided($question), 'the decision and the assumption stand; the paused step is not resumed again');
        $this->assertToldOnce($question);
        $this->assertSame(
            [self::ASSUMED_NOTICE, self::PROCEEDED, self::LATE_REPLY_NOTICE],
            $this->telegram->textsFor($question->conversation_id),
            'told in the chat, where the reply was typed',
        );
        $this->assertSame([], $this->web->deliveries, 'nothing goes to the web');
        $this->assertCount(3, $this->transport()->capturedChatPayloads(), 'the reply is handled as one new turn');
        $this->assertReachesTheModelAsAMessage($this->chatPayloadMessages(2), '2');
        $reply = Message::where('conversation_id', $question->conversation_id)->where('role', 'user')->where('content', '2')->first();
        $this->assertNotNull($reply);
        $this->assertNull($reply->tool_data, 'an ordinary message, not an answer');

        // A second reply is not told again.
        $this->at('2026-10-12 16:35:00');
        $this->script()->finalAnswer('Switched to Pest.');
        $this->telegramSend('Yes, switch to Pest.')
            ->assertStatus(200)
            ->assertJson(['conversation_id' => $question->conversation_id]);
        $this->assertToldOnce($question);
        $this->assertSame([self::ASSUMED_NOTICE, self::PROCEEDED, self::LATE_REPLY_NOTICE], $this->telegram->textsFor($question->conversation_id));

        $this->assertSame($decided, $this->decided($question));
        $this->assertSame([], $this->problemsLogged);
    }

    // ---------------------------------------------------------------
    // (b) The stale web form after the agent proceeded
    // ---------------------------------------------------------------

    #[Test]
    public function the_stale_web_form_after_the_agent_proceeded_is_refused_naming_the_assumption(): void
    {
        $this->scenario = 'late_answer_stale_form';
        $this->entryPath = 'stream';

        $question = $this->proceededOnTheWeb();
        $decided = $this->decided($question);
        $footprint = $this->footprint();
        $deliveries = $this->web->deliveries;

        $this->at(self::REPLIED_AT);
        $response = $this->actingAs($this->fixture()->user, 'api')
            ->postJson(self::API."/question/{$question->id}/answer", ['selected' => [2]]);

        $response->assertStatus(409);
        $this->assertSame('question_not_pending', $response->json('code'));
        $this->assertSame('expired_assumed', $response->json('state'));
        $this->assertFalse($response->json('question.answerable'));
        $this->assertSame(self::ASSUMED_OUTCOME, $response->json('question.outcome_text'), 'the form is told what was assumed');

        $this->assertSame($decided, $this->decided($question), 'nothing is applied');
        $this->assertSame($footprint, $this->footprint(), 'no message, no resume, no run');
        $this->assertSame($deliveries, $this->web->deliveries, 'nothing is delivered');
        $this->assertSame([], $this->lateReplyNotices($question->conversation_id), 'a form submission writes no message, not even a notice');
    }

    // ---------------------------------------------------------------
    // (c) A stale tap after the agent proceeded
    // ---------------------------------------------------------------

    #[Test]
    public function a_stale_tap_after_the_agent_proceeded_applies_nothing_and_is_described_with_the_assumption(): void
    {
        $this->scenario = 'late_answer_stale_tap';
        $this->entryPath = 'sync';

        $question = $this->proceededInTheChat();
        $decided = $this->decided($question);
        $footprint = $this->footprint();
        $chat = $this->telegram->textsFor($question->conversation_id);

        $this->at(self::REPLIED_AT);
        $outcome = $this->telegram->tap($question->id, (string) $this->fixture()->user->id, [2]);

        $this->assertSame(InteractiveAnswerStatus::NotPending, $outcome->status);
        $this->assertSame(QuestionState::ExpiredAssumed, $outcome->state);
        $this->assertSame(
            self::ASSUMED_OUTCOME,
            $this->app->make(InteractiveQuestionAnswers::class)->describeState($question->id, (string) $this->fixture()->user->id),
            'what the channel acknowledges the tap with names the assumption',
        );
        $this->assertSame($decided, $this->decided($question), 'nothing is applied');
        $this->assertSame($footprint, $this->footprint(), 'no message, no job, no model request');
        $this->assertSame($chat, $this->telegram->textsFor($question->conversation_id), 'nothing more is sent to the chat');
    }

    // ---------------------------------------------------------------
    // Stopped work: one late answer, one effect
    // ---------------------------------------------------------------

    #[Test]
    public function of_a_typed_late_answer_and_a_later_tap_only_the_first_resumes_the_stopped_work(): void
    {
        $this->scenario = 'late_answer_stopped_typed_then_tap';
        $this->entryPath = 'sync';

        $question = $this->askInTheChat(false);
        $this->letItStop($question);

        $this->at(self::REPLIED_AT);
        $this->script()->finalAnswer('Setting up Pest.');
        $this->telegramSend('2')
            ->assertStatus(200)
            ->assertJson(['conversation_id' => $question->conversation_id, 'status' => 'completed', 'content' => 'Setting up Pest.']);
        $answered = $question->fresh();
        $this->assertSame(QuestionState::Answered, $answered->state);
        $this->assertNotNull($answered->stopped_at, 'answered after work stopped');
        $this->assertSame('typed_reply', $answered->answer_method->value);
        $decided = $this->decided($question);
        $footprint = $this->footprint();

        $outcome = $this->telegram->tap($question->id, (string) $this->fixture()->user->id, [1]);

        $this->assertSame(InteractiveAnswerStatus::NotPending, $outcome->status, 'the tap is a late reply to an answered question');
        $this->assertSame(QuestionState::Answered, $outcome->state);
        $this->assertSame(
            self::ANSWERED_LATE_OUTCOME,
            $this->app->make(InteractiveQuestionAnswers::class)->describeState($question->id, (string) $this->fixture()->user->id),
        );
        $this->assertSame($decided, $this->decided($question), 'the first late answer stands');
        $this->assertSame($footprint, $this->footprint(), 'the work resumed once: no second resume, no message');
        $this->assertSame([self::STOP_NOTICE], $this->telegram->textsFor($question->conversation_id));
        $this->assertSame(1, DB::table('agent_runs')->where('conversation_id', $question->conversation_id)->count());
    }

    #[Test]
    public function of_a_tap_and_a_later_typed_late_answer_only_the_tap_resumes_the_stopped_work(): void
    {
        $this->scenario = 'late_answer_stopped_tap_then_typed';
        $this->entryPath = 'sync';

        $question = $this->askInTheChat(false);
        $this->letItStop($question);

        $this->at(self::REPLIED_AT);
        $this->script()->finalAnswer('Setting up Pest.');
        $outcome = $this->telegram->tap($question->id, (string) $this->fixture()->user->id, [2]);
        $this->assertSame(InteractiveAnswerStatus::Answered, $outcome->status);
        $this->assertSame([self::STOP_NOTICE, 'Setting up Pest.'], $this->telegram->textsFor($question->conversation_id), 'the tap resumed the work');
        $decided = $this->decided($question);
        $this->assertCount(2, $this->transport()->capturedChatPayloads());

        // The typed "1" arrives after: an ordinary message in the
        // conversation, handled as a new turn — never a second resume.
        $this->at('2026-10-12 16:31:00');
        $this->script()->finalAnswer('You also said PHPUnit; keeping Pest as answered.');
        $this->telegramSend('1')
            ->assertStatus(200)
            ->assertJson(['conversation_id' => $question->conversation_id, 'status' => 'completed']);

        $this->assertSame($decided, $this->decided($question), 'the tap\'s late answer stands');
        $typed = Message::where('conversation_id', $question->conversation_id)->where('role', 'user')->where('content', '1')->first();
        $this->assertNotNull($typed);
        $this->assertNull($typed->tool_data, 'an ordinary message');
        $this->assertCount(3, $this->transport()->capturedChatPayloads(), 'one new turn, no second continuation');
        $this->assertReachesTheModelAsAMessage($this->chatPayloadMessages(2), '1');
        $this->assertSame([], $this->lateReplyNotices($question->conversation_id), 'nothing was assumed, so there is nothing to tell');
        $this->assertCount(1, Queue::pushed(ResumeAfterQuestionJob::class), 'one resume in all');
    }

    #[Test]
    public function a_late_answer_then_ending_the_conversation_resumes_the_work_and_discards_nothing(): void
    {
        $this->scenario = 'late_answer_stopped_answer_then_end';
        $this->entryPath = 'sync';

        $question = $this->askInTheChat(false);
        $this->letItStop($question);

        $this->at(self::REPLIED_AT);
        $this->script()->finalAnswer('Setting up Pest.');
        $this->telegramSend('2')->assertStatus(200)->assertJson(['status' => 'completed']);
        $decided = $this->decided($question);

        $this->actingAs($this->fixture()->user, 'api')
            ->postJson(self::API."/conversation/{$question->conversation_id}/end")
            ->assertStatus(200);

        $this->assertSame($decided, $this->decided($question), 'the late answer stands');
        $this->assertSame(QuestionState::Answered, $question->fresh()->state);
        $this->assertSame('completed', DB::table('agent_runs')->where('id', $question->run_id)->value('end_state'), 'the resumed work completed; nothing was discarded');
        $this->assertSame([self::STOP_NOTICE], $this->telegram->textsFor($question->conversation_id), 'no discard notice');
    }

    #[Test]
    public function ending_the_conversation_then_a_late_answer_discards_the_work_and_the_answer_is_not_applied(): void
    {
        $this->scenario = 'late_answer_stopped_end_then_answer';
        $this->entryPath = 'sync';

        $question = $this->askInTheChat(false);
        $this->letItStop($question);

        $this->at(self::REPLIED_AT);
        $this->actingAs($this->fixture()->user, 'api')
            ->postJson(self::API."/conversation/{$question->conversation_id}/end")
            ->assertStatus(200);
        $discarded = $question->fresh();
        $this->assertSame(QuestionState::Withdrawn, $discarded->state, 'precondition: discarded');
        $this->assertSame('stopped_early', DB::table('agent_runs')->where('id', $question->run_id)->value('end_state'));
        $decided = $this->decided($question);
        $footprint = $this->footprint();

        $response = $this->actingAs($this->fixture()->user, 'api')
            ->postJson(self::API."/question/{$question->id}/answer", ['selected' => [2]]);

        $response->assertStatus(409);
        $this->assertSame('withdrawn', $response->json('state'));
        $this->assertSame($decided, $this->decided($question), 'the answer is not applied');
        $this->assertSame($footprint, $this->footprint(), 'nothing resumes, nothing is written');
        $this->assertSame(
            'Discarded — the conversation was ended or deleted while work was stopped.',
            $this->app->make(InteractiveQuestionAnswers::class)->describeState($question->id, (string) $this->fixture()->user->id),
        );
    }
}
