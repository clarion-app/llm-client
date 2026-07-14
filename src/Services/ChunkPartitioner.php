<?php

namespace ClarionApp\LlmClient\Services;

class ChunkPartitioner
{
    /**
     * Partition messages into chunks by ordinal using floor(ordinal / chunk_size).
     *
     * Messages are expected to be ordered by created_at. Ordinals are assigned
     * based on position in the sorted array.
     *
     * @param list<array{role: string, content: string, ordinal?: int, created_at: mixed}> $messages
     * @param int $chunkSize Fixed chunk size in turn-units
     * @return list<list<array{role: string, content: string, ordinal: int, created_at: mixed}>>
     */
    public function partition(array $messages, int $chunkSize): array
    {
        if (empty($messages)) {
            return [];
        }

        // Assign ordinals based on created_at order
        $indexed = [];
        foreach ($messages as $i => $msg) {
            $indexed[] = array_merge($msg, ['ordinal' => $msg['ordinal'] ?? $i]);
        }

        // Sort by created_at to ensure deterministic ordering
        usort($indexed, function ($a, $b) {
            $timeA = $a['created_at'] ?? 0;
            $timeB = $b['created_at'] ?? 0;
            if ($timeA instanceof \Carbon\Carbon && $timeB instanceof \Carbon\Carbon) {
                return $timeA->gte($timeB) ? 1 : -1;
            }
            return $timeA <=> $timeB;
        });

        // Re-assign ordinals after sorting
        foreach ($indexed as $i => $msg) {
            $indexed[$i]['ordinal'] = $i;
        }

        // Partition into chunks
        $chunks = [];
        foreach ($indexed as $msg) {
            $chunkIdx = $this->getChunkIndex($msg['ordinal'], $chunkSize);
            $chunks[$chunkIdx][] = $msg;
        }

        return array_values($chunks);
    }

    /**
     * Compute the chunk index for a given ordinal.
     */
    public function getChunkIndex(int $ordinal, int $chunkSize): int
    {
        return (int) floor($ordinal / $chunkSize);
    }

    /**
     * Find which chunks are fully sealed (all member messages are older than the verbatim boundary).
     *
     * The verbatim boundary is the number of NEWEST messages kept as verbatim-recent.
     * Dropped messages are those with ordinal < (totalMessages - verbatimBoundary).
     * A chunk is sealed when its highest-ordinal message is still below the dropped boundary.
     *
     * @param list<array{role: string, content: string, ordinal?: int}> $messages
     * @param int $verbatimBoundary Number of newest messages to keep verbatim
     * @param int $chunkSize Fixed chunk size
     * @return list<int> Sorted list of sealed chunk indices
     */
    public function findSealedChunks(array $messages, int $verbatimBoundary, int $chunkSize): array
    {
        if (empty($messages)) {
            return [];
        }

        $totalMessages = count($messages);
        
        // The cutoff: messages with ordinal < this are "dropped" (older than verbatim)
        $droppedCutoff = $totalMessages - $verbatimBoundary;
        
        if ($droppedCutoff <= 0) {
            return [];
        }

        $maxOrdinal = $totalMessages - 1;
        $maxChunk = $this->getChunkIndex($maxOrdinal, $chunkSize);

        $sealed = [];
        for ($chunkIdx = 0; $chunkIdx <= $maxChunk; $chunkIdx++) {
            $chunkStart = $chunkIdx * $chunkSize;
            $chunkEnd = min($chunkStart + $chunkSize, $totalMessages) - 1;

            // Chunk is sealed if all its messages (highest ordinal) are below the dropped cutoff
            if ($chunkEnd < $droppedCutoff) {
                $sealed[] = $chunkIdx;
            }
        }

        return $sealed;
    }

    /**
     * Compute a deterministic content hash for a chunk's source messages.
     *
     * @param list<array{role: string, content: string, ordinal?: int}> $messages
     * @param int $chunkIndex Which chunk to hash
     * @param int $chunkSize Fixed chunk size
     * @return string SHA-256 hash of the chunk's message contents
     */
    public function computeSourceHash(array $messages, int $chunkIndex, int $chunkSize): string
    {
        $chunkStart = $chunkIndex * $chunkSize;
        $chunkEnd = $chunkStart + $chunkSize;

        $content = '';
        foreach ($messages as $i => $msg) {
            if ($i >= $chunkStart && $i < $chunkEnd) {
                $content .= ($msg['role'] ?? '') . '|' . ($msg['content'] ?? '') . "\n";
            }
        }

        return hash('sha256', $content);
    }
}
