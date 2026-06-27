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
 * llama.cpp provider implementation (OpenAI-compatible API).
 *
 * Supports local llama.cpp servers exposing the standard OpenAI Chat Completions
 * protocol. Key difference from OpenAiProvider: authentication is optional —
 * local servers often run without Bearer tokens.
 *
 * Additionally supports streaming fallback: if a streaming connection fails,
 * the provider retries as a synchronous chat call and yields the result.
 *
 * @see LlmProvider for the provider contract.
 */
class LlamaCppProvider implements LlmProvider
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
     */
    private function getBaseUrl(): string
    {
        $url = $this->server->server_url;
        $endpointPatterns = ['/v1/chat/completions', '/chat/completions', '/v1/chat', '/chat'];
        foreach ($endpointPatterns as $pattern) {
            if (str_ends_with($url, $pattern)) {
                $base = substr($url, 0, strlen($url) - strlen($pattern));
                if (!str_ends_with($base, '/')) {
                    $base .= '/';
                }
                return rtrim($base, '/');
            }
        }
        return rtrim($url, '/');
    }

    /**
     * Build headers array — includes Authorization only if token is set.
     */
    private function buildHeaders(string $accept): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => $accept,
        ];

        if ($this->server->token !== null) {
            $headers['Authorization'] = 'Bearer ' . $this->server->token;
        }

        return $headers;
    }

    /**
     * Build the request body for chat/stream requests.
     */
    private function buildBody(array $messages, array $tools, array $options, bool $stream): array
    {
        $body = [
            'model' => $options['model'] ?? null,
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? 1.0,
            'stream' => $stream,
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

        return $body;
    }

    // ─── LlmProvider contract methods ───

    /**
     * Synchronous non-streaming chat completion.
     */
    public function chat(array $messages, array $tools = [], array $options = []): array
    {
        if ($this->server->server_url === null) {
            throw new RuntimeException('Server URL is not configured. Cannot make LLM request.');
        }

        $body = $this->buildBody($messages, $tools, $options, false);

        try {
            $response = $this->client->post($this->server->server_url, [
                'headers' => $this->buildHeaders('application/json'),
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
     *
     * Includes fallback: if the streaming connection fails, retries as a
     * synchronous chat() call and yields the full response as a single chunk.
     */
    public function stream(array $messages, array $tools = [], array $options = []): Generator
    {
        if ($this->server->server_url === null) {
            throw new RuntimeException('Server URL is not configured. Cannot make LLM request.');
        }

        $body = $this->buildBody($messages, $tools, $options, true);

        try {
            $response = $this->client->post($this->server->server_url, [
                'headers' => $this->buildHeaders('text/event-stream'),
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

                $lines = explode("\n", $buffer);
                $buffer = array_pop($lines);

                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || $line === null) {
                        continue;
                    }

                    if (str_starts_with($line, ':')) {
                        continue;
                    }

                    if (str_starts_with($line, 'data: ')) {
                        $data = substr($line, 6);

                        if (trim($data) === '[DONE]') {
                            continue;
                        }

                        $parsed = json_decode($data, true);
                        if (!is_array($parsed) || !isset($parsed['choices'][0])) {
                            continue;
                        }

                        $choice = $parsed['choices'][0];
                        $delta = $choice['delta'] ?? [];

                        if (isset($delta['content']) && $delta['content'] !== null) {
                            yield ['content' => $delta['content']];
                        }

                        if (isset($delta['tool_calls']) && is_array($delta['tool_calls'])) {
                            yield ['tool_calls' => $delta['tool_calls']];
                        }

                        if (isset($choice['finish_reason']) && $choice['finish_reason'] !== null) {
                            yield ['finish_reason' => $choice['finish_reason']];
                        }
                    }
                }
            }
        } catch (ConnectException $e) {
            // Fallback to synchronous chat on streaming connection failure
            try {
                $result = $this->chat($messages, $tools, $options);
                $choice = $result['choices'][0] ?? null;
                if ($choice !== null) {
                    $message = $choice['message'] ?? [];
                    if (isset($message['content']) && $message['content'] !== null) {
                        yield ['content' => $message['content']];
                    }
                    if (isset($message['tool_calls'])) {
                        yield ['tool_calls' => $message['tool_calls']];
                    }
                    if (isset($choice['finish_reason'])) {
                        yield ['finish_reason' => $choice['finish_reason']];
                    }
                }
            } catch (\Throwable $fallbackError) {
                throw new RuntimeException(
                    'Streaming failed and fallback chat also failed: ' . $e->getMessage() . ' | ' . $fallbackError->getMessage(),
                    0,
                    $e
                );
            }
        } catch (RequestException $e) {
            $this->throwErrorFromResponse($e->getResponse() ?? null, $e);
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

        $baseUrl = $this->getBaseUrl();
        $embeddingsUrl = $baseUrl . '/v1/embeddings';

        $body = ['input' => $inputs];

        if (!empty($options['model'])) {
            $body['model'] = $options['model'];
        }

        try {
            $response = $this->client->post($embeddingsUrl, [
                'headers' => $this->buildHeaders('application/json'),
                'json' => $body,
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

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
     */
    public function countTokens(string $text, ?string $model = null): int
    {
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

        $baseUrl = $this->getBaseUrl();
        $modelsUrl = $baseUrl . '/v1/models';

        try {
            $response = $this->client->get($modelsUrl, [
                'headers' => $this->buildHeaders('application/json'),
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            $models = [];
            foreach ($result['data'] ?? [] as $item) {
                $models[] = [
                    'id' => $item['id'],
                    'object' => $item['object'] ?? 'model',
                    'owned_by' => $item['owned_by'] ?? 'local',
                ];
            }

            return ['models' => $models];
        } catch (ConnectException $e) {
            throw new RuntimeException('Connection to LLM server failed: ' . $e->getMessage(), 0, $e);
        } catch (RequestException $e) {
            $this->throwErrorFromResponse($e->getResponse() ?? null, $e);
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
                throw new RuntimeException(
                    'Server error (' . $statusCode . '): ' . ($errorMessage ?: 'Internal server error'),
                    $statusCode,
                    $previous
                );
            default:
                throw new RuntimeException(
                    'LLM request failed (' . $statusCode . '): ' . ($errorMessage ?: 'Unknown error'),
                    $statusCode,
                    $previous
                );
        }
    }
}
