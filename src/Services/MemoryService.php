<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Contracts\MemoryScope;
use ClarionApp\LlmClient\Contracts\MemoryService as MemoryServiceContract;
use ClarionApp\LlmClient\Models\MemoryEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MemoryService implements MemoryServiceContract
{
    public function __construct(
        private ?MemoryEvictionService $evictionService = null
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
            return $existing;
        }

        // Create new entry
        return MemoryEntry::create([
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
        int $limit = 20
    ): array {
        // Clamp limit to configured max
        $maxLimit = config('llm-client.memory.search_max_limit', 100);
        $limit = min(max($limit, 1), $maxLimit);

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
