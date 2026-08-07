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
use PHPUnit\Framework\Attributes\Test;

/**
 * research.md D2/D3, contracts §2 C1/P1 — mapResponse() must fold Anthropic's
 * cache-creation/cache-read tokens into the total input count it reports
 * (a pre-existing undercount otherwise makes every ordinary cache hit look
 * like an FR-014 anomaly), and forward cache_read_input_tokens only when
 * Anthropic's own response actually included it.
 */
class AnthropicProviderReuseMappingTest extends TestCase
{
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

    private function createProvider(Server $server, MockHandler $mock): AnthropicProvider
    {
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);
        return new AnthropicProvider($server, $client);
    }

    #[Test]
    public function cache_fields_present_are_folded_into_total_and_forwarded(): void
    {
        $body = json_encode([
            'id' => 'msg_cache',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-20250514',
            'content' => [['type' => 'text', 'text' => 'Hi']],
            'stop_reason' => 'end_turn',
            'usage' => [
                'input_tokens' => 50,
                'cache_creation_input_tokens' => 10,
                'cache_read_input_tokens' => 900,
                'output_tokens' => 20,
            ],
        ]);

        $mock = new MockHandler([new Response(200, [], $body)]);
        $server = $this->createServer();
        $provider = $this->createProvider($server, $mock);

        $result = $provider->chat([['role' => 'user', 'content' => 'Hi']]);

        $this->assertSame(
            960,
            $result['usage']['prompt_tokens'],
            'prompt_tokens must include input_tokens + cache_creation_input_tokens + cache_read_input_tokens, not just input_tokens'
        );
        $this->assertArrayHasKey('cache_read_input_tokens', $result['usage']);
        $this->assertSame(900, $result['usage']['cache_read_input_tokens']);
        $this->assertSame(980, $result['usage']['total_tokens']);
    }

    #[Test]
    public function no_cache_fields_leaves_prompt_tokens_unchanged_and_omits_the_key(): void
    {
        $body = json_encode([
            'id' => 'msg_nocache',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-20250514',
            'content' => [['type' => 'text', 'text' => 'Hi']],
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

        $this->assertSame(10, $result['usage']['prompt_tokens']);
        $this->assertArrayNotHasKey(
            'cache_read_input_tokens',
            $result['usage'],
            'Unknown must stay unknown — the key must not be forwarded as an implied 0 when Anthropic never reported it'
        );
    }
}
