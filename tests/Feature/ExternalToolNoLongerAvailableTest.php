<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\McpClientServer;
use ClarionApp\LlmClient\Models\McpClientTool;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\McpClientToolDiscoveryService;
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
 * User Story 5's own Acceptance Scenarios: a server's tool offering
 * changes on its own schedule, independent of anything Clarion did, and
 * the next refresh -- not a new McpClientServer row, the SAME one --
 * must make search reflect both the addition and the removal, while an
 * attempt to invoke the removed tool by its previously-known synthetic
 * operationId must fail cleanly and locally, never by reaching out to
 * the server at all.
 */
class ExternalToolNoLongerAvailableTest extends TestCase
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
        \Illuminate\Support\Carbon::setTestNow();

        DB::table('conversations')->delete();
        DB::table('mcp_client_tools')->delete();
        DB::table('mcp_client_server_statuses')->delete();
        DB::table('mcp_client_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    /**
     * Mirrors ExternalToolDiscoveryJourneyTest's own identical helper --
     * required before any AgentDefinitionParser::parse() call, though
     * this file never binds a conversation to an agent version at all
     * (every mcp: operationId is exempted from the catalog-emptiness
     * check, and every invocation here goes through a plain,
     * unbound conversation, the same posture ExternalToolDenylistTest's
     * own direct-invoke tests use).
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

    private function searchFor(string $query): array
    {
        $conversation = Conversation::factory()->create(['user_id' => $this->user->id]);

        return json_decode(
            app(AgentLoopService::class)->executeMetaTool('search_operations', ['query' => $query], $conversation),
            true,
        );
    }

    #[Test]
    public function a_refresh_against_the_same_server_reflects_an_added_and_a_removed_tool_without_any_change_to_the_server_row(): void
    {
        $this->referenceServer = new ReferenceMcpServer();
        $url = $this->referenceServer->startHttp(Protocol::MODE_HAPPY_PATH, [
            'dynamic_tools' => ['reference_echo', 'reference_fail'],
        ]);

        $server = McpClientServer::create([
            'name' => 'Currency Server',
            'transport' => McpTransportKind::StreamableHttp,
            'url' => $url,
            'user_id' => $this->user->id,
        ]);
        $originalAttributes = $server->fresh()->getAttributes();

        app(McpClientToolDiscoveryService::class)->discover($server);

        $echoTool = McpClientTool::where('server_id', $server->id)->where('name', 'reference_echo')->firstOrFail();
        $failTool = McpClientTool::where('server_id', $server->id)->where('name', 'reference_fail')->firstOrFail();

        $beforeResults = $this->searchFor('reference');
        $beforeIds = collect($beforeResults['results'] ?? [])->pluck('operationId')->all();
        $this->assertContains($echoTool->synthetic_operation_id, $beforeIds);
        $this->assertContains($failTool->synthetic_operation_id, $beforeIds, 'the soon-to-be-removed tool must still be found before the reconfiguration');

        // The server's operator changes what it offers, entirely out of
        // band -- reference_fail drops off, reference_summarize appears
        // -- without touching the McpClientServer row's own connection
        // details at all (same URL, same process).
        $this->referenceServer->setTools(['reference_echo', 'reference_summarize']);

        // The two refreshes must land in different, genuinely-comparable
        // seconds -- mirroring McpClientToolDiscoveryServiceTest's own
        // identical precedent -- since last_seen_at/refresh_finished_at
        // store no sub-second precision, and real refreshes are always
        // seconds apart in practice (a five-minute sweep, or a human
        // clicking "refresh").
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::now()->addSeconds(2));

        // The scheduled/manual refresh re-running against the SAME
        // server row -- AC1/AC2.
        app(McpClientToolDiscoveryService::class)->discover($server);

        $this->assertSame(
            $originalAttributes,
            $server->fresh()->getAttributes(),
            'reconfiguring what a server offers must never touch the McpClientServer row itself'
        );

        $summarizeTool = McpClientTool::where('server_id', $server->id)->where('name', 'reference_summarize')->firstOrFail();

        $afterResults = $this->searchFor('reference');
        $afterIds = collect($afterResults['results'] ?? [])->pluck('operationId')->all();

        $this->assertContains($echoTool->synthetic_operation_id, $afterIds, 'a tool the server still offers must remain discoverable across the refresh');
        $this->assertContains($summarizeTool->synthetic_operation_id, $afterIds, 'AC1: a newly added tool must be discoverable after the next refresh');
        $this->assertNotContains($failTool->synthetic_operation_id, $afterIds, 'AC2: a tool the server stopped offering must no longer be discoverable');
    }

    #[Test]
    public function invoking_a_since_removed_tools_previously_known_operation_id_fails_cleanly_and_locally_with_no_network_call_attempted(): void
    {
        $this->referenceServer = new ReferenceMcpServer();
        $url = $this->referenceServer->startHttp(Protocol::MODE_HAPPY_PATH, [
            'dynamic_tools' => ['reference_echo', 'reference_fail'],
        ]);

        $server = McpClientServer::create([
            'name' => 'Vanishing Tool Server',
            'transport' => McpTransportKind::StreamableHttp,
            'url' => $url,
            'user_id' => $this->user->id,
        ]);

        app(McpClientToolDiscoveryService::class)->discover($server);
        $removedTool = McpClientTool::where('server_id', $server->id)->where('name', 'reference_fail')->firstOrFail();
        $removedOperationId = $removedTool->synthetic_operation_id;

        // Server-side removal, then a refresh that genuinely observes it
        // -- AC3 (US5): the row survives (mcp_client_tools has no
        // deleted_at column at all), but the next completed refresh
        // never touched it, so it is no longer current. The clock
        // advance mirrors the same-second precision issue
        // McpClientToolDiscoveryServiceTest's own precedent already
        // established.
        $this->referenceServer->setTools(['reference_echo']);
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::now()->addSeconds(2));
        app(McpClientToolDiscoveryService::class)->discover($server);

        // The reference server is no longer needed for this assertion --
        // stopped outright, on top of Http::fake(), so any attempt to
        // actually reach it would be caught two different ways.
        $this->referenceServer->stopHttp();
        $this->referenceServer = null;
        Http::fake();

        $conversation = Conversation::factory()->create(['user_id' => $this->user->id]);
        $result = json_decode(
            app(AgentLoopService::class)->executeMetaTool(
                'execute_operation',
                ['operationId' => $removedOperationId, 'parameters' => ['text' => 'hello']],
                $conversation,
            ),
            true,
        );

        Http::assertNothingSent();

        $this->assertSame(
            'This tool is no longer offered by its server. Search again for a current capability.',
            $result['error'] ?? null,
            'a since-removed tool must fail with the clear, local "no longer offered" result, never a hang, crash, or an attempt to actually reach the server; got: '.json_encode($result)
        );
        $this->assertArrayNotHasKey(
            '__requires_confirmation',
            (array) $result,
            'a since-removed tool must never reach the confirmation step at all -- the local cache-miss path short-circuits before McpClientCallValidator ever runs'
        );
    }

    #[Test]
    public function a_tool_the_fixture_never_offered_in_the_first_place_still_gets_the_identical_no_longer_offered_result(): void
    {
        // The pre-existing miss case (no row ever existed for this
        // synthetic id) must resolve to the exact same wording a
        // genuinely-since-removed row resolves to -- both are, from the
        // caller's perspective, "not currently offered".
        $server = McpClientServer::create([
            'name' => 'Never Offered Server',
            'transport' => McpTransportKind::StreamableHttp,
            'url' => 'https://example.test/mcp',
            'user_id' => $this->user->id,
        ]);

        Http::fake();

        $conversation = Conversation::factory()->create(['user_id' => $this->user->id]);
        $result = json_decode(
            app(AgentLoopService::class)->executeMetaTool(
                'execute_operation',
                ['operationId' => "mcp:{$server->id}:never_existed", 'parameters' => []],
                $conversation,
            ),
            true,
        );

        Http::assertNothingSent();
        $this->assertSame('This tool is no longer offered by its server. Search again for a current capability.', $result['error'] ?? null);
    }
}
