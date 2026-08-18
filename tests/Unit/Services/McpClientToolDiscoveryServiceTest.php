<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Exceptions\McpProtocolException;
use ClarionApp\LlmClient\Models\McpClientServer;
use ClarionApp\LlmClient\Models\McpClientTool;
use ClarionApp\LlmClient\Services\McpClientTextSanitizer;
use ClarionApp\LlmClient\Services\McpClientToolDiscoveryService;
use ClarionApp\LlmClient\Services\McpTransport;
use ClarionApp\LlmClient\Services\McpTransportFactory;
use ClarionApp\LlmClient\ValueObjects\McpTransportKind;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\ReferenceMcpServer\Protocol;
use Tests\Fixtures\ReferenceMcpServer\ReferenceMcpServer;
use Tests\TestCase;

/**
 * McpClientToolDiscoveryService::discover() -- runs the initialize/
 * tools-list handshake against one server and reconciles the result into
 * mcp_client_tools + mcp_client_server_statuses. The happy-path/
 * unreachable/auth-failed cases are driven against ReferenceMcpServer
 * over real loopback HTTP (mirroring McpTransportContractTest's own
 * real-I/O approach rather than mocking the transport boundary); the
 * reconciliation-specific case (a tool vanishing between two refreshes)
 * is driven against a mocked McpTransportFactory instead, since the
 * property under test there is discover()'s own row bookkeeping, not
 * transport behavior a real second fixture process would only obscure.
 *
 * Written before McpClientToolDiscoveryService exists -- expected to FAIL
 * red (class not found) until it is created.
 */
class McpClientToolDiscoveryServiceTest extends TestCase
{
    private ?ReferenceMcpServer $referenceServer = null;

    protected function tearDown(): void
    {
        $this->referenceServer?->stopHttp();
        $this->referenceServer = null;
        Mockery::close();
        \Illuminate\Support\Carbon::setTestNow();

        parent::tearDown();
    }

    private function service(?McpTransportFactory $factory = null): McpClientToolDiscoveryService
    {
        return new McpClientToolDiscoveryService($factory ?? new McpTransportFactory(), new McpClientTextSanitizer());
    }

    private function makeServer(string $url): McpClientServer
    {
        return McpClientServer::create([
            'name' => 'Reference Server',
            'transport' => McpTransportKind::StreamableHttp,
            'url' => $url,
            'user_id' => (string) Str::uuid(),
        ]);
    }

    /**
     * A McpTransportFactory double whose for() always returns the same
     * pre-built transport double, regardless of which McpClientServer it
     * is handed -- letting a test drive discover() against a scripted
     * McpTransport without a real fixture process.
     */
    private function factoryReturning(McpTransport $transport): McpTransportFactory
    {
        $factory = Mockery::mock(McpTransportFactory::class);
        $factory->shouldReceive('for')->andReturn($transport);

        return $factory;
    }

    #[Test]
    public function happy_path_marks_reachable_with_the_correct_tool_count_and_creates_rows(): void
    {
        $this->referenceServer = new ReferenceMcpServer();
        $url = $this->referenceServer->startHttp(Protocol::MODE_HAPPY_PATH);
        $server = $this->makeServer($url);

        $status = $this->service()->discover($server);

        $this->assertSame('reachable', $status->connection_status);
        $this->assertSame(2, $status->tool_count);
        $this->assertNull($status->last_error);
        $this->assertNotNull($status->refresh_finished_at);

        $tools = McpClientTool::where('server_id', $server->id)->get();
        $this->assertCount(2, $tools);
        $this->assertTrue($tools->contains('name', 'reference_echo'));
        $this->assertTrue($tools->contains('name', 'reference_fail'));

        // The sanitizer's provenance prefix was applied at write time.
        $echo = $tools->firstWhere('name', 'reference_echo');
        $this->assertStringStartsWith('[External tool via Reference Server]', $echo->description);
    }

    #[Test]
    public function a_second_run_with_one_tool_removed_soft_removes_only_that_row_leaving_the_other_untouched(): void
    {
        $server = McpClientServer::create([
            'name' => 'Mocked Server',
            'transport' => McpTransportKind::StreamableHttp,
            'url' => 'https://example.test/mcp',
            'user_id' => (string) Str::uuid(),
        ]);

        $firstRunTransport = Mockery::mock(McpTransport::class);
        $firstRunTransport->shouldReceive('initialize')->once();
        $firstRunTransport->shouldReceive('listTools')->once()->andReturn([
            ['name' => 'alpha', 'description' => 'The alpha tool.', 'inputSchema' => ['type' => 'object'], 'annotations' => null],
            ['name' => 'beta', 'description' => 'The beta tool.', 'inputSchema' => ['type' => 'object'], 'annotations' => null],
        ]);

        $firstStatus = $this->service($this->factoryReturning($firstRunTransport))->discover($server);
        $this->assertSame(2, $firstStatus->tool_count);

        $alpha = McpClientTool::where('server_id', $server->id)->where('name', 'alpha')->firstOrFail();
        $beta = McpClientTool::where('server_id', $server->id)->where('name', 'beta')->firstOrFail();

        // The two refreshes must land in different, genuinely-comparable
        // seconds -- the datetime columns/casts involved store no
        // sub-second precision, so two discover() calls close enough
        // together to round to the same second would make a vanished
        // row's stale last_seen_at indistinguishable from a fresh one.
        // Real refreshes are always seconds apart in practice (a
        // five-minute sweep, or a human clicking "refresh"); this travels
        // the clock forward to make that same real-world spacing explicit
        // rather than leaving the test's own timing to chance.
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::now()->addSeconds(2));

        // Second refresh: the server no longer offers "beta".
        $secondRunTransport = Mockery::mock(McpTransport::class);
        $secondRunTransport->shouldReceive('initialize')->once();
        $secondRunTransport->shouldReceive('listTools')->once()->andReturn([
            ['name' => 'alpha', 'description' => 'The alpha tool.', 'inputSchema' => ['type' => 'object'], 'annotations' => null],
        ]);

        $secondStatus = $this->service($this->factoryReturning($secondRunTransport))->discover($server);

        $this->assertSame('reachable', $secondStatus->connection_status);
        $this->assertSame(1, $secondStatus->tool_count);

        // Both rows still physically exist -- mcp_client_tools has no
        // deleted_at column at all -- but only the one this refresh
        // actually touched is covered by scopeActive().
        $this->assertNotNull(McpClientTool::find($alpha->id));
        $this->assertNotNull(McpClientTool::find($beta->id));

        $activeNames = McpClientTool::where('server_id', $server->id)->active()->pluck('name')->all();
        $this->assertContains('alpha', $activeNames);
        $this->assertNotContains('beta', $activeNames, 'a vanished tool must be excluded from the active scope, not deleted');

        $this->assertSame($beta->last_seen_at->toDateTimeString(), $beta->fresh()->last_seen_at->toDateTimeString(), 'the untouched row must not be re-stamped');
    }

    #[Test]
    public function unreachable_marks_unreachable_and_touches_no_rows(): void
    {
        $this->referenceServer = new ReferenceMcpServer();
        $url = $this->referenceServer->startHttp(Protocol::MODE_UNREACHABLE);
        $server = $this->makeServer($url);

        $status = $this->service()->discover($server);

        $this->assertSame('unreachable', $status->connection_status);
        $this->assertSame(0, $status->tool_count);
        $this->assertNotNull($status->last_error);
        $this->assertCount(0, McpClientTool::where('server_id', $server->id)->get());
    }

    #[Test]
    public function an_authentication_rejection_is_reported_as_auth_failed_distinct_from_unreachable(): void
    {
        $this->referenceServer = new ReferenceMcpServer();
        $url = $this->referenceServer->startHttp(Protocol::MODE_HAPPY_PATH, ['expected_token' => 'the-real-token']);
        // No credential supplied -- the fixture will reject with 401.
        $server = $this->makeServer($url);

        $status = $this->service()->discover($server);

        $this->assertSame('auth_failed', $status->connection_status);
        $this->assertNotSame('unreachable', $status->connection_status);
        $this->assertNotNull($status->last_error);
        $this->assertCount(0, McpClientTool::where('server_id', $server->id)->get());
    }

    #[Test]
    public function no_exception_escapes_discover_for_either_a_working_or_an_unreachable_server(): void
    {
        foreach ([Protocol::MODE_HAPPY_PATH, Protocol::MODE_UNREACHABLE] as $mode) {
            $referenceServer = new ReferenceMcpServer();
            $url = $referenceServer->startHttp($mode);
            $server = $this->makeServer($url);

            $status = $this->service()->discover($server);

            $this->assertNotNull($status);
            $referenceServer->stopHttp();
        }
    }

    // -----------------------------------------------------------------
    // Rename/redescribe reconciliation
    // -----------------------------------------------------------------

    private function mockTransport(array|\Throwable $toolsOrException): McpTransport
    {
        $transport = Mockery::mock(McpTransport::class);
        $transport->shouldReceive('initialize')->once();

        if ($toolsOrException instanceof \Throwable) {
            $transport->shouldReceive('listTools')->once()->andThrow($toolsOrException);
        } else {
            $transport->shouldReceive('listTools')->once()->andReturn($toolsOrException);
        }

        return $transport;
    }

    #[Test]
    public function an_unambiguous_rename_only_change_preserves_the_rows_id_and_synthetic_operation_id(): void
    {
        $server = $this->makeServer('https://example.test/mcp');
        $schema = ['type' => 'object', 'properties' => ['confirm' => ['type' => 'boolean']], 'required' => ['confirm']];

        $this->service($this->factoryReturning($this->mockTransport([
            ['name' => 'delete_everything', 'description' => 'Deletes everything.', 'inputSchema' => $schema, 'annotations' => null],
        ])))->discover($server);

        $original = McpClientTool::where('server_id', $server->id)->where('name', 'delete_everything')->firstOrFail();

        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::now()->addSeconds(2));

        $this->service($this->factoryReturning($this->mockTransport([
            ['name' => 'purge_all_records', 'description' => 'Deletes everything.', 'inputSchema' => $schema, 'annotations' => null],
        ])))->discover($server);

        $this->assertCount(1, McpClientTool::where('server_id', $server->id)->get(), 'a pure rename must never create a second row');

        $renamed = McpClientTool::find($original->id);
        $this->assertNotNull($renamed);
        $this->assertSame('purge_all_records', $renamed->name);
        $this->assertSame($original->id, $renamed->id);
        $this->assertSame($original->synthetic_operation_id, $renamed->synthetic_operation_id, 'the durable id must survive an unambiguous rename');
    }

    #[Test]
    public function a_redescribe_only_change_is_the_existing_fast_path_update_with_id_preserved_trivially(): void
    {
        $server = $this->makeServer('https://example.test/mcp');
        $schema = ['type' => 'object'];

        $this->service($this->factoryReturning($this->mockTransport([
            ['name' => 'search_records', 'description' => 'Searches records.', 'inputSchema' => $schema, 'annotations' => null],
        ])))->discover($server);

        $original = McpClientTool::where('server_id', $server->id)->where('name', 'search_records')->firstOrFail();

        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::now()->addSeconds(2));

        $this->service($this->factoryReturning($this->mockTransport([
            ['name' => 'search_records', 'description' => 'Finds matching records quickly.', 'inputSchema' => $schema, 'annotations' => null],
        ])))->discover($server);

        $this->assertCount(1, McpClientTool::where('server_id', $server->id)->get());

        $updated = McpClientTool::find($original->id);
        $this->assertSame($original->id, $updated->id);
        $this->assertSame($original->synthetic_operation_id, $updated->synthetic_operation_id);
        $this->assertSame('search_records', $updated->name);
        $this->assertStringContainsString('Finds matching records quickly.', $updated->description);
    }

    #[Test]
    public function a_simultaneous_rename_and_redescribe_preserves_the_rows_id(): void
    {
        $server = $this->makeServer('https://example.test/mcp');
        $schema = ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path']];

        $this->service($this->factoryReturning($this->mockTransport([
            ['name' => 'read_file', 'description' => 'Reads a file.', 'inputSchema' => $schema, 'annotations' => null],
        ])))->discover($server);

        $original = McpClientTool::where('server_id', $server->id)->where('name', 'read_file')->firstOrFail();

        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::now()->addSeconds(2));

        $this->service($this->factoryReturning($this->mockTransport([
            ['name' => 'fetch_file_contents', 'description' => 'Fetches the contents of a file.', 'inputSchema' => $schema, 'annotations' => null],
        ])))->discover($server);

        $this->assertCount(1, McpClientTool::where('server_id', $server->id)->get());

        $updated = McpClientTool::find($original->id);
        $this->assertSame($original->id, $updated->id);
        $this->assertSame($original->synthetic_operation_id, $updated->synthetic_operation_id);
        $this->assertSame('fetch_file_contents', $updated->name);
        $this->assertStringContainsString('Fetches the contents of a file.', $updated->description);
    }

    #[Test]
    public function two_orphaned_rows_sharing_an_identical_trivial_schema_never_auto_match_two_unclaimed_tools_sharing_it(): void
    {
        $server = $this->makeServer('https://example.test/mcp');
        $trivialSchema = ['type' => 'object'];

        $this->service($this->factoryReturning($this->mockTransport([
            ['name' => 'list_users', 'description' => 'Lists users.', 'inputSchema' => $trivialSchema, 'annotations' => null],
            ['name' => 'list_orders', 'description' => 'Lists orders.', 'inputSchema' => $trivialSchema, 'annotations' => null],
        ])))->discover($server);

        $originalUsers = McpClientTool::where('server_id', $server->id)->where('name', 'list_users')->firstOrFail();
        $originalOrders = McpClientTool::where('server_id', $server->id)->where('name', 'list_orders')->firstOrFail();

        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::now()->addSeconds(2));

        $this->service($this->factoryReturning($this->mockTransport([
            ['name' => 'list_products', 'description' => 'Lists products.', 'inputSchema' => $trivialSchema, 'annotations' => null],
            ['name' => 'list_invoices', 'description' => 'Lists invoices.', 'inputSchema' => $trivialSchema, 'annotations' => null],
        ])))->discover($server);

        // Neither pair auto-matched: both old rows are untouched (still
        // exist, unaged in place) and both new names are genuinely new
        // rows with fresh ids -- never reusing an ambiguous orphan's id.
        $this->assertNotNull(McpClientTool::find($originalUsers->id));
        $this->assertNotNull(McpClientTool::find($originalOrders->id));

        $newProducts = McpClientTool::where('server_id', $server->id)->where('name', 'list_products')->firstOrFail();
        $newInvoices = McpClientTool::where('server_id', $server->id)->where('name', 'list_invoices')->firstOrFail();

        $this->assertNotSame($originalUsers->id, $newProducts->id);
        $this->assertNotSame($originalUsers->id, $newInvoices->id);
        $this->assertNotSame($originalOrders->id, $newProducts->id);
        $this->assertNotSame($originalOrders->id, $newInvoices->id);

        $activeNames = McpClientTool::where('server_id', $server->id)->active()->pluck('name')->all();
        $this->assertContains('list_products', $activeNames);
        $this->assertContains('list_invoices', $activeNames);
        $this->assertNotContains('list_users', $activeNames);
        $this->assertNotContains('list_orders', $activeNames);
        $this->assertCount(4, McpClientTool::where('server_id', $server->id)->get(), 'the two old rows must still physically exist, ages out only');
    }

    #[Test]
    public function a_schema_changing_rename_is_never_merged_and_is_treated_as_remove_one_plus_add_one(): void
    {
        $server = $this->makeServer('https://example.test/mcp');

        $this->service($this->factoryReturning($this->mockTransport([
            [
                'name' => 'convert_currency',
                'description' => 'Converts an amount between currencies.',
                'inputSchema' => ['type' => 'object', 'properties' => ['amount' => ['type' => 'number'], 'from' => ['type' => 'string'], 'to' => ['type' => 'string']], 'required' => ['amount', 'from', 'to']],
                'annotations' => null,
            ],
        ])))->discover($server);

        $original = McpClientTool::where('server_id', $server->id)->where('name', 'convert_currency')->firstOrFail();

        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::now()->addSeconds(2));

        $this->service($this->factoryReturning($this->mockTransport([
            [
                'name' => 'convert_currency_v2',
                'description' => 'Converts an amount between currencies, v2.',
                'inputSchema' => ['type' => 'object', 'properties' => ['amount' => ['type' => 'number'], 'target_currency' => ['type' => 'string']], 'required' => ['amount', 'target_currency']],
                'annotations' => null,
            ],
        ])))->discover($server);

        $replacement = McpClientTool::where('server_id', $server->id)->where('name', 'convert_currency_v2')->firstOrFail();

        $this->assertNotSame($original->id, $replacement->id, 'a genuine schema change must never be merged into the old row\'s identity');
        $this->assertNotSame($original->synthetic_operation_id, $replacement->synthetic_operation_id);
        $this->assertNotNull(McpClientTool::find($original->id), 'the old row must still physically exist, aged out rather than deleted');

        $activeNames = McpClientTool::where('server_id', $server->id)->active()->pluck('name')->all();
        $this->assertContains('convert_currency_v2', $activeNames);
        $this->assertNotContains('convert_currency', $activeNames);
    }

    #[Test]
    public function a_tools_id_is_unchanged_across_a_successful_unreachable_successful_sequence(): void
    {
        $server = $this->makeServer('https://example.test/mcp');
        $schema = ['type' => 'object'];

        $this->service($this->factoryReturning($this->mockTransport([
            ['name' => 'search_records', 'description' => 'Searches records.', 'inputSchema' => $schema, 'annotations' => null],
        ])))->discover($server);

        $original = McpClientTool::where('server_id', $server->id)->where('name', 'search_records')->firstOrFail();

        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::now()->addSeconds(2));

        $unreachableStatus = $this->service($this->factoryReturning($this->mockTransport(new \RuntimeException('connection refused'))))->discover($server);
        $this->assertSame('unreachable', $unreachableStatus->connection_status);

        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::now()->addSeconds(2));

        $finalStatus = $this->service($this->factoryReturning($this->mockTransport([
            ['name' => 'search_records', 'description' => 'Searches records.', 'inputSchema' => $schema, 'annotations' => null],
        ])))->discover($server);

        $this->assertSame('reachable', $finalStatus->connection_status);
        $this->assertCount(1, McpClientTool::where('server_id', $server->id)->get(), 'the intervening failure must never produce a duplicate row');

        $survivor = McpClientTool::where('server_id', $server->id)->where('name', 'search_records')->firstOrFail();
        $this->assertSame($original->id, $survivor->id, 'a transient connectivity failure must never reset a tool\'s durable id');
        $this->assertSame($original->synthetic_operation_id, $survivor->synthetic_operation_id);
    }

    /**
     * Sibling of a_tools_id_is_unchanged_across_a_successful_unreachable_successful_sequence()
     * above -- that case proves id *stability* across a fail-then-succeed
     * sequence but stops immediately after the successful recovery,
     * never inspecting scopeActive() in between. This case stops right
     * after the single failed attempt instead, and asserts the tool
     * stays in the active/search-visible pool at that exact point --
     * the missing half: pool *stability*, not merely id stability.
     */
    #[Test]
    public function a_tool_stays_active_immediately_after_a_single_failed_discovery_attempt(): void
    {
        $server = $this->makeServer('https://example.test/mcp');
        $schema = ['type' => 'object'];

        $this->service($this->factoryReturning($this->mockTransport([
            ['name' => 'search_records', 'description' => 'Searches records.', 'inputSchema' => $schema, 'annotations' => null],
        ])))->discover($server);

        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::now()->addSeconds(2));

        $unreachableStatus = $this->service($this->factoryReturning($this->mockTransport(new \RuntimeException('connection refused'))))->discover($server);
        $this->assertSame('unreachable', $unreachableStatus->connection_status);

        $activeNames = McpClientTool::where('server_id', $server->id)->active()->pluck('name')->all();
        $this->assertContains('search_records', $activeNames, 'a tool must remain in the active/search-visible pool immediately after an intervening failed discovery attempt, not only survive with an unchanged id');
    }

    // -----------------------------------------------------------------
    // McpClientConnectionOutcomeClassifier delegation (protocol_error,
    // last_reachable_at) -- written before discover() delegates to the
    // classifier or the last_reachable_at column exists; expected FAIL
    // red against the current, unmodified discover().
    // -----------------------------------------------------------------

    #[Test]
    public function a_misbehaving_server_is_reported_as_protocol_error_distinct_from_unreachable(): void
    {
        $server = $this->makeServer('https://example.test/mcp');

        $status = $this->service($this->factoryReturning(
            $this->mockTransport(new McpProtocolException('malformed tools/list response'))
        ))->discover($server);

        $this->assertSame('protocol_error', $status->connection_status);
        $this->assertNotSame('unreachable', $status->connection_status);
        $this->assertNotNull($status->last_error);
        $this->assertCount(0, McpClientTool::where('server_id', $server->id)->get());
    }

    #[Test]
    public function a_successful_discovery_sets_last_reachable_at_to_the_refresh_timestamp(): void
    {
        $server = $this->makeServer('https://example.test/mcp');
        $schema = ['type' => 'object'];

        $status = $this->service($this->factoryReturning($this->mockTransport([
            ['name' => 'search_records', 'description' => 'Searches records.', 'inputSchema' => $schema, 'annotations' => null],
        ])))->discover($server);

        $this->assertNotNull($status->last_reachable_at);
        $this->assertSame($status->refresh_finished_at->toDateTimeString(), $status->last_reachable_at->toDateTimeString());
    }

    #[Test]
    public function a_subsequent_failed_discovery_leaves_last_reachable_at_unchanged_from_the_prior_success(): void
    {
        $server = $this->makeServer('https://example.test/mcp');
        $schema = ['type' => 'object'];

        $successStatus = $this->service($this->factoryReturning($this->mockTransport([
            ['name' => 'search_records', 'description' => 'Searches records.', 'inputSchema' => $schema, 'annotations' => null],
        ])))->discover($server);

        $lastReachableAt = $successStatus->last_reachable_at;
        $this->assertNotNull($lastReachableAt);

        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::now()->addSeconds(2));

        $failedStatus = $this->service($this->factoryReturning(
            $this->mockTransport(new \RuntimeException('connection refused'))
        ))->discover($server);

        $this->assertSame('unreachable', $failedStatus->connection_status);
        $this->assertSame($lastReachableAt->toDateTimeString(), $failedStatus->last_reachable_at->toDateTimeString(), 'last_reachable_at must never regress on a failure branch');
    }
}
