<?php

namespace ClarionApp\LlmClient\Contracts;

use ClarionApp\LlmClient\Models\DeclarativeMemory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Contract for declarative memory operations.
 *
 * Enforces the confirmation gate at the storage boundary:
 * - User-driven writes (createByUser, updateByUser) apply immediately with no confirmation.
 * - Agent-driven writes (applyAgentWrite) throw ConfirmationRequiredException without explicit confirmation.
 */
interface DeclarativeMemoryService
{
    /**
     * Create a declarative memory entry directly by the user.
     *
     * Source is forced to 'user_stated'. Applies immediately with no confirmation step.
     * Runs semantic conflict/supersede check before storing.
     *
     * @param string $userId User owning the entry
     * @param string $type Entry type: fact, preference, or rule
     * @param string $content The stated fact/preference/rule text
     * @return DeclarativeMemory The created (or superseded) entry
     */
    public function createByUser(string $userId, string $type, string $content): DeclarativeMemory;

    /**
     * Update an existing declarative memory entry by the user.
     *
     * Replaces content in place, refreshes updated_at, best-effort re-embeds.
     * No confirmation required for user-driven edits.
     *
     * @param string $userId User owning the entry
     * @param string $id DeclarativeMemory UUID
     * @param string $content The new content
     * @return DeclarativeMemory The updated entry
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If entry not found or not owned by user
     */
    public function updateByUser(string $userId, string $id, string $content): DeclarativeMemory;

    /**
     * Apply an agent-sourced declarative memory write (new entry or inferred update).
     *
     * Source is set to 'agent_learned'. Throws ConfirmationRequiredException BEFORE
     * any DB read/write when userConfirmed is not true.
     *
     * @param string $userId User owning the entry
     * @param string $type Entry type: fact, preference, or rule
     * @param string $content The content to store
     * @param bool $userConfirmed True only when the user has explicitly confirmed
     * @param string|null $existingId Existing entry ID for inferred updates (FR-003a), or null for new entries
     * @param int|null $confidenceLevel Confidence percentage (0-100) for learned patterns
     * @return DeclarativeMemory The persisted entry
     * @throws \ClarionApp\LlmClient\Exceptions\ConfirmationRequiredException If userConfirmed is false
     */
    public function applyAgentWrite(string $userId, string $type, string $content, bool $userConfirmed, ?string $existingId = null, ?int $confidenceLevel = null): DeclarativeMemory;

    /**
     * Recall all declarative memories for a user.
     *
     * Returns entries grouped by provenance with 'rule' entries surfaced as
     * a distinct binding-constraint group. Hot-path, indexed, no embedding call.
     *
     * @param string $userId User to recall memories for
     * @return array Structured recall data with rules, facts, and preferences
     */
    public function recall(string $userId): array;

    /**
     * List declarative memories for a user (paginated).
     *
     * Includes type and source per entry. Empty store returns an empty paginator (never error).
     *
     * @param string $userId User to list memories for
     * @param int $page Page number
     * @param int $perPage Items per page (default 20, max 100)
     * @return LengthAwarePaginator Paginated results
     */
    public function list(string $userId, int $page = 1, int $perPage = 20): LengthAwarePaginator;

    /**
     * Permanently delete a declarative memory entry.
     *
     * Uses forceDelete() for immediate, non-resurfacing removal (FR-009).
     * Scoped to the owning user.
     *
     * @param string $userId User owning the entry
     * @param string $id DeclarativeMemory UUID
     * @return bool True if the entry was successfully deleted
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If entry not found or not owned by user
     */
    public function delete(string $userId, string $id): bool;
}
