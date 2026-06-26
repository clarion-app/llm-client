<?php

namespace ClarionApp\LlmClient\Tests\Unit\Providers;

use Tests\TestCase;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Models\Server;
use RuntimeException;
use Mockery;

use PHPUnit\Framework\Attributes\Test;

class ProviderRegistryByTypeTest extends TestCase
{
    /**
     * Create a minimal Server instance for testing.
     */
    private function createServer(): Server
    {
        return new class extends Server {
            public function __construct()
            {
                // Skip parent constructor to avoid database connection
            }
        };
    }

    #[Test]
    public function resolveByType_resolves_openai_provider(): void
    {
        $registry = new ProviderRegistry();
        $mockProvider = Mockery::mock(LlmProvider::class);
        $factory = fn (Server $server) => $mockProvider;

        $registry->register(ProviderType::OpenAI, $factory);

        $server = $this->createServer();
        $result = $registry->resolveByType(ProviderType::OpenAI, $server);

        $this->assertSame($mockProvider, $result);
    }

    #[Test]
    public function resolveByType_resolves_anthropic_provider(): void
    {
        $registry = new ProviderRegistry();
        $mockProvider = Mockery::mock(LlmProvider::class);
        $factory = fn (Server $server) => $mockProvider;

        $registry->register(ProviderType::Anthropic, $factory);

        $server = $this->createServer();
        $result = $registry->resolveByType(ProviderType::Anthropic, $server);

        $this->assertSame($mockProvider, $result);
    }

    #[Test]
    public function resolveByType_resolves_llamacpp_provider(): void
    {
        $registry = new ProviderRegistry();
        $mockProvider = Mockery::mock(LlmProvider::class);
        $factory = fn (Server $server) => $mockProvider;

        $registry->register(ProviderType::LlamaCpp, $factory);

        $server = $this->createServer();
        $result = $registry->resolveByType(ProviderType::LlamaCpp, $server);

        $this->assertSame($mockProvider, $result);
    }

    #[Test]
    public function resolveByType_passes_server_to_factory(): void
    {
        $registry = new ProviderRegistry();
        $receivedServer = null;
        $mockProvider = Mockery::mock(LlmProvider::class);
        $factory = static function (Server $srv) use (&$receivedServer, $mockProvider) {
            $receivedServer = $srv;
            return $mockProvider;
        };

        $registry->register(ProviderType::OpenAI, $factory);

        $server = $this->createServer();
        $result = $registry->resolveByType(ProviderType::OpenAI, $server);

        $this->assertSame($server, $receivedServer);
        $this->assertSame($mockProvider, $result);
    }

    #[Test]
    public function resolveByType_throws_for_unregistered_type(): void
    {
        $registry = new ProviderRegistry();
        $registry->register(ProviderType::OpenAI, fn () => Mockery::mock(LlmProvider::class));

        $server = $this->createServer();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("No provider registered for type 'anthropic'");

        $registry->resolveByType(ProviderType::Anthropic, $server);
    }

    #[Test]
    public function resolveByType_does_not_fall_back_to_default(): void
    {
        $registry = new ProviderRegistry();
        $defaultProvider = Mockery::mock(LlmProvider::class);
        $registry->default(fn () => $defaultProvider);

        $server = $this->createServer();

        $this->expectException(RuntimeException::class);

        $registry->resolveByType(ProviderType::Anthropic, $server);
    }
}
