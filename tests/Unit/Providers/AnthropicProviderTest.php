<?php

namespace ClarionApp\LlmClient\Tests\Unit\Providers;

use Tests\TestCase;
use ClarionApp\LlmClient\Providers\AnthropicProvider;
use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Models\Server;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Stream;

use PHPUnit\Framework\Attributes\Test;

class AnthropicProviderTest extends TestCase
{
    /**
     * Create a stub Server using an anonymous subclass.
     */
    private function createServer(array $overrides = []): Server
    {
        $defaults = [
            'server_url' => 'https://api.anthropic.com/v1/messages',
            'token' => 'sk-ant-test-token',
        ];
        $attrs = array_merge($defaults, $overrides);

        return new class($attrs) extends Server {
            public function __construct(array $attrs)
            {
                $this->server_url = $attrs['server_url'] ?? null;
                $this->token = $attrs['token'] ?? null;
                $this->provider_type = $attrs['provider_type'] ?? ProviderType::Anthropic;
            }
        };
    }

    private function createProvider(Server $server, ?MockHandler $mock = null): AnthropicProvider
    {
        if ($mock) {
            $handlerStack = HandlerStack::create($mock);
            $client = new Client(['handler' => $handlerStack]);
            return new AnthropicProvider($server, $client);
        }
        return new AnthropicProvider($server);
    }

    // ─── Chat Response Parsing ───

    #[Test]
    public function chat_parses_simple_text_response(): void
    {
        $body = json_encode([
            'id' => 'msg_abc123',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-20250514',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Hello, how can I help you?',
                ],
            ],
            'stop_reason' => 'end_turn',
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 8,
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
    }

    #[Test]
    public function chat_parses_tool_use_response(): void
    {
        $body = json_encode([
            'id' => 'msg_abc123',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-20250514',
            'content' => [
                [
                    'type' => 'tool_use',
                    'id' => 'toolu_abc123',
                    'name' => 'search_operations',
                    'input' => ['query' => 'list contacts'],
                ],
            ],
            'stop_reason' => 'tool_use',
            'usage' => [
                'input_tokens' => 50,
                'output_tokens' => 30,
            ],
        ]);

        $mock = new MockHandler([new Response(200, [], $body)]);
        $server = $this->createServer();
        $provider = $this->createProvider($server, $mock);

        $result = $provider->chat(
            [['role' => 'user', 'content' => 'List my contacts']],
            [['type' => 'function', 'function' => ['name' => 'search_operations', 'description' => 'Search', 'parameters' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']]]]]
        );

        $this->assertEquals('assistant', $result['choices'][0]['message']['role']);
        $this->assertCount(1, $result['choices'][0]['message']['tool_calls']);
        $this->assertEquals('toolu_abc123', $result['choices'][0]['message']['tool_calls'][0]['id']);
        $this->assertEquals('function', $result['choices'][0]['message']['tool_calls'][0]['type']);
        $this->assertEquals('search_operations', $result['choices'][0]['message']['tool_calls'][0]['function']['name']);
        $this->assertEquals('tool_calls', $result['choices'][0]['finish_reason']);
    }

    #[Test]
    public function chat_extracts_system_message_from_messages(): void
    {
        // Anthropic API separates system prompt from messages array
        $body = json_encode([
            'id' => 'msg_abc',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-20250514',
            'content' => [['type' => 'text', 'text' => 'OK']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 20, 'output_tokens' => 3],
        ]);

        $mock = new MockHandler([new Response(200, [], $body)]);
        $server = $this->createServer();
        $provider = $this->createProvider($server, $mock);

        // Pass messages with a system role first
        $messages = [
            ['role' => 'system', 'content' => 'You are a helpful assistant.'],
            ['role' => 'user', 'content' => 'Hi'],
        ];
        $result = $provider->chat($messages);

        $this->assertArrayHasKey('choices', $result);
    }

    // ─── Null validation ───

    #[Test]
    public function chat_throws_on_null_server_url(): void
    {
        $server = $this->createServer(['server_url' => null, 'token' => 'sk-ant-test']);
        $provider = new AnthropicProvider($server);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Server URL.*not configured/i');

        $provider->chat([['role' => 'user', 'content' => 'Hi']]);
    }

    #[Test]
    public function chat_throws_on_null_token(): void
    {
        $server = $this->createServer(['server_url' => 'https://api.anthropic.com/v1/messages', 'token' => null]);
        $provider = new AnthropicProvider($server);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/token.*not configured/i');

        $provider->chat([['role' => 'user', 'content' => 'Hi']]);
    }

    // ─── Streaming SSE Parsing ───

    #[Test]
    public function stream_parses_text_delta_events(): void
    {
        $sseData = "event: message_start\n";
        $sseData .= "data: {\"type\":\"message_start\",\"message\":{\"id\":\"msg_1\",\"type\":\"message\",\"role\":\"assistant\",\"model\":\"claude-sonnet-4-20250514\"}}\n\n";
        $sseData .= "event: content_block_start\n";
        $sseData .= "data: {\"type\":\"content_block_start\",\"index\":0,\"content_block\":{\"type\":\"text\",\"text\":\"\"}}\n\n";
        $sseData .= "event: content_block_delta\n";
        $sseData .= "data: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\"Hello\"}}\n\n";
        $sseData .= "event: content_block_delta\n";
        $sseData .= "data: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\" world\"}}\n\n";
        $sseData .= "event: message_delta\n";
        $sseData .= "data: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"end_turn\"},\"usage\":{\"output_tokens\":5}}\n\n";
        $sseData .= "event: message_stop\n";
        $sseData .= "data: {\"type\":\"message_stop\"}\n\n";

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
    public function stream_parses_tool_use_delta_events(): void
    {
        $sseData = "event: content_block_start\n";
        $sseData .= "data: {\"type\":\"content_block_start\",\"index\":0,\"content_block\":{\"type\":\"tool_use\",\"id\":\"toolu_1\",\"name\":\"search\",\"input\":{}}}\n\n";
        $sseData .= "event: content_block_delta\n";
        $sseData .= "data: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"input_json_delta\",\"partial_json\":\"{\\\"query\"}}\n\n";
        $sseData .= "event: content_block_delta\n";
        $sseData .= "data: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"input_json_delta\",\"partial_json\":\"\\\"}\"}}\n\n";
        $sseData .= "event: message_delta\n";
        $sseData .= "data: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"tool_use\"},\"usage\":{\"output_tokens\":10}}\n\n";
        $sseData .= "event: message_stop\n";
        $sseData .= "data: {\"type\":\"message_stop\"}\n\n";

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

    // ─── Error Handling ───

    #[Test]
    public function chat_throws_on_401(): void
    {
        $mock = new MockHandler([
            new Response(401, [], json_encode(['error' => ['message' => 'Invalid API key']])),
        ]);
        $server = $this->createServer();
        $provider = $this->createProvider($server, $mock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Authentication');

        $provider->chat([['role' => 'user', 'content' => 'Hi']]);
    }

    #[Test]
    public function chat_throws_on_429_rate_limit(): void
    {
        $mock = new MockHandler([
            new Response(429, [], json_encode(['error' => ['message' => 'Rate limit exceeded']])),
        ]);
        $server = $this->createServer();
        $provider = $this->createProvider($server, $mock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Rate limit');

        $provider->chat([['role' => 'user', 'content' => 'Hi']]);
    }

    #[Test]
    public function chat_throws_on_529_overload(): void
    {
        // Anthropic-specific 529 overload error
        $mock = new MockHandler([
            new Response(529, [], json_encode(['error' => ['message' => 'Overloaded']])),
        ]);
        $server = $this->createServer();
        $provider = $this->createProvider($server, $mock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Overloaded');

        $provider->chat([['role' => 'user', 'content' => 'Hi']]);
    }

    #[Test]
    public function chat_throws_on_500_server_error(): void
    {
        $mock = new MockHandler([
            new Response(500, [], json_encode(['error' => ['message' => 'Internal error']])),
        ]);
        $server = $this->createServer();
        $provider = $this->createProvider($server, $mock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Server error');

        $provider->chat([['role' => 'user', 'content' => 'Hi']]);
    }

    // ─── Embed throws (Anthropic doesn't support embeddings) ───

    #[Test]
    public function embed_throws_runtime_exception(): void
    {
        $server = $this->createServer();
        $provider = new AnthropicProvider($server);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('embeddings');

        $provider->embed(['test text']);
    }

    // ─── countTokens returns approximation ───

    #[Test]
    public function countTokens_returns_approximation(): void
    {
        $server = $this->createServer();
        $provider = new AnthropicProvider($server);

        $count = $provider->countTokens('Hello world this is a test');

        $this->assertIsInt($count);
        $this->assertGreaterThan(0, $count);
    }

    // ─── listModels throws (Anthropic doesn't support model listing) ───

    #[Test]
    public function listModels_throws_runtime_exception(): void
    {
        $server = $this->createServer();
        $provider = new AnthropicProvider($server);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('model listing');

        $provider->listModels();
    }
}
