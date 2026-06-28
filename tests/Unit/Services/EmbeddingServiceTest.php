<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Contracts\MemoryScope;
use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Models\MemoryEntry;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\EmbeddingService;
use Illuminate\Support\Str;
use RuntimeException;

class EmbeddingServiceTest extends TestCase
{
    protected EmbeddingService $service;
    protected ProviderRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        // Create servers table for embedding server tests
        if (!\Illuminate\Support\Facades\Schema::hasTable('llm_servers')) {
            \Illuminate\Support\Facades\Schema::create('llm_servers', function ($table) {
                $table->uuid('id')->primary();
                $table->string('name')->nullable();
                $table->text('server_url')->nullable();
                $table->longText('token')->nullable();
                $table->string('provider_type')->default('openai');
                $table->timestamps();
                $table->softDeletes();
            });
        }

        $this->registry = app(ProviderRegistry::class);
        $this->service = new EmbeddingService($this->registry);
    }

    // ─── isEnabled() Tests ───

    public function test_is_enabled_returns_true_by_default(): void
    {
        config(['llm-client.memory.embedding.enabled' => true]);
        $this->assertTrue($this->service->isEnabled());
    }

    public function test_is_enabled_returns_false_when_disabled(): void
    {
        config(['llm-client.memory.embedding.enabled' => false]);
        $this->assertFalse($this->service->isEnabled());
    }

    // ─── getProvider() Tests ───

    public function test_get_provider_returns_null_when_disabled(): void
    {
        config(['llm-client.memory.embedding.enabled' => false]);
        $this->assertNull($this->service->getProvider());
    }

    public function test_get_provider_returns_null_when_no_server_configured(): void
    {
        config(['llm-client.memory.embedding.enabled' => true]);
        config(['llm-client.memory.embedding.server_id' => null]);
        $this->assertNull($this->service->getProvider());
    }

    public function test_get_provider_returns_provider_for_dedicated_server(): void
    {
        // Register a mock provider factory
        $mockProvider = $this->createMock(LlmProvider::class);
        $this->registry->register(
            ProviderType::OpenAI,
            fn () => $mockProvider
        );

        // Create a server record
        $server = Server::create([
            'id' => (string) Str::uuid(),
            'name' => 'Embedding Server',
            'server_url' => 'https://api.example.com',
            'token' => 'test-token',
            'provider_type' => ProviderType::OpenAI,
        ]);

        config(['llm-client.memory.embedding.enabled' => true]);
        config(['llm-client.memory.embedding.server_id' => $server->id]);

        $provider = $this->service->getProvider();
        $this->assertSame($mockProvider, $provider);
    }

    public function test_get_provider_returns_null_for_invalid_server_id(): void
    {
        config(['llm-client.memory.embedding.enabled' => true]);
        config(['llm-client.memory.embedding.server_id' => (string) Str::uuid()]); // Non-existent server

        $this->assertNull($this->service->getProvider());
    }

    // ─── cosineSimilarity() Tests ───

    public function test_cosine_similarity_identical_vectors(): void
    {
        $a = [1.0, 0.0, 0.0];
        $b = [1.0, 0.0, 0.0];
        $this->assertEquals(1.0, EmbeddingService::cosineSimilarity($a, $b), 0.0001);
    }

    public function test_cosine_similarity_orthogonal_vectors(): void
    {
        $a = [1.0, 0.0, 0.0];
        $b = [0.0, 1.0, 0.0];
        $this->assertEquals(0.0, EmbeddingService::cosineSimilarity($a, $b), 0.0001);
    }

    public function test_cosine_similarity_opposite_vectors(): void
    {
        $a = [1.0, 0.0, 0.0];
        $b = [-1.0, 0.0, 0.0];
        $this->assertEquals(-1.0, EmbeddingService::cosineSimilarity($a, $b), 0.0001);
    }

    public function test_cosine_similarity_different_dimensions(): void
    {
        $a = [1.0, 0.0, 0.0];
        $b = [1.0, 0.0];
        $this->assertEquals(0.0, EmbeddingService::cosineSimilarity($a, $b), 0.0001);
    }

    public function test_cosine_similarity_empty_vectors(): void
    {
        $this->assertEquals(0.0, EmbeddingService::cosineSimilarity([], []), 0.0001);
    }

    public function test_cosine_similarity_zero_norm(): void
    {
        $a = [0.0, 0.0, 0.0];
        $b = [1.0, 0.0, 0.0];
        $this->assertEquals(0.0, EmbeddingService::cosineSimilarity($a, $b), 0.0001);
    }

    // ─── normalizeSimilarity() Tests ───

    public function test_normalize_similarity_one(): void
    {
        $this->assertEquals(1.0, EmbeddingService::normalizeSimilarity(1.0));
    }

    public function test_normalize_similarity_zero(): void
    {
        $this->assertEquals(0.5, EmbeddingService::normalizeSimilarity(0.0));
    }

    public function test_normalize_similarity_negative_one(): void
    {
        $this->assertEquals(0.0, EmbeddingService::normalizeSimilarity(-1.0));
    }

    public function test_normalize_similarity_partial(): void
    {
        $this->assertEquals(0.75, EmbeddingService::normalizeSimilarity(0.5));
    }

    // ─── generateForEntry() Tests ───

    public function test_generate_for_entry_skips_scratch_scope(): void
    {
        $entry = MemoryEntry::create([
            'id' => (string) Str::uuid(),
            'scope' => MemoryScope::SCRATCH,
            'agent_id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'key' => 'test',
            'content' => 'test content',
        ]);

        $result = $this->service->generateForEntry($entry);
        $this->assertFalse($result);
    }

    public function test_generate_for_entry_skips_short_term_scope(): void
    {
        $entry = MemoryEntry::create([
            'id' => (string) Str::uuid(),
            'scope' => MemoryScope::SHORT_TERM,
            'agent_id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'key' => 'test',
            'content' => 'test content',
        ]);

        $result = $this->service->generateForEntry($entry);
        $this->assertFalse($result);
    }

    public function test_generate_for_entry_succeeds_for_long_term(): void
    {
        // Register a mock provider that returns a valid embedding
        $mockProvider = $this->createMock(LlmProvider::class);
        $mockProvider->expects($this->once())
            ->method('embed')
            ->willReturn(['embeddings' => [[0.1, 0.2, 0.3]]]);

        $this->registry->register(
            ProviderType::OpenAI,
            fn () => $mockProvider
        );

        // Create server and configure
        $server = Server::create([
            'id' => (string) Str::uuid(),
            'name' => 'Embedding Server',
            'server_url' => 'https://api.example.com',
            'token' => 'test-token',
            'provider_type' => ProviderType::OpenAI,
        ]);

        config(['llm-client.memory.embedding.enabled' => true]);
        config(['llm-client.memory.embedding.server_id' => $server->id]);

        $entry = MemoryEntry::create([
            'id' => (string) Str::uuid(),
            'scope' => MemoryScope::LONG_TERM,
            'agent_id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'key' => 'test',
            'content' => 'test content for embedding',
        ]);

        $result = $this->service->generateForEntry($entry);
        $this->assertTrue($result);

        $entry->refresh();
        $this->assertNotNull($entry->embedding);
        $this->assertEquals([0.1, 0.2, 0.3], $entry->embedding);
    }

    public function test_generate_for_entry_handles_provider_failure(): void
    {
        // Register a mock provider that throws an exception
        $mockProvider = $this->createMock(LlmProvider::class);
        $mockProvider->method('embed')
            ->willThrowException(new RuntimeException('Provider error'));

        $this->registry->register(
            ProviderType::OpenAI,
            fn () => $mockProvider
        );

        $server = Server::create([
            'id' => (string) Str::uuid(),
            'name' => 'Embedding Server',
            'server_url' => 'https://api.example.com',
            'token' => 'test-token',
            'provider_type' => ProviderType::OpenAI,
        ]);

        config(['llm-client.memory.embedding.enabled' => true]);
        config(['llm-client.memory.embedding.server_id' => $server->id]);

        $entry = MemoryEntry::create([
            'id' => (string) Str::uuid(),
            'scope' => MemoryScope::LONG_TERM,
            'agent_id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'key' => 'test',
            'content' => 'test content',
        ]);

        // Should not throw, just return false
        $result = $this->service->generateForEntry($entry);
        $this->assertFalse($result);

        $entry->refresh();
        $this->assertNull($entry->embedding);
    }

    // ─── Content Truncation Tests ───

    public function test_generate_truncates_long_content(): void
    {
        $mockProvider = $this->createMock(LlmProvider::class);
        $mockProvider->expects($this->once())
            ->method('embed')
            ->willReturnCallback(function (array $inputs) {
                // Verify content was truncated (max 8000 chars)
                $this->assertLessThanOrEqual(8000, strlen($inputs[0]));
                return ['embeddings' => [[0.1, 0.2]]];
            });

        $this->registry->register(
            ProviderType::OpenAI,
            fn () => $mockProvider
        );

        $server = Server::create([
            'id' => (string) Str::uuid(),
            'name' => 'Embedding Server',
            'server_url' => 'https://api.example.com',
            'token' => 'test-token',
            'provider_type' => ProviderType::OpenAI,
        ]);

        config(['llm-client.memory.embedding.enabled' => true]);
        config(['llm-client.memory.embedding.server_id' => $server->id]);

        // Generate with very long content
        $longContent = str_repeat('word ', 2000); // ~10000 chars
        $embedding = $this->service->generate($longContent);
        $this->assertCount(2, $embedding);
    }
}
