<?php

namespace ClarionApp\LlmClient\Tests\Feature\Providers;

use Tests\TestCase;
use ClarionApp\LlmClient\Providers\OpenAiProvider;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Models\Server;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Mockery;

use PHPUnit\Framework\Attributes\Test;

class OpenAiProviderContractTest extends TestCase
{
    private function createServer(): Server
    {
        return new class extends Server {
            public function __construct()
            {
                $this->server_url = 'https://api.openai.com/v1/chat/completions';
                $this->token = 'sk-test-token';
                $this->provider_type = ProviderType::OpenAI;
            }
        };
    }

    #[Test]
    public function openai_provider_implements_llm_provider_interface(): void
    {
        $server = $this->createServer();
        $provider = new OpenAiProvider($server);

        $this->assertInstanceOf(LlmProvider::class, $provider);
    }

    #[Test]
    public function openai_provider_chat_returns_correct_structure(): void
    {
        $body = json_encode([
            'model' => 'gpt-4o',
            'choices' => [
                [
                    'message' => ['role' => 'assistant', 'content' => 'Hello'],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3, 'total_tokens' => 8],
        ]);

        $mock = new MockHandler([new Response(200, [], $body)]);
        $handlerStack = \GuzzleHttp\HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);
        $server = $this->createServer();
        $provider = new OpenAiProvider($server, $client);

        $result = $provider->chat([['role' => 'user', 'content' => 'Hi']]);

        // Verify required structure
        $this->assertArrayHasKey('choices', $result);
        $this->assertIsArray($result['choices']);
        $choice = $result['choices'][0];
        $this->assertArrayHasKey('message', $choice);
        $this->assertArrayHasKey('role', $choice['message']);
        $this->assertArrayHasKey('content', $choice['message']);
        $this->assertArrayHasKey('finish_reason', $choice);
        $this->assertArrayHasKey('usage', $result);
        $this->assertArrayHasKey('prompt_tokens', $result['usage']);
        $this->assertArrayHasKey('completion_tokens', $result['usage']);
        $this->assertArrayHasKey('total_tokens', $result['usage']);
    }

    #[Test]
    public function openai_provider_stream_returns_generator(): void
    {
        $sseData = "data: {\"id\":\"chatcmpl-1\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\"Hi\"},\"finish_reason\":null}]}\n\n";
        $sseData .= "data: [DONE]\n\n";

        $stream = new \GuzzleHttp\Psr7\Stream(fopen('php://temp', 'r+'));
        $stream->write($sseData);
        $stream->rewind();

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream'], $stream),
        ]);
        $handlerStack = \GuzzleHttp\HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);
        $server = $this->createServer();
        $provider = new OpenAiProvider($server, $client);

        $result = $provider->stream([['role' => 'user', 'content' => 'Hi']]);

        $this->assertInstanceOf(\Generator::class, $result);
    }

    #[Test]
    public function openai_provider_embed_returns_correct_structure(): void
    {
        $body = json_encode([
            'data' => [
                ['embedding' => [0.1, 0.2, 0.3], 'index' => 0],
            ],
            'usage' => ['prompt_tokens' => 2, 'total_tokens' => 2],
        ]);

        $mock = new MockHandler([new Response(200, [], $body)]);
        $handlerStack = \GuzzleHttp\HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);
        $server = $this->createServer();
        $provider = new OpenAiProvider($server, $client);

        $result = $provider->embed(['hello']);

        $this->assertArrayHasKey('embeddings', $result);
        $this->assertCount(1, $result['embeddings']);
        $this->assertEquals([0.1, 0.2, 0.3], $result['embeddings'][0]);
    }

    #[Test]
    public function openai_provider_count_tokens_returns_int(): void
    {
        $server = $this->createServer();
        $provider = new OpenAiProvider($server);

        $result = $provider->countTokens('Hello world');

        $this->assertIsInt($result);
        $this->assertGreaterThan(0, $result);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
