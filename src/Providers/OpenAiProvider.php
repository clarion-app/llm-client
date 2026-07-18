<?php

namespace ClarionApp\LlmClient\Providers;

use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Server;
use Generator;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;

/**
 * OpenAI-compatible provider implementation.
 *
 * Supports OpenAI, Azure OpenAI, and local proxies (llama.cpp server, Ollama).
 * Uses the standard OpenAI Chat Completions API format.
 *
 * @see LlmProvider for the provider contract.
 */
class OpenAiProvider implements LlmProvider
{
    private Server $server;
    private Client $client;

    public function __construct(Server $server, ?Client $client = null)
    {
        $this->server = $server;
        $this->client = $client ?? new Client(['timeout' => 240]);
    }

    /**
     * Extract the base URL from the server_url.
     *
     * The server_url may be a full endpoint URL (e.g., /v1/chat/completions)
     * or a base URL. This method extracts the base for constructing other endpoints.
     */
    /**
     * Translate an optional `timeout_ms` option into Guzzle request options.
     *
     * Both the connect and total budgets are set: the client default is 240s, so
     * without this a caller on a sub-second budget can block for minutes, and an
     * unreachable host would otherwise hang on connect indefinitely.
     *
     * @param array<string, mixed> $options
     * @return array<string, float>
     */
    private function buildTimeoutOptions(array $options): array
    {
        $timeoutMs = $options['timeout_ms'] ?? null;

        if (!is_numeric($timeoutMs) || $timeoutMs <= 0) {
            return [];
        }

        $seconds = $timeoutMs / 1000;

        return [
            'timeout' => $seconds,
            'connect_timeout' => $seconds,
        ];
    }

    private function getBaseUrl(): string
    {
        $url = $this->server->server_url;
        // If the URL ends with a known endpoint path, strip it to get the base
        $endpointPatterns = ['/v1/chat/completions', '/chat/completions', '/v1/chat', '/chat'];
        foreach ($endpointPatterns as $pattern) {
            if (str_ends_with($url, $pattern)) {
                $base = substr($url, 0, strlen($url) - strlen($pattern));
                // Ensure base ends with /
                if (!str_ends_with($base, '/')) {
                    $base .= '/';
                }
                return rtrim($base, '/');
            }
        }
        // If no pattern matches, assume it's already a base URL
        return rtrim($url, '/');
    }

    /**
     * Synchronous non-streaming chat completion.
     */
    public function chat(array $messages, array $tools = [], array $options = []): array
    {
        if ($this->server->server_url === null) {
            throw new RuntimeException('Server URL is not configured. Cannot make LLM request.');
        }

        if ($this->server->token === null) {
            throw new RuntimeException('API token is not configured. Cannot authenticate with LLM server.');
        }

        $body = [
            'model' => $options['model'] ?? null,
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? 1.0,
            'stream' => false,
        ];

        // Remove null model to let the server use its default
        if ($body['model'] === null) {
            unset($body['model']);
        }

        // Only include tools if non-empty and model supports them
        if (!empty($tools) && ($options['skip_tools'] ?? false) === false) {
            $body['tools'] = $tools;
        }

        // Merge any additional options (max_tokens, top_p, etc.)
        $allowedOptions = ['max_tokens', 'top_p', 'top_k', 'frequency_penalty', 'presence_penalty', 'stop', 'seed'];
        foreach ($allowedOptions as $opt) {
            if (isset($options[$opt])) {
                $body[$opt] = $options[$opt];
            }
        }

        // JSON response format mode
        if (isset($options['response_format']) && $options['response_format'] === 'json') {
            $body['response_format'] = ['type' => 'json_object'];
        }

        try {
            $response = $this->client->post($this->server->server_url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->server->token,
                ],
                'json' => $body,
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            $this->throwErrorFromResponse($e->getResponse() ?? null, $e);
        } catch (ConnectException $e) {
            throw new RuntimeException('Connection to LLM server failed: ' . $e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            if ($e instanceof RuntimeException) {
                throw $e;
            }
            throw new RuntimeException('LLM request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Streaming chat completion via PHP Generator.
     */
    public function stream(array $messages, array $tools = [], array $options = []): Generator
    {
        if ($this->server->server_url === null) {
            throw new RuntimeException('Server URL is not configured. Cannot make LLM request.');
        }

        if ($this->server->token === null) {
            throw new RuntimeException('API token is not configured. Cannot authenticate with LLM server.');
        }

        $body = [
            'model' => $options['model'] ?? null,
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? 1.0,
            'stream' => true,
        ];

        if ($body['model'] === null) {
            unset($body['model']);
        }

        if (!empty($tools) && ($options['skip_tools'] ?? false) === false) {
            $body['tools'] = $tools;
        }

        $allowedOptions = ['max_tokens', 'top_p', 'top_k', 'frequency_penalty', 'presence_penalty', 'stop', 'seed'];
        foreach ($allowedOptions as $opt) {
            if (isset($options[$opt])) {
                $body[$opt] = $options[$opt];
            }
        }

        // JSON response format mode
        if (isset($options['response_format']) && $options['response_format'] === 'json') {
            $body['response_format'] = ['type' => 'json_object'];
        }

        try {
            $response = $this->client->post($this->server->server_url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'text/event-stream',
                    'Authorization' => 'Bearer ' . $this->server->token,
                ],
                'json' => $body,
                'stream' => true,
            ]);

            $bodyStream = $response->getBody();
            $buffer = '';

            while (!$bodyStream->eof()) {
                $chunk = $bodyStream->read(4096);
                if ($chunk === '' || $chunk === false) {
                    continue;
                }

                $buffer .= $chunk;

                // Process complete SSE lines
                $lines = explode("\n", $buffer);
                // Keep the last (possibly incomplete) line in the buffer
                $buffer = array_pop($lines);

                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || $line === null) {
                        continue;
                    }

                    // Skip comment lines
                    if (str_starts_with($line, ':')) {
                        continue;
                    }

                    if (str_starts_with($line, 'data: ')) {
                        $data = substr($line, 6);

                        // End of stream
                        if (trim($data) === '[DONE]') {
                            continue;
                        }

                        $parsed = json_decode($data, true);
                        if (!is_array($parsed) || !isset($parsed['choices'][0])) {
                            continue;
                        }

                        $choice = $parsed['choices'][0];
                        $delta = $choice['delta'] ?? [];

                        // Yield content chunk
                        if (isset($delta['content']) && $delta['content'] !== null) {
                            yield ['content' => $delta['content']];
                        }

                        // Yield tool call chunks
                        if (isset($delta['tool_calls']) && is_array($delta['tool_calls'])) {
                            yield ['tool_calls' => $delta['tool_calls']];
                        }

                        // Yield finish reason
                        if (isset($choice['finish_reason']) && $choice['finish_reason'] !== null) {
                            yield ['finish_reason' => $choice['finish_reason']];
                        }
                    }
                }
            }
        } catch (RequestException $e) {
            $this->throwErrorFromResponse($e->getResponse() ?? null, $e);
        } catch (ConnectException $e) {
            throw new RuntimeException('Streaming connection to LLM server failed: ' . $e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            if ($e instanceof RuntimeException) {
                throw $e;
            }
            throw new RuntimeException('Streaming LLM request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Generate embeddings for input text(s).
     */
    public function embed(array $inputs, array $options = []): array
    {
        if ($this->server->server_url === null) {
            throw new RuntimeException('Server URL is not configured. Cannot make embedding request.');
        }

        if ($this->server->token === null) {
            throw new RuntimeException('API token is not configured. Cannot authenticate with LLM server.');
        }

        $baseUrl = $this->getBaseUrl();
        $embeddingsUrl = $baseUrl . '/v1/embeddings';

        $body = [
            'input' => $inputs,
        ];

        if (!empty($options['model'])) {
            $body['model'] = $options['model'];
        }

        $requestOptions = [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $this->server->token,
            ],
            'json' => $body,
        ];

        try {
            $response = $this->client->post(
                $embeddingsUrl,
                $requestOptions + $this->buildTimeoutOptions($options)
            );

            $result = json_decode($response->getBody()->getContents(), true);

            // Map to unified format
            $embeddings = [];
            foreach ($result['data'] ?? [] as $item) {
                $embeddings[] = $item['embedding'] ?? [];
            }

            return [
                'embeddings' => $embeddings,
                'usage' => $result['usage'] ?? null,
            ];
        } catch (RequestException $e) {
            $this->throwErrorFromResponse($e->getResponse() ?? null, $e);
        } catch (\Throwable $e) {
            if ($e instanceof RuntimeException) {
                throw $e;
            }
            throw new RuntimeException('Embedding request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Count approximate tokens in text.
     * Uses character-based approximation (~4 chars per token for GPT models).
     */
    public function countTokens(string $text, ?string $model = null): int
    {
        // Character-based approximation for OpenAI models
        // GPT models typically use ~4 characters per token
        return (int) ceil(strlen($text) / 4);
    }

    /**
     * List available models on this server.
     */
    public function listModels(): array
    {
        if ($this->server->server_url === null) {
            throw new RuntimeException('Server URL is not configured. Cannot list models.');
        }

        if ($this->server->token === null) {
            throw new RuntimeException('API token is not configured. Cannot authenticate with LLM server.');
        }

        $baseUrl = $this->getBaseUrl();
        $modelsUrl = $baseUrl . '/v1/models';

        try {
            $response = $this->client->get($modelsUrl, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->server->token,
                ],
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            $models = [];
            foreach ($result['data'] ?? [] as $item) {
                $models[] = [
                    'id' => $item['id'],
                    'object' => $item['object'] ?? 'model',
                    'owned_by' => $item['owned_by'] ?? '',
                ];
            }

            return ['models' => $models];
        } catch (RequestException $e) {
            $this->throwErrorFromResponse($e->getResponse() ?? null, $e);
        } catch (ConnectException $e) {
            throw new RuntimeException('Connection to LLM server failed: ' . $e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            if ($e instanceof RuntimeException) {
                throw $e;
            }
            throw new RuntimeException('Failed to list models: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Throw a descriptive RuntimeException based on HTTP error response.
     */
    private function throwErrorFromResponse(?object $response, \Throwable $previous): never
    {
        $statusCode = $response ? $response->getStatusCode() : 0;

        $errorBody = '';
        if ($response && method_exists($response, 'getBody')) {
            $errorBody = $response->getBody()->getContents();
        }

        $errorMessage = '';
        if ($errorBody) {
            $errorData = json_decode($errorBody, true);
            if (is_array($errorData)) {
                $errorMessage = $errorData['error']['message'] ?? $errorData['error'] ?? $errorBody;
            } else {
                $errorMessage = $errorBody;
            }
        }

        switch ($statusCode) {
            case 401:
                throw new RuntimeException(
                    'Authentication failed for LLM server: ' . ($errorMessage ?: 'Invalid or missing API key'),
                    $statusCode,
                    $previous
                );
            case 429:
                throw new RuntimeException(
                    'Rate limit exceeded by LLM server: ' . ($errorMessage ?: 'Too many requests'),
                    $statusCode,
                    $previous
                );
            case 500:
            case 502:
            case 503:
            case 504:
                throw new RuntimeException(
                    'Server error from LLM provider (HTTP ' . $statusCode . '): ' . ($errorMessage ?: 'Internal server error'),
                    $statusCode,
                    $previous
                );
            default:
                throw new RuntimeException(
                    'LLM request failed (HTTP ' . $statusCode . '): ' . ($errorMessage ?: 'Unknown error'),
                    $statusCode,
                    $previous
                );
        }
    }
}
