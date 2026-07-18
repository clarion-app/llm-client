<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Contracts\DeclarativeMemoryService as DeclarativeMemoryServiceContract;
use ClarionApp\LlmClient\Contracts\MemoryScope;
use ClarionApp\LlmClient\Contracts\MemoryService as MemoryServiceContract;
use ClarionApp\LlmClient\Exceptions\SemanticSearchException;
use ClarionApp\LlmClient\Models\DeclarativeMemory;
use ClarionApp\LlmClient\Services\AutoMemoryRetriever;
use ClarionApp\LlmClient\Services\EpisodicMemorySearchService;
use ClarionApp\LlmClient\Services\EmbeddingService;
use ClarionApp\LlmClient\Services\PreferenceInjector;
use ClarionApp\LlmClient\ValueObjects\MemoryInjectionSection;
use ClarionApp\LlmClient\ValueObjects\MemoryRetrievalResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;

use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for AutoMemoryRetriever.
 *
 * Covers all retrieval paths, graceful degradation, budget enforcement,
 * per-turn memoization, and the single-embedding guarantee (SC-008).
 */
class AutoMemoryRetrieverTest extends TestCase
{
    private AutoMemoryRetriever $retriever;
    private Mockery\MockInterface $declarativeMemoryService;
    private Mockery\MockInterface $episodicMemorySearchService;
    private Mockery\MockInterface $memoryService;
    private Mockery\MockInterface $embeddingService;
    private Mockery\MockInterface $preferenceInjector;

    protected function setUp(): void
    {
        parent::setUp();

        // Create declarative_memories table (retriever queries model directly)
        if (!\Illuminate\Support\Facades\Schema::hasTable('declarative_memories')) {
            \Illuminate\Support\Facades\Schema::create('declarative_memories', function ($table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->string('type');
                $table->text('content');
                $table->string('source');
                $table->integer('confidence_level')->nullable();
                $table->json('embedding')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index('user_id');
                $table->index(['user_id', 'type']);
                $table->index('deleted_at');
            });
        }

        // Create mock dependencies
        $this->declarativeMemoryService = Mockery::mock(DeclarativeMemoryServiceContract::class);
        $this->episodicMemorySearchService = Mockery::mock(EpisodicMemorySearchService::class);
        $this->memoryService = Mockery::mock(MemoryServiceContract::class);
        $this->embeddingService = Mockery::mock(EmbeddingService::class);
        $this->preferenceInjector = Mockery::mock(PreferenceInjector::class);

        // Default config
        config([
            'llm-client.auto_memory_retrieval.enabled' => true,
            'llm-client.auto_memory_retrieval.max_tokens' => 1000,
            'llm-client.auto_memory_retrieval.relevance_threshold' => 0.3,
            'llm-client.auto_memory_retrieval.max_results_per_store' => 5,
            'llm-client.auto_memory_retrieval.stores' => ['declarative', 'episodic', 'long-term'],
        ]);

        $this->retriever = new AutoMemoryRetriever(
            $this->declarativeMemoryService,
            $this->episodicMemorySearchService,
            $this->memoryService,
            $this->embeddingService,
            $this->preferenceInjector,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /* isEnabled() tests                                                    */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function isEnabled_returns_true_when_configured(): void
    {
        config(['llm-client.auto_memory_retrieval.enabled' => true]);
        $this->assertTrue($this->retriever->isEnabled());
    }

    #[Test]
    public function isEnabled_returns_false_when_disabled(): void
    {
        config(['llm-client.auto_memory_retrieval.enabled' => false]);
        $this->assertFalse($this->retriever->isEnabled());
    }

    /* ------------------------------------------------------------------ */
    /* SC-008: Single embedding call guarantee                              */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function sc008_single_embedding_call_across_all_stores(): void
    {
        // Embedding service: enabled, returns one embedding
        $embedding = [0.1, 0.2, 0.3];
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(true);
        $this->embeddingService->shouldReceive('generate')
            ->once()
            ->with('test query')
            ->andReturn($embedding);

        // Episodic: return empty results
        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->once()
            ->andReturn([]);

        // Long-term: return empty results
        $this->memoryService->shouldReceive('search')
            ->once()
            ->andReturn([]);

        $result = $this->retriever->retrieve('conv-1:msg-1', 'user-1', 'agent-1', 'test query');

        // Result is valid (may be empty, but no exception)
        $this->assertInstanceOf(MemoryRetrievalResult::class, $result);
    }

    /* ------------------------------------------------------------------ */
    /* T011b: Per-turn memoization                                          */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function t011b_same_turnKey_returns_cached_result_without_io(): void
    {
        $embedding = [0.1, 0.2, 0.3];
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(true);
        $this->embeddingService->shouldReceive('generate')
            ->once()
            ->andReturn($embedding);

        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->once()
            ->andReturn([]);

        $this->memoryService->shouldReceive('search')
            ->once()
            ->andReturn([]);

        // First call performs I/O
        $result1 = $this->retriever->retrieve('conv-1:msg-1', 'user-1', 'agent-1', 'test query');

        // Second call with same turnKey should NOT trigger I/O
        // (no more mock expectations set — will fail if I/O occurs)
        $result2 = $this->retriever->retrieve('conv-1:msg-1', 'user-1', 'agent-1', 'different query');

        // Both results are the same cached object
        $this->assertSame($result1, $result2);
    }

    #[Test]
    public function t011b_different_turnKey_triggers_fresh_retrieval(): void
    {
        $embedding = [0.1, 0.2, 0.3];
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(true);
        $this->embeddingService->shouldReceive('generate')
            ->twice()
            ->andReturn($embedding);

        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->twice()
            ->andReturn([]);

        $this->memoryService->shouldReceive('search')
            ->twice()
            ->andReturn([]);

        $result1 = $this->retriever->retrieve('conv-1:msg-1', 'user-1', 'agent-1', 'query one');
        $result2 = $this->retriever->retrieve('conv-1:msg-2', 'user-1', 'agent-1', 'query two');

        // Different turnKeys produce different results
        $this->assertNotSame($result1, $result2);
    }

    /* ------------------------------------------------------------------ */
    /* SC-007 / FR-011: Rules survive embedding failure                     */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function sc007_rules_still_injected_when_embedding_fails(): void
    {
        // Embedding service throws RuntimeException
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(true);
        $this->embeddingService->shouldReceive('generate')
            ->once()
            ->andThrow(new \RuntimeException('Embedding provider unavailable'));

        // No embedding means declarative fallback to PreferenceInjector
        $this->preferenceInjector->shouldReceive('assemble')->andReturn(null);

        // Create a rule in the database (queried directly by retrieveDeclarative)
        $user = \ClarionApp\Backend\Models\User::factory()->create();
        DeclarativeMemory::withoutGlobalScope('user')->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'type' => 'rule',
            'content' => 'Always be concise',
            'source' => 'user_stated',
            'embedding' => null,
        ]);

        // Episodic and long-term should still be attempted (with null embedding)
        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->once()
            ->andReturn([]);

        $this->memoryService->shouldReceive('search')
            ->once()
            ->andReturn([]);

        $result = $this->retriever->retrieve('conv-1:msg-1', $user->id, 'agent-1', 'test query');

        // Rules are present despite embedding failure
        $ruleHits = $result->hitsByType('rule');
        $this->assertCount(1, $ruleHits);
        $this->assertEquals('Always be concise', $ruleHits[0]->content);

        // Degradation event is recorded
        $this->assertCount(1, $result->degradationEvents);
        $this->assertStringContainsString('embedding_generation_failed', $result->degradationEvents[0]);
    }

    /* ------------------------------------------------------------------ */
    /* Retrieval with no results                                            */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function retrieval_with_no_results_returns_empty_result(): void
    {
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(false);
        $this->preferenceInjector->shouldReceive('assemble')->andReturn(null);

        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->once()
            ->andReturn([]);

        $this->memoryService->shouldReceive('search')
            ->once()
            ->andReturn([]);

        $user = \ClarionApp\Backend\Models\User::factory()->create();
        $result = $this->retriever->retrieve('conv-1:msg-1', $user->id, 'agent-1', 'test query');

        $this->assertTrue($result->isEmpty());
        $this->assertEmpty($result->hits);
    }

    /* ------------------------------------------------------------------ */
    /* Declarative retrieval (rules unconditional, facts scored)            */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function declarative_retrieval_includes_rules_unconditionally(): void
    {
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(false);
        $this->preferenceInjector->shouldReceive('assemble')->andReturn(null);

        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->once()
            ->andReturn([]);

        $this->memoryService->shouldReceive('search')
            ->once()
            ->andReturn([]);

        $user = \ClarionApp\Backend\Models\User::factory()->create();
        DeclarativeMemory::withoutGlobalScope('user')->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'type' => 'rule',
            'content' => 'Always respond in French',
            'source' => 'user_stated',
            'embedding' => null,
        ]);

        $result = $this->retriever->retrieve('conv-1:msg-1', $user->id, 'agent-1', 'hello');

        $ruleHits = $result->hitsByType('rule');
        $this->assertCount(1, $ruleHits);
        $this->assertEquals('Always respond in French', $ruleHits[0]->content);
        $this->assertEquals(1.0, $ruleHits[0]->score);
    }

    #[Test]
    public function declarative_retrieval_scores_facts_with_embedding(): void
    {
        $embedding = [1.0, 0.0, 0.0];
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(true);
        $this->embeddingService->shouldReceive('generate')
            ->once()
            ->andReturn($embedding);

        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->once()
            ->andReturn([]);

        $this->memoryService->shouldReceive('search')
            ->once()
            ->andReturn([]);

        $user = \ClarionApp\Backend\Models\User::factory()->create();
        // Fact with embedding that matches query embedding (cosine = 1.0)
        DeclarativeMemory::withoutGlobalScope('user')->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'type' => 'fact',
            'content' => 'I prefer dark mode',
            'source' => 'user_stated',
            'embedding' => [1.0, 0.0, 0.0],
        ]);

        $result = $this->retriever->retrieve('conv-1:msg-1', $user->id, 'agent-1', 'preferences');

        $factHits = $result->hitsByType('fact');
        $this->assertCount(1, $factHits);
        // Cosine(1,0,0 vs 1,0,0) = 1.0, normalized = (1.0 + 1.0) / 2.0 = 1.0
        $this->assertEquals(1.0, $factHits[0]->score);
    }

    #[Test]
    public function declarative_retrieval_filters_facts_below_threshold(): void
    {
        $embedding = [1.0, 0.0, 0.0];
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(true);
        $this->embeddingService->shouldReceive('generate')
            ->once()
            ->andReturn($embedding);

        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->once()
            ->andReturn([]);

        $this->memoryService->shouldReceive('search')
            ->once()
            ->andReturn([]);

        // Set high threshold
        config(['llm-client.auto_memory_retrieval.relevance_threshold' => 0.9]);

        $user = \ClarionApp\Backend\Models\User::factory()->create();
        // Fact with orthogonal embedding (cosine = 0.0, normalized = 0.5)
        DeclarativeMemory::withoutGlobalScope('user')->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'type' => 'fact',
            'content' => 'I like pizza',
            'source' => 'user_stated',
            'embedding' => [0.0, 1.0, 0.0],
        ]);

        $result = $this->retriever->retrieve('conv-1:msg-1', $user->id, 'agent-1', 'test');

        $factHits = $result->hitsByType('fact');
        $this->assertCount(0, $factHits);
    }

    #[Test]
    public function declarative_retrieval_fallback_to_preferenceInjector_when_no_embedding(): void
    {
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(false);

        // PreferenceInjector returns formatted text
        $this->preferenceInjector->shouldReceive('assemble')
            ->once()
            ->andReturn("- I prefer concise responses\n- I like bullet points");

        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->once()
            ->andReturn([]);

        $this->memoryService->shouldReceive('search')
            ->once()
            ->andReturn([]);

        $user = \ClarionApp\Backend\Models\User::factory()->create();
        $result = $this->retriever->retrieve('conv-1:msg-1', $user->id, 'agent-1', 'test');

        // Fallback hits from PreferenceInjector
        $prefHits = $result->hitsByType('preference');
        $this->assertCount(2, $prefHits);
    }

    /* ------------------------------------------------------------------ */
    /* Episodic retrieval with embedding                                    */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function episodic_retrieval_with_embedding(): void
    {
        $embedding = [0.1, 0.2, 0.3];
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(true);
        $this->embeddingService->shouldReceive('generate')
            ->once()
            ->andReturn($embedding);

        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->once()
            ->andReturn([
                [
                    'id' => 'ep-1',
                    'summary' => 'User discussed deployment strategies',
                    'conversation_id' => 'conv-5',
                    'topics' => ['deployment', 'kubernetes'],
                    'similarity_score' => 0.85,
                ],
            ]);

        $this->memoryService->shouldReceive('search')
            ->once()
            ->andReturn([]);

        $result = $this->retriever->retrieve('conv-1:msg-1', 'user-1', 'agent-1', 'deployment');

        $episodicHits = $result->hitsBySource('episodic');
        $this->assertCount(1, $episodicHits);
        $this->assertEquals('User discussed deployment strategies', $episodicHits[0]->content);
        $this->assertEquals(0.85, $episodicHits[0]->score);
    }

    #[Test]
    public function episodic_retrieval_filters_by_threshold(): void
    {
        $embedding = [0.1, 0.2, 0.3];
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(true);
        $this->embeddingService->shouldReceive('generate')
            ->once()
            ->andReturn($embedding);

        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->once()
            ->andReturn([
                [
                    'id' => 'ep-1',
                    'summary' => 'Relevant content',
                    'similarity_score' => 0.85,
                ],
                [
                    'id' => 'ep-2',
                    'summary' => 'Irrelevant content',
                    'similarity_score' => 0.1,
                ],
            ]);

        $this->memoryService->shouldReceive('search')
            ->once()
            ->andReturn([]);

        $result = $this->retriever->retrieve('conv-1:msg-1', 'user-1', 'agent-1', 'test');

        $episodicHits = $result->hitsBySource('episodic');
        $this->assertCount(1, $episodicHits);
        $this->assertEquals('Relevant content', $episodicHits[0]->content);
    }

    /* ------------------------------------------------------------------ */
    /* Long-term retrieval with embedding                                   */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function longTerm_retrieval_with_embedding(): void
    {
        $embedding = [0.1, 0.2, 0.3];
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(true);
        $this->embeddingService->shouldReceive('generate')
            ->once()
            ->andReturn($embedding);

        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->once()
            ->andReturn([]);

        // Mock MemoryEntry-like objects
        $entry = Mockery::mock();
        $entry->id = 'lt-1';
        $entry->content = 'User prefers dark theme';
        $entry->key = 'theme_preference';
        $entry->last_accessed_at = null;
        $entry->shouldReceive('getAttribute')
            ->with('similarity_score', 0.5)
            ->andReturn(0.92);

        $this->memoryService->shouldReceive('search')
            ->once()
            ->andReturn([$entry]);

        $result = $this->retriever->retrieve('conv-1:msg-1', 'user-1', 'agent-1', 'theme');

        $longTermHits = $result->hitsBySource('long-term');
        $this->assertCount(1, $longTermHits);
        $this->assertEquals('User prefers dark theme', $longTermHits[0]->content);
        $this->assertEquals(0.92, $longTermHits[0]->score);
    }

    /* ------------------------------------------------------------------ */
    /* Score normalization and threshold filtering                          */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function score_normalization_filters_below_threshold(): void
    {
        // Set threshold to 0.5
        config(['llm-client.auto_memory_retrieval.relevance_threshold' => 0.5]);

        $embedding = [1.0, 0.0, 0.0];
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(true);
        $this->embeddingService->shouldReceive('generate')
            ->once()
            ->andReturn($embedding);

        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->once()
            ->andReturn([]);

        $this->memoryService->shouldReceive('search')
            ->once()
            ->andReturn([]);

        $user = \ClarionApp\Backend\Models\User::factory()->create();
        // Orthogonal vector: cosine = 0.0, normalized = 0.5 (exactly at threshold)
        DeclarativeMemory::withoutGlobalScope('user')->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'type' => 'fact',
            'content' => 'Borderline fact',
            'source' => 'user_stated',
            'embedding' => [0.0, 1.0, 0.0],
        ]);
        // Opposite vector: cosine = -1.0, normalized = 0.0 (below threshold)
        DeclarativeMemory::withoutGlobalScope('user')->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'type' => 'fact',
            'content' => 'Irrelevant fact',
            'source' => 'user_stated',
            'embedding' => [-1.0, 0.0, 0.0],
        ]);

        $result = $this->retriever->retrieve('conv-1:msg-1', $user->id, 'agent-1', 'test');

        // Only the borderline fact (score 0.5) passes threshold of 0.5
        $factHits = $result->hitsByType('fact');
        $this->assertCount(1, $factHits);
        $this->assertEquals('Borderline fact', $factHits[0]->content);
    }

    /* ------------------------------------------------------------------ */
    /* Token budget enforcement                                             */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function tokenBudget_enforcement_drops_low_priority_entries(): void
    {
        $embedding = [0.1, 0.2, 0.3];
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(true);
        $this->embeddingService->shouldReceive('generate')
            ->once()
            ->andReturn($embedding);

        // Small budget (30 tokens = 120 chars)
        config(['llm-client.auto_memory_retrieval.max_tokens' => 30]);

        // Episodic returns a hit that will compete for budget
        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->once()
            ->andReturn([
                ['id' => 'ep-1', 'summary' => str_repeat('X', 200), 'similarity_score' => 0.9],
            ]);

        // Long-term store is skipped by the pre-stage budget gate because
        // the episodic hit (200 chars) already exceeds the budget (120 chars).
        $this->memoryService->shouldReceive('search')
            ->never();

        $user = \ClarionApp\Backend\Models\User::factory()->create();
        // Create a short rule (always kept) and a long episodic hit
        DeclarativeMemory::withoutGlobalScope('user')->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'type' => 'rule',
            'content' => 'Be concise',
            'source' => 'user_stated',
            'embedding' => null,
        ]);

        $result = $this->retriever->retrieve('conv-1:msg-1', $user->id, 'agent-1', 'test');

        // Rule should still be present (never truncated)
        $ruleHits = $result->hitsByType('rule');
        $this->assertCount(1, $ruleHits);
        // Episodic hit should be dropped (budget overflow)
        $episodicHits = $result->hitsBySource('episodic');
        $this->assertCount(0, $episodicHits);
    }

    /* ------------------------------------------------------------------ */
    /* Graceful degradation per store                                       */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function gracefulDegradation_embeddingService_runtimeException(): void
    {
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(true);
        $this->embeddingService->shouldReceive('generate')
            ->once()
            ->andThrow(new \RuntimeException('Network timeout'));

        // No embedding means declarative fallback to PreferenceInjector
        $this->preferenceInjector->shouldReceive('assemble')->andReturn(null);

        // Stores still called with null embedding
        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->once()
            ->andReturn([]);

        $this->memoryService->shouldReceive('search')
            ->once()
            ->andReturn([]);

        $result = $this->retriever->retrieve('conv-1:msg-1', 'user-1', 'agent-1', 'test');

        $this->assertStringContainsString('embedding_generation_failed', $result->degradationEvents[0]);
    }

    #[Test]
    public function gracefulDegradation_episodic_invalidArgumentException(): void
    {
        $embedding = [0.1, 0.2, 0.3];
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(true);
        $this->embeddingService->shouldReceive('generate')
            ->once()
            ->andReturn($embedding);

        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->once()
            ->andThrow(new \InvalidArgumentException('No embeddings available'));

        $this->memoryService->shouldReceive('search')
            ->once()
            ->andReturn([]);

        $result = $this->retriever->retrieve('conv-1:msg-1', 'user-1', 'agent-1', 'test');

        $this->assertStringContainsString('episodic_retrieval_failed', $result->degradationEvents[0]);
        // No exception propagated — result is still valid
        $this->assertInstanceOf(MemoryRetrievalResult::class, $result);
    }

    #[Test]
    public function gracefulDegradation_longTerm_semanticSearchException(): void
    {
        $embedding = [0.1, 0.2, 0.3];
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(true);
        $this->embeddingService->shouldReceive('generate')
            ->once()
            ->andReturn($embedding);

        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->once()
            ->andReturn([]);

        $this->memoryService->shouldReceive('search')
            ->once()
            ->andThrow(new SemanticSearchException('embedding_provider_unavailable', 'No embedding provider', 'Configure an embedding server'));

        $result = $this->retriever->retrieve('conv-1:msg-1', 'user-1', 'agent-1', 'test');

        $this->assertStringContainsString('long_term_retrieval_failed', $result->degradationEvents[0]);
        $this->assertInstanceOf(MemoryRetrievalResult::class, $result);
    }

    #[Test]
    public function gracefulDegradation_allStores_fail(): void
    {
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(true);
        $this->embeddingService->shouldReceive('generate')
            ->once()
            ->andThrow(new \RuntimeException('Provider down'));

        // No embedding means declarative fallback to PreferenceInjector
        $this->preferenceInjector->shouldReceive('assemble')->andReturn(null);

        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->once()
            ->andThrow(new \InvalidArgumentException('Disabled'));

        $this->memoryService->shouldReceive('search')
            ->once()
            ->andThrow(new SemanticSearchException('embedding_provider_unavailable'));

        $result = $this->retriever->retrieve('conv-1:msg-1', 'user-1', 'agent-1', 'test');

        // Three degradation events recorded
        $this->assertCount(3, $result->degradationEvents);
        $this->assertStringContainsString('embedding_generation_failed', $result->degradationEvents[0]);
        $this->assertStringContainsString('episodic_retrieval_failed', $result->degradationEvents[1]);
        $this->assertStringContainsString('long_term_retrieval_failed', $result->degradationEvents[2]);
    }

    /* ------------------------------------------------------------------ */
    /* Pre-stage budget gates                                               */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function preStageBudgetGate_skips_store_when_budget_exhausted(): void
    {
        $embedding = [0.1, 0.2, 0.3];
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(true);
        $this->embeddingService->shouldReceive('generate')
            ->once()
            ->andReturn($embedding);

        // Tiny budget (10 tokens = 40 chars)
        config(['llm-client.auto_memory_retrieval.max_tokens' => 10]);

        $user = \ClarionApp\Backend\Models\User::factory()->create();
        // Create a rule that fills the budget
        DeclarativeMemory::withoutGlobalScope('user')->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'type' => 'rule',
            'content' => str_repeat('X', 100), // 100 chars > 40 char budget
            'source' => 'user_stated',
            'embedding' => null,
        ]);

        // Episodic should NOT be called because budget is exhausted
        // The pre-stage gate: getUsedChars >= maxChars AND count(result->hits) > 0
        // 100 chars >= 40 chars AND hits count > 0 => skip episodic
        $this->episodicMemorySearchService->shouldNotReceive('hybridSearch');

        // Long-term should also be skipped
        $this->memoryService->shouldNotReceive('search');

        $result = $this->retriever->retrieve('conv-1:msg-1', $user->id, 'agent-1', 'test');

        // Only the rule should be present (rules are never truncated by budget)
        $ruleHits = $result->hitsByType('rule');
        $this->assertCount(1, $ruleHits);
    }

    /* ------------------------------------------------------------------ */
    /* Sort by priority then score                                          */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function sortByPriority_thenScore(): void
    {
        $embedding = [0.1, 0.2, 0.3];
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(true);
        $this->embeddingService->shouldReceive('generate')
            ->once()
            ->andReturn($embedding);

        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->once()
            ->andReturn([
                ['id' => 'ep-1', 'summary' => 'Episodic low score', 'similarity_score' => 0.4],
                ['id' => 'ep-2', 'summary' => 'Episodic high score', 'similarity_score' => 0.9],
            ]);

        // Mock long-term entry
        $ltEntry = Mockery::mock();
        $ltEntry->id = 'lt-1';
        $ltEntry->content = 'Long-term memory';
        $ltEntry->key = 'some_key';
        $ltEntry->last_accessed_at = null;
        $ltEntry->shouldReceive('getAttribute')
            ->with('similarity_score', 0.5)
            ->andReturn(0.7);

        $this->memoryService->shouldReceive('search')
            ->once()
            ->andReturn([$ltEntry]);

        $user = \ClarionApp\Backend\Models\User::factory()->create();
        // Add a rule (highest priority)
        DeclarativeMemory::withoutGlobalScope('user')->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'type' => 'rule',
            'content' => 'Rule content',
            'source' => 'user_stated',
            'embedding' => null,
        ]);

        $result = $this->retriever->retrieve('conv-1:msg-1', $user->id, 'agent-1', 'test');

        // Priority order: rule (0) > episodic (3) > long-term (4)
        // Within same priority, sort by score descending
        $types = array_map(fn ($h) => $h->type, $result->hits);
        $this->assertEquals('rule', $types[0]);

        // Episodic hits should be sorted by score descending
        $episodicTypes = array_values(array_filter($types, fn ($t) => $t === 'episodic'));
        $this->assertEquals(2, count($episodicTypes));
    }

    /* ------------------------------------------------------------------ */
    /* Store enable/disable config                                          */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function storeFiltering_skips_disabled_stores(): void
    {
        $embedding = [0.1, 0.2, 0.3];
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(true);
        $this->embeddingService->shouldReceive('generate')
            ->once()
            ->andReturn($embedding);

        // Only declarative store enabled
        config(['llm-client.auto_memory_retrieval.stores' => ['declarative']]);

        // Episodic and long-term should NOT be called
        $this->episodicMemorySearchService->shouldNotReceive('hybridSearch');
        $this->memoryService->shouldNotReceive('search');

        $user = \ClarionApp\Backend\Models\User::factory()->create();
        $result = $this->retriever->retrieve('conv-1:msg-1', $user->id, 'agent-1', 'test');

        $this->assertInstanceOf(MemoryRetrievalResult::class, $result);
    }

    /* ------------------------------------------------------------------ */
    /* formatInjectionSection tests                                         */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function formatInjectionSection_returns_section_with_hits(): void
    {
        $result = new MemoryRetrievalResult();
        $result->addHit(\ClarionApp\LlmClient\ValueObjects\MemoryHit::fromRule('r1', 'Be kind'));

        $section = $this->retriever->formatInjectionSection($result);

        $this->assertInstanceOf(MemoryInjectionSection::class, $section);
        $this->assertStringContainsString('Retrieved Memory Context', $section->rawText);
        $this->assertStringContainsString('Be kind', $section->rawText);
        $this->assertEquals(1, $section->hitCount);
    }

    #[Test]
    public function formatInjectionSection_returns_empty_for_no_hits(): void
    {
        $result = new MemoryRetrievalResult();
        $section = $this->retriever->formatInjectionSection($result);

        $this->assertInstanceOf(MemoryInjectionSection::class, $section);
        $this->assertTrue($section->isEmpty());
        $this->assertEquals('', $section->rawText);
    }

    /* ------------------------------------------------------------------ */
    /* retrieveWithMetrics tests                                            */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function retrieveWithMetrics_returns_result(): void
    {
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(false);
        $this->preferenceInjector->shouldReceive('assemble')->andReturn(null);

        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->once()
            ->andReturn([]);

        $this->memoryService->shouldReceive('search')
            ->once()
            ->andReturn([]);

        $result = $this->retriever->retrieveWithMetrics('conv-1:msg-1', 'user-1', 'agent-1', 'test');

        $this->assertInstanceOf(MemoryRetrievalResult::class, $result);
    }

    #[Test]
    public function retrieveWithMetrics_cacheHit_skips_metrics(): void
    {
        $embedding = [0.1, 0.2, 0.3];
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(true);
        $this->embeddingService->shouldReceive('generate')
            ->once()
            ->andReturn($embedding);

        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->once()
            ->andReturn([]);

        $this->memoryService->shouldReceive('search')
            ->once()
            ->andReturn([]);

        // First call (cache miss)
        $result1 = $this->retriever->retrieveWithMetrics('conv-1:msg-1', 'user-1', 'agent-1', 'test');

        // Second call (cache hit) — no more mock expectations
        $result2 = $this->retriever->retrieveWithMetrics('conv-1:msg-1', 'user-1', 'agent-1', 'test');

        $this->assertSame($result1, $result2);
    }

    /* ------------------------------------------------------------------ */
    /* Retrieval time tracking                                              */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function retrievalTime_is_set(): void
    {
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(false);
        $this->preferenceInjector->shouldReceive('assemble')->andReturn(null);

        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->once()
            ->andReturn([]);

        $this->memoryService->shouldReceive('search')
            ->once()
            ->andReturn([]);

        $user = \ClarionApp\Backend\Models\User::factory()->create();
        $result = $this->retriever->retrieve('conv-1:msg-1', $user->id, 'agent-1', 'test');

        $this->assertGreaterThan(0.0, $result->retrievalTime);
    }

    /* ------------------------------------------------------------------ */
    /* Max results per store enforcement                                    */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function maxResultsPerStore_limits_facts(): void
    {
        // Limit to 2 facts per store
        config(['llm-client.auto_memory_retrieval.max_results_per_store' => 2]);

        $embedding = [1.0, 0.0, 0.0];
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(true);
        $this->embeddingService->shouldReceive('generate')
            ->once()
            ->andReturn($embedding);

        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->once()
            ->andReturn([]);

        $this->memoryService->shouldReceive('search')
            ->once()
            ->andReturn([]);

        $user = \ClarionApp\Backend\Models\User::factory()->create();
        // Create 5 facts with same embedding (all score 1.0)
        for ($i = 0; $i < 5; $i++) {
            DeclarativeMemory::withoutGlobalScope('user')->create([
                'id' => Str::uuid()->toString(),
                'user_id' => $user->id,
                'type' => 'fact',
                'content' => "Fact number {$i}",
                'source' => 'user_stated',
                'embedding' => [1.0, 0.0, 0.0],
            ]);
        }

        $result = $this->retriever->retrieve('conv-1:msg-1', $user->id, 'agent-1', 'test');

        // Only 2 facts should be included (max_results_per_store = 2)
        $factHits = $result->hitsByType('fact');
        $this->assertCount(2, $factHits);
    }

    #[Test]
    public function maxResultsPerStore_does_not_limit_rules(): void
    {
        // Limit to 2 per store
        config(['llm-client.auto_memory_retrieval.max_results_per_store' => 2]);

        $this->embeddingService->shouldReceive('isEnabled')->andReturn(false);
        $this->preferenceInjector->shouldReceive('assemble')->andReturn(null);

        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->once()
            ->andReturn([]);

        $this->memoryService->shouldReceive('search')
            ->once()
            ->andReturn([]);

        $user = \ClarionApp\Backend\Models\User::factory()->create();
        // Create 5 rules (rules are exempt from per-store cap)
        for ($i = 0; $i < 5; $i++) {
            DeclarativeMemory::withoutGlobalScope('user')->create([
                'id' => Str::uuid()->toString(),
                'user_id' => $user->id,
                'type' => 'rule',
                'content' => "Rule number {$i}",
                'source' => 'user_stated',
                'embedding' => null,
            ]);
        }

        $result = $this->retriever->retrieve('conv-1:msg-1', $user->id, 'agent-1', 'test');

        // All 5 rules should be present (rules are exempt)
        $ruleHits = $result->hitsByType('rule');
        $this->assertCount(5, $ruleHits);
    }

    /* ------------------------------------------------------------------ */
    /* T033: Scope enforcement and store filtering                          */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function t033_scopeEnforcement_longTermScope_used_for_memoryService(): void
    {
        $embedding = [0.1, 0.2, 0.3];
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(true);
        $this->embeddingService->shouldReceive('generate')
            ->once()
            ->andReturn($embedding);

        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->once()
            ->andReturn([]);

        // Assert MemoryScope::LONG_TERM is passed as the first argument
        $this->memoryService->shouldReceive('search')
            ->once()
            ->withArgs(function ($scope, $agentId, $query, $mode, $limit, $minScore, $queryEmbedding) {
                if ($scope !== MemoryScope::LONG_TERM) {
                    $this->fail(sprintf(
                        'Expected MemoryScope::LONG_TERM but got %s',
                        is_object($scope) ? $scope->value : $scope
                    ));
                }
                return true;
            })
            ->andReturn([]);

        $result = $this->retriever->retrieve('conv-1:msg-1', 'user-1', 'agent-1', 'test query');

        $this->assertInstanceOf(MemoryRetrievalResult::class, $result);
    }

    #[Test]
    public function t033_storeFiltering_onlyDeclarative_episodicAndLongTermNeverCalled(): void
    {
        $embedding = [0.1, 0.2, 0.3];
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(true);
        $this->embeddingService->shouldReceive('generate')
            ->once()
            ->andReturn($embedding);

        // Only declarative store enabled
        config(['llm-client.auto_memory_retrieval.stores' => ['declarative']]);

        // Episodic and long-term stores should NEVER be queried
        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->never();

        $this->memoryService->shouldReceive('search')
            ->never();

        $user = \ClarionApp\Backend\Models\User::factory()->create();
        $result = $this->retriever->retrieve('conv-1:msg-1', $user->id, 'agent-1', 'test query');

        $this->assertInstanceOf(MemoryRetrievalResult::class, $result);
    }

    #[Test]
    public function t033_storeFiltering_allEnabled_allThreeStoresQueried(): void
    {
        $embedding = [0.1, 0.2, 0.3];
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(true);
        $this->embeddingService->shouldReceive('generate')
            ->once()
            ->andReturn($embedding);

        // Default config: all three stores enabled
        config(['llm-client.auto_memory_retrieval.stores' => ['declarative', 'episodic', 'long-term']]);

        // All three stores should be queried exactly once
        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->once()
            ->andReturn([]);

        $this->memoryService->shouldReceive('search')
            ->once()
            ->andReturn([]);

        $user = \ClarionApp\Backend\Models\User::factory()->create();
        $result = $this->retriever->retrieve('conv-1:msg-1', $user->id, 'agent-1', 'test query');

        $this->assertInstanceOf(MemoryRetrievalResult::class, $result);
    }

    /* ------------------------------------------------------------------ */
    /* T035: Token budget enforcement verification                         */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function t035_budgetOverflow_dropsLowPriorityEntries(): void
    {
        $embedding = [0.1, 0.2, 0.3];
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(true);
        $this->embeddingService->shouldReceive('generate')
            ->once()
            ->andReturn($embedding);

        // Small budget: 10 tokens = 40 chars
        // Header alone uses ~30 chars, so only rule + maybe one short hit fits
        config(['llm-client.auto_memory_retrieval.max_tokens' => 10]);

        // Episodic returns two hits that will overflow the budget
        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->once()
            ->andReturn([
                ['id' => 'ep-1', 'summary' => 'First episodic hit with content', 'similarity_score' => 0.9],
                ['id' => 'ep-2', 'summary' => 'Second episodic hit with content', 'similarity_score' => 0.7],
            ]);

        // Long-term may be skipped by pre-stage gate if episodic hits exceed budget
        $this->memoryService->shouldReceive('search')
            ->atMost()->once()
            ->andReturn([]);

        $user = \ClarionApp\Backend\Models\User::factory()->create();
        // Create a rule (always kept)
        DeclarativeMemory::withoutGlobalScope('user')->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'type' => 'rule',
            'content' => 'Be concise',
            'source' => 'user_stated',
            'embedding' => null,
        ]);

        $result = $this->retriever->retrieve('conv-1:msg-1', $user->id, 'agent-1', 'test');

        // Budget overflow should have occurred
        $this->assertTrue($result->truncated);

        // Rule should be kept
        $ruleHits = $result->hitsByType('rule');
        $this->assertCount(1, $ruleHits);
    }

    #[Test]
    public function t035_truncationFlag_setOnResult_whenEntriesDropped(): void
    {
        $embedding = [0.1, 0.2, 0.3];
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(true);
        $this->embeddingService->shouldReceive('generate')
            ->once()
            ->andReturn($embedding);

        // Small budget: 10 tokens = 40 chars
        config(['llm-client.auto_memory_retrieval.max_tokens' => 10]);

        // Episodic returns a hit that exceeds the budget
        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->once()
            ->andReturn([
                ['id' => 'ep-1', 'summary' => str_repeat('X', 100), 'similarity_score' => 0.9],
            ]);

        // Long-term will be skipped by pre-stage gate (budget exhausted by episodic)
        $this->memoryService->shouldReceive('search')
            ->never();

        $user = \ClarionApp\Backend\Models\User::factory()->create();
        $result = $this->retriever->retrieve('conv-1:msg-1', $user->id, 'agent-1', 'test');

        // Truncation flag should be true because the episodic hit exceeds budget
        $this->assertTrue($result->truncated);
    }

    #[Test]
    public function t035_truncationFlag_notSet_whenWithinBudget(): void
    {
        $embedding = [0.1, 0.2, 0.3];
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(true);
        $this->embeddingService->shouldReceive('generate')
            ->once()
            ->andReturn($embedding);

        // Large budget: 4096 tokens = 16384 chars
        config(['llm-client.auto_memory_retrieval.max_tokens' => 4096]);

        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->once()
            ->andReturn([
                ['id' => 'ep-1', 'summary' => 'Short hit', 'similarity_score' => 0.9],
            ]);

        $this->memoryService->shouldReceive('search')
            ->once()
            ->andReturn([]);

        $user = \ClarionApp\Backend\Models\User::factory()->create();
        $result = $this->retriever->retrieve('conv-1:msg-1', $user->id, 'agent-1', 'test');

        // No truncation needed
        $this->assertFalse($result->truncated);
    }

    /* ------------------------------------------------------------------ */
    /* T036: Truncation flag threading from retrieve to formatInjectionSection */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function t036_truncationFlag_propagatedToInjectionSection(): void
    {
        $embedding = [0.1, 0.2, 0.3];
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(true);
        $this->embeddingService->shouldReceive('generate')
            ->once()
            ->andReturn($embedding);

        // Small budget: 20 tokens = 80 chars
        // Enough to get hits in, but enforceBudget will drop some
        config(['llm-client.auto_memory_retrieval.max_tokens' => 20]);

        // Episodic returns hits that will fit initial budget check but overflow after sorting
        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->once()
            ->andReturn([
                ['id' => 'ep-1', 'summary' => 'First hit content here', 'similarity_score' => 0.9],
                ['id' => 'ep-2', 'summary' => 'Second hit content here too', 'similarity_score' => 0.8],
            ]);

        // Long-term may or may not be called depending on pre-stage gate
        $this->memoryService->shouldReceive('search')
            ->atMost()->once()
            ->andReturn([]);

        $user = \ClarionApp\Backend\Models\User::factory()->create();
        // Create a rule to ensure we have hits of different priorities
        DeclarativeMemory::withoutGlobalScope('user')->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'type' => 'rule',
            'content' => 'Rule A',
            'source' => 'user_stated',
            'embedding' => null,
        ]);

        $result = $this->retriever->retrieve('conv-1:msg-1', $user->id, 'agent-1', 'test');

        // Result should have truncated flag set (budget is tight)
        $this->assertTrue($result->truncated);

        // formatInjectionSection should propagate the flag
        $section = $this->retriever->formatInjectionSection($result);
        $this->assertTrue($section->truncated);
    }

    #[Test]
    public function t036_injectionSection_notTruncated_whenWithinBudget(): void
    {
        $embedding = [0.1, 0.2, 0.3];
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(true);
        $this->embeddingService->shouldReceive('generate')
            ->once()
            ->andReturn($embedding);

        // Large budget
        config(['llm-client.auto_memory_retrieval.max_tokens' => 4096]);

        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->once()
            ->andReturn([
                ['id' => 'ep-1', 'summary' => 'Short summary', 'similarity_score' => 0.9],
            ]);

        $this->memoryService->shouldReceive('search')
            ->once()
            ->andReturn([]);

        $user = \ClarionApp\Backend\Models\User::factory()->create();
        $result = $this->retriever->retrieve('conv-1:msg-1', $user->id, 'agent-1', 'test');

        $this->assertFalse($result->truncated);

        $section = $this->retriever->formatInjectionSection($result);
        $this->assertFalse($section->truncated);
    }

    /* ------------------------------------------------------------------ */
    /* T037: Rules always kept even when over budget                       */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function t037_rulesAlwaysKept_evenWhenOverBudget(): void
    {
        $embedding = [0.1, 0.2, 0.3];
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(true);
        $this->embeddingService->shouldReceive('generate')
            ->once()
            ->andReturn($embedding);

        // Tiny budget: 10 tokens = 40 chars
        config(['llm-client.auto_memory_retrieval.max_tokens' => 10]);

        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->once()
            ->andReturn([
                ['id' => 'ep-1', 'summary' => str_repeat('X', 200), 'similarity_score' => 0.9],
            ]);

        // Long-term will be skipped by pre-stage gate
        $this->memoryService->shouldReceive('search')
            ->never();

        $user = \ClarionApp\Backend\Models\User::factory()->create();
        // Create a rule (always kept)
        DeclarativeMemory::withoutGlobalScope('user')->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'type' => 'rule',
            'content' => 'Always be concise',
            'source' => 'user_stated',
            'embedding' => null,
        ]);

        $result = $this->retriever->retrieve('conv-1:msg-1', $user->id, 'agent-1', 'test');

        // Rule should still be present despite budget overflow
        $ruleHits = $result->hitsByType('rule');
        $this->assertCount(1, $ruleHits);
        $this->assertEquals('Always be concise', $ruleHits[0]->content);

        // Episodic hit should be dropped
        $episodicHits = $result->hitsBySource('episodic');
        $this->assertCount(0, $episodicHits);

        // Truncation flag should be true
        $this->assertTrue($result->truncated);
    }

    #[Test]
    public function t037_rulesOnlyKept_whenAllOthersDropped(): void
    {
        $embedding = [0.1, 0.2, 0.3];
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(true);
        $this->embeddingService->shouldReceive('generate')
            ->once()
            ->andReturn($embedding);

        // Tiny budget: 5 tokens = 20 chars
        config(['llm-client.auto_memory_retrieval.max_tokens' => 5]);

        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->once()
            ->andReturn([
                ['id' => 'ep-1', 'summary' => 'Episodic content here', 'similarity_score' => 0.9],
                ['id' => 'ep-2', 'summary' => 'More episodic content', 'similarity_score' => 0.8],
            ]);

        // Long-term will be skipped by pre-stage gate
        $this->memoryService->shouldReceive('search')
            ->never();

        $user = \ClarionApp\Backend\Models\User::factory()->create();
        // Create a short rule
        DeclarativeMemory::withoutGlobalScope('user')->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'type' => 'rule',
            'content' => 'Be brief',
            'source' => 'user_stated',
            'embedding' => null,
        ]);

        $result = $this->retriever->retrieve('conv-1:msg-1', $user->id, 'agent-1', 'test');

        // Only the rule should remain
        $this->assertCount(1, $result->hits);
        $this->assertEquals('rule', $result->hits[0]->type);
        $this->assertTrue($result->truncated);
    }

    #[Test]
    public function t037_formatInjectionSection_rulesKept_withTruncationFlag(): void
    {
        $embedding = [0.1, 0.2, 0.3];
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(true);
        $this->embeddingService->shouldReceive('generate')
            ->once()
            ->andReturn($embedding);

        // Tiny budget
        config(['llm-client.auto_memory_retrieval.max_tokens' => 5]);

        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->once()
            ->andReturn([
                ['id' => 'ep-1', 'summary' => str_repeat('Z', 300), 'similarity_score' => 0.9],
            ]);

        // Long-term will be skipped by pre-stage gate
        $this->memoryService->shouldReceive('search')
            ->never();

        $user = \ClarionApp\Backend\Models\User::factory()->create();
        DeclarativeMemory::withoutGlobalScope('user')->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'type' => 'rule',
            'content' => 'Rule A',
            'source' => 'user_stated',
            'embedding' => null,
        ]);

        $result = $this->retriever->retrieve('conv-1:msg-1', $user->id, 'agent-1', 'test');

        // Verify the injection section has the rule and truncated flag
        $section = $this->retriever->formatInjectionSection($result);
        $this->assertStringContainsString('Rule A', $section->rawText);
        $this->assertTrue($section->truncated);
        $this->assertEquals(1, $section->hitCount);
    }

    /* ------------------------------------------------------------------ */
    /* T041: Cross-store deduplication                                      */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function t041_deduplication_exactMatch_removesDuplicateFromDifferentStores(): void
    {
        $embedding = [0.1, 0.2, 0.3];
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(true);
        $this->embeddingService->shouldReceive('generate')
            ->once()
            ->andReturn($embedding);

        // Episodic returns a hit with same content as a fact
        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->once()
            ->andReturn([
                [
                    'id' => 'ep-1',
                    'summary' => 'I prefer dark mode',
                    'similarity_score' => 0.85,
                ],
            ]);

        $this->memoryService->shouldReceive('search')
            ->once()
            ->andReturn([]);

        $user = \ClarionApp\Backend\Models\User::factory()->create();
        // Create a fact with same content as episodic
        DeclarativeMemory::withoutGlobalScope('user')->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'type' => 'fact',
            'content' => 'I prefer dark mode',
            'source' => 'user_stated',
            'embedding' => [0.1, 0.2, 0.3],
        ]);

        $result = $this->retriever->retrieve('conv-1:msg-1', $user->id, 'agent-1', 'dark mode');

        // Only one copy should remain (fact has higher priority than episodic)
        $contentHits = array_filter($result->hits, fn ($h) => trim($h->content) === 'I prefer dark mode');
        $this->assertCount(1, $contentHits);
        // The remaining hit should be the fact (priority 1 > episodic priority 3)
        $this->assertEquals('fact', array_values($contentHits)[0]->type);
    }

    #[Test]
    public function t041_deduplication_rulePreserved_overEpisodicWithSameContent(): void
    {
        $embedding = [0.1, 0.2, 0.3];
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(true);
        $this->embeddingService->shouldReceive('generate')
            ->once()
            ->andReturn($embedding);

        // Episodic returns a hit with same content as a rule
        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->once()
            ->andReturn([
                [
                    'id' => 'ep-1',
                    'summary' => 'Always respond in French',
                    'similarity_score' => 0.9,
                ],
            ]);

        $this->memoryService->shouldReceive('search')
            ->once()
            ->andReturn([]);

        $user = \ClarionApp\Backend\Models\User::factory()->create();
        // Create a rule with same content as episodic
        DeclarativeMemory::withoutGlobalScope('user')->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'type' => 'rule',
            'content' => 'Always respond in French',
            'source' => 'user_stated',
            'embedding' => null,
        ]);

        $result = $this->retriever->retrieve('conv-1:msg-1', $user->id, 'agent-1', 'french');

        // Only one copy should remain (rule is always kept)
        $contentHits = array_filter($result->hits, fn ($h) => trim($h->content) === 'Always respond in French');
        $this->assertCount(1, $contentHits);
        // The remaining hit must be the rule
        $this->assertEquals('rule', array_values($contentHits)[0]->type);
    }

    #[Test]
    public function t041_deduplication_caseInsensitiveMatch(): void
    {
        $embedding = [0.1, 0.2, 0.3];
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(true);
        $this->embeddingService->shouldReceive('generate')
            ->once()
            ->andReturn($embedding);

        // Episodic returns a hit with different case
        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->once()
            ->andReturn([
                [
                    'id' => 'ep-1',
                    'summary' => 'I LIKE PIZZA',
                    'similarity_score' => 0.85,
                ],
            ]);

        $this->memoryService->shouldReceive('search')
            ->once()
            ->andReturn([]);

        $user = \ClarionApp\Backend\Models\User::factory()->create();
        // Fact with lowercase content
        DeclarativeMemory::withoutGlobalScope('user')->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'type' => 'fact',
            'content' => 'i like pizza',
            'source' => 'user_stated',
            'embedding' => [0.1, 0.2, 0.3],
        ]);

        $result = $this->retriever->retrieve('conv-1:msg-1', $user->id, 'agent-1', 'pizza');

        // Only one copy should remain (case-insensitive match)
        $contentHits = array_filter($result->hits, fn ($h) => mb_strtolower(trim($h->content)) === 'i like pizza');
        $this->assertCount(1, $contentHits);
    }

    #[Test]
    public function t041_deduplication_factKept_overPreferenceWithSameContent(): void
    {
        $embedding = [0.1, 0.2, 0.3];
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(true);
        $this->embeddingService->shouldReceive('generate')
            ->once()
            ->andReturn($embedding);

        // Episodic returns a hit with same content as a fact
        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->once()
            ->andReturn([
                [
                    'id' => 'ep-1',
                    'summary' => 'User likes dark mode',
                    'similarity_score' => 0.85,
                ],
            ]);

        $this->memoryService->shouldReceive('search')
            ->once()
            ->andReturn([]);

        $user = \ClarionApp\Backend\Models\User::factory()->create();
        // Fact with same content as episodic
        DeclarativeMemory::withoutGlobalScope('user')->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'type' => 'fact',
            'content' => 'User likes dark mode',
            'source' => 'user_stated',
            'embedding' => [0.1, 0.2, 0.3],
        ]);

        $result = $this->retriever->retrieve('conv-1:msg-1', $user->id, 'agent-1', 'dark mode');

        // Only one copy should remain (fact priority 1 > episodic priority 3)
        $this->assertCount(1, $result->hits);
        $this->assertEquals('fact', $result->hits[0]->type);
    }

    #[Test]
    public function t041_deduplication_noDuplicates_keepsAll(): void
    {
        $embedding = [0.1, 0.2, 0.3];
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(true);
        $this->embeddingService->shouldReceive('generate')
            ->once()
            ->andReturn($embedding);

        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->once()
            ->andReturn([
                ['id' => 'ep-1', 'summary' => 'Unique episodic content', 'similarity_score' => 0.9],
            ]);

        // Long-term with different content
        $ltEntry = Mockery::mock();
        $ltEntry->id = 'lt-1';
        $ltEntry->content = 'Unique long-term content';
        $ltEntry->key = 'some_key';
        $ltEntry->last_accessed_at = null;
        $ltEntry->shouldReceive('getAttribute')
            ->with('similarity_score', 0.5)
            ->andReturn(0.8);

        $this->memoryService->shouldReceive('search')
            ->once()
            ->andReturn([$ltEntry]);

        $user = \ClarionApp\Backend\Models\User::factory()->create();
        // Rule with unique content
        DeclarativeMemory::withoutGlobalScope('user')->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'type' => 'rule',
            'content' => 'Unique rule content',
            'source' => 'user_stated',
            'embedding' => null,
        ]);

        $result = $this->retriever->retrieve('conv-1:msg-1', $user->id, 'agent-1', 'test');

        // All three unique hits should remain
        $this->assertCount(3, $result->hits);
    }

    /* ------------------------------------------------------------------ */
    /* T039: Relevance threshold filtering (rules exempt)                   */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function t039_relevanceThreshold_filtersLowScoringHits_butKeepsRules(): void
    {
        // Set high threshold
        config(['llm-client.auto_memory_retrieval.relevance_threshold' => 0.9]);

        $embedding = [1.0, 0.0, 0.0];
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(true);
        $this->embeddingService->shouldReceive('generate')
            ->once()
            ->andReturn($embedding);

        // Episodic returns a hit below threshold
        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->once()
            ->andReturn([
                ['id' => 'ep-1', 'summary' => 'Low scoring episodic', 'similarity_score' => 0.5],
            ]);

        $this->memoryService->shouldReceive('search')
            ->once()
            ->andReturn([]);

        $user = \ClarionApp\Backend\Models\User::factory()->create();
        // Create a rule (always kept regardless of threshold)
        DeclarativeMemory::withoutGlobalScope('user')->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'type' => 'rule',
            'content' => 'Important rule',
            'source' => 'user_stated',
            'embedding' => null,
        ]);
        // Fact with orthogonal embedding (cosine = 0, normalized = 0.5 < 0.9 threshold)
        DeclarativeMemory::withoutGlobalScope('user')->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'type' => 'fact',
            'content' => 'Irrelevant fact',
            'source' => 'user_stated',
            'embedding' => [0.0, 1.0, 0.0],
        ]);

        $result = $this->retriever->retrieve('conv-1:msg-1', $user->id, 'agent-1', 'test');

        // Rule should be present
        $ruleHits = $result->hitsByType('rule');
        $this->assertCount(1, $ruleHits);
        // Fact below threshold should be filtered
        $factHits = $result->hitsByType('fact');
        $this->assertCount(0, $factHits);
        // Episodic below threshold should be filtered
        $episodicHits = $result->hitsBySource('episodic');
        $this->assertCount(0, $episodicHits);
    }

    /* ------------------------------------------------------------------ */
    /* T040: max_results_per_store (rules exempt)                           */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function t040_maxResultsPerStore_capsFacts_butNotRules(): void
    {
        // Limit to 1 per store
        config(['llm-client.auto_memory_retrieval.max_results_per_store' => 1]);

        $embedding = [1.0, 0.0, 0.0];
        $this->embeddingService->shouldReceive('isEnabled')->andReturn(true);
        $this->embeddingService->shouldReceive('generate')
            ->once()
            ->andReturn($embedding);

        $this->episodicMemorySearchService->shouldReceive('hybridSearch')
            ->once()
            ->andReturn([]);

        $this->memoryService->shouldReceive('search')
            ->once()
            ->andReturn([]);

        $user = \ClarionApp\Backend\Models\User::factory()->create();
        // Create 3 rules (exempt from cap)
        for ($i = 0; $i < 3; $i++) {
            DeclarativeMemory::withoutGlobalScope('user')->create([
                'id' => Str::uuid()->toString(),
                'user_id' => $user->id,
                'type' => 'rule',
                'content' => "Rule {$i}",
                'source' => 'user_stated',
                'embedding' => null,
            ]);
        }
        // Create 5 facts (should be capped at 1)
        for ($i = 0; $i < 5; $i++) {
            DeclarativeMemory::withoutGlobalScope('user')->create([
                'id' => Str::uuid()->toString(),
                'user_id' => $user->id,
                'type' => 'fact',
                'content' => "Fact {$i}",
                'source' => 'user_stated',
                'embedding' => [1.0, 0.0, 0.0],
            ]);
        }

        $result = $this->retriever->retrieve('conv-1:msg-1', $user->id, 'agent-1', 'test');

        // All 3 rules should be present (exempt from cap)
        $ruleHits = $result->hitsByType('rule');
        $this->assertCount(3, $ruleHits);
        // Only 1 fact (capped at max_results_per_store)
        $factHits = $result->hitsByType('fact');
        $this->assertCount(1, $factHits);
    }
}

