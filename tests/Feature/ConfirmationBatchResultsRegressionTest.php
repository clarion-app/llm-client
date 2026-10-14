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
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\McpClientServer;
use ClarionApp\LlmClient\Models\McpClientTool;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\McpClientToolExecutor;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression coverage for two defects in the confirmation pause, on each of
 * the four places the agent loop can pause for a confirmation:
 *
 *  - AgentLoopService::run()                       (synchronous first turn)
 *  - AgentLoopService::resumeSync()                (synchronous continuation)
 *  - AgentLoopStreamHandler, registry-tool branch  (streamed DELETE/PUT/PATCH)
 *  - AgentLoopStreamHandler, execute_operation branch
 *
 * Each case runs one model turn shaped [A, confirmation-requiring call, B]:
 *
 *  1. A executes before the pause, so its result must be kept and must reach
 *     the stored tool_results once the confirmation is approved. Previously
 *     every pause site wrote `tool_results => null`, discarding it.
 *  2. The approval's result must be attributed to the call that actually
 *     paused. Previously the resume wrote one result for tool_calls[0] —
 *     A's id — leaving the paused call and B unanswered.
 *  3. B never runs (the turn stopped at the pause) and receives a
 *     synthesized "Not executed" result so every tool call is answered.
 *
 * And across pause + approval, A executes exactly once.
 */
class ConfirmationBatchResultsRegressionTest extends TestCase
{
    private User $user;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->server = Server::create([
            'name' => 'Batch Regression LLM Server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'sk-test',
        ]);
        app(RoleAssignmentService::class)->set(ModelRole::Inference, $this->user->id, $this->server->id, 'test-model');

        config(['llm-client.confirm_methods' => []]);
        config(['llm-client.api_denylist' => []]);

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
    }

    protected function tearDown(): void
    {
        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        Mockery::close();

        foreach (['agent_run_actions', 'agent_run_steps', 'agent_runs', 'mcp_sessions'] as $table) {
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
            'title' => 'Batch regression conversation',
            'is_processing' => true,
        ]);
    }

    /**
     * An external MCP tool: every call to it pauses for confirmation, and
     * approving it dispatches through McpClientToolExecutor (no HTTP).
     */
    private function externalTool(): McpClientTool
    {
        $server = McpClientServer::create([
            'name' => 'Filesystem Server',
            'transport' => 'streamable_http',
            'url' => 'https://example.test/mcp',
            'user_id' => $this->user->id,
        ]);

        return McpClientTool::create([
            'server_id' => $server->id,
            'synthetic_operation_id' => "mcp:{$server->id}:delete_file",
            'name' => 'delete_file',
            'description' => 'Deletes a file.',
            'input_schema' => ['type' => 'object', 'properties' => []],
            'last_seen_at' => now(),
        ]);
    }

    /**
     * Binds a McpClientToolExecutor that records each approved external
     * call, so the test can count how many times the confirmed call ran.
     */
    private function bindExternalExecutor(int &$approvedRuns): void
    {
        $executor = Mockery::mock(McpClientToolExecutor::class);
        $executor->shouldReceive('execute')->andReturnUsing(function () use (&$approvedRuns) {
            $approvedRuns++;

            return ['content' => [['type' => 'text', 'text' => 'file deleted']], 'isError' => false];
        });
        $this->app->instance(McpClientToolExecutor::class, $executor);
    }

    private function toolCall(string $id, string $name, array $arguments): array
    {
        return [
            'id' => $id,
            'type' => 'function',
            'function' => ['name' => $name, 'arguments' => json_encode($arguments)],
        ];
    }

    private function toolCallReply(array $calls): array
    {
        return ['choices' => [['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => $calls]]]];
    }

    private function textReply(string $content): array
    {
        return [
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content, 'tool_calls' => []]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20],
        ];
    }

    /**
     * A synchronous service whose model replies are scripted, and whose two
     * local tools `record_note_a` / `record_note_b` count their executions.
     */
    private function syncService(array $responses): BatchRecordingAgentLoopService
    {
        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function () use (&$responses) {
            return array_shift($responses) ?? ['choices' => [['message' => ['content' => 'Done.', 'tool_calls' => []]]]];
        });
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);

        return new BatchRecordingAgentLoopService(
            app(McpToolRegistry::class),
            new McpToolExecutor(app(McpToolRegistry::class), null, fn ($user) => 'test-token'),
            app(OperationCache::class),
            $registry,
        );
    }

    /**
     * Binds registry/executor doubles for the streamed path: `notes.store`
     * and `tags.store` are safe POST tools whose executions are recorded;
     * `contacts.destroy` is a DELETE tool, which pauses for confirmation.
     *
     * @param  list<string>  $executedRegistryTools
     */
    private function bindStreamRegistry(array &$executedRegistryTools): McpToolExecutor
    {
        $registry = Mockery::mock(McpToolRegistry::class);
        $registry->shouldReceive('findTool')->andReturnUsing(function (string $name) {
            return match ($name) {
                'notes.store' => ['name' => $name, '_meta' => ['operationId' => 'storeNote', 'method' => 'POST', 'path' => '/api/notes']],
                'tags.store' => ['name' => $name, '_meta' => ['operationId' => 'storeTag', 'method' => 'POST', 'path' => '/api/tags']],
                'contacts.destroy' => ['name' => $name, '_meta' => ['operationId' => 'destroyContact', 'method' => 'DELETE', 'path' => '/api/contacts/{id}']],
                default => null,
            };
        });

        $executor = Mockery::mock(McpToolExecutor::class);
        $executor->shouldReceive('executeTool')->andReturnUsing(function (string $name) use (&$executedRegistryTools) {
            $executedRegistryTools[] = $name;

            return ['content' => [['type' => 'text', 'text' => json_encode(['stored' => $name])]], 'isError' => false];
        });
        $executor->shouldReceive('extractArguments')->andReturn(['path' => '/api/contacts/42', 'query' => [], 'body' => []]);
        $executor->shouldReceive('executeHttpCall')->andReturn([
            'content' => [['type' => 'text', 'text' => json_encode(['deleted' => true])]],
            'isError' => false,
        ]);

        $this->app->instance(McpToolRegistry::class, $registry);
        $this->app->instance(McpToolExecutor::class, $executor);

        return $executor;
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

    private function streamPausedTurn(Conversation $conversation, array $toolCalls): Message
    {
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

    /**
     * The three-way assertion every case shares: results for [A, paused, B]
     * in tool_calls order, A's real result kept, the approval attributed to
     * the paused call, and B answered with a synthesized not-executed result.
     */
    private function assertBatchResults(
        Message $pausedMessage,
        string $idA,
        string $idPaused,
        string $idB,
        string $expectedInA,
        string $expectedInPaused,
    ): void {
        $toolResults = $pausedMessage->fresh()->tool_data['tool_results'] ?? null;

        $this->assertIsArray($toolResults, 'the paused turn must have tool_results once the confirmation is approved');
        $this->assertSame(
            [$idA, $idPaused, $idB],
            array_map(fn ($r) => $r['tool_call_id'] ?? null, $toolResults),
            'every call in the turn must be answered exactly once, in tool_calls order; got '.json_encode($toolResults),
        );

        $this->assertStringContainsString(
            $expectedInA,
            (string) $toolResults[0]['content'],
            'the call executed before the pause must keep its real result',
        );
        $this->assertStringContainsString(
            $expectedInPaused,
            (string) $toolResults[1]['content'],
            'the approval result must be attributed to the paused call, not tool_calls[0]',
        );
        $this->assertStringStartsWith(
            'Not executed',
            (string) $toolResults[2]['content'],
            'the call after the pause must receive a synthesized not-executed result',
        );
        $this->assertStringContainsStringIgnoringCase(
            'confirm',
            (string) $toolResults[2]['content'],
            'the synthesized result must say the turn paused for a confirmation',
        );
    }

    // ---------------------------------------------------------------
    // Pause site 1: AgentLoopService::run()
    // ---------------------------------------------------------------

    #[Test]
    public function run_pause_keeps_earlier_results_and_attributes_the_approval_to_the_paused_call(): void
    {
        $approvedRuns = 0;
        $this->bindExternalExecutor($approvedRuns);
        $this->actingAs($this->user, 'api');

        $tool = $this->externalTool();
        $conversation = $this->conversation();
        $conversation->update(['is_processing' => false]);

        $service = $this->syncService([
            $this->toolCallReply([
                $this->toolCall('call_a', 'record_note_a', []),
                $this->toolCall('call_confirm', 'execute_operation', ['operationId' => $tool->synthetic_operation_id, 'parameters' => []]),
                $this->toolCall('call_b', 'record_note_b', []),
            ]),
            $this->textReply('All done.'),
        ]);

        $paused = $service->run($conversation->fresh(), 'Tidy up my files.');
        $this->assertSame('confirmation_required', $paused['status'] ?? null, 'got: '.json_encode($paused));
        $this->assertSame(['record_note_a'], $service->executedTools, 'only the call before the pause runs before approval');

        $pausedMessage = Message::findOrFail($paused['message_id']);

        $final = $service->resumeSync($conversation->fresh(), $pausedMessage, true);
        $this->assertSame('completed', $final['status'] ?? null, 'got: '.json_encode($final));

        $this->assertBatchResults($pausedMessage, 'call_a', 'call_confirm', 'call_b', 'record_note_a', 'file deleted');
        $this->assertSame(['record_note_a'], $service->executedTools, 'A runs exactly once and B never runs');
        $this->assertSame(1, $approvedRuns);
    }

    // ---------------------------------------------------------------
    // Pause site 2: AgentLoopService::resumeSync()'s own continuation loop
    // ---------------------------------------------------------------

    #[Test]
    public function resume_sync_pause_keeps_earlier_results_and_attributes_the_approval_to_the_paused_call(): void
    {
        $approvedRuns = 0;
        $this->bindExternalExecutor($approvedRuns);
        $this->actingAs($this->user, 'api');

        $tool = $this->externalTool();
        $conversation = $this->conversation();
        $conversation->update(['is_processing' => false]);
        $confirmArgs = ['operationId' => $tool->synthetic_operation_id, 'parameters' => []];

        $service = $this->syncService([
            // First turn: a lone confirmation, so the second pause happens
            // inside resumeSync()'s continuation loop.
            $this->toolCallReply([$this->toolCall('call_first', 'execute_operation', $confirmArgs)]),
            $this->toolCallReply([
                $this->toolCall('call_a', 'record_note_a', []),
                $this->toolCall('call_confirm', 'execute_operation', $confirmArgs),
                $this->toolCall('call_b', 'record_note_b', []),
            ]),
            $this->textReply('All done.'),
        ]);

        $first = $service->run($conversation->fresh(), 'Tidy up my files.');
        $this->assertSame('confirmation_required', $first['status'] ?? null, 'got: '.json_encode($first));

        $second = $service->resumeSync($conversation->fresh(), Message::findOrFail($first['message_id']), true);
        $this->assertSame('confirmation_required', $second['status'] ?? null, 'resumeSync must pause on its own batch; got: '.json_encode($second));
        $this->assertSame(['record_note_a'], $service->executedTools);

        $pausedMessage = Message::findOrFail($second['message_id']);

        $final = $service->resumeSync($conversation->fresh(), $pausedMessage, true);
        $this->assertSame('completed', $final['status'] ?? null, 'got: '.json_encode($final));

        $this->assertBatchResults($pausedMessage, 'call_a', 'call_confirm', 'call_b', 'record_note_a', 'file deleted');
        $this->assertSame(['record_note_a'], $service->executedTools, 'A runs exactly once and B never runs');
        $this->assertSame(2, $approvedRuns);
    }

    // ---------------------------------------------------------------
    // Pause site 3: AgentLoopStreamHandler, registry DELETE/PUT/PATCH branch
    // ---------------------------------------------------------------

    #[Test]
    public function stream_registry_pause_keeps_earlier_results_and_attributes_the_approval_to_the_paused_call(): void
    {
        Queue::fake();
        $this->fakeStreamEvents();

        $executedRegistryTools = [];
        $executor = $this->bindStreamRegistry($executedRegistryTools);
        $conversation = $this->conversation();

        $pausedMessage = $this->streamPausedTurn($conversation, [
            $this->toolCall('call_a', 'notes.store', ['body' => ['text' => 'hello']]),
            $this->toolCall('call_confirm', 'contacts.destroy', ['path' => ['id' => '42']]),
            $this->toolCall('call_b', 'tags.store', ['body' => ['name' => 'later']]),
        ]);

        $this->assertNotNull($pausedMessage->tool_data['pending_confirmation'] ?? null, 'the DELETE call must pause the turn');
        $this->assertSame(['notes.store'], $executedRegistryTools, 'only the call before the pause runs before approval');
        Queue::assertNotPushed(AgentStreamStepJob::class);

        $service = new AgentLoopService(app(McpToolRegistry::class), $executor, new OperationCache(), app(ProviderRegistry::class));
        $service->resume($conversation->fresh(), $pausedMessage, true);

        Queue::assertPushed(AgentStreamStepJob::class);
        $this->assertBatchResults($pausedMessage, 'call_a', 'call_confirm', 'call_b', 'notes.store', 'deleted');
        $this->assertSame(['notes.store'], $executedRegistryTools, 'A runs exactly once and B never runs');
    }

    // ---------------------------------------------------------------
    // Pause site 4: AgentLoopStreamHandler, execute_operation marker branch
    // ---------------------------------------------------------------

    #[Test]
    public function stream_execute_operation_pause_keeps_earlier_results_and_attributes_the_approval_to_the_paused_call(): void
    {
        Queue::fake();
        $this->fakeStreamEvents();

        $approvedRuns = 0;
        $this->bindExternalExecutor($approvedRuns);

        // The container's AgentLoopService (used by the stream handler for
        // execute_operation) is built on the real tool registry, before the
        // registry double below replaces it for the handler's own lookups.
        $realService = new AgentLoopService(
            app(McpToolRegistry::class),
            new McpToolExecutor(app(McpToolRegistry::class), null, fn ($user) => 'test-token'),
            new OperationCache(),
            app(ProviderRegistry::class),
        );
        $this->app->instance(AgentLoopService::class, $realService);

        $executedRegistryTools = [];
        $this->bindStreamRegistry($executedRegistryTools);

        $tool = $this->externalTool();
        $conversation = $this->conversation();

        $pausedMessage = $this->streamPausedTurn($conversation, [
            $this->toolCall('call_a', 'notes.store', ['body' => ['text' => 'hello']]),
            $this->toolCall('call_confirm', 'execute_operation', ['operationId' => $tool->synthetic_operation_id, 'parameters' => []]),
            $this->toolCall('call_b', 'tags.store', ['body' => ['name' => 'later']]),
        ]);

        $this->assertSame(
            'external_tool',
            $pausedMessage->tool_data['pending_confirmation']['confirmation_type'] ?? null,
            'the external execute_operation call must pause the turn',
        );
        $this->assertSame(['notes.store'], $executedRegistryTools, 'only the call before the pause runs before approval');
        Queue::assertNotPushed(AgentStreamStepJob::class);

        $realService->resume($conversation->fresh(), $pausedMessage, true);

        Queue::assertPushed(AgentStreamStepJob::class);
        $this->assertBatchResults($pausedMessage, 'call_a', 'call_confirm', 'call_b', 'notes.store', 'file deleted');
        $this->assertSame(['notes.store'], $executedRegistryTools, 'A runs exactly once and B never runs');
        $this->assertSame(1, $approvedRuns);
    }
}

/**
 * An AgentLoopService whose two local tools, `record_note_a` and
 * `record_note_b`, are recorded instead of dispatched, so a test can count
 * exactly which calls in a batch were executed. Every other tool name goes
 * through the real implementation.
 */
class BatchRecordingAgentLoopService extends AgentLoopService
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
