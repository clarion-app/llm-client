<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Exceptions\McpAuthenticationException;
use ClarionApp\LlmClient\Exceptions\McpProtocolException;
use ClarionApp\LlmClient\Exceptions\McpTransportException;
use ClarionApp\LlmClient\Exceptions\McpTransportTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * McpTransport implementation speaking MCP over Streamable HTTP: one
 * JSON-RPC request per call over the Http facade, mirroring
 * ForwardRunTracesCommand::deliver()'s own Http::timeout()->post() shape
 * and RefreshServerModelsJob's own "no response body means unreachable,
 * 401/403 means the credential was rejected" classification. Stateless
 * from the caller's own perspective -- a fresh instance is built per call
 * site by McpTransportFactory, no connection or session is held open
 * across calls.
 */
class StreamableHttpMcpTransport implements McpTransport
{
    private int $nextRequestId = 1;

    public function __construct(
        private readonly string $url,
        private readonly ?string $credential = null,
        private readonly int $callTimeoutSeconds = 30,
        private readonly int $handshakeTimeoutSeconds = 15,
    ) {
    }

    public function initialize(): void
    {
        $this->call('initialize', [], $this->handshakeTimeoutSeconds);
    }

    public function listTools(): array
    {
        $result = $this->call('tools/list', [], $this->handshakeTimeoutSeconds);

        return is_array($result['tools'] ?? null) ? $result['tools'] : [];
    }

    public function callTool(string $name, array $arguments): array
    {
        return $this->call('tools/call', ['name' => $name, 'arguments' => $arguments], $this->callTimeoutSeconds);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function call(string $method, array $params, int $timeoutSeconds): array
    {
        $id = $this->nextRequestId++;

        $request = Http::timeout($timeoutSeconds)->connectTimeout($timeoutSeconds);
        if ($this->credential !== null && $this->credential !== '') {
            $request = $request->withHeaders(['Authorization' => "Bearer {$this->credential}"]);
        }

        try {
            $response = $request->post($this->url, [
                'jsonrpc' => '2.0',
                'id' => $id,
                'method' => $method,
                'params' => $params,
            ]);
        } catch (ConnectionException $e) {
            // Guzzle/cURL's own wording distinguishes the two cases in its
            // exception message ("cURL error 28: Operation timed out..."
            // vs "cURL error 7: Failed to connect...") -- Laravel's
            // ConnectionException wraps that message verbatim, so this is
            // the same signal RefreshServerModelsJob's own ConnectException
            // handling would key off if it needed the distinction.
            if (str_contains(strtolower($e->getMessage()), 'timed out')) {
                throw new McpTransportTimeoutException('External server did not respond in time.', previous: $e);
            }

            throw new McpTransportException('Could not reach external server.', previous: $e);
        } catch (\Throwable $e) {
            throw new McpTransportException($e->getMessage(), previous: $e);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new McpAuthenticationException('External server rejected the stored credential.');
        }

        if ($response->failed()) {
            throw new McpTransportException("External server returned HTTP {$response->status()}.");
        }

        $decoded = $response->json();
        if (!is_array($decoded)) {
            throw new McpProtocolException('External server returned an invalid response.');
        }

        if (array_key_exists('error', $decoded)) {
            $error = $decoded['error'];
            $message = is_array($error) ? ($error['message'] ?? 'unknown error') : (string) $error;

            throw new McpProtocolException("External server reported an error: {$message}");
        }

        $result = $decoded['result'] ?? null;
        if (!is_array($result)) {
            throw new McpProtocolException('External server returned an invalid response.');
        }

        return $result;
    }
}
