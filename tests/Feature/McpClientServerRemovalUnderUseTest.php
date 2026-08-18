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
use ClarionApp\LlmClient\Models\ToolInvocationRecord;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\McpClientToolDiscoveryService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\MetricsRecorder;
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
 * D8/US4: removal-under-use is already correct in production today --
 * SoftDeletingScope excludes a deleted McpClientServer's tools from both
 * McpClientTool::scopeActive()'s whereHas('server', ...) predicate and
 * McpClientCallValidator/AgentLoopService::handleExecuteOperation()'s own
 * cache-miss short-circuit, since both resolve the server relation
 * through the same globally-scoped query. This file closes the one real
 * gap: no existing test drives an actual $server->delete() against a
 * server that already has cached tools AND a prior recorded tool
 * invocation, then asserts on search/execute/removal behavior and on the
 * prior invocation's own survival, all together, end to end.
 *
 * Written expecting green on first run (confirm-or-fix, not red-first
 * TDD) -- this is a test-coverage gap, not a production one.
 */
class McpClientServerRemovalUnderUseTest extends TestCase
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

        DB::table('tool_invocation_records')->delete();
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
    // Fixture scaffolding -- mirrors ExternalToolNoLongerAvailableTest's
    // and ExternalToolConfirmationJourneyTest's own identical helpers.
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

    private function searchFor(string $query): array
    {
        $conversation = Conversation::factory()->create(['user_id' => $this->user->id]);

        return json_decode(
            app(AgentLoopService::class)->executeMetaTool('search_operations', ['query' => $query], $conversation),
            true,
        );
    }

    /**
     * A conversation bound to a real Server with a valid server_url, so
     * resumeSync()'s own continuation can resolve a provider -- mirrors
     * ExternalToolConfirmationJourneyTest::conversationWithRealServer()'s
     * identical setup.
     */
    private function conversationWithRealServer(): Conversation
    {
        $server = Server::create([
            'name' => 'Removal-Under-Use LLM Server',
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
     * Message -- mirrors ExternalToolConfirmationJourneyTest::
     * pendingConfirmationMessage()'s identical helper, the established
     * precedent for driving past a confirmation pause without a live
     * user turn.
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

    #[Test]
    public function removing_a_server_moments_after_an_agent_used_one_of_its_tools_completes_cleanly_and_excludes_it_everywhere_while_leaving_the_prior_invocation_untouched(): void
    {
        $this->referenceServer = new ReferenceMcpServer();
        $url = $this->referenceServer->startHttp(Protocol::MODE_HAPPY_PATH, [
            'dynamic_tools' => ['reference_echo'],
        ]);

        $server = McpClientServer::create([
            'name' => 'Soon Removed Server',
            'transport' => McpTransportKind::StreamableHttp,
            'url' => $url,
            'user_id' => $this->user->id,
        ]);

        // Run discovery so the server has cached tools (AS1/AS2's own
        // starting condition).
        app(McpClientToolDiscoveryService::class)->discover($server);

        $tool = McpClientTool::where('server_id', $server->id)->where('name', 'reference_echo')->firstOrFail();
        $operationId = $tool->synthetic_operation_id;

        // Invoke the tool via the real agent-loop/McpClientToolExecutor
        // path (approving a pending external-tool confirmation through
        // resumeSync(), which -- unlike resume()'s async/queued sibling
        // -- calls recordToolMetric() for its external_tool branch) so a
        // prior invocation is genuinely recorded, against the real
        // reference server, no mocked executor.
        $conversation = $this->conversationWithRealServer();
        $arguments = ['text' => 'hello'];
        $message = $this->pendingConfirmationMessage($conversation, $tool, $arguments);

        $registry = app(ProviderRegistry::class);
        $registry->register('openai', fn ($s) => $this->fakeProvider([$this->textResponse('Done.')]));

        // Constructed manually with only the arguments this path actually
        // needs -- mirrors ExternalToolConfirmationJourneyTest's own
        // identical precedent -- rather than app(AgentLoopService::class),
        // which would also auto-wire a real ConversationCondenser whose
        // condensation_states table this shared test schema (Grounding
        // note 3, tests/TestCase.php's own hand-declared schema) does not
        // define; metricsRecorder is passed explicitly (by name) since it
        // is the one optional dependency this test genuinely needs live,
        // to actually record the prior invocation.
        $service = new AgentLoopService(
            Mockery::mock(McpToolRegistry::class),
            Mockery::mock(McpToolExecutor::class),
            new OperationCache(),
            $registry,
            metricsRecorder: app(MetricsRecorder::class),
        );

        $final = $service->resumeSync($conversation, $message, true);
        $this->assertSame('completed', $final['status'] ?? null, 'the tool invocation must genuinely succeed before removal is even attempted; got: '.json_encode($final));

        $toolResults = $message->fresh()->tool_data['tool_results'] ?? [];
        $executedContent = json_decode($toolResults[0]['content'] ?? 'null', true);
        $this->assertFalse($executedContent['isError'] ?? true, 'the real reference server call must have succeeded; got: '.json_encode($executedContent));

        $this->assertSame(
            1,
            ToolInvocationRecord::query()->count(),
            'invoking the tool through resumeSync() must record exactly one prior invocation before the server is ever removed'
        );
        $priorInvocation = ToolInvocationRecord::query()->firstOrFail();
        $priorInvocationId = $priorInvocation->id;
        $priorInvocationAttributes = $priorInvocation->getAttributes();

        // Baseline: the tool is still discoverable before removal.
        $beforeResults = $this->searchFor('reference');
        $beforeIds = collect($beforeResults['results'] ?? [])->pluck('operationId')->all();
        $this->assertContains($operationId, $beforeIds, 'the tool must be discoverable before the server is removed');

        // DELETE -- no error (AS1/AS2).
        $response = $this->actingAs($this->user)->deleteJson("/api/clarion-app/llm-client/mcp-client-server/{$server->id}");
        $response->assertStatus(204);

        // GET list no longer shows it (AS1).
        $listResponse = $this->actingAs($this->user)->getJson('/api/clarion-app/llm-client/mcp-client-server');
        $listResponse->assertOk();
        $listIds = collect($listResponse->json())->pluck('id')->all();
        $this->assertNotContains($server->id, $listIds, 'a removed server must no longer appear in the caller\'s list');

        // search_operations no longer surfaces its tools (AS1).
        $afterResults = $this->searchFor('reference');
        $afterIds = collect($afterResults['results'] ?? [])->pluck('operationId')->all();
        $this->assertNotContains($operationId, $afterIds, 'a removed server\'s tools must no longer be surfaced by search_operations');

        // execute_operation against the previously-known operationId fails
        // cleanly with the existing local "no longer offered" message --
        // no exception, no network call attempted. The reference server is
        // stopped outright, on top of Http::fake(), so any attempt to
        // actually reach it would be caught two different ways (mirrors
        // ExternalToolNoLongerAvailableTest's own identical precedent).
        $this->referenceServer->stopHttp();
        $this->referenceServer = null;
        Http::fake();

        $executeConversation = Conversation::factory()->create(['user_id' => $this->user->id]);
        $executeResult = json_decode(
            app(AgentLoopService::class)->executeMetaTool(
                'execute_operation',
                ['operationId' => $operationId, 'parameters' => ['text' => 'hello']],
                $executeConversation,
            ),
            true,
        );

        Http::assertNothingSent();
        $this->assertSame(
            'This tool is no longer offered by its server. Search again for a current capability.',
            $executeResult['error'] ?? null,
            'a removed server\'s previously-known operationId must fail cleanly and locally, never a hang, crash, or an attempt to actually reach the server; got: '.json_encode($executeResult)
        );

        // The prior recorded invocation is untouched by the deletion
        // (AS2) -- tool_invocation_records carries no FK to
        // mcp_client_servers at all, so a soft-delete cannot cascade into
        // it either way; asserted directly rather than only inferred.
        $this->assertSame(
            1,
            ToolInvocationRecord::query()->count(),
            'the deletion must never remove or duplicate the prior invocation record'
        );
        $survivingInvocation = ToolInvocationRecord::query()->firstOrFail();
        $this->assertSame($priorInvocationId, $survivingInvocation->id);
        $this->assertSame($priorInvocationAttributes, $survivingInvocation->getAttributes(), 'the prior invocation\'s own row must be byte-for-byte unchanged by the server\'s removal');
    }
}
