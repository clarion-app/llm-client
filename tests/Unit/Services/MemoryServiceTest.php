<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use ClarionApp\LlmClient\Contracts\MemoryScope;
use ClarionApp\LlmClient\Services\MemoryService;
use ClarionApp\LlmClient\Models\MemoryEntry;

class MemoryServiceTest extends TestCase
{
    protected function getService(): MemoryService
    {
        return app(MemoryService::class);
    }

    // ─── Scratch Scope Tests (US1) ───

    public function test_scratch_create_and_read(): void
    {
        $service = $this->getService();
        $agentId = (string) \Illuminate\Support\Str::uuid();
        $userId = (string) \Illuminate\Support\Str::uuid();
        $convId = (string) \Illuminate\Support\Str::uuid();
        $turnId = '1';

        $entry = $service->create(
            MemoryScope::SCRATCH,
            $agentId,
            $userId,
            $convId,
            $turnId,
            'test_key',
            'scratch content'
        );

        $this->assertEquals(MemoryScope::SCRATCH, $entry->scope);
        $this->assertEquals('test_key', $entry->key);
        $this->assertEquals('scratch content', $entry->content);
        $this->assertEquals($turnId, $entry->turn_id);

        // Read back
        $read = $service->read(MemoryScope::SCRATCH, $agentId, 'test_key');
        $this->assertNotNull($read);
        $this->assertEquals('scratch content', $read->content);
    }

    public function test_scratch_delete(): void
    {
        $service = $this->getService();
        $agentId = (string) \Illuminate\Support\Str::uuid();
        $userId = (string) \Illuminate\Support\Str::uuid();
        $convId = (string) \Illuminate\Support\Str::uuid();

        $service->create(
            MemoryScope::SCRATCH,
            $agentId,
            $userId,
            $convId,
            '1',
            'delete_me',
            'delete content'
        );

        $deleted = $service->delete(MemoryScope::SCRATCH, $agentId, 'delete_me');
        $this->assertTrue($deleted);

        $read = $service->read(MemoryScope::SCRATCH, $agentId, 'delete_me');
        $this->assertNull($read);
    }

    public function test_scratch_auto_generated_key(): void
    {
        $service = $this->getService();
        $agentId = (string) \Illuminate\Support\Str::uuid();
        $userId = (string) \Illuminate\Support\Str::uuid();
        $convId = (string) \Illuminate\Support\Str::uuid();

        $entry = $service->create(
            MemoryScope::SCRATCH,
            $agentId,
            $userId,
            $convId,
            '1',
            null, // no key — should be auto-generated
            'auto key content'
        );

        $this->assertNotNull($entry->key);
        $this->assertNotEquals('', $entry->key);

        // Should be readable by the generated key
        $read = $service->read(MemoryScope::SCRATCH, $agentId, $entry->key);
        $this->assertNotNull($read);
    }

    // ─── Scope Isolation Tests ───

    public function test_scope_isolation(): void
    {
        $service = $this->getService();
        $agentId = (string) \Illuminate\Support\Str::uuid();
        $userId = (string) \Illuminate\Support\Str::uuid();
        $convId = (string) \Illuminate\Support\Str::uuid();

        // Create entry in scratch scope
        $service->create(
            MemoryScope::SCRATCH,
            $agentId,
            $userId,
            $convId,
            '1',
            'shared_key',
            'scratch content'
        );

        // Create entry with same key in long_term scope
        $service->create(
            MemoryScope::LONG_TERM,
            $agentId,
            $userId,
            $convId,
            null,
            'shared_key',
            'long_term content'
        );

        // Read from scratch scope — should return scratch content
        $scratch = $service->read(MemoryScope::SCRATCH, $agentId, 'shared_key');
        $this->assertEquals('scratch content', $scratch->content);

        // Read from long_term scope — should return long_term content
        $longTerm = $service->read(MemoryScope::LONG_TERM, $agentId, 'shared_key');
        $this->assertEquals('long_term content', $longTerm->content);

        // Delete from scratch should not affect long_term
        $service->delete(MemoryScope::SCRATCH, $agentId, 'shared_key');
        $longTermAfter = $service->read(MemoryScope::LONG_TERM, $agentId, 'shared_key');
        $this->assertNotNull($longTermAfter);
        $this->assertEquals('long_term content', $longTermAfter->content);
    }

    // ─── Short-Term Tests (US2) ───

    public function test_short_term_create_and_persist(): void
    {
        $service = $this->getService();
        $agentId = (string) \Illuminate\Support\Str::uuid();
        $userId = (string) \Illuminate\Support\Str::uuid();
        $convId = (string) \Illuminate\Support\Str::uuid();

        $entry = $service->create(
            MemoryScope::SHORT_TERM,
            $agentId,
            $userId,
            $convId,
            null,
            'short_key',
            'short term content'
        );

        $this->assertEquals(MemoryScope::SHORT_TERM, $entry->scope);

        $read = $service->read(MemoryScope::SHORT_TERM, $agentId, 'short_key');
        $this->assertNotNull($read);
        $this->assertEquals('short term content', $read->content);
    }

    // ─── Long-Term Tests (US3) ───

    public function test_long_term_implicit_update_on_duplicate_key(): void
    {
        $service = $this->getService();
        $agentId = (string) \Illuminate\Support\Str::uuid();
        $userId = (string) \Illuminate\Support\Str::uuid();
        $convId = (string) \Illuminate\Support\Str::uuid();

        $entry1 = $service->create(
            MemoryScope::LONG_TERM,
            $agentId,
            $userId,
            $convId,
            null,
            'update_key',
            'original content'
        );

        $originalId = $entry1->id;

        // Create again with same key — should update, not create new
        $entry2 = $service->create(
            MemoryScope::LONG_TERM,
            $agentId,
            $userId,
            $convId,
            null,
            'update_key',
            'updated content'
        );

        $this->assertEquals($originalId, $entry2->id);
        $this->assertEquals('updated content', $entry2->content);

        // Verify only one entry exists
        $count = MemoryEntry::where('scope', MemoryScope::LONG_TERM->value)
            ->where('agent_id', $agentId)
            ->where('key', 'update_key')
            ->count();
        $this->assertEquals(1, $count);
    }

    public function test_long_term_search_content_mode(): void
    {
        $service = $this->getService();
        $agentId = (string) \Illuminate\Support\Str::uuid();
        $userId = (string) \Illuminate\Support\Str::uuid();
        $convId = (string) \Illuminate\Support\Str::uuid();

        $service->create(MemoryScope::LONG_TERM, $agentId, $userId, $convId, null, 'key_a', 'foo bar baz');
        $service->create(MemoryScope::LONG_TERM, $agentId, $userId, $convId, null, 'key_b', 'hello world');
        $service->create(MemoryScope::LONG_TERM, $agentId, $userId, $convId, null, 'key_c', 'foo fight');

        // Content search for 'foo'
        $results = $service->search(MemoryScope::LONG_TERM, $agentId, 'foo', 'content');
        $this->assertCount(2, $results);

        // Key prefix search for 'key_'
        $keyResults = $service->search(MemoryScope::LONG_TERM, $agentId, 'key_', 'key_prefix');
        $this->assertCount(3, $keyResults);
    }

    public function test_last_accessed_at_updated_on_read(): void
    {
        $service = $this->getService();
        $agentId = (string) \Illuminate\Support\Str::uuid();
        $userId = (string) \Illuminate\Support\Str::uuid();
        $convId = (string) \Illuminate\Support\Str::uuid();

        $entry = $service->create(
            MemoryScope::LONG_TERM,
            $agentId,
            $userId,
            $convId,
            null,
            'access_key',
            'access content'
        );

        $originalAccess = $entry->last_accessed_at;

        // Small delay to ensure timestamp difference
        usleep(100000); // 100ms

        $read = $service->read(MemoryScope::LONG_TERM, $agentId, 'access_key');
        $this->assertNotNull($read);
        $this->assertNotNull($read->last_accessed_at);
    }

    public function test_empty_search_returns_empty_array(): void
    {
        $service = $this->getService();
        $agentId = (string) \Illuminate\Support\Str::uuid();

        $results = $service->search(MemoryScope::LONG_TERM, $agentId, 'nonexistent', 'key_prefix');
        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    public function test_read_by_uuid(): void
    {
        $service = $this->getService();
        $agentId = (string) \Illuminate\Support\Str::uuid();
        $userId = (string) \Illuminate\Support\Str::uuid();
        $convId = (string) \Illuminate\Support\Str::uuid();

        $entry = $service->create(
            MemoryScope::LONG_TERM,
            $agentId,
            $userId,
            $convId,
            null,
            'uuid_key',
            'uuid content'
        );

        // Read by UUID instead of key
        $read = $service->read(MemoryScope::LONG_TERM, $agentId, $entry->id);
        $this->assertNotNull($read);
        $this->assertEquals($entry->id, $read->id);
    }
}
