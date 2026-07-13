<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Contracts\EpisodicMemoryService as EpisodicMemoryServiceContract;
use ClarionApp\LlmClient\Models\EpisodicMemory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class EpisodicMemoryService implements EpisodicMemoryServiceContract
{
    /**
     * Protect an episodic memory entry from automatic retention cleanup.
     */
    public function protect(string $userId, string $memoryId): bool
    {
        $memory = $this->findForUser($userId, $memoryId);
        return $memory->update(['protected' => true]);
    }

    /**
     * Unprotect an episodic memory entry (allow retention cleanup).
     */
    public function unprotect(string $userId, string $memoryId): bool
    {
        $memory = $this->findForUser($userId, $memoryId);
        return $memory->update(['protected' => false]);
    }

    /**
     * Permanently delete an episodic memory entry (forceDelete for immediate removal per FR-012).
     */
    public function delete(string $userId, string $memoryId): bool
    {
        $memory = $this->findForUser($userId, $memoryId);
        return $memory->forceDelete();
    }

    /**
     * List episodic memories for a user (paginated, per-user scoped, excludes soft-deleted).
     */
    public function list(string $userId, int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        return EpisodicMemory::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->latest('created_at')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Recall relevant episodic memories by topic for a given user.
     * Returns most recent relevant entry per topic (deduplicate conflicts by recency).
     */
    public function recall(string $userId, string $topic): array
    {
        // Search by topic tag match and summary keyword match
        $query = EpisodicMemory::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->where(function ($q) use ($topic) {
                // Match topic in the JSON topics array
                $q->where('topics', 'like', '%"'.addcslashes($topic, '"').'"%')
                  // Or match topic keywords in the summary text
                  ->orWhere('summary', 'like', "%{$topic}%");
            })
            ->latest('created_at');

        $memories = $query->get();

        // Deduplicate by topic — keep most recent entry per unique topic set
        $seenTopics = [];
        $result = [];

        foreach ($memories as $memory) {
            // Create a fingerprint from the topics array for deduplication
            $fingerprint = strtolower(implode(',', array_map('trim', $memory->topics ?? [])));

            if (!in_array($fingerprint, $seenTopics)) {
                $seenTopics[] = $fingerprint;
                $result[] = $memory;
            }
        }

        return $result;
    }

    /**
     * Find an episodic memory entry for a specific user.
     *
     * @throws ModelNotFoundException If entry not found or not owned by user
     */
    protected function findForUser(string $userId, string $memoryId): EpisodicMemory
    {
        $memory = EpisodicMemory::withoutGlobalScope('user')
            ->where('id', $memoryId)
            ->where('user_id', $userId)
            ->first();

        if (!$memory) {
            throw (new ModelNotFoundException("Episodic memory not found or not owned by user"))
                ->setModel(EpisodicMemory::class, $memoryId);
        }

        return $memory;
    }
}
