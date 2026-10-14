<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Jobs\AgentStreamStepJob;
use ClarionApp\LlmClient\AgentLoopStreamHandler;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Events\ApiCallConfirmationRequiredEvent;
use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Events\ToolExecutionEvent;
use ClarionApp\LlmClient\Events\UpdateOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\McpClientServer;
use ClarionApp\LlmClient\Models\McpClientTool;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\McpClientToolExecutor;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use ClarionApp\LlmClient\ValueObjects\PauseKind;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use ClarionApp\LlmClient\ValueObjects\UnansweredPolicy;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\RecordingChannelDelivery;
use Tests\TestCase;

/**
 * `ask_user` at the places the agent loop can pause.
 *
 * The tool is offered to the model with a fixed, channel-free schema, and
 * like every meta-tool it disappears when withheld. On the streaming path
 * (AgentLoopStreamHandler::handleToolCalls()) a valid ask pauses the turn
 * as a question — the run waits, no further model request is dispatched —
 * with the same batch semantics as any pause: calls before the ask keep
 * their results, calls after it are not executed and are answered as such.
 * An invalid ask never pauses: its refusal is the call's result in the same
 * iteration and nothing is written or shown to the user.
 *
 * The synchronous pause sites (AgentLoopService::run() and resumeSync()'s
 * continuation loop, which serve `/agent` and so Telegram) pause the same
 * way, push the question through the conversation's channel adapter before
 * returning, and report `awaiting_user` instead of a reply.
 */
class AskUserPauseSitesTest extends TestCase
{
    private const GOLDEN_THREE_CHOICES = "Which part should I change first?\n"
        ."\n"
        ."1. Backend\n"
        ."2. Frontend\n"
        ."3. Docs\n"
        ."\n"
        .'Reply with the number of your choice, or answer in your own words.'
        ."\n"
        ."\n"
        // Asked at the frozen 09:00 UTC on 12 Oct 2026 (see setUp()) with the
        // default one-day period and no policy, so the work stops if unanswered.
        .'If there is no answer by 09:00 UTC on 13 Oct 2026 (1 day after this was asked), work on this task will pause until you reply.';

    private User $user;

    private Server $server;

    private RecordingChannelDelivery $web;

    private RecordingChannelDelivery $telegram;

    private AskRecordingAgentLoopService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Frozen, so the deadline every question states is known exactly.
        $this->travelTo(Carbon::parse('2026-10-12 09:00:00', 'UTC'));

        $this->user = User::factory()->create();
        $this->server = Server::create([
            'name' => 'Ask User LLM Server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'sk-test',
        ]);
        app(RoleAssignmentService::class)->set(ModelRole::Inference, $this->user->id, $this->server->id, 'test-model');

        config(['llm-client.run_trace.enabled' => true]);

        if (!Schema::hasTable('mcp_sessions')) {
            Schema::create('mcp_sessions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->string('protocol_version');
                $table->string('client_name')->nullable();
                $table->string('client_version')->nullable();
                $table->json('capabilities')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        $this->seedEmptyOperationCatalog();

        $this->web = new RecordingChannelDelivery('web');
        app(ConversationChannelRegistry::class)->register($this->web);
        $this->telegram = new RecordingChannelDelivery('telegram');
        app(ConversationChannelRegistry::class)->register($this->telegram);

        $this->service = new AskRecordingAgentLoopService(
            app(McpToolRegistry::class),
            new McpToolExecutor(app(McpToolRegistry::class), null, fn ($user) => 'test-token'),
            new OperationCache(),
            app(ProviderRegistry::class),
        );
        $this->app->instance(AgentLoopService::class, $this->service);
    }

    protected function tearDown(): void
    {
        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        Mockery::close();

        foreach (['agent_questions', 'agent_run_actions', 'agent_run_steps', 'agent_runs', 'mcp_sessions'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
        DB::table('messages')->delete();
        DB::table('conversations')->delete();
        DB::table('mcp_client_tools')->delete();
        DB::table('mcp_client_servers')->delete();
        DB::table('llm_role_assignments')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    private function seedEmptyOperationCatalog(): void
    {
        $doc = ['paths' => []];

        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, $doc);

        $generator = Mockery::mock(Generator::class);
        $generator->shouldReceive('__invoke')->andReturn($doc);
        $this->app->instance(Generator::class, $generator);
    }

    private function conversation(): Conversation
    {
        return Conversation::factory()->create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'title' => 'Ask user conversation',
            'channel' => 'web',
            'is_processing' => true,
        ]);
    }

    private function toolCall(string $id, string $name, array $arguments): array
    {
        return [
            'id' => $id,
            'type' => 'function',
            'function' => ['name' => $name, 'arguments' => json_encode($arguments)],
        ];
    }

    private function askCall(string $id = 'call_ask', array $arguments = []): array
    {
        return $this->toolCall($id, 'ask_user', $arguments + [
            'question' => 'Which part should I change first?',
            'choices' => ['Backend', 'Frontend', 'Docs'],
        ]);
    }

    private function fakeStreamEvents(): void
    {
        Event::fake([
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            NewConversationMessageEvent::class,
            ToolExecutionEvent::class,
            ApiCallConfirmationRequiredEvent::class,
        ]);
    }

    /** Drive one streamed model turn carrying $toolCalls through the real handler. */
    private function streamTurn(Conversation $conversation, array $toolCalls): Message
    {
        Queue::fake();
        $this->fakeStreamEvents();

        $handler = new AgentLoopStreamHandler();
        $handler->toolCalls = $toolCalls;
        $handler->message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
        ]);

        $handler->finish(json_encode([
            'conversation_id' => $conversation->id,
            'iteration' => 1,
        ]), 2);

        return $handler->message->fresh();
    }

    // ---------------------------------------------------------------
    // The tool definition
    // ---------------------------------------------------------------

    #[Test]
    public function ask_user_is_offered_with_its_fixed_schema(): void
    {
        $tools = $this->service->buildToolsPayload();
        $askUser = collect($tools)->firstWhere('function.name', 'ask_user');

        $this->assertNotNull($askUser, 'ask_user must be offered to the model');
        $this->assertEquals([
            'type' => 'function',
            'function' => [
                'name' => 'ask_user',
                'description' => 'Pause this task and ask the user a question. The task resumes when the user answers, or when the question expires or is withdrawn; the outcome is returned as this tool\'s result. Offer up to 10 choices when the answer is one of a known set; the user may always answer in their own words. Only one question can be pending in a conversation at a time. Decide per question what happens if the user does not answer in time: by default work stops until they answer; set if_unanswered to proceed_on_assumption and state the answer you will assume (assumed_choices or assumed_text) only when the question has a safe default. Mark questions that gate spending, deleting, sending or other real-world consequences as consequential — those can never proceed on an assumption.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'question' => ['type' => 'string', 'description' => 'The question to ask, in plain text.'],
                        'choices' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'maxItems' => 10,
                            'description' => 'Optional choices, shown numbered from 1.',
                        ],
                        'allow_multiple' => ['type' => 'boolean', 'description' => 'Whether several choices may be selected. Requires choices.'],
                        'if_unanswered' => [
                            'type' => 'string',
                            'enum' => ['proceed_on_assumption', 'stop'],
                            'description' => 'What happens if no answer arrives in time. Default: stop (or proceed_on_assumption when an assumed answer is given).',
                        ],
                        'assumed_choices' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                            'description' => 'The choice number(s) to assume if unanswered. Exactly one unless allow_multiple.',
                        ],
                        'assumed_text' => [
                            'type' => 'string',
                            'description' => 'The free-text answer to assume if unanswered, read exactly as if the user had typed it. Use instead of assumed_choices.',
                        ],
                        'consequential' => [
                            'type' => 'boolean',
                            'description' => 'True when the answer gates an action with real-world consequences. A consequential question always stops if unanswered.',
                        ],
                        'wait_minutes' => [
                            'type' => 'integer',
                            'description' => 'How long to wait for an answer. Brought within the installation\'s minimum and maximum.',
                        ],
                    ],
                    'required' => ['question'],
                ],
            ],
        ], $askUser);
    }

    #[Test]
    public function ask_user_takes_no_channel_or_conversation_parameter(): void
    {
        $askUser = collect($this->service->buildToolsPayload())->firstWhere('function.name', 'ask_user');
        $this->assertNotNull($askUser);

        $parameters = array_keys((array) $askUser['function']['parameters']['properties']);
        sort($parameters);

        $this->assertSame(
            ['allow_multiple', 'assumed_choices', 'assumed_text', 'choices', 'consequential', 'if_unanswered', 'question', 'wait_minutes'],
            $parameters,
            'the asking side never names a channel, chat or conversation',
        );
    }

    #[Test]
    public function ask_user_is_not_offered_when_withheld(): void
    {
        $names = collect($this->service->buildToolsPayload(['ask_user']))->pluck('function.name')->all();

        $this->assertNotContains('ask_user', $names);
        $this->assertContains('search_operations', $names, 'withholding ask_user removes only ask_user');
    }

    // ---------------------------------------------------------------
    // Streaming pause site
    // ---------------------------------------------------------------

    #[Test]
    public function a_valid_streamed_ask_pauses_the_run_without_another_request(): void
    {
        $conversation = $this->conversation();

        $paused = $this->streamTurn($conversation, [$this->askCall()]);

        Queue::assertNotPushed(AgentStreamStepJob::class);

        $question = AgentQuestion::where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($question, 'a valid ask opens a question');
        $this->assertSame(QuestionState::Pending, $question->state);
        $this->assertSame('stream', $question->resume_mode);
        $this->assertSame('call_ask', $question->tool_call_id);
        $this->assertSame($paused->id, $question->paused_message_id);
        $this->assertSame('web', $question->channel);
        $this->assertNotNull($question->run_id, 'the question is tied to the waiting run');

        $toolData = $paused->tool_data;
        $this->assertSame(PauseKind::Question->value, $toolData['pause_kind'] ?? null);
        $this->assertSame('call_ask', $toolData['paused_tool_call_id'] ?? null);
        $this->assertSame(['question_id' => $question->id], $toolData['pending_question'] ?? null);
        $this->assertArrayHasKey('tool_results', $toolData);
        $this->assertNull($toolData['tool_results']);

        $display = Message::findOrFail($question->display_message_id);
        $this->assertSame(self::GOLDEN_THREE_CHOICES, $display->content);
        $this->assertSame([self::GOLDEN_THREE_CHOICES], $this->web->textsFor($conversation->id), 'the question is delivered once, on the conversation\'s channel');

        $this->assertFalse((bool) $conversation->fresh()->is_processing);

        $action = DB::table('agent_run_actions')->where('target', 'ask_user')->first();
        $this->assertNotNull($action, 'the ask_user call is recorded on the run');
        $this->assertSame('awaiting_confirmation', $action->outcome);
        $this->assertSame($action->id, $question->action_id);

        $run = DB::table('agent_runs')->where('id', $question->run_id)->first();
        $this->assertSame('in_progress', $run->end_state, 'the run is waiting, not finished');
    }

    #[Test]
    public function a_streamed_batch_keeps_earlier_results_and_does_not_execute_later_calls(): void
    {
        $conversation = $this->conversation();

        $paused = $this->streamTurn($conversation, [
            $this->toolCall('call_a', 'record_note_a', []),
            $this->askCall(),
            $this->toolCall('call_b', 'record_note_b', []),
        ]);

        Queue::assertNotPushed(AgentStreamStepJob::class);
        $this->assertSame(['record_note_a'], $this->service->executedTools, 'the call before the ask runs; the call after it never does');

        $pending = $paused->tool_data['pending_tool_results'] ?? null;
        $this->assertIsArray($pending);
        $this->assertSame(['call_a', 'call_b'], array_map(fn ($r) => $r['tool_call_id'] ?? null, $pending));
        $this->assertStringContainsString('record_note_a', (string) $pending[0]['content'], 'the earlier call keeps its real result');
        $this->assertSame(PauseKind::Question->notExecutedResult(), $pending[1]['content']);
        $this->assertSame(1, AgentQuestion::where('conversation_id', $conversation->id)->count());
    }

    #[Test]
    public function a_second_ask_in_the_same_turn_is_not_executed(): void
    {
        $conversation = $this->conversation();

        $paused = $this->streamTurn($conversation, [
            $this->askCall('call_first'),
            $this->askCall('call_second', ['question' => 'Should I also update the docs?', 'choices' => ['Yes', 'No']]),
        ]);

        $questions = AgentQuestion::where('conversation_id', $conversation->id)->get();
        $this->assertCount(1, $questions, 'only the first ask opens a question');
        $this->assertSame('call_first', $questions->first()->tool_call_id);

        $this->assertSame(
            [['tool_call_id' => 'call_second', 'content' => PauseKind::Question->notExecutedResult()]],
            $paused->tool_data['pending_tool_results'] ?? null,
        );
        $this->assertCount(1, $this->web->textsFor($conversation->id), 'only one question is shown');
    }

    #[Test]
    public function an_invalid_ask_is_refused_in_the_same_iteration(): void
    {
        $conversation = $this->conversation();
        $tooMany = array_map(fn (int $n) => "Option {$n}", range(1, 11));

        $message = $this->streamTurn($conversation, [
            $this->askCall('call_ask', ['choices' => $tooMany]),
        ]);

        $this->assertSame(0, AgentQuestion::count(), 'a refused ask writes no question');
        $this->assertSame([], $this->web->deliveries, 'nothing is shown to the user for a refused ask');

        $toolData = $message->tool_data;
        $this->assertArrayNotHasKey('pause_kind', $toolData, 'a refused ask does not pause the turn');
        $results = $toolData['tool_results'] ?? null;
        $this->assertIsArray($results);
        $this->assertSame('call_ask', $results[0]['tool_call_id'] ?? null);
        $this->assertSame(
            ['outcome' => 'refused', 'code' => 'invalid_question', 'reason' => 'more than 10 choices'],
            json_decode((string) $results[0]['content'], true),
            'the refusal is the call\'s result',
        );

        Queue::assertPushed(AgentStreamStepJob::class, 1);
    }

    // ---------------------------------------------------------------
    // Synchronous pause sites: run() and resumeSync()
    // ---------------------------------------------------------------

    /** Number of model requests the synchronous service made. */
    private int $chatCalls = 0;

    private function telegramConversation(): Conversation
    {
        return Conversation::factory()->create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'title' => 'Ask user over Telegram',
            'channel' => 'telegram',
            'is_processing' => false,
        ]);
    }

    private function toolCallReply(array $calls, string $content = ''): array
    {
        return ['choices' => [['message' => ['role' => 'assistant', 'content' => $content, 'tool_calls' => $calls]]]];
    }

    private function textReply(string $content): array
    {
        return [
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content, 'tool_calls' => []]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20],
        ];
    }

    /**
     * A synchronous service whose model replies are scripted (counted in
     * $chatCalls) and whose `record_note_a` / `record_note_b` tools record
     * their executions.
     */
    private function syncService(array $responses): AskRecordingAgentLoopService
    {
        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function () use (&$responses) {
            $this->chatCalls++;

            return array_shift($responses) ?? ['choices' => [['message' => ['content' => 'Done.', 'tool_calls' => []]]]];
        });
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);

        $service = new AskRecordingAgentLoopService(
            app(McpToolRegistry::class),
            new McpToolExecutor(app(McpToolRegistry::class), null, fn ($user) => 'test-token'),
            app(OperationCache::class),
            $registry,
            runTraceRecorder: app(RunTraceRecorder::class),
        );
        $this->app->instance(AgentLoopService::class, $service);

        return $service;
    }

    /**
     * The synchronous `awaiting_user` result: the question was asked and
     * already delivered; there is no reply content.
     */
    private function assertAwaitingUser(array $result, Conversation $conversation): AgentQuestion
    {
        $this->assertSame('awaiting_user', $result['status'] ?? null, 'got: '.json_encode($result));
        $this->assertSame($conversation->id, $result['conversation_id'] ?? null);
        $this->assertSame('', $result['content'] ?? null, 'the question is pushed through the channel, not returned as content');

        $question = AgentQuestion::find($result['question_id'] ?? null);
        $this->assertNotNull($question, 'the result names the opened question');
        $this->assertSame($conversation->id, $question->conversation_id);
        $this->assertSame($question->display_message_id, $result['message_id'] ?? null, 'message_id is the question\'s display message');
        $this->assertSame(QuestionState::Pending, $question->state);
        $this->assertSame('sync', $question->resume_mode);
        $this->assertSame('telegram', $question->channel);

        return $question;
    }

    #[Test]
    public function run_pauses_on_ask_user_and_reports_awaiting_user(): void
    {
        $conversation = $this->telegramConversation();
        $service = $this->syncService([$this->toolCallReply([$this->askCall()])]);

        $result = $service->run($conversation->fresh(), 'Help me tidy up the project.');

        $question = $this->assertAwaitingUser($result, $conversation);
        $this->assertSame(1, $this->chatCalls, 'the run waits for the user: no further model request');
        $this->assertSame('call_ask', $question->tool_call_id);

        $paused = Message::findOrFail($question->paused_message_id);
        $this->assertSame(PauseKind::Question->value, $paused->tool_data['pause_kind'] ?? null);
        $this->assertSame('call_ask', $paused->tool_data['paused_tool_call_id'] ?? null);
        $this->assertSame(['question_id' => $question->id], $paused->tool_data['pending_question'] ?? null);
        $this->assertArrayHasKey('tool_results', $paused->tool_data);
        $this->assertNull($paused->tool_data['tool_results'], 'the paused call is not answered yet');

        $this->assertSame(self::GOLDEN_THREE_CHOICES, Message::findOrFail($question->display_message_id)->content);
        $this->assertSame([self::GOLDEN_THREE_CHOICES], $this->telegram->textsFor($conversation->id), 'the question was delivered before run() returned');
        $this->assertSame([], $this->web->deliveries, 'only the conversation\'s own channel receives it');
        $this->assertNotNull($question->delivered_at);

        $this->assertFalse((bool) $conversation->fresh()->is_processing, 'a waiting conversation is not processing');

        $this->assertNotNull($question->run_id);
        $this->assertSame('awaiting_confirmation', DB::table('agent_run_actions')->where('id', $question->action_id)->value('outcome'));
        $this->assertSame('in_progress', DB::table('agent_runs')->where('id', $question->run_id)->value('end_state'));
    }

    #[Test]
    public function run_puts_the_turns_assistant_text_before_the_question(): void
    {
        $conversation = $this->telegramConversation();
        $preamble = 'I found three areas that need work.';
        $service = $this->syncService([$this->toolCallReply([$this->askCall()], $preamble)]);

        $result = $service->run($conversation->fresh(), 'Help me tidy up the project.');

        $question = $this->assertAwaitingUser($result, $conversation);
        $expected = $preamble."\n\n".self::GOLDEN_THREE_CHOICES;
        $this->assertSame($expected, Message::findOrFail($question->display_message_id)->content, 'on the synchronous path the assistant text is shown with the question, since the reply content is empty');
        $this->assertSame([$expected], $this->telegram->textsFor($conversation->id));
    }

    #[Test]
    public function run_batch_keeps_earlier_results_and_does_not_execute_later_calls(): void
    {
        $conversation = $this->telegramConversation();
        $service = $this->syncService([$this->toolCallReply([
            $this->toolCall('call_a', 'record_note_a', []),
            $this->askCall(),
            $this->toolCall('call_b', 'record_note_b', []),
        ])]);

        $result = $service->run($conversation->fresh(), 'Help me tidy up the project.');

        $question = $this->assertAwaitingUser($result, $conversation);
        $this->assertSame(1, $this->chatCalls);
        $this->assertSame(['record_note_a'], $service->executedTools, 'the call before the ask runs; the call after it never does');

        $pending = Message::findOrFail($question->paused_message_id)->tool_data['pending_tool_results'] ?? null;
        $this->assertIsArray($pending);
        $this->assertSame(['call_a', 'call_b'], array_map(fn ($r) => $r['tool_call_id'] ?? null, $pending));
        $this->assertStringContainsString('record_note_a', (string) $pending[0]['content'], 'the earlier call keeps its real result');
        $this->assertSame(PauseKind::Question->notExecutedResult(), $pending[1]['content']);
    }

    #[Test]
    public function run_second_ask_in_the_same_turn_is_not_executed(): void
    {
        $conversation = $this->telegramConversation();
        $service = $this->syncService([$this->toolCallReply([
            $this->askCall('call_first'),
            $this->askCall('call_second', ['question' => 'Should I also update the docs?', 'choices' => ['Yes', 'No']]),
        ])]);

        $result = $service->run($conversation->fresh(), 'Help me tidy up the project.');

        $question = $this->assertAwaitingUser($result, $conversation);
        $this->assertSame('call_first', $question->tool_call_id);
        $this->assertSame(1, AgentQuestion::where('conversation_id', $conversation->id)->count(), 'only the first ask opens a question');
        $this->assertSame(
            [['tool_call_id' => 'call_second', 'content' => PauseKind::Question->notExecutedResult()]],
            Message::findOrFail($question->paused_message_id)->tool_data['pending_tool_results'] ?? null,
        );
        $this->assertCount(1, $this->telegram->textsFor($conversation->id), 'only one question is shown');
    }

    #[Test]
    public function run_refuses_an_invalid_ask_in_the_same_iteration(): void
    {
        $conversation = $this->telegramConversation();
        $tooMany = array_map(fn (int $n) => "Option {$n}", range(1, 11));
        $service = $this->syncService([
            $this->toolCallReply([$this->askCall('call_ask', ['choices' => $tooMany])]),
            $this->textReply('I will pick one myself.'),
        ]);

        $result = $service->run($conversation->fresh(), 'Help me tidy up the project.');

        $this->assertSame('completed', $result['status'] ?? null, 'a refused ask does not pause; got: '.json_encode($result));
        $this->assertSame(2, $this->chatCalls, 'the refusal goes back to the model in the next request');
        $this->assertSame(0, AgentQuestion::count());
        $this->assertSame([], $this->telegram->deliveries, 'nothing is shown to the user for a refused ask');

        $turn = Message::where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->get()
            ->first(fn (Message $m) => !empty($m->tool_data['tool_calls'] ?? null));
        $this->assertNotNull($turn);
        $this->assertSame(
            ['outcome' => 'refused', 'code' => 'invalid_question', 'reason' => 'more than 10 choices'],
            json_decode((string) ($turn->tool_data['tool_results'][0]['content'] ?? ''), true),
            'the refusal is the call\'s result',
        );
    }

    #[Test]
    public function resume_sync_pauses_on_ask_user_in_its_continuation(): void
    {
        $this->actingAs($this->user, 'api');

        // The first turn pauses for a confirmation; once approved, the
        // continuation turn asks the user — the pause inside resumeSync().
        $mcpServer = McpClientServer::create([
            'name' => 'Filesystem Server',
            'transport' => 'streamable_http',
            'url' => 'https://example.test/mcp',
            'user_id' => $this->user->id,
        ]);
        $tool = McpClientTool::create([
            'server_id' => $mcpServer->id,
            'synthetic_operation_id' => "mcp:{$mcpServer->id}:delete_file",
            'name' => 'delete_file',
            'description' => 'Deletes a file.',
            'input_schema' => ['type' => 'object', 'properties' => []],
            'last_seen_at' => now(),
        ]);
        $executor = Mockery::mock(McpClientToolExecutor::class);
        $executor->shouldReceive('execute')->andReturn(['content' => [['type' => 'text', 'text' => 'file deleted']], 'isError' => false]);
        $this->app->instance(McpClientToolExecutor::class, $executor);
        config(['llm-client.confirm_methods' => []]);
        config(['llm-client.api_denylist' => []]);

        $conversation = $this->telegramConversation();
        $service = $this->syncService([
            $this->toolCallReply([$this->toolCall('call_confirm', 'execute_operation', ['operationId' => $tool->synthetic_operation_id, 'parameters' => []])]),
            $this->toolCallReply([
                $this->toolCall('call_a', 'record_note_a', []),
                $this->askCall(),
                $this->toolCall('call_b', 'record_note_b', []),
            ]),
        ]);

        $first = $service->run($conversation->fresh(), 'Tidy up my files.');
        $this->assertSame('confirmation_required', $first['status'] ?? null, 'precondition; got: '.json_encode($first));

        $result = $service->resumeSync($conversation->fresh(), Message::findOrFail($first['message_id']), true);

        $question = $this->assertAwaitingUser($result, $conversation);
        $this->assertSame(2, $this->chatCalls, 'no model request after the ask');
        $this->assertSame(['record_note_a'], $service->executedTools);
        $pending = Message::findOrFail($question->paused_message_id)->tool_data['pending_tool_results'] ?? null;
        $this->assertSame(['call_a', 'call_b'], array_map(fn ($r) => $r['tool_call_id'] ?? null, (array) $pending));
        $this->assertSame(PauseKind::Question->notExecutedResult(), $pending[1]['content'] ?? null);
        $this->assertSame([self::GOLDEN_THREE_CHOICES], $this->telegram->textsFor($conversation->id));
        $this->assertFalse((bool) $conversation->fresh()->is_processing);
    }

    // ---------------------------------------------------------------
    // What happens if nobody answers: carried from the ask to the row
    // ---------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function pauseSites(): array
    {
        return [
            'synchronous run()' => ['run'],
            'synchronous resumeSync()' => ['resumeSync'],
            'streaming handler' => ['stream'],
        ];
    }

    /** Ask with $arguments at $site and return the question it opened. */
    private function askAt(string $site, array $arguments): AgentQuestion
    {
        if ($site === 'stream') {
            $conversation = $this->conversation();
            $this->streamTurn($conversation, [$this->askCall('call_ask', $arguments)]);

            $question = AgentQuestion::where('conversation_id', $conversation->id)->first();
            $this->assertNotNull($question, 'a valid streamed ask opens a question');
            $this->assertSame('stream', $question->resume_mode);

            return $question;
        }

        if ($site === 'run') {
            $conversation = $this->telegramConversation();
            $service = $this->syncService([$this->toolCallReply([$this->askCall('call_ask', $arguments)])]);

            return $this->assertAwaitingUser($service->run($conversation->fresh(), 'Help me tidy up the project.'), $conversation);
        }

        // resumeSync(): the first turn pauses for a confirmation; once it is
        // approved, the continuation turn asks.
        $this->actingAs($this->user, 'api');
        $mcpServer = McpClientServer::create([
            'name' => 'Filesystem Server',
            'transport' => 'streamable_http',
            'url' => 'https://example.test/mcp',
            'user_id' => $this->user->id,
        ]);
        $tool = McpClientTool::create([
            'server_id' => $mcpServer->id,
            'synthetic_operation_id' => "mcp:{$mcpServer->id}:delete_file",
            'name' => 'delete_file',
            'description' => 'Deletes a file.',
            'input_schema' => ['type' => 'object', 'properties' => []],
            'last_seen_at' => now(),
        ]);
        $executor = Mockery::mock(McpClientToolExecutor::class);
        $executor->shouldReceive('execute')->andReturn(['content' => [['type' => 'text', 'text' => 'file deleted']], 'isError' => false]);
        $this->app->instance(McpClientToolExecutor::class, $executor);
        config(['llm-client.confirm_methods' => []]);
        config(['llm-client.api_denylist' => []]);

        $conversation = $this->telegramConversation();
        $service = $this->syncService([
            $this->toolCallReply([$this->toolCall('call_confirm', 'execute_operation', ['operationId' => $tool->synthetic_operation_id, 'parameters' => []])]),
            $this->toolCallReply([$this->askCall('call_ask', $arguments)]),
        ]);

        $first = $service->run($conversation->fresh(), 'Tidy up my files.');
        $this->assertSame('confirmation_required', $first['status'] ?? null, 'precondition; got: '.json_encode($first));

        return $this->assertAwaitingUser(
            $service->resumeSync($conversation->fresh(), Message::findOrFail($first['message_id']), true),
            $conversation,
        );
    }

    #[Test]
    #[DataProvider('pauseSites')]
    public function an_assumed_answer_and_a_requested_wait_reach_the_question_at_every_pause_site(string $site): void
    {
        $question = $this->askAt($site, ['assumed_choices' => [1], 'wait_minutes' => 120])->fresh();

        $this->assertSame(UnansweredPolicy::ProceedOnAssumption, $question->unanswered_policy, 'an assumed answer with no policy proceeds on it');
        $this->assertSame('1', $question->assumed_answer_raw);
        $this->assertSame([['number' => 1, 'text' => 'Backend']], $question->assumed_answer_selected);
        $this->assertFalse($question->consequential);
        $this->assertSame(120, $question->requested_wait_minutes);
        $this->assertSame(120, $question->effective_wait_minutes);
        $this->assertSame(120 * 60, $question->expires_at->getTimestamp() - $question->asked_at->getTimestamp(), 'the deadline is the requested period');
    }

    #[Test]
    #[DataProvider('pauseSites')]
    public function a_consequential_ask_stops_at_every_pause_site(string $site): void
    {
        $question = $this->askAt($site, ['consequential' => true])->fresh();

        $this->assertSame(UnansweredPolicy::Stop, $question->unanswered_policy);
        $this->assertTrue($question->consequential);
        $this->assertNull($question->assumed_answer_raw);
        $this->assertNull($question->assumed_answer_selected);
        $this->assertNull($question->requested_wait_minutes);
        $this->assertSame(1440, $question->effective_wait_minutes, 'no requested period: the installation default');
    }
}

/**
 * An AgentLoopService whose two local tools, `record_note_a` and
 * `record_note_b`, are recorded instead of dispatched, so a test can tell
 * exactly which calls in a batch were executed. Every other tool name —
 * `ask_user` included — goes through the real implementation.
 */
class AskRecordingAgentLoopService extends AgentLoopService
{
    /** @var list<string> */
    public array $executedTools = [];

    public function executeMetaTool(string $toolName, array $arguments, Conversation $conversation, ?string $runId = null, bool $unattended = false): string
    {
        if (in_array($toolName, ['record_note_a', 'record_note_b'], true)) {
            $this->executedTools[] = $toolName;

            return json_encode(['recorded' => $toolName]);
        }

        return parent::executeMetaTool($toolName, $arguments, $conversation, $runId, $unattended);
    }
}
