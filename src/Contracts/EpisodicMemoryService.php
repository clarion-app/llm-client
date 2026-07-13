<?php

namespace ClarionApp\LlmClient\Contracts;

use ClarionApp\LlmClient\Models\EpisodicMemory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface EpisodicMemoryService
{
    /**
     * Protect an episodic memory entry from automatic retention cleanup.
     *
     * @param string $userId User owning the entry
     * @param string $memoryId EpisodicMemory UUID
     * @return bool True if the entry was successfully protected
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If entry not found or not owned by user
     */
    public function protect(string $userId, string $memoryId): bool;

    /**
     * Unprotect an episodic memory entry (allow retention cleanup).
     *
     * @param string $userId User owning the entry
     * @param string $memoryId EpisodicMemory UUID
     * @return bool True if the entry was successfully unprotected
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If entry not found or not owned by user
     */
    public function unprotect(string $userId, string $memoryId): bool;

    /**
     * Permanently delete an episodic memory entry (forceDelete for immediate removal per FR-012).
     *
     * @param string $userId User owning the entry
     * @param string $memoryId EpisodicMemory UUID
     * @return bool True if the entry was successfully deleted
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If entry not found or not owned by user
     */
    public function delete(string $userId, string $memoryId): bool;

    /**
     * List episodic memories for a user (paginated, per-user scoped, excludes soft-deleted).
     *
     * @param string $userId User to list memories for
     * @param int $page Page number
     * @param int $perPage Items per page
     * @return LengthAwarePaginator Paginated results
     */
    public function list(string $userId, int $page = 1, int $perPage = 20): LengthAwarePaginator;

    /**
     * Recall relevant episodic memories by topic for a given user.
     * Returns most recent relevant entry per topic (deduplicate conflicts by recency).
     *
     * @param string $userId User to recall memories for
     * @param string $topic Topic string to search for
     * @return EpisodicMemory[] Relevant memories ordered by recency
     */
    public function recall(string $userId, string $topic): array;
}
