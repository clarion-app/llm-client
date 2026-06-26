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

class ProviderRegistryTest extends TestCase
{
    /**
     * Create a partial mock Server with the given provider_type.
     */
    private function createServerMock(ProviderType $type): Server
    {
        $server = Mockery::mock(Server::class);
        $server->shouldReceive('getAttribute')->with('provider_type')->andReturn($type);
        // Make provider_type property accessible
        $server->provider_type = $type;
        return $server;
    }

    #[Test]
    public function register_stores_factory_for_provider_type(): void
    {
        $registry = new ProviderRegistry();
        $factory = fn () => Mockery::mock(LlmProvider::class);

        $registry->register(ProviderType::OpenAI, $factory);

        $this->assertEquals(['openai'], $registry->getRegisteredTypes());
    }

    #[Test]
    public function register_accepts_string_type(): void
    {
        $registry = new ProviderRegistry();
        $factory = fn () => Mockery::mock(LlmProvider::class);

        $registry->register('openai', $factory);

        $this->assertEquals(['openai'], $registry->getRegisteredTypes());
    }

    #[Test]
    public function resolve_returns_provider_from_factory(): void
    {
        $registry = new ProviderRegistry();
        $mockProvider = Mockery::mock(LlmProvider::class);
        $factory = fn () => $mockProvider;

        $registry->register(ProviderType::OpenAI, $factory);

        $server = new class(ProviderType::OpenAI) extends Server {
            public function __construct(ProviderType $type)
            {
                $this->provider_type = $type;
            }
        };

        $result = $registry->resolve($server);

        $this->assertSame($mockProvider, $result);
    }

    #[Test]
    public function resolve_falls_back_to_default_factory(): void
    {
        $registry = new ProviderRegistry();
        $mockProvider = Mockery::mock(LlmProvider::class);
        $defaultFactory = fn () => $mockProvider;

        $registry->default($defaultFactory);

        $server = new class(ProviderType::OpenAI) extends Server {
            public function __construct(ProviderType $type)
            {
                $this->provider_type = $type;
            }
        };

        $result = $registry->resolve($server);

        $this->assertSame($mockProvider, $result);
    }

    #[Test]
    public function resolve_throws_when_no_factory_registered(): void
    {
        $registry = new ProviderRegistry();

        $server = new class(ProviderType::Anthropic) extends Server {
            public function __construct(ProviderType $type)
            {
                $this->provider_type = $type;
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("No provider registered for type 'anthropic'");

        $registry->resolve($server);
    }

    #[Test]
    public function resolve_prefers_explicit_factory_over_default(): void
    {
        $registry = new ProviderRegistry();
        $explicitProvider = Mockery::mock(LlmProvider::class);
        $defaultProvider = Mockery::mock(LlmProvider::class);

        $registry->register(ProviderType::OpenAI, fn () => $explicitProvider);
        $registry->default(fn () => $defaultProvider);

        $server = new class(ProviderType::OpenAI) extends Server {
            public function __construct(ProviderType $type)
            {
                $this->provider_type = $type;
            }
        };

        $result = $registry->resolve($server);

        $this->assertSame($explicitProvider, $result);
    }

    #[Test]
    public function get_registered_types_returns_empty_when_no_factories(): void
    {
        $registry = new ProviderRegistry();

        $this->assertEquals([], $registry->getRegisteredTypes());
    }

    #[Test]
    public function register_multiple_types(): void
    {
        $registry = new ProviderRegistry();
        $factory = fn () => Mockery::mock(LlmProvider::class);

        $registry->register(ProviderType::OpenAI, $factory);
        $registry->register(ProviderType::Anthropic, $factory);

        $types = $registry->getRegisteredTypes();
        sort($types);

        $this->assertEquals(['anthropic', 'openai'], $types);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
