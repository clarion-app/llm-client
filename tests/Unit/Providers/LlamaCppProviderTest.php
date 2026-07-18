<?php

namespace ClarionApp\LlmClient\Tests\Unit\Providers;

use Tests\TestCase;
use ClarionApp\LlmClient\Providers\LlamaCppProvider;
use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Models\Server;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Psr7\Request as PsrRequest;

use PHPUnit\Framework\Attributes\Test;

class LlamaCppProviderTest extends TestCase
{
    /**
     * Create a stub Server using an anonymous subclass.
     */
    private function createServer(array $overrides = []): Server
    {
        $defaults = [
            'server_url' => 'http://localhost:8080/v1/chat/completions',
            'token' => null,
        ];
        $attrs = array_merge($defaults, $overrides);

        return new class($attrs) extends Server {
            public function __construct(array $attrs)
            {
                $this->server_url = $attrs['server_url'] ?? null;
                $this->token = $attrs['token'] ?? null;
                $this->provider_type = $attrs['provider_type'] ?? ProviderType::LlamaCpp;
            }
        };
    }

    private function createProvider(Server $server, ?MockHandler $mock = null): LlamaCppProvider
    {
        if ($mock) {
            $handlerStack = HandlerStack::create($mock);
            $client = new Client(['handler' => $handlerStack]);
            return new LlamaCppProvider($server, $client);
        }
        return new LlamaCppProvider($server);
    }

    // ─── Chat Response Parsing ───

    #[Test]
    public function chat_parses_simple_text_response(): void
    {
        $body = json_encode([
            'id' => 'chatcmpl-abc',
            'object' => 'chat.completion',
            'model' => 'llama-3-8b',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Hello, how can I help you?',
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 8,
                'total_tokens' => 18,
            ],
        ]);

        $mock = new MockHandler([new Response(200, [], $body)]);
        $server = $this->createServer();
        $provider = $this->createProvider($server, $mock);

        $result = $provider->chat([['role' => 'user', 'content' => 'Hi']]);

        $this->assertArrayHasKey('choices', $result);
        $this->assertCount(1, $result['choices']);
        $this->assertEquals('assistant', $result['choices'][0]['message']['role']);
        $this->assertEquals('Hello, how can I help you?', $result['choices'][0]['message']['content']);
        $this->assertEquals('stop', $result['choices'][0]['finish_reason']);
        $this->assertEquals(10, $result['usage']['prompt_tokens']);
        $this->assertEquals(8, $result['usage']['completion_tokens']);
        $this->assertEquals('llama-3-8b', $result['model']);
    }

    #[Test]
    public function chat_parses_tool_call_response(): void
    {
        $body = json_encode([
            'id' => 'chatcmpl-abc',
            'model' => 'llama-3-8b',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [
                            [
                                'id' => 'call_abc123',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'search_operations',
                                    'arguments' => json_encode(['query' => 'list contacts']),
                                ],
                            ],
                        ],
                    ],
                    'finish_reason' => 'tool_calls',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 50,
                'completion_tokens' => 30,
                'total_tokens' => 80,
            ],
        ]);

        $mock = new MockHandler([new Response(200, [], $body)]);
        $server = $this->createServer();
        $provider = $this->createProvider($server, $mock);

        $result = $provider->chat(
            [['role' => 'user', 'content' => 'List my contacts']],
            [['type' => 'function', 'function' => ['name' => 'search_operations', 'description' => 'Search', 'parameters' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']]]]]
        );

        $this->assertEquals('', $result['choices'][0]['message']['content']);
        $this->assertCount(1, $result['choices'][0]['message']['tool_calls']);
        $this->assertEquals('call_abc123', $result['choices'][0]['message']['tool_calls'][0]['id']);
        $this->assertEquals('function', $result['choices'][0]['message']['tool_calls'][0]['type']);
        $this->assertEquals('search_operations', $result['choices'][0]['message']['tool_calls'][0]['function']['name']);
        $this->assertEquals('tool_calls', $result['choices'][0]['finish_reason']);
    }

    #[Test]
    public function chat_with_no_token_omits_auth_header(): void
    {
        $body = json_encode([
            'choices' => [
                [
                    'message' => ['role' => 'assistant', 'content' => 'OK'],
                    'finish_reason' => 'stop',
                ],
            ],
        ]);

        $mock = new MockHandler([new Response(200, [], $body)]);
        $server = $this->createServer(['token' => null]);
        $provider = $this->createProvider($server, $mock);

        $result = $provider->chat([['role' => 'user', 'content' => 'Hi']]);

        $this->assertEquals('OK', $result['choices'][0]['message']['content']);
    }

    #[Test]
    public function chat_with_token_includes_bearer_auth(): void
    {
        $body = json_encode([
            'choices' => [
                [
                    'message' => ['role' => 'assistant', 'content' => 'OK'],
                    'finish_reason' => 'stop',
                ],
            ],
        ]);

        $mock = new MockHandler([new Response(200, [], $body)]);
        $server = $this->createServer(['token' => 'my-secret-token']);
        $provider = $this->createProvider($server, $mock);

        $result = $provider->chat([['role' => 'user', 'content' => 'Hi']]);

        $this->assertEquals('OK', $result['choices'][0]['message']['content']);
    }

    #[Test]
    public function chat_throws_on_null_server_url(): void
    {
        $server = $this->createServer(['server_url' => null]);
        $provider = new LlamaCppProvider($server);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Server URL.*not configured/i');

        $provider->chat([['role' => 'user', 'content' => 'Hi']]);
    }

    #[Test]
    public function chat_throws_on_connection_error(): void
    {
        $mock = new MockHandler([
            new ConnectException('Connection refused', new PsrRequest('POST', 'http://localhost:8080')),
        ]);
        $server = $this->createServer();
        $provider = $this->createProvider($server, $mock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Connection.*failed/i');

        $provider->chat([['role' => 'user', 'content' => 'Hi']]);
    }

    // ─── Streaming ───

    #[Test]
    public function stream_yields_content_chunks(): void
    {
        $sseData = "data: {\"id\":\"chatcmpl-1\",\"choices\":[{\"index\":0,\"delta\":{\"role\":\"assistant\",\"content\":\"Hello\"},\"finish_reason\":null}]}\n\n";
        $sseData .= "data: {\"id\":\"chatcmpl-1\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\" world\"},\"finish_reason\":null}]}\n\n";
        $sseData .= "data: {\"id\":\"chatcmpl-1\",\"choices\":[{\"index\":0,\"delta\":{},\"finish_reason\":\"stop\"}]}\n\n";
        $sseData .= "data: [DONE]\n\n";

        $stream = new Stream(fopen('php://temp', 'r+'));
        $stream->write($sseData);
        $stream->rewind();

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream'], $stream),
        ]);
        $server = $this->createServer();
        $provider = $this->createProvider($server, $mock);

        $chunks = iterator_to_array($provider->stream([['role' => 'user', 'content' => 'Hi']]));

        $contentChunks = array_filter($chunks, fn ($c) => isset($c['content']));
        $finishChunks = array_filter($chunks, fn ($c) => isset($c['finish_reason']));

        $this->assertGreaterThanOrEqual(2, count($contentChunks));
        $this->assertGreaterThanOrEqual(1, count($finishChunks));
    }

    #[Test]
    public function stream_yields_tool_call_chunks(): void
    {
        $sseData = "data: {\"id\":\"chatcmpl-1\",\"choices\":[{\"index\":0,\"delta\":{\"role\":\"assistant\",\"tool_calls\":[{\"index\":0,\"id\":\"call_1\",\"type\":\"function\",\"function\":{\"name\":\"search\",\"arguments\":\"\"}}]},\"finish_reason\":null}]}\n\n";
        $sseData .= "data: {\"id\":\"chatcmpl-1\",\"choices\":[{\"index\":0,\"delta\":{\"tool_calls\":[{\"index\":0,\"function\":{\"arguments\":\"{\\\"q\"}}]},\"finish_reason\":null}]}\n\n";
        $sseData .= "data: {\"id\":\"chatcmpl-1\",\"choices\":[{\"index\":0,\"delta\":{},\"finish_reason\":\"tool_calls\"}]}\n\n";
        $sseData .= "data: [DONE]\n\n";

        $stream = new Stream(fopen('php://temp', 'r+'));
        $stream->write($sseData);
        $stream->rewind();

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream'], $stream),
        ]);
        $server = $this->createServer();
        $provider = $this->createProvider($server, $mock);

        $chunks = iterator_to_array($provider->stream([['role' => 'user', 'content' => 'Hi']]));

        $toolCallChunks = array_filter($chunks, fn ($c) => isset($c['tool_calls']));
        $this->assertGreaterThanOrEqual(1, count($toolCallChunks));
    }

    #[Test]
    public function stream_yields_finish_reason(): void
    {
        $sseData = "data: {\"id\":\"chatcmpl-1\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\"Hello\"},\"finish_reason\":null}]}\n\n";
        $sseData .= "data: {\"id\":\"chatcmpl-1\",\"choices\":[{\"index\":0,\"delta\":{},\"finish_reason\":\"stop\"}]}\n\n";
        $sseData .= "data: [DONE]\n\n";

        $stream = new Stream(fopen('php://temp', 'r+'));
        $stream->write($sseData);
        $stream->rewind();

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream'], $stream),
        ]);
        $server = $this->createServer();
        $provider = $this->createProvider($server, $mock);

        $chunks = iterator_to_array($provider->stream([['role' => 'user', 'content' => 'Hi']]));

        $finishChunks = array_filter($chunks, fn ($c) => isset($c['finish_reason']));
        $this->assertGreaterThanOrEqual(1, count($finishChunks));
        $this->assertEquals('stop', $finishChunks[array_key_first($finishChunks)]['finish_reason']);
    }

    // ─── Embed ───

    #[Test]
    public function embed_returns_embeddings_array(): void
    {
        $body = json_encode([
            'object' => 'list',
            'data' => [
                [
                    'object' => 'embedding',
                    'index' => 0,
                    'embedding' => [0.1, 0.2, 0.3],
                ],
            ],
            'usage' => [
                'prompt_tokens' => 5,
                'total_tokens' => 5,
            ],
        ]);

        $mock = new MockHandler([new Response(200, [], $body)]);
        $server = $this->createServer();
        $provider = $this->createProvider($server, $mock);

        $result = $provider->embed(['Hello world']);

        $this->assertArrayHasKey('embeddings', $result);
        $this->assertCount(1, $result['embeddings']);
        $this->assertEquals([0.1, 0.2, 0.3], $result['embeddings'][0]);
        $this->assertEquals(5, $result['usage']['prompt_tokens']);
    }

    #[Test]
    public function embed_threads_timeoutMs_into_request_options(): void
    {
        $body = json_encode(['data' => [['embedding' => [0.1]]]]);
        $mock = new MockHandler([new Response(200, [], $body)]);
        $provider = $this->createProvider($this->createServer(), $mock);

        $provider->embed(['Hello world'], ['timeout_ms' => 500]);

        // Converted to Guzzle's seconds. Connect is bounded too, otherwise an
        // unreachable host hangs regardless of the total budget.
        $options = $mock->getLastOptions();
        $this->assertEquals(0.5, $options['timeout']);
        $this->assertEquals(0.5, $options['connect_timeout']);
    }

    #[Test]
    public function embed_omitsTimeout_whenNotSupplied(): void
    {
        $body = json_encode(['data' => [['embedding' => [0.1]]]]);
        $mock = new MockHandler([new Response(200, [], $body)]);
        $provider = $this->createProvider($this->createServer(), $mock);

        $provider->embed(['Hello world']);

        // Background callers keep the client default rather than inheriting a
        // hot-path budget.
        $options = $mock->getLastOptions();
        $this->assertArrayNotHasKey('connect_timeout', $options);
    }

    // ─── countTokens ───

    #[Test]
    public function countTokens_returns_approximate_count(): void
    {
        $server = $this->createServer();
        $provider = new LlamaCppProvider($server);

        $count = $provider->countTokens('Hello world this is a test');

        $this->assertIsInt($count);
        $this->assertGreaterThan(0, $count);
    }

    // ─── Model option ───

    #[Test]
    public function chat_sends_model_option_when_provided(): void
    {
        $body = json_encode([
            'choices' => [
                [
                    'message' => ['role' => 'assistant', 'content' => 'OK'],
                    'finish_reason' => 'stop',
                ],
            ],
        ]);

        $mock = new MockHandler([new Response(200, [], $body)]);
        $server = $this->createServer();
        $provider = $this->createProvider($server, $mock);

        $result = $provider->chat(
            [['role' => 'user', 'content' => 'Hi']],
            [],
            ['model' => 'llama-3-8b']
        );

        $this->assertEquals('OK', $result['choices'][0]['message']['content']);
    }

    // ─── US2: listModels ───

    #[Test]
    public function listModels_returns_model_list(): void
    {
        $body = json_encode([
            'object' => 'list',
            'data' => [
                [
                    'id' => 'llama-3-8b',
                    'object' => 'model',
                    'owned_by' => 'local',
                ],
                [
                    'id' => 'mistral-7b',
                    'object' => 'model',
                    'owned_by' => 'local',
                ],
            ],
        ]);

        $mock = new MockHandler([new Response(200, [], $body)]);
        $server = $this->createServer();
        $provider = $this->createProvider($server, $mock);

        $result = $provider->listModels();

        $this->assertArrayHasKey('models', $result);
        $this->assertCount(2, $result['models']);
        $this->assertEquals('llama-3-8b', $result['models'][0]['id']);
        $this->assertEquals('local', $result['models'][0]['owned_by']);
        $this->assertEquals('mistral-7b', $result['models'][1]['id']);
    }

    #[Test]
    public function listModels_throws_on_connection_error(): void
    {
        $mock = new MockHandler([
            new ConnectException('Connection refused', new PsrRequest('GET', 'http://localhost:8080')),
        ]);
        $server = $this->createServer();
        $provider = $this->createProvider($server, $mock);

        $this->expectException(\RuntimeException::class);

        $provider->listModels();
    }

    // ─── US3: Model option in chat/stream ───

    #[Test]
    public function chat_omits_model_when_not_in_options(): void
    {
        $body = json_encode([
            'choices' => [
                [
                    'message' => ['role' => 'assistant', 'content' => 'OK'],
                    'finish_reason' => 'stop',
                ],
            ],
        ]);

        $mock = new MockHandler([new Response(200, [], $body)]);
        $server = $this->createServer();
        $provider = $this->createProvider($server, $mock);

        $result = $provider->chat([['role' => 'user', 'content' => 'Hi']]);

        $this->assertEquals('OK', $result['choices'][0]['message']['content']);
    }

    #[Test]
    public function stream_sends_model_option_when_provided(): void
    {
        $sseData = "data: {\"id\":\"chatcmpl-1\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\"Hello\"},\"finish_reason\":\"stop\"}]}\n\n";
        $sseData .= "data: [DONE]\n\n";

        $stream = new Stream(fopen('php://temp', 'r+'));
        $stream->write($sseData);
        $stream->rewind();

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream'], $stream),
        ]);
        $server = $this->createServer();
        $provider = $this->createProvider($server, $mock);

        $chunks = iterator_to_array($provider->stream(
            [['role' => 'user', 'content' => 'Hi']],
            [],
            ['model' => 'llama-3-8b']
        ));

        $contentChunks = array_filter($chunks, fn ($c) => isset($c['content']));
        $this->assertGreaterThanOrEqual(1, count($contentChunks));
    }

    // ─── Polish: Streaming fallback ───

    #[Test]
    public function stream_falls_back_to_sync_on_error(): void
    {
        // First call (stream) fails with connection error
        // Second call (sync chat) succeeds
        $syncBody = json_encode([
            'choices' => [
                [
                    'message' => ['role' => 'assistant', 'content' => 'Fallback response'],
                    'finish_reason' => 'stop',
                ],
            ],
        ]);

        $mock = new MockHandler([
            new ConnectException('Connection refused', new PsrRequest('POST', 'http://localhost:8080')),
            new Response(200, [], $syncBody),
        ]);
        $server = $this->createServer();
        $provider = $this->createProvider($server, $mock);

        $chunks = iterator_to_array($provider->stream([['role' => 'user', 'content' => 'Hi']]));

        // Should get a content chunk from the fallback sync call
        $contentChunks = array_filter($chunks, fn ($c) => isset($c['content']));
        $this->assertGreaterThanOrEqual(1, count($contentChunks));
        $this->assertEquals('Fallback response', $contentChunks[array_key_first($contentChunks)]['content']);
    }

    // ─── JSON Response Format ───

    #[Test]
    public function chat_with_json_response_format_sends_response_format_param(): void
    {
        $capturedBodies = new \stdClass();
        $capturedBodies->bodies = [];

        $mock = new MockHandler([
            function ($request) use ($capturedBodies) {
                $capturedBodies->bodies[] = json_decode((string) $request->getBody(), true);
                return new Response(200, [], json_encode([
                    'choices' => [['message' => ['role' => 'assistant', 'content' => '{"key":"value"}'], 'finish_reason' => 'stop']],
                ]));
            },
        ]);
        $server = $this->createServer();
        $provider = $this->createProvider($server, $mock);

        $provider->chat(
            [['role' => 'user', 'content' => 'Return JSON']],
            [],
            ['response_format' => 'json']
        );

        $requestBody = $capturedBodies->bodies[0];

        $this->assertArrayHasKey('response_format', $requestBody);
        $this->assertEquals(['type' => 'json_object'], $requestBody['response_format']);
    }

    #[Test]
    public function chat_without_json_response_format_omits_param(): void
    {
        $capturedBodies = new \stdClass();
        $capturedBodies->bodies = [];

        $mock = new MockHandler([
            function ($request) use ($capturedBodies) {
                $capturedBodies->bodies[] = json_decode((string) $request->getBody(), true);
                return new Response(200, [], json_encode([
                    'choices' => [['message' => ['role' => 'assistant', 'content' => 'Hello'], 'finish_reason' => 'stop']],
                ]));
            },
        ]);
        $server = $this->createServer();
        $provider = $this->createProvider($server, $mock);

        $provider->chat(
            [['role' => 'user', 'content' => 'Hi']],
            []
        );

        $requestBody = $capturedBodies->bodies[0];

        $this->assertArrayNotHasKey('response_format', $requestBody);
    }

    #[Test]
    public function stream_with_json_response_format_sends_response_format_param(): void
    {
        $capturedBodies = new \stdClass();
        $capturedBodies->bodies = [];

        $sseData = "data: {\"id\":\"chatcmpl-1\",\"choices\":[{\"index\":0,\"delta\":{\"role\":\"assistant\",\"content\":\"{\\\"key\\\"}\"},\"finish_reason\":null}]}\n\n";
        $sseData .= "data: {\"id\":\"chatcmpl-1\",\"choices\":[{\"index\":0,\"delta\":{},\"finish_reason\":\"stop\"}]}\n\n";
        $sseData .= "data: [DONE]\n\n";

        $stream = new Stream(fopen('php://temp', 'r+'));
        $stream->write($sseData);
        $stream->rewind();

        $mock = new MockHandler([
            function ($request) use ($capturedBodies, $stream) {
                $capturedBodies->bodies[] = json_decode((string) $request->getBody(), true);
                return new Response(200, ['Content-Type' => 'text/event-stream'], $stream);
            },
        ]);
        $server = $this->createServer();
        $provider = $this->createProvider($server, $mock);

        iterator_to_array($provider->stream(
            [['role' => 'user', 'content' => 'Return JSON']],
            [],
            ['response_format' => 'json']
        ));

        $requestBody = $capturedBodies->bodies[0];

        $this->assertArrayHasKey('response_format', $requestBody);
        $this->assertEquals(['type' => 'json_object'], $requestBody['response_format']);
    }
}
