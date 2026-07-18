<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * Value object representing the result of an auto memory retrieval operation.
 *
 * Contains the collection of memory hits, token budget info, and any degradation events.
 * Mutable by design — the retriever accumulates hits and events during the pipeline.
 */
final class MemoryRetrievalResult
{
    /**
     * @param list<MemoryHit> $hits Retrieved memory hits
     * @param int $totalTokens Total token count of all hit content
     * @param list<string> $degradationEvents Events where a store was skipped or degraded
     * @param float $retrievalTime Retrieval duration in milliseconds
     * @param bool $truncated Whether entries were dropped due to budget overflow
     */
    public function __construct(
        public array $hits = [],
        public int $totalTokens = 0,
        public array $degradationEvents = [],
        public float $retrievalTime = 0.0,
        public bool $truncated = false,
    ) {}

    /**
     * Add a memory hit to the result.
     */
    public function addHit(MemoryHit $hit): void
    {
        $this->hits[] = $hit;
        $this->totalTokens += $hit->estimatedTokens();
    }

    /**
     * Add a degradation event description.
     */
    public function addDegradationEvent(string $event): void
    {
        $this->degradationEvents[] = $event;
    }

    /**
     * Check if no hits were retrieved.
     */
    public function isEmpty(): bool
    {
        return $this->hits === [];
    }

    /**
     * Get hits filtered by source.
     *
     * @return list<MemoryHit>
     */
    public function hitsBySource(string $source): array
    {
        return array_values(array_filter($this->hits, fn (MemoryHit $h) => $h->source === $source));
    }

    /**
     * Get hits filtered by type.
     *
     * @return list<MemoryHit>
     */
    public function hitsByType(string $type): array
    {
        return array_values(array_filter($this->hits, fn (MemoryHit $h) => $h->type === $type));
    }

    /**
     * Set retrieval time (called at the end of the pipeline).
     */
    public function setRetrievalTime(float $ms): void
    {
        $this->retrievalTime = $ms;
    }

    /**
     * Recalculate total tokens from current hits.
     */
    public function recalculateTokens(): void
    {
        $this->totalTokens = 0;
        foreach ($this->hits as $hit) {
            $this->totalTokens += $hit->estimatedTokens();
        }
    }
}
