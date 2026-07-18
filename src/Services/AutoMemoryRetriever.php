<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Contracts\DeclarativeMemoryService as DeclarativeMemoryServiceContract;
use ClarionApp\LlmClient\Contracts\MemoryScope;
use ClarionApp\LlmClient\Contracts\MemoryService as MemoryServiceContract;
use ClarionApp\LlmClient\Exceptions\SemanticSearchException;
use ClarionApp\LlmClient\Models\DeclarativeMemory;
use ClarionApp\LlmClient\ValueObjects\MemoryHit;
use ClarionApp\LlmClient\ValueObjects\MemoryInjectionSection;
use ClarionApp\LlmClient\ValueObjects\MemoryRetrievalResult;
use Illuminate\Support\Facades\Log;

/**
 * AutoMemoryRetriever — orchestrates multi-store memory retrieval.
 *
 * On each turn this service:
 * 1. Generates a single query embedding (reused across all stores)
 * 2. Fans out to declarative, episodic, and long-term memory stores
 * 3. Normalizes scores, filters by relevance threshold, sorts by priority
 * 4. Enforces a token budget with whole-entry truncation
 * 5. Formats the result as a markdown injection section
 *
 * Graceful degradation: each store is wrapped in try-catch so a failure
 * in one store does not block the others. Degradation events are tracked.
 */
class AutoMemoryRetriever
{
    /**
     * Per-turn memo cache. Keyed by "{conversation_id}:{last_user_message_id}".
     * Request-scoped only — no Laravel Cache (avoids cross-request leakage).
     */
    private array $turnCache = [];

    public function __construct(
        private readonly DeclarativeMemoryServiceContract $declarativeMemoryService,
        private readonly EpisodicMemorySearchService $episodicMemorySearchService,
        private readonly MemoryServiceContract $memoryService,
        private readonly EmbeddingService $embeddingService,
        private readonly PreferenceInjector $preferenceInjector,
        private readonly ?MetricsRecorder $metricsRecorder = null,
    ) {}

    /**
     * Check if auto-retrieval is enabled via config.
     */
    public function isEnabled(): bool
    {
        return config('llm-client.auto_memory_retrieval.enabled', true) === true;
    }

    /**
     * Retrieve relevant memories for a given turn.
     *
     * @param string $turnKey Unique key for this turn (e.g., "conversation_id:message_id")
     * @param string $userId The user ID to retrieve memories for
     * @param string $agentId The agent/character ID (for long-term memory scope)
     * @param string $query The user's current message text (used for relevance scoring)
     * @return MemoryRetrievalResult
     */
    public function retrieve(string $turnKey, string $userId, string $agentId, string $query): MemoryRetrievalResult
    {
        $startTime = microtime(true);

        // T011b: Check turn cache first (per-turn memo)
        if (isset($this->turnCache[$turnKey])) {
            $cached = $this->turnCache[$turnKey];
            $cached->setRetrievalTime((microtime(true) - $startTime) * 1000);
            return $cached;
        }

        $result = new MemoryRetrievalResult();

        // Get config values
        $maxTokens = (int) config('llm-client.auto_memory_retrieval.max_tokens', 4096);
        $maxChars = $maxTokens * 4;
        $relevanceThreshold = (float) config('llm-client.auto_memory_retrieval.relevance_threshold', 0.3);
        $maxResultsPerStore = (int) config('llm-client.auto_memory_retrieval.max_results_per_store', 10);
        $stores = config('llm-client.auto_memory_retrieval.stores', ['declarative', 'episodic', 'long-term']);

        // T011: Generate a single query embedding (reused across all stores)
        $queryEmbedding = null;
        try {
            if ($this->embeddingService->isEnabled()) {
                $queryEmbedding = $this->embeddingService->generate($query);
            }
        } catch (\RuntimeException $e) {
            // Embedding generation failed — track as degradation event
            // Rules will still be retrieved (unconditional), other stores will degrade
            $result->addDegradationEvent(sprintf('embedding_generation_failed: %s', $e->getMessage()));
            Log::warning('AutoMemoryRetriever: embedding generation failed', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
            ]);
        }

        // T012: Declarative retrieval
        if (in_array('declarative', $stores, true)) {
            try {
                $this->retrieveDeclarative($result, $userId, $queryEmbedding, $relevanceThreshold, $maxResultsPerStore);
            } catch (\RuntimeException | \InvalidArgumentException $e) {
                $result->addDegradationEvent(sprintf('declarative_retrieval_failed: %s', $e->getMessage()));
                Log::warning('AutoMemoryRetriever: declarative retrieval failed', [
                    'error' => $e->getMessage(),
                    'user_id' => $userId,
                ]);
            }
        }

        // T013: Episodic retrieval
        if (in_array('episodic', $stores, true)) {
            // Pre-stage budget gate: skip if budget already spent
            if ($this->getUsedChars($result) < $maxChars || count($result->hits) === 0) {
                try {
                    $this->retrieveEpisodic($result, $userId, $query, $queryEmbedding, $relevanceThreshold, $maxResultsPerStore);
                } catch (\RuntimeException | \InvalidArgumentException $e) {
                    $result->addDegradationEvent(sprintf('episodic_retrieval_failed: %s', $e->getMessage()));
                    Log::warning('AutoMemoryRetriever: episodic retrieval failed', [
                        'error' => $e->getMessage(),
                        'user_id' => $userId,
                    ]);
                }
            }
        }

        // T014: Long-term retrieval
        if (in_array('long-term', $stores, true)) {
            // Pre-stage budget gate: skip if budget already spent
            if ($this->getUsedChars($result) < $maxChars || count($result->hits) === 0) {
                try {
                    $this->retrieveLongTerm($result, $agentId, $query, $queryEmbedding, $relevanceThreshold, $maxResultsPerStore);
                } catch (SemanticSearchException $e) {
                    $result->addDegradationEvent(sprintf('long_term_retrieval_failed: %s', $e->getMessage()));
                    Log::warning('AutoMemoryRetriever: long-term retrieval failed', [
                        'error' => $e->getMessage(),
                        'agent_id' => $agentId,
                    ]);
                } catch (\RuntimeException | \InvalidArgumentException $e) {
                    $result->addDegradationEvent(sprintf('long_term_retrieval_failed: %s', $e->getMessage()));
                    Log::warning('AutoMemoryRetriever: long-term retrieval failed', [
                        'error' => $e->getMessage(),
                        'agent_id' => $agentId,
                    ]);
                }
            }
        }

        // T015: Sort by priority then score
        $this->sortHits($result);

        // T041: Cross-store deduplication
        $this->deduplicateHits($result);

        // T016: Enforce token budget (whole-entry truncation)
        $result->truncated = $this->enforceBudget($result, $maxChars);

        // Calculate final retrieval time
        $retrievalTime = (microtime(true) - $startTime) * 1000;
        $result->setRetrievalTime($retrievalTime);

        // Cache the result for this turn
        $this->turnCache[$turnKey] = $result;

        return $result;
    }

    /**
     * Retrieve with metrics tracking.
     *
     * Wraps retrieve() and records metrics via MetricsRecorder.
     * Only records on cache misses (not memoized hits).
     */
    public function retrieveWithMetrics(string $turnKey, string $userId, string $agentId, string $query): MemoryRetrievalResult
    {
        // Check if this is a cache hit (no metrics to record)
        if (isset($this->turnCache[$turnKey])) {
            return $this->retrieve($turnKey, $userId, $agentId, $query);
        }

        $result = $this->retrieve($turnKey, $userId, $agentId, $query);

        // Record metrics only on cache misses. MetricsRecorder never throws.
        if ($this->metricsRecorder !== null) {
            $hitsByStore = $this->computeHitsByStore($result);
            $this->metricsRecorder->recordMemoryRetrieval(
                $userId,
                $agentId,
                $this->extractConversationId($turnKey),
                $result->retrievalTime,
                $result->totalTokens,
                count($result->hits),
                $hitsByStore,
                $result->degradationEvents,
            );
        }

        return $result;
    }

    /**
     * Compute hit counts grouped by store source.
     *
     * @return array<string, int>
     */
    private function computeHitsByStore(MemoryRetrievalResult $result): array
    {
        $counts = [];
        foreach ($result->hits as $hit) {
            $source = $hit->source;
            $counts[$source] = ($counts[$source] ?? 0) + 1;
        }
        return $counts;
    }

    /**
     * Extract conversation ID from a turn key (format: "conversation_id:message_id").
     */
    private function extractConversationId(string $turnKey): string
    {
        $parts = explode(':', $turnKey, 2);
        return $parts[0];
    }

    /**
     * T012: Retrieve declarative memories.
     *
     * Rules: unconditional Eloquent query, no embedding, no scoring, no threshold, no per-store cap.
     * Facts/preferences: in-memory cosine scan with EmbeddingService::cosineSimilarity().
     * Fallback: when no query embedding, delegate to PreferenceInjector::assemble().
     */
    private function retrieveDeclarative(
        MemoryRetrievalResult $result,
        string $userId,
        ?array $queryEmbedding,
        float $relevanceThreshold,
        int $maxResultsPerStore,
    ): void {
        // Get all declarative memories for this user
        $entries = DeclarativeMemory::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->orderBy('type')
            ->orderBy('updated_at', 'desc')
            ->get(['id', 'type', 'content', 'embedding', 'confidence_level', 'updated_at']);

        // Split by type
        $rules = $entries->where('type', 'rule');
        $factsAndPrefs = $entries->whereIn('type', ['fact', 'preference']);

        // Rules: unconditional inclusion (no scoring, no threshold, no cap)
        foreach ($rules as $entry) {
            $hit = MemoryHit::fromRule(
                $entry->id,
                $entry->content,
                1.0,
                ['updated_at' => $entry->updated_at?->toIso8601String()],
            );
            $result->addHit($hit);
        }

        // Facts/preferences: cosine scan if embedding available, else fallback
        if ($queryEmbedding !== null && !empty($queryEmbedding)) {
            // In-memory cosine similarity scan
            $scored = [];
            foreach ($factsAndPrefs as $entry) {
                $entryEmbedding = $entry->embedding;
                if (!is_array($entryEmbedding) || empty($entryEmbedding)) {
                    continue;
                }

                $score = EmbeddingService::cosineSimilarity($queryEmbedding, $entryEmbedding);
                $normalizedScore = EmbeddingService::normalizeSimilarity($score);

                // Apply relevance threshold
                if ($normalizedScore < $relevanceThreshold) {
                    continue;
                }

                $scored[] = [
                    'entry' => $entry,
                    'score' => $normalizedScore,
                    'embedding' => $entryEmbedding,
                ];
            }

            // Sort by score descending and apply per-store cap
            usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
            $scored = array_slice($scored, 0, $maxResultsPerStore);

            foreach ($scored as $item) {
                $entry = $item['entry'];
                $hit = MemoryHit::fromDeclarative(
                    $entry->id,
                    $entry->content,
                    $entry->type,
                    round($item['score'], 4),
                    [
                        'confidence_level' => $entry->confidence_level,
                        'updated_at' => $entry->updated_at?->toIso8601String(),
                        'embedding' => $item['embedding'],
                    ],
                );
                $result->addHit($hit);
            }
        } else {
            // Fallback: use PreferenceInjector::assemble() output
            $sectionText = $this->preferenceInjector->assemble($userId);
            if ($sectionText !== null) {
                // Parse the assembled section back into hits
                // This is a best-effort fallback when embeddings are unavailable
                $lines = array_filter(array_map('trim', explode("\n", $sectionText)));
                foreach ($lines as $line) {
                    if (str_starts_with($line, '- ')) {
                        $content = substr($line, 2);
                        $hit = MemoryHit::fromDeclarative(
                            'fallback_' . md5($content),
                            $content,
                            'preference',
                            0.5, // Default score for fallback
                        );
                        $result->addHit($hit);
                    }
                }
            }
        }
    }

    /**
     * T013: Retrieve episodic memories.
     *
     * Delegate to EpisodicMemorySearchService::hybridSearch() passing $queryEmbedding.
     */
    private function retrieveEpisodic(
        MemoryRetrievalResult $result,
        string $userId,
        string $query,
        ?array $queryEmbedding,
        float $relevanceThreshold,
        int $maxResultsPerStore,
    ): void {
        $searchResults = $this->episodicMemorySearchService->hybridSearch(
            $userId,
            $query,
            $maxResultsPerStore,
            $queryEmbedding,
        );

        foreach ($searchResults as $entry) {
            $score = isset($entry['similarity_score']) ? (float) $entry['similarity_score'] : 0.5;

            // Apply relevance threshold (keyword results get default 0.5)
            if ($score < $relevanceThreshold) {
                continue;
            }

            $content = $entry['summary'] ?? '';
            $hit = MemoryHit::fromEpisodic(
                $entry['id'],
                $content,
                round($score, 4),
                [
                    'conversation_id' => $entry['conversation_id'] ?? null,
                    'topics' => $entry['topics'] ?? [],
                ],
            );
            $result->addHit($hit);
        }
    }

    /**
     * T014: Retrieve long-term memories.
     *
     * Delegate to MemoryService::search() with semantic mode, passing $queryEmbedding.
     */
    private function retrieveLongTerm(
        MemoryRetrievalResult $result,
        string $agentId,
        string $query,
        ?array $queryEmbedding,
        float $relevanceThreshold,
        int $maxResultsPerStore,
    ): void {
        $searchResults = $this->memoryService->search(
            MemoryScope::LONG_TERM,
            $agentId,
            $query,
            'semantic',
            $maxResultsPerStore,
            null,
            $queryEmbedding,
        );

        foreach ($searchResults as $entry) {
            $score = $entry->getAttribute('similarity_score', 0.5);

            // Apply relevance threshold
            if ($score < $relevanceThreshold) {
                continue;
            }

            $hit = MemoryHit::fromLongTerm(
                $entry->id,
                $entry->content,
                round((float) $score, 4),
                [
                    'key' => $entry->key,
                    'last_accessed_at' => $entry->last_accessed_at?->toIso8601String(),
                ],
            );
            $result->addHit($hit);
        }
    }

    /**
     * T015: Sort hits by priority then score.
     *
     * Priority order: rules > facts/preferences > episodic > long-term.
     * Within same priority, sort by score descending.
     */
    private function sortHits(MemoryRetrievalResult $result): void
    {
        $priorityMap = [
            'rule' => 0,
            'fact' => 1,
            'preference' => 2,
            'episodic' => 3,
            'long-term' => 4,
        ];

        usort($result->hits, function (MemoryHit $a, MemoryHit $b) use ($priorityMap) {
            $priorityA = $priorityMap[$a->type] ?? 99;
            $priorityB = $priorityMap[$b->type] ?? 99;

            // First by priority (lower number = higher priority)
            if ($priorityA !== $priorityB) {
                return $priorityA <=> $priorityB;
            }

            // Then by score descending
            return $b->score <=> $a->score;
        });
    }

    /**
     * T041: Cross-store deduplication.
     *
     * Removes near-duplicate hits across stores using:
     * - Exact content match (case-insensitive, trimmed)
     * - Cosine similarity > 0.9 on embeddings (when available)
     *
     * Only compares hits from different stores (source values).
     * Preserves the higher-priority copy (rule > fact > preference > episodic > long-term).
     * Rules are NEVER deduplicated away.
     */
    private function deduplicateHits(MemoryRetrievalResult $result): void
    {
        $priorityMap = [
            'rule' => 0,
            'fact' => 1,
            'preference' => 2,
            'episodic' => 3,
            'long-term' => 4,
        ];

        $hits = $result->hits;
        $count = count($hits);

        if ($count <= 1) {
            return;
        }

        // Track which indices to remove
        $removed = [];

        for ($i = 0; $i < $count; $i++) {
            if (in_array($i, $removed, true)) {
                continue;
            }

            for ($j = $i + 1; $j < $count; $j++) {
                if (in_array($j, $removed, true)) {
                    continue;
                }

                // Cross-store only: skip hits from the same store
                if ($hits[$i]->source === $hits[$j]->source) {
                    continue;
                }

                // Check if hits are near-duplicates
                if (!$this->isNearDuplicate($hits[$i], $hits[$j])) {
                    continue;
                }

                // Determine which to keep based on priority
                $priorityI = $priorityMap[$hits[$i]->type] ?? 99;
                $priorityJ = $priorityMap[$hits[$j]->type] ?? 99;

                // Rules are always kept — never remove a rule
                if ($hits[$j]->type === 'rule') {
                    // j is a rule, so remove i (if i is not also a rule)
                    if ($hits[$i]->type !== 'rule') {
                        $removed[] = $i;
                    }
                    break;
                }

                if ($hits[$i]->type === 'rule') {
                    // i is a rule, so remove j
                    $removed[] = $j;
                    continue;
                }

                // Lower priority number = higher priority = keep
                if ($priorityJ <= $priorityI) {
                    $removed[] = $i;
                    break;
                }

                $removed[] = $j;
            }
        }

        if ($removed === []) {
            return;
        }

        $kept = [];
        for ($i = 0; $i < $count; $i++) {
            if (!in_array($i, $removed, true)) {
                $kept[] = $hits[$i];
            }
        }

        $result->hits = $kept;
        $result->recalculateTokens();
    }

    /**
     * Check if two hits are near-duplicates.
     *
     * Uses exact content match (case-insensitive, trimmed) or
     * cosine similarity > 0.9 on embeddings (when both available).
     */
    private function isNearDuplicate(MemoryHit $a, MemoryHit $b): bool
    {
        // Content-based: case-insensitive trimmed exact match
        $contentA = mb_strtolower(trim($a->content));
        $contentB = mb_strtolower(trim($b->content));

        if ($contentA === $contentB) {
            return true;
        }

        // Embedding-based: cosine similarity > 0.9
        $embeddingA = $a->metadata['embedding'] ?? null;
        $embeddingB = $b->metadata['embedding'] ?? null;

        if (is_array($embeddingA) && is_array($embeddingB)
            && !empty($embeddingA) && !empty($embeddingB)) {
            $similarity = EmbeddingService::cosineSimilarity($embeddingA, $embeddingB);
            if ($similarity > 0.9) {
                return true;
            }
        }

        return false;
    }

    /**
     * T016: Enforce token budget with whole-entry truncation.
     *
     * Rules are always kept (never truncated). Other entries are dropped
     * lowest-priority-first when the budget is exceeded.
     *
     * @return bool True if entries were truncated
     */
    private function enforceBudget(MemoryRetrievalResult $result, int $maxChars): bool
    {
        if ($result->hits === []) {
            return false;
        }

        // Calculate total character count including formatting overhead
        $truncated = false;
        $sectionHeaderLen = strlen("## Retrieved Memory Context\n\n");
        $trailingNewlineLen = strlen("\n");
        $bulletPrefixLen = 20; // Approximate "[source/type] " prefix length

        $currentHits = $result->hits;
        $usedChars = $sectionHeaderLen + $trailingNewlineLen;

        // Separate rules (always kept) from other entries
        $rules = [];
        $others = [];

        foreach ($currentHits as $hit) {
            if ($hit->type === 'rule') {
                $rules[] = $hit;
                $usedChars += $bulletPrefixLen + $hit->contentLength() + 1; // +1 for newline
            } else {
                $others[] = $hit;
            }
        }

        // Add others back in priority order, dropping when budget exceeded
        $finalHits = $rules;

        foreach ($others as $hit) {
            $entryChars = $bulletPrefixLen + $hit->contentLength() + 1;

            if ($usedChars + $entryChars > $maxChars) {
                $truncated = true;
                continue; // Drop this entry
            }

            $finalHits[] = $hit;
            $usedChars += $entryChars;
        }

        if ($truncated) {
            $result->hits = $finalHits;
            $result->recalculateTokens();
        }

        return $truncated;
    }

    /**
     * Get total characters used by current hits (for budget gates).
     */
    private function getUsedChars(MemoryRetrievalResult $result): int
    {
        $chars = 0;
        foreach ($result->hits as $hit) {
            $chars += $hit->contentLength();
        }
        return $chars;
    }

    /**
     * Format the retrieval result as a MemoryInjectionSection.
     *
     * @return MemoryInjectionSection
     */
    public function formatInjectionSection(MemoryRetrievalResult $result): MemoryInjectionSection
    {
        if ($result->isEmpty()) {
            return MemoryInjectionSection::empty();
        }

        return MemoryInjectionSection::fromRetrievalResult($result, $result->truncated);
    }
}
