<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Contracts\MemoryScope;
use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Exceptions\SemanticSearchException;
use ClarionApp\LlmClient\Models\MemoryEntry;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\EmbeddingService;
use ClarionApp\LlmClient\Services\MemoryService;
use Illuminate\Support\Str;
use RuntimeException;

class MemoryServiceSemanticSearchTest extends TestCase
{
    protected MemoryService $memoryService;
    protected ProviderRegistry $registry;
    protected EmbeddingService $embeddingService;

    protected function setUp(): void
    {
        parent::setUp();

        // Create llm_servers table if not exists
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
        $this->embeddingService = new EmbeddingService($this->registry);
        $this->memoryService = new MemoryService(
            null, // no eviction service
            $this->embeddingService
        );
    }

    // ─── T007: Semantic search with mocked provider returns relevant entries ───

    public function test_semantic_search_returns_relevant_entries(): void
    {
        // Register a mock provider
        $mockProvider = $this->createMock(LlmProvider::class);
        $mockProvider->method('embed')
            ->willReturnCallback(function (array $inputs) {
                // Return different embeddings based on input
                $embedding = count($inputs) === 1 ? [0.1, 0.9, 0.0] : [0.2, 0.8, 0.0];
                return ['embeddings' => [$embedding]];
            });

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

        $agentId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        // Create entries with embeddings
        $entry1 = MemoryEntry::create([
            'id' => (string) Str::uuid(),
            'scope' => MemoryScope::LONG_TERM,
            'agent_id' => $agentId,
            'user_id' => $userId,
            'key' => 'entry1',
            'content' => 'Machine learning algorithms',
            'embedding' => [0.1, 0.9, 0.0],
        ]);

        $entry2 = MemoryEntry::create([
            'id' => (string) Str::uuid(),
            'scope' => MemoryScope::LONG_TERM,
            'agent_id' => $agentId,
            'user_id' => $userId,
            'key' => 'entry2',
            'content' => 'Database optimization',
            'embedding' => [0.9, 0.1, 0.0],
        ]);

        // Search (using fallback since we're on SQLite)
        $results = $this->memoryService->search(
            MemoryScope::LONG_TERM,
            $agentId,
            'algorithms and learning',
            'semantic'
        );

        // Should return entries with similarity_score attribute
        $this->assertNotEmpty($results);
        foreach ($results as $result) {
            $this->assertArrayHasKey('similarity_score', $result->getAttributes());
        }
    }

    // ─── T008: Result ordering by similarity_score ───

    public function test_semantic_search_results_ordered_by_similarity(): void
    {
        $mockProvider = $this->createMock(LlmProvider::class);
        $mockProvider->method('embed')
            ->willReturn(['embeddings' => [[0.8, 0.2, 0.0]]]);

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

        $agentId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        // Create entries with varying similarity to query embedding [0.8, 0.2, 0.0]
        // High similarity: [0.9, 0.1, 0.0]
        MemoryEntry::create([
            'id' => (string) Str::uuid(),
            'scope' => MemoryScope::LONG_TERM,
            'agent_id' => $agentId,
            'user_id' => $userId,
            'key' => 'entry_high',
            'content' => 'High similarity content',
            'embedding' => [0.9, 0.1, 0.0],
        ]);

        // Low similarity: [0.1, 0.9, 0.0]
        MemoryEntry::create([
            'id' => (string) Str::uuid(),
            'scope' => MemoryScope::LONG_TERM,
            'agent_id' => $agentId,
            'user_id' => $userId,
            'key' => 'entry_low',
            'content' => 'Low similarity content',
            'embedding' => [0.1, 0.9, 0.0],
        ]);

        $results = $this->memoryService->search(
            MemoryScope::LONG_TERM,
            $agentId,
            'test query',
            'semantic'
        );

        // Results should be ordered by similarity_score descending
        $scores = array_map(fn($r) => $r->getAttribute('similarity_score'), $results);
        $sortedScores = $scores;
        rsort($sortedScores);
        $this->assertEquals($sortedScores, $scores);
    }

    // ─── T009: Scope restriction on scratch/short_term ───

    public function test_semantic_search_restricted_to_long_term(): void
    {
        $agentId = (string) Str::uuid();

        // Should throw SemanticSearchException for scratch scope
        $this->expectException(SemanticSearchException::class);
        $this->expectExceptionMessageMatches('/long_term.*scope/');

        $this->memoryService->search(
            MemoryScope::SCRATCH,
            $agentId,
            'test query',
            'semantic'
        );
    }

    public function test_semantic_search_restricted_for_short_term(): void
    {
        $agentId = (string) Str::uuid();

        $this->expectException(SemanticSearchException::class);
        $this->expectExceptionMessageMatches('/long_term.*scope/');

        $this->memoryService->search(
            MemoryScope::SHORT_TERM,
            $agentId,
            'test query',
            'semantic'
        );
    }

    // ─── T010: Empty scope returns empty array ───

    public function test_semantic_search_empty_results_for_no_embeddings(): void
    {
        $mockProvider = $this->createMock(LlmProvider::class);
        $mockProvider->method('embed')
            ->willReturn(['embeddings' => [[0.1, 0.2, 0.3]]]);

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

        $agentId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        // Create entries WITHOUT embeddings
        MemoryEntry::create([
            'id' => (string) Str::uuid(),
            'scope' => MemoryScope::LONG_TERM,
            'agent_id' => $agentId,
            'user_id' => $userId,
            'key' => 'no_embedding',
            'content' => 'Content without embedding',
            'embedding' => null,
        ]);

        $results = $this->memoryService->search(
            MemoryScope::LONG_TERM,
            $agentId,
            'test query',
            'semantic'
        );

        // Should return empty array (no entries with embeddings)
        $this->assertEmpty($results);
    }

    // ─── T017: Invalid mode throws InvalidArgumentException ───

    public function test_invalid_search_mode_throws_exception(): void
    {
        $agentId = (string) Str::uuid();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid.*mode/i');

        $this->memoryService->search(
            MemoryScope::LONG_TERM,
            $agentId,
            'test query',
            'invalid_mode'
        );
    }

    // ─── T018: Key prefix/content modes still work ───

    public function test_key_prefix_mode_still_works(): void
    {
        $agentId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        MemoryEntry::create([
            'id' => (string) Str::uuid(),
            'scope' => MemoryScope::LONG_TERM,
            'agent_id' => $agentId,
            'user_id' => $userId,
            'key' => 'test_key_123',
            'content' => 'Some content',
        ]);

        MemoryEntry::create([
            'id' => (string) Str::uuid(),
            'scope' => MemoryScope::LONG_TERM,
            'agent_id' => $agentId,
            'user_id' => $userId,
            'key' => 'other_key_456',
            'content' => 'Other content',
        ]);

        $results = $this->memoryService->search(
            MemoryScope::LONG_TERM,
            $agentId,
            'test_',
            'key_prefix'
        );

        $this->assertCount(1, $results);
        $this->assertEquals('test_key_123', $results[0]->key);
    }

    public function test_content_mode_still_works(): void
    {
        $agentId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        MemoryEntry::create([
            'id' => (string) Str::uuid(),
            'scope' => MemoryScope::LONG_TERM,
            'agent_id' => $agentId,
            'user_id' => $userId,
            'key' => 'key1',
            'content' => 'Content about machine learning',
        ]);

        MemoryEntry::create([
            'id' => (string) Str::uuid(),
            'scope' => MemoryScope::LONG_TERM,
            'agent_id' => $agentId,
            'user_id' => $userId,
            'key' => 'key2',
            'content' => 'Content about databases',
        ]);

        $results = $this->memoryService->search(
            MemoryScope::LONG_TERM,
            $agentId,
            'machine',
            'content'
        );

        $this->assertCount(1, $results);
        $this->assertEquals('key1', $results[0]->key);
    }

    // ─── T019: Threshold filtering (simulated) ───

    public function test_semantic_search_filters_low_similarity(): void
    {
        $mockProvider = $this->createMock(LlmProvider::class);
        // Query embedding that will have negative similarity with [0.1, 0.9, 0.0]
        // Query: [0.9, 0.1, 0.0]
        // Entry: [0.1, 0.9, 0.0] → cosine similarity ≈ 0.18 (positive but low)
        // Entry: [-0.9, -0.1, 0.0] → cosine similarity < 0 (filtered out)
        $mockProvider->method('embed')
            ->willReturn(['embeddings' => [[0.9, 0.1, 0.0]]]);

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

        $agentId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        // Entry with positive similarity
        MemoryEntry::create([
            'id' => (string) Str::uuid(),
            'scope' => MemoryScope::LONG_TERM,
            'agent_id' => $agentId,
            'user_id' => $userId,
            'key' => 'positive',
            'content' => 'Positive similarity',
            'embedding' => [0.8, 0.2, 0.0],
        ]);

        // Entry with near-zero/negative similarity (will be filtered)
        MemoryEntry::create([
            'id' => (string) Str::uuid(),
            'scope' => MemoryScope::LONG_TERM,
            'agent_id' => $agentId,
            'user_id' => $userId,
            'key' => 'negative',
            'content' => 'Negative similarity',
            'embedding' => [-0.8, -0.2, 0.0],
        ]);

        $results = $this->memoryService->search(
            MemoryScope::LONG_TERM,
            $agentId,
            'test',
            'semantic'
        );

        // Negative similarity entries are filtered out by the fallback
        $this->assertNotEmpty($results);
        foreach ($results as $result) {
            $this->assertGreaterThan(0, $result->getAttribute('similarity_score') - 0.5);
        }
    }

    // ─── T023: Provider unavailable throws SemanticSearchException ───

    public function test_semantic_search_provider_unavailable(): void
    {
        // Disable embedding
        config(['llm-client.memory.embedding.enabled' => false]);

        $agentId = (string) Str::uuid();

        $this->expectException(SemanticSearchException::class);
        $this->expectExceptionMessageMatches('/provider.*available/i');

        $this->memoryService->search(
            MemoryScope::LONG_TERM,
            $agentId,
            'test query',
            'semantic'
        );
    }

    public function test_semantic_search_provider_resolution_failure(): void
    {
        // Enable but no server configured
        config(['llm-client.memory.embedding.enabled' => true]);
        config(['llm-client.memory.embedding.server_id' => null]);

        $agentId = (string) Str::uuid();

        $this->expectException(SemanticSearchException::class);
        $this->expectExceptionMessageMatches('/provider.*available/i');

        $this->memoryService->search(
            MemoryScope::LONG_TERM,
            $agentId,
            'test query',
            'semantic'
        );
    }

    // ─── T024: Embedding generation failure during search ───

    public function test_semantic_search_embedding_generation_failure(): void
    {
        $mockProvider = $this->createMock(LlmProvider::class);
        $mockProvider->method('embed')
            ->willThrowException(new RuntimeException('Embedding API error'));

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

        $agentId = (string) Str::uuid();

        $this->expectException(SemanticSearchException::class);
        $this->expectExceptionMessageMatches('/embed/i');

        $this->memoryService->search(
            MemoryScope::LONG_TERM,
            $agentId,
            'test query',
            'semantic'
        );
    }

    // ─── T025: Non-blocking embedding on create ───

    public function test_embedding_generation_on_create_is_non_blocking(): void
    {
        // Even if provider fails, create should succeed
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

        $agentId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        // Create should succeed even with provider failure
        $entry = $this->memoryService->create(
            MemoryScope::LONG_TERM,
            $agentId,
            $userId,
            null,
            null,
            'test_key',
            'test content'
        );

        $this->assertNotNull($entry);
        $this->assertEquals('test content', $entry->content);
        // Embedding will be null due to failure
        $this->assertNull($entry->embedding);
    }

    public function test_embedding_not_generated_for_scratch_scope(): void
    {
        $mockProvider = $this->createMock(LlmProvider::class);
        // Provider should NOT be called for scratch entries
        $mockProvider->expects($this->never())
            ->method('embed');

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

        $agentId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        $entry = $this->memoryService->create(
            MemoryScope::SCRATCH,
            $agentId,
            $userId,
            null,
            '1',
            'scratch_key',
            'scratch content'
        );

        $this->assertNotNull($entry);
        $this->assertNull($entry->embedding);
    }

    public function test_embedding_not_generated_for_short_term_scope(): void
    {
        $mockProvider = $this->createMock(LlmProvider::class);
        $mockProvider->expects($this->never())
            ->method('embed');

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

        $agentId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        $entry = $this->memoryService->create(
            MemoryScope::SHORT_TERM,
            $agentId,
            $userId,
            null,
            null,
            'short_key',
            'short term content'
        );

        $this->assertNotNull($entry);
        $this->assertNull($entry->embedding);
    }
}
