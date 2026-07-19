<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\LlmClientServiceProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

class LlmClientServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LlmClientServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('eloquent-multichain-bridge.disabled', true);
    }

    /**
     * Create a testable subclass that exposes the protected method.
     */
    private function createTestableProvider(): TestableLlmClientServiceProvider
    {
        return new TestableLlmClientServiceProvider($this->app);
    }

    #[Test]
    public function httpClientFor_uses_bound_handler()
    {
        $mockHandler = new MockHandler([new Response(200, [], 'mocked')]);
        $this->app->bind('llm-client.http_handler', fn () => $mockHandler);

        $provider = $this->createTestableProvider();
        $client = $provider->testableHttpClientFor(ProviderType::OpenAI);

        // Verify the mock handler is actually used by making a request.
        $response = $client->request('GET', 'https://example.com/test');
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('mocked', $response->getBody()->getContents());
    }

    #[Test]
    public function httpClientFor_without_bound_handler_is_default()
    {
        // Ensure nothing is bound.
        if ($this->app->bound('llm-client.http_handler')) {
            $this->app->forgetInstance('llm-client.http_handler');
        }

        $provider = $this->createTestableProvider();
        $client = $provider->testableHttpClientFor(ProviderType::OpenAI);

        // Without a bound handler, the client should NOT have a MockHandler.
        // Guzzle's default handler is a HandlerStack, not a MockHandler.
        $handler = $client->getConfig('handler');
        $this->assertNotInstanceOf(MockHandler::class, $handler);
    }
}

/**
 * Test subclass exposing the protected httpClientFor method.
 */
class TestableLlmClientServiceProvider extends LlmClientServiceProvider
{
    public function testableHttpClientFor(
        \ClarionApp\LlmClient\Contracts\ProviderType $type
    ): Client {
        return $this->httpClientFor($type);
    }
}
