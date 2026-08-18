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
use ClarionApp\LlmClient\Services\McpTransport;
use ClarionApp\LlmClient\Services\McpTransportFactory;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\ValueObjects\McpTransportKind;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A server's previously-discovered tools survive any number of
 * consecutive failed discovery attempts, fully searchable and invokable
 * throughout, while a genuine, server-confirmed removal still takes
 * effect exactly as promptly as before -- proven end-to-end through
 * McpClientToolDiscoveryService::discover(), AgentLoopService::
 * executeMetaTool('search_operations'/'execute_operation'), and
 * AgentLoopService::resumeSync(), the same three entry points
 * ExternalToolNoLongerAvailableTest and ExternalToolConfirmationJourneyTest
 * already exercise, driven with a mocked McpTransportFactory bound into
 * the container -- mirroring McpClientToolDiscoveryServiceTest's own
 * scripted-double approach -- rather than a real fixture process, since
 * the property under test here is what search/execute do with an
 * already-cached row across a scripted sequence of discovery outcomes,
 * not transport behavior itself. McpClientToolDiscoveryService and
 * McpClientToolExecutor both take McpTransportFactory by constructor
 * injection and are both resolved fresh from the container at each call
 * site below, so binding one mock factory instance drives every
 * discover() and every actual invocation through the identical scripted
 * sequence.
 */
class ExternalToolTransientOutageJourneyTest extends TestCase
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
        Carbon::setTestNow();

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

    private function operationIds(array $searchResult): array
    {
        return collect($searchResult['results'] ?? [])->pluck('operationId')->all();
    }

    /**
     * A conversation bound to a real Server with a valid server_url, so
     * resumeSync()'s own next-iteration dispatch can resolve a provider
     * for it -- mirrors ExternalToolConfirmationJourneyTest's identical
     * helper.
     */
    private function conversationWithRealServer(): Conversation
    {
        $server = Server::create([
            'name' => 'Transient Outage Journey LLM Server',
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
     * Message, the exact shape AgentLoopService::handleExecuteOperation()'s
     * own pause-storage sites reconstruct -- mirrors
     * ExternalToolConfirmationJourneyTest::pendingConfirmationMessage()'s
     * identical helper.
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
    // Scripted transport doubles -- mirrors
    // McpClientToolDiscoveryServiceTest::mockTransport()'s own shape, plus
    // a sibling for a McpClientToolExecutor::execute() attempt (initialize
    // then callTool, rather than initialize then listTools).
    // -----------------------------------------------------------------

    private function discoveryTransport(array|\Throwable $toolsOrException): McpTransport
    {
        $transport = Mockery::mock(McpTransport::class);

        if ($toolsOrException instanceof \Throwable) {
            $transport->shouldReceive('initialize')->once()->andThrow($toolsOrException);
        } else {
            $transport->shouldReceive('initialize')->once();
            $transport->shouldReceive('listTools')->once()->andReturn($toolsOrException);
        }

        return $transport;
    }

    private function failingInvocationTransport(\Throwable $exception): McpTransport
    {
        $transport = Mockery::mock(McpTransport::class);
        $transport->shouldReceive('initialize')->once()->andThrow($exception);

        return $transport;
    }

    #[Test]
    public function search_and_invocation_survive_repeated_transient_outages_while_a_genuine_removal_still_takes_effect_promptly(): void
    {
        $schema = ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']];
        $bothTools = [
            ['name' => 'reference_echo', 'description' => 'Echoes back the provided text.', 'inputSchema' => $schema, 'annotations' => null],
            ['name' => 'reference_fail', 'description' => 'Always fails when invoked.', 'inputSchema' => ['type' => 'object'], 'annotations' => null],
        ];
        $onlyEcho = [$bothTools[0]];
        $stillDown = new \RuntimeException('connection refused');

        // Seven scripted transport outcomes, in the exact order this
        // test drives them: an initial happy-path discovery; one failed
        // discovery; one failed invocation attempt against the tool the
        // failed discovery left in its grace period; two more
        // consecutive failed discoveries (three in a row overall); a
        // discovery that succeeds and reports the identical two tools;
        // and a final discovery that succeeds but genuinely no longer
        // reports one of them.
        $factory = Mockery::mock(McpTransportFactory::class);
        $factory->shouldReceive('for')->times(7)->andReturn(
            $this->discoveryTransport($bothTools),
            $this->discoveryTransport($stillDown),
            $this->failingInvocationTransport($stillDown),
            $this->discoveryTransport($stillDown),
            $this->discoveryTransport($stillDown),
            $this->discoveryTransport($bothTools),
            $this->discoveryTransport($onlyEcho),
        );
        $this->app->instance(McpTransportFactory::class, $factory);

        $server = McpClientServer::create([
            'name' => 'Currency Server',
            'transport' => McpTransportKind::StreamableHttp,
            'url' => 'https://example.test/mcp',
            'user_id' => $this->user->id,
        ]);

        $discovery = app(McpClientToolDiscoveryService::class);

        // ---------------------------------------------------------
        // Baseline: both tools discovered, searchable, and resolvable.
        // ---------------------------------------------------------
        $initialStatus = $discovery->discover($server);
        $this->assertSame('reachable', $initialStatus->connection_status);
        $this->assertSame(2, $initialStatus->tool_count);

        $echoTool = McpClientTool::where('server_id', $server->id)->where('name', 'reference_echo')->firstOrFail();
        $failTool = McpClientTool::where('server_id', $server->id)->where('name', 'reference_fail')->firstOrFail();

        $baselineIds = $this->operationIds($this->searchFor('reference'));
        $this->assertContains($echoTool->synthetic_operation_id, $baselineIds);
        $this->assertContains($failTool->synthetic_operation_id, $baselineIds);

        $markerConversation = Conversation::factory()->create(['user_id' => $this->user->id]);
        foreach ([$echoTool, $failTool] as $tool) {
            $marker = json_decode(
                app(AgentLoopService::class)->executeMetaTool(
                    'execute_operation',
                    ['operationId' => $tool->synthetic_operation_id, 'parameters' => []],
                    $markerConversation,
                ),
                true,
            );
            $this->assertTrue((bool) ($marker['__requires_confirmation'] ?? false), "expected {$tool->name} to resolve to a confirmation marker, got: ".json_encode($marker));
            $this->assertSame($tool->name, $marker['tool_name'] ?? null);
        }

        $echoAttributesBeforeOutage = McpClientTool::find($echoTool->id)->getAttributes();
        $failAttributesBeforeOutage = McpClientTool::find($failTool->id)->getAttributes();

        // ---------------------------------------------------------
        // One failed discovery attempt: the status row advances, no
        // tool row is touched at all.
        // ---------------------------------------------------------
        Carbon::setTestNow(Carbon::now()->addSeconds(2));
        $firstFailure = $discovery->discover($server);
        $this->assertSame('unreachable', $firstFailure->connection_status);
        $this->assertNotNull($firstFailure->refresh_finished_at);
        $this->assertTrue($firstFailure->refresh_finished_at->greaterThan($initialStatus->refresh_finished_at));

        $this->assertSame($echoAttributesBeforeOutage, McpClientTool::find($echoTool->id)->getAttributes(), 'a failed discovery attempt must never touch an untouched tool row');
        $this->assertSame($failAttributesBeforeOutage, McpClientTool::find($failTool->id)->getAttributes(), 'a failed discovery attempt must never touch an untouched tool row');

        // Acceptance Scenario 1: search is unaffected by a single failed attempt.
        $afterOneFailureIds = $this->operationIds($this->searchFor('reference'));
        $this->assertContains($echoTool->synthetic_operation_id, $afterOneFailureIds);
        $this->assertContains($failTool->synthetic_operation_id, $afterOneFailureIds);

        // ---------------------------------------------------------
        // Acceptance Scenario 5: a tool searchable only because of the
        // grace period still gets a real invocation attempt (not a
        // local short-circuit) when the server is genuinely still down.
        // ---------------------------------------------------------
        $graceMarker = json_decode(
            app(AgentLoopService::class)->executeMetaTool(
                'execute_operation',
                ['operationId' => $echoTool->synthetic_operation_id, 'parameters' => ['text' => 'hello']],
                $markerConversation,
            ),
            true,
        );
        $this->assertTrue((bool) ($graceMarker['__requires_confirmation'] ?? false), 'a tool inside its grace period must still resolve to a confirmation marker, not the local "no longer offered" short-circuit; got: '.json_encode($graceMarker));

        $invocationConversation = $this->conversationWithRealServer();
        $pendingMessage = $this->pendingConfirmationMessage($invocationConversation, $echoTool, ['text' => 'hello']);

        $registry = app(ProviderRegistry::class);
        $registry->register('openai', fn ($server) => $this->fakeProvider([$this->textResponse('Done.')]));

        $confirmationService = new AgentLoopService(
            Mockery::mock(McpToolRegistry::class),
            Mockery::mock(McpToolExecutor::class),
            new OperationCache(),
            $registry,
        );

        $final = $confirmationService->resumeSync($invocationConversation, $pendingMessage, true);
        $this->assertSame('completed', $final['status'] ?? null, 'got: '.json_encode($final));

        $dispatchedResult = json_decode($pendingMessage->fresh()->tool_data['tool_results'][0]['content'] ?? 'null', true);
        $this->assertTrue($dispatchedResult['isError'] ?? false, 'a genuinely-still-down server must still fail the call, not silently succeed; got: '.json_encode($dispatchedResult));
        $this->assertStringStartsWith('Error: ', $dispatchedResult['content'][0]['text'] ?? '', 'a genuinely-still-down invocation must fail with the ordinary transport-failure shape');
        $this->assertArrayNotHasKey('error', $dispatchedResult, 'a genuinely-still-down invocation must reach the actual transport attempt, never the local "no longer offered" short-circuit');

        // ---------------------------------------------------------
        // Acceptance Scenario 2: two more consecutive failures (three
        // total) never remove either tool from search on their own.
        // ---------------------------------------------------------
        foreach (range(1, 2) as $_) {
            Carbon::setTestNow(Carbon::now()->addSeconds(2));
            $status = $discovery->discover($server);
            $this->assertSame('unreachable', $status->connection_status);
        }

        $afterThreeFailuresIds = $this->operationIds($this->searchFor('reference'));
        $this->assertContains($echoTool->synthetic_operation_id, $afterThreeFailuresIds);
        $this->assertContains($failTool->synthetic_operation_id, $afterThreeFailuresIds);

        // ---------------------------------------------------------
        // Acceptance Scenario 3: a subsequent success reporting the
        // identical tools keeps the same identities, no gap.
        // ---------------------------------------------------------
        Carbon::setTestNow(Carbon::now()->addSeconds(2));
        $recoveryStatus = $discovery->discover($server);
        $this->assertSame('reachable', $recoveryStatus->connection_status);
        $this->assertSame(2, $recoveryStatus->tool_count);

        $this->assertSame($echoTool->id, McpClientTool::where('server_id', $server->id)->where('name', 'reference_echo')->firstOrFail()->id);
        $this->assertSame($echoTool->synthetic_operation_id, McpClientTool::find($echoTool->id)->synthetic_operation_id);
        $this->assertSame($failTool->id, McpClientTool::where('server_id', $server->id)->where('name', 'reference_fail')->firstOrFail()->id);
        $this->assertSame($failTool->synthetic_operation_id, McpClientTool::find($failTool->id)->synthetic_operation_id);

        // ---------------------------------------------------------
        // Acceptance Scenario 4: a genuine, confirmed removal still
        // takes effect promptly on the very next successful discovery.
        // ---------------------------------------------------------
        Carbon::setTestNow(Carbon::now()->addSeconds(2));
        $removalStatus = $discovery->discover($server);
        $this->assertSame('reachable', $removalStatus->connection_status);
        $this->assertSame(1, $removalStatus->tool_count);

        $finalIds = $this->operationIds($this->searchFor('reference'));
        $this->assertContains($echoTool->synthetic_operation_id, $finalIds, 'a tool the server still offers must remain discoverable');
        $this->assertNotContains($failTool->synthetic_operation_id, $finalIds, 'a tool the server genuinely stopped offering must no longer be discoverable');

        $removedToolResult = json_decode(
            app(AgentLoopService::class)->executeMetaTool(
                'execute_operation',
                ['operationId' => $failTool->synthetic_operation_id, 'parameters' => []],
                $markerConversation,
            ),
            true,
        );
        $this->assertSame(
            'This tool is no longer offered by its server. Search again for a current capability.',
            $removedToolResult['error'] ?? null,
        );
    }
}
