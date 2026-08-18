<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\LlmClient\Contracts\LlmProvider;
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
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The safety behavior a destructive external action must carry, exactly
 * like a built-in destructive operation already does: every external
 * tool call -- benign-sounding or destructive-sounding alike -- pauses
 * for confirmation naming the server that will actually carry it out
 * (its own configured name, never anything the tool itself supplied);
 * declining leaves the target completely untouched; approving actually
 * dispatches the call through McpClientToolExecutor and the result
 * reaches the conversation, on both the asynchronous (resume()) and
 * synchronous (resumeSync()) continuation paths.
 *
 * ExternalToolPermissionNarrowingTest and ExternalToolInjectionResistanceTest
 * already prove the two structural guarantees this rests on (fall-through
 * narrowing, server text never read by the confirm/deny decision) -- this
 * file proves the confirmation journey itself: the marker's own content,
 * decline's inertness, and both approval paths' dispatch.
 */
class ExternalToolConfirmationJourneyTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->seedOperationCatalog();
    }

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('messages')->delete();
        DB::table('conversations')->delete();
        DB::table('mcp_client_tools')->delete();
        DB::table('mcp_client_servers')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Fixture scaffolding
    // -----------------------------------------------------------------

    private function seedOperationCatalog(): void
    {
        $doc = ['paths' => []];

        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, $doc);

        $generator = Mockery::mock(Generator::class);
        $generator->shouldReceive('__invoke')->andReturn($doc);
        $this->app->instance(Generator::class, $generator);
    }

    private function clearOperationCatalog(): void
    {
        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    /**
     * @return array{0: McpClientServer, 1: McpClientTool}
     */
    private function makeExternalTool(string $serverName, string $toolName, string $description = 'An external tool.'): array
    {
        $server = McpClientServer::create([
            'name' => $serverName,
            'transport' => 'streamable_http',
            'url' => 'https://example.test/mcp',
            'user_id' => $this->user->id,
        ]);

        $tool = McpClientTool::create([
            'server_id' => $server->id,
            'synthetic_operation_id' => "mcp:{$server->id}:{$toolName}",
            'name' => $toolName,
            'description' => $description,
            'input_schema' => ['type' => 'object', 'properties' => []],
            'last_seen_at' => now(),
        ]);

        return [$server, $tool];
    }

    /**
     * A conversation bound to a real Server with a valid server_url, so
     * resume()'s own dispatchStreamRequest() call (which resolves an
     * outbound URL via EndpointResolver even though Queue::fake() stops
     * the queued job from actually running) never fails to resolve one --
     * mirrors AgentLoopServiceTest::resume_dispatches_next_iteration_on_approval()'s
     * identical setup.
     */
    private function conversationWithRealServer(): Conversation
    {
        $server = Server::create([
            'name' => 'Confirmation Journey LLM Server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'sk-test',
        ]);

        return Conversation::factory()->create([
            'user_id' => $this->user->id,
            'server_id' => $server->id,
            'is_processing' => true,
        ]);
    }

    /**
     * Hand-builds a pending 'external_tool' confirmation on a real
     * Message, the same shape handleExecuteOperation()'s own
     * pause-storage sites reconstruct -- mirrors
     * ExternalToolDiscoveryJourneyTest::pendingConfirmationMessage()'s
     * identical helper, the established precedent for driving past a
     * confirmation pause without a live user turn.
     */
    private function pendingConfirmationMessage(Conversation $conversation, McpClientTool $tool, array $arguments): Message
    {
        return Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => $conversation->character,
            'content' => '',
            'responseTime' => 0,
            'tool_data' => [
                'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'execute_operation', 'arguments' => json_encode(['operationId' => $tool->synthetic_operation_id, 'parameters' => $arguments])]]],
                'tool_results' => null,
                'iteration' => 1,
                'pending_confirmation' => [
                    'tool_name' => 'execute_operation',
                    'confirmation_type' => 'external_tool',
                    'operationId' => $tool->synthetic_operation_id,
                    'method' => 'MCP_EXTERNAL',
                    'path' => "/mcp-client/{$tool->server_id}/{$tool->name}",
                    'arguments' => $arguments,
                    'expires_at' => now()->addMinutes(5)->toIso8601String(),
                ],
            ],
        ]);
    }

    private function fakeProvider(array $responses): LlmProvider
    {
        return new class($responses) implements LlmProvider {
            private int $i = 0;

            public function __construct(private array $responses) {}

            public function chat(array $messages, array $tools = [], array $options = []): array
            {
                $r = $this->responses[$this->i] ?? end($this->responses);
                $this->i++;

                return $r;
            }

            public function stream(array $messages, array $tools = [], array $options = []): \Generator
            {
                yield [];
            }

            public function embed(array $inputs, array $options = []): array
            {
                return ['embeddings' => []];
            }

            public function countTokens(string $text, ?string $model = null): int
            {
                return 0;
            }

            public function listModels(): array
            {
                return ['models' => []];
            }
        };
    }

    private function textResponse(string $content): array
    {
        return [
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20],
        ];
    }

    // -----------------------------------------------------------------
    // Marker shape (Acceptance Scenario 1/4, mutation-checklist row 14)
    // -----------------------------------------------------------------

    #[Test]
    public function every_external_tool_call_produces_the_universal_external_tool_confirmation_marker_naming_the_servers_own_configured_name(): void
    {
        // Both a benign-sounding and a destructive-sounding tool: the
        // marker (and its status, "confirm") must be identical in kind
        // for both -- research.md D6's universal default, never derived
        // from what the tool sounds like it does.
        [$benignServer, $benignTool] = $this->makeExternalTool('Weather Reporting Server', 'get_weather', 'Reports current weather for a location.');
        [$destructiveServer, $destructiveTool] = $this->makeExternalTool('Filesystem Server', 'delete_file', 'Permanently deletes a file.');

        $conversation = Conversation::factory()->create(['user_id' => $this->user->id]);

        foreach ([[$benignServer, $benignTool], [$destructiveServer, $destructiveTool]] as [$server, $tool]) {
            $result = json_decode(
                app(AgentLoopService::class)->executeMetaTool(
                    'execute_operation',
                    ['operationId' => $tool->synthetic_operation_id, 'parameters' => []],
                    $conversation,
                ),
                true,
            );

            $this->assertTrue(
                (bool) ($result['__requires_confirmation'] ?? false),
                "an external tool call must always pause for confirmation, never a silent allow; got: ".json_encode($result),
            );
            $this->assertSame('external_tool', $result['confirmation_type'] ?? null);
            $this->assertArrayNotHasKey('content', $result, 'a marker must never itself carry an already-executed result');
            $this->assertSame(
                $server->name,
                $result['server_name'] ?? null,
                'the marker must name the server\'s own configured name, never anything derived from the tool',
            );
            $this->assertSame($tool->name, $result['tool_name'] ?? null);
        }
    }

    // -----------------------------------------------------------------
    // Declining leaves the target unaffected
    // -----------------------------------------------------------------

    #[Test]
    public function declining_leaves_the_target_unaffected_and_never_invokes_the_external_tool_executor(): void
    {
        Queue::fake();

        [, $tool] = $this->makeExternalTool('Filesystem Server', 'delete_file');
        $conversation = $this->conversationWithRealServer();
        $message = $this->pendingConfirmationMessage($conversation, $tool, ['path' => '/data/notes.txt']);

        $mockExecutor = Mockery::mock(McpClientToolExecutor::class);
        $mockExecutor->shouldNotReceive('execute');
        $this->app->instance(McpClientToolExecutor::class, $mockExecutor);

        $service = new AgentLoopService(
            Mockery::mock(McpToolRegistry::class),
            Mockery::mock(McpToolExecutor::class),
            new OperationCache(),
            app(ProviderRegistry::class),
        );

        $service->resume($conversation, $message, false);

        $message->refresh();
        $this->assertNull($message->tool_data['pending_confirmation']);
        $this->assertSame(
            'User cancelled this operation.',
            $message->tool_data['tool_results'][0]['content'] ?? null,
            'declining must produce the same generic cancellation content every other confirmation type\'s decline already produces -- never a tool-specific result',
        );
    }

    // -----------------------------------------------------------------
    // Approving via resume() (asynchronous) dispatches the call
    // -----------------------------------------------------------------

    #[Test]
    public function approving_via_resume_dispatches_the_external_tool_call_and_records_its_result(): void
    {
        Queue::fake();

        [$server, $tool] = $this->makeExternalTool('Filesystem Server', 'delete_file');
        $conversation = $this->conversationWithRealServer();
        $arguments = ['path' => '/data/notes.txt'];
        $message = $this->pendingConfirmationMessage($conversation, $tool, $arguments);

        $mockExecutor = Mockery::mock(McpClientToolExecutor::class);
        $mockExecutor->shouldReceive('execute')
            ->once()
            ->withArgs(function ($calledServer, $calledTool, $calledArguments) use ($server, $tool, $arguments) {
                return $calledServer instanceof McpClientServer
                    && $calledServer->id === $server->id
                    && $calledTool instanceof McpClientTool
                    && $calledTool->id === $tool->id
                    && $calledArguments === $arguments;
            })
            ->andReturn(['content' => [['type' => 'text', 'text' => 'file deleted']], 'isError' => false]);
        $this->app->instance(McpClientToolExecutor::class, $mockExecutor);

        $service = new AgentLoopService(
            Mockery::mock(McpToolRegistry::class),
            Mockery::mock(McpToolExecutor::class),
            new OperationCache(),
            app(ProviderRegistry::class),
        );

        $service->resume($conversation, $message, true);

        Queue::assertPushed(SendHttpStreamRequest::class);

        $message->refresh();
        $this->assertNull($message->tool_data['pending_confirmation']);

        $content = json_decode($message->tool_data['tool_results'][0]['content'] ?? 'null', true);
        $this->assertIsArray($content, 'the executor\'s own result must reach the conversation, not be discarded');
        $this->assertSame('file deleted', $content['content'][0]['text'] ?? null);
        $this->assertFalse($content['isError'] ?? true);
    }

    // -----------------------------------------------------------------
    // Approving via resumeSync() (synchronous) dispatches the call and
    // returns the continuation's own result
    // -----------------------------------------------------------------

    #[Test]
    public function approving_via_resume_sync_dispatches_the_external_tool_call_and_returns_its_result_to_the_conversation(): void
    {
        [$server, $tool] = $this->makeExternalTool('Filesystem Server', 'delete_file');
        $conversation = $this->conversationWithRealServer();
        $arguments = ['path' => '/data/notes.txt'];
        $message = $this->pendingConfirmationMessage($conversation, $tool, $arguments);

        $mockExecutor = Mockery::mock(McpClientToolExecutor::class);
        $mockExecutor->shouldReceive('execute')
            ->once()
            ->withArgs(function ($calledServer, $calledTool, $calledArguments) use ($server, $tool, $arguments) {
                return $calledServer instanceof McpClientServer
                    && $calledServer->id === $server->id
                    && $calledTool instanceof McpClientTool
                    && $calledTool->id === $tool->id
                    && $calledArguments === $arguments;
            })
            ->andReturn(['content' => [['type' => 'text', 'text' => 'file deleted']], 'isError' => false]);
        $this->app->instance(McpClientToolExecutor::class, $mockExecutor);

        $registry = app(ProviderRegistry::class);
        $registry->register('openai', fn ($server) => $this->fakeProvider([$this->textResponse('Done.')]));

        $service = new AgentLoopService(
            Mockery::mock(McpToolRegistry::class),
            Mockery::mock(McpToolExecutor::class),
            new OperationCache(),
            $registry,
        );

        $final = $service->resumeSync($conversation, $message, true);

        $this->assertSame('completed', $final['status'] ?? null, 'got: '.json_encode($final));

        $toolResults = $message->fresh()->tool_data['tool_results'] ?? [];
        $content = json_decode($toolResults[0]['content'] ?? 'null', true);
        $this->assertSame('file deleted', $content['content'][0]['text'] ?? null);
        $this->assertFalse($content['isError'] ?? true);
    }
}
