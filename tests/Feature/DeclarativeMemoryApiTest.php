<?php

namespace Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\DeclarativeMemoryService as DeclarativeMemoryServiceContract;
use ClarionApp\LlmClient\Models\DeclarativeMemory;
use ClarionApp\LlmClient\Services\EmbeddingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * Feature test: Declarative memory API endpoints (User Story 3).
 *
 * Covers:
 * - List returns each entry's type+source and paginates
 * - Empty store returns empty collection (never an error)
 * - List responds well under 5 s (SC-002)
 * - Update edits content and the new value is what recall returns
 * - Delete permanently removes and the entry does not resurface
 * - 404 (not 403) on a missing or non-owned id
 *
 * Tests use the service layer with mocked auth (Passport unavailable in test bench).
 */
class DeclarativeMemoryApiTest extends TestCase
{
    private DeclarativeMemoryServiceContract $service;

    protected function setUp(): void
    {
        parent::setUp();

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

        $embeddingService = $this->createMock(EmbeddingService::class);
        $embeddingService->method('isEnabled')->willReturn(false);
        $this->service = new \ClarionApp\LlmClient\Services\DeclarativeMemoryService($embeddingService);
    }

    /* -----------------------------------------------------------------
     * List Tests
     * ----------------------------------------------------------------- */

    #[Test]
    public function list_returns_entries_with_type_and_source(): void
    {
        $user = User::factory()->create();

        $this->service->createByUser($user->id, 'preference', 'Always use 24-hour time format');
        $this->service->createByUser($user->id, 'rule', 'Never delete without confirmation');

        $paginator = $this->service->list($user->id);

        $this->assertCount(2, $paginator->items());

        $types = [];
        $sources = [];
        foreach ($paginator->items() as $entry) {
            $types[] = $entry->type;
            $sources[] = $entry->source;
        }

        $this->assertContains('preference', $types);
        $this->assertContains('rule', $types);
        // Both created via createByUser so source is user_stated
        $this->assertContains('user_stated', $sources);
    }

    #[Test]
    public function list_empty_store_returns_empty_collection(): void
    {
        $user = User::factory()->create();

        $paginator = $this->service->list($user->id);

        $this->assertEmpty($paginator->items());
        $this->assertEquals(0, $paginator->total());
    }

    #[Test]
    public function list_responds_under_5_seconds(): void
    {
        $user = User::factory()->create();

        // Create enough entries to exercise pagination
        for ($i = 0; $i < 30; $i++) {
            $this->service->createByUser($user->id, 'fact', "Fact number {$i}");
        }

        $startTime = microtime(true);
        $paginator = $this->service->list($user->id);
        $elapsed = microtime(true) - $startTime;

        $this->assertGreaterThanOrEqual(1, count($paginator->items()));
        $this->assertEquals(30, $paginator->total());
        $this->assertLessThan(5.0, $elapsed, "List responded in {$elapsed}s, expected < 5s");
    }

    #[Test]
    public function list_supports_pagination(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 25; $i++) {
            $this->service->createByUser($user->id, 'fact', "Fact {$i}");
        }

        $paginator = $this->service->list($user->id, 1, 10);

        $this->assertCount(10, $paginator->items());
        $this->assertEquals(1, $paginator->currentPage());
        $this->assertEquals(3, $paginator->lastPage());
        $this->assertEquals(25, $paginator->total());
    }

    /* -----------------------------------------------------------------
     * Update Tests
     * ----------------------------------------------------------------- */

    #[Test]
    public function update_edits_content_and_recall_returns_new_value(): void
    {
        $user = User::factory()->create();

        $entry = $this->service->createByUser($user->id, 'preference', 'Always use 24-hour time format');

        $newContent = 'Always use 12-hour time format';
        $updated = $this->service->updateByUser($user->id, $entry->id, $newContent);

        $this->assertEquals($newContent, $updated->content);
        $this->assertEquals($entry->id, $updated->id);

        // Verify recall returns the new value
        $recalled = $this->service->recall($user->id);
        $recalledContent = $recalled['entries']->firstWhere('id', $entry->id)->content;
        $this->assertEquals($newContent, $recalledContent);
    }

    #[Test]
    public function update_throws_for_missing_id(): void
    {
        $user = User::factory()->create();

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->service->updateByUser($user->id, (string) Str::uuid(), 'New content');
    }

    /* -----------------------------------------------------------------
     * Delete Tests
     * ----------------------------------------------------------------- */

    #[Test]
    public function delete_removes_entry_and_it_does_not_resurface(): void
    {
        $user = User::factory()->create();

        $entry = $this->service->createByUser($user->id, 'fact', 'Temporary fact');
        $entryId = $entry->id;

        $result = $this->service->delete($user->id, $entryId);
        $this->assertTrue($result);

        // Verify entry is gone from the DB
        $this->assertNull(DeclarativeMemory::withoutGlobalScope('user')->find($entryId));

        // Verify list no longer returns it
        $paginator = $this->service->list($user->id);
        $ids = [];
        foreach ($paginator->items() as $e) {
            $ids[] = $e->id;
        }
        $this->assertNotContains($entryId, $ids);
    }

    #[Test]
    public function delete_throws_for_non_owned_entry(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $entry = $this->service->createByUser($owner->id, 'fact', "Owner's secret fact");

        // Other user attempts to delete
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->service->delete($otherUser->id, $entry->id);

        // Entry still exists for the owner
        $this->assertNotNull(DeclarativeMemory::withoutGlobalScope('user')->find($entry->id));
    }

    #[Test]
    public function delete_throws_for_missing_id(): void
    {
        $user = User::factory()->create();

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->service->delete($user->id, (string) Str::uuid());
    }

    /* -----------------------------------------------------------------
     * Unified View With Confidence (User Story 1)
     * ----------------------------------------------------------------- */

    #[Test]
    public function list_returns_mixed_sources_with_confidence_levels(): void
    {
        $user = User::factory()->create();

        // User-stated entry (confidence NULL)
        $this->service->createByUser($user->id, 'preference', 'Always use 24-hour time format');

        // Learned patterns with confidence
        $this->service->applyAgentWrite($user->id, 'fact', 'User prefers Python', true, null, 85);
        $this->service->applyAgentWrite($user->id, 'rule', 'Prefers short replies', true, null, 30);

        $paginator = $this->service->list($user->id);
        $this->assertCount(3, $paginator->items());

        $byContent = collect($paginator->items())->keyBy('content');

        $this->assertSame('user_stated', $byContent['Always use 24-hour time format']->source);
        $this->assertNull($byContent['Always use 24-hour time format']->confidence_level);

        $this->assertSame('agent_learned', $byContent['User prefers Python']->source);
        $this->assertSame(85, $byContent['User prefers Python']->confidence_level);

        $this->assertSame('agent_learned', $byContent['Prefers short replies']->source);
        $this->assertSame(30, $byContent['Prefers short replies']->confidence_level);
    }

    /* -----------------------------------------------------------------
     * Remove Learned Patterns (User Story 2)
     * ----------------------------------------------------------------- */

    #[Test]
    public function learned_pattern_is_deleted_via_standard_remove_control(): void
    {
        $user = User::factory()->create();

        $learned = $this->service->applyAgentWrite($user->id, 'fact', 'User prefers Python', true, null, 85);
        $this->assertSame('agent_learned', $learned->source);

        // The same delete() path used for user-stated entries removes learned patterns
        $result = $this->service->delete($user->id, $learned->id);
        $this->assertTrue($result);

        $this->assertNull(DeclarativeMemory::withoutGlobalScope('user')->find($learned->id));

        $paginator = $this->service->list($user->id);
        $this->assertEmpty($paginator->items());
    }

    /* -----------------------------------------------------------------
     * Empty Store (User Story 6) & Edge Cases
     * ----------------------------------------------------------------- */

    #[Test]
    public function empty_store_list_returns_empty_array_without_error(): void
    {
        $user = User::factory()->create();

        $paginator = $this->service->list($user->id);

        $this->assertIsArray($paginator->items());
        $this->assertCount(0, $paginator->items());
        $this->assertEquals(0, $paginator->total());
    }

    #[Test]
    public function removing_all_entries_behaves_like_new_user(): void
    {
        $user = User::factory()->create();

        $a = $this->service->createByUser($user->id, 'fact', 'Fact A');
        $b = $this->service->applyAgentWrite($user->id, 'preference', 'Learned preference', true, null, 50);

        $this->service->delete($user->id, $a->id);
        $this->service->delete($user->id, $b->id);

        // Store is now empty — same observable state as a brand-new user
        $paginator = $this->service->list($user->id);
        $this->assertCount(0, $paginator->items());
        $this->assertEquals(0, $paginator->total());

        $recalled = $this->service->recall($user->id);
        $this->assertCount(0, $recalled['entries']);
    }
}
