<?php

namespace ClarionApp\LlmClient\Jobs;

use ClarionApp\LlmClient\Models\McpClientServer;
use ClarionApp\LlmClient\Models\McpClientServerStatus;
use ClarionApp\LlmClient\Services\McpClientToolDiscoveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queued job refreshing one McpClientServer's cached tool list, mirroring
 * RefreshServerModelsJob field-for-field: one job per server, dispatched
 * separately, never a batched fan-out -- so one server's own failure can
 * never delay or affect another server's refresh dispatched alongside it.
 *
 * McpClientToolDiscoveryService::discover() already converts every
 * transport-level failure into an ordinary status row rather than an
 * exception, so this job's own try/catch below is a backstop for a
 * failure that discovery service did not anticipate (a database error, a
 * bug), not the primary isolation mechanism -- but it exists so that even
 * a genuinely unexpected failure can never escape handle() uncaught.
 */
class RefreshMcpClientServerToolsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 45;

    public function __construct(
        public readonly string $serverId,
        public readonly ?string $triggeredBy = null,
    ) {
    }

    public function handle(McpClientToolDiscoveryService $discoveryService): void
    {
        $server = McpClientServer::find($this->serverId);
        if (!$server) {
            Log::warning('RefreshMcpClientServerToolsJob: server not found', [
                'server_id' => $this->serverId,
            ]);

            return;
        }

        try {
            $status = $discoveryService->discover($server);
            $status->update(['triggered_by' => $this->triggeredBy]);
        } catch (\Throwable $e) {
            $this->recordUnexpectedFailure($e);
        }
    }

    /**
     * Handle a job that failed and could not be processed at all (e.g. the
     * queue's own retry policy exhausted). Writes a terminal status row so
     * the failure is visible the same way a caught, in-handle() failure
     * already is.
     */
    public function failed(\Throwable $exception): void
    {
        $this->recordUnexpectedFailure($exception);

        Log::error('RefreshMcpClientServerToolsJob failed permanently', [
            'server_id' => $this->serverId,
            'error' => $exception->getMessage(),
        ]);
    }

    private function recordUnexpectedFailure(\Throwable $exception): void
    {
        Log::error('RefreshMcpClientServerToolsJob: unexpected failure', [
            'server_id' => $this->serverId,
            'error' => $exception->getMessage(),
        ]);

        McpClientServerStatus::updateOrCreate(
            ['server_id' => $this->serverId],
            [
                'connection_status' => 'unreachable',
                'last_error' => $exception->getMessage(),
                'tool_count' => 0,
                'refresh_finished_at' => now(),
                'triggered_by' => $this->triggeredBy,
            ]
        );
    }
}
