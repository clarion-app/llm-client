<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\LlmClient\Jobs\RefreshMcpClientServerToolsJob;
use ClarionApp\LlmClient\Models\McpClientServer;
use ClarionApp\LlmClient\Models\McpClientServerStatus;
use ClarionApp\LlmClient\Models\McpClientTool;
use ClarionApp\LlmClient\ValueObjects\McpTransportKind;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\ReferenceMcpServer\Protocol;
use Tests\Fixtures\ReferenceMcpServer\ReferenceMcpServer;
use Tests\TestCase;

/**
 * RefreshMcpClientServerToolsJob -- one job per server (mirroring
 * RefreshServerModelsJob's own per-server, never-batched dispatch shape),
 * so a failing server's own refresh outcome never touches a healthy
 * server's rows, and the failing job's own exception never propagates out
 * of handle() uncaught.
 *
 * Written before RefreshMcpClientServerToolsJob exists -- expected to
 * FAIL red (class not found) until it is created.
 */
class RefreshMcpClientServerToolsJobIsolationTest extends TestCase
{
    private ?ReferenceMcpServer $healthyServer = null;
    private ?ReferenceMcpServer $failingServer = null;

    protected function tearDown(): void
    {
        $this->healthyServer?->stopHttp();
        $this->healthyServer = null;
        $this->failingServer?->stopHttp();
        $this->failingServer = null;

        parent::tearDown();
    }

    private function makeServer(string $url): McpClientServer
    {
        return McpClientServer::create([
            'name' => 'Isolation Test Server',
            'transport' => McpTransportKind::StreamableHttp,
            'url' => $url,
            'user_id' => (string) Str::uuid(),
        ]);
    }

    #[Test]
    public function one_servers_failing_refresh_never_affects_the_other_servers_own_dispatched_refresh(): void
    {
        $this->healthyServer = new ReferenceMcpServer();
        $healthyUrl = $this->healthyServer->startHttp(Protocol::MODE_HAPPY_PATH);
        $healthy = $this->makeServer($healthyUrl);

        $this->failingServer = new ReferenceMcpServer();
        $failingUrl = $this->failingServer->startHttp(Protocol::MODE_UNREACHABLE);
        $failing = $this->makeServer($failingUrl);

        // One job per server, dispatched separately -- never a shared
        // batch or fan-out that could let one propagate into the other.
        Bus::dispatchSync(new RefreshMcpClientServerToolsJob($failing->id, 'manual'));
        Bus::dispatchSync(new RefreshMcpClientServerToolsJob($healthy->id, 'manual'));

        $healthyStatus = McpClientServerStatus::where('server_id', $healthy->id)->firstOrFail();
        $this->assertSame('reachable', $healthyStatus->connection_status);
        $this->assertSame(2, $healthyStatus->tool_count);
        $this->assertCount(2, McpClientTool::where('server_id', $healthy->id)->get());

        $failingStatus = McpClientServerStatus::where('server_id', $failing->id)->firstOrFail();
        $this->assertSame('unreachable', $failingStatus->connection_status);
        $this->assertCount(0, McpClientTool::where('server_id', $failing->id)->get());
    }

    #[Test]
    public function dispatching_the_failing_servers_job_first_still_leaves_the_healthy_servers_rows_correct(): void
    {
        // Order reversed from the test above -- the failing job's own
        // outcome must not depend on running second.
        $this->failingServer = new ReferenceMcpServer();
        $failingUrl = $this->failingServer->startHttp(Protocol::MODE_UNREACHABLE);
        $failing = $this->makeServer($failingUrl);

        $this->healthyServer = new ReferenceMcpServer();
        $healthyUrl = $this->healthyServer->startHttp(Protocol::MODE_HAPPY_PATH);
        $healthy = $this->makeServer($healthyUrl);

        Bus::dispatchSync(new RefreshMcpClientServerToolsJob($failing->id, 'manual'));
        Bus::dispatchSync(new RefreshMcpClientServerToolsJob($healthy->id, 'manual'));

        $this->assertSame('reachable', McpClientServerStatus::where('server_id', $healthy->id)->first()->connection_status);
    }

    #[Test]
    public function the_failing_jobs_own_exception_never_propagates_out_of_handle(): void
    {
        $this->failingServer = new ReferenceMcpServer();
        $failingUrl = $this->failingServer->startHttp(Protocol::MODE_UNREACHABLE);
        $failing = $this->makeServer($failingUrl);

        // No exception thrown here at all is the assertion -- PHPUnit
        // treats an uncaught exception from the job as a test error.
        Bus::dispatchSync(new RefreshMcpClientServerToolsJob($failing->id, 'manual'));

        $this->assertSame('unreachable', McpClientServerStatus::where('server_id', $failing->id)->first()->connection_status);
    }

    #[Test]
    public function a_missing_server_id_is_a_no_op_not_an_exception(): void
    {
        Bus::dispatchSync(new RefreshMcpClientServerToolsJob((string) Str::uuid(), 'manual'));

        $this->assertTrue(true, 'dispatchSync() above must not throw');
    }
}
