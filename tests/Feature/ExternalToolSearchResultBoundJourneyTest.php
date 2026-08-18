<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\McpClientServer;
use ClarionApp\LlmClient\Models\McpClientTool;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\OperationsSearchService;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * User Story 2: one connected server offering an unusually large number of
 * matching tools must never fill an entire search_operations response --
 * every other eligible server's own matches, and every matching built-in
 * operation, still appear in the same response, and the trimmed subset a
 * high-volume server contributes is chosen the same way every time an
 * identical search runs. Mirrors ExternalToolScopeIsolationTest's and
 * ExternalToolNameCollisionJourneyTest's own direct-row-creation approach
 * (McpClientServer::create()/McpClientTool::create(), no transport double
 * needed since this feature reads only already-cached rows) and
 * AgentLoopServiceTest's own OperationsSearchService-mock-binding approach
 * for exercising a built-in-operation match without a real
 * operation_search_index table.
 */
class ExternalToolSearchResultBoundJourneyTest extends TestCase
{
    private const BUILTIN_OPERATION_ID = 'contacts.store';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Mockery::close();

        DB::table('conversations')->delete();
        DB::table('mcp_client_tools')->delete();
        DB::table('mcp_client_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    private function makeServer(string $name): McpClientServer
    {
        return McpClientServer::create([
            'name' => $name,
            'transport' => 'streamable_http',
            'url' => 'https://example.test/mcp',
            'user_id' => $this->user->id,
        ]);
    }

    /**
     * Creates $count tools on $server, all currently active (identical
     * last_seen_at, so scopeActive() includes every one of them -- this
     * file is entirely about the per-server bound, not about currency),
     * named "{$matchWord}_NN" with a zero-padded two-digit counter so
     * lexical ("name" ASC) order matches numeric order, matching
     * $matchWord via the existing name/description LIKE filter.
     *
     * @return list<McpClientTool>
     */
    private function makeTools(McpClientServer $server, int $count, string $matchWord): array
    {
        $seenAt = now();
        $tools = [];

        for ($i = 1; $i <= $count; $i++) {
            $name = sprintf('%s_%02d', $matchWord, $i);
            $tools[] = McpClientTool::create([
                'server_id' => $server->id,
                'synthetic_operation_id' => "mcp:{$server->id}:{$name}",
                'name' => $name,
                'description' => "A tool offered by {$server->name}.",
                'input_schema' => ['type' => 'object', 'properties' => []],
                'last_seen_at' => $seenAt,
            ]);
        }

        return $tools;
    }

    /**
     * Binds a mocked OperationsSearchService reporting the
     * operation_search_index table present and exactly one built-in
     * operation matching every query -- AgentLoopServiceTest's own
     * established binding shape for exercising handleSearchOperations()'s
     * built-in-result path without a real operation_search_index table
     * (this package's own test harness, tests/TestCase.php, does not
     * create that table at all -- OperationsSearchService::tableExists()
     * would otherwise genuinely return false here).
     */
    private function bindBuiltinOperationMatch(): void
    {
        $builtinRow = (object) [
            'operationId' => self::BUILTIN_OPERATION_ID,
            'type' => 'operation',
            'summary' => 'Store a new contact',
            'method' => 'POST',
            'path' => '/api/contacts',
            'paramSchema' => null,
            'promptContent' => null,
        ];

        $searchServiceMock = Mockery::mock(OperationsSearchService::class);
        $searchServiceMock->shouldReceive('tableExists')->andReturn(true);
        $searchServiceMock->shouldReceive('search')->andReturn([$builtinRow]);

        app()->instance(OperationsSearchService::class, $searchServiceMock);
    }

    private function searchResults(string $query): array
    {
        $conversation = Conversation::factory()->create(['user_id' => $this->user->id]);

        $result = json_decode(
            app(AgentLoopService::class)->executeMetaTool('search_operations', ['query' => $query], $conversation),
            true,
        );

        return $result['results'] ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resultsForServer(array $results, McpClientServer $server): array
    {
        return array_values(array_filter(
            $results,
            fn (array $r) => str_starts_with((string) ($r['operationId'] ?? ''), "mcp:{$server->id}:"),
        ));
    }

    // -----------------------------------------------------------------
    // AC1: the large server's excess is trimmed; the small server's own
    // matches and the built-in match are both untouched.
    // -----------------------------------------------------------------

    #[Test]
    public function a_high_volume_servers_matches_are_capped_while_the_small_server_and_the_builtin_operation_are_not(): void
    {
        $this->bindBuiltinOperationMatch();

        $large = $this->makeServer('Large Catalog Server');
        $this->makeTools($large, 30, 'gizmo');

        $small = $this->makeServer('Small Catalog Server');
        $smallTools = $this->makeTools($small, 2, 'gizmo');

        $results = $this->searchResults('gizmo');

        $this->assertNotNull(
            collect($results)->firstWhere('operationId', self::BUILTIN_OPERATION_ID),
            'the matching built-in operation must still appear alongside external-tool results; got: '.json_encode($results),
        );

        foreach ($smallTools as $tool) {
            $this->assertNotNull(
                collect($results)->firstWhere('operationId', $tool->synthetic_operation_id),
                "the small server's own tool {$tool->name} must not be trimmed; got: ".json_encode($results),
            );
        }

        $largeResults = $this->resultsForServer($results, $large);
        $this->assertLessThanOrEqual(
            5,
            count($largeResults),
            'the large server must contribute no more than the configured per-server limit (default 5); got: '.json_encode($largeResults),
        );
        $this->assertGreaterThan(0, count($largeResults), 'the large server must still contribute some of its own matches, not be excluded entirely');
    }

    // -----------------------------------------------------------------
    // AC2: two high-volume servers are each capped independently.
    // -----------------------------------------------------------------

    #[Test]
    public function two_high_volume_servers_are_each_independently_capped_and_neither_reduces_the_others_share(): void
    {
        $serverA = $this->makeServer('Server A');
        $this->makeTools($serverA, 30, 'gizmo');

        $serverD = $this->makeServer('Server D');
        $this->makeTools($serverD, 30, 'gizmo');

        $results = $this->searchResults('gizmo');

        $resultsA = $this->resultsForServer($results, $serverA);
        $resultsD = $this->resultsForServer($results, $serverD);

        $this->assertLessThanOrEqual(5, count($resultsA), 'got: '.json_encode($resultsA));
        $this->assertLessThanOrEqual(5, count($resultsD), 'got: '.json_encode($resultsD));
        $this->assertGreaterThan(0, count($resultsA), 'server A must still contribute its own share');
        $this->assertGreaterThan(0, count($resultsD), 'server D must still contribute its own share, independent of server A');
    }

    // -----------------------------------------------------------------
    // AC3: repeated identical searches choose the identical subset.
    // -----------------------------------------------------------------

    #[Test]
    public function the_identical_search_run_twice_returns_the_identical_trimmed_subset(): void
    {
        $server = $this->makeServer('Deterministic Server');
        $this->makeTools($server, 30, 'gizmo');

        $firstRun = collect($this->resultsForServer($this->searchResults('gizmo'), $server))
            ->pluck('operationId')->sort()->values()->all();
        $secondRun = collect($this->resultsForServer($this->searchResults('gizmo'), $server))
            ->pluck('operationId')->sort()->values()->all();

        $this->assertSame(
            $firstRun,
            $secondRun,
            'an identical search run twice, nothing changed, must select the identical subset of a trimmed server\'s matches',
        );
        $this->assertCount(5, $firstRun);
    }

    // -----------------------------------------------------------------
    // AC4: a server within its own budget is never trimmed.
    // -----------------------------------------------------------------

    #[Test]
    public function a_server_whose_matches_are_within_the_limit_is_never_trimmed(): void
    {
        $server = $this->makeServer('Under Limit Server');
        $tools = $this->makeTools($server, 3, 'gizmo');

        $results = $this->searchResults('gizmo');
        $serverResults = $this->resultsForServer($results, $server);

        $this->assertCount(
            3,
            $serverResults,
            'a server within its own contribution limit must have every one of its matches returned; got: '.json_encode($serverResults),
        );
        foreach ($tools as $tool) {
            $this->assertNotNull(collect($serverResults)->firstWhere('operationId', $tool->synthetic_operation_id));
        }
    }

    // -----------------------------------------------------------------
    // Edge case: a tool trimmed from a broad response remains findable
    // via a narrower, more specific query.
    // -----------------------------------------------------------------

    #[Test]
    public function a_tool_excluded_by_the_bound_from_a_broad_search_remains_findable_via_a_narrower_query(): void
    {
        $server = $this->makeServer('Narrowly Findable Server');
        $this->makeTools($server, 30, 'gizmo');

        $broadResults = $this->resultsForServer($this->searchResults('gizmo'), $server);
        $this->assertCount(5, $broadResults, 'sanity check: the broad query must be trimmed to the default limit');

        // "gizmo_15" is excluded from the broad, alphabetically-sorted
        // top-5 (gizmo_01..gizmo_05) -- and its name/query text is unique
        // enough (no other tool's name contains it) that a query for it
        // specifically matches only this one tool.
        $excludedName = 'gizmo_15';
        $this->assertNull(
            collect($broadResults)->first(fn (array $r) => str_ends_with($r['operationId'] ?? '', ":{$excludedName}")),
            'sanity check: gizmo_15 must genuinely be one of the excluded tools in the broad response',
        );

        $narrowResults = $this->resultsForServer($this->searchResults($excludedName), $server);

        $this->assertNotNull(
            collect($narrowResults)->first(fn (array $r) => str_ends_with($r['operationId'] ?? '', ":{$excludedName}")),
            'a tool trimmed from one broad response must remain fully findable by a narrower, more specific query; got: '.json_encode($narrowResults),
        );
    }
}
