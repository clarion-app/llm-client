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
                $table->integer('confidence_level')->nullable();
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

    /* -----------------------------------------------------------------
     * Recall Provenance Tests (User Story 4)
     * ----------------------------------------------------------------- */

    #[Test]
    public function recall_distinguishes_user_stated_from_agent_learned(): void
    {
        $embeddingService = $this->createMock(EmbeddingService::class);
        $embeddingService->method('isEnabled')->willReturn(false);
        $service = new \ClarionApp\LlmClient\Services\DeclarativeMemoryService($embeddingService);

        // Create a user_stated entry
        $service->createByUser($this->user->id, 'preference', 'Always use 24-hour time format');

        // Create an agent_learned entry directly (simulating confirmed agent write)
        DeclarativeMemory::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'type' => 'fact',
            'content' => 'User prefers Python over JavaScript',
            'source' => 'agent_learned',
        ]);

        $recalled = $service->recall($this->user->id);

        // Each entry must expose its source
        $sources = $recalled['entries']->pluck('source')->toArray();
        $this->assertContains('user_stated', $sources);
        $this->assertContains('agent_learned', $sources);

        // Verify source is per-entry, not just aggregate
        foreach ($recalled['entries'] as $entry) {
            $this->assertNotNull($entry->source);
            $this->assertContains($entry->source, ['user_stated', 'agent_learned']);
        }
    }

    #[Test]
    public function recall_surfaces_rules_as_binding_group(): void
    {
        $embeddingService = $this->createMock(EmbeddingService::class);
        $embeddingService->method('isEnabled')->willReturn(false);
        $service = new \ClarionApp\LlmClient\Services\DeclarativeMemoryService($embeddingService);

        // Create a rule entry
        $service->createByUser($this->user->id, 'rule', 'Never delete files without confirmation');

        // Create a fact entry
        $service->createByUser($this->user->id, 'fact', 'I live in Boston');

        // Create a preference entry
        $service->createByUser($this->user->id, 'preference', 'Dark mode preferred');

        $recalled = $service->recall($this->user->id);

        // Rules must be in a distinct group
        $this->assertArrayHasKey('rules', $recalled);
        $this->assertCount(1, $recalled['rules']);
        $firstRule = $recalled['rules']->first();
        $this->assertEquals('rule', $firstRule->type);
        $this->assertEquals('Never delete files without confirmation', $firstRule->content);

        // Facts and preferences are separate
        $this->assertCount(1, $recalled['facts']);
        $this->assertCount(1, $recalled['preferences']);

        // Total entries include all
        $this->assertCount(3, $recalled['entries']);
    }

    /* -----------------------------------------------------------------
     * Confidence Visibility on Recall (User Story 1 / User Story 3)
     * ----------------------------------------------------------------- */

    private function makeService(bool $embeddingEnabled = false): \ClarionApp\LlmClient\Services\DeclarativeMemoryService
    {
        $embeddingService = $this->createMock(EmbeddingService::class);
        $embeddingService->method('isEnabled')->willReturn($embeddingEnabled);

        return new \ClarionApp\LlmClient\Services\DeclarativeMemoryService($embeddingService);
    }

    #[Test]
    public function recall_exposes_confidence_level_for_high_low_and_null(): void
    {
        $service = $this->makeService();

        // User-stated → confidence_level is NULL
        $service->createByUser($this->user->id, 'preference', 'Always use 24-hour time format');

        // High-confidence learned pattern
        $service->applyAgentWrite($this->user->id, 'fact', 'User prefers Python', true, null, 90);

        // Low-confidence learned pattern
        $service->applyAgentWrite($this->user->id, 'rule', 'Prefers short replies', true, null, 20);

        $recalled = $service->recall($this->user->id);

        $byContent = $recalled['entries']->keyBy('content');

        $this->assertNull($byContent['Always use 24-hour time format']->confidence_level);
        $this->assertSame(90, $byContent['User prefers Python']->confidence_level);
        $this->assertSame(20, $byContent['Prefers short replies']->confidence_level);
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('confidenceLevelProvider')]
    public function learned_pattern_recall_reports_confidence_level(int $confidence): void
    {
        $service = $this->makeService();

        $service->applyAgentWrite($this->user->id, 'fact', 'A learned fact', true, null, $confidence);

        $recalled = $service->recall($this->user->id);
        $entry = $recalled['entries']->firstWhere('content', 'A learned fact');

        $this->assertSame('agent_learned', $entry->source);
        $this->assertSame($confidence, $entry->confidence_level);
    }

    public static function confidenceLevelProvider(): array
    {
        return [
            'high confidence' => [95],
            'low confidence' => [15],
            'zero confidence' => [0],
        ];
    }

    #[Test]
    public function user_stated_entries_have_null_confidence_level(): void
    {
        $service = $this->makeService();

        $result = $service->createByUser($this->user->id, 'preference', 'Dark mode preferred');

        $this->assertNull($result->confidence_level);

        $fromDb = DeclarativeMemory::withoutGlobalScope('user')->find($result->id);
        $this->assertNull($fromDb->confidence_level);
    }

    /* -----------------------------------------------------------------
     * Confidence Range Validation (User Story 3)
     * ----------------------------------------------------------------- */

    #[Test]
    public function confirmed_write_rejects_confidence_below_zero(): void
    {
        $service = $this->makeService();

        $this->expectException(\InvalidArgumentException::class);
        $service->applyAgentWrite($this->user->id, 'fact', 'Out of range', true, null, -1);
    }

    #[Test]
    public function confirmed_write_rejects_confidence_above_one_hundred(): void
    {
        $service = $this->makeService();

        $this->expectException(\InvalidArgumentException::class);
        $service->applyAgentWrite($this->user->id, 'fact', 'Out of range', true, null, 101);
    }

    #[Test]
    public function confirmed_write_accepts_confidence_boundaries(): void
    {
        $service = $this->makeService();

        $zero = $service->applyAgentWrite($this->user->id, 'fact', 'Zero bound', true, null, 0);
        $hundred = $service->applyAgentWrite($this->user->id, 'preference', 'Hundred bound', true, null, 100);

        $this->assertSame(0, $zero->confidence_level);
        $this->assertSame(100, $hundred->confidence_level);
    }

    /* -----------------------------------------------------------------
     * Learned → User-Stated Conversion on Edit (User Story 2)
     * ----------------------------------------------------------------- */

    #[Test]
    public function editing_learned_entry_converts_source_to_user_stated(): void
    {
        $service = $this->makeService();

        $learned = $service->applyAgentWrite($this->user->id, 'fact', 'User prefers Python', true, null, 80);
        $this->assertSame('agent_learned', $learned->source);

        $updated = $service->updateByUser($this->user->id, $learned->id, 'User prefers Rust');

        $this->assertSame('user_stated', $updated->source);
        $this->assertSame('User prefers Rust', $updated->content);
    }

    #[Test]
    public function editing_learned_entry_clears_confidence_level_to_null(): void
    {
        $service = $this->makeService();

        $learned = $service->applyAgentWrite($this->user->id, 'preference', 'Prefers dark mode', true, null, 65);
        $this->assertSame(65, $learned->confidence_level);

        $updated = $service->updateByUser($this->user->id, $learned->id, 'Prefers light mode');

        $this->assertNull($updated->confidence_level);

        $fromDb = DeclarativeMemory::withoutGlobalScope('user')->find($learned->id);
        $this->assertNull($fromDb->confidence_level);
        $this->assertSame('user_stated', $fromDb->source);
    }

    /* -----------------------------------------------------------------
     * Precedence Rules (User Story 4)
     * ----------------------------------------------------------------- */

    /**
     * Build a service whose embeddings all collide (high similarity), so that
     * every same-type write is treated as a semantic conflict against existing rows.
     */
    private function makeCollidingEmbeddingService(): \ClarionApp\LlmClient\Services\DeclarativeMemoryService
    {
        $vector = array_fill(0, 1536, 0.0);
        $vector[0] = 1.0;

        $embeddingService = $this->createMock(EmbeddingService::class);
        $embeddingService->method('isEnabled')->willReturn(true);
        $embeddingService->method('generate')->willReturn($vector);

        return new \ClarionApp\LlmClient\Services\DeclarativeMemoryService($embeddingService);
    }

    #[Test]
    public function user_stated_entry_is_never_superseded_by_learned_pattern(): void
    {
        $service = $this->makeCollidingEmbeddingService();

        // User states a preference
        $userEntry = $service->createByUser($this->user->id, 'preference', 'Always use 24-hour time');
        $this->assertSame('user_stated', $userEntry->source);

        // Agent proposes a conflicting learned pattern (same type, colliding embedding)
        $service->applyAgentWrite($this->user->id, 'preference', 'Prefers 12-hour time', true, null, 95);

        // The user-stated entry must survive unchanged; a separate learned row is inserted
        $userEntry->refresh();
        $this->assertSame('user_stated', $userEntry->source);
        $this->assertSame('Always use 24-hour time', $userEntry->content);
        $this->assertNull($userEntry->confidence_level);

        $count = DeclarativeMemory::withoutGlobalScope('user')
            ->where('user_id', $this->user->id)
            ->where('type', 'preference')
            ->count();
        $this->assertEquals(2, $count);
    }

    #[Test]
    public function higher_confidence_learned_pattern_supersedes_older_learned_pattern(): void
    {
        $service = $this->makeCollidingEmbeddingService();

        $first = $service->applyAgentWrite($this->user->id, 'fact', 'User likes tea', true, null, 40);

        $second = $service->applyAgentWrite($this->user->id, 'fact', 'User strongly likes tea', true, null, 80);

        // Higher confidence supersedes in place
        $this->assertSame($first->id, $second->id);
        $this->assertSame('User strongly likes tea', $second->content);
        $this->assertSame(80, $second->confidence_level);

        $count = DeclarativeMemory::withoutGlobalScope('user')
            ->where('user_id', $this->user->id)
            ->where('type', 'fact')
            ->count();
        $this->assertEquals(1, $count);
    }

    #[Test]
    public function lower_confidence_learned_pattern_does_not_supersede(): void
    {
        $service = $this->makeCollidingEmbeddingService();

        $first = $service->applyAgentWrite($this->user->id, 'fact', 'User likes tea', true, null, 80);

        $second = $service->applyAgentWrite($this->user->id, 'fact', 'User maybe likes tea', true, null, 30);

        // Lower confidence does not overwrite — a new row is inserted instead
        $this->assertNotSame($first->id, $second->id);

        $first->refresh();
        $this->assertSame('User likes tea', $first->content);
        $this->assertSame(80, $first->confidence_level);

        $count = DeclarativeMemory::withoutGlobalScope('user')
            ->where('user_id', $this->user->id)
            ->where('type', 'fact')
            ->count();
        $this->assertEquals(2, $count);
    }

    #[Test]
    public function newer_user_stated_entry_supersedes_older_user_stated_entry(): void
    {
        $service = $this->makeCollidingEmbeddingService();

        $first = $service->createByUser($this->user->id, 'preference', 'Always use 24-hour time');

        $second = $service->createByUser($this->user->id, 'preference', 'Prefer showing times as 24-hour');

        // Unchanged behavior: user-stated restatement supersedes in place
        $this->assertSame($first->id, $second->id);
        $this->assertSame('Prefer showing times as 24-hour', $second->content);

        $count = DeclarativeMemory::withoutGlobalScope('user')
            ->where('user_id', $this->user->id)
            ->where('type', 'preference')
            ->count();
        $this->assertEquals(1, $count);
    }

    /* -----------------------------------------------------------------
     * Empty Store (User Story 6) & Edge Cases
     * ----------------------------------------------------------------- */

    #[Test]
    public function recall_on_empty_store_returns_empty_groups_without_error(): void
    {
        $service = $this->makeService();

        $recalled = $service->recall($this->user->id);

        $this->assertCount(0, $recalled['entries']);
        $this->assertCount(0, $recalled['rules']);
        $this->assertCount(0, $recalled['facts']);
        $this->assertCount(0, $recalled['preferences']);
    }

    #[Test]
    public function identical_learned_and_user_stated_entries_coexist_without_conflict(): void
    {
        // Embeddings disabled and distinct types → no supersession between the two
        $service = $this->makeService();

        $userEntry = $service->createByUser($this->user->id, 'fact', 'User prefers Python');
        $learnedEntry = $service->applyAgentWrite($this->user->id, 'preference', 'User prefers Python', true, null, 70);

        $this->assertSame('user_stated', $userEntry->source);
        $this->assertSame('agent_learned', $learnedEntry->source);
        $this->assertNotSame($userEntry->id, $learnedEntry->id);

        $count = DeclarativeMemory::withoutGlobalScope('user')
            ->where('user_id', $this->user->id)
            ->count();
        $this->assertEquals(2, $count);
    }

    #[Test]
    public function learned_pattern_with_zero_confidence_is_stored(): void
    {
        $service = $this->makeService();

        $result = $service->applyAgentWrite($this->user->id, 'fact', 'A barely-supported guess', true, null, 0);

        $this->assertSame('agent_learned', $result->source);
        $this->assertSame(0, $result->confidence_level);

        $fromDb = DeclarativeMemory::withoutGlobalScope('user')->find($result->id);
        $this->assertSame(0, $fromDb->confidence_level);
    }

    #[Test]
    public function all_entries_live_in_the_single_declarative_memories_table(): void
    {
        // FR-013 single-store invariant: user-stated and learned entries share one table.
        $service = $this->makeService();

        $service->createByUser($this->user->id, 'preference', 'User-stated preference');
        $service->applyAgentWrite($this->user->id, 'fact', 'Learned fact', true, null, 55);

        $rows = DeclarativeMemory::withoutGlobalScope('user')
            ->where('user_id', $this->user->id)
            ->get();

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(
            ['user_stated', 'agent_learned'],
            $rows->pluck('source')->all()
        );
        $this->assertSame('declarative_memories', (new DeclarativeMemory)->getTable());
    }
}
