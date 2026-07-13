<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\EpisodicMemory;
use ClarionApp\LlmClient\Services\EpisodicMemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;

use PHPUnit\Framework\Attributes\Test;

/**
 * Feature tests for Episodic Memory Recall.
 *
 * Seeds multiple EpisodicMemory entries with overlapping topics,
 * verifies recall returns most recent entry per topic,
 * and verifies unrelated topics are excluded.
 */
class EpisodicMemoryRecallTest extends TestCase
{
    use RefreshDatabase;

    private EpisodicMemoryService $service;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(EpisodicMemoryService::class);
        $this->user = User::factory()->create();
    }

    #[Test]
    public function recall_returns_most_recent_entry_per_overlapping_topic()
    {
        // Create multiple memories with overlapping "deployment" topic
        EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-1',
            'summary' => 'Initial brainstorming on deployment approaches',
            'topics' => ['deployment', 'brainstorming'],
            'word_count' => 200,
            'summary_word_count' => 20,
            'created_at' => \Carbon\Carbon::now()->subDays(14),
            'updated_at' => \Carbon\Carbon::now()->subDays(14),
        ]);

        EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-2',
            'summary' => 'Research on canary deployment tools and best practices',
            'topics' => ['deployment', 'canary', 'research'],
            'word_count' => 150,
            'summary_word_count' => 15,
            'created_at' => \Carbon\Carbon::now()->subDays(7),
            'updated_at' => \Carbon\Carbon::now()->subDays(7),
        ]);

        EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-3',
            'summary' => 'Final decision: adopt canary deployment for production',
            'topics' => ['deployment', 'decision', 'canary'],
            'word_count' => 180,
            'summary_word_count' => 18,
            'created_at' => \Carbon\Carbon::now()->subDays(1),
            'updated_at' => \Carbon\Carbon::now()->subDays(1),
        ]);

        // Recall by "deployment" topic
        $results = $this->service->recall($this->user->id, 'deployment');

        // Should return entries ordered by recency, with deduplication by topic fingerprint
        $this->assertGreaterThanOrEqual(1, count($results));

        // Most recent should be first
        $this->assertEquals('conv-3', $results[0]->conversation_id);
        $this->assertStringContainsString('Final decision', $results[0]->summary);
    }

    #[Test]
    public function recall_excludes_unrelated_topics()
    {
        // Create memories with distinct topics
        EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-deploy',
            'summary' => 'Deployment strategy discussion',
            'topics' => ['deployment', 'kubernetes'],
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);

        EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-security',
            'summary' => 'Security audit review',
            'topics' => ['security', 'audit'],
            'word_count' => 80,
            'summary_word_count' => 8,
        ]);

        EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-hiring',
            'summary' => 'Hiring plan for Q2',
            'topics' => ['hiring', 'team'],
            'word_count' => 60,
            'summary_word_count' => 6,
        ]);

        // Recall by "deployment" - should NOT include security or hiring
        $results = $this->service->recall($this->user->id, 'deployment');

        $this->assertCount(1, $results);
        $this->assertEquals('conv-deploy', $results[0]->conversation_id);
    }

    #[Test]
    public function recall_handles_multiple_users_independently()
    {
        // Create another user with overlapping topics
        $otherUser = User::factory()->create();

        EpisodicMemory::create([
            'user_id' => $otherUser->id,
            'conversation_id' => 'conv-other-deploy',
            'summary' => 'Other user deployment decision',
            'topics' => ['deployment', 'production'],
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);

        EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-my-deploy',
            'summary' => 'My deployment decision',
            'topics' => ['deployment', 'staging'],
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);

        // Recall as current user - should not see other user's memories
        $results = $this->service->recall($this->user->id, 'deployment');

        $this->assertCount(1, $results);
        $this->assertEquals('conv-my-deploy', $results[0]->conversation_id);
    }

    #[Test]
    public function recall_finds_memories_by_summary_keyword_match()
    {
        EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-1',
            'summary' => 'User decided to migrate from MySQL to PostgreSQL for better JSON support',
            'topics' => ['database', 'migration'],
            'word_count' => 150,
            'summary_word_count' => 15,
        ]);

        // Search for "PostgreSQL" which is only in the summary, not topics
        $results = $this->service->recall($this->user->id, 'PostgreSQL');

        $this->assertCount(1, $results);
        $this->assertEquals('conv-1', $results[0]->conversation_id);
    }

    #[Test]
    public function recall_returns_empty_for_user_with_no_memories()
    {
        $results = $this->service->recall($this->user->id, 'nonexistent-topic');

        $this->assertCount(0, $results);
        $this->assertIsArray($results);
    }

    #[Test]
    public function recall_excludes_soft_deleted_memories()
    {
        $memory = EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-1',
            'summary' => 'This memory was deleted',
            'topics' => ['deployment', 'deleted'],
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);

        // Soft delete the memory
        $memory->delete();

        // Recall should not return soft-deleted memories
        $results = $this->service->recall($this->user->id, 'deployment');

        $this->assertCount(0, $results);
    }
}
