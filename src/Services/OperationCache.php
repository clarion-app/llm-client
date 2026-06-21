<?php

namespace ClarionApp\LlmClient\Services;

/**
 * In-memory, conversation-scoped LRU cache for operation metadata.
 *
 * Stores operation details (operationId → {summary, method, path, paramSchema})
 * resolved during execute_operation, so subsequent lookups in the same
 * conversation skip ApiManager::getOperationDetails().
 *
 * Features:
 * - Per-conversation isolation (static array keyed by conversation UUID)
 * - LRU eviction at configurable max_entries per conversation
 * - Idempotent puts (duplicate operationId updates existing entry)
 * - getSummaries() for system prompt injection
 */
class OperationCache
{
    /**
     * Static cache storage: [$conversationId => [$operationId => $entry]].
     * PHP arrays preserve insertion/access order for LRU tracking.
     */
    private static array $caches = [];

    /**
     * Maximum entries per conversation before LRU eviction.
     */
    private int $maxEntries;

    public function __construct(?int $maxEntries = null)
    {
        $this->maxEntries = $maxEntries ?? (int) config('llm-client.operation_cache.max_entries', 25);
    }

    /**
     * Put an operation entry into the cache for a conversation.
     *
     * If the operationId already exists, the entry is updated and moved to the
     * end (most recently used). If the cache is at capacity and this is a new
     * entry, the oldest (least recently used) entry is evicted first.
     *
     * @param string $conversationId Conversation UUID
     * @param string $operationId Operation identifier
     * @param array $details Operation details from ApiManager::getOperationDetails()
     */
    public function put(string $conversationId, string $operationId, array $details): void
    {
        // Initialize conversation cache if needed
        if (!isset(self::$caches[$conversationId])) {
            self::$caches[$conversationId] = [];
        }

        $convCache = &self::$caches[$conversationId];

        // If already exists, remove it first (will be re-added at end)
        if (isset($convCache[$operationId])) {
            unset($convCache[$operationId]);
        }

        // Evict LRU entry if at capacity
        if (count($convCache) >= $this->maxEntries) {
            array_shift($convCache);
        }

        // Add entry at end (most recently used)
        $convCache[$operationId] = [
            'operationId' => $operationId,
            'summary' => $details['summary'] ?? '',
            'method' => strtoupper($details['method'] ?? 'GET'),
            'path' => $details['path'] ?? '',
            'paramSchema' => $details['paramSchema'] ?? null,
        ];
    }

    /**
     * Get a cached operation entry for a conversation.
     *
     * On hit, the entry is moved to the end of the array (most recently used).
     *
     * @param string $conversationId Conversation UUID
     * @param string $operationId Operation identifier
     * @return array|null Cached operation entry or null on miss
     */
    public function get(string $conversationId, string $operationId): ?array
    {
        if (!isset(self::$caches[$conversationId])) {
            return null;
        }

        $convCache = &self::$caches[$conversationId];

        if (!isset($convCache[$operationId])) {
            return null;
        }

        // Move to end (most recently used) — LRU tracking
        $entry = $convCache[$operationId];
        unset($convCache[$operationId]);
        $convCache[$operationId] = $entry;

        return $entry;
    }

    /**
     * Get formatted one-line summaries for all cached operations in a conversation.
     *
     * Used for system prompt injection ("Recently Used Operations" section).
     *
     * @param string $conversationId Conversation UUID
     * @return string[] Array of formatted summary strings, e.g. "create-contact (POST /contacts)"
     */
    public function getSummaries(string $conversationId): array
    {
        if (!isset(self::$caches[$conversationId])) {
            return [];
        }

        $summaries = [];
        foreach (self::$caches[$conversationId] as $entry) {
            $summaries[] = sprintf(
                '%s (%s %s)',
                $entry['operationId'],
                $entry['method'],
                $entry['path']
            );
        }

        return $summaries;
    }

    /**
     * Get the number of cached entries for a conversation.
     *
     * @param string $conversationId Conversation UUID
     * @return int Entry count
     */
    public function count(string $conversationId): int
    {
        return count(self::$caches[$conversationId] ?? []);
    }

    /**
     * Clear all cached data (useful for testing).
     */
    public static function flush(): void
    {
        self::$caches = [];
    }
}
