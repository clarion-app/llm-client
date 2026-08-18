<?php

namespace Tests\Fixtures\ReferenceMcpServer;

use Symfony\Component\Process\Process;

/**
 * A minimal, protocol-correct initialize/tools-list/tools-call JSON-RPC
 * implementation, servable two ways so both real transport
 * implementations can be exercised over real loopback I/O -- never
 * mocked at the HTTP/process boundary:
 *
 *  - startHttp()/stopHttp(): spawns PHP's own built-in web server on a
 *    free loopback port, running bin/http_server.php, and returns the
 *    base URL a Streamable HTTP transport would be configured against.
 *  - stdioCommand()/stdioEnv(): the command/args/environment a stdio
 *    transport would spawn per call, running bin/stdio_server.php.
 *
 * One instance per test; call stopHttp() in tearDown() so no fixture
 * process outlives the test that started it.
 */
final class ReferenceMcpServer
{
    private ?Process $httpProcess = null;

    /**
     * Set only when startHttp() was given the dynamic_tools option --
     * the path setTools()/setToolDefinitions() rewrite to change what
     * this same running process reports for tools/list on its next
     * request, without restarting the process or changing the URL a
     * caller already holds. Holds JSON: a list of plain name strings, a
     * list of full tool definition arrays, or a mix of both.
     */
    private ?string $toolNamesFile = null;

    /**
     * Set only when startHttp() was given the log_requests option -- the
     * path a caller reads back via loggedToolCalls() to confirm which
     * physical fixture process actually received a given call, the same
     * kind of file-based side channel the dynamic_tools option already
     * uses for setTools().
     */
    private ?string $requestLogFile = null;

    /**
     * Start (or, for the unreachable mode, deliberately not start) an
     * HTTP-servable instance of the fixture. Returns the base URL a
     * StreamableHttpMcpTransport would be pointed at.
     *
     * @param array{expected_token?: string, delay_seconds?: float, dynamic_tools?: list<string|array{name: string, description?: ?string, inputSchema?: mixed, annotations?: ?array}>, log_requests?: bool} $options
     */
    public function startHttp(string $mode, array $options = []): string
    {
        $port = self::findFreePort();

        if ($mode === Protocol::MODE_UNREACHABLE) {
            // No process is started -- the port found above is left
            // closed, so a real connection attempt to it fails exactly
            // like a genuinely offline server.
            return "http://127.0.0.1:{$port}";
        }

        $env = ['REFERENCE_MCP_MODE' => $mode];
        if (isset($options['expected_token'])) {
            $env['REFERENCE_MCP_EXPECTED_TOKEN'] = $options['expected_token'];
        }
        if (isset($options['delay_seconds'])) {
            $env['REFERENCE_MCP_DELAY_SECONDS'] = (string) $options['delay_seconds'];
        }
        if (isset($options['dynamic_tools'])) {
            // A file rather than an env var: this process's own env is
            // fixed at spawn time, but a file is read fresh on every
            // request (bin/http_server.php), so setTools()/
            // setToolDefinitions() below can change what tools/list
            // reports mid-test. JSON-encoded so an entry can be either a
            // plain name or a full tool definition array.
            $this->toolNamesFile = tempnam(sys_get_temp_dir(), 'reference_mcp_tools_');
            file_put_contents($this->toolNamesFile, json_encode($options['dynamic_tools']));
            $env['REFERENCE_MCP_TOOL_NAMES_FILE'] = $this->toolNamesFile;
        }

        if (!empty($options['log_requests'])) {
            $this->requestLogFile = tempnam(sys_get_temp_dir(), 'reference_mcp_requests_');
            file_put_contents($this->requestLogFile, '');
            $env['REFERENCE_MCP_REQUEST_LOG_FILE'] = $this->requestLogFile;
        }

        $this->httpProcess = new Process(
            ['php', '-S', "127.0.0.1:{$port}", __DIR__ . '/bin/http_server.php'],
            null,
            $env,
        );
        $this->httpProcess->start();

        self::waitUntilAcceptingConnections('127.0.0.1', $port);

        return "http://127.0.0.1:{$port}";
    }

    /**
     * Overwrites the tool list this same fixture process reports on its
     * next tools/list request -- the process keeps running, its URL
     * never changes, and no McpClientServer row is touched. Each name is
     * resolved against the fixture's own static catalog exactly as
     * before -- this method's behavior and signature are unchanged.
     * Requires startHttp() to have been called with the dynamic_tools
     * option.
     *
     * @param list<string> $names
     */
    public function setTools(array $names): void
    {
        if ($this->toolNamesFile === null) {
            throw new \RuntimeException('setTools() requires startHttp() to have been called with the dynamic_tools option.');
        }

        file_put_contents($this->toolNamesFile, json_encode($names));
    }

    /**
     * Overwrites the tool list this same fixture process reports on its
     * next tools/list request with arbitrary, test-chosen tool
     * definitions -- name, description, and inputSchema all
     * caller-controlled, not limited to what the fixture's own static
     * catalog already knows how to describe. Only 'name' is required per
     * entry; 'description'/'inputSchema'/'annotations' default to null
     * (or, for 'inputSchema', a trivial no-argument object schema) when
     * omitted. Same file-based mechanism setTools() uses -- the process
     * keeps running, its URL never changes, and no McpClientServer row
     * is touched. Requires startHttp() to have been called with the
     * dynamic_tools option.
     *
     * @param list<array{name: string, description?: ?string, inputSchema?: mixed, annotations?: ?array}> $definitions
     */
    public function setToolDefinitions(array $definitions): void
    {
        if ($this->toolNamesFile === null) {
            throw new \RuntimeException('setToolDefinitions() requires startHttp() to have been called with the dynamic_tools option.');
        }

        file_put_contents($this->toolNamesFile, json_encode($definitions));
    }

    /**
     * Every tools/call request this fixture process has actually
     * received so far, oldest first -- lets a caller confirm which
     * physical server process a given invocation genuinely reached,
     * independent of whatever response content that call produced.
     * Requires startHttp() to have been called with the log_requests
     * option.
     *
     * @return list<array{tool: ?string, arguments: array<string, mixed>}>
     */
    public function loggedToolCalls(): array
    {
        if ($this->requestLogFile === null) {
            throw new \RuntimeException('loggedToolCalls() requires startHttp() to have been called with the log_requests option.');
        }

        $raw = file_get_contents($this->requestLogFile) ?: '';
        $lines = array_values(array_filter(explode("\n", $raw), fn (string $line) => $line !== ''));

        return array_map(function (string $line) {
            $decoded = json_decode($line, true);

            return [
                'tool' => is_array($decoded) ? ($decoded['tool'] ?? null) : null,
                'arguments' => is_array($decoded) && is_array($decoded['arguments'] ?? null) ? $decoded['arguments'] : [],
            ];
        }, $lines);
    }

    /**
     * Stop the HTTP-servable instance started by startHttp(), if any.
     * Safe to call even when nothing was started (the unreachable mode,
     * or a test that never called startHttp() at all).
     */
    public function stopHttp(): void
    {
        $this->httpProcess?->stop();
        $this->httpProcess = null;

        if ($this->toolNamesFile !== null) {
            @unlink($this->toolNamesFile);
            $this->toolNamesFile = null;
        }

        if ($this->requestLogFile !== null) {
            @unlink($this->requestLogFile);
            $this->requestLogFile = null;
        }
    }

    /**
     * The command a stdio transport would spawn for one call, for the
     * unreachable mode a deliberately nonexistent binary path so the
     * spawn itself fails.
     *
     * @return list<string>
     */
    public function stdioCommand(string $mode): array
    {
        if ($mode === Protocol::MODE_UNREACHABLE) {
            return ['/nonexistent/reference-mcp-server-binary'];
        }

        return ['php', __DIR__ . '/bin/stdio_server.php'];
    }

    /**
     * The environment a stdio transport would set for one call --
     * carrying the mode (and, for the credential-checking case, the
     * expected/actual credential) the same way StdioMcpTransport carries
     * a server's own credential: as an environment variable, never a
     * command-line argument.
     *
     * @param array{expected_credential?: string, delay_seconds?: float} $options
     * @return array<string, string>
     */
    public function stdioEnv(string $mode, array $options = []): array
    {
        $env = ['REFERENCE_MCP_MODE' => $mode];
        if (isset($options['expected_credential'])) {
            $env['REFERENCE_MCP_EXPECTED_CREDENTIAL'] = $options['expected_credential'];
        }
        if (isset($options['delay_seconds'])) {
            $env['REFERENCE_MCP_DELAY_SECONDS'] = (string) $options['delay_seconds'];
        }

        return $env;
    }

    private static function findFreePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new \RuntimeException("Could not allocate a loopback port for the reference MCP server fixture: {$errstr}");
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private static function waitUntilAcceptingConnections(string $host, int $port, float $timeoutSeconds = 5.0): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            $connection = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 0.1);
            if ($connection !== false) {
                fclose($connection);

                return;
            }
            usleep(20_000);
        }

        throw new \RuntimeException("The reference MCP server fixture did not start listening on {$host}:{$port} in time.");
    }
}
