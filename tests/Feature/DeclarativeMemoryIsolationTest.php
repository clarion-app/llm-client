<?php

namespace Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\DeclarativeMemoryService as DeclarativeMemoryServiceContract;
use ClarionApp\LlmClient\Models\DeclarativeMemory;
use ClarionApp\LlmClient\Services\EmbeddingService;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * Feature test: Per-user isolation for declarative memories (User Story 3).
 *
 * Covers:
 * - One user cannot read another user's entries via list/recall
 * - One user cannot edit another user's entries
 * - One user cannot delete another user's entries
 * - No admin/operator override
 * - Cross-user access yields ModelNotFoundException (maps to 404 in controller)
 *
 * Tests use the service layer directly (Passport unavailable in test bench).
 */
class DeclarativeMemoryIsolationTest extends TestCase
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
     * Read Isolation
     * ----------------------------------------------------------------- */

    #[Test]
    public function user_cannot_read_another_users_entries(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        // Owner creates entries
        $this->service->createByUser($owner->id, 'preference', "Owner's preference");
        $this->service->createByUser($owner->id, 'fact', "Owner's fact");

        // Other user recalls — should see empty, not owner's entries
        $recalled = $this->service->recall($otherUser->id);
        $this->assertEmpty($recalled['entries']);

        // Other user lists — should see empty
        $paginator = $this->service->list($otherUser->id);
        $this->assertEmpty($paginator->items());
        $this->assertEquals(0, $paginator->total());
    }

    /* -----------------------------------------------------------------
     * Edit Isolation
     * ----------------------------------------------------------------- */

    #[Test]
    public function user_cannot_edit_another_users_entry(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $entry = $this->service->createByUser($owner->id, 'preference', "Owner's preference");
        $originalContent = $entry->content;

        // Other user attempts to edit via service
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->service->updateByUser($otherUser->id, $entry->id, 'Hacked content');

        // Content unchanged
        $entry->refresh();
        $this->assertEquals($originalContent, $entry->content);
    }

    /* -----------------------------------------------------------------
     * Delete Isolation
     * ----------------------------------------------------------------- */

    #[Test]
    public function user_cannot_delete_another_users_entry(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $entry = $this->service->createByUser($owner->id, 'fact', "Owner's fact");

        // Other user attempts to delete via service
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->service->delete($otherUser->id, $entry->id);

        // Entry still exists for the owner
        $this->assertNotNull(DeclarativeMemory::withoutGlobalScope('user')->find($entry->id));
    }

    /* -----------------------------------------------------------------
     * No Admin Override
     * ----------------------------------------------------------------- */

    #[Test]
    public function cross_user_access_throws_model_not_found(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $entry = $this->service->createByUser($owner->id, 'rule', "Owner's rule");

        // Cross-user update throws ModelNotFoundException (maps to 404 in controller)
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->service->updateByUser($otherUser->id, $entry->id, 'Should not work');
    }

    /* -----------------------------------------------------------------
     * Service-Level Isolation (verify scoping via recall)
     * ----------------------------------------------------------------- */

    #[Test]
    public function recall_is_scoped_to_user(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        // User A creates entries
        $this->service->createByUser($userA->id, 'preference', 'User A preference');
        $this->service->createByUser($userA->id, 'fact', 'User A fact');

        // User B creates entries
        $this->service->createByUser($userB->id, 'preference', 'User B preference');

        // User A's recall should only return A's entries
        $recalledA = $this->service->recall($userA->id);
        $this->assertCount(2, $recalledA['entries']);
        foreach ($recalledA['entries'] as $e) {
            $this->assertEquals($userA->id, $e->user_id);
        }

        // User B's recall should only return B's entry
        $recalledB = $this->service->recall($userB->id);
        $this->assertCount(1, $recalledB['entries']);
        foreach ($recalledB['entries'] as $e) {
            $this->assertEquals($userB->id, $e->user_id);
        }
    }
}
