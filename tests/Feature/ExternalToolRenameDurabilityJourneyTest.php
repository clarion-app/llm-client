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
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\ReferenceMcpServer\Protocol;
use Tests\Fixtures\ReferenceMcpServer\ReferenceMcpServer;
use Tests\TestCase;

/**
 * A block an administrator establishes against a specific external tool,
 * anchored to that tool's own durable synthetic_operation_id rather than
 * its current name, must keep applying after the offering server renames
 * the tool, redescribes it, or both -- with no administrator action taken
 * in between -- while a genuinely new, unrelated capability from the same
 * server stays governed only by the ordinary confirm-by-default posture
 * (McpClientCallValidator's own STATUS_CONFIRM-or-STATUS_REJECT default,
 * proved structurally gapless in McpClientCallValidatorTest).
 *
 * A second scenario proves the safe side of the same mechanism: when a
 * server's next offering is genuinely ambiguous (two orphaned rows and two
 * newly-reported tools all sharing one trivial schema), nothing
 * auto-matches -- the new tools insert as fresh rows and fall back to
 * ordinary confirmation, never a silent allow and never a mistaken
 * rejection as if they were still the blocked tool.
 *
 * Drives McpClientToolDiscoveryService::discover() against a real,
 * reconfigurable ReferenceMcpServer instance over loopback HTTP -- the
 * same fixture-reconfiguration-mid-test pattern
 * ExternalToolNoLongerAvailableTest already established for a server
 * changing its own tool offering -- rather than the mocked
 * McpTransportFactory McpClientToolDiscoveryServiceTest uses to prove the
 * reconciliation algorithm's own row-bookkeeping in isolation.
 */
class ExternalToolRenameDurabilityJourneyTest extends TestCase
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
     * Mirrors ExternalToolNoLongerAvailableTest's own identical helper --
     * required before any handleSearchOperations()/handleExecuteOperation()
     * call, even though every operationId this file exercises is
     * mcp:-prefixed and never resolves through it.
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
     * Starts one real ReferenceMcpServer instance offering the given
     * dynamic tool definitions, creates and discovers a McpClientServer
     * against it, and keeps the ReferenceMcpServer instance so the caller
     * can later reconfigure the same running process via
     * setToolDefinitions() without restarting it or changing its URL.
     *
     * @param  list<array{name: string, description?: ?string, inputSchema?: mixed, annotations?: ?array}>  $definitions
     */
    private function discoverServerOffering(string $name, array $definitions): McpClientServer
    {
        $this->referenceServer = new ReferenceMcpServer();
        $url = $this->referenceServer->startHttp(Protocol::MODE_HAPPY_PATH, [
            'dynamic_tools' => $definitions,
        ]);

        $server = McpClientServer::create([
            'name' => $name,
            'transport' => McpTransportKind::StreamableHttp,
            'url' => $url,
            'user_id' => $this->user->id,
        ]);

        app(McpClientToolDiscoveryService::class)->discover($server);

        return $server;
    }

    /**
     * Advances the clock by two seconds and re-runs discover() against the
     * same server row -- mirroring McpClientToolDiscoveryServiceTest's and
     * ExternalToolNoLongerAvailableTest's own identical pattern, since
     * last_seen_at/refresh_finished_at store no sub-second precision and
     * two same-second refreshes would be indistinguishable.
     */
    private function rediscover(McpClientServer $server): void
    {
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::now()->addSeconds(2));
        app(McpClientToolDiscoveryService::class)->discover($server);
    }

    private function invoke(Conversation $conversation, string $operationId, array $parameters): array
    {
        return json_decode(
            app(AgentLoopService::class)->executeMetaTool(
                'execute_operation',
                ['operationId' => $operationId, 'parameters' => $parameters],
                $conversation,
            ),
            true,
        );
    }

    private function search(Conversation $conversation, string $query): array
    {
        return json_decode(
            app(AgentLoopService::class)->executeMetaTool('search_operations', ['query' => $query], $conversation),
            true,
        );
    }

    #[Test]
    public function a_block_written_against_a_tools_durable_id_survives_a_rename_and_redescription_but_never_expands_to_a_new_capability(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['target' => ['type' => 'string']],
            'required' => ['target'],
        ];

        $server = $this->discoverServerOffering('Rename Durability Server', [
            ['name' => 'delete_everything', 'description' => 'Deletes everything.', 'inputSchema' => $schema],
        ]);

        $tool = McpClientTool::where('server_id', $server->id)->where('name', 'delete_everything')->firstOrFail();
        $syntheticOperationId = $tool->synthetic_operation_id;
        $rowId = $tool->id;

        config(['llm-client.api_denylist' => [$syntheticOperationId]]);

        $conversation = Conversation::factory()->create(['user_id' => $this->user->id]);

        $beforeRename = $this->invoke($conversation, $syntheticOperationId, ['target' => 'the database']);
        $this->assertArrayHasKey('error', $beforeRename, 'got: '.json_encode($beforeRename));
        $this->assertFalse((bool) ($beforeRename['__requires_confirmation'] ?? false), 'a denylisted tool must be rejected outright, never paused for confirmation');

        // The server's operator renames AND redescribes the same
        // capability -- inputSchema byte-for-byte unchanged -- without the
        // administrator touching the rule at all.
        $this->referenceServer->setToolDefinitions([
            ['name' => 'purge_all_records', 'description' => 'Purges all records permanently.', 'inputSchema' => $schema],
        ]);
        $this->rediscover($server);

        $renamed = $tool->fresh();
        $this->assertSame($rowId, $renamed->id, "the row's own id must survive an unambiguous rename");
        $this->assertSame($syntheticOperationId, $renamed->synthetic_operation_id, 'the durable id the block is anchored to must survive the rename');
        $this->assertSame('purge_all_records', $renamed->name);
        $this->assertStringContainsString('Purges all records permanently.', $renamed->description ?? '');

        $searchResults = $this->search($conversation, 'purge');
        $foundIds = collect($searchResults['results'] ?? [])->pluck('operationId')->all();
        $this->assertContains($syntheticOperationId, $foundIds, 'the renamed tool must be discoverable by its new name; got: '.json_encode($searchResults));

        $afterRename = $this->invoke($conversation, $syntheticOperationId, ['target' => 'the database']);
        $this->assertArrayHasKey('error', $afterRename, 'got: '.json_encode($afterRename));
        $this->assertFalse((bool) ($afterRename['__requires_confirmation'] ?? false));
        $this->assertSame(
            $beforeRename['error'] ?? null,
            $afterRename['error'] ?? null,
            'the rejection after the rename must be indistinguishable from the rejection before it -- no administrator action taken in between',
        );

        // A genuinely new, previously-unseen capability from the same
        // server -- the rename-durable rule from above must have no
        // effect on it at all.
        $this->referenceServer->setToolDefinitions([
            ['name' => 'purge_all_records', 'description' => 'Purges all records permanently.', 'inputSchema' => $schema],
            ['name' => 'get_status', 'description' => 'Reports current status.', 'inputSchema' => ['type' => 'object', 'properties' => [], 'required' => []]],
        ]);
        $this->rediscover($server);

        $newTool = McpClientTool::where('server_id', $server->id)->where('name', 'get_status')->firstOrFail();
        $this->assertNotSame($syntheticOperationId, $newTool->synthetic_operation_id, "a genuinely new capability must never inherit the blocked tool's durable id");

        $newToolResult = $this->invoke($conversation, $newTool->synthetic_operation_id, []);
        $this->assertTrue((bool) ($newToolResult['__requires_confirmation'] ?? false), 'a genuinely new capability must be subject only to ordinary confirm-by-default behavior; got: '.json_encode($newToolResult));
        $this->assertArrayNotHasKey('error', $newToolResult);
    }

    #[Test]
    public function two_tools_sharing_a_trivial_schema_never_auto_match_two_new_tools_sharing_the_same_schema(): void
    {
        $trivialSchema = ['type' => 'object', 'properties' => [], 'required' => []];

        $server = $this->discoverServerOffering('Ambiguous Rename Server', [
            ['name' => 'tool_a', 'description' => 'Tool A.', 'inputSchema' => $trivialSchema],
            ['name' => 'tool_b', 'description' => 'Tool B.', 'inputSchema' => $trivialSchema],
        ]);

        $toolA = McpClientTool::where('server_id', $server->id)->where('name', 'tool_a')->firstOrFail();
        $toolB = McpClientTool::where('server_id', $server->id)->where('name', 'tool_b')->firstOrFail();

        config(['llm-client.api_denylist' => [$toolA->synthetic_operation_id]]);

        // Both original tools disappear; two new tools sharing the exact
        // same trivial schema take their place -- an ambiguous rename on
        // both sides (two orphaned rows, two unclaimed reports, one shared
        // signature) that must never auto-match.
        $this->referenceServer->setToolDefinitions([
            ['name' => 'tool_c', 'description' => 'Tool C.', 'inputSchema' => $trivialSchema],
            ['name' => 'tool_d', 'description' => 'Tool D.', 'inputSchema' => $trivialSchema],
        ]);
        $this->rediscover($server);

        $toolC = McpClientTool::where('server_id', $server->id)->where('name', 'tool_c')->firstOrFail();
        $toolD = McpClientTool::where('server_id', $server->id)->where('name', 'tool_d')->firstOrFail();

        foreach ([$toolC, $toolD] as $newTool) {
            $this->assertNotSame($toolA->synthetic_operation_id, $newTool->synthetic_operation_id, "an ambiguous rename must never merge a new tool into the blocked tool's row");
            $this->assertNotSame($toolB->synthetic_operation_id, $newTool->synthetic_operation_id, 'an ambiguous rename must never merge a new tool into the other orphaned row either');
        }

        $conversation = Conversation::factory()->create(['user_id' => $this->user->id]);

        foreach ([$toolC, $toolD] as $newTool) {
            $result = $this->invoke($conversation, $newTool->synthetic_operation_id, []);
            $this->assertTrue(
                (bool) ($result['__requires_confirmation'] ?? false),
                "an unmatched new tool sharing the blocked tool's old schema must fall back to ordinary confirmation, never a silent allow; got: ".json_encode($result),
            );
            $this->assertArrayNotHasKey(
                'error',
                $result,
                'an unmatched new tool must never be mistakenly rejected as if it were still the blocked tool; got: '.json_encode($result),
            );
        }
    }
}
