<?php

namespace ClarionApp\LlmClient\Contracts;

use ClarionApp\LlmClient\Models\MemoryEntry;

interface MemoryService
{
    /**
     * Create a new memory entry.
     * If an entry with the same (scope, agent_id, key) exists, update it instead.
     *
     * @param MemoryScope $scope Memory scope
     * @param string $agent_id Agent/character identifier
     * @param string $user_id User identifier
     * @param string|null $conversation_id Conversation session identifier (nullable for long-term)
     * @param string|null $turn_id Turn identifier (required for scratch)
     * @param string|null $key Entry key (auto-generated UUID if null)
     * @param string $content Entry content
     * @return MemoryEntry The created or updated entry
     */
    public function create(
        MemoryScope $scope,
        string $agent_id,
        string $user_id,
        ?string $conversation_id,
        ?string $turn_id,
        ?string $key,
        string $content
    ): MemoryEntry;

    /**
     * Read a memory entry by key (preferred) or by UUID.
     *
     * @param MemoryScope $scope Memory scope
     * @param string $agent_id Agent/character identifier
     * @param string $identifier Entry key or UUID
     * @return MemoryEntry|null
     */
    public function read(MemoryScope $scope, string $agent_id, string $identifier): ?MemoryEntry;

    /**
     * Search memory entries within a scope.
     *
     * @param MemoryScope $scope Memory scope
     * @param string $agent_id Agent/character identifier
     * @param string $query Search query string
     * @param string $mode Search mode: 'key_prefix' or 'content'
     * @param int $limit Maximum results (bounded by search_max_limit)
     * @return MemoryEntry[]
     */
    public function search(
        MemoryScope $scope,
        string $agent_id,
        string $query,
        string $mode = 'key_prefix',
        int $limit = 20
    ): array;

    /**
     * Delete a memory entry by key (preferred) or by UUID.
     *
     * @param MemoryScope $scope Memory scope
     * @param string $agent_id Agent/character identifier
     * @param string $identifier Entry key or UUID
     * @return bool True if an entry was deleted
     */
    public function delete(MemoryScope $scope, string $agent_id, string $identifier): bool;
}
