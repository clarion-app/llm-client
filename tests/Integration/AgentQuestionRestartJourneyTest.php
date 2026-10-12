<?php

namespace Tests\Integration;

use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Events\ToolExecutionEvent;
use ClarionApp\LlmClient\Events\UpdateOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\LlmClientServiceProvider;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\Services\AnswerParser;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\CapturedPayload;
use Tests\Integration\Harness\OperationCatalogue;
use Tests\Integration\Harness\RecordingChannelDelivery;

/**
 * A paused task survives a long wait and a restart, end to end.
 *
 * Nothing about a pending question may live in a process: after every
 * in-memory llm-client service and handler is discarded, the question is
 * still pending, still shown in the conversation, and answering it resumes
 * the run — on the web (streaming) path and on the messaging (synchronous)
 * path alike. And when the process dies after an answer was recorded but
 * before the run resumed, the every-minute sweep re-drives the resume, and
 * the run continues exactly once however many times it is re-driven.
 *
 * Real wiring throughout, with the model scripted at the streaming and HTTP
 * boundaries and a recording adapter standing in for the Telegram chat.
 */
class AgentQuestionRestartJourneyTest extends AssembledSystemTestCase
{
    private const MESSAGE_ENDPOINT = '/api/clarion-app/llm-client/message';

    private const AGENT_ENDPOINT = '/api/clarion-app/llm-client/agent';

    private const SWEEP = 'llm-client:resolve-expired-questions';

    private RecordingChannelDelivery $telegram;

    protected function setUp(): void
    {
        parent::setUp();

        config(['llm-client.run_trace.enabled' => true]);
        config(['llm-client.conversation.inactivity_threshold_hours' => 4]);

        // Resume jobs are held on the fake queue, as a real queue holds them
        // until a worker picks them up, so a test controls when they run.
        Queue::fake([SendHttpStreamRequest::class, ResumeAfterQuestionJob::class]);

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
            '/notes' => fn () => Http::response(['stored' => true], 201, ['Content-Type' => 'application/json']),
        ]);

        Event::fake([
            NewConversationMessageEvent::class,
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            ToolExecutionEvent::class,
        ]);

        // The Telegram chat is outside the process and outlives a restart.
        // Its adapter is registered whenever the registry is built, as the
        // Telegram package's provider does on every boot.
        $this->telegram = new RecordingChannelDelivery('telegram');
        $this->app->make(ConversationChannelRegistry::class)->register($this->telegram);
        $this->app->afterResolving(
            ConversationChannelRegistry::class,
            fn (ConversationChannelRegistry $registry) => $registry->register($this->telegram),
        );
    }

    protected function tearDown(): void
    {
        (new OperationCatalogue())->reset();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Restarting
    // ---------------------------------------------------------------

    /**
     * Discard every in-memory llm-client instance — services, the channel
     * registry, the run recorder, the agent loop — as a process restart
     * does. The application container itself, its database connection and
     * its configuration stand in for the durable store and the deployed
     * configuration, which a restart keeps.
     *
     * The container's `forgetInstances()` would also discard the database
     * connection, and with it the in-memory database the test runs on, so
     * the package's own instances are forgotten one by one instead.
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

        // A new process boots the package again, which fills the in-memory
        // registries (model providers, presets, agent kinds, starting
        // points) that the instances just discarded held. Routes, event
        // listeners and the schedule live in the framework's own objects,
        // which this simulation keeps.
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
    // Driving the web conversation (streaming)
    // ---------------------------------------------------------------

    private function webReply(string $content): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->fixture()->user, 'api')->postJson(self::MESSAGE_ENDPOINT, [
            'conversation_id' => $this->fixture()->conversation->id,
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

    /** Ask the three-choice question on the first streamed turn. */
    private function askOnTheWeb(): AgentQuestion
    {
        $this->fixture()->build();

        $this->webReply('Help me tidy up the project.')->assertStatus(201);
        $this->playTurn(0, '', [[
            'id' => 'call_ask',
            'name' => 'ask_user',
            'arguments' => AgentQuestionWebJourneyTest::threeChoiceAskArguments(),
        ]]);

        $this->assertSame(1, $this->turnCount(), 'the run waits for the user: no further model request');

        return $this->questionIn($this->fixture()->conversation);
    }

    // ---------------------------------------------------------------
    // Driving the messaging conversation (synchronous)
    // ---------------------------------------------------------------

    private function telegramSend(string $message, ?string $conversationId = null): \Illuminate\Testing\TestResponse
    {
        $body = ['message' => $message, 'channel' => 'telegram'];
        if ($conversationId !== null) {
            $body['conversation_id'] = $conversationId;
        }

        return $this->actingAs($this->fixture()->user, 'api')->postJson(self::AGENT_ENDPOINT, $body);
    }

    /** Ask the three-choice question over `/agent` on the Telegram channel. */
    private function askOnTelegram(string $finalAnswer): AgentQuestion
    {
        $fixture = $this->fixture()->build();
        $fixture->conversation->update(['channel' => 'telegram']);

        $this->script()
            ->toolRequest('ask_user', ['question' => AgentQuestionWebJourneyTest::QUESTION, 'choices' => AgentQuestionWebJourneyTest::CHOICES])
            ->finalAnswer($finalAnswer);

        $this->telegramSend('Help me tidy up the project.', $fixture->conversation->id)
            ->assertStatus(202)
            ->assertJson(['status' => 'awaiting_user']);

        $question = $this->questionIn($fixture->conversation);
        $this->assertSame(
            [AgentQuestionWebJourneyTest::GOLDEN_THREE_CHOICES],
            $this->telegram->textsFor($fixture->conversation->id),
            'precondition: the question reached the chat',
        );

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

    // ---------------------------------------------------------------
    // Shared helpers
    // ---------------------------------------------------------------

    private function questionIn(Conversation $conversation): AgentQuestion
    {
        $question = AgentQuestion::where('conversation_id', $conversation->id)->latest('asked_at')->first();
        $this->assertNotNull($question, 'the agent\'s ask must have opened a question');

        return $question;
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

    /**
     * The question is still waiting, durably: pending in its row, the slot
     * held, the paused turn unconsumed, and the numbered question listed in
     * the conversation the user opens.
     */
    private function assertStillPendingAndShown(AgentQuestion $question): void
    {
        $fresh = AgentQuestion::findOrFail($question->id);
        $this->assertSame(QuestionState::Pending, $fresh->state);
        $this->assertSame($question->conversation_id, $fresh->pending_conversation_id);
        $this->assertNull($fresh->resumed_at);

        $paused = Message::findOrFail($fresh->paused_message_id)->tool_data;
        $this->assertSame($fresh->id, $paused['pending_question']['question_id'] ?? null, 'the paused turn still waits on this question');

        $listed = $this->actingAs($this->fixture()->user, 'api')
            ->getJson("/api/clarion-app/llm-client/conversation/{$fresh->conversation_id}/message")
            ->assertStatus(200)
            ->json();
        $shown = array_values(array_filter($listed, fn (array $m) => ($m['id'] ?? null) === $fresh->display_message_id));
        $this->assertCount(1, $shown, 'the conversation lists the question');
        $this->assertSame(AgentQuestionWebJourneyTest::GOLDEN_THREE_CHOICES, $shown[0]['content']);
        $this->assertSame('pending', $shown[0]['tool_data']['state'] ?? null);
    }

    private function assertRunCompleted(AgentQuestion $question): void
    {
        $question = $question->fresh();
        $this->assertNotNull($question->resumed_at);
        $this->assertSame('completed', DB::table('agent_runs')->where('id', $question->run_id)->value('end_state'), 'the resumed run completes');
        $this->assertSame('success', DB::table('agent_run_actions')->where('id', $question->action_id)->value('outcome'));
        $this->assertFalse((bool) Conversation::findOrFail($question->conversation_id)->is_processing);
    }

    /**
     * Record "$reply" as the answer without resuming — the state a process
     * that dies right after committing the answer leaves behind.
     */
    private function answerWithoutResuming(AgentQuestion $question, string $reply): void
    {
        $message = app(AgentQuestionService::class)->answer(
            $question,
            (new AnswerParser())->parse($reply, $question),
            (string) $question->user_id,
            $reply,
        );
        $this->assertNotNull($message, 'precondition: the answer won the transition');

        $question->refresh();
        $this->assertSame(QuestionState::Answered, $question->state);
        $this->assertNull($question->resumed_at, 'precondition: the run has not resumed');
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

    private function graceSeconds(): int
    {
        return (int) config('llm-client.agent_questions.resume_grace_seconds');
    }

    // ---------------------------------------------------------------
    // A restart while the question is pending
    // ---------------------------------------------------------------

    public function test_web_question_survives_a_restart_and_the_answer_resumes_the_run(): void
    {
        $this->scenario = 'restart_web_question_pending';
        $this->entryPath = 'stream';

        $question = $this->askOnTheWeb();

        $this->restart();

        $this->assertStillPendingAndShown($question);

        $this->webReply('2')->assertStatus(201);

        $this->assertSame(2, $this->turnCount(), 'the answer resumes the run with exactly one continuation request');
        $raw = $this->toolResultFor($this->streamPayloadMessages(1), 'call_ask');
        $this->assertNotNull($raw, 'the continuation answers the ask_user call');
        $this->assertSame([
            'outcome' => 'answered',
            'question_id' => $question->id,
            'answer' => ['raw_reply' => '2', 'is_selection' => true, 'selected' => [['number' => 2, 'text' => 'Frontend']]],
        ], json_decode($raw, true));

        $this->playTurn(1, 'Starting with the frontend.');

        $this->assertSame(QuestionState::Answered, $question->fresh()->state);
        $this->assertRunCompleted($question);
    }

    public function test_messaging_question_survives_a_restart_and_the_answer_resumes_the_run(): void
    {
        $this->scenario = 'restart_messaging_question_pending';
        $this->entryPath = 'sync';

        $question = $this->askOnTelegram('Starting with the backend.');

        $this->restart();

        $this->assertStillPendingAndShown($question);
        $this->assertFalse((bool) Conversation::findOrFail($question->conversation_id)->is_processing);

        $this->telegramSend('1')
            ->assertStatus(200)
            ->assertJson([
                'conversation_id' => $question->conversation_id,
                'status' => 'completed',
                'content' => 'Starting with the backend.',
            ]);

        $raw = $this->toolResultFor($this->chatPayloadMessages(1), $question->tool_call_id);
        $this->assertNotNull($raw, 'the continuation answers the ask_user call');
        $this->assertSame([['number' => 1, 'text' => 'Backend']], json_decode($raw, true)['answer']['selected'] ?? null);

        $this->assertSame(QuestionState::Answered, $question->fresh()->state);
        $this->assertRunCompleted($question);
        $this->assertCount(1, $this->telegram->deliveries, 'the reply goes back in the HTTP response; only the question was pushed');
    }

    public function test_an_answer_hours_later_still_resumes_the_run(): void
    {
        $this->scenario = 'restart_web_question_answered_hours_later';
        $this->entryPath = 'stream';

        $question = $this->askOnTheWeb();

        $this->travel(6)->hours();
        $this->assertTrue($question->fresh()->expires_at->isFuture(), 'precondition: still within the expiry period');

        // The sweep running in the meantime leaves a pending question alone.
        $this->sweep();
        $this->assertSame([], $this->queuedResumeJobs());

        $this->restart();
        $this->assertStillPendingAndShown($question);

        $this->webReply('3')->assertStatus(201);

        $this->assertSame(2, $this->turnCount());
        $raw = $this->toolResultFor($this->streamPayloadMessages(1), 'call_ask');
        $this->assertSame([['number' => 3, 'text' => 'Docs']], json_decode((string) $raw, true)['answer']['selected'] ?? null);

        $this->playTurn(1, 'Docs first, then.');
        $this->assertRunCompleted($question);
    }

    // ---------------------------------------------------------------
    // A crash after the answer was recorded, before the run resumed
    // ---------------------------------------------------------------

    #[Test]
    public function redrive_resumes_exactly_once(): void
    {
        $this->scenario = 'restart_redrive_exactly_once';
        $this->entryPath = 'stream';

        $question = $this->askOnTheWeb();
        $this->answerWithoutResuming($question, '2');
        $this->restart();

        $this->travel($this->graceSeconds() + 30)->seconds();

        // Two sweep ticks before any worker gets to the queue: each finds
        // the resolved, unresumed row and queues a resume for it.
        $this->sweep();
        $this->sweep();

        $this->assertSame(1, $this->turnCount(), 'the sweep only queues the resume; it does not continue the run itself');
        $jobs = $this->queuedResumeJobs();
        $this->assertCount(2, $jobs, 'each tick re-drives a resolved, unresumed question; the claim makes the duplicate harmless');

        $this->work($jobs[0]);
        $claimedAt = $question->fresh()->resumed_at;
        $this->assertNotNull($claimedAt, 'the first resume takes the claim');
        $this->assertSame(2, $this->turnCount(), 'the first resume continues the run');

        $this->travel(5)->seconds();
        $this->work($jobs[1]);

        $this->assertSame(2, $this->turnCount(), 'the duplicate resume does not continue the run again');
        $this->assertEquals($claimedAt, $question->fresh()->resumed_at, 'resumed_at is set once');

        $results = Message::findOrFail($question->paused_message_id)->tool_data['tool_results'] ?? [];
        $this->assertCount(1, array_filter($results, fn ($r) => ($r['tool_call_id'] ?? null) === 'call_ask'), 'the recorded answer is applied exactly once');

        $raw = $this->toolResultFor($this->streamPayloadMessages(1), 'call_ask');
        $this->assertSame([
            'outcome' => 'answered',
            'question_id' => $question->id,
            'answer' => ['raw_reply' => '2', 'is_selection' => true, 'selected' => [['number' => 2, 'text' => 'Frontend']]],
        ], json_decode((string) $raw, true), 'the recorded answer is not lost');

        $this->playTurn(1, 'Starting with the frontend.');
        $this->assertRunCompleted($question);

        // A later tick finds nothing left to re-drive.
        $this->sweep();
        $this->assertCount(2, $this->queuedResumeJobs());
    }

    public function test_redrive_on_the_messaging_channel_pushes_the_reply_once(): void
    {
        $this->scenario = 'restart_redrive_messaging';
        $this->entryPath = 'sync';

        $question = $this->askOnTelegram('Starting with the backend.');
        $this->answerWithoutResuming($question, '1');
        $this->restart();

        $this->travel($this->graceSeconds() + 30)->seconds();
        $this->sweep();
        $this->sweep();

        $jobs = $this->queuedResumeJobs();
        $this->assertNotEmpty($jobs, 'the sweep re-drives the resolved question');
        foreach ($jobs as $job) {
            $this->work($job);
        }

        $this->assertCount(2, $this->transport()->capturedChatPayloads(), 'the ask and exactly one continuation');
        $raw = $this->toolResultFor($this->chatPayloadMessages(1), $question->tool_call_id);
        $this->assertSame([['number' => 1, 'text' => 'Backend']], json_decode((string) $raw, true)['answer']['selected'] ?? null);

        // Nobody waits on an HTTP response for a re-driven run, so its reply
        // is pushed to the chat — once.
        $this->assertSame(
            [AgentQuestionWebJourneyTest::GOLDEN_THREE_CHOICES, 'Starting with the backend.'],
            $this->telegram->textsFor($question->conversation_id),
        );
        $this->assertRunCompleted($question);
    }

    public function test_a_resolution_within_the_grace_window_is_not_redriven(): void
    {
        $this->scenario = 'restart_redrive_grace_window';
        $this->entryPath = 'stream';

        $question = $this->askOnTheWeb();
        $this->answerWithoutResuming($question, '2');

        // Inside the window the inline resume may still be on its way.
        $this->travel(max(0, $this->graceSeconds() - 10))->seconds();
        $this->sweep();

        $this->assertSame([], $this->queuedResumeJobs(), 'a fresh resolution is left to its own resumer');
        $this->assertNull($question->fresh()->resumed_at);
        $this->assertSame(1, $this->turnCount());

        // Once the window has passed, it is re-driven.
        $this->travel(20)->seconds();
        $this->sweep();

        $jobs = $this->queuedResumeJobs();
        $this->assertCount(1, $jobs);
        $this->work($jobs[0]);
        $this->assertSame(2, $this->turnCount());

        $this->playTurn(1, 'Starting with the frontend.');
        $this->assertRunCompleted($question);
    }
}
