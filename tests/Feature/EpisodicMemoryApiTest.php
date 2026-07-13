<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\EpisodicMemory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * Feature tests for EpisodicMemory API endpoints.
 *
 * Tests GET list, POST search, PATCH protect, DELETE endpoints
 * with proper authentication and user scoping.
 */
class EpisodicMemoryApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Load llm-client migrations (parent::defineDatabaseMigrations() already creates users table)
        $this->loadMigrationsFrom(__DIR__.'/../src/Migrations');

        $this->user = User::factory()->create();
    }

    #[Test]
    public function test_get_list_returns_paged_memories(): void
    {
        // Create 25 test memories
        for ($i = 0; $i < 25; $i++) {
            EpisodicMemory::create([
                'id' => (string) Str::uuid(),
                'user_id' => $this->user->id,
                'conversation_id' => (string) Str::uuid(),
                'summary' => "Test memory #{$i}",
                'topics' => ["topic-{$i}"],
                'protected' => false,
                'word_count' => 100,
                'summary_word_count' => 10,
            ]);
        }

        $response = $this->actingAs($this->user)->getJson('/api/clarion-app/llm-client/episodic-memories?page=1&per_page=10');

        $response->assertStatus(200)
            ->assertJsonCount(10, 'data')
            ->assertJsonStructure(['data', 'links', 'meta'])
            ->assertJsonFragment(['current_page' => 1, 'per_page' => 10, 'total' => 25]);
    }

    #[Test]
    public function test_get_list_with_topic_filter(): void
    {
        EpisodicMemory::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'conversation_id' => (string) Str::uuid(),
            'summary' => 'Deployment strategy discussion',
            'topics' => ['deployment', 'strategy'],
            'protected' => false,
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);

        EpisodicMemory::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'conversation_id' => (string) Str::uuid(),
            'summary' => 'Budget planning',
            'topics' => ['budget', 'planning'],
            'protected' => false,
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/clarion-app/llm-client/episodic-memories?topic=deployment');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function test_post_search_with_keyword_mode(): void
    {
        EpisodicMemory::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'conversation_id' => (string) Str::uuid(),
            'summary' => 'Key decision: deployment strategy for Q3',
            'topics' => ['deployment', 'strategy'],
            'protected' => false,
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/clarion-app/llm-client/episodic-memories/search', [
            'query' => 'deployment',
            'mode' => 'keyword',
            'limit' => 10,
        ]);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['mode' => 'keyword']);
    }

    #[Test]
    public function test_post_search_with_empty_query_returns_422(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/clarion-app/llm-client/episodic-memories/search', [
            'query' => '',
            'mode' => 'keyword',
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['error' => 'Query is required']);
    }

    #[Test]
    public function test_post_search_with_limit_exceeds_max_returns_422(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/clarion-app/llm-client/episodic-memories/search', [
            'query' => 'test',
            'mode' => 'keyword',
            'limit' => 101,
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['error' => 'Limit exceeds maximum of 100']);
    }

    #[Test]
    public function test_patch_protect_sets_protection_flag(): void
    {
        $memory = EpisodicMemory::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'conversation_id' => (string) Str::uuid(),
            'summary' => 'Important memory',
            'topics' => ['important'],
            'protected' => false,
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);

        $response = $this->actingAs($this->user)->patchJson(
            "/api/clarion-app/llm-client/episodic-memories/{$memory->id}/protect",
            ['protected' => true]
        );

        $response->assertStatus(200)
            ->assertJsonFragment(['protected' => true]);

        $this->assertDatabaseHas('episodic_memories', [
            'id' => $memory->id,
            'protected' => true,
        ]);
    }

    #[Test]
    public function test_patch_protect_nonexistent_returns_404(): void
    {
        $response = $this->actingAs($this->user)->patchJson(
            "/api/clarion-app/llm-client/episodic-memories/".(string) Str::uuid()."/protect",
            ['protected' => true]
        );

        $response->assertStatus(404);
    }

    #[Test]
    public function test_delete_removes_memory_permanently(): void
    {
        $memory = EpisodicMemory::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'conversation_id' => (string) Str::uuid(),
            'summary' => 'Memory to delete',
            'topics' => ['delete'],
            'protected' => false,
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);

        $response = $this->actingAs($this->user)->deleteJson(
            "/api/clarion-app/llm-client/episodic-memories/{$memory->id}"
        );

        $response->assertStatus(204);

        // Verify the memory was force deleted (immediate permanent removal per FR-012)
        $this->assertDatabaseMissing('episodic_memories', [
            'id' => $memory->id,
        ]);
    }

    #[Test]
    public function test_delete_nonexistent_returns_404(): void
    {
        $response = $this->actingAs($this->user)->deleteJson(
            "/api/clarion-app/llm-client/episodic-memories/".(string) Str::uuid()
        );

        $response->assertStatus(404);
    }

    #[Test]
    public function test_hybrid_search_degrades_to_keyword_when_no_embeddings(): void
    {
        EpisodicMemory::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'conversation_id' => (string) Str::uuid(),
            'summary' => 'Hybrid search test entry',
            'topics' => ['hybrid', 'search'],
            'protected' => false,
            'word_count' => 100,
            'summary_word_count' => 10,
            'embedding' => null,
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/clarion-app/llm-client/episodic-memories/search', [
            'query' => 'hybrid search',
            'mode' => 'hybrid',
        ]);

        // Should degrade to keyword mode and still find results
        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }
}
