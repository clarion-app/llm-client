<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\McpClientServer;
use ClarionApp\LlmClient\Models\McpClientTool;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\McpClientToolDiscoveryService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\ValueObjects\McpTransportKind;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\ReferenceMcpServer\Protocol;
use Tests\Fixtures\ReferenceMcpServer\ReferenceMcpServer;
use Tests\TestCase;

/**
 * User Story 2's own Acceptance Scenarios, proven against the wiring
 * Foundational already built and safety-proved
 * (McpClientToolDiscoveryServiceTest, ExternalToolPermissionNarrowingTest,
 * McpClientToolExecutorFailureIsolationTest): a real cached tool actually
 * turning up in a real search_operations call, a real confirm-then-approve
 * round trip actually invoking it, and a tool-internal failure (not a
 * transport failure) actually reaching the conversation legibly.
 */
class ExternalToolDiscoveryJourneyTest extends TestCase
{
    private ?ReferenceMcpServer $referenceServer = null;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->seedOperationCatalog();
    }

    protected function tearDown(): void
    {
        $this->referenceServer?->stopHttp();
        $this->referenceServer = null;
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('messages')->delete();
        DB::table('conversations')->delete();
        DB::table('mcp_client_tools')->delete();
        DB::table('mcp_client_server_statuses')->delete();
        DB::table('mcp_client_servers')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Fixture scaffolding
    // -----------------------------------------------------------------

    /**
     * Seeds ApiManager's live-catalog seam so AgentDefinitionParser::parse()
     * has something to resolve non-mcp: patterns against, mirroring
     * ExternalToolPermissionNarrowingTest's own identical helper. Every
     * tools.allow entry this file writes is mcp:-prefixed and therefore
     * exempted from the catalog-emptiness check entirely, but the catalog
     * itself must still exist for AgentService::create() to parse at all.
     */
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
     * Starts a real ReferenceMcpServer instance over loopback HTTP and
     * runs the real McpClientToolDiscoveryService against it, so the
     * McpClientTool/McpClientServerStatus rows this file asserts on are
     * genuinely produced by the discovery pipeline, not hand-seeded.
     */
    private function discoverServer(string $name, string $mode, array $options = []): McpClientServer
    {
        $this->referenceServer = new ReferenceMcpServer();
        $url = $this->referenceServer->startHttp($mode, $options);

        $server = McpClientServer::create([
            'name' => $name,
            'transport' => McpTransportKind::StreamableHttp,
            'url' => $url,
            'user_id' => $this->user->id,
        ]);

        app(McpClientToolDiscoveryService::class)->discover($server);

        return $server;
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

    /**
     * A standalone AgentLoopService instance, mirroring
     * AgentLoopMetricsIntegrationTest::makeService(): runTraceRecorder
     * deliberately left null (no run-trace machinery needed for this
     * file's assertions), a scripted provider registered for the
     * continuation call resumeSync() makes once a tool result exists.
     */
    private function serviceWithScriptedProvider(array $responses): AgentLoopService
    {
        $registry = app(ProviderRegistry::class);
        $registry->register('openai', fn ($server) => $this->fakeProvider($responses));

        return new AgentLoopService(
            Mockery::mock(McpToolRegistry::class),
            Mockery::mock(McpToolExecutor::class),
            new OperationCache(),
            $registry,
        );
    }

    /**
     * A conversation bound to a freshly created agent version whose
     * tools.allow names exactly the given operationIds -- the widening
     * pattern ExternalToolPermissionNarrowingTest's own
     * widening_the_bound_agent_versions_tools_allow_... case established
     * for letting a synthetic external-tool operationId reach the
     * confirmation step rather than being narrowed away.
     */
    private function conversationWithAgentPermitting(array $operationIds): Conversation
    {
        $server = Server::create([
            'name' => 'Discovery Journey LLM Server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'sk-test',
        ]);

        $allowLines = implode("\n", array_map(fn (string $id) => "    - {$id}", $operationIds));
        $yaml = <<<YAML
name: discovery-journey-agent
instructions: I use external tools.
tools:
  allow:
{$allowLines}
YAML;
        $agent = app(AgentService::class)->create($this->user->id, $yaml);

        return Conversation::factory()->create([
            'user_id' => $this->user->id,
            'agent_id' => $agent->id,
            'agent_version_id' => $agent->current_version_id,
            'server_id' => $server->id,
        ]);
    }

    /**
     * Hand-builds a pending 'external_tool' confirmation on a real Message,
     * the same shape handleExecuteOperation()'s own pause-storage sites
     * reconstruct (arguments carried under the 'arguments' key, not the
     * marker's own 'parameters' key) -- mirroring
     * AgentLoopMetricsIntegrationTest::resume_sync_records_the_confirmed_
     * operation()'s identical hand-built Message, the established
     * precedent for driving past a confirmation pause without a live user
     * turn.
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
                'tool_calls' => [['id' => 'call_1', 'function' => ['name' => 'execute_operation', 'arguments' => '{}']]],
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

    // -----------------------------------------------------------------
    // Tests
    // -----------------------------------------------------------------

    #[Test]
    public function a_discovered_external_tool_appears_in_search_operations_results_with_a_server_prefixed_summary(): void
    {
        $server = $this->discoverServer('Web Search Server', Protocol::MODE_HAPPY_PATH);
        $tool = McpClientTool::where('server_id', $server->id)->where('name', 'reference_echo')->firstOrFail();

        $conversation = Conversation::factory()->create(['user_id' => $this->user->id]);

        $result = json_decode(
            app(AgentLoopService::class)->executeMetaTool('search_operations', ['query' => 'echo'], $conversation),
            true,
        );

        $results = $result['results'] ?? [];
        $match = collect($results)->firstWhere('operationId', $tool->synthetic_operation_id);

        $this->assertNotNull($match, 'the cached external tool must appear in search_operations results (FR-005); got: '.json_encode($result));
        $this->assertSame('operation', $match['type'] ?? null, 'an external tool must appear as the same "operation" type a built-in result carries');
        $this->assertStringStartsWith(
            '[External tool via Web Search Server]',
            $match['summary'] ?? '',
            'FR-006: the summary must name which server the tool came from',
        );
    }

    #[Test]
    public function search_operations_reads_only_the_cache_and_makes_no_outbound_call_even_when_the_servers_own_process_is_no_longer_reachable(): void
    {
        $server = $this->discoverServer('Now Unreachable Server', Protocol::MODE_HAPPY_PATH);
        $tool = McpClientTool::where('server_id', $server->id)->where('name', 'reference_echo')->firstOrFail();

        // The server that produced this cached row is now made unreachable
        // -- search must never notice, because it must never even attempt
        // to contact it (research.md D4, mutation-checklist row 15).
        $this->referenceServer?->stopHttp();
        $this->referenceServer = null;

        Http::fake();

        $conversation = Conversation::factory()->create(['user_id' => $this->user->id]);
        $result = json_decode(
            app(AgentLoopService::class)->executeMetaTool('search_operations', ['query' => 'echo'], $conversation),
            true,
        );

        Http::assertNothingSent();

        $results = $result['results'] ?? [];
        $match = collect($results)->firstWhere('operationId', $tool->synthetic_operation_id);
        $this->assertNotNull($match, 'the cached row must still be found from the local cache alone, regardless of the server\'s current reachability; got: '.json_encode($result));
    }

    #[Test]
    public function invoking_a_discovered_external_tool_through_execute_operation_and_confirming_it_returns_its_result_in_the_same_envelope_shape_a_built_in_operations_result_would(): void
    {
        $server = $this->discoverServer('Echo Server', Protocol::MODE_HAPPY_PATH);
        $tool = McpClientTool::where('server_id', $server->id)->where('name', 'reference_echo')->firstOrFail();

        $conversation = $this->conversationWithAgentPermitting([$tool->synthetic_operation_id]);

        // AC1/AC2: the agent finds it via execute_operation and invokes it
        // -- the confirmation marker every external tool call produces
        // unconditionally (McpClientCallValidator).
        $confirmResult = json_decode(
            app(AgentLoopService::class)->executeMetaTool(
                'execute_operation',
                ['operationId' => $tool->synthetic_operation_id, 'parameters' => ['text' => 'hello external world']],
                $conversation,
            ),
            true,
        );
        $this->assertTrue((bool) ($confirmResult['__requires_confirmation'] ?? false), 'got: '.json_encode($confirmResult));
        $this->assertSame('external_tool', $confirmResult['confirmation_type'] ?? null);

        // Approving it -- the established precedent for driving past a
        // confirmation pause in a test (AgentLoopMetricsIntegrationTest::
        // resume_sync_records_the_confirmed_operation()).
        $message = $this->pendingConfirmationMessage($conversation, $tool, ['text' => 'hello external world']);
        $service = $this->serviceWithScriptedProvider([$this->textResponse('Done.')]);

        $final = $service->resumeSync($conversation, $message, true);

        $this->assertSame('completed', $final['status'] ?? null, 'got: '.json_encode($final));

        $toolResults = $message->fresh()->tool_data['tool_results'] ?? [];
        $this->assertArrayHasKey('tool_call_id', $toolResults[0] ?? [], 'a confirmed external tool result must be recorded the same way a built-in operation\'s is -- tool_call_id + content');
        $this->assertArrayHasKey('content', $toolResults[0] ?? []);

        $content = json_decode($toolResults[0]['content'], true);
        $this->assertIsArray($content, 'the reference server\'s own MCP content envelope must survive intact');
        $this->assertSame('hello external world', $content['content'][0]['text'] ?? null, 'the real ReferenceMcpServer round-trip actually ran, not a stand-in');
        $this->assertFalse($content['isError'] ?? true);
    }

    #[Test]
    public function a_tool_level_failure_reported_by_the_external_tool_itself_is_returned_clearly_rather_than_as_a_stall_or_crash(): void
    {
        $server = $this->discoverServer('Failing Tool Server', Protocol::MODE_HAPPY_PATH);
        $tool = McpClientTool::where('server_id', $server->id)->where('name', 'reference_fail')->firstOrFail();

        $conversation = $this->conversationWithAgentPermitting([$tool->synthetic_operation_id]);

        $confirmResult = json_decode(
            app(AgentLoopService::class)->executeMetaTool(
                'execute_operation',
                ['operationId' => $tool->synthetic_operation_id, 'parameters' => []],
                $conversation,
            ),
            true,
        );
        $this->assertTrue((bool) ($confirmResult['__requires_confirmation'] ?? false), 'got: '.json_encode($confirmResult));

        $message = $this->pendingConfirmationMessage($conversation, $tool, []);
        $service = $this->serviceWithScriptedProvider([
            $this->textResponse('The tool reported it could not complete the request.'),
        ]);

        $final = $service->resumeSync($conversation, $message, true);

        // AC4: the loop must complete, not stall or crash -- the model
        // gets a legible failure it can explain, and the turn finishes.
        $this->assertSame('completed', $final['status'] ?? null, 'got: '.json_encode($final));

        $toolResults = $message->fresh()->tool_data['tool_results'] ?? [];
        $content = json_decode($toolResults[0]['content'] ?? 'null', true);

        $this->assertIsArray($content, 'a tool-level failure must still arrive as a real, parseable envelope, never an opaque stall');
        $this->assertTrue($content['isError'] ?? false, 'reference_fail always reports isError -- the failure must be legible in the same shape a genuine external isError result would carry');
        $this->assertStringContainsString('reference_fail always reports a tool-level failure', $content['content'][0]['text'] ?? '');
    }
}
