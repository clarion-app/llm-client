<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Exceptions\McpTransportTimeoutException;
use ClarionApp\LlmClient\Services\StdioMcpTransport;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Fixtures\ReferenceMcpServer\Protocol;
use Tests\Fixtures\ReferenceMcpServer\ReferenceMcpServer;
use Tests\TestCase;

/**
 * Proves a timed-out stdio subprocess is genuinely reaped from the OS
 * process table -- not merely that McpTransportTimeoutException was
 * thrown, which a leaked-but-orphaned process would not by itself
 * contradict. Uses an anonymous subclass of StdioMcpTransport to capture
 * the spawned subprocess's PID at the moment it starts (via the
 * transport's own afterProcessStarted() test seam), then, after the
 * timeout fires, polls /proc for that PID's continued existence the same
 * direct way GitDefinitionFileReaderTest inspects real OS state rather
 * than a mock.
 */
class StdioMcpTransportProcessCleanupTest extends TestCase
{
    #[Test]
    public function a_timed_out_subprocess_is_gone_from_the_process_table_afterwards(): void
    {
        $referenceServer = new ReferenceMcpServer();

        $transport = new class(
            $referenceServer->stdioCommand(Protocol::MODE_SLOW),
            $referenceServer->stdioEnv(Protocol::MODE_SLOW, ['delay_seconds' => 4.0]),
            1,
            1,
        ) extends StdioMcpTransport {
            public ?int $capturedPid = null;

            protected function afterProcessStarted(Process $process): void
            {
                $this->capturedPid = $process->getPid();
            }
        };

        try {
            $transport->initialize();
            $this->fail('Expected McpTransportTimeoutException to be thrown.');
        } catch (McpTransportTimeoutException) {
            // Expected -- the assertions below are what this test is
            // actually about.
        }

        $this->assertIsInt($transport->capturedPid, 'the subprocess PID must have been captured before this transport tore it down');
        $this->assertProcessIsGone($transport->capturedPid);
    }

    private function assertProcessIsGone(int $pid): void
    {
        // A brief grace window: Process::stop()'s own SIGTERM/SIGKILL
        // escalation and reap can take a moment to complete after
        // control returns to this test.
        $deadline = microtime(true) + 2.0;
        while (is_dir("/proc/{$pid}") && microtime(true) < $deadline) {
            usleep(20_000);
        }

        $this->assertFalse(
            is_dir("/proc/{$pid}"),
            "the subprocess with pid {$pid} must no longer exist in the process table after a timeout"
        );
    }
}
