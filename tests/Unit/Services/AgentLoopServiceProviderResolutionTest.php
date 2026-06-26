<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\MessageFormatter;
use ClarionApp\LlmClient\Services\ToolFormatter;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Server;
use Mockery;

use PHPUnit\Framework\Attributes\Test;

class AgentLoopServiceProviderResolutionTest extends TestCase
{
    /**
     * Build an AgentLoopService with mocked dependencies and a conversation that
     * has a known effectiveProviderType.
     */
    private function buildService(ProviderType $effectiveType, ?ProviderType $override = null): AgentLoopService
    {
        // Create Server in memory
        $serverProviderType = $override !== null ? ProviderType::OpenAI : $effectiveType;
        $server = new Server();
        $server->setRawAttributes([
            'id' => 1,
            'name' => 'Test Server',
            'server_url' => 'http://localhost',
            'token' => 'test-token',
            'provider_type' => $serverProviderType->value,
        ], true);

        // Create Conversation with override if set
        $conversation = new Conversation();
        $conversation->setRawAttributes([
            'id' => 1,
            'server_id' => 1,
            'title' => 'Test Conversation',
            'model' => 'test-model',
            'character' => 'Clarion',
            'provider_override' => $override ? $override->value : null,
        ], true);
        $conversation->setRelation('server', $server);

        // Mock dependencies
        $toolRegistry = Mockery::mock(McpToolRegistry::class);
        $toolExecutor = Mockery::mock(McpToolExecutor::class);
        $operationCache = Mockery::mock(OperationCache::class);
        $operationCache->shouldReceive('getEntries')->andReturn([]);

        // Real ProviderRegistry so we can verify resolveByType is called
        $registry = new ProviderRegistry();
        $mockProvider = Mockery::mock(LlmProvider::class);
        $registry->register(ProviderType::OpenAI, fn () => $mockProvider);
        $registry->register(ProviderType::Anthropic, fn () => $mockProvider);
        $registry->register(ProviderType::LlamaCpp, fn () => $mockProvider);

        $messageFormatter = Mockery::mock(MessageFormatter::class);
        $messageFormatter->shouldReceive('formatForProvider')
            ->with(Mockery::type('array'), Mockery::type(ProviderType::class))
            ->andReturn(['messages' => [], 'system' => '']);

        $toolFormatter = Mockery::mock(ToolFormatter::class);
        $toolFormatter->shouldReceive('formatForProvider')
            ->with(Mockery::type('array'), Mockery::type(ProviderType::class))
            ->andReturn([]);

        return new AgentLoopService(
            $toolRegistry,
            $toolExecutor,
            $operationCache,
            $registry,
            $messageFormatter,
            $toolFormatter
        );
    }

    #[Test]
    public function formatMessages_uses_effectiveProviderType_with_override(): void
    {
        // Server is OpenAI, conversation overrides to Anthropic
        // We verify that formatForProvider is called with Anthropic (the override)
        $toolRegistry = Mockery::mock(McpToolRegistry::class);
        $toolExecutor = Mockery::mock(McpToolExecutor::class);
        $operationCache = Mockery::mock(OperationCache::class);
        $operationCache->shouldReceive('getEntries')->andReturn([]);
        $registry = new ProviderRegistry();

        // Capture the ProviderType passed to formatForProvider
        $capturedType = null;
        $messageFormatter = Mockery::mock(MessageFormatter::class);
        $messageFormatter->shouldReceive('formatForProvider')
            ->with(Mockery::type('array'), Mockery::on(function ($type) use (&$capturedType) {
                $capturedType = $type;
                return $type instanceof ProviderType;
            }))
            ->andReturn(['messages' => [], 'system' => '']);

        $toolFormatter = Mockery::mock(ToolFormatter::class);
        $toolFormatter->shouldReceive('formatForProvider')
            ->andReturn([]);

        $service = new AgentLoopService(
            $toolRegistry,
            $toolExecutor,
            $operationCache,
            $registry,
            $messageFormatter,
            $toolFormatter
        );

        // Use reflection to call the private formatMessages method
        $server = new Server();
        $server->setRawAttributes([
            'id' => 1,
            'provider_type' => 'openai',
        ], true);

        $conversation = new Conversation();
        $conversation->setRawAttributes([
            'id' => 1,
            'server_id' => 1,
            'provider_override' => 'anthropic',
        ], true);
        $conversation->setRelation('server', $server);

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('formatMessages');
        $method->invoke($service, $conversation, []);

        $this->assertEquals(ProviderType::Anthropic, $capturedType);
    }

    #[Test]
    public function formatTools_uses_effectiveProviderType_with_override(): void
    {
        $toolRegistry = Mockery::mock(McpToolRegistry::class);
        $toolExecutor = Mockery::mock(McpToolExecutor::class);
        $operationCache = Mockery::mock(OperationCache::class);
        $operationCache->shouldReceive('getEntries')->andReturn([]);
        $registry = new ProviderRegistry();

        $messageFormatter = Mockery::mock(MessageFormatter::class);
        $messageFormatter->shouldReceive('formatForProvider')
            ->andReturn(['messages' => [], 'system' => '']);

        $capturedType = null;
        $toolFormatter = Mockery::mock(ToolFormatter::class);
        $toolFormatter->shouldReceive('formatForProvider')
            ->with(Mockery::type('array'), Mockery::on(function ($type) use (&$capturedType) {
                $capturedType = $type;
                return $type instanceof ProviderType;
            }))
            ->andReturn([]);

        $service = new AgentLoopService(
            $toolRegistry,
            $toolExecutor,
            $operationCache,
            $registry,
            $messageFormatter,
            $toolFormatter
        );

        $server = new Server();
        $server->setRawAttributes([
            'id' => 1,
            'provider_type' => 'openai',
        ], true);

        $conversation = new Conversation();
        $conversation->setRawAttributes([
            'id' => 1,
            'server_id' => 1,
            'provider_override' => 'anthropic',
        ], true);
        $conversation->setRelation('server', $server);

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('formatTools');
        $method->invoke($service, $conversation, []);

        $this->assertEquals(ProviderType::Anthropic, $capturedType);
    }

    #[Test]
    public function callLlmSync_uses_resolveByType_with_override(): void
    {
        $toolRegistry = Mockery::mock(McpToolRegistry::class);
        $toolExecutor = Mockery::mock(McpToolExecutor::class);
        $operationCache = Mockery::mock(OperationCache::class);
        $operationCache->shouldReceive('getEntries')->andReturn([]);

        // Use a mock registry to capture resolveByType calls
        $capturedType = null;
        $capturedServer = null;
        $mockProvider = Mockery::mock(LlmProvider::class);
        $mockProvider->shouldReceive('chat')
            ->andReturn(['choices' => [['message' => ['content' => 'test', 'tool_calls' => []]]]]);

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolveByType')
            ->with(Mockery::on(function ($type) use (&$capturedType) {
                $capturedType = $type;
                return $type instanceof ProviderType;
            }), Mockery::on(function ($server) use (&$capturedServer) {
                $capturedServer = $server;
                return $server instanceof Server;
            }))
            ->andReturn($mockProvider);

        $messageFormatter = Mockery::mock(MessageFormatter::class);
        $messageFormatter->shouldReceive('formatForProvider')
            ->andReturn(['messages' => [], 'system' => '']);

        $toolFormatter = Mockery::mock(ToolFormatter::class);
        $toolFormatter->shouldReceive('formatForProvider')
            ->andReturn([]);

        $service = new AgentLoopService(
            $toolRegistry,
            $toolExecutor,
            $operationCache,
            $registry,
            $messageFormatter,
            $toolFormatter
        );

        $server = new Server();
        $server->setRawAttributes([
            'id' => 1,
            'provider_type' => 'openai',
            'server_url' => 'http://localhost',
            'token' => 'secret',
        ], true);

        $conversation = new Conversation();
        $conversation->setRawAttributes([
            'id' => 1,
            'server_id' => 1,
            'model' => 'test-model',
            'provider_override' => 'anthropic',
        ], true);
        $conversation->setRelation('server', $server);

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('callLlmSync');
        $method->invoke($service, $conversation, [], []);

        $this->assertEquals(ProviderType::Anthropic, $capturedType);
        $this->assertSame($server, $capturedServer);
    }

    #[Test]
    public function callLlmSync_uses_effectiveProviderType_fallback_to_server_when_no_override(): void
    {
        $toolRegistry = Mockery::mock(McpToolRegistry::class);
        $toolExecutor = Mockery::mock(McpToolExecutor::class);
        $operationCache = Mockery::mock(OperationCache::class);
        $operationCache->shouldReceive('getEntries')->andReturn([]);

        $capturedType = null;
        $mockProvider = Mockery::mock(LlmProvider::class);
        $mockProvider->shouldReceive('chat')
            ->andReturn(['choices' => [['message' => ['content' => 'test', 'tool_calls' => []]]]]);

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolveByType')
            ->with(Mockery::on(function ($type) use (&$capturedType) {
                $capturedType = $type;
                return $type instanceof ProviderType;
            }), Mockery::type(Server::class))
            ->andReturn($mockProvider);

        $messageFormatter = Mockery::mock(MessageFormatter::class);
        $messageFormatter->shouldReceive('formatForProvider')
            ->andReturn(['messages' => [], 'system' => '']);

        $toolFormatter = Mockery::mock(ToolFormatter::class);
        $toolFormatter->shouldReceive('formatForProvider')
            ->andReturn([]);

        $service = new AgentLoopService(
            $toolRegistry,
            $toolExecutor,
            $operationCache,
            $registry,
            $messageFormatter,
            $toolFormatter
        );

        $server = new Server();
        $server->setRawAttributes([
            'id' => 1,
            'provider_type' => 'anthropic',
            'server_url' => 'http://localhost',
            'token' => 'secret',
        ], true);

        $conversation = new Conversation();
        $conversation->setRawAttributes([
            'id' => 1,
            'server_id' => 1,
            'model' => 'test-model',
            'provider_override' => null,
        ], true);
        $conversation->setRelation('server', $server);

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('callLlmSync');
        $method->invoke($service, $conversation, [], []);

        // No override, so should fall back to server's Anthropic
        $this->assertEquals(ProviderType::Anthropic, $capturedType);
    }
}
