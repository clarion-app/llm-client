<?php

namespace Tests\RealDatabase;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * T022: Schema fidelity at dimension 8.
 *
 * Migrate at dimension 8 and assert:
 * - FULLTEXT index exists over (type, searchable_text)
 * - VECTOR columns created with engine-native type at dimension 8
 * - The migration pair guesses the index name and converges
 */
#[Group('real-db')]
class SchemaFidelityTest extends RealDatabaseTestCase
{
    /**
     * SchemaFidelityTest runs at dimension 8 (the default from RealDatabaseTestCase).
     * No override needed.
     */

    #[Test]
    public function fulltextIndexExistsOverTypeAndSearchableText(): void
    {
        $this->assertReady();

        // Read the index metadata from INFORMATION_SCHEMA.
        $indexes = DB::select(
            "SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns "
            . "FROM INFORMATION_SCHEMA.STATISTICS "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'operation_search_index' "
            . "AND INDEX_TYPE = 'FULLTEXT' "
            . "GROUP BY INDEX_NAME"
        );

        $indexRows = [];
        foreach ($indexes as $row) {
            $indexRows[$row->INDEX_NAME] = $row->columns;
        }

        // There should be at least one FULLTEXT index.
        $this->assertGreaterThan(
            0,
            count($indexRows),
            'Expected at least one FULLTEXT index on operation_search_index '
            . '(query: FULLTEXT indexes on operation_search_index)'
        );

        // One of them must cover both 'type' and 'searchable_text'.
        $found = false;
        foreach ($indexRows as $name => $columns) {
            if ($columns === 'type,searchable_text') {
                $found = true;
                break;
            }
        }

        $this->assertTrue(
            $found,
            "Expected FULLTEXT index over (type, searchable_text). "
            . "Found indexes: " . json_encode($indexRows)
            . ' (query: FULLTEXT index columns on operation_search_index)'
        );
    }

    #[Test]
    public function vectorColumnsUseNativeTypeAtDimension8(): void
    {
        $this->assertReady();

        // Check llm_memory_entries.embedding column type.
        $columns = DB::select(
            "SELECT COLUMN_NAME, COLUMN_TYPE "
            . "FROM INFORMATION_SCHEMA.COLUMNS "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'llm_memory_entries' "
            . "AND COLUMN_NAME = 'embedding'"
        );

        $this->assertNotEmpty(
            $columns,
            'llm_memory_entries should have an embedding column '
            . '(query: embedding column on llm_memory_entries)'
        );

        $columnType = $columns[0]->COLUMN_TYPE;
        $this->assertStringContainsString(
            'vector(8)',
            strtolower($columnType),
            "Expected VECTOR(8) column type, got '{$columnType}' "
            . '(query: embedding column type on llm_memory_entries)'
        );

        // Also check episodic_memories.
        $episodicColumns = DB::select(
            "SELECT COLUMN_NAME, COLUMN_TYPE "
            . "FROM INFORMATION_SCHEMA.COLUMNS "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'episodic_memories' "
            . "AND COLUMN_NAME = 'embedding'"
        );

        $this->assertNotEmpty(
            $episodicColumns,
            'episodic_memories should have an embedding column '
            . '(query: embedding column on episodic_memories)'
        );

        $episodicType = $episodicColumns[0]->COLUMN_TYPE;
        $this->assertStringContainsString(
            'vector(8)',
            strtolower($episodicType),
            "Expected VECTOR(8) on episodic_memories, got '{$episodicType}' "
            . '(query: embedding column type on episodic_memories)'
        );
    }

    #[Test]
    public function migrationPairConvergesOnSingleFulltextIndex(): void
    {
        $this->assertReady();

        // The first migration creates a FULLTEXT on (searchable_text).
        // The second migration drops it (guessing the name) and creates one on (type, searchable_text).
        // We assert that after both migrations, there is exactly one FULLTEXT index
        // and it covers (type, searchable_text).

        $indexes = DB::select(
            "SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns "
            . "FROM INFORMATION_SCHEMA.STATISTICS "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'operation_search_index' "
            . "AND INDEX_TYPE = 'FULLTEXT' "
            . "GROUP BY INDEX_NAME"
        );

        $fulltextCount = count($indexes);

        $this->assertSame(
            1,
            $fulltextCount,
            "Expected exactly 1 FULLTEXT index after migration pair, found {$fulltextCount}. "
            . 'The migration pair should have converged (old index dropped, new index created). '
            . '(query: FULLTEXT index count on operation_search_index)'
        );

        $columns = $indexes[0]->columns;
        $this->assertSame(
            'type,searchable_text',
            $columns,
            "Expected FULLTEXT index over (type, searchable_text), got ({$columns}). "
            . 'The migration pair did not converge correctly. '
            . '(query: FULLTEXT index columns on operation_search_index)'
        );
    }
}
