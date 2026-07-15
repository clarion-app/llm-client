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
     *
     * Editing a learned entry converts source to 'user_stated' and clears confidence_level.
     */
    public function updateByUser(string $userId, string $id, string $content): DeclarativeMemory
    {
        $entry = DeclarativeMemory::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->where('id', $id)
            ->first();

        if (!$entry) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException(
                "Entry not found: {$id} for user {$userId}"
            );
        }

        $entry->content = $content;

        // Editing a learned entry converts it to user-stated and clears confidence
        if ($entry->source === 'agent_learned') {
            $entry->source = 'user_stated';
            $entry->confidence_level = null;
        }

        // Best-effort re-embed
        if ($this->embeddingService->isEnabled()) {
            try {
                $entry->embedding = $this->embeddingService->generate($content);
            } catch (\Throwable $e) {
                Log::warning('Embedding generation failed during declarative memory update', [
                    'user_id' => $userId,
                    'entry_id' => $id,
                    'error' => $e->getMessage(),
                ]);
                $entry->embedding = null;
            }
        }

        $entry->save();
        $entry->refresh();
        return $entry;
    }

    /**
     * Apply an agent-sourced declarative memory write.
     *
     * Enforces the confirmation gate at the storage boundary:
     * throws ConfirmationRequiredException BEFORE any DB read/write
     * when userConfirmed is not true.
     *
     * Confidence level is validated (0-100) and stored with the entry.
     */
    public function applyAgentWrite(
        string $userId,
        string $type,
        string $content,
        bool $userConfirmed,
        ?string $existingId = null,
        ?int $confidenceLevel = null
    ): DeclarativeMemory {
        // Hard confirmation gate — throw BEFORE any DB access (SC-004 / FR-003 / FR-003a)
        if ($userConfirmed !== true) {
            throw new ConfirmationRequiredException($type, $content, $existingId, $confidenceLevel);
        }

        // Validate confidence range (0-100) for confirmed writes
        if ($confidenceLevel !== null && ($confidenceLevel < 0 || $confidenceLevel > 100)) {
            throw new \InvalidArgumentException(
                "confidence_level must be between 0 and 100, got: {$confidenceLevel}"
            );
        }

        // Confirmed — persist as agent_learned via conflict/supersede path
        return $this->resolveConflictAndStore($userId, $type, $content, 'agent_learned', $confidenceLevel);
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
        $entry = DeclarativeMemory::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->where('id', $id)
            ->first();

        if (!$entry) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException(
                "Entry not found: {$id} for user {$userId}"
            );
        }

        return $entry->forceDelete();
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
     * Precedence rules:
     * - User-stated entries are never superseded by learned patterns
     * - Higher-confidence learned patterns supersede older learned patterns
     * - Newer user-stated entries supersede older user-stated entries (unchanged)
     *
     * @param string $userId Owning user
     * @param string $type Entry type
     * @param string $content Entry content
     * @param string $source Provenance (user_stated or agent_learned)
     * @param int|null $confidenceLevel Confidence percentage (0-100) for learned patterns
     * @return DeclarativeMemory The created or superseded entry
     */
    private function resolveConflictAndStore(
        string $userId,
        string $type,
        string $content,
        string $source,
        ?int $confidenceLevel = null
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
                // Precedence check: user-stated entries are never superseded by learned patterns
                if ($source === 'agent_learned' && $bestMatch->source === 'user_stated') {
                    // Learned pattern cannot supersede user-stated — fall through to insert new
                } else {
                    // Learned pattern can supersede older learned pattern if higher confidence
                    if ($source === 'agent_learned' && $bestMatch->source === 'agent_learned') {
                        $existingConfidence = $bestMatch->confidence_level;
                        // Higher confidence wins; equal or lower confidence falls through to insert new
                        if ($confidenceLevel !== null && $existingConfidence !== null) {
                            if ($confidenceLevel <= $existingConfidence) {
                                // New pattern not more confident — fall through to insert new
                            } else {
                                // Higher confidence — supersede
                                $bestMatch->content = $content;
                                $bestMatch->confidence_level = $confidenceLevel;
                                $bestMatch->embedding = $newEmbedding;
                                $bestMatch->save();
                                $bestMatch->refresh();
                                return $bestMatch;
                            }
                        } else {
                            // If confidence is not set on either, allow supersede (existing behavior)
                            $bestMatch->content = $content;
                            $bestMatch->confidence_level = $confidenceLevel;
                            $bestMatch->embedding = $newEmbedding;
                            $bestMatch->save();
                            $bestMatch->refresh();
                            return $bestMatch;
                        }
                    } else {
                        // User-stated superseding (unchanged behavior) or other valid case
                        $bestMatch->content = $content;
                        $bestMatch->confidence_level = $confidenceLevel;
                        $bestMatch->embedding = $newEmbedding;
                        $bestMatch->source = $source;
                        $bestMatch->save();
                        $bestMatch->refresh();
                        return $bestMatch;
                    }
                }
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
            // Precedence check for fallback path too
            if ($source === 'agent_learned' && $fallbackMatch->source === 'user_stated') {
                // Learned pattern cannot supersede user-stated — fall through to insert new
            } else {
                $fallbackMatch->content = $content;
                $fallbackMatch->confidence_level = $confidenceLevel;
                $fallbackMatch->embedding = $newEmbedding;
                $fallbackMatch->source = $source;
                $fallbackMatch->save();
                $fallbackMatch->refresh();
                return $fallbackMatch;
            }
        }

        // No conflict — insert new entry
        return DeclarativeMemory::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $userId,
            'type' => $type,
            'content' => $content,
            'source' => $source,
            'confidence_level' => $confidenceLevel,
            'embedding' => $newEmbedding,
        ]);
    }
}
