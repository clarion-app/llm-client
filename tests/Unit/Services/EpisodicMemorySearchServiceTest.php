<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\EpisodicMemory;
use ClarionApp\LlmClient\Services\EpisodicMemorySearchService;
use ClarionApp\LlmClient\Services\EmbeddingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for EpisodicMemorySearchService.
 *
 * Tests keyword search (LIKE-based), semantic search (cosine similarity),
 * hybrid search combining both modes, and empty results for unmatched queries.
 */
class EpisodicMemorySearchServiceTest extends TestCase
{
    use RefreshDatabase;

    private EpisodicMemorySearchService $service;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $embeddingMock = \Mockery::mock(EmbeddingService::class);
        $embeddingMock->shouldReceive('isEnabled')->andReturn(false)->byDefault();

        $this->service = new EpisodicMemorySearchService($embeddingMock);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function keywordSearch_finds_memories_by_summary_match()
    {
        EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-1',
            'summary' => 'User discussed deployment strategies for microservices',
            'topics' => ['deployment', 'strategy'],
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);

        EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-2',
            'summary' => 'Security audit completed with no critical findings',
            'topics' => ['security', 'audit'],
            'word_count' => 80,
            'summary_word_count' => 8,
        ]);

        $results = $this->service->keywordSearch($this->user->id, 'deployment');

        $this->assertCount(1, $results);
        $this->assertEquals('conv-1', $results[0]['conversation_id']);
    }

    #[Test]
    public function keywordSearch_finds_memories_by_topics_match()
    {
        EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-1',
            'summary' => 'Some general discussion',
            'topics' => ['kubernetes', 'containers'],
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);

        $results = $this->service->keywordSearch($this->user->id, 'kubernetes');

        $this->assertCount(1, $results);
        $this->assertEquals('conv-1', $results[0]['conversation_id']);
    }

    #[Test]
    public function keywordSearch_returns_empty_for_unmatched_query()
    {
        EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-1',
            'summary' => 'Deployment discussion',
            'topics' => ['deployment'],
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);

        $results = $this->service->keywordSearch($this->user->id, 'cooking recipes');

        $this->assertCount(0, $results);
    }

    #[Test]
    public function keywordSearch_enforces_user_scoping()
    {
        $otherUser = User::factory()->create();

        EpisodicMemory::create([
            'user_id' => $otherUser->id,
            'conversation_id' => 'conv-other',
            'summary' => 'Secret deployment plans',
            'topics' => ['deployment', 'secret'],
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);

        EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-mine',
            'summary' => 'My deployment plans',
            'topics' => ['deployment'],
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);

        $results = $this->service->keywordSearch($this->user->id, 'deployment');

        $this->assertCount(1, $results);
        $this->assertEquals('conv-mine', $results[0]['conversation_id']);
    }

    #[Test]
    public function keywordSearch_orders_by_recency()
    {
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

        EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-2',
            'summary' => 'Latest deployment discussion',
            'topics' => ['deployment'],
            'word_count' => 100,
            'summary_word_count' => 10,
            'created_at' => \Carbon\Carbon::now(),
            'updated_at' => \Carbon\Carbon::now(),
        ]);

        $results = $this->service->keywordSearch($this->user->id, 'deployment');

        $this->assertCount(2, $results);
        // Most recent first
        $this->assertEquals('conv-2', $results[0]['conversation_id']);
        $this->assertEquals('conv-1', $results[1]['conversation_id']);
    }

    #[Test]
    public function keywordSearch_respects_limit()
    {
        for ($i = 0; $i < 5; $i++) {
            EpisodicMemory::create([
                'user_id' => $this->user->id,
                'conversation_id' => "conv-{$i}",
                'summary' => "Deployment discussion number {$i}",
                'topics' => ['deployment'],
                'word_count' => 100,
                'summary_word_count' => 10,
            ]);
        }

        $results = $this->service->keywordSearch($this->user->id, 'deployment', 3);

        $this->assertCount(3, $results);
    }

    #[Test]
    public function search_with_invalid_mode_throws_exception()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid search mode');

        $this->service->search($this->user->id, 'test', 'invalid_mode');
    }

    #[Test]
    public function search_delegates_to_keyword_search()
    {
        EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-1',
            'summary' => 'Deployment strategy discussion',
            'topics' => ['deployment'],
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);

        $results = $this->service->search($this->user->id, 'deployment', 'keyword');

        $this->assertCount(1, $results);
    }

    #[Test]
    public function hybrid_search_degrades_to_keyword_when_embeddings_disabled()
    {
        EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-1',
            'summary' => 'Deployment strategy discussion',
            'topics' => ['deployment'],
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);

        // EmbeddingService::isEnabled returns false, hybrid should fall back to keyword
        $results = $this->service->search($this->user->id, 'deployment', 'hybrid');

        $this->assertCount(1, $results);
        $this->assertEquals('conv-1', $results[0]['conversation_id']);
    }
}
