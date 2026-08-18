<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Exceptions\McpProtocolException;
use ClarionApp\LlmClient\Exceptions\McpTransportException;
use ClarionApp\LlmClient\Exceptions\McpTransportTimeoutException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * McpTransport implementation speaking MCP over stdio. Every public call
 * spawns its own subprocess, performs the initialize handshake followed
 * by the one operation the call actually needs, and terminates the
 * process before returning -- mirroring GitDefinitionFileReader's own
 * array-argument Process construction (never a shell command line, so a
 * stored command/argument string is never interpreted by a shell) and
 * CodingWorkspaceController::runTests()'s own timeout-then-cleanup shape.
 * Because a queue worker process is reused across many subsequent,
 * unrelated jobs, no session is ever held open across calls.
 */
class StdioMcpTransport implements McpTransport
{
    /**
     * @param list<string> $command
     * @param array<string, string> $env
     */
    public function __construct(
        private readonly array $command,
        private readonly array $env = [],
        private readonly int $callTimeoutSeconds = 30,
        private readonly int $handshakeTimeoutSeconds = 15,
    ) {
    }

    public function initialize(): void
    {
        $this->run([['initialize', []]], $this->handshakeTimeoutSeconds);
    }

    public function listTools(): array
    {
        $responses = $this->run([['initialize', []], ['tools/list', []]], $this->handshakeTimeoutSeconds);
        $result = $responses[1]['result'] ?? null;

        return is_array($result['tools'] ?? null) ? $result['tools'] : [];
    }

    public function callTool(string $name, array $arguments): array
    {
        $responses = $this->run(
            [['initialize', []], ['tools/call', ['name' => $name, 'arguments' => $arguments]]],
            $this->callTimeoutSeconds,
        );

        $result = $responses[1]['result'] ?? null;
        if (!is_array($result)) {
            throw new McpProtocolException('External server returned an invalid response.');
        }

        return $result;
    }

    /**
     * Spawn one subprocess, write one JSON-RPC request per line for each
     * $calls entry, read back one decoded response per line, and ensure
     * the process is gone before returning -- even when a timeout or any
     * other failure occurs, via the try/finally below.
     *
     * @param list<array{0: string, 1: array<string, mixed>}> $calls
     * @return list<array<string, mixed>>
     */
    private function run(array $calls, int $timeoutSeconds): array
    {
        $process = $this->createProcess();
        $process->setTimeout($timeoutSeconds);
        $process->setInput($this->buildInput($calls));

        try {
            $process->start();
        } catch (\Throwable $e) {
            throw new McpTransportException('Could not reach external server: ' . $e->getMessage(), previous: $e);
        }

        $this->afterProcessStarted($process);

        try {
            try {
                $process->wait();
            } catch (ProcessTimedOutException $e) {
                throw new McpTransportTimeoutException('External server did not respond in time.', previous: $e);
            }
        } finally {
            // Process's own timeout handling already escalates
            // SIGTERM -> SIGKILL and reaps the child (Process::checkTimeout()
            // -> Process::stop(0)) before the exception above is thrown, but
            // this is a backstop: no subprocess this transport spawns is
            // ever allowed to survive past this call, on any exit path.
            if ($process->isRunning()) {
                $process->stop(2, 9);
            }
        }

        return $this->parseOutput($process, $calls);
    }

    /**
     * @param list<array{0: string, 1: array<string, mixed>}> $calls
     */
    private function buildInput(array $calls): string
    {
        $lines = [];
        foreach ($calls as $i => [$method, $params]) {
            $lines[] = json_encode([
                'jsonrpc' => '2.0',
                'id' => $i + 1,
                'method' => $method,
                'params' => $params,
            ]);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<array{0: string, 1: array<string, mixed>}> $calls
     * @return list<array<string, mixed>>
     */
    private function parseOutput(Process $process, array $calls): array
    {
        $output = trim($process->getOutput());

        if ($output === '') {
            $errorOutput = trim($process->getErrorOutput());

            throw new McpTransportException(
                $errorOutput !== ''
                    ? "Could not reach external server: {$errorOutput}"
                    : 'Could not reach external server.'
            );
        }

        $lines = array_values(array_filter(explode("\n", $output), static fn (string $line): bool => $line !== ''));

        if (count($lines) < count($calls)) {
            throw new McpProtocolException('External server returned an incomplete response.');
        }

        $decoded = [];
        foreach ($lines as $line) {
            $row = json_decode($line, true);
            if (!is_array($row)) {
                throw new McpProtocolException('External server returned an invalid response.');
            }

            if (array_key_exists('error', $row)) {
                $error = $row['error'];
                $message = is_array($error) ? ($error['message'] ?? 'unknown error') : (string) $error;

                throw new McpTransportException("External server rejected the request: {$message}");
            }

            $decoded[] = $row;
        }

        return $decoded;
    }

    /**
     * Overridable so a test can capture the spawned subprocess's PID (via
     * $process->getPid()) at the moment it starts, before this transport
     * tears it down -- see StdioMcpTransportProcessCleanupTest, which
     * needs the PID to prove the process is genuinely gone afterward, not
     * merely that an exception was thrown.
     */
    protected function afterProcessStarted(Process $process): void
    {
    }

    protected function createProcess(): Process
    {
        return new Process($this->command, null, $this->env !== [] ? $this->env : null);
    }
}
