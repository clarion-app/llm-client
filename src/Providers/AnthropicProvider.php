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
 * Anthropic provider implementation.
 *
 * Supports the Anthropic Messages API (Claude models).
 * Handles request transformation (system message extraction, tool schema flattening),
 * SSE streaming event parsing, and Anthropic-specific error codes (including 529 overload).
 *
 * @see LlmProvider for the provider contract.
 */
class AnthropicProvider implements LlmProvider
{
    private Server $server;
    private Client $client;

    public function __construct(Server $server, ?Client $client = null)
    {
        $this->server = $server;
        $this->client = $client ?? new Client(['timeout' => 240]);
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
            'model' => $options['model'] ?? 'claude-sonnet-4-20250514',
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? 1.0,
            'max_tokens' => $options['max_tokens'] ?? 4096,
            'stream' => false,
        ];

        // Include system prompt if present (pre-extracted by MessageFormatter)
        if (isset($options['system']) && $options['system'] !== '') {
            $body['system'] = $options['system'];
        }

        // Only include tools if non-empty (tools are pre-formatted by ToolFormatter)
        if (!empty($tools) && ($options['skip_tools'] ?? false) === false) {
            $body['tools'] = $tools;
        }

        try {
            $response = $this->client->post($this->server->server_url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'x-api-key' => $this->server->token,
                    'anthropic-version' => '2023-06-01',
                ],
                'json' => $body,
            ]);

            return $this->mapResponse(json_decode($response->getBody()->getContents(), true));
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
            'model' => $options['model'] ?? 'claude-sonnet-4-20250514',
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? 1.0,
            'max_tokens' => $options['max_tokens'] ?? 4096,
            'stream' => true,
        ];

        // Include system prompt if present (pre-extracted by MessageFormatter)
        if (isset($options['system']) && $options['system'] !== '') {
            $body['system'] = $options['system'];
        }

        // Tools are pre-formatted by ToolFormatter
        if (!empty($tools) && ($options['skip_tools'] ?? false) === false) {
            $body['tools'] = $tools;
        }

        try {
            $response = $this->client->post($this->server->server_url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-api-key' => $this->server->token,
                    'anthropic-version' => '2023-06-01',
                ],
                'json' => $body,
                'stream' => true,
            ]);

            yield from $this->parseStream($response->getBody());
        } catch (ConnectException $e) {
            throw new RuntimeException('Connection to LLM server failed: ' . $e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            if ($e instanceof RuntimeException) {
                throw $e;
            }
            throw new RuntimeException('LLM streaming request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Parse SSE stream from Anthropic API.
     */
    private function parseStream($body): Generator
    {
        $buffer = '';

        while (!$body->eof()) {
            $chunk = $body->read(4096);
            if ($chunk === '' || $chunk === false) {
                continue;
            }

            $buffer .= $chunk;

            // Process complete lines
            $lines = explode("\n", $buffer);
            $buffer = array_pop($lines); // Keep incomplete line in buffer

            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || $line === 'data: [DONE]') {
                    continue;
                }

                // Parse data lines
                if (str_starts_with($line, 'data: ')) {
                    $data = json_decode(substr($line, 6), true);
                    if (!$data) {
                        continue;
                    }

                    $event = $data['type'] ?? '';

                    // Handle content_block_delta events (text or tool input)
                    if ($event === 'content_block_delta') {
                        $delta = $data['delta'] ?? [];
                        if (isset($delta['text'])) {
                            yield [
                                'content' => $delta['text'],
                            ];
                        }
                        if (isset($delta['partial_json'])) {
                            yield [
                                'tool_calls' => [
                                    ['index' => $data['index'] ?? 0, 'function' => ['arguments' => $delta['partial_json']]],
                                ],
                            ];
                        }
                    }

                    // Handle message_delta events (finish reason)
                    if ($event === 'message_delta') {
                        $delta = $data['delta'] ?? [];
                        $stopReason = $delta['stop_reason'] ?? null;
                        if ($stopReason === 'end_turn') {
                            yield ['finish_reason' => 'stop'];
                        } elseif ($stopReason === 'tool_use') {
                            yield ['finish_reason' => 'tool_calls'];
                        } elseif ($stopReason === 'max_tokens') {
                            yield ['finish_reason' => 'length'];
                        }
                    }

                    // Handle content_block_start (for tool call metadata)
                    if ($event === 'content_block_start') {
                        $block = $data['content_block'] ?? [];
                        if ($block['type'] === 'tool_use') {
                            yield [
                                'tool_calls' => [
                                    [
                                        'index' => $data['index'] ?? 0,
                                        'id' => $block['id'] ?? '',
                                        'type' => 'function',
                                        'function' => ['name' => $block['name'] ?? ''],
                                    ],
                                ],
                            ];
                        }
                    }
                }
            }
        }
    }

    /**
     * Anthropic doesn't support embeddings — throw descriptive exception.
     */
    public function embed(array $inputs, array $options = []): array
    {
        throw new RuntimeException(
            'Anthropic provider does not support embeddings. Use an OpenAI-compatible provider for embedding generation.'
        );
    }

    /**
     * Approximate token count using character-based estimation (~1.3 chars/token for Anthropic).
     */
    public function countTokens(string $text, ?string $model = null): int
    {
        // Character-based approximation (~1.3 chars/token for Anthropic)
        return (int) ceil(strlen($text) / 1.3);
    }

    /**
     * Anthropic doesn't support model listing via the Messages API.
     */
    public function listModels(): array
    {
        throw new RuntimeException(
            'Anthropic provider does not support model listing. Use getModels() on the Anthropic API directly if needed.'
        );
    }

    /**
     * Map Anthropic response to unified completion result format.
     */
    private function mapResponse(array $response): array
    {
        $content = $response['content'] ?? [];
        $stopReason = $response['stop_reason'] ?? null;
        $usage = $response['usage'] ?? [];

        // Map content blocks to unified format
        $messageContent = '';
        $toolCalls = [];

        foreach ($content as $block) {
            if ($block['type'] === 'text') {
                $messageContent .= $block['text'] ?? '';
            } elseif ($block['type'] === 'tool_use') {
                $toolCalls[] = [
                    'id' => $block['id'] ?? '',
                    'type' => 'function',
                    'function' => [
                        'name' => $block['name'] ?? '',
                        'arguments' => json_encode($block['input'] ?? []),
                    ],
                ];
            }
        }

        // Map stop_reason to finish_reason
        $finishReason = match ($stopReason) {
            'end_turn' => 'stop',
            'tool_use' => 'tool_calls',
            'max_tokens' => 'length',
            'stop_sequence' => 'stop',
            default => $stopReason ?? 'stop',
        };

        $message = [
            'role' => 'assistant',
            'content' => $messageContent,
        ];

        if (!empty($toolCalls)) {
            $message['tool_calls'] = $toolCalls;
        }

        return [
            'id' => $response['id'] ?? '',
            'model' => $response['model'] ?? '',
            'choices' => [
                [
                    'index' => 0,
                    'message' => $message,
                    'finish_reason' => $finishReason,
                ],
            ],
            'usage' => [
                'prompt_tokens' => $usage['input_tokens'] ?? 0,
                'completion_tokens' => $usage['output_tokens'] ?? 0,
                'total_tokens' => ($usage['input_tokens'] ?? 0) + ($usage['output_tokens'] ?? 0),
            ],
        ];
    }

    /**
     * Throw appropriate exception based on HTTP response.
     */
    private function throwErrorFromResponse(?object $response, \Throwable $previous): never
    {
        $code = $response ? $response->getStatusCode() : 0;
        $body = $response ? json_decode((string) $response->getBody(), true) : null;
        $message = $body['error']['message'] ?? $body['error'] ?? 'Unknown error';

        switch ($code) {
            case 401:
                throw new RuntimeException('Authentication failed: ' . $message, $code, $previous);
            case 429:
                throw new RuntimeException('Rate limit exceeded: ' . $message, $code, $previous);
            case 529:
                throw new RuntimeException('Overloaded: ' . $message, $code, $previous);
            default:
                if ($code >= 500) {
                    throw new RuntimeException('Server error (' . $code . '): ' . $message, $code, $previous);
                }
                throw new RuntimeException('Request failed (' . $code . '): ' . $message, $code, $previous);
        }
    }
}
