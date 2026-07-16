<?php

namespace ClarionApp\LlmClient\Services;

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Conversation-scoped LRU cache for operation metadata.
 *
 * Stores operation details (operationId → {summary, method, path, paramSchema})
 * resolved during execute_operation, so subsequent lookups in the same
 * conversation skip ApiManager::getOperationDetails().
 *
 * Features:
 * - Per-conversation isolation (one cache key per conversation)
 * - LRU eviction at configurable max_entries per conversation
 * - Idempotent puts (duplicate operationId updates existing entry)
 * - getSummaries() for system prompt injection
 * - Shared across workers via configured Laravel Cache store
 */
class OperationCache
{
    /**
     * Maximum entries per conversation before LRU eviction.
     */
    private int $maxEntries;

    /**
     * Cache repository for shared storage.
     */
    private ?Repository $store = null;

    /**
     * Request-scoped memoization of decoded arrays per conversation.
     */
    private array $memo = [];

    /**
     * @param ?int $maxEntries Maximum entries per conversation (null = config default)
     * @param ?Repository $store Cache repository (null = resolve from config)
     */
    public function __construct(?int $maxEntries = null, ?Repository $store = null)
    {
        $this->maxEntries = $maxEntries ?? (int) $this->getConfig('llm-client.operation_cache.max_entries', 20);
        $this->store = $store;
    }

    /**
     * Get the cache key for a conversation.
     */
    private function keyFor(string $conversationId): string
    {
        return 'llm-client:op_cache:'.$conversationId;
    }

    /**
     * Execute a callable with graceful degradation.
     *
     * Catches Throwable, logs once per request at warning, returns $fallback.
     */
    private function safely(callable $fn, mixed $fallback): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            try {
                Log::warning('Operation cache backend error', [
                    'error' => $e->getMessage(),
                    'class' => $e::class,
                ]);
            } catch (\Throwable) {
                // Log facade unavailable outside container — silently degrade
            }
            return $fallback;
        }
    }

    /**
     * Execute a callable under a lock for the given conversation.
     *
     * Only lock contention falls through to an unsynchronized run — a lost
     * concurrent entry costs one re-discovery, while an exception would cost
     * the user's request. Errors raised by $fn itself propagate to the
     * caller's safely() wrapper: retrying them here would both duplicate the
     * callable's side effects and drop the very lock that prevents lost
     * updates.
     */
    private function withLock(string $conversationId, callable $fn): mixed
    {
        $repo = $this->resolveStore();
        if (!$repo) {
            return null;
        }

        $lockKey = $this->keyFor($conversationId) . ':lock';
        $lockSeconds = (int) $this->getConfig('llm-client.operation_cache.lock_seconds', 5);
        $lockWait = (int) $this->getConfig('llm-client.operation_cache.lock_wait', 3);

        try {
            $lock = $repo->lock($lockKey, $lockSeconds);
        } catch (\Throwable) {
            // Store cannot provide locks (no LockProvider) — run unsynchronized.
            return $fn();
        }

        try {
            return $lock->block($lockWait, $fn);
        } catch (LockTimeoutException) {
            return $fn();
        }
    }

    /**
     * Resolve the cache repository (lazy resolution).
     */
    private function resolveStore(): ?Repository
    {
        if ($this->store) {
            return $this->store;
        }

        try {
            $storeName = config('llm-client.operation_cache.store');
            return $storeName ? Cache::store($storeName) : Cache::store();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Safe config accessor with fallback (works without container).
     */
    private function getConfig(string $key, mixed $default): mixed
    {
        try {
            return config($key, $default);
        } catch (\Throwable) {
            return $default;
        }
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
        $entry = [
            'operationId' => $operationId,
            'summary' => $details['summary'] ?? '',
            'method' => strtoupper($details['method'] ?? 'GET'),
            'path' => $details['path'] ?? '',
            'paramSchema' => $details['paramSchema'] ?? null,
        ];

        $this->safely(function () use ($conversationId, $operationId, $entry) {
            $this->withLock($conversationId, function () use ($conversationId, $operationId, $entry) {
                $convCache = $this->loadConversation($conversationId);

                // If already exists, remove it first (will be re-added at end)
                if (isset($convCache[$operationId])) {
                    unset($convCache[$operationId]);
                }

                // Evict LRU entry if at capacity
                if (count($convCache) >= $this->maxEntries) {
                    array_shift($convCache);
                }

                // Add entry at end (most recently used)
                $convCache[$operationId] = $entry;

                $this->saveConversation($conversationId, $convCache);
            });
        }, null);

        // The stored array has moved on; drop the memo so the next read reloads.
        unset($this->memo[$conversationId]);
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
        return $this->safely(function () use ($conversationId, $operationId) {
            // Check memo first (fast path)
            if (isset($this->memo[$conversationId][$operationId])) {
                // Promotion under lock (persists across workers)
                $this->withLock($conversationId, function () use ($conversationId, $operationId) {
                    $convCache = $this->loadConversation($conversationId);
                    if (isset($convCache[$operationId])) {
                        $entry = $convCache[$operationId];
                        unset($convCache[$operationId]);
                        $convCache[$operationId] = $entry;
                        $this->saveConversation($conversationId, $convCache);
                    }
                });
                return $this->memo[$conversationId][$operationId];
            }

            // Miss — load fresh (no lock needed for read-miss)
            $convCache = $this->loadConversation($conversationId);
            if (!isset($convCache[$operationId])) {
                return null;
            }

            // Hit — promote and memoize
            $entry = $convCache[$operationId];
            unset($convCache[$operationId]);
            $convCache[$operationId] = $entry;
            $this->saveConversation($conversationId, $convCache);
            $this->memo[$conversationId] = $convCache;

            return $entry;
        }, null);
    }

    /**
     * Get formatted one-line summaries for all cached operations in a conversation.
     *
     * Used for system prompt injection ("Known Operations" section).
     * No lock, no promotion — hot path. Returns LRU→MRU order.
     *
     * @param string $conversationId Conversation UUID
     * @return string[] Array of formatted summary strings, e.g. "create-contact (POST /contacts)"
     */
    public function getSummaries(string $conversationId): array
    {
        return $this->safely(function () use ($conversationId) {
            $convCache = $this->memo[$conversationId] ?? $this->loadConversation($conversationId);
            if (empty($convCache)) {
                return [];
            }

            $summaries = [];
            foreach ($convCache as $entry) {
                $summaries[] = sprintf(
                    '%s (%s %s)',
                    $entry['operationId'],
                    $entry['method'],
                    $entry['path']
                );
            }

            return $summaries;
        }, []);
    }

    /**
     * Get full cached entries for a conversation, ordered most-recently-used first.
     *
     * No lock, no promotion — reads are cheap. Returns MRU-first order.
     *
     * @param string $conversationId Conversation UUID
     * @param int $limit Maximum entries to return (default 20)
     * @return array Array of entry arrays (each entry has operationId, summary, method, path, paramSchema)
     */
    public function getEntries(string $conversationId, int $limit = 20): array
    {
        return $this->safely(function () use ($conversationId, $limit) {
            $convCache = $this->memo[$conversationId] ?? $this->loadConversation($conversationId);
            if (empty($convCache)) {
                return [];
            }

            $entries = array_values($convCache);

            // Reverse so most recently used (last in array) comes first
            $entries = array_reverse($entries);

            return array_slice($entries, 0, $limit);
        }, []);
    }

    /**
     * Get the number of cached entries for a conversation.
     *
     * @param string $conversationId Conversation UUID
     * @return int Entry count
     */
    public function count(string $conversationId): int
    {
        return $this->safely(function () use ($conversationId) {
            $convCache = $this->memo[$conversationId] ?? $this->loadConversation($conversationId);
            return count($convCache);
        }, 0);
    }

    /**
     * Drop every cached operation for a conversation.
     *
     * Called when a conversation is deleted so its operations stop
     * contributing to prompts immediately, rather than lingering until the
     * TTL elapses.
     *
     * @param string $conversationId Conversation UUID
     */
    public function forget(string $conversationId): void
    {
        $this->safely(function () use ($conversationId) {
            $repo = $this->resolveStore();
            if ($repo) {
                $repo->forget($this->keyFor($conversationId));
            }
        }, null);

        unset($this->memo[$conversationId]);
    }

    /**
     * Load conversation cache array from store.
     */
    private function loadConversation(string $conversationId): array
    {
        $repo = $this->resolveStore();
        if (!$repo) {
            return [];
        }

        $key = $this->keyFor($conversationId);
        $value = $repo->get($key);

        if (is_array($value)) {
            return $value;
        }

        return [];
    }

    /**
     * Save conversation cache array to store.
     */
    private function saveConversation(string $conversationId, array $convCache): void
    {
        $repo = $this->resolveStore();
        if (!$repo) {
            return;
        }

        $key = $this->keyFor($conversationId);
        $ttl = (int) $this->getConfig('llm-client.operation_cache.ttl', 86400);

        if (empty($convCache)) {
            $repo->forget($key);
        } else {
            $repo->put($key, $convCache, $ttl);
        }
    }
}
