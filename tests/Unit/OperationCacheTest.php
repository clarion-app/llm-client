<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\LlmClient\Services\OperationCache;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\Test;

class OperationCacheTest extends TestCase
{
    private OperationCache $cache;
    private ArrayStore $store;

    protected function setUp(): void
    {
        // Use ArrayStore-backed repository so tests exercise the real store path
        $this->store = new ArrayStore();
        $this->cache = new OperationCache(25, new Repository($this->store));
    }

    protected function tearDown(): void
    {
        $this->resetCache();
        parent::tearDown();
    }

    private function resetCache(): void
    {
        // Clear the ArrayStore directly — no reflection needed
        $this->store = new ArrayStore();
        $this->cache = new OperationCache(25, new Repository($this->store));
    }

    #[Test]
    public function put_and_get_basic_operation()
    {
        $details = [
            'operationId' => 'create-contact',
            'summary' => 'Create a new contact',
            'method' => 'POST',
            'path' => '/contacts',
            'paramSchema' => ['body' => ['name' => ['type' => 'string']]],
        ];

        $this->cache->put('conv-1', 'create-contact', $details);
        $result = $this->cache->get('conv-1', 'create-contact');

        $this->assertNotNull($result);
        $this->assertEquals('create-contact', $result['operationId']);
        $this->assertEquals('Create a new contact', $result['summary']);
        $this->assertEquals('POST', $result['method']);
        $this->assertEquals('/contacts', $result['path']);
    }

    #[Test]
    public function get_returns_null_for_missing_operation()
    {
        $result = $this->cache->get('conv-1', 'nonexistent');
        $this->assertNull($result);
    }

    #[Test]
    public function get_returns_null_for_empty_cache()
    {
        $result = $this->cache->get('conv-1', 'anything');
        $this->assertNull($result);
    }

    #[Test]
    public function put_is_idempotent_for_same_operationId()
    {
        $details = [
            'operationId' => 'create-contact',
            'summary' => 'Create a new contact',
            'method' => 'POST',
            'path' => '/contacts',
            'paramSchema' => null,
        ];

        $this->cache->put('conv-1', 'create-contact', $details);
        $this->cache->put('conv-1', 'create-contact', $details);

        // Should still have only one entry
        $summaries = $this->cache->getSummaries('conv-1');
        $this->assertCount(1, $summaries);
    }

    #[Test]
    public function put_updates_existing_entry()
    {
        $detailsV1 = [
            'operationId' => 'create-contact',
            'summary' => 'Old summary',
            'method' => 'POST',
            'path' => '/contacts',
            'paramSchema' => null,
        ];

        $detailsV2 = [
            'operationId' => 'create-contact',
            'summary' => 'Updated summary',
            'method' => 'PUT',
            'path' => '/contacts/updated',
            'paramSchema' => null,
        ];

        $this->cache->put('conv-1', 'create-contact', $detailsV1);
        $this->cache->put('conv-1', 'create-contact', $detailsV2);

        $result = $this->cache->get('conv-1', 'create-contact');
        $this->assertEquals('Updated summary', $result['summary']);
        $this->assertEquals('PUT', $result['method']);
    }

    #[Test]
    public function getSummaries_returns_formatted_entries()
    {
        $this->cache->put('conv-1', 'create-contact', [
            'operationId' => 'create-contact',
            'summary' => 'Create a new contact',
            'method' => 'POST',
            'path' => '/contacts',
            'paramSchema' => null,
        ]);

        $this->cache->put('conv-1', 'list-tasks', [
            'operationId' => 'list-tasks',
            'summary' => 'List all tasks',
            'method' => 'GET',
            'path' => '/tasks',
            'paramSchema' => null,
        ]);

        $summaries = $this->cache->getSummaries('conv-1');

        $this->assertCount(2, $summaries);
        $this->assertStringContainsString('create-contact (POST /contacts)', $summaries[0]);
        $this->assertStringContainsString('list-tasks (GET /tasks)', $summaries[1]);
    }

    #[Test]
    public function getSummaries_returns_empty_array_for_empty_cache()
    {
        $summaries = $this->cache->getSummaries('conv-1');
        $this->assertIsArray($summaries);
        $this->assertCount(0, $summaries);
    }

    #[Test]
    public function getSummaries_returns_empty_for_unknown_conversation()
    {
        $this->cache->put('conv-1', 'create-contact', [
            'operationId' => 'create-contact',
            'summary' => 'Create a new contact',
            'method' => 'POST',
            'path' => '/contacts',
            'paramSchema' => null,
        ]);

        $summaries = $this->cache->getSummaries('conv-2');
        $this->assertCount(0, $summaries);
    }

    #[Test]
    public function per_conversation_isolation()
    {
        $this->cache->put('conv-A', 'create-contact', [
            'operationId' => 'create-contact',
            'summary' => 'Create a new contact',
            'method' => 'POST',
            'path' => '/contacts',
            'paramSchema' => null,
        ]);

        // Conversation A should have the entry
        $resultA = $this->cache->get('conv-A', 'create-contact');
        $this->assertNotNull($resultA);
        $this->assertEquals('create-contact', $resultA['operationId']);

        // Conversation B should NOT have the entry
        $resultB = $this->cache->get('conv-B', 'create-contact');
        $this->assertNull($resultB);

        // Conversation B summaries should be empty
        $summariesB = $this->cache->getSummaries('conv-B');
        $this->assertCount(0, $summariesB);
    }

    #[Test]
    public function lru_eviction_at_max_entries_boundary()
    {
        // Add 25 entries (max_entries = 25)
        for ($i = 1; $i <= 25; $i++) {
            $this->cache->put('conv-1', 'op-' . $i, [
                'operationId' => 'op-' . $i,
                'summary' => 'Operation ' . $i,
                'method' => 'GET',
                'path' => '/op/' . $i,
                'paramSchema' => null,
            ]);
        }

        // Should have exactly 25 entries
        $summaries = $this->cache->getSummaries('conv-1');
        $this->assertCount(25, $summaries);

        // Add a 26th entry — should evict the oldest (op-1)
        $this->cache->put('conv-1', 'op-26', [
            'operationId' => 'op-26',
            'summary' => 'Operation 26',
            'method' => 'GET',
            'path' => '/op/26',
            'paramSchema' => null,
        ]);

        // op-1 should be evicted
        $result1 = $this->cache->get('conv-1', 'op-1');
        $this->assertNull($result1, 'op-1 should have been evicted by LRU');

        // op-26 should exist
        $result26 = $this->cache->get('conv-1', 'op-26');
        $this->assertNotNull($result26, 'op-26 should exist');
        $this->assertEquals('op-26', $result26['operationId']);

        // Should still have exactly 25 entries
        $summaries = $this->cache->getSummaries('conv-1');
        $this->assertCount(25, $summaries);
    }

    #[Test]
    public function lru_eviction_respects_access_order()
    {
        // Add 3 entries with max=3
        $this->cache = new OperationCache(3, new Repository($this->store));

        $this->cache->put('conv-1', 'op-a', [
            'operationId' => 'op-a',
            'summary' => 'A',
            'method' => 'GET',
            'path' => '/a',
            'paramSchema' => null,
        ]);

        $this->cache->put('conv-1', 'op-b', [
            'operationId' => 'op-b',
            'summary' => 'B',
            'method' => 'GET',
            'path' => '/b',
            'paramSchema' => null,
        ]);

        $this->cache->put('conv-1', 'op-c', [
            'operationId' => 'op-c',
            'summary' => 'C',
            'method' => 'GET',
            'path' => '/c',
            'paramSchema' => null,
        ]);

        // Access op-a to make it recently used
        $this->cache->get('conv-1', 'op-a');

        // Add op-d — should evict op-b (least recently used)
        $this->cache->put('conv-1', 'op-d', [
            'operationId' => 'op-d',
            'summary' => 'D',
            'method' => 'GET',
            'path' => '/d',
            'paramSchema' => null,
        ]);

        // op-a should still exist (was accessed recently)
        $this->assertNotNull($this->cache->get('conv-1', 'op-a'), 'op-a should survive as recently accessed');

        // op-b should be evicted (least recently used)
        $this->assertNull($this->cache->get('conv-1', 'op-b'), 'op-b should be evicted as LRU');

        // op-c and op-d should exist
        $this->assertNotNull($this->cache->get('conv-1', 'op-c'));
        $this->assertNotNull($this->cache->get('conv-1', 'op-d'));
    }

    #[Test]
    public function multiple_conversations_have_independent_eviction()
    {
        // Fill conversation A to max
        for ($i = 1; $i <= 25; $i++) {
            $this->cache->put('conv-A', 'op-' . $i, [
                'operationId' => 'op-' . $i,
                'summary' => 'Op ' . $i,
                'method' => 'GET',
                'path' => '/op/' . $i,
                'paramSchema' => null,
            ]);
        }

        // Conversation B has only 1 entry
        $this->cache->put('conv-B', 'single-op', [
            'operationId' => 'single-op',
            'summary' => 'Single',
            'method' => 'GET',
            'path' => '/single',
            'paramSchema' => null,
        ]);

        // Evict in A — should not affect B
        $this->cache->put('conv-A', 'op-new', [
            'operationId' => 'op-new',
            'summary' => 'New',
            'method' => 'GET',
            'path' => '/new',
            'paramSchema' => null,
        ]);

        // B should still have its entry
        $resultB = $this->cache->get('conv-B', 'single-op');
        $this->assertNotNull($resultB);
        $this->assertEquals('single-op', $resultB['operationId']);

        // A should have 25 entries (op-1 evicted, op-new added)
        $summariesA = $this->cache->getSummaries('conv-A');
        $this->assertCount(25, $summariesA);
    }

    #[Test]
    public function multiple_puts_in_single_turn()
    {
        // Simulate multiple operations cached in one agent loop iteration
        $this->cache->put('conv-1', 'op-a', [
            'operationId' => 'op-a',
            'summary' => 'A',
            'method' => 'GET',
            'path' => '/a',
            'paramSchema' => null,
        ]);

        $this->cache->put('conv-1', 'op-b', [
            'operationId' => 'op-b',
            'summary' => 'B',
            'method' => 'POST',
            'path' => '/b',
            'paramSchema' => null,
        ]);

        $this->cache->put('conv-1', 'op-c', [
            'operationId' => 'op-c',
            'summary' => 'C',
            'method' => 'DELETE',
            'path' => '/c',
            'paramSchema' => null,
        ]);

        $summaries = $this->cache->getSummaries('conv-1');
        $this->assertCount(3, $summaries);

        // All should be retrievable
        $this->assertNotNull($this->cache->get('conv-1', 'op-a'));
        $this->assertNotNull($this->cache->get('conv-1', 'op-b'));
        $this->assertNotNull($this->cache->get('conv-1', 'op-c'));
    }

    #[Test]
    public function getEntries_returns_empty_array_for_empty_cache()
    {
        $entries = $this->cache->getEntries('conv-1');
        $this->assertIsArray($entries);
        $this->assertCount(0, $entries);
    }

    #[Test]
    public function getEntries_returns_empty_array_for_unknown_conversation()
    {
        $this->cache->put('conv-1', 'create-contact', [
            'operationId' => 'create-contact',
            'summary' => 'Create a new contact',
            'method' => 'POST',
            'path' => '/contacts',
            'paramSchema' => ['body' => ['name' => ['type' => 'string']]],
        ]);

        $entries = $this->cache->getEntries('conv-2');
        $this->assertIsArray($entries);
        $this->assertCount(0, $entries);
    }

    #[Test]
    public function getEntries_returns_entries_most_recently_used_first()
    {
        $this->cache->put('conv-1', 'op-a', [
            'operationId' => 'op-a',
            'summary' => 'First',
            'method' => 'GET',
            'path' => '/a',
            'paramSchema' => null,
        ]);
        $this->cache->put('conv-1', 'op-b', [
            'operationId' => 'op-b',
            'summary' => 'Second',
            'method' => 'GET',
            'path' => '/b',
            'paramSchema' => null,
        ]);
        $this->cache->put('conv-1', 'op-c', [
            'operationId' => 'op-c',
            'summary' => 'Third',
            'method' => 'GET',
            'path' => '/c',
            'paramSchema' => null,
        ]);

        $entries = $this->cache->getEntries('conv-1');

        $this->assertCount(3, $entries);
        // Most recently used first (op-c was last put)
        $this->assertEquals('op-c', $entries[0]['operationId']);
        $this->assertEquals('op-b', $entries[1]['operationId']);
        $this->assertEquals('op-a', $entries[2]['operationId']);
    }

    #[Test]
    public function getEntries_includes_all_fields()
    {
        $this->cache->put('conv-1', 'create-contact', [
            'operationId' => 'create-contact',
            'summary' => 'Create a new contact',
            'method' => 'POST',
            'path' => '/contacts',
            'paramSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
        ]);

        $entries = $this->cache->getEntries('conv-1');

        $this->assertCount(1, $entries);
        $entry = $entries[0];
        $this->assertEquals('create-contact', $entry['operationId']);
        $this->assertEquals('Create a new contact', $entry['summary']);
        $this->assertEquals('POST', $entry['method']);
        $this->assertEquals('/contacts', $entry['path']);
        $this->assertIsArray($entry['paramSchema']);
    }

    #[Test]
    public function getEntries_truncates_to_limit()
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->cache->put('conv-1', 'op-' . $i, [
                'operationId' => 'op-' . $i,
                'summary' => 'Operation ' . $i,
                'method' => 'GET',
                'path' => '/op/' . $i,
                'paramSchema' => null,
            ]);
        }

        $entries = $this->cache->getEntries('conv-1', 5);

        $this->assertCount(5, $entries);
        // Most recently used first
        $this->assertEquals('op-10', $entries[0]['operationId']);
        $this->assertEquals('op-9', $entries[1]['operationId']);
        $this->assertEquals('op-8', $entries[2]['operationId']);
        $this->assertEquals('op-7', $entries[3]['operationId']);
        $this->assertEquals('op-6', $entries[4]['operationId']);
    }

    #[Test]
    public function getEntries_respects_custom_limit()
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->cache->put('conv-1', 'op-' . $i, [
                'operationId' => 'op-' . $i,
                'summary' => 'Operation ' . $i,
                'method' => 'GET',
                'path' => '/op/' . $i,
                'paramSchema' => null,
            ]);
        }

        $entries = $this->cache->getEntries('conv-1', 3);
        $this->assertCount(3, $entries);

        $entries = $this->cache->getEntries('conv-1', 10);
        $this->assertCount(5, $entries);
    }

    #[Test]
    public function getEntries_with_25_entries_and_limit_20_returns_20_most_recently_used()
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->cache->put('conv-1', 'op-' . $i, [
                'operationId' => 'op-' . $i,
                'summary' => 'Operation ' . $i,
                'method' => 'GET',
                'path' => '/op/' . $i,
                'paramSchema' => null,
            ]);
        }

        $entries = $this->cache->getEntries('conv-1', 20);

        $this->assertCount(20, $entries);
        // Most recently used first (op-25 down to op-6)
        $this->assertEquals('op-25', $entries[0]['operationId']);
        $this->assertEquals('op-6', $entries[19]['operationId']);
    }

    #[Test]
    public function cache_never_exceeds_max_capacity_with_50_inserts()
    {
        // Use maxEntries=20 to match the new default
        $this->cache = new OperationCache(20, new Repository($this->store));

        // Insert 50 unique operations
        for ($i = 1; $i <= 50; $i++) {
            $this->cache->put('conv-1', 'op-' . $i, [
                'operationId' => 'op-' . $i,
                'summary' => 'Operation ' . $i,
                'method' => 'GET',
                'path' => '/op/' . $i,
                'paramSchema' => null,
            ]);
        }

        // Cache should never exceed 20 entries
        $this->assertEquals(20, $this->cache->count('conv-1'), 'Cache count should be exactly 20');

        // Only the last 20 operations (op-31 through op-50) should remain
        $entries = $this->cache->getEntries('conv-1', 50);
        $this->assertCount(20, $entries);

        // First 30 operations should be evicted
        for ($i = 1; $i <= 30; $i++) {
            $this->assertNull(
                $this->cache->get('conv-1', 'op-' . $i),
                "op-{$i} should have been evicted"
            );
        }

        // Last 20 operations should be present
        for ($i = 31; $i <= 50; $i++) {
            $result = $this->cache->get('conv-1', 'op-' . $i);
            $this->assertNotNull(
                $result,
                "op-{$i} should still be in cache"
            );
            $this->assertEquals('op-' . $i, $result['operationId']);
        }
    }

    #[Test]
    public function single_entry_cache_evicts_immediately()
    {
        $this->cache = new OperationCache(1, new Repository($this->store));

        // Add op-a
        $this->cache->put('conv-1', 'op-a', [
            'operationId' => 'op-a',
            'summary' => 'A',
            'method' => 'GET',
            'path' => '/a',
            'paramSchema' => null,
        ]);

        // Add op-b — should evict op-a immediately
        $this->cache->put('conv-1', 'op-b', [
            'operationId' => 'op-b',
            'summary' => 'B',
            'method' => 'GET',
            'path' => '/b',
            'paramSchema' => null,
        ]);

        // op-a should be evicted
        $this->assertNull($this->cache->get('conv-1', 'op-a'), 'op-a should have been evicted');

        // op-b should remain
        $result = $this->cache->get('conv-1', 'op-b');
        $this->assertNotNull($result, 'op-b should exist');
        $this->assertEquals('op-b', $result['operationId']);

        // Cache count should be exactly 1
        $this->assertEquals(1, $this->cache->count('conv-1'));
    }

    #[Test]
    public function rapid_eviction_cycles_stable()
    {
        $this->cache = new OperationCache(20, new Repository($this->store));

        // Perform 100 add-evict cycles with unique operations
        for ($i = 1; $i <= 100; $i++) {
            $this->cache->put('conv-1', 'op-' . $i, [
                'operationId' => 'op-' . $i,
                'summary' => 'Operation ' . $i,
                'method' => 'GET',
                'path' => '/op/' . $i,
                'paramSchema' => null,
            ]);

            // Count should never exceed 20 at any point
            $this->assertLessThanOrEqual(
                20,
                $this->cache->count('conv-1'),
                "Count should not exceed 20 after inserting op-{$i}"
            );
        }

        // Final count should be exactly 20
        $this->assertEquals(20, $this->cache->count('conv-1'));

        // All entries in cache should be from the last 20 inserts (op-81 through op-100)
        $entries = $this->cache->getEntries('conv-1', 50);
        $this->assertCount(20, $entries);

        // First 80 operations should be evicted
        for ($i = 1; $i <= 80; $i++) {
            $this->assertNull(
                $this->cache->get('conv-1', 'op-' . $i),
                "op-{$i} should have been evicted"
            );
        }

        // Last 20 operations should be present
        for ($i = 81; $i <= 100; $i++) {
            $result = $this->cache->get('conv-1', 'op-' . $i);
            $this->assertNotNull(
                $result,
                "op-{$i} should still be in cache"
            );
            $this->assertEquals('op-' . $i, $result['operationId']);
        }
    }

    #[Test]
    public function readd_evicted_operation_with_new_details()
    {
        $this->cache = new OperationCache(3, new Repository($this->store));

        // Add op-a, op-b, op-c
        $this->cache->put('conv-1', 'op-a', [
            'operationId' => 'op-a',
            'summary' => 'A',
            'method' => 'GET',
            'path' => '/a',
            'paramSchema' => null,
        ]);

        $this->cache->put('conv-1', 'op-b', [
            'operationId' => 'op-b',
            'summary' => 'B',
            'method' => 'GET',
            'path' => '/b',
            'paramSchema' => null,
        ]);

        $this->cache->put('conv-1', 'op-c', [
            'operationId' => 'op-c',
            'summary' => 'C',
            'method' => 'GET',
            'path' => '/c',
            'paramSchema' => null,
        ]);

        // Access op-a to make it MRU
        $this->cache->get('conv-1', 'op-a');

        // Add op-d — should evict op-b (least recently used)
        $this->cache->put('conv-1', 'op-d', [
            'operationId' => 'op-d',
            'summary' => 'D',
            'method' => 'GET',
            'path' => '/d',
            'paramSchema' => null,
        ]);

        // Confirm op-b is evicted
        $this->assertNull($this->cache->get('conv-1', 'op-b'), 'op-b should be evicted');

        // Re-add op-b with different summary
        $this->cache->put('conv-1', 'op-b', [
            'operationId' => 'op-b',
            'summary' => 'B (re-added)',
            'method' => 'POST',
            'path' => '/b/v2',
            'paramSchema' => null,
        ]);

        // op-b should be accessible with new summary
        $result = $this->cache->get('conv-1', 'op-b');
        $this->assertNotNull($result, 'op-b should be re-added');
        $this->assertEquals('B (re-added)', $result['summary']);
        $this->assertEquals('POST', $result['method']);
        $this->assertEquals('/b/v2', $result['path']);

        // op-b should be at MRU position (last in getEntries order, first when reversed)
        $entries = $this->cache->getEntries('conv-1');
        $this->assertEquals('op-b', $entries[0]['operationId'], 'op-b should be at MRU position (first in getEntries)');
    }

    #[Test]
    public function degrades_gracefully_when_store_throws()
    {
        $throwingStore = new class implements \Illuminate\Contracts\Cache\Store {
            public function get($key) { throw new \RuntimeException('Store unavailable'); }
            public function many(array $keys) { throw new \RuntimeException('Store unavailable'); }
            public function put($key, $value, $ttl) { throw new \RuntimeException('Store unavailable'); }
            public function putMany(array $values, $ttl) { throw new \RuntimeException('Store unavailable'); }
            public function forever($key, $value) { throw new \RuntimeException('Store unavailable'); }
            public function forget($key) { throw new \RuntimeException('Store unavailable'); }
            public function increment($key, $value = 1) { throw new \RuntimeException('Store unavailable'); }
            public function decrement($key, $value = 1) { throw new \RuntimeException('Store unavailable'); }
            public function getPrefix() { return ''; }
            public function lock($name, $seconds = 0, $options = []) { throw new \RuntimeException('Store unavailable'); }
            public function restoreLock(\Illuminate\Contracts\Cache\Lock $lock) { throw new \RuntimeException('Store unavailable'); }
            public function flush($regex = '') { throw new \RuntimeException('Store unavailable'); }
        };

        $repo = new \Illuminate\Cache\Repository($throwingStore);
        $cache = new OperationCache(3, $repo);

        // FR-009: every operation degrades to an empty cache, none may throw.
        $cache->put('conv-1', 'op-1', [
            'operationId' => 'op-1',
            'summary'     => 'Test operation',
            'method'      => 'GET',
            'path'        => '/test',
            'paramSchema' => null,
        ]);

        $this->assertNull($cache->get('conv-1', 'op-1'));
        $this->assertSame([], $cache->getSummaries('conv-1'));
        $this->assertSame([], $cache->getEntries('conv-1'));
        $this->assertSame(0, $cache->count('conv-1'));

        $cache->forget('conv-1');
    }

    #[Test]
    public function put_applies_ttl_from_config()
    {
        // Track TTL values passed to put()
        $capturedTtls = [];
        $trackingStore = new class($capturedTtls) implements \Illuminate\Contracts\Cache\Store, \Illuminate\Contracts\Cache\LockProvider {
            private $capturedTtls;

            public function __construct(&$capturedTtls)
            {
                $this->capturedTtls = &$capturedTtls;
            }

            public function get($key) { return null; }
            public function many(array $keys) { return []; }
            public function put($key, $value, $ttl) {
                $this->capturedTtls[] = $ttl;
            }
            public function putMany(array $values, $ttl) { }
            public function forever($key, $value) { }
            public function forget($key) { }
            public function increment($key, $value = 1) { return 0; }
            public function decrement($key, $value = 1) { return 0; }
            public function getPrefix() { return ''; }
            public function lock($name, $seconds = 0, $options = []) {
                return new \Illuminate\Cache\NoLock($name, $seconds);
            }
            public function restoreLock($name, $owner) { }
            public function flush($regex = '') { }
        };

        $repo = new \Illuminate\Cache\Repository($trackingStore);
        $cache = new OperationCache(20, $repo);

        $cache->put('conv-ttl', 'op-ttl', [
            'operationId' => 'op-ttl',
            'summary'     => 'TTL test',
            'method'      => 'GET',
            'path'        => '/ttl',
            'paramSchema' => null,
        ]);

        $this->assertNotEmpty($capturedTtls, 'put() should have called store->put() with a TTL');
        $expectedTtl = 86400; // default from config
        $this->assertEquals($expectedTtl, $capturedTtls[0], 'TTL should match config default');
    }

    #[Test]
    public function every_write_refreshes_ttl()
    {
        // Track call count to put() on the store
        $putCallCount = 0;
        $trackingStore = new class($putCallCount) implements \Illuminate\Contracts\Cache\Store, \Illuminate\Contracts\Cache\LockProvider {
            private $putCallCount;

            public function __construct(&$putCallCount)
            {
                $this->putCallCount = &$putCallCount;
            }

            public function get($key) { return null; }
            public function many(array $keys) { return []; }
            public function put($key, $value, $ttl) {
                $this->putCallCount++;
            }
            public function putMany(array $values, $ttl) { }
            public function forever($key, $value) { }
            public function forget($key) { }
            public function increment($key, $value = 1) { return 0; }
            public function decrement($key, $value = 1) { return 0; }
            public function getPrefix() { return ''; }
            public function lock($name, $seconds = 0, $options = []) {
                return new \Illuminate\Cache\NoLock($name, $seconds);
            }
            public function restoreLock($name, $owner) { }
            public function flush($regex = '') { }
        };

        $repo = new \Illuminate\Cache\Repository($trackingStore);
        $cache = new OperationCache(5, $repo);

        // Put 3 different operations — each should trigger a store->put()
        $cache->put('conv-ttl', 'op-1', [
            'operationId' => 'op-1', 'summary' => 'Op 1', 'method' => 'GET', 'path' => '/1', 'paramSchema' => null,
        ]);
        $cache->put('conv-ttl', 'op-2', [
            'operationId' => 'op-2', 'summary' => 'Op 2', 'method' => 'GET', 'path' => '/2', 'paramSchema' => null,
        ]);
        $cache->put('conv-ttl', 'op-3', [
            'operationId' => 'op-3', 'summary' => 'Op 3', 'method' => 'GET', 'path' => '/3', 'paramSchema' => null,
        ]);

        $this->assertEquals(3, $putCallCount, 'Each put() should refresh the TTL via store->put()');
    }
}
