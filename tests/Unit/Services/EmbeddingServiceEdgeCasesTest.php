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

class EmbeddingServiceEdgeCasesTest extends TestCase
{
    protected EmbeddingService $service;
    protected ProviderRegistry $registry;

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
        $this->service = new EmbeddingService($this->registry);
    }

    protected function setupProvider(): void
    {
        $mockProvider = $this->createMock(LlmProvider::class);
        $mockProvider->method('embed')
            ->willReturnCallback(function (array $inputs) {
                return ['embeddings' => [[0.1, 0.2, 0.3]]];
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
    }

    // ─── T030: Edge Cases ───

    public function test_content_truncation_for_very_long_text(): void
    {
        $mockProvider = $this->createMock(LlmProvider::class);
        $mockProvider->expects($this->once())
            ->method('embed')
            ->willReturnCallback(function (array $inputs) {
                // Verify content was truncated to max 8000 chars
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

        // Create very long content (~20,000 chars)
        $longContent = str_repeat('word ', 4000);
        $embedding = $this->service->generate($longContent);

        $this->assertCount(2, $embedding); // Mock returns [0.1, 0.2]
    }

    public function test_single_word_embedding(): void
    {
        $this->setupProvider();

        // Single word should still generate an embedding
        $embedding = $this->service->generate('hello');
        $this->assertIsArray($embedding);
        $this->assertNotEmpty($embedding);
    }

    public function test_empty_string_embedding(): void
    {
        $this->setupProvider();

        // Empty string should still generate (provider may handle it)
        $embedding = $this->service->generate('');
        $this->assertIsArray($embedding);
    }

    public function test_duplicate_content_gets_own_embedding_per_key(): void
    {
        $this->setupProvider();

        $agentId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        // Create two entries with same content but different keys
        $entry1 = MemoryEntry::create([
            'id' => (string) Str::uuid(),
            'scope' => MemoryScope::LONG_TERM,
            'agent_id' => $agentId,
            'user_id' => $userId,
            'key' => 'key1',
            'content' => 'Same content',
        ]);

        $entry2 = MemoryEntry::create([
            'id' => (string) Str::uuid(),
            'scope' => MemoryScope::LONG_TERM,
            'agent_id' => $agentId,
            'user_id' => $userId,
            'key' => 'key2',
            'content' => 'Same content',
        ]);

        // Both should get embeddings generated
        $result1 = $this->service->generateForEntry($entry1);
        $result2 = $this->service->generateForEntry($entry2);

        $this->assertTrue($result1);
        $this->assertTrue($result2);

        $entry1->refresh();
        $entry2->refresh();

        // Both should have embeddings (may be same or different depending on provider)
        $this->assertNotNull($entry1->embedding);
        $this->assertNotNull($entry2->embedding);
    }

    public function test_special_characters_in_content(): void
    {
        $this->setupProvider();

        // Content with special characters, unicode, etc.
        $specialContent = "Hello 世界! 🎉 Test with émojis and ünïcödé chars <script>alert('xss')</script>";
        $embedding = $this->service->generate($specialContent);

        $this->assertIsArray($embedding);
        $this->assertNotEmpty($embedding);
    }

    public function test_whitespace_only_content(): void
    {
        $this->setupProvider();

        $embedding = $this->service->generate('   ');
        $this->assertIsArray($embedding);
    }

    public function test_newlines_and_tabs_in_content(): void
    {
        $this->setupProvider();

        $multilineContent = "Line 1\nLine 2\n\nLine 4\twith tab";
        $embedding = $this->service->generate($multilineContent);

        $this->assertIsArray($embedding);
        $this->assertNotEmpty($embedding);
    }

    public function test_cosine_similarity_with_float_precision(): void
    {
        // Test with very small floating point differences
        $a = [0.33333333, 0.33333334, 0.33333333];
        $b = [0.33333333, 0.33333333, 0.33333334];

        $similarity = EmbeddingService::cosineSimilarity($a, $b);

        // Should be very close to 1.0 (nearly identical vectors)
        $this->assertGreaterThan(0.999, $similarity);
        $this->assertLessThanOrEqual(1.001, $similarity);
    }

    public function test_cosine_similarity_with_large_values(): void
    {
        $a = [1000.0, 2000.0, 3000.0];
        $b = [1000.0, 2000.0, 3000.0];

        $similarity = EmbeddingService::cosineSimilarity($a, $b);

        // Identical vectors should have similarity very close to 1.0 (IEEE 754 precision)
        $this->assertEqualsWithDelta(1.0, $similarity, 0.0001);
    }

    public function test_normalize_similarity_clamps_to_range(): void
    {
        // Values within [-1, 1] should map to [0, 1]
        $this->assertEquals(0.0, EmbeddingService::normalizeSimilarity(-1.0));
        $this->assertEquals(0.5, EmbeddingService::normalizeSimilarity(0.0));
        $this->assertEquals(1.0, EmbeddingService::normalizeSimilarity(1.0));

        // Edge cases
        $this->assertEquals(0.25, EmbeddingService::normalizeSimilarity(-0.5));
        $this->assertEquals(0.75, EmbeddingService::normalizeSimilarity(0.5));
    }
}
