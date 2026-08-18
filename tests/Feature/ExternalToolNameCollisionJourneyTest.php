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
use ClarionApp\LlmClient\Services\McpClientToolDiscoveryService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\ValueObjects\McpTransportKind;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\ReferenceMcpServer\Protocol;
use Tests\Fixtures\ReferenceMcpServer\ReferenceMcpServer;
use Tests\TestCase;

/**
 * User Story 6's own Acceptance Scenarios: two independently configured
 * servers that both happen to offer a tool named "search" must stay two
 * fully separate, correctly attributed things throughout -- distinct
 * synthetic operationIds in search results, a call by one server's id
 * genuinely reaching only that server's own process, and the record of
 * a completed call naming the exact server that carried it out.
 *
 * McpClientToolDiscoveryService already derives a synthetic_operation_id
 * of "mcp:{server_id}:{tool_name}" (McpClientToolDiscoveryServiceTest,
 * McpClientToolCatalogMergerTest) -- namespaced by the server's own UUID,
 * not merely the tool's own name -- so two servers offering the same
 * tool name were already guaranteed distinct ids before this file existed.
 * What this file proves is that the guarantee holds end to end: through
 * search, through the confirm-then-approve round trip, and through
 * which physical fixture process an approved call actually reaches.
 */
class ExternalToolNameCollisionJourneyTest extends TestCase
{
    /** @var list<ReferenceMcpServer> */
    private array $referenceServers = [];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->seedOperationCatalog();
    }

    protected function tearDown(): void
    {
        foreach ($this->referenceServers as $referenceServer) {
            $referenceServer->stopHttp();
        }
        $this->referenceServers = [];

        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('messages')->delete();
        DB::table('conversations')->delete();
        DB::table('mcp_client_tools')->delete();
        DB::table('mcp_client_server_statuses')->delete();
        DB::table('mcp_client_servers')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Fixture scaffolding
    // -----------------------------------------------------------------

    /**
     * Mirrors every other Phase 2/US2-8 journey test's identical helper:
     * ApiManager needs a resolvable (empty) catalog before
     * handleSearchOperations()/handleExecuteOperation() can run at all,
     * even though every operationId this file exercises is mcp:-prefixed
     * and never resolves through it.
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
     * Starts one real ReferenceMcpServer instance, over its own loopback
     * port, offering exactly one tool named "search" (Protocol's own
     * dynamic_tools filtering, the same mechanism
     * ExternalToolNoLongerAvailableTest already relies on to change a
     * running fixture's own tool list), with request logging turned on
     * so a later assertion can confirm which of two such instances an
     * invocation genuinely reached. Runs the real discovery service
     * against it, exactly like ExternalToolDiscoveryJourneyTest's own
     * discoverServer() helper, and keeps the ReferenceMcpServer instance
     * (not just the McpClientServer row) so the caller can read its
     * request log back later.
     */
    private function discoverSearchToolServer(string $name): McpClientServer
    {
        $referenceServer = new ReferenceMcpServer();
        $url = $referenceServer->startHttp(Protocol::MODE_HAPPY_PATH, [
            'dynamic_tools' => ['search'],
            'log_requests' => true,
        ]);
        $this->referenceServers[] = $referenceServer;

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
     * A fresh AgentLoopService with a scripted continuation provider
     * registered, mirroring ExternalToolDiscoveryJourneyTest's own
     * serviceWithScriptedProvider() -- a new instance per call so each of
     * this file's two resumeSync() round trips gets its own single-use
     * response queue rather than sharing one across both servers' calls.
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
     * A conversation bound to a real Server row, mirroring
     * ExternalToolConfirmationJourneyTest::conversationWithRealServer():
     * resumeSync()'s own continuation call needs a resolvable provider,
     * even though this file's assertions are about which external
     * server handled the tool call, not about the continuation's own
     * content.
     */
    private function conversationWithRealServer(): Conversation
    {
        $server = Server::create([
            'name' => 'Collision Journey LLM Server',
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
     * Message, mirroring ExternalToolConfirmationJourneyTest's own
     * pendingConfirmationMessage() -- the established precedent for
     * driving past a confirmation pause without a live user turn.
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

    // -----------------------------------------------------------------
    // AC1: both tools appear as distinct, separately invokable results
    // -----------------------------------------------------------------

    #[Test]
    public function two_servers_offering_an_identically_named_search_tool_produce_two_distinct_discoverable_operation_ids(): void
    {
        $serverA = $this->discoverSearchToolServer('Alpha Search Server');
        $serverB = $this->discoverSearchToolServer('Beta Search Server');

        $toolA = McpClientTool::where('server_id', $serverA->id)->where('name', 'search')->firstOrFail();
        $toolB = McpClientTool::where('server_id', $serverB->id)->where('name', 'search')->firstOrFail();

        $this->assertSame("mcp:{$serverA->id}:search", $toolA->synthetic_operation_id);
        $this->assertSame("mcp:{$serverB->id}:search", $toolB->synthetic_operation_id);
        $this->assertNotSame(
            $toolA->synthetic_operation_id,
            $toolB->synthetic_operation_id,
            'two servers offering an identically-named tool must never collapse into one cached row'
        );

        $conversation = Conversation::factory()->create(['user_id' => $this->user->id]);
        $result = json_decode(
            app(AgentLoopService::class)->executeMetaTool('search_operations', ['query' => 'search'], $conversation),
            true,
        );

        $results = $result['results'] ?? [];
        $matchA = collect($results)->firstWhere('operationId', $toolA->synthetic_operation_id);
        $matchB = collect($results)->firstWhere('operationId', $toolB->synthetic_operation_id);

        $this->assertNotNull($matchA, 'Server A\'s own "search" tool must appear in results; got: '.json_encode($result));
        $this->assertNotNull($matchB, 'Server B\'s own "search" tool must appear in results; got: '.json_encode($result));
        $this->assertNotEquals($matchA, $matchB, 'the two same-named tools must be two genuinely distinct results, not one entry counted twice');

        $this->assertStringStartsWith('[External tool via Alpha Search Server]', $matchA['summary'] ?? '');
        $this->assertStringStartsWith('[External tool via Beta Search Server]', $matchB['summary'] ?? '');
    }

    // -----------------------------------------------------------------
    // AC2/AC3: invoking one by its own synthetic id reaches that exact
    // server, never the other -- and the record of each call names the
    // correct one.
    // -----------------------------------------------------------------

    #[Test]
    public function invoking_each_servers_own_search_tool_by_its_synthetic_id_reaches_only_that_server_and_the_call_record_names_it(): void
    {
        $serverA = $this->discoverSearchToolServer('Alpha Search Server');
        $serverB = $this->discoverSearchToolServer('Beta Search Server');

        $toolA = McpClientTool::where('server_id', $serverA->id)->where('name', 'search')->firstOrFail();
        $toolB = McpClientTool::where('server_id', $serverB->id)->where('name', 'search')->firstOrFail();

        [$referenceServerA, $referenceServerB] = $this->referenceServers;

        $conversation = $this->conversationWithRealServer();

        // AC3: select Server A's own version of "search" specifically.
        $confirmA = json_decode(
            app(AgentLoopService::class)->executeMetaTool(
                'execute_operation',
                ['operationId' => $toolA->synthetic_operation_id, 'parameters' => ['text' => 'alpha-call']],
                $conversation,
            ),
            true,
        );
        $this->assertTrue((bool) ($confirmA['__requires_confirmation'] ?? false), 'got: '.json_encode($confirmA));
        // AC2: the confirmation step's own response -- what a user or
        // agent actually sees before approving -- already names the
        // exact, server-qualified operationId being confirmed, not
        // merely "search".
        $this->assertSame($toolA->synthetic_operation_id, $confirmA['operationId'] ?? null);
        $this->assertSame($serverA->name, $confirmA['server_name'] ?? null);

        $messageA = $this->pendingConfirmationMessage($conversation, $toolA, ['text' => 'alpha-call']);
        $finalA = $this->serviceWithScriptedProvider([$this->textResponse('Done.')])
            ->resumeSync($conversation, $messageA, true);
        $this->assertSame('completed', $finalA['status'] ?? null, 'got: '.json_encode($finalA));

        // AC3: now Server B's own version of the identically-named tool.
        $confirmB = json_decode(
            app(AgentLoopService::class)->executeMetaTool(
                'execute_operation',
                ['operationId' => $toolB->synthetic_operation_id, 'parameters' => ['text' => 'beta-call']],
                $conversation,
            ),
            true,
        );
        $this->assertTrue((bool) ($confirmB['__requires_confirmation'] ?? false), 'got: '.json_encode($confirmB));
        $this->assertSame($toolB->synthetic_operation_id, $confirmB['operationId'] ?? null);
        $this->assertSame($serverB->name, $confirmB['server_name'] ?? null);

        $messageB = $this->pendingConfirmationMessage($conversation, $toolB, ['text' => 'beta-call']);
        $finalB = $this->serviceWithScriptedProvider([$this->textResponse('Done.')])
            ->resumeSync($conversation, $messageB, true);
        $this->assertSame('completed', $finalB['status'] ?? null, 'got: '.json_encode($finalB));

        // Dispatch correctness (AC3): each fixture process's own request
        // log shows exactly the one call meant for it, with the argument
        // that call alone carried -- never the other server's call.
        $loggedA = $referenceServerA->loggedToolCalls();
        $loggedB = $referenceServerB->loggedToolCalls();

        $this->assertCount(1, $loggedA, 'Server A\'s own process must have received exactly the one call addressed to it; got: '.json_encode($loggedA));
        $this->assertSame('search', $loggedA[0]['tool'] ?? null);
        $this->assertSame('alpha-call', $loggedA[0]['arguments']['text'] ?? null);

        $this->assertCount(1, $loggedB, 'Server B\'s own process must have received exactly the one call addressed to it; got: '.json_encode($loggedB));
        $this->assertSame('search', $loggedB[0]['tool'] ?? null);
        $this->assertSame('beta-call', $loggedB[0]['arguments']['text'] ?? null);

        // Response content corroborates the same thing from the other
        // direction: each server's own reply carries back the argument
        // only its own call supplied.
        $contentA = json_decode($messageA->fresh()->tool_data['tool_results'][0]['content'] ?? 'null', true);
        $this->assertSame('alpha-call', $contentA['content'][0]['text'] ?? null, 'got: '.json_encode($contentA));

        $contentB = json_decode($messageB->fresh()->tool_data['tool_results'][0]['content'] ?? 'null', true);
        $this->assertSame('beta-call', $contentB['content'][0]['text'] ?? null, 'got: '.json_encode($contentB));

        // AC2: "the record of that call identifies which server actually
        // handled it" -- the tool_calls entry each completed Message
        // still carries (the same free-text tool_data column every
        // built-in operation call's own record already lives in) still
        // names the full, server-qualified synthetic id after
        // pending_confirmation has been cleared, and the two calls'
        // records are never interchangeable.
        $callArgumentsA = json_decode($messageA->fresh()->tool_data['tool_calls'][0]['function']['arguments'] ?? '{}', true);
        $this->assertSame($toolA->synthetic_operation_id, $callArgumentsA['operationId'] ?? null);

        $callArgumentsB = json_decode($messageB->fresh()->tool_data['tool_calls'][0]['function']['arguments'] ?? '{}', true);
        $this->assertSame($toolB->synthetic_operation_id, $callArgumentsB['operationId'] ?? null);

        $this->assertNotSame(
            $callArgumentsA['operationId'],
            $callArgumentsB['operationId'],
            'the two completed calls\' own records must never be attributable to the same server'
        );
    }
}
