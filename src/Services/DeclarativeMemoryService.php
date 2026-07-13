<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Contracts\DeclarativeMemoryService as DeclarativeMemoryServiceContract;
use ClarionApp\LlmClient\Exceptions\ConfirmationRequiredException;
use ClarionApp\LlmClient\Models\DeclarativeMemory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

/**
 * DeclarativeMemoryService — single guarded write path plus reads.
 *
 * Enforces the confirmation gate at the storage boundary:
 * - User-driven writes apply immediately with no confirmation.
 * - Agent-driven writes throw ConfirmationRequiredException without explicit confirmation.
 *
 * All entries are permanent by design — no retention, eviction, or cap.
 */
class DeclarativeMemoryService implements DeclarativeMemoryServiceContract
{
    /**
     * Create a new service instance.
     */
    public function __construct(
        private readonly EmbeddingService $embeddingService
    ) {}

    /* -----------------------------------------------------------------
     * Write Methods
     * ----------------------------------------------------------------- */

    /**
     * Create a declarative memory entry directly by the user.
     */
    public function createByUser(string $userId, string $type, string $content): DeclarativeMemory
    {
        return $this->resolveConflictAndStore($userId, $type, $content, 'user_stated');
    }

    /**
     * Update an existing declarative memory entry by the user.
     */
    public function updateByUser(string $userId, string $id, string $content): DeclarativeMemory
    {
        // TODO (US3): Implement user edit with re-embed.
        throw new \RuntimeException('updateByUser not yet implemented');
    }

    /**
     * Apply an agent-sourced declarative memory write.
     */
    public function applyAgentWrite(
        string $userId,
        string $type,
        string $content,
        bool $userConfirmed,
        ?string $existingId = null
    ): DeclarativeMemory {
        // TODO (US2): Implement confirmation gate + agent-learned persist.
        throw new \RuntimeException('applyAgentWrite not yet implemented');
    }

    /* -----------------------------------------------------------------
     * Read Methods
     * ----------------------------------------------------------------- */

    /**
     * Recall all declarative memories for a user.
     */
    public function recall(string $userId): array
    {
        $entries = DeclarativeMemory::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->orderBy('type')
            ->orderBy('updated_at', 'desc')
            ->get();

        return [
            'entries' => $entries,
            'rules' => $entries->where('type', 'rule'),
            'facts' => $entries->where('type', 'fact'),
            'preferences' => $entries->where('type', 'preference'),
        ];
    }

    /**
     * List declarative memories for a user (paginated).
     */
    public function list(string $userId, int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        $perPage = min(max(1, $perPage), 100);

        return DeclarativeMemory::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->orderBy('updated_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Permanently delete a declarative memory entry.
     */
    public function delete(string $userId, string $id): bool
    {
        // TODO (US3): Implement forceDelete with user scoping.
        throw new \RuntimeException('delete not yet implemented');
    }

    /* -----------------------------------------------------------------
     * Private Helpers
     * ----------------------------------------------------------------- */

    /**
     * Resolve semantic conflicts and store the entry.
     *
     * Best-effort embed new content, scan same-type entries via cosine similarity,
     * supersede in place when max ≥ threshold. On embedding failure, fall back
     * to normalized exact-content match. Never drops the write.
     *
     * @param string $userId Owning user
     * @param string $type Entry type
     * @param string $content Entry content
     * @param string $source Provenance (user_stated or agent_learned)
     * @return DeclarativeMemory The created or superseded entry
     */
    private function resolveConflictAndStore(
        string $userId,
        string $type,
        string $content,
        string $source
    ): DeclarativeMemory {
        $threshold = config('llm-client.declarative_memory.conflict_similarity_threshold', 0.85);

        // Best-effort: try to generate embedding for the new content
        $newEmbedding = null;
        $embeddingAvailable = false;

        if ($this->embeddingService->isEnabled()) {
            try {
                $newEmbedding = $this->embeddingService->generate($content);
                $embeddingAvailable = true;
            } catch (\Throwable $e) {
                Log::warning('Embedding generation failed for declarative memory, falling back to normalized exact match', [
                    'user_id' => $userId,
                    'type' => $type,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Semantic conflict check if embedding is available
        if ($embeddingAvailable && $newEmbedding !== null) {
            $existingEntries = DeclarativeMemory::withoutGlobalScope('user')
                ->where('user_id', $userId)
                ->where('type', $type)
                ->whereNotNull('embedding')
                ->get();

            $bestMatch = null;
            $bestSimilarity = 0.0;

            foreach ($existingEntries as $entry) {
                $existingEmbedding = $entry->embedding;
                if (!is_array($existingEmbedding) || empty($existingEmbedding)) {
                    continue;
                }

                $cosine = EmbeddingService::cosineSimilarity($newEmbedding, $existingEmbedding);
                $normalized = EmbeddingService::normalizeSimilarity($cosine);

                if ($normalized > $bestSimilarity) {
                    $bestSimilarity = $normalized;
                    $bestMatch = $entry;
                }
            }

            // Supersede in place if similarity exceeds threshold
            if ($bestMatch !== null && $bestSimilarity >= $threshold) {
                $bestMatch->content = $content;
                $bestMatch->embedding = $newEmbedding;
                $bestMatch->save();
                $bestMatch->refresh();
                return $bestMatch;
            }
        }

        // Fallback: normalized exact-content match (trim + lowercase + collapse whitespace)
        $normalizedContent = preg_replace('/\s+/', ' ', trim(strtolower($content)));

        $fallbackMatch = DeclarativeMemory::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->where('type', $type)
            ->whereRaw('LOWER(TRIM(REPLACE(content, \'\\n\', \' \'))) = ?', [$normalizedContent])
            ->first();

        // Simpler fallback: iterate and compare normalized content
        if ($fallbackMatch === null) {
            $existingForType = DeclarativeMemory::withoutGlobalScope('user')
                ->where('user_id', $userId)
                ->where('type', $type)
                ->get();

            foreach ($existingForType as $entry) {
                $entryNormalized = preg_replace('/\s+/', ' ', trim(strtolower($entry->content)));
                if ($entryNormalized === $normalizedContent) {
                    $fallbackMatch = $entry;
                    break;
                }
            }
        }

        if ($fallbackMatch !== null) {
            $fallbackMatch->content = $content;
            $fallbackMatch->embedding = $newEmbedding;
            $fallbackMatch->save();
            $fallbackMatch->refresh();
            return $fallbackMatch;
        }

        // No conflict — insert new entry
        return DeclarativeMemory::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $userId,
            'type' => $type,
            'content' => $content,
            'source' => $source,
            'embedding' => $newEmbedding,
        ]);
    }
}
