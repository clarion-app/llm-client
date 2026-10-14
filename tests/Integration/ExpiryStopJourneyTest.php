<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Jobs\AgentStreamStepJob;
use ClarionApp\LlmClient\AgentLoopStreamHandler;
use ClarionApp\LlmClient\Events\ConversationEnded;
use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Events\ToolExecutionEvent;
use ClarionApp\LlmClient\Events\UpdateOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\LlmClientServiceProvider;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswerStatus;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use ClarionApp\LlmClient\ValueObjects\UnansweredPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\CapturedPayload;
use Tests\Integration\Harness\OperationCatalogue;
use Tests\Integration\Harness\RecordingChannelDelivery;
use Tests\Integration\Harness\RecordingRichChannelDelivery;

/**
 * A question that must not be guessed stops the work when nobody answers,
 * and the user can pick it up again later, end to end.
 *
 * The agent saves a note (a state-changing operation whose every call is
 * counted), then asks a single-choice question naming no fallback and a
 * one-hour waiting period. The question tells the user, up front, that work
 * will pause if there is no answer by the deadline. The deadline passes:
 * the every-minute sweep stops the task — no model call, no tool call,
 * nothing queued — tells the user on the conversation's channel, and leaves
 * the run open and waiting on its user. Hours later the user answers "2" by
 * any of the four ways a question can be answered (typed on the web, typed
 * in the chat, the web form, a tap), across a restart too, and the task
 * resumes from where it paused: the agent is told the answer came after the
 * question expired, the run completes, and the note is never saved twice.
 * Ending or deleting the conversation instead discards the stopped task for
 * good. A blank reply is refused, and the stopped question still holds the
 * conversation's one question slot.
 *
 * Real wiring throughout — controllers, the agent loop, the question
 * service, the sweep command, the interactive-answer service, the resumer
 * and its job (worked the way a queue worker does) — with the model
 * scripted at the streaming and HTTP boundaries, the host API scripted at
 * its HTTP boundary, and recording adapters standing in for both channels.
 */
class ExpiryStopJourneyTest extends AssembledSystemTestCase
{
    private const API = '/api/clarion-app/llm-client';

    private const MESSAGE_ENDPOINT = self::API.'/message';

    private const AGENT_ENDPOINT = self::API.'/agent';

    private const SWEEP = 'llm-client:resolve-expired-questions';

    private const QUESTION = 'Which part should I change first?';

    private const CHOICES = ['Backend', 'Frontend', 'Docs'];

    private const WAIT_MINUTES = 60;

    /** The clock is frozen at each of these instants in turn. */
    private const ASKED_AT = '2026-10-12 09:00:00';

    private const JUST_BEFORE_DEADLINE = '2026-10-12 09:59:30';

    private const SWEPT_AT = '2026-10-12 10:01:00';

    private const ANSWERED_AT = '2026-10-12 11:30:00';

    private const DEADLINE_TEXT = '10:00 UTC on 12 Oct 2026 (1 hour after this was asked)';

    private const STOP_STATEMENT = 'If there is no answer by '.self::DEADLINE_TEXT.', work on this task will pause until you reply.';

    private const STOP_NOTICE = 'No answer to "'.self::QUESTION.'" was received by the deadline ('.self::DEADLINE_TEXT.'). '
        .'Work on this task has stopped. It will continue when you answer the question above.';

    private const DISCARD_NOTICE = 'The stopped task waiting on "'.self::QUESTION.'" was discarded because the conversation '
        .'was ended or deleted. It no longer needs a reply.';

    private const STOPPED_OUTCOME = 'Expired — work stopped. Answer to resume.';

    private const ANSWERED_LATE_MESSAGE = 'The question expired without an answer after 60 minutes and work stopped. '
        .'The user answered later; continue with this answer. Nothing was done while the work was stopped.';

    private const CHOICE_TWO = [['number' => 2, 'text' => 'Frontend']];

    private const FINAL_ANSWER = 'Starting with the frontend.';

    /** The question as shown alongside its choices as buttons, before the statement. */
    private const WITH_OPTIONS = "Which part should I change first?\n"
        ."\n"
        ."1. Backend\n"
        ."2. Frontend\n"
        ."3. Docs\n"
        ."\n"
        .'Choose an option below, or reply in your own words.';

    private RecordingChannelDelivery $web;

    private RecordingRichChannelDelivery $telegram;

    /** @var list<array<string, mixed>> every request the host API received to store a note */
    private array $noteWrites = [];

    /** @var array<int, true> resume jobs already worked, by object id */
    private array $worked = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::ASKED_AT, 'UTC'));

        config(['llm-client.run_trace.enabled' => true]);
        config(['llm-client.conversation.inactivity_threshold_hours' => 4]);

        // Resume jobs are held on the queue until a worker picks them up, so
        // the test sees exactly what each step queued.
        Queue::fake([AgentStreamStepJob::class, ResumeAfterQuestionJob::class]);

        // The host application's catalogue holds one state-changing
        // operation, and its API is scripted so each call to it is counted.
        // McpToolExecutor resolves API tokens via Passport in production; a
        // fixed token stands in here.
        $this->app->singleton(
            McpToolExecutor::class,
            fn () => new McpToolExecutor($this->app->make(McpToolRegistry::class), null, fn ($user) => 'test-token-'.$user->id),
        );
        $catalogue = new OperationCatalogue();
        $catalogue->seed([
            'openapi' => '3.0.0',
            'info' => ['title' => 'Test API', 'version' => '1.0.0'],
            'paths' => [
                '/notes' => [
                    'post' => [
                        'operationId' => 'storeNote',
                        'summary' => 'Store a note',
                        'parameters' => [],
                        'responses' => ['201' => ['description' => 'Created']],
                    ],
                ],
            ],
        ]);
        $catalogue->fakeHostApi([
            '/notes' => function ($request) {
                $this->noteWrites[] = $request->data();

                return Http::response(['stored' => true], 201, ['Content-Type' => 'application/json']);
            },
        ]);

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

        // Both channels are outside the process and outlive a restart: their
        // adapters are registered whenever the registry is built, as each
        // channel package's provider does on every boot.
        $this->web = new RecordingChannelDelivery('web');
        $this->telegram = new RecordingRichChannelDelivery('telegram');
        $registry = $this->app->make(ConversationChannelRegistry::class);
        $registry->register($this->web);
        $registry->register($this->telegram);
        $this->app->afterResolving(ConversationChannelRegistry::class, function (ConversationChannelRegistry $registry) {
            $registry->register($this->web);
            $registry->register($this->telegram);
        });
    }

    protected function tearDown(): void
    {
        (new OperationCatalogue())->reset();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Time
    // ---------------------------------------------------------------

    private function at(string $instant): void
    {
        $this->travelTo(Carbon::parse($instant, 'UTC'));
    }

    private function sweep(): void
    {
        $this->assertSame(0, Artisan::call(self::SWEEP), 'the sweep succeeds');
    }

    // ---------------------------------------------------------------
    // The web conversation (streaming)
    // ---------------------------------------------------------------

    private function webReply(Conversation $conversation, string $content): TestResponse
    {
        return $this->actingAs($this->fixture()->user, 'api')->postJson(self::MESSAGE_ENDPOINT, [
            'conversation_id' => $conversation->id,
            'content' => $content,
        ]);
    }

    private function submitForm(AgentQuestion $question, array $body): TestResponse
    {
        return $this->actingAs($this->fixture()->user, 'api')
            ->postJson(self::API."/question/{$question->id}/answer", $body);
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

    private function noteCall(): array
    {
        return [
            'id' => 'call_note',
            'name' => 'execute_operation',
            'arguments' => json_encode(['operationId' => 'storeNote', 'parameters' => ['body' => ['text' => 'PRE-ASK-NOTE-7731']]]),
        ];
    }

    private function askArguments(): array
    {
        // No fallback named: a question that must not be guessed.
        return ['question' => self::QUESTION, 'choices' => self::CHOICES, 'wait_minutes' => self::WAIT_MINUTES];
    }

    /**
     * On the web: in one turn the agent saves a note and asks, and the run
     * waits on the user.
     */
    private function askOnTheWeb(): AgentQuestion
    {
        $conversation = $this->fixture()->build()->conversation;

        $this->webReply($conversation, 'Save a note, then ask me which part to change.')->assertStatus(201);
        $this->playTurn(0, '', [
            $this->noteCall(),
            ['id' => 'call_ask', 'name' => 'ask_user', 'arguments' => json_encode($this->askArguments())],
        ]);
        $this->assertCount(1, $this->noteWrites, 'precondition: the state-changing call ran before the pause');
        $this->assertSame(1, $this->turnCount(), 'precondition: the run waits for the user, no further model request');

        $question = $this->questionIn($conversation);
        $this->assertAskedAsAStopQuestion($question, 'stream');
        $this->assertCount(1, $this->web->textsFor($conversation->id), 'precondition: the question was delivered on the web');

        return $question;
    }

    // ---------------------------------------------------------------
    // The messaging conversation (synchronous)
    // ---------------------------------------------------------------

    private function telegramSend(string $message, ?string $conversationId = null): TestResponse
    {
        $body = ['message' => $message, 'channel' => 'telegram'];
        if ($conversationId !== null) {
            $body['conversation_id'] = $conversationId;
        }

        return $this->actingAs($this->fixture()->user, 'api')->postJson(self::AGENT_ENDPOINT, $body);
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

    /**
     * In the chat: in one turn the agent saves a note and asks, and the run
     * waits on the user. When $continues, the model finishes with FINAL_ANSWER once
     * the run continues.
     */
    private function askInTheChat(bool $continues = true): AgentQuestion
    {
        $fixture = $this->fixture()->build();
        $fixture->conversation->update(['channel' => 'telegram']);
        $conversation = $fixture->conversation->fresh();

        $this->script()->toolRequests([
            ['id' => 'call_note', 'name' => 'execute_operation', 'arguments' => ['operationId' => 'storeNote', 'parameters' => ['body' => ['text' => 'PRE-ASK-NOTE-7731']]]],
            ['id' => 'call_ask', 'name' => 'ask_user', 'arguments' => $this->askArguments()],
        ]);
        if ($continues) {
            $this->script()->finalAnswer(self::FINAL_ANSWER);
        }

        $this->telegramSend('Save a note, then ask me which part to change.', $conversation->id)
            ->assertStatus(202)
            ->assertJson(['status' => 'awaiting_user']);

        $this->assertCount(1, $this->noteWrites, 'precondition: the state-changing call ran before the pause');
        $this->assertCount(1, $this->transport()->capturedChatPayloads(), 'precondition: the run waits for the user, no further model request');

        $question = $this->questionIn($conversation);
        $this->assertAskedAsAStopQuestion($question, 'sync');
        $this->assertCount(1, $this->telegram->presentationsFor($conversation->id), 'precondition: the question was presented in the chat');
        $this->assertSame([], $this->telegram->textsFor($conversation->id), 'precondition: nothing was sent as plain text');

        return $question;
    }

    // ---------------------------------------------------------------
    // Shared checks
    // ---------------------------------------------------------------

    private function questionIn(Conversation $conversation): AgentQuestion
    {
        $question = AgentQuestion::where('conversation_id', $conversation->id)->latest('asked_at')->first();
        $this->assertNotNull($question, 'the agent\'s ask must have opened a question');

        return $question;
    }

    /** Asked with no fallback: it stops when unanswered, an hour from now. */
    private function assertAskedAsAStopQuestion(AgentQuestion $question, string $resumeMode): void
    {
        $question = $question->fresh();
        $this->assertSame(QuestionState::Pending, $question->state);
        $this->assertSame($resumeMode, $question->resume_mode);
        $this->assertSame(UnansweredPolicy::Stop, $question->unanswered_policy, 'naming no fallback means stop');
        $this->assertSame(self::WAIT_MINUTES, $question->requested_wait_minutes);
        $this->assertSame(self::WAIT_MINUTES, $question->effective_wait_minutes);
        $this->assertSame('2026-10-12 09:00:00', $question->asked_at->copy()->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-12 10:00:00', $question->expires_at->copy()->utc()->format('Y-m-d H:i:s'));
        $this->assertNotNull($question->delivered_at);
    }

    /** What the model and the channels have seen so far, to prove nothing more happens. */
    private function activity(): array
    {
        return [
            'model_requests' => count($this->transport()->capturedChatPayloads()),
            'stream_requests' => $this->turnCount(),
            'note_writes' => count($this->noteWrites),
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
        $this->assertSame(QuestionState::Stopped, $stopped->state, 'an unanswered stop question stops the task at its deadline');
        $this->assertSame($question->conversation_id, $stopped->pending_conversation_id, 'the stopped question keeps the conversation\'s question slot');
        $this->assertSame('2026-10-12 10:01:00', $stopped->stopped_at?->copy()->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($stopped->resolved_at, 'stopping is not a resolution');
        $this->assertNull($stopped->resumed_at);
        $this->assertSame($before, $this->activity(), 'no model call, no tool call, nothing queued');
        $this->assertFalse((bool) Conversation::findOrFail($question->conversation_id)->is_processing);
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
            'answer' => ['raw_reply' => '2', 'is_selection' => true, 'selected' => self::CHOICE_TWO],
            'message' => self::ANSWERED_LATE_MESSAGE,
        ];
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

    /** The run completed after the late answer, and the note was saved once only. */
    private function assertResumedAndCompleted(AgentQuestion $question): void
    {
        $question = $question->fresh();
        $this->assertSame(QuestionState::Answered, $question->state);
        $this->assertNotNull($question->stopped_at, 'answered after it stopped');
        $this->assertNull($question->pending_conversation_id, 'the slot is free once answered');
        $this->assertNotNull($question->resumed_at);
        $this->assertSame('completed', DB::table('agent_runs')->where('id', $question->run_id)->value('end_state'), 'the resumed run completes');
        $this->assertSame('success', DB::table('agent_run_actions')->where('id', $question->action_id)->value('outcome'));
        $this->assertFalse((bool) Conversation::findOrFail($question->conversation_id)->is_processing);
        $this->assertCount(1, $this->noteWrites, 'the note saved before the pause is saved exactly once across stop and resume');
    }

    /** Work every queued resume the way a queue worker does. */
    private function workQueuedResumes(): void
    {
        foreach (Queue::pushed(ResumeAfterQuestionJob::class)->all() as $job) {
            if (isset($this->worked[spl_object_id($job)])) {
                continue;
            }
            $this->worked[spl_object_id($job)] = true;
            $this->app->call([$job, 'handle']);
        }
    }

    /** @return array<string, mixed> the run as its owner's run list shows it */
    private function runSummary(AgentQuestion $question): array
    {
        return $this->actingAs($this->fixture()->user, 'api')
            ->getJson(self::API."/agent-runs/{$question->run_id}")
            ->assertStatus(200)
            ->json();
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

    private function twinConversation(): Conversation
    {
        $fixture = $this->fixture();

        return Conversation::create([
            'id' => (string) Str::uuid(),
            'user_id' => $fixture->user->id,
            'server_id' => $fixture->server->id,
            'model' => $fixture->conversation->model,
            'title' => 'Another task',
        ]);
    }

    /**
     * Discard every in-memory llm-client instance, as a process restart
     * does, keeping the container, its database connection and its
     * configuration (the durable store and the deployed configuration).
     */
    private function restart(): void
    {
        $property = new \ReflectionProperty($this->app, 'instances');
        $instances = $property->getValue($this->app);

        $forgotten = 0;
        foreach ($instances as $abstract => $instance) {
            $isPackageService = str_starts_with((string) $abstract, 'ClarionApp\\LlmClient\\')
                || (is_object($instance) && str_starts_with(get_class($instance), 'ClarionApp\\LlmClient\\'));
            if ($isPackageService) {
                $this->app->forgetInstance($abstract);
                $forgotten++;
            }
        }
        $this->app->forgetScopedInstances();
        Facade::clearResolvedInstances();

        $this->assertGreaterThan(0, $forgotten, 'precondition: the asking process held llm-client instances');

        $provider = $this->app->getProvider(LlmClientServiceProvider::class);
        $this->assertNotNull($provider, 'precondition: the package provider is registered');
        (function () {
            $this->registerProviders();
            $this->registerPresets();
            $this->registerAgentKinds();
            $this->registerAgentStartingPoints();
        })->call($provider);
    }

    // ---------------------------------------------------------------
    // The question says, up front, what happens without an answer
    // ---------------------------------------------------------------

    #[Test]
    public function the_web_question_says_work_will_pause_if_there_is_no_answer_by_the_deadline(): void
    {
        $this->scenario = 'expiry_stop_statement_web';
        $this->entryPath = 'stream';

        $question = $this->askOnTheWeb();

        $expected = AgentQuestionWebJourneyTest::GOLDEN_THREE_CHOICES."\n\n".self::STOP_STATEMENT;
        $this->assertSame($expected, Message::findOrFail($question->display_message_id)->content, 'the stored question ends with the statement');
        $this->assertSame([$expected], $this->web->textsFor($question->conversation_id), 'and is delivered with it');
    }

    #[Test]
    public function the_chat_question_says_work_will_pause_if_there_is_no_answer_by_the_deadline(): void
    {
        $this->scenario = 'expiry_stop_statement_chat';
        $this->entryPath = 'sync';

        $question = $this->askInTheChat(continues: false);

        $presentation = $this->telegram->presentationsFor($question->conversation_id)[0];
        $this->assertSame(self::WITH_OPTIONS."\n\n".self::STOP_STATEMENT, $presentation->bodyText, 'the buttons\' message ends with the statement');
        $this->assertSame(AgentQuestionWebJourneyTest::GOLDEN_THREE_CHOICES."\n\n".self::STOP_STATEMENT, $presentation->plainText, 'so does its plain fallback');
        $this->assertSame(
            AgentQuestionWebJourneyTest::GOLDEN_THREE_CHOICES."\n\n".self::STOP_STATEMENT,
            Message::findOrFail($question->display_message_id)->content,
        );
    }

    // ---------------------------------------------------------------
    // The deadline passes
    // ---------------------------------------------------------------

    #[Test]
    public function at_the_deadline_the_web_task_stops_and_the_user_is_told(): void
    {
        $this->scenario = 'expiry_stop_deadline_web';
        $this->entryPath = 'stream';

        $question = $this->askOnTheWeb();
        $this->letTheDeadlinePass($question);

        $conversation = Conversation::findOrFail($question->conversation_id);
        $this->assertSame(
            [Message::findOrFail($question->display_message_id)->content, self::STOP_NOTICE],
            $this->web->textsFor($conversation->id),
            'the user is told on the conversation\'s channel, once',
        );
        $notices = $this->listedNotices($conversation);
        $this->assertCount(1, $notices, 'the stop notice is listed in the conversation');
        $this->assertSame(self::STOP_NOTICE, $notices[0]['content']);
        $this->assertSame(
            ['kind' => 'agent_question_notice', 'question_id' => $question->id, 'state' => 'stopped'],
            array_intersect_key($notices[0]['tool_data'], ['kind' => 1, 'question_id' => 1, 'state' => 1]),
        );
        $this->assertSame('stopped', Message::findOrFail($question->display_message_id)->tool_data['state'] ?? null, 'the question is shown as stopped');

        $run = $this->runSummary($question);
        $this->assertSame('in_progress', $run['end_state'], 'the run is neither completed, failed nor abandoned');
        $this->assertTrue($run['awaiting_user'], 'it is waiting on its user');

        // Later ticks change nothing.
        $this->travel(3)->hours();
        $before = $this->activity();
        $this->sweep();
        $this->assertSame($before, $this->activity());
        $this->assertSame(QuestionState::Stopped, $question->fresh()->state);
        $this->assertCount(2, $this->web->textsFor($conversation->id));
    }

    #[Test]
    public function at_the_deadline_the_chat_task_stops_and_the_chat_is_told(): void
    {
        $this->scenario = 'expiry_stop_deadline_chat';
        $this->entryPath = 'sync';

        $question = $this->askInTheChat(continues: false);
        $this->letTheDeadlinePass($question);

        $this->assertSame([self::STOP_NOTICE], $this->telegram->textsFor($question->conversation_id), 'the chat is told, once');
        $run = $this->runSummary($question);
        $this->assertSame('in_progress', $run['end_state']);
        $this->assertTrue($run['awaiting_user']);

        $resolutions = $this->telegram->resolutionsFor($question->id);
        $this->assertCount(1, $resolutions, 'the chat\'s buttons are refreshed once for the stop');
        $this->assertSame(QuestionState::Stopped, $resolutions[0]->state);
        $this->assertSame(self::STOPPED_OUTCOME, $resolutions[0]->summary);
    }

    // ---------------------------------------------------------------
    // A late answer resumes the task, by every way of answering
    // ---------------------------------------------------------------

    #[Test]
    public function a_late_answer_typed_on_the_web_resumes_the_task(): void
    {
        $this->scenario = 'expiry_stop_late_typed_web';
        $this->entryPath = 'stream';

        $question = $this->askOnTheWeb();
        $this->letTheDeadlinePass($question);

        $this->at(self::ANSWERED_AT);
        $this->webReply(Conversation::findOrFail($question->conversation_id), '2')->assertStatus(201);

        $this->assertSame(2, $this->turnCount(), 'the late answer resumes the run with exactly one continuation request');
        $continuation = $this->streamPayloadMessages(1);
        $this->assertSame($this->answeredLate($question), $this->toolResultFor($continuation, 'call_ask'));
        $this->assertNotNull($this->toolResultFor($continuation, 'call_note'), 'the note\'s stored result is replayed, not re-run');

        $this->playTurn(1, self::FINAL_ANSWER);
        $this->assertResumedAndCompleted($question);
    }

    #[Test]
    public function a_late_answer_typed_in_the_chat_resumes_the_task(): void
    {
        $this->scenario = 'expiry_stop_late_typed_chat';
        $this->entryPath = 'sync';

        $question = $this->askInTheChat();
        $this->letTheDeadlinePass($question);

        // As Telegram sends it: no conversation id.
        $this->at(self::ANSWERED_AT);
        $this->telegramSend('2')
            ->assertStatus(200)
            ->assertJson(['conversation_id' => $question->conversation_id, 'status' => 'completed', 'content' => self::FINAL_ANSWER]);

        $this->assertCount(2, $this->transport()->capturedChatPayloads(), 'exactly one continuation request');
        $this->assertSame($this->answeredLate($question), $this->toolResultFor($this->chatPayloadMessages(1), $question->tool_call_id));
        $this->assertResumedAndCompleted($question);
    }

    #[Test]
    public function a_late_answer_through_the_web_form_resumes_the_task(): void
    {
        $this->scenario = 'expiry_stop_late_form';
        $this->entryPath = 'stream';

        $question = $this->askOnTheWeb();
        $this->letTheDeadlinePass($question);

        $this->at(self::ANSWERED_AT);
        $this->submitForm($question, ['selected' => [2]])->assertStatus(201);
        $this->workQueuedResumes();

        $this->assertSame(2, $this->turnCount(), 'the late answer resumes the run with exactly one continuation request');
        $this->assertSame($this->answeredLate($question), $this->toolResultFor($this->streamPayloadMessages(1), 'call_ask'));

        $this->playTurn(1, self::FINAL_ANSWER);
        $this->assertResumedAndCompleted($question);
    }

    #[Test]
    public function a_late_answer_by_a_tap_in_the_chat_resumes_the_task(): void
    {
        $this->scenario = 'expiry_stop_late_tap';
        $this->entryPath = 'sync';

        $question = $this->askInTheChat();
        $this->letTheDeadlinePass($question);

        $this->at(self::ANSWERED_AT);
        $outcome = $this->telegram->tap($question->id, (string) $this->fixture()->user->id, [2]);

        $this->assertSame(InteractiveAnswerStatus::Answered, $outcome->status);
        $this->assertCount(2, $this->transport()->capturedChatPayloads(), 'exactly one continuation request');
        $this->assertSame($this->answeredLate($question), $this->toolResultFor($this->chatPayloadMessages(1), $question->tool_call_id));
        $this->assertSame([self::STOP_NOTICE, self::FINAL_ANSWER], $this->telegram->textsFor($question->conversation_id), 'the reply is pushed to the chat');
        $this->assertResumedAndCompleted($question);
    }

    #[Test]
    public function a_stopped_task_survives_a_restart_and_a_late_answer_resumes_it(): void
    {
        $this->scenario = 'expiry_stop_restart';
        $this->entryPath = 'stream';

        $question = $this->askOnTheWeb();
        $this->letTheDeadlinePass($question);

        $this->restart();

        $stopped = $question->fresh();
        $this->assertSame(QuestionState::Stopped, $stopped->state, 'still stopped after the restart');
        $this->assertSame($question->conversation_id, $stopped->pending_conversation_id);
        $paused = Message::findOrFail($stopped->paused_message_id)->tool_data;
        $this->assertSame($stopped->id, $paused['pending_question']['question_id'] ?? null, 'the paused turn still waits on this question');

        $this->at(self::ANSWERED_AT);
        $this->webReply(Conversation::findOrFail($question->conversation_id), '2')->assertStatus(201);

        $this->assertSame(2, $this->turnCount());
        $this->assertSame($this->answeredLate($question), $this->toolResultFor($this->streamPayloadMessages(1), 'call_ask'));

        $this->playTurn(1, self::FINAL_ANSWER);
        $this->assertResumedAndCompleted($question);
    }

    // ---------------------------------------------------------------
    // Ending or deleting the conversation discards the stopped task
    // ---------------------------------------------------------------

    /** The stopped task was discarded and its run closed for good. */
    private function assertDiscarded(AgentQuestion $question): void
    {
        $discarded = AgentQuestion::findOrFail($question->id);
        $this->assertSame(QuestionState::Withdrawn, $discarded->state);
        $this->assertNotNull($discarded->stopped_at, 'it was stopped when it was discarded');
        $this->assertNull($discarded->pending_conversation_id);
        $this->assertNull($discarded->answer_raw);

        $run = DB::table('agent_runs')->where('id', $question->run_id)->first();
        $this->assertSame('stopped_early', $run->end_state);
        $this->assertSame('stopped question discarded', $run->end_reason);
        $step = DB::table('agent_run_steps')->where('id', $question->step_id)->first();
        $this->assertSame('stopped_early', $step->end_state);
        $this->assertSame('stopped question discarded', $step->end_reason);
        $this->assertSame(1, $this->turnCount(), 'the discarded run is never continued');
        $this->assertCount(1, $this->noteWrites);
    }

    /** A reply in another conversation of the same user is ordinary, and the discarded task stays discarded. */
    private function assertAnotherConversationUnaffected(AgentQuestion $question): void
    {
        $before = (array) DB::table('agent_questions')->where('id', $question->id)->first();
        $twin = $this->twinConversation();
        $turns = $this->turnCount();

        $reply = $this->webReply($twin, '2');
        $reply->assertStatus(201);
        $this->assertNull($reply->json('tool_data'), 'an ordinary message, not an answer');
        $this->assertSame($turns + 1, $this->turnCount(), 'it starts ordinary new work');
        $last = $this->streamPayloadMessages($turns);
        $this->assertSame(['role' => 'user', 'content' => '2'], array_intersect_key(end($last), ['role' => 1, 'content' => 1]));
        $this->assertSame($before, (array) DB::table('agent_questions')->where('id', $question->id)->first(), 'the discarded question is untouched');
    }

    #[Test]
    public function ending_the_conversation_discards_the_stopped_task(): void
    {
        $this->scenario = 'expiry_stop_discard_end';
        $this->entryPath = 'stream';

        $question = $this->askOnTheWeb();
        $this->letTheDeadlinePass($question);
        $conversation = Conversation::findOrFail($question->conversation_id);

        $this->at(self::ANSWERED_AT);
        $this->actingAs($this->fixture()->user, 'api')
            ->postJson(self::API."/conversation/{$conversation->id}/end")
            ->assertStatus(200);
        $this->workQueuedResumes();

        $this->assertDiscarded($question);
        $this->assertSame(
            [Message::findOrFail($question->display_message_id)->content, self::STOP_NOTICE, self::DISCARD_NOTICE],
            $this->web->textsFor($conversation->id),
            'the user is told the stopped task was discarded',
        );
        $notices = $this->listedNotices($conversation);
        $this->assertSame([self::STOP_NOTICE, self::DISCARD_NOTICE], array_column($notices, 'content'));
        $this->assertSame('withdrawn', $notices[1]['tool_data']['state'] ?? null);

        $this->assertAnotherConversationUnaffected($question);

        // A later reply in the ended conversation never resumes it.
        $before = (array) DB::table('agent_questions')->where('id', $question->id)->first();
        $form = $this->submitForm($question, ['selected' => [2]]);
        $form->assertStatus(409);
        $this->assertSame('withdrawn', $form->json('state'));

        $turns = $this->turnCount();
        $reply = $this->webReply($conversation, '2');
        $reply->assertStatus(201);
        $this->assertNull($reply->json('tool_data'), 'an ordinary message, not an answer');
        $this->workQueuedResumes();
        $this->assertSame($turns + 1, $this->turnCount(), 'ordinary new work, not a continuation');
        $outcome = $this->toolResultFor($this->streamPayloadMessages($turns), 'call_ask')['outcome'] ?? null;
        $this->assertNotContains($outcome, ['answered', 'answered_late'], 'the discarded ask is never answered');
        $this->assertSame($before, (array) DB::table('agent_questions')->where('id', $question->id)->first());
        $this->assertSame('stopped_early', DB::table('agent_runs')->where('id', $question->run_id)->value('end_state'));
    }

    #[Test]
    public function deleting_the_conversation_discards_the_stopped_task(): void
    {
        $this->scenario = 'expiry_stop_discard_delete';
        $this->entryPath = 'stream';

        $question = $this->askOnTheWeb();
        $this->letTheDeadlinePass($question);
        $conversationId = $question->conversation_id;
        $displayText = Message::findOrFail($question->display_message_id)->content;

        $this->at(self::ANSWERED_AT);
        $this->actingAs($this->fixture()->user, 'api')
            ->deleteJson(self::API."/conversation/{$conversationId}")
            ->assertStatus(204);
        $this->workQueuedResumes();

        $this->assertNull(Conversation::find($conversationId), 'precondition: the conversation is gone');
        $this->assertDiscarded($question);
        $this->assertSame(
            [$displayText, self::STOP_NOTICE, self::DISCARD_NOTICE],
            $this->web->textsFor($conversationId),
            'the user is told the stopped task was discarded',
        );

        $this->assertAnotherConversationUnaffected($question);

        // A later answer to the deleted conversation's question never resumes it.
        $before = (array) DB::table('agent_questions')->where('id', $question->id)->first();
        $turns = $this->turnCount();
        $form = $this->submitForm($question, ['selected' => [2]]);
        $form->assertStatus(409);
        $this->assertSame('withdrawn', $form->json('state'));
        $this->workQueuedResumes();
        $this->assertSame($turns, $this->turnCount());
        $this->assertSame($before, (array) DB::table('agent_questions')->where('id', $question->id)->first());
    }

    // ---------------------------------------------------------------
    // While stopped
    // ---------------------------------------------------------------

    #[Test]
    public function a_blank_late_reply_is_refused_and_the_task_stays_stopped(): void
    {
        $this->scenario = 'expiry_stop_blank_reply';
        $this->entryPath = 'stream';

        $question = $this->askOnTheWeb();
        $this->letTheDeadlinePass($question);
        $conversation = Conversation::findOrFail($question->conversation_id);
        $messages = Message::where('conversation_id', $conversation->id)->count();
        $before = $this->activity();

        $this->at(self::ANSWERED_AT);
        $reply = $this->webReply($conversation, '   ');

        $reply->assertStatus(422);
        $this->assertSame('answer_required', $reply->json('code'));
        $this->assertSame(QuestionState::Stopped, $question->fresh()->state, 'still stopped');
        $this->assertSame($conversation->id, $question->fresh()->pending_conversation_id);
        $this->assertSame($messages, Message::where('conversation_id', $conversation->id)->count(), 'nothing is stored');
        $this->assertSame($before, $this->activity(), 'nothing runs');
    }

    #[Test]
    public function a_second_question_in_the_conversation_is_refused_while_the_task_is_stopped(): void
    {
        $this->scenario = 'expiry_stop_second_question_refused';
        $this->entryPath = 'stream';

        $question = $this->askOnTheWeb();
        $this->letTheDeadlinePass($question);
        $conversation = Conversation::findOrFail($question->conversation_id);
        $deliveries = $this->web->textsFor($conversation->id);

        // Another agent turn in the same conversation asks.
        $handler = $this->app->make(AgentLoopStreamHandler::class);
        $handler->toolCalls = [[
            'id' => 'call_second',
            'type' => 'function',
            'function' => ['name' => 'ask_user', 'arguments' => json_encode(['question' => 'Should I also update the docs?', 'choices' => ['Yes', 'No']])],
        ]];
        $handler->message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
        ]);
        $handler->finish(json_encode(['conversation_id' => $conversation->id, 'iteration' => 1]), 1.0);

        $refused = null;
        foreach ((array) ($handler->message->fresh()->tool_data['tool_results'] ?? []) as $result) {
            if (($result['tool_call_id'] ?? null) === 'call_second') {
                $refused = json_decode((string) $result['content'], true);
            }
        }
        $this->assertSame(
            ['outcome' => 'refused', 'code' => 'question_already_pending', 'reason' => "A question is already waiting for the user's answer in this conversation. Wait for it to be resolved before asking another."],
            $refused,
            'the second ask is refused: the stopped question still holds the conversation\'s slot',
        );

        $questions = AgentQuestion::where('conversation_id', $conversation->id)->get();
        $this->assertCount(1, $questions, 'no second question exists');
        $this->assertSame(QuestionState::Stopped, $questions[0]->state);
        $this->assertSame($conversation->id, $questions[0]->pending_conversation_id);
        $this->assertSame($deliveries, $this->web->textsFor($conversation->id), 'the user sees no second question');
    }

    #[Test]
    public function the_chat_shows_the_stop_as_answerable_then_the_late_answer_as_final(): void
    {
        $this->scenario = 'expiry_stop_chat_resolutions';
        $this->entryPath = 'sync';

        $question = $this->askInTheChat();
        $this->letTheDeadlinePass($question);

        $this->at(self::ANSWERED_AT);
        $this->assertSame(InteractiveAnswerStatus::Answered, $this->telegram->tap($question->id, (string) $this->fixture()->user->id, [2])->status);

        $resolutions = $this->telegram->resolutionsFor($question->id);
        $this->assertCount(2, $resolutions, 'the chat was told twice: the stop, then the late answer');

        $this->assertSame(QuestionState::Stopped, $resolutions[0]->state);
        $this->assertSame(self::STOPPED_OUTCOME, $resolutions[0]->summary);
        $this->assertTrue(
            ($resolutions[0]->answerable ?? null) === true,
            'the stop leaves the buttons answerable',
        );

        $this->assertSame(QuestionState::Answered, $resolutions[1]->state);
        $this->assertSame('Answered after work stopped: 2. Frontend', $resolutions[1]->summary);
        $this->assertSame(self::CHOICE_TWO, $resolutions[1]->selected);
        $this->assertTrue(
            ($resolutions[1]->answerable ?? null) === false,
            'the late answer takes the buttons away',
        );
        $this->assertResumedAndCompleted($question);
    }
}
