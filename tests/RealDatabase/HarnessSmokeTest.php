<?php

namespace Tests\RealDatabase;

use Illuminate\Support\Facades\DB;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * T019: Trivial gated smoke scenario.
 *
 * Asserts the connection is live and migrated.
 * With Docker: runs green.
 * Without Docker: skips cleanly.
 * With STRICT=1 and no Docker: fails naming what's missing.
 */
#[Group('real-db')]
class HarnessSmokeTest extends RealDatabaseTestCase
{
    #[Test]
    public function connectionIsLiveAndMigrated(): void
    {
        $this->assertReady();

        // Assert we can query the database.
        $version = DB::select('SELECT VERSION()')[0];
        $this->assertNotNull($version);

        // Assert migrations ran — the operation_search_index table should exist.
        $tables = DB::select(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
        );
        $tableNames = array_map(fn ($t) => $t->TABLE_NAME, $tables);

        $this->assertContains('operation_search_index', $tableNames,
            'operation_search_index table should exist after migrations');
        $this->assertContains('llm_memory_entries', $tableNames,
            'llm_memory_entries table should exist after migrations');
        $this->assertContains('conversations', $tableNames,
            'conversations table should exist after migrations');
        $this->assertContains('messages', $tableNames,
            'messages table should exist after migrations');
    }

    #[Test]
    public function driverIsMysql(): void
    {
        $this->assertReady();

        $spec = $this->getSpec();
        $this->assertNotNull($spec);
        $this->assertSame('mysql', $spec->driver);
    }
}
