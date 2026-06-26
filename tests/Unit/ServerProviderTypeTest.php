<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Contracts\ProviderType;

use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for Server model provider_type attribute.
 * These tests verify model configuration (fillable, casts, accessor)
 * without requiring database connectivity.
 */
class ServerProviderTypeTest extends TestCase
{
    #[Test]
    public function server_model_has_provider_type_in_fillable(): void
    {
        $server = new Server();
        $fillable = $server->getFillable();

        $this->assertContains('provider_type', $fillable);
    }

    #[Test]
    public function server_model_casts_provider_type_to_enum(): void
    {
        $server = new Server();
        $casts = $server->getCasts();

        $this->assertArrayHasKey('provider_type', $casts);
        $this->assertEquals(ProviderType::class, $casts['provider_type']);
    }

    #[Test]
    public function server_provider_type_returns_openai_for_null(): void
    {
        $server = new Server();
        // Simulate null value from database via setRawAttributes
        $server->setRawAttributes(['provider_type' => null]);

        $this->assertEquals(ProviderType::OpenAI, $server->provider_type);
    }

    #[Test]
    public function server_provider_type_returns_openai_for_invalid_value(): void
    {
        $server = new Server();
        // Simulate invalid string value from database
        $server->setRawAttributes(['provider_type' => 'invalid-provider']);

        $this->assertEquals(ProviderType::OpenAI, $server->provider_type);
    }

    #[Test]
    public function server_provider_type_parsing_openai_string(): void
    {
        $server = new Server();
        $server->setRawAttributes(['provider_type' => 'openai']);

        $this->assertEquals(ProviderType::OpenAI, $server->provider_type);
    }

    #[Test]
    public function server_provider_type_parsing_anthropic_string(): void
    {
        $server = new Server();
        $server->setRawAttributes(['provider_type' => 'anthropic']);

        $this->assertEquals(ProviderType::Anthropic, $server->provider_type);
    }
}
