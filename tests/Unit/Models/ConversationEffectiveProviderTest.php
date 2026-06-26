<?php

namespace ClarionApp\LlmClient\Tests\Unit\Models;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Contracts\ProviderType;
use Mockery;

use PHPUnit\Framework\Attributes\Test;

class ConversationEffectiveProviderTest extends TestCase
{
    /**
     * Create a Conversation with a pre-loaded Server relationship (no DB persistence).
     */
    private function createConversationWithServer(ProviderType $serverProviderType, ?ProviderType $override = null): Conversation
    {
        // Create Server in memory using setRawAttributes (matches existing test pattern)
        $server = new Server();
        $server->setRawAttributes([
            'id' => 1,
            'name' => 'Test Server',
            'server_url' => 'http://localhost',
            'token' => 'test-token',
            'provider_type' => $serverProviderType->value,
        ], true);

        // Create Conversation in memory
        $conversation = new Conversation();
        $conversation->setRawAttributes([
            'id' => 1,
            'server_id' => 1,
            'title' => 'Test Conversation',
            'provider_override' => $override ? $override->value : null,
        ], true);

        // Pre-load the server relationship so the accessor finds it
        $conversation->setRelation('server', $server);

        return $conversation;
    }

    #[Test]
    public function effectiveProviderType_returns_server_providerType_when_override_is_null(): void
    {
        $conversation = $this->createConversationWithServer(ProviderType::OpenAI, null);

        $result = $conversation->effectiveProviderType;

        $this->assertEquals(ProviderType::OpenAI, $result);
    }

    #[Test]
    public function effectiveProviderType_returns_server_providerType_for_anthropic_server(): void
    {
        $conversation = $this->createConversationWithServer(ProviderType::Anthropic, null);

        $result = $conversation->effectiveProviderType;

        $this->assertEquals(ProviderType::Anthropic, $result);
    }

    #[Test]
    public function effectiveProviderType_returns_server_providerType_for_llamacpp_server(): void
    {
        $conversation = $this->createConversationWithServer(ProviderType::LlamaCpp, null);

        $result = $conversation->effectiveProviderType;

        $this->assertEquals(ProviderType::LlamaCpp, $result);
    }

    /* ------------------------------------------------------------------ */
    /* US2: Override tests                                                  */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function effectiveProviderType_returns_override_when_set(): void
    {
        // Server is OpenAI, but conversation overrides to Anthropic
        $conversation = $this->createConversationWithServer(ProviderType::OpenAI, ProviderType::Anthropic);

        $result = $conversation->effectiveProviderType;

        $this->assertEquals(ProviderType::Anthropic, $result);
    }

    #[Test]
    public function effectiveProviderType_returns_override_for_llamacpp_override(): void
    {
        // Server is Anthropic, but conversation overrides to LlamaCpp
        $conversation = $this->createConversationWithServer(ProviderType::Anthropic, ProviderType::LlamaCpp);

        $result = $conversation->effectiveProviderType;

        $this->assertEquals(ProviderType::LlamaCpp, $result);
    }

    #[Test]
    public function effectiveProviderType_cleared_override_falls_back_to_server(): void
    {
        // Null override should fall back to server default (OpenAI)
        $conversation = $this->createConversationWithServer(ProviderType::OpenAI, null);

        $result = $conversation->effectiveProviderType;

        $this->assertEquals(ProviderType::OpenAI, $result);
    }

    #[Test]
    public function effectiveProviderType_throws_when_server_missing(): void
    {
        // Create conversation with null server relationship (server deleted)
        $conversation = new Conversation();
        $conversation->setRawAttributes([
            'id' => 1,
            'server_id' => 999,
            'title' => 'Test Conversation',
            'provider_override' => null,
        ], true);

        // Explicitly set server relation to null to avoid database lookup
        $conversation->setRelation('server', null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No LLM server configured for this conversation');

        $conversation->effectiveProviderType;
    }
}
