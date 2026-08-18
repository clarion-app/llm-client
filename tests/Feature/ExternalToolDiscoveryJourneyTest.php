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

    /**
     * Re-confirms search_operations_reads_only_the_cache_and_makes_no_outbound_call_...'s
     * own guarantee, but with a second server that was never reachable
     * in the first place -- rather than one made unreachable mid-test --
     * sitting in the eligible-server set alongside the reachable one, so
     * a persistently broken configured server can never slow or block a
     * search that has nothing to do with it.
     */
    #[Test]
    public function search_operations_still_returns_the_reachable_servers_results_promptly_while_a_second_configured_server_is_unreachable(): void
    {
        $reachable = $this->discoverServer('Reachable Server', Protocol::MODE_HAPPY_PATH);
        $reachableTool = McpClientTool::where('server_id', $reachable->id)->where('name', 'reference_echo')->firstOrFail();

        // The reachable server's own process is no longer needed once its
        // tool is cached -- search must never contact it regardless.
        $this->referenceServer?->stopHttp();
        $this->referenceServer = null;

        // A second, distinct configured server that has never been
        // successfully contactable at all.
        $this->discoverServer('Unreachable Server', Protocol::MODE_UNREACHABLE);

        $conversation = Conversation::factory()->create(['user_id' => $this->user->id]);

        $start = microtime(true);
        $result = json_decode(
            app(AgentLoopService::class)->executeMetaTool('search_operations', ['query' => 'echo'], $conversation),
            true,
        );
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(
            1.0,
            $elapsed,
            'search_operations must complete promptly even though one configured server is unreachable -- it must never attempt to contact it'
        );

        $results = $result['results'] ?? [];
        $match = collect($results)->firstWhere('operationId', $reachableTool->synthetic_operation_id);
        $this->assertNotNull($match, 'the reachable server\'s tool must still be found; got: '.json_encode($result));
    }

    /**
     * Extends invoking_a_discovered_external_tool_through_execute_operation_...'s
     * own confirm-then-approve round trip: this time the server behind
     * the already-cached tool has turned slow since it was last
     * discovered, proving McpClientToolExecutor::execute() -- already
     * unit-proven bounded by McpClientToolExecutorFailureIsolationTest's
     * own slow-server case -- is genuinely reached and cut off at this,
     * agent-facing level too. The turn completes with the same standard
     * error envelope that case proves, never a hang or a raw exception.
     */
    #[Test]
    public function a_slow_external_tool_call_is_cut_off_at_the_configured_call_timeout_and_the_agent_receives_a_legible_failure_rather_than_a_hang(): void
    {
        $server = $this->discoverServer('Slow Server', Protocol::MODE_HAPPY_PATH);
        $tool = McpClientTool::where('server_id', $server->id)->where('name', 'reference_echo')->firstOrFail();

        // The tool was cached while the server was still fast -- now the
        // server behind it has turned slow, mirroring how a real
        // third-party server can degrade after its tools were last
        // discovered. A short call_timeout_seconds keeps this test fast
        // rather than waiting out the real default.
        $this->referenceServer?->stopHttp();
        $this->referenceServer = new ReferenceMcpServer();
        $slowUrl = $this->referenceServer->startHttp(Protocol::MODE_SLOW);
        $server->update(['url' => $slowUrl]);
        config(['llm-client.mcp_client.call_timeout_seconds' => 1]);

        $conversation = $this->conversationWithAgentPermitting([$tool->synthetic_operation_id]);

        $confirmResult = json_decode(
            app(AgentLoopService::class)->executeMetaTool(
                'execute_operation',
                ['operationId' => $tool->synthetic_operation_id, 'parameters' => ['text' => 'hello']],
                $conversation,
            ),
            true,
        );
        $this->assertTrue((bool) ($confirmResult['__requires_confirmation'] ?? false), 'got: '.json_encode($confirmResult));

        $message = $this->pendingConfirmationMessage($conversation, $tool, ['text' => 'hello']);
        $service = $this->serviceWithScriptedProvider([
            $this->textResponse('The external tool did not respond in time.'),
        ]);

        $start = microtime(true);
        $final = $service->resumeSync($conversation, $message, true);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(
            10.0,
            $elapsed,
            'a slow server must be cut off at the configured call_timeout_seconds bound, not left to hang toward the real default'
        );
        $this->assertSame('completed', $final['status'] ?? null, 'the turn must still complete -- a slow external server must never stall the conversation; got: '.json_encode($final));

        $toolResults = $message->fresh()->tool_data['tool_results'] ?? [];
        $content = json_decode($toolResults[0]['content'] ?? 'null', true);

        $this->assertIsArray($content, 'a timeout must still arrive as a real, parseable envelope, never an opaque stall');
        $this->assertTrue($content['isError'] ?? false, 'got: '.json_encode($content));
        $this->assertStringStartsWith(
            'Error: ',
            $content['content'][0]['text'] ?? '',
            'the raw transport timeout exception must never reach the conversation directly -- only the standard error envelope'
        );
        $this->assertStringNotContainsString('Stack trace', $content['content'][0]['text'] ?? '');
        $this->assertStringNotContainsString(
            'McpTransportTimeoutException',
            $content['content'][0]['text'] ?? '',
            'the envelope must carry a plain-language message, not the exception\'s own class name'
        );
    }
}
