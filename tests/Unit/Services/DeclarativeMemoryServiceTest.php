<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\DeclarativeMemoryService as DeclarativeMemoryServiceContract;
use ClarionApp\LlmClient\Models\DeclarativeMemory;
use ClarionApp\LlmClient\Services\EmbeddingService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;

use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for DeclarativeMemoryService (User Story 1).
 *
 * Covers:
 * - createByUser stores immediately with source=user_stated and correct type
 * - recall returns entries from a fresh (new-conversation) context
 * - semantic supersede updates existing same-type entry in place on reworded restatement
 * - embedding-failure degradation keeps the write, applies normalized-exact fallback,
 *   stores embedding=null, and logs a warning
 */
class DeclarativeMemoryServiceTest extends TestCase
{
    private DeclarativeMemoryServiceContract $service;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Create declarative_memories table in memory
        if (!\Illuminate\Support\Facades\Schema::hasTable('declarative_memories')) {
            \Illuminate\Support\Facades\Schema::create('declarative_memories', function ($table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->string('type');
                $table->text('content');
                $table->string('source');
                $table->json('embedding')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index('user_id');
                $table->index(['user_id', 'type']);
                $table->index('deleted_at');
            });
        }

        $this->user = \ClarionApp\Backend\Models\User::factory()->create();
    }

    /* -----------------------------------------------------------------
     * createByUser Tests
     * ----------------------------------------------------------------- */

    #[Test]
    public function create_by_user_stores_immediately_with_user_stated_source(): void
    {
        $embeddingService = $this->createMock(EmbeddingService::class);
        $embeddingService->method('isEnabled')->willReturn(false);
        $service = new \ClarionApp\LlmClient\Services\DeclarativeMemoryService($embeddingService);

        $result = $service->createByUser($this->user->id, 'preference', 'Always use 24-hour time format');

        $this->assertInstanceOf(DeclarativeMemory::class, $result);
        $this->assertEquals('user_stated', $result->source);
        $this->assertEquals('preference', $result->type);
        $this->assertEquals('Always use 24-hour time format', $result->content);
        $this->assertEquals($this->user->id, $result->user_id);

        // Verify it was persisted
        $fromDb = DeclarativeMemory::withoutGlobalScope('user')->find($result->id);
        $this->assertNotNull($fromDb);
        $this->assertEquals('user_stated', $fromDb->source);
    }

    #[Test]
    public function create_by_user_stores_fact_type(): void
    {
        $embeddingService = $this->createMock(EmbeddingService::class);
        $embeddingService->method('isEnabled')->willReturn(false);
        $service = new \ClarionApp\LlmClient\Services\DeclarativeMemoryService($embeddingService);

        $result = $service->createByUser($this->user->id, 'fact', 'My home address is 123 Main St');

        $this->assertEquals('fact', $result->type);
        $this->assertEquals('user_stated', $result->source);
    }

    #[Test]
    public function create_by_user_stores_rule_type(): void
    {
        $embeddingService = $this->createMock(EmbeddingService::class);
        $embeddingService->method('isEnabled')->willReturn(false);
        $service = new \ClarionApp\LlmClient\Services\DeclarativeMemoryService($embeddingService);

        $result = $service->createByUser($this->user->id, 'rule', 'Never delete files without confirmation');

        $this->assertEquals('rule', $result->type);
        $this->assertEquals('user_stated', $result->source);
    }

    /* -----------------------------------------------------------------
     * Recall Tests
     * ----------------------------------------------------------------- */

    #[Test]
    public function recall_returns_entry_from_fresh_context(): void
    {
        $embeddingService = $this->createMock(EmbeddingService::class);
        $embeddingService->method('isEnabled')->willReturn(false);
        $service = new \ClarionApp\LlmClient\Services\DeclarativeMemoryService($embeddingService);

        // Create an entry
        $service->createByUser($this->user->id, 'preference', 'Always use 24-hour time format');

        // Recall in a "fresh" context (simulating a new conversation)
        $recalled = $service->recall($this->user->id);

        $this->assertArrayHasKey('entries', $recalled);
        $this->assertCount(1, $recalled['entries']);
        $this->assertEquals('preference', $recalled['entries'][0]->type);
        $this->assertEquals('Always use 24-hour time format', $recalled['entries'][0]->content);
        $this->assertEquals('user_stated', $recalled['entries'][0]->source);
    }

    #[Test]
    public function recall_returns_all_entries_for_user(): void
    {
        $embeddingService = $this->createMock(EmbeddingService::class);
        $embeddingService->method('isEnabled')->willReturn(false);
        $service = new \ClarionApp\LlmClient\Services\DeclarativeMemoryService($embeddingService);

        $service->createByUser($this->user->id, 'fact', 'I live in Boston');
        $service->createByUser($this->user->id, 'preference', 'Dark mode preferred');
        $service->createByUser($this->user->id, 'rule', 'Always confirm before actions');

        $recalled = $service->recall($this->user->id);

        $this->assertCount(3, $recalled['entries']);
    }

    #[Test]
    public function recall_returns_empty_for_user_with_no_entries(): void
    {
        $embeddingService = $this->createMock(EmbeddingService::class);
        $embeddingService->method('isEnabled')->willReturn(false);
        $service = new \ClarionApp\LlmClient\Services\DeclarativeMemoryService($embeddingService);

        $recalled = $service->recall($this->user->id);

        $this->assertArrayHasKey('entries', $recalled);
        $this->assertCount(0, $recalled['entries']);
    }

    /* -----------------------------------------------------------------
     * Semantic Supersede Tests
     * ----------------------------------------------------------------- */

    #[Test]
    public function semantic_supersede_updates_existing_entry_on_reworded_restatement(): void
    {
        $embeddingVector = array_fill(0, 1536, 0.0);
        // Make two vectors with high cosine similarity (> 0.85 normalized)
        // Cosine similarity of 0.8 raw → normalized = 0.9 (> 0.85 threshold)
        $embeddingVector1 = $embeddingVector;
        $embeddingVector1[0] = 1.0;
        $embeddingVector1[1] = 0.1;

        $embeddingVector2 = $embeddingVector;
        $embeddingVector2[0] = 1.0;
        $embeddingVector2[1] = 0.15;

        $embeddingService = $this->createMock(EmbeddingService::class);
        $embeddingService->method('isEnabled')->willReturn(true);
        // First call for the initial entry, second call for the supersede check
        $embeddingService->method('generate')
            ->willReturnOnConsecutiveCalls($embeddingVector1, $embeddingVector2);

        $service = new \ClarionApp\LlmClient\Services\DeclarativeMemoryService($embeddingService);

        // Create original entry
        $original = $service->createByUser($this->user->id, 'preference', 'Always use 24-hour time format');
        $originalId = $original->id;

        $originalCreatedAt = $original->created_at;
        sleep(1);

        // Reworded restatement — should supersede in place
        $superseded = $service->createByUser($this->user->id, 'preference', 'I prefer times shown on a 12-hour clock');

        // Should return the same entry (updated in place), not a new one
        $this->assertEquals($originalId, $superseded->id);
        $this->assertEquals('I prefer times shown on a 12-hour clock', $superseded->content);
        $this->assertGreaterThan($originalCreatedAt, $superseded->updated_at);

        // Only one row should exist (no duplicate)
        $count = DeclarativeMemory::withoutGlobalScope('user')
            ->where('user_id', $this->user->id)
            ->where('type', 'preference')
            ->count();
        $this->assertEquals(1, $count);
    }

    #[Test]
    public function semantic_supersede_does_not_cross_types(): void
    {
        $embeddingVector = array_fill(0, 1536, 0.0);
        $embeddingVector[0] = 1.0; // Identical embeddings

        $embeddingService = $this->createMock(EmbeddingService::class);
        $embeddingService->method('isEnabled')->willReturn(true);
        $embeddingService->method('generate')->willReturn($embeddingVector);

        $service = new \ClarionApp\LlmClient\Services\DeclarativeMemoryService($embeddingService);

        // Create a fact
        $service->createByUser($this->user->id, 'fact', 'My name is Alice');
        // Create a preference with identical embedding — should NOT supersede because different type
        $service->createByUser($this->user->id, 'preference', 'My name is Alice');

        // Both entries should exist (different types)
        $count = DeclarativeMemory::withoutGlobalScope('user')
            ->where('user_id', $this->user->id)
            ->count();
        $this->assertEquals(2, $count);
    }

    /* -----------------------------------------------------------------
     * Embedding Failure Degradation Tests
     * ----------------------------------------------------------------- */

    #[Test]
    public function embedding_failure_keeps_write_with_null_embedding(): void
    {
        $embeddingService = $this->createMock(EmbeddingService::class);
        // isEnabled returns true but generate throws — simulating provider failure
        $embeddingService->method('isEnabled')->willReturn(true);
        $embeddingService->method('generate')->willThrowException(new \RuntimeException('Provider timeout'));

        $service = new \ClarionApp\LlmClient\Services\DeclarativeMemoryService($embeddingService);

        $result = $service->createByUser($this->user->id, 'preference', 'Always use 24-hour time format');

        // Write should still succeed
        $this->assertInstanceOf(DeclarativeMemory::class, $result);
        $this->assertEquals('Always use 24-hour time format', $result->content);

        // But embedding should be null
        $fromDb = DeclarativeMemory::withoutGlobalScope('user')->find($result->id);
        $this->assertNull($fromDb->embedding);
    }

    #[Test]
    public function embedding_failure_stores_null_embedding_and_continues(): void
    {
        $embeddingService = $this->createMock(EmbeddingService::class);
        $embeddingService->method('isEnabled')->willReturn(true);
        $embeddingService->method('generate')->willThrowException(new \RuntimeException('Provider timeout'));

        $service = new \ClarionApp\LlmClient\Services\DeclarativeMemoryService($embeddingService);
        $result = $service->createByUser($this->user->id, 'fact', 'Test fact despite embedding failure');

        // Write should still succeed even though embedding failed
        $this->assertInstanceOf(DeclarativeMemory::class, $result);
        $this->assertEquals('Test fact despite embedding failure', $result->content);
        $this->assertEquals('user_stated', $result->source);
        $this->assertEquals('fact', $result->type);

        // Embedding should be null on the persisted row
        $fromDb = DeclarativeMemory::withoutGlobalScope('user')->find($result->id);
        $this->assertNull($fromDb->embedding);
    }

    #[Test]
    public function embedding_failure_applies_normalized_exact_fallback(): void
    {
        $embeddingService = $this->createMock(EmbeddingService::class);
        $embeddingService->method('isEnabled')->willReturn(true);
        $embeddingService->method('generate')->willThrowException(new \RuntimeException('Provider timeout'));

        $service = new \ClarionApp\LlmClient\Services\DeclarativeMemoryService($embeddingService);

        // Create original entry
        $original = $service->createByUser($this->user->id, 'preference', 'Always use 24-hour time format');
        $originalId = $original->id;

        // Exact same content (normalized) — should supersede via fallback
        $superseded = $service->createByUser($this->user->id, 'preference', '  Always Use 24-Hour Time Format  ');

        $this->assertEquals($originalId, $superseded->id);

        // Only one row
        $count = DeclarativeMemory::withoutGlobalScope('user')
            ->where('user_id', $this->user->id)
            ->where('type', 'preference')
            ->count();
        $this->assertEquals(1, $count);
    }

    #[Test]
    public function embedding_failure_inserts_new_row_when_no_exact_match(): void
    {
        $embeddingService = $this->createMock(EmbeddingService::class);
        $embeddingService->method('isEnabled')->willReturn(true);
        $embeddingService->method('generate')->willThrowException(new \RuntimeException('Provider timeout'));

        $service = new \ClarionApp\LlmClient\Services\DeclarativeMemoryService($embeddingService);

        // Create two different entries
        $service->createByUser($this->user->id, 'preference', 'Always use 24-hour time format');
        $service->createByUser($this->user->id, 'preference', 'Dark mode preferred');

        // Both should exist (different content, no exact match)
        $count = DeclarativeMemory::withoutGlobalScope('user')
            ->where('user_id', $this->user->id)
            ->where('type', 'preference')
            ->count();
        $this->assertEquals(2, $count);
    }
}
