<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\LlmClient\Services\ChunkPartitioner;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ChunkPartitionerTest extends TestCase
{
    private ChunkPartitioner $partitioner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->partitioner = new ChunkPartitioner();
    }

    #[Test]
    public function partitions_messages_into_chunks_by_ordinal(): void
    {
        $messages = $this->createMessages(40);
        $chunks = $this->partitioner->partition($messages, 20);

        $this->assertCount(2, $chunks);
        $this->assertCount(20, $chunks[0]);
        $this->assertCount(20, $chunks[1]);
    }

    #[Test]
    public function chunk_index_is_floor_of_ordinal_divided_by_chunk_size(): void
    {
        $messages = $this->createMessages(65);
        $chunks = $this->partitioner->partition($messages, 20);

        // 65 messages / 20 chunk_size = 4 chunks (3 full + 1 partial)
        $this->assertCount(4, $chunks);
        $this->assertCount(20, $chunks[0]);
        $this->assertCount(20, $chunks[1]);
        $this->assertCount(20, $chunks[2]);
        $this->assertCount(5, $chunks[3]);
    }

    #[Test]
    public function chunk_index_calculation(): void
    {
        $messages = $this->createMessages(10);

        // Message at ordinal 0 should be in chunk 0
        $this->assertEquals(0, $this->partitioner->getChunkIndex(0, 20));
        // Message at ordinal 19 should be in chunk 0
        $this->assertEquals(0, $this->partitioner->getChunkIndex(19, 20));
        // Message at ordinal 20 should be in chunk 1
        $this->assertEquals(1, $this->partitioner->getChunkIndex(20, 20));
        // Message at ordinal 39 should be in chunk 1
        $this->assertEquals(1, $this->partitioner->getChunkIndex(39, 20));
        // Message at ordinal 40 should be in chunk 2
        $this->assertEquals(2, $this->partitioner->getChunkIndex(40, 20));
    }

    #[Test]
    public function detects_sealed_chunks_correctly(): void
    {
        $messages = $this->createMessages(60);

        // verbatimBoundary=20 means the 20 newest messages (40-59) are kept verbatim.
        // Dropped messages are 0-39 (ordinal < 60-20=40).
        // Chunk 0 (ordinal 0-19): highest ordinal 19 < 40 → sealed
        // Chunk 1 (ordinal 20-39): highest ordinal 39 < 40 → sealed
        // Chunk 2 (ordinal 40-59): highest ordinal 59 >= 40 → NOT sealed
        $sealedChunks = $this->partitioner->findSealedChunks($messages, 20, 20);

        $this->assertCount(2, $sealedChunks);
        $this->assertEquals(0, $sealedChunks[0]);
        $this->assertEquals(1, $sealedChunks[1]);
    }

    #[Test]
    public function partial_trailing_chunk_is_not_sealed(): void
    {
        $messages = $this->createMessages(25);

        // verbatimBoundary=5 means the 5 newest messages (20-24) are kept verbatim.
        // Dropped messages are 0-19 (ordinal < 25-5=20).
        // Chunk 0 (ordinal 0-19): highest ordinal 19 < 20 → sealed
        // Chunk 1 (ordinal 20-24): highest ordinal 24 >= 20 → NOT sealed
        $sealedChunks = $this->partitioner->findSealedChunks($messages, 5, 20);

        $this->assertCount(1, $sealedChunks);
        $this->assertEquals(0, $sealedChunks[0]);
    }

    #[Test]
    public function empty_messages_returns_no_chunks(): void
    {
        $chunks = $this->partitioner->partition([], 20);
        $this->assertCount(0, $chunks);
    }

    #[Test]
    public function no_sealed_chunks_when_all_messages_are_verbatim(): void
    {
        $messages = $this->createMessages(10);

        // All 10 messages are within the verbatim boundary of 10
        $sealedChunks = $this->partitioner->findSealedChunks($messages, 10, 20);
        $this->assertCount(0, $sealedChunks);
    }

    #[Test]
    public function boundary_off_by_one_at_chunk_size(): void
    {
        $messages = $this->createMessages(40);

        // Exactly at boundary: message 19 is last in chunk 0, message 20 is first in chunk 1
        $chunks = $this->partitioner->partition($messages, 20);
        $this->assertEquals(19, $chunks[0][19]['ordinal']);
        $this->assertEquals(20, $chunks[1][0]['ordinal']);
    }

    #[Test]
    public function messages_are_ordered_by_created_at(): void
    {
        // Create messages with timestamps in order
        $messages = $this->createMessages(10);
        $chunks = $this->partitioner->partition($messages, 20);

        $this->assertCount(1, $chunks);
        for ($i = 0; $i < 10; $i++) {
            $this->assertEquals($i, $chunks[0][$i]['ordinal']);
        }
    }

    #[Test]
    public function sealed_chunk_source_hash_is_deterministic(): void
    {
        $messages = $this->createMessages(40);

        $hash1 = $this->partitioner->computeSourceHash($messages, 0, 20);
        $hash2 = $this->partitioner->computeSourceHash($messages, 0, 20);

        $this->assertEquals($hash1, $hash2);

        // Different chunk should have different hash
        $hash3 = $this->partitioner->computeSourceHash($messages, 1, 20);
        $this->assertNotEquals($hash1, $hash3);
    }

    private function createMessages(int $count): array
    {
        $messages = [];
        for ($i = 0; $i < $count; $i++) {
            $messages[] = [
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => "Message content {$i}",
                'ordinal' => $i,
                'created_at' => \Carbon\Carbon::now()->addSeconds($i),
            ];
        }
        return $messages;
    }
}
