<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

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
}
