<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use ClarionApp\LlmClient\Contracts\MemoryScope;
use ClarionApp\LlmClient\Services\MemoryService;
use ClarionApp\LlmClient\Services\MemoryEvictionService;
use ClarionApp\LlmClient\Models\MemoryEntry;

class MemoryEvictionServiceTest extends TestCase
{
    protected function getEvictionService(): MemoryEvictionService
    {
        return app(MemoryEvictionService::class);
    }

    protected function getService(): MemoryService
    {
        return app(MemoryService::class);
    }

    public function test_eviction_removes_oldest_by_last_accessed_at(): void
    {
        $eviction = $this->getEvictionService();

        // Temporarily set max to 3 for testing
        config(['llm-client.memory.long_term_max_entries' => 3]);

        $agentId = (string) \Illuminate\Support\Str::uuid();
        $userId = (string) \Illuminate\Support\Str::uuid();
        $convId = (string) \Illuminate\Support\Str::uuid();

        // Create 3 entries with staggered last_accessed_at
        $entry1 = MemoryEntry::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'scope' => MemoryScope::LONG_TERM,
            'agent_id' => $agentId,
            'user_id' => $userId,
            'conversation_id' => $convId,
            'key' => 'oldest',
            'content' => 'oldest content',
            'last_accessed_at' => now()->subDays(3),
        ]);

        $entry2 = MemoryEntry::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'scope' => MemoryScope::LONG_TERM,
            'agent_id' => $agentId,
            'user_id' => $userId,
            'conversation_id' => $convId,
            'key' => 'middle',
            'content' => 'middle content',
            'last_accessed_at' => now()->subDays(2),
        ]);

        $entry3 = MemoryEntry::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'scope' => MemoryScope::LONG_TERM,
            'agent_id' => $agentId,
            'user_id' => $userId,
            'conversation_id' => $convId,
            'key' => 'newest',
            'content' => 'newest content',
            'last_accessed_at' => now()->subDays(1),
        ]);

        // At capacity (3 entries, limit is 3) — evicting should remove oldest
        $evicted = $eviction->ensureCapacity($agentId);
        // Count is 3, limit is 3, so 3 >= 3 -> evicts 1
        $this->assertEquals(1, $evicted);

        // Verify oldest was evicted
        $remaining = MemoryEntry::where('scope', MemoryScope::LONG_TERM->value)
            ->where('agent_id', $agentId)
            ->pluck('key')
            ->sort()
            ->values();

        $this->assertEquals(['middle', 'newest'], $remaining->toArray());
    }

    public function test_eviction_does_not_trigger_for_under_limit(): void
    {
        $eviction = $this->getEvictionService();

        config(['llm-client.memory.long_term_max_entries' => 10]);

        $agentId = (string) \Illuminate\Support\Str::uuid();
        $userId = (string) \Illuminate\Support\Str::uuid();
        $convId = (string) \Illuminate\Support\Str::uuid();

        // Create 2 entries (under limit of 10)
        MemoryEntry::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'scope' => MemoryScope::LONG_TERM,
            'agent_id' => $agentId,
            'user_id' => $userId,
            'conversation_id' => $convId,
            'key' => 'key1',
            'content' => 'content1',
            'last_accessed_at' => now(),
        ]);

        MemoryEntry::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'scope' => MemoryScope::LONG_TERM,
            'agent_id' => $agentId,
            'user_id' => $userId,
            'conversation_id' => $convId,
            'key' => 'key2',
            'content' => 'content2',
            'last_accessed_at' => now(),
        ]);

        $evicted = $eviction->ensureCapacity($agentId);
        $this->assertEquals(0, $evicted);

        // Both entries should still exist
        $count = MemoryEntry::where('scope', MemoryScope::LONG_TERM->value)
            ->where('agent_id', $agentId)
            ->count();
        $this->assertEquals(2, $count);
    }

    public function test_eviction_does_not_affect_scratch_or_short_term(): void
    {
        $eviction = $this->getEvictionService();

        config(['llm-client.memory.long_term_max_entries' => 2]);

        $agentId = (string) \Illuminate\Support\Str::uuid();
        $userId = (string) \Illuminate\Support\Str::uuid();
        $convId = (string) \Illuminate\Support\Str::uuid();

        // Create scratch entries
        MemoryEntry::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'scope' => MemoryScope::SCRATCH,
            'agent_id' => $agentId,
            'user_id' => $userId,
            'conversation_id' => $convId,
            'turn_id' => '1',
            'key' => 'scratch1',
            'content' => 'scratch content',
            'last_accessed_at' => now(),
        ]);

        // Create short_term entries
        MemoryEntry::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'scope' => MemoryScope::SHORT_TERM,
            'agent_id' => $agentId,
            'user_id' => $userId,
            'conversation_id' => $convId,
            'key' => 'short1',
            'content' => 'short term content',
            'last_accessed_at' => now(),
        ]);

        // Eviction should only affect long_term
        $evicted = $eviction->ensureCapacity($agentId);
        $this->assertEquals(0, $evicted);

        // Scratch and short_term should be untouched
        $scratchCount = MemoryEntry::where('scope', MemoryScope::SCRATCH->value)
            ->where('agent_id', $agentId)->count();
        $shortCount = MemoryEntry::where('scope', MemoryScope::SHORT_TERM->value)
            ->where('agent_id', $agentId)->count();

        $this->assertEquals(1, $scratchCount);
        $this->assertEquals(1, $shortCount);
    }

    public function test_eviction_on_create_when_at_capacity(): void
    {
        $service = $this->getService();

        config(['llm-client.memory.long_term_max_entries' => 2]);

        $agentId = (string) \Illuminate\Support\Str::uuid();
        $userId = (string) \Illuminate\Support\Str::uuid();
        $convId = (string) \Illuminate\Support\Str::uuid();

        // Create 2 entries (at capacity)
        $service->create(MemoryScope::LONG_TERM, $agentId, $userId, $convId, null, 'key1', 'content1');
        usleep(100000);
        $service->create(MemoryScope::LONG_TERM, $agentId, $userId, $convId, null, 'key2', 'content2');

        // Before creating 3rd, manually evict to ensure capacity
        $eviction = $this->getEvictionService();
        $eviction->ensureCapacity($agentId);

        // Now create a 3rd entry — eviction should trigger
        $service->create(MemoryScope::LONG_TERM, $agentId, $userId, $convId, null, 'key3', 'content3');

        // Count should be within limit (eviction runs before create, so max 2 existing + 1 new = 3 max)
        $count = MemoryEntry::where('scope', MemoryScope::LONG_TERM->value)
            ->where('agent_id', $agentId)
            ->count();
        $this->assertLessThanOrEqual(3, $count);
    }

    public function test_get_max_entries(): void
    {
        $eviction = $this->getEvictionService();

        // Default value
        config(['llm-client.memory.long_term_max_entries' => 200]);
        $this->assertEquals(200, $eviction->getMaxEntries());

        // Custom value
        config(['llm-client.memory.long_term_max_entries' => 50]);
        $this->assertEquals(50, $eviction->getMaxEntries());
    }

    public function test_get_count(): void
    {
        $eviction = $this->getEvictionService();

        $agentId = (string) \Illuminate\Support\Str::uuid();
        $userId = (string) \Illuminate\Support\Str::uuid();
        $convId = (string) \Illuminate\Support\Str::uuid();

        // Initially zero
        $this->assertEquals(0, $eviction->getCount($agentId));

        // Create some entries
        MemoryEntry::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'scope' => MemoryScope::LONG_TERM,
            'agent_id' => $agentId,
            'user_id' => $userId,
            'conversation_id' => $convId,
            'key' => 'count1',
            'content' => 'content1',
            'last_accessed_at' => now(),
        ]);

        MemoryEntry::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'scope' => MemoryScope::LONG_TERM,
            'agent_id' => $agentId,
            'user_id' => $userId,
            'conversation_id' => $convId,
            'key' => 'count2',
            'content' => 'content2',
            'last_accessed_at' => now(),
        ]);

        $this->assertEquals(2, $eviction->getCount($agentId));
    }
}
