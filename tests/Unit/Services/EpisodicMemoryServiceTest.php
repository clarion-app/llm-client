<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\EpisodicMemory;
use ClarionApp\LlmClient\Services\EpisodicMemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;

use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for EpisodicMemoryService::recall().
 *
 * Tests topic-based recall, most-recent-wins conflict resolution,
 * fuzzy topic overlap, and empty results when no relevant memories exist.
 */
class EpisodicMemoryServiceTest extends TestCase
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
    public function recall_returns_memories_matching_topic_in_topics_array()
    {
        // Create memories with different topics
        EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-1',
            'summary' => 'Discussed deployment strategies',
            'topics' => ['deployment', 'kubernetes', 'strategy'],
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);

        EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-2',
            'summary' => 'Reviewed security audit findings',
            'topics' => ['security', 'audit', 'compliance'],
            'word_count' => 80,
            'summary_word_count' => 8,
        ]);

        // Search for "deployment" topic
        $results = $this->service->recall($this->user->id, 'deployment');

        // Should only return the first memory
        $this->assertCount(1, $results);
        $this->assertEquals('conv-1', $results[0]->conversation_id);
    }

    #[Test]
    public function recall_returns_memories_matching_topic_in_summary_text()
    {
        EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-1',
            'summary' => 'User agreed to use canary deployments for the production rollout',
            'topics' => ['decisions', 'agreements'],
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);

        EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-2',
            'summary' => 'General discussion about project timelines',
            'topics' => ['scheduling', 'timelines'],
            'word_count' => 50,
            'summary_word_count' => 5,
        ]);

        // Search for "canary" which is only in the summary text
        $results = $this->service->recall($this->user->id, 'canary');

        $this->assertCount(1, $results);
        $this->assertEquals('conv-1', $results[0]->conversation_id);
    }

    #[Test]
    public function recall_returns_most_recent_entry_per_topic()
    {
        // Create older memory with deployment topic
        $older = EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-1',
            'summary' => 'Initial deployment discussion',
            'topics' => ['deployment', 'kubernetes'],
            'word_count' => 100,
            'summary_word_count' => 10,
            'created_at' => \Carbon\Carbon::now()->subDays(7),
            'updated_at' => \Carbon\Carbon::now()->subDays(7),
        ]);

        // Create newer memory with overlapping deployment topic
        $newer = EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-2',
            'summary' => 'Final deployment decision on canary approach',
            'topics' => ['deployment', 'canary', 'decision'],
            'word_count' => 120,
            'summary_word_count' => 12,
            'created_at' => \Carbon\Carbon::now()->subDays(1),
            'updated_at' => \Carbon\Carbon::now()->subDays(1),
        ]);

        // Search for "deployment"
        $results = $this->service->recall($this->user->id, 'deployment');

        // Should return both (different topic fingerprints) but most recent first
        $this->assertGreaterThanOrEqual(1, count($results));
        // First result should be the newer one
        $this->assertEquals('conv-2', $results[0]->conversation_id);
    }

    #[Test]
    public function recall_handles_fuzzy_topic_overlap()
    {
        // Memory with "deployment strategy" topics
        EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-1',
            'summary' => 'Discussed deployment strategy for microservices',
            'topics' => ['deployment', 'strategy', 'microservices'],
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);

        // Memory with "deployment plan" topics (overlapping "deployment")
        EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-2',
            'summary' => 'Created deployment plan for Q1 release',
            'topics' => ['deployment', 'planning', 'releases'],
            'word_count' => 80,
            'summary_word_count' => 8,
        ]);

        // Search for "deployment" - should match both
        $results = $this->service->recall($this->user->id, 'deployment');

        // Both should be returned (different topic fingerprints)
        $this->assertCount(2, $results);
    }

    #[Test]
    public function recall_returns_empty_when_no_relevant_memories()
    {
        EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-1',
            'summary' => 'Discussed deployment strategies',
            'topics' => ['deployment', 'kubernetes'],
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);

        // Search for a topic that doesn't match
        $results = $this->service->recall($this->user->id, 'cooking recipes');

        $this->assertCount(0, $results);
    }

    #[Test]
    public function recall_returns_empty_when_user_has_no_memories()
    {
        $results = $this->service->recall($this->user->id, 'deployment');

        $this->assertCount(0, $results);
    }

    #[Test]
    public function recall_enforces_user_scoping()
    {
        // Create memory for another user
        $otherUser = User::factory()->create();
        EpisodicMemory::create([
            'user_id' => $otherUser->id,
            'conversation_id' => 'conv-other',
            'summary' => 'Secret deployment discussion',
            'topics' => ['deployment', 'secret'],
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);

        // Create memory for current user
        EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-mine',
            'summary' => 'My deployment discussion',
            'topics' => ['deployment', 'personal'],
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);

        // Search as current user
        $results = $this->service->recall($this->user->id, 'deployment');

        // Should only return current user's memories
        $this->assertCount(1, $results);
        $this->assertEquals('conv-mine', $results[0]->conversation_id);
    }

    #[Test]
    public function recall_orders_results_by_recency()
    {
        // Create oldest memory
        EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-1',
            'summary' => 'First deployment discussion',
            'topics' => ['deployment'],
            'word_count' => 100,
            'summary_word_count' => 10,
            'created_at' => \Carbon\Carbon::now()->subDays(14),
            'updated_at' => \Carbon\Carbon::now()->subDays(14),
        ]);

        // Create middle memory
        EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-2',
            'summary' => 'Second deployment discussion',
            'topics' => ['deployment', 'review'],
            'word_count' => 100,
            'summary_word_count' => 10,
            'created_at' => \Carbon\Carbon::now()->subDays(7),
            'updated_at' => \Carbon\Carbon::now()->subDays(7),
        ]);

        // Create newest memory
        EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-3',
            'summary' => 'Latest deployment discussion',
            'topics' => ['deployment', 'final'],
            'word_count' => 100,
            'summary_word_count' => 10,
            'created_at' => \Carbon\Carbon::now(),
            'updated_at' => \Carbon\Carbon::now(),
        ]);

        $results = $this->service->recall($this->user->id, 'deployment');

        // All should be returned ordered by recency (newest first)
        $this->assertCount(3, $results);
        $this->assertEquals('conv-3', $results[0]->conversation_id);
        $this->assertEquals('conv-2', $results[1]->conversation_id);
        $this->assertEquals('conv-1', $results[2]->conversation_id);
    }
}
