<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Contracts\MemoryScope;
use ClarionApp\LlmClient\Contracts\MemoryService as MemoryServiceContract;
use ClarionApp\LlmClient\Exceptions\SemanticSearchException;
use ClarionApp\LlmClient\Models\MemoryEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;



class MemoryService implements MemoryServiceContract
{
    public function __construct(
        private ?MemoryEvictionService $evictionService = null,
        private ?EmbeddingService $embeddingService = null
    ) {}

    public function create(
        MemoryScope $scope,
        string $agent_id,
        string $user_id,
        ?string $conversation_id,
        ?string $turn_id,
        ?string $key,
        string $content
    ): MemoryEntry {
        // Auto-generate key if not provided
        if ($key === null) {
            $key = (string) Str::uuid();
        }

        // For long-term scope, ensure capacity before creating
        if ($scope === MemoryScope::LONG_TERM && $this->evictionService !== null) {
            $this->evictionService->ensureCapacity($agent_id);
        }

        // Check for existing entry with same (scope, agent_id, key) — implicit update
        $existing = MemoryEntry::where('scope', $scope->value)
            ->where('agent_id', $agent_id)
            ->where('key', $key)
            ->first();

        if ($existing) {
            $existing->content = $content;
            $existing->conversation_id = $conversation_id;
            $existing->turn_id = $turn_id;
            $existing->last_accessed_at = now();
            $existing->save();

            // Regenerate embedding on content update (non-blocking)
            $this->embedEntry($existing);

            return $existing;
        }

        // Create new entry
        $entry = MemoryEntry::create([
            'id' => (string) Str::uuid(),
            'scope' => $scope,
            'agent_id' => $agent_id,
            'user_id' => $user_id,
            'conversation_id' => $conversation_id,
            'turn_id' => $turn_id,
            'key' => $key,
            'content' => $content,
            'last_accessed_at' => now(),
        ]);

        // Generate embedding for long-term entries (non-blocking)
        $this->embedEntry($entry);

        return $entry;
    }

    public function read(MemoryScope $scope, string $agent_id, string $identifier): ?MemoryEntry
    {
        // Try key lookup first, then UUID lookup
        $entry = MemoryEntry::where('scope', $scope->value)
            ->where('agent_id', $agent_id)
            ->where('key', $identifier)
            ->first();

        if (!$entry) {
            $entry = MemoryEntry::where('scope', $scope->value)
                ->where('agent_id', $agent_id)
                ->where('id', $identifier)
                ->first();
        }

        // Update last_accessed_at on read
        if ($entry) {
            $entry->last_accessed_at = now();
            $entry->save();
        }

        return $entry;
    }

    public function search(
        MemoryScope $scope,
        string $agent_id,
        string $query,
        string $mode = 'key_prefix',
        int $limit = 20,
        ?float $min_score = null,
        ?array $queryEmbedding = null
    ): array {
        // Validate mode
        $allowedModes = ['key_prefix', 'content', 'semantic'];
        if (!in_array($mode, $allowedModes, true)) {
            throw new InvalidArgumentException(
                "Invalid search mode '{$mode}'. Allowed modes: " . implode(', ', $allowedModes)
            );
        }

        // Semantic search is restricted to long_term scope only
        if ($mode === 'semantic' && $scope !== MemoryScope::LONG_TERM) {
            throw new SemanticSearchException(
                'semantic_search_long_term_only',
                suggestion: 'Use key_prefix or content mode for scratch and short_term scopes'
            );
        }

        // Clamp limit to configured max
        $maxLimit = config('llm-client.memory.search_max_limit', 100);
        $limit = min(max($limit, 1), $maxLimit);

        // Handle semantic search mode
        if ($mode === 'semantic') {
            return $this->searchSemantic($scope, $agent_id, $query, $limit, $min_score, $queryEmbedding);
        }

        // Keyword-based search modes
        $queryBuilder = MemoryEntry::where('scope', $scope->value)
            ->where('agent_id', $agent_id);

        if ($mode === 'key_prefix') {
            $queryBuilder->where('key', 'LIKE', $query . '%');
        } elseif ($mode === 'content') {
            $queryBuilder->where('content', 'LIKE', '%' . $query . '%');
        }

        return $queryBuilder->orderByDesc('last_accessed_at')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * Perform semantic search using embedding similarity.
     *
     * @param MemoryScope $scope Memory scope (must be LONG_TERM)
     * @param string $agent_id Agent identifier
     * @param string $query Natural language search query
     * @param int $limit Maximum results to return
     * @param float|null $min_score Minimum similarity threshold (0.0-1.0)
     * @param float[]|null $queryEmbedding Pre-computed embedding vector (optional — skips internal generate() call when supplied)
     * @return MemoryEntry[] Results with similarity_score attribute attached
     * @throws SemanticSearchException If embedding provider is unavailable or generation fails
     */
    private function searchSemantic(MemoryScope $scope, string $agent_id, string $query, int $limit, ?float $min_score = null, ?array $queryEmbedding = null): array
    {
        // Check embedding service and provider availability
        if ($this->embeddingService === null || $this->embeddingService->getProvider() === null) {
            throw new SemanticSearchException(
                'embedding_provider_unavailable',
                suggestion: 'Use key_prefix or content mode, or configure memory.embedding.server_id'
            );
        }

        // Use pre-computed embedding if supplied, otherwise generate one
        if ($queryEmbedding === null) {
            try {
                $queryEmbedding = $this->embeddingService->generate($query);
            } catch (RuntimeException $e) {
                throw new SemanticSearchException(
                    'embedding_generation_failed',
                    $e->getMessage(),
                    previous: $e
                );
            }
        }

        // Use native vector search for MySQL/MariaDB, fallback to application-side for others
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            $results = $this->searchSemanticNative($agent_id, $queryEmbedding, $limit);
        } else {
            $results = $this->searchSemanticFallback($scope, $agent_id, $queryEmbedding, $limit);
        }

        // Apply min_score filter if specified
        if ($min_score !== null) {
            $results = array_filter($results, function ($entry) use ($min_score) {
                return $entry->getAttribute('similarity_score', 0) >= $min_score;
            });
            // Re-index array to remove gaps
            $results = array_values($results);
        }

        return $results;
    }

    /**
     * Native vector similarity search using MariaDB VECTOR operators.
     *
     * @param string $agent_id Agent identifier
     * @param float[] $queryEmbedding Embedding vector for the search query
     * @param int $limit Maximum results
     * @return MemoryEntry[] Results with similarity_score attribute
     */
    private function searchSemanticNative(string $agent_id, array $queryEmbedding, int $limit): array
    {
        // Convert embedding array to VECTOR format string for raw SQL
        // MariaDB VECTOR format: '[f1,f2,f3,...]'
        $embeddingValues = array_map(function ($v) {
            return sprintf('%.8f', $v);
        }, $queryEmbedding);
        $embeddingVector = '[' . implode(',', $embeddingValues) . ']';

        $results = MemoryEntry::where('scope', MemoryScope::LONG_TERM->value)
            ->where('agent_id', $agent_id)
            ->whereNotNull('embedding')
            ->selectRaw('*, VECTOR_COSINE_DISTANCE(embedding, CAST(? AS VECTOR(' . count($queryEmbedding) . '))) AS similarity_raw', [$embeddingVector])
            ->orderByDesc('similarity_raw')
            ->limit($limit)
            ->get();

        // Attach normalized similarity_score to each result
        foreach ($results as $entry) {
            // VECTOR_COSINE_DISTANCE returns distance (lower = more similar)
            // Convert to similarity score: similarity = 1 - distance
            // Then normalize to [0, 1] range
            $rawDistance = $entry->getAttribute('similarity_raw');
            $similarity = EmbeddingService::normalizeSimilarity(1.0 - (float) $rawDistance);
            $entry->setAttribute('similarity_score', round($similarity, 4));
        }

        return $results->all();
    }

    /**
     * Application-side cosine similarity fallback for SQLite/PostgreSQL.
     *
     * Fetches candidate entries with embeddings, computes similarity in PHP.
     *
     * @param MemoryScope $scope Memory scope
     * @param string $agent_id Agent identifier
     * @param float[] $queryEmbedding Embedding vector for the search query
     * @param int $limit Maximum results
     * @return MemoryEntry[] Results with similarity_score attribute
     */
    private function searchSemanticFallback(MemoryScope $scope, string $agent_id, array $queryEmbedding, int $limit): array
    {
        // Fetch entries with non-null embeddings (fetch more than limit to sort then trim)
        $fetchLimit = config('llm-client.memory.search_max_limit', 100);
        $candidates = MemoryEntry::where('scope', $scope->value)
            ->where('agent_id', $agent_id)
            ->whereNotNull('embedding')
            ->limit($fetchLimit)
            ->get();

        // Compute similarity scores and sort
        $scored = [];
        foreach ($candidates as $entry) {
            $storedEmbedding = $entry->embedding;
            if (!is_array($storedEmbedding) || empty($storedEmbedding)) {
                continue;
            }

            $similarity = EmbeddingService::cosineSimilarity($queryEmbedding, $storedEmbedding);
            $normalizedScore = EmbeddingService::normalizeSimilarity($similarity);

            // Skip entries with negative similarity (antithetical)
            if ($similarity <= 0) {
                continue;
            }

            $entry->setAttribute('similarity_score', round($normalizedScore, 4));
            $scored[] = $entry;
        }

        // Sort by similarity score descending
        usort($scored, function ($a, $b) {
            return $b->getAttribute('similarity_score') <=> $a->getAttribute('similarity_score');
        });

        return array_slice($scored, 0, $limit);
    }

    /**
     * Generate embedding for a memory entry (non-blocking).
     *
     * Only generates embeddings for long-term entries.
     * Failures are silently ignored — entry is saved without embedding.
     */
    private function embedEntry(MemoryEntry $entry): void
    {
        if ($this->embeddingService === null) {
            return;
        }

        // Only embed long-term entries
        if ($entry->scope !== MemoryScope::LONG_TERM) {
            return;
        }

        // Non-blocking: generateForEntry handles errors internally
        $this->embeddingService->generateForEntry($entry);
    }

    public function delete(MemoryScope $scope, string $agent_id, string $identifier): bool
    {
        // Try key deletion first, then UUID deletion
        $deleted = MemoryEntry::where('scope', $scope->value)
            ->where('agent_id', $agent_id)
            ->where('key', $identifier)
            ->delete();

        if ($deleted > 0) {
            return true;
        }

        $deleted = MemoryEntry::where('scope', $scope->value)
            ->where('agent_id', $agent_id)
            ->where('id', $identifier)
            ->delete();

        return $deleted > 0;
    }
}
