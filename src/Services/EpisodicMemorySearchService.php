<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\EpisodicMemory;
use Illuminate\Support\Facades\DB;

/**
 * Service for keyword and semantic search of episodic memories.
 *
 * Supports three modes:
 * - keyword: LIKE-based search on summary and topics
 * - semantic: cosine similarity on embedding vectors
 * - hybrid: combines both with recency tiebreaking (auto-degrades to keyword-only if embeddings unavailable)
 */
class EpisodicMemorySearchService
{
    public function __construct(
        private readonly EmbeddingService $embeddingService
    ) {}

    /**
     * Search episodic memories in the specified mode.
     *
     * @param string $userId User to search memories for
     * @param string $query Search query string
     * @param string $mode 'keyword', 'semantic', or 'hybrid'
     * @param int $limit Maximum results to return
     * @return array List of EpisodicMemory models (may include 'similarity_score' attribute for semantic mode)
     * @throws \InvalidArgumentException If mode is invalid or embeddings are unavailable for semantic search
     */
    public function search(string $userId, string $query, string $mode = 'hybrid', int $limit = 20): array
    {
        switch ($mode) {
            case 'keyword':
                return $this->keywordSearch($userId, $query, $limit);
            case 'semantic':
                return $this->semanticSearch($userId, $query, $limit);
            case 'hybrid':
                return $this->hybridSearch($userId, $query, $limit);
            default:
                throw new \InvalidArgumentException("Invalid search mode: {$mode}. Must be 'keyword', 'semantic', or 'hybrid'.");
        }
    }

    /**
     * Keyword search using LIKE-based matching on summary and topics columns.
     */
    public function keywordSearch(string $userId, string $query, int $limit = 20): array
    {
        $likeQuery = '%'.addcslashes($query, '%_').'%';

        $results = EpisodicMemory::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->where(function ($q) use ($likeQuery) {
                $q->where('summary', 'like', $likeQuery)
                  ->orWhere('topics', 'like', $likeQuery);
            })
            ->latest('created_at')
            ->limit($limit)
            ->get();

        return $results->toArray();
    }

    /**
     * Semantic search using cosine similarity on embedding vectors.
     *
     * @param string $userId User to search memories for
     * @param string $query Search query string
     * @param int $limit Maximum results to return
     * @param float[]|null $queryEmbedding Pre-computed embedding vector (optional — skips internal generate() call when supplied)
     * @throws \InvalidArgumentException If embeddings are unavailable
     */
    public function semanticSearch(string $userId, string $query, int $limit = 20, ?array $queryEmbedding = null): array
    {
        if (!$this->embeddingService->isEnabled()) {
            throw new \InvalidArgumentException('Semantic search unavailable. Embedding generation is disabled.');
        }

        // Use pre-computed embedding if supplied, otherwise generate one
        if ($queryEmbedding === null) {
            try {
                $queryEmbedding = $this->embeddingService->generate($query);
            } catch (\RuntimeException $e) {
                throw new \InvalidArgumentException(
                    'Semantic search unavailable: '.$e->getMessage()
                );
            }
        }

        // Check if user has any entries with embeddings
        $hasEmbeddings = EpisodicMemory::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->whereNotNull('embedding')
            ->exists();

        if (!$hasEmbeddings) {
            throw new \InvalidArgumentException('Semantic search unavailable. No embeddings exist for this user\'s entries.');
        }

        $queryEmbeddingJson = json_encode($queryEmbedding);

        // Use database-specific cosine similarity
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            // PostgreSQL: use vector operations (assuming pgvector or JSONB with manual cosine)
            $results = EpisodicMemory::withoutGlobalScope('user')
                ->where('user_id', $userId)
                ->whereNotNull('embedding')
                ->select('episodic_memories.*')
                ->selectRaw(
                    '1 - (embedding::vector <=> ?::vector) as similarity_score',
                    [$queryEmbeddingJson]
                )
                ->orderByRaw('similarity_score DESC')
                ->limit($limit)
                ->get();
        } elseif ($driver === 'mysql') {
            // MySQL: use VECTOR cosine similarity
            $results = EpisodicMemory::withoutGlobalScope('user')
                ->where('user_id', $userId)
                ->whereNotNull('embedding')
                ->select('episodic_memories.*')
                ->selectRaw('VECTOR_COSINE_SIMILARITY(embedding, ?) as similarity_score', [$queryEmbeddingJson])
                ->orderByRaw('similarity_score DESC')
                ->limit($limit)
                ->get();
        } else {
            // SQLite fallback: manual cosine similarity computation
            $memories = EpisodicMemory::withoutGlobalScope('user')
                ->where('user_id', $userId)
                ->whereNotNull('embedding')
                ->get();

            $results = collect();
            foreach ($memories as $memory) {
                $embedding = json_decode($memory->embedding, true);
                if ($embedding === null) continue;

                $score = $this->cosineSimilarity($queryEmbedding, $embedding);
                $memory->attributes['similarity_score'] = round($score, 6);
                $results->push($memory);
            }

            $results = $results->sortByDesc('similarity_score')->take($limit)->values();
        }

        return $results->toArray();
    }

    /**
     * Hybrid search combining keyword and semantic results with recency tiebreaking.
     * Auto-degrades to keyword-only if embeddings are unavailable.
     *
     * @param string $userId User to search memories for
     * @param string $query Search query string
     * @param int $limit Maximum results to return
     * @param float[]|null $queryEmbedding Pre-computed embedding vector (optional — skips internal generate() call when supplied)
     */
    public function hybridSearch(string $userId, string $query, int $limit = 20, ?array $queryEmbedding = null): array
    {
        // Try semantic search first
        $semanticResults = [];
        try {
            $semanticResults = $this->semanticSearch($userId, $query, $limit, $queryEmbedding);
        } catch (\InvalidArgumentException) {
            // Embeddings unavailable, fall back to keyword-only
            return $this->keywordSearch($userId, $query, $limit);
        }

        // If semantic returned results, return them (already ranked by similarity)
        if (!empty($semanticResults)) {
            return $semanticResults;
        }

        // No semantic results, fall back to keyword
        return $this->keywordSearch($userId, $query, $limit);
    }

    /**
     * Compute cosine similarity between two vectors.
     */
    protected function cosineSimilarity(array $a, array $b): float
    {
        $dotProduct = 0.0;
        $magnitudeA = 0.0;
        $magnitudeB = 0.0;

        $length = min(count($a), count($b));

        for ($i = 0; $i < $length; $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $magnitudeA += $a[$i] * $a[$i];
            $magnitudeB += $b[$i] * $b[$i];
        }

        $magnitudeA = sqrt($magnitudeA);
        $magnitudeB = sqrt($magnitudeB);

        if ($magnitudeA === 0.0 || $magnitudeB === 0.0) {
            return 0.0;
        }

        return $dotProduct / ($magnitudeA * $magnitudeB);
    }
}
