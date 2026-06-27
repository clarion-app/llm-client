<?php

namespace ClarionApp\LlmClient\Tests\Unit\Providers;

use Tests\TestCase;
use ClarionApp\LlmClient\Providers\OpenAiProvider;
use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Models\Server;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Mockery;

use PHPUnit\Framework\Attributes\Test;

class OpenAiProviderTest extends TestCase
{
    /**
     * Create a stub Server with the given attributes using an anonymous subclass.
     */
    private function createServer(array $overrides = []): Server
    {
        $defaults = [
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'sk-test-token',
        ];
        $attrs = array_merge($defaults, $overrides);

        $server = new class($attrs) extends Server {
            public function __construct(array $attrs)
            {
                $this->server_url = $attrs['server_url'] ?? null;
                $this->token = $attrs['token'] ?? null;
                $this->provider_type = $attrs['provider_type'] ?? ProviderType::OpenAI;
            }
        };
        return $server;
    }

    private function createProvider($server, ?MockHandler $mock = null): OpenAiProvider
    {
        if ($mock) {
            $handlerStack = HandlerStack::create($mock);
            $client = new Client(['handler' => $handlerStack]);
            return new OpenAiProvider($server, $client);
        }
        return new OpenAiProvider($server);
    }

    #[Test]
    public function chat_parses_simple_text_response(): void
    {
        $body = json_encode([
            'id' => 'chatcmpl-abc',
            'object' => 'chat.completion',
            'model' => 'gpt-4o',
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
        $this->assertEquals('gpt-4o', $result['model']);
    }

    #[Test]
    public function chat_parses_tool_call_response(): void
    {
        $body = json_encode([
            'id' => 'chatcmpl-abc',
            'model' => 'gpt-4o',
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
    public function chat_throws_on_null_server_url(): void
    {
        $server = $this->createServer(['server_url' => null, 'token' => 'sk-test']);
        $provider = new OpenAiProvider($server);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Server URL.*not configured/i');

        $provider->chat([['role' => 'user', 'content' => 'Hi']]);
    }

    #[Test]
    public function chat_throws_on_null_token(): void
    {
        $server = $this->createServer(['server_url' => 'https://api.openai.com/v1/chat/completions', 'token' => null]);
        $provider = new OpenAiProvider($server);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/token.*not configured/i');

        $provider->chat([['role' => 'user', 'content' => 'Hi']]);
    }

    #[Test]
    public function stream_parses_sse_chunks(): void
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
    public function stream_parses_tool_call_fragments(): void
    {
        $sseData = "data: {\"id\":\"chatcmpl-1\",\"choices\":[{\"index\":0,\"delta\":{\"role\":\"assistant\",\"tool_calls\":[{\"index\":0,\"id\":\"call_abc\",\"type\":\"function\",\"function\":{\"name\":\"search\",\"arguments\":\"\"}}]},\"finish_reason\":null}]}\n\n";
        $sseData .= "data: {\"id\":\"chatcmpl-1\",\"choices\":[{\"index\":0,\"delta\":{\"tool_calls\":[{\"index\":0,\"function\":{\"arguments\":\"{\\\"query\"}}]},\"finish_reason\":null}]}\n\n";
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
    public function chat_throws_on_401(): void
    {
        $mock = new MockHandler([
            new Response(401, [], json_encode(['error' => ['message' => 'Invalid API key', 'type' => 'invalid_request_error']])),
        ]);
        $server = $this->createServer();
        $provider = $this->createProvider($server, $mock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Authentication');

        $provider->chat([['role' => 'user', 'content' => 'Hi']]);
    }

    #[Test]
    public function chat_throws_on_429(): void
    {
        $mock = new MockHandler([
            new Response(429, [], json_encode(['error' => ['message' => 'Rate limit exceeded', 'type' => 'rate_limit']])),
        ]);
        $server = $this->createServer();
        $provider = $this->createProvider($server, $mock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Rate limit');

        $provider->chat([['role' => 'user', 'content' => 'Hi']]);
    }

    #[Test]
    public function chat_throws_on_500(): void
    {
        $mock = new MockHandler([
            new Response(500, [], json_encode(['error' => ['message' => 'Internal server error']])),
        ]);
        $server = $this->createServer();
        $provider = $this->createProvider($server, $mock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Server error');

        $provider->chat([['role' => 'user', 'content' => 'Hi']]);
    }

    #[Test]
    public function chat_throws_on_timeout(): void
    {
        $mock = new MockHandler([
            new ConnectException('Connection timed out', new PsrRequest('POST', 'https://api.openai.com')),
        ]);
        $server = $this->createServer();
        $provider = $this->createProvider($server, $mock);

        $this->expectException(\RuntimeException::class);

        $provider->chat([['role' => 'user', 'content' => 'Hi']]);
    }

    #[Test]
    public function chat_sends_request_without_tools_when_empty(): void
    {
        $body = json_encode([
            'choices' => [
                [
                    'message' => ['role' => 'assistant', 'content' => 'Response'],
                    'finish_reason' => 'stop',
                ],
            ],
        ]);

        $mock = new MockHandler([new Response(200, [], $body)]);
        $server = $this->createServer();
        $provider = $this->createProvider($server, $mock);

        $result = $provider->chat([['role' => 'user', 'content' => 'Hi']], []);

        $this->assertEquals('Response', $result['choices'][0]['message']['content']);
    }

    // ─── listModels ───

    #[Test]
    public function listModels_returns_model_list(): void
    {
        $body = json_encode([
            'object' => 'list',
            'data' => [
                [
                    'id' => 'gpt-4o',
                    'object' => 'model',
                    'owned_by' => 'openai',
                ],
                [
                    'id' => 'gpt-3.5-turbo',
                    'object' => 'model',
                    'owned_by' => 'openai',
                ],
            ],
        ]);

        $mock = new MockHandler([new Response(200, [], $body)]);
        $server = $this->createServer();
        $provider = $this->createProvider($server, $mock);

        $result = $provider->listModels();

        $this->assertArrayHasKey('models', $result);
        $this->assertCount(2, $result['models']);
        $this->assertEquals('gpt-4o', $result['models'][0]['id']);
        $this->assertEquals('openai', $result['models'][0]['owned_by']);
    }

    // ─── Tear Down ───

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
