<?php

namespace ClarionApp\LlmClient\Jobs;

use ClarionApp\LlmClient\Models\McpClientConnectionTest;
use ClarionApp\LlmClient\Models\McpClientServer;
use ClarionApp\LlmClient\Services\McpClientConnectionOutcomeClassifier;
use ClarionApp\LlmClient\Services\McpTransportFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queued job running one "test before saving" connection attempt
 * (FR-003, D2) -- a genuine ShouldQueue job dispatched by
 * McpClientServerController::testConnection() and run on whatever
 * process actually runs `php artisan queue:work` for this installation,
 * never inline in the web request. Never persists a mcp_client_servers
 * row (D3/D4): it builds a transient, unsaved McpClientServer instance
 * from the test row's own stored connection fields and hands it,
 * unmodified, to the existing McpTransportFactory::for() -- the exact
 * same call McpClientToolDiscoveryService::discover() already makes for
 * a real, persisted server -- so the test path and the production
 * discovery path can never drift from each other. Failure classification
 * is delegated to the same shared McpClientConnectionOutcomeClassifier
 * discover() uses (D5), so a given transport failure always means the
 * same thing whether it happened during a test or during ongoing
 * discovery (FR-010).
 */
class TestMcpClientConnectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 45;

    public function __construct(
        public readonly string $testId,
    ) {
    }

    public function handle(McpTransportFactory $transportFactory, McpClientConnectionOutcomeClassifier $classifier): void
    {
        $test = McpClientConnectionTest::find($this->testId);
        if (!$test) {
            Log::warning('TestMcpClientConnectionJob: test row not found', [
                'test_id' => $this->testId,
            ]);

            return;
        }

        $test->update(['started_at' => now()]);

        // A transient, unsaved McpClientServer -- never ->save()'d --
        // built solely from this test row's own connection fields, so
        // McpTransportFactory::for() (and everything it calls, unchanged)
        // reads the exact same shape it would for a real, persisted
        // server (D4).
        $transientServer = new McpClientServer([
            'transport' => $test->transport,
            'url' => $test->url,
            'command' => $test->command,
            'args' => $test->args,
            'credential' => $test->credential,
        ]);

        try {
            $transport = $transportFactory->for($transientServer);
            $transport->initialize();
            $tools = $transport->listTools();
        } catch (\Throwable $e) {
            $outcome = $classifier->classify($e);

            $test->update([
                'status' => 'failed',
                'failure_category' => $outcome->category,
                'message' => $outcome->message,
                'finished_at' => now(),
            ]);

            return;
        }

        $test->update([
            'status' => 'passed',
            'tool_count' => count($tools),
            'finished_at' => now(),
        ]);
    }

    /**
     * Handle a job that failed and could not be processed at all (e.g.
     * the queue's own retry policy exhausted). Writes a terminal status
     * row so the failure is visible the same way a caught, in-handle()
     * failure already is -- mirrors
     * RefreshMcpClientServerToolsJob::failed()'s own discipline.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('TestMcpClientConnectionJob failed permanently', [
            'test_id' => $this->testId,
            'error' => $exception->getMessage(),
        ]);

        McpClientConnectionTest::where('id', $this->testId)->update([
            'status' => 'failed',
            'failure_category' => 'unreachable',
            'message' => $exception->getMessage(),
            'finished_at' => now(),
        ]);
    }
}
