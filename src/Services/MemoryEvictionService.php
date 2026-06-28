<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Contracts\MemoryScope;
use ClarionApp\LlmClient\Models\MemoryEntry;

class MemoryEvictionService
{
    /**
     * Get the configured max entries for long-term memory per agent.
     */
    public function getMaxEntries(): int
    {
        return config('llm-client.memory.long_term_max_entries', 200);
    }

    /**
     * Check current count of long-term entries for an agent.
     */
    public function getCount(string $agent_id): int
    {
        return MemoryEntry::where('scope', MemoryScope::LONG_TERM->value)
            ->where('agent_id', $agent_id)
            ->count();
    }

    /**
     * Evict oldest long-term entries for an agent if count exceeds the limit.
     * Evicts entries ordered by last_accessed_at ASC (oldest first).
     */
    public function ensureCapacity(string $agent_id, ?int $maxEntries = null): int
    {
        $maxEntries = $maxEntries ?? $this->getMaxEntries();
        $count = $this->getCount($agent_id);

        if ($count < $maxEntries) {
            return 0;
        }

        // Calculate how many to evict (evict enough to get under the limit)
        $toEvict = $count - $maxEntries + 1;

        $oldestIds = MemoryEntry::where('scope', MemoryScope::LONG_TERM->value)
            ->where('agent_id', $agent_id)
            ->orderBy('last_accessed_at', 'asc')
            ->limit($toEvict)
            ->pluck('id')
            ->all();

        if (empty($oldestIds)) {
            return 0;
        }

        $deleted = MemoryEntry::whereIn('id', $oldestIds)->delete();

        return is_int($deleted) ? $deleted : 0;
    }
}
