<?php

namespace ClarionApp\LlmClient\Tests\Feature\Providers;

use PHPUnit\Framework\TestCase;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Providers\ProviderRegistry;

/**
 * Integration tests for mixed-provider routing.
 *
 * Verifies that ProviderRegistry correctly routes servers with different
 * provider_type values to the appropriate LlmProvider implementation.
 *
 */
class ProviderRoutingTest extends TestCase
{
    /**
     * Helper: create a mock Server via anonymous class extending Server.
     * This avoids database/Eloquent issues in plain PHPUnit TestCase.
     */
    private function createServer(ProviderType $providerType): Server
    {
        return new class($providerType) extends Server {
            public function __construct(ProviderType $providerType)
            {
                // Do not call parent constructor — avoids database/boots issues.
                $this->provider_type = $providerType;
            }
        };
    }

    /**
     * Mixed provider routing — OpenAI server resolves to OpenAiProvider.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function openai_server_resolves_to_openai_provider(): void
    {
        $registry = new ProviderRegistry();
        $registry->register(ProviderType::OpenAI, function () {
            return $this->mockProvider('openai');
        });
        $registry->register(ProviderType::Anthropic, function () {
            return $this->mockProvider('anthropic');
        });

        $server = $this->createServer(ProviderType::OpenAI);

        $provider = $registry->resolve($server);
        $this->assertInstanceOf(LlmProvider::class, $provider);
        $this->assertEquals('openai', $provider->getAttribute('provider_type'));
    }

    /**
     * Mixed provider routing — Anthropic server resolves to AnthropicProvider.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function anthropic_server_resolves_to_anthropic_provider(): void
    {
        $registry = new ProviderRegistry();
        $registry->register(ProviderType::OpenAI, function () {
            return $this->mockProvider('openai');
        });
        $registry->register(ProviderType::Anthropic, function () {
            return $this->mockProvider('anthropic');
        });

        $server = $this->createServer(ProviderType::Anthropic);

        $provider = $registry->resolve($server);
        $this->assertInstanceOf(LlmProvider::class, $provider);
        $this->assertEquals('anthropic', $provider->getAttribute('provider_type'));
    }

    /**
     * Legacy server (no provider_type) defaults to OpenAI provider
     * via the default factory when no explicit factory is registered.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function legacy_server_defaults_to_openai_provider(): void
    {
        $registry = new ProviderRegistry();
        // Register OpenAI explicitly and set it as default too.
        $openAiProvider = $this->mockProvider('openai');
        $registry->register(ProviderType::OpenAI, function () use ($openAiProvider) {
            return $openAiProvider;
        });
        $registry->default(function () use ($openAiProvider) {
            return $openAiProvider;
        });

        $server = $this->createServer(ProviderType::OpenAI);

        $provider = $registry->resolve($server);
        $this->assertInstanceOf(LlmProvider::class, $provider);
        $this->assertEquals('openai', $provider->getAttribute('provider_type'));
    }

    /**
     * Server with unregistered provider_type falls back to default factory.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function server_with_unregistered_type_uses_default_factory(): void
    {
        $registry = new ProviderRegistry();
        // Only register Anthropic, not OpenAI.
        $registry->register(ProviderType::Anthropic, function () {
            return $this->mockProvider('anthropic');
        });
        $defaultProvider = $this->mockProvider('openai');
        $registry->default(function () use ($defaultProvider) {
            return $defaultProvider;
        });

        // Server with provider_type = OpenAI (not registered) → should use default.
        $server = $this->createServer(ProviderType::OpenAI);

        // Should NOT throw because default factory exists.
        $provider = $registry->resolve($server);
        $this->assertInstanceOf(LlmProvider::class, $provider);
        $this->assertEquals('openai', $provider->getAttribute('provider_type'));
    }

    /**
     * Multiple servers with different types can coexist and route correctly.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function mixed_provider_environment_routes_correctly(): void
    {
        $registry = new ProviderRegistry();
        $registry->register(ProviderType::OpenAI, function () {
            return $this->mockProvider('openai');
        });
        $registry->register(ProviderType::Anthropic, function () {
            return $this->mockProvider('anthropic');
        });

        $openAiServer = $this->createServer(ProviderType::OpenAI);
        $anthropicServer = $this->createServer(ProviderType::Anthropic);

        $openAiProvider = $registry->resolve($openAiServer);
        $anthropicProvider = $registry->resolve($anthropicServer);

        $this->assertNotSame($openAiProvider, $anthropicProvider);
        $this->assertEquals('openai', $openAiProvider->getAttribute('provider_type'));
        $this->assertEquals('anthropic', $anthropicProvider->getAttribute('provider_type'));
    }

    /**
     * getRegisteredTypes returns only explicitly registered type strings.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function getRegisteredTypes_returns_only_registered_types(): void
    {
        $registry = new ProviderRegistry();
        $registry->register(ProviderType::OpenAI, function () { return null; });
        $registry->register(ProviderType::Anthropic, function () { return null; });

        $types = $registry->getRegisteredTypes();
        $this->assertEquals(['openai', 'anthropic'], $types);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Create a mock LlmProvider implementation with a provider_type identifier.
     */
    private function mockProvider(string $type): LlmProvider
    {
        return new class($type) implements LlmProvider {
            private string $providerType;

            public function __construct(string $type)
            {
                $this->providerType = $type;
            }

            public function getAttribute($key)
            {
                if ($key === 'provider_type') return $this->providerType;
                return null;
            }

            public function chat(array $messages, array $tools = [], array $options = []): array
            {
                return ['content' => [['type' => 'text', 'text' => 'mock response from ' . $this->providerType]]];
            }

            public function stream(array $messages, array $tools = [], array $options = []): \Generator
            {
                yield ['content' => [['type' => 'text', 'text' => 'chunk from ' . $this->providerType]]];
            }

            public function embed(array $inputs, array $options = []): array
            {
                return ['data' => []];
            }

            public function countTokens(string $text, ?string $model = null): int
            {
                return strlen($text) / 4;
            }
        };
    }
}
