<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\EpisodicMemory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * Security tests for EpisodicMemory cross-user isolation.
 *
 * Verifies that User A cannot list, search, protect, or delete
 * episodic memories belonging to User B.
 */
class EpisodicMemoryIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $userA;
    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        // Load llm-client migrations (parent::defineDatabaseMigrations() already creates users table)
        $this->loadMigrationsFrom(__DIR__.'/../src/Migrations');

        $this->userA = User::factory()->create();
        $this->userB = User::factory()->create();
    }

    #[Test]
    public function test_user_a_cannot_list_user_b_memories(): void
    {
        // Create memory for User B
        EpisodicMemory::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->userB->id,
            'conversation_id' => (string) Str::uuid(),
            'summary' => 'User B private memory',
            'topics' => ['private'],
            'protected' => false,
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);

        // User A attempts to list memories
        $response = $this->actingAs($this->userA)->getJson('/api/clarion-app/llm-client/episodic-memories');

        // Should return empty list (no User B memories visible)
        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function test_user_a_cannot_search_user_b_memories(): void
    {
        // Create memory for User B with searchable content
        EpisodicMemory::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->userB->id,
            'conversation_id' => (string) Str::uuid(),
            'summary' => 'User B secret deployment plan',
            'topics' => ['deployment', 'secret'],
            'protected' => false,
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);

        // User A attempts to search for the keyword
        $response = $this->actingAs($this->userA)->postJson('/api/clarion-app/llm-client/episodic-memories/search', [
            'query' => 'deployment',
            'mode' => 'keyword',
        ]);

        // Should return empty results (User B memory not visible)
        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function test_user_a_cannot_protect_user_b_memory(): void
    {
        // Create memory for User B
        $memoryB = EpisodicMemory::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->userB->id,
            'conversation_id' => (string) Str::uuid(),
            'summary' => 'User B memory',
            'topics' => ['test'],
            'protected' => false,
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);

        // User A attempts to protect User B's memory
        $response = $this->actingAs($this->userA)->patchJson(
            "/api/clarion-app/llm-client/episodic-memories/{$memoryB->id}/protect",
            ['protected' => true]
        );

        // Should return 404 (memory not found for this user)
        $response->assertStatus(404);

        // Verify protection status unchanged
        $this->assertDatabaseHas('episodic_memories', [
            'id' => $memoryB->id,
            'protected' => false,
        ]);
    }

    #[Test]
    public function test_user_a_cannot_delete_user_b_memory(): void
    {
        // Create memory for User B
        $memoryB = EpisodicMemory::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->userB->id,
            'conversation_id' => (string) Str::uuid(),
            'summary' => 'User B memory',
            'topics' => ['test'],
            'protected' => false,
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);

        // User A attempts to delete User B's memory
        $response = $this->actingAs($this->userA)->deleteJson(
            "/api/clarion-app/llm-client/episodic-memories/{$memoryB->id}"
        );

        // Should return 404 (memory not found for this user)
        $response->assertStatus(404);

        // Verify memory still exists
        $this->assertDatabaseHas('episodic_memories', [
            'id' => $memoryB->id,
        ]);
    }

    #[Test]
    public function test_each_user_only_sees_their_own_memories(): void
    {
        // Create memories for both users
        EpisodicMemory::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->userA->id,
            'conversation_id' => (string) Str::uuid(),
            'summary' => 'User A memory',
            'topics' => ['user-a'],
            'protected' => false,
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);

        EpisodicMemory::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->userB->id,
            'conversation_id' => (string) Str::uuid(),
            'summary' => 'User B memory',
            'topics' => ['user-b'],
            'protected' => false,
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);

        // User A should only see their own memory
        $responseA = $this->actingAs($this->userA)->getJson('/api/clarion-app/llm-client/episodic-memories');
        $responseA->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['summary' => 'User A memory']);

        // User B should only see their own memory
        $responseB = $this->actingAs($this->userB)->getJson('/api/clarion-app/llm-client/episodic-memories');
        $responseB->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['summary' => 'User B memory']);
    }
}
