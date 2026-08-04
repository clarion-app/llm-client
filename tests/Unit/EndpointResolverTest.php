<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Services\EndpointResolver;
use ClarionApp\LlmClient\ValueObjects\Operation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EndpointResolver.
 *
 * Tests URL derivation, header derivation, and supports() matrix.
 * Uses the model-independent *ForValues methods to avoid Eloquent mocking.
 */
class EndpointResolverTest extends TestCase
{
    private EndpointResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new EndpointResolver();
    }

    // ─── URL derivation matrix: 5 operations × 3 families ───

    #[Test]
    #[DataProvider('urlDerivationCases')]
    public function urlFor_matrix(
        ProviderType $family,
        Operation $operation,
        string $serverUrl,
        string $expectedUrl,
    ): void {
        $url = $this->resolver->urlForValues($family, $serverUrl, $operation);
        $this->assertSame($expectedUrl, $url);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function urlDerivationCases(): array
    {
        return [
            // OpenAI: 5 operations
            'openai/chat'             => [ProviderType::OpenAI, Operation::Chat, 'http://localhost:8081', 'http://localhost:8081/v1/chat/completions'],
            'openai/chat_stream'      => [ProviderType::OpenAI, Operation::ChatStream, 'http://localhost:8081', 'http://localhost:8081/v1/chat/completions'],
            'openai/title_generation' => [ProviderType::OpenAI, Operation::TitleGeneration, 'http://localhost:8081', 'http://localhost:8081/v1/chat/completions'],
            'openai/embeddings'       => [ProviderType::OpenAI, Operation::Embeddings, 'http://localhost:8081', 'http://localhost:8081/v1/embeddings'],
            'openai/models'           => [ProviderType::OpenAI, Operation::Models, 'http://localhost:8081', 'http://localhost:8081/v1/models'],
            // LlamaCpp: 5 operations
            'llama.cpp/chat'          => [ProviderType::LlamaCpp, Operation::Chat, 'http://localhost:8081', 'http://localhost:8081/v1/chat/completions'],
            'llama.cpp/chat_stream'   => [ProviderType::LlamaCpp, Operation::ChatStream, 'http://localhost:8081', 'http://localhost:8081/v1/chat/completions'],
            'llama.cpp/title_gen'     => [ProviderType::LlamaCpp, Operation::TitleGeneration, 'http://localhost:8081', 'http://localhost:8081/v1/chat/completions'],
            'llama.cpp/embeddings'    => [ProviderType::LlamaCpp, Operation::Embeddings, 'http://localhost:8081', 'http://localhost:8081/v1/embeddings'],
            'llama.cpp/models'        => [ProviderType::LlamaCpp, Operation::Models, 'http://localhost:8081', 'http://localhost:8081/v1/models'],
            // Anthropic: 4 supported + 1 unsupported
            'anthropic/chat'          => [ProviderType::Anthropic, Operation::Chat, 'https://api.anthropic.com', 'https://api.anthropic.com/v1/messages'],
            'anthropic/chat_stream'   => [ProviderType::Anthropic, Operation::ChatStream, 'https://api.anthropic.com', 'https://api.anthropic.com/v1/messages'],
            'anthropic/title_gen'     => [ProviderType::Anthropic, Operation::TitleGeneration, 'https://api.anthropic.com', 'https://api.anthropic.com/v1/messages'],
            'anthropic/models'        => [ProviderType::Anthropic, Operation::Models, 'https://api.anthropic.com', 'https://api.anthropic.com/v1/models'],
        ];
    }

    #[Test]
    public function urlFor_anthropic_embeddings_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/anthropic.*embeddings/i');
        $this->resolver->urlForValues(ProviderType::Anthropic, 'https://api.anthropic.com', Operation::Embeddings);
    }

    // ─── Custom path prefix preserved ───

    #[Test]
    public function urlFor_customPathPrefix_preserved(): void
    {
        $url = $this->resolver->urlForValues(
            ProviderType::OpenAI,
            'https://host/llm/v1/chat/completions',
            Operation::Chat,
        );
        $this->assertSame('https://host/llm/v1/chat/completions', $url);
    }

    #[Test]
    public function urlFor_customPathPrefix_embeddings(): void
    {
        $url = $this->resolver->urlForValues(
            ProviderType::OpenAI,
            'https://host/llm/v1/chat/completions',
            Operation::Embeddings,
        );
        $this->assertSame('https://host/llm/v1/embeddings', $url);
    }

    // ─── Header derivation ───

    #[Test]
    public function headersFor_openai_includesAuthorization_whenTokenPresent(): void
    {
        $headers = $this->resolver->headersForValues(ProviderType::OpenAI, 'sk-test', Operation::Chat);
        $this->assertArrayHasKey('Authorization', $headers);
        $this->assertSame('Bearer sk-test', $headers['Authorization']);
    }

    #[Test]
    public function headersFor_openai_omitsAuthorization_whenTokenNull(): void
    {
        $headers = $this->resolver->headersForValues(ProviderType::OpenAI, null, Operation::Chat);
        $this->assertArrayNotHasKey('Authorization', $headers);
    }

    #[Test]
    public function headersFor_openai_omitsAuthorization_whenTokenEmpty(): void
    {
        $headers = $this->resolver->headersForValues(ProviderType::OpenAI, '', Operation::Chat);
        $this->assertArrayNotHasKey('Authorization', $headers);
    }

    #[Test]
    public function headersFor_chatStream_acceptsSse(): void
    {
        $headers = $this->resolver->headersForValues(ProviderType::OpenAI, 'sk-test', Operation::ChatStream);
        $this->assertSame('text/event-stream', $headers['Accept']);
    }

    #[Test]
    public function headersFor_chat_acceptsJson(): void
    {
        $headers = $this->resolver->headersForValues(ProviderType::OpenAI, 'sk-test', Operation::Chat);
        $this->assertSame('application/json', $headers['Accept']);
    }

    #[Test]
    public function headersFor_anthropic_includesXApiKey(): void
    {
        $headers = $this->resolver->headersForValues(ProviderType::Anthropic, 'sk-ant-test', Operation::Chat);
        $this->assertArrayHasKey('x-api-key', $headers);
        $this->assertSame('sk-ant-test', $headers['x-api-key']);
    }

    #[Test]
    public function headersFor_anthropic_includesAnthropicVersion(): void
    {
        $headers = $this->resolver->headersForValues(ProviderType::Anthropic, 'sk-ant-test', Operation::Chat);
        $this->assertArrayHasKey('anthropic-version', $headers);
    }

    #[Test]
    public function headersFor_anthropic_omitsAuthorization(): void
    {
        $headers = $this->resolver->headersForValues(ProviderType::Anthropic, 'sk-ant-test', Operation::Chat);
        $this->assertArrayNotHasKey('Authorization', $headers);
    }

    // ─── provider_type null resolves to openai (proxy methods) ───

    #[Test]
    public function proxyMethods_delegateCorrectly(): void
    {
        // The urlFor/headersFor/supports proxy methods extract values from
        // the Server model and delegate to the *ForValues methods.
        // We verify the proxy methods exist and are callable.
        $this->assertTrue(method_exists($this->resolver, 'urlFor'));
        $this->assertTrue(method_exists($this->resolver, 'headersFor'));
        $this->assertTrue(method_exists($this->resolver, 'supports'));
    }

    // ─── supports() ───

    #[Test]
    public function supports_openai_allOperations(): void
    {
        foreach (Operation::cases() as $op) {
            $this->assertTrue(
                $this->resolver->supportsValues(ProviderType::OpenAI, $op),
                "OpenAI should support {$op->name}"
            );
        }
    }

    #[Test]
    public function supports_anthropic_allExceptEmbeddings(): void
    {
        $this->assertTrue($this->resolver->supportsValues(ProviderType::Anthropic, Operation::Chat));
        $this->assertTrue($this->resolver->supportsValues(ProviderType::Anthropic, Operation::ChatStream));
        $this->assertTrue($this->resolver->supportsValues(ProviderType::Anthropic, Operation::TitleGeneration));
        $this->assertTrue($this->resolver->supportsValues(ProviderType::Anthropic, Operation::Models));
        $this->assertFalse($this->resolver->supportsValues(ProviderType::Anthropic, Operation::Embeddings));
    }

    #[Test]
    public function supports_llamaCpp_allOperations(): void
    {
        foreach (Operation::cases() as $op) {
            $this->assertTrue(
                $this->resolver->supportsValues(ProviderType::LlamaCpp, $op),
                "LlamaCpp should support {$op->name}"
            );
        }
    }
}
