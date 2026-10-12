<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\LlmClient\AgentLoopStreamHandler;
use ClarionApp\LlmClient\Events\ApiCallConfirmationRequiredEvent;
use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Events\ToolExecutionEvent;
use ClarionApp\LlmClient\Events\UpdateOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use ClarionApp\LlmClient\ValueObjects\PauseKind;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
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
 */
class AskUserPauseSitesTest extends TestCase
{
    private const GOLDEN_THREE_CHOICES = "Which part should I change first?\n"
        ."\n"
        ."1. Backend\n"
        ."2. Frontend\n"
        ."3. Docs\n"
        ."\n"
        .'Reply with the number of your choice, or answer in your own words.';

    private User $user;

    private Server $server;

    private RecordingChannelDelivery $web;

    private AskRecordingAgentLoopService $service;

    protected function setUp(): void
    {
        parent::setUp();

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
                'description' => 'Pause this task and ask the user a question. The task resumes when the user answers, or when the question expires or is withdrawn; the outcome is returned as this tool\'s result. Offer up to 10 choices when the answer is one of a known set; the user may always answer in their own words. Only one question can be pending in a conversation at a time.',
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

        $this->assertSame(['allow_multiple', 'choices', 'question'], $parameters, 'the asking side never names a channel, chat or conversation');
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

        Queue::assertNotPushed(SendHttpStreamRequest::class);

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

        Queue::assertNotPushed(SendHttpStreamRequest::class);
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

        Queue::assertPushed(SendHttpStreamRequest::class, 1);
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
