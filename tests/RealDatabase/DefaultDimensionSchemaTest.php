<?php

namespace Tests\RealDatabase;

use Illuminate\Support\Facades\DB;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * T022a: Schema fidelity at the shipped default dimension (1536).
 *
 * Separate class from SchemaFidelityTest because migrations read
 * dimension from config at migrate time. This class overrides
 * embeddingDimension to return 1536 and seeds no rows.
 */
#[Group('real-db')]
class DefaultDimensionSchemaTest extends RealDatabaseTestCase
{
    /**
     * Override to use the shipped default dimension (1536).
     */
    protected function embeddingDimension(): int
    {
        return 1536;
    }

    #[Test]
    public function vectorColumnsUseNativeTypeAtDimension1536(): void
    {
        $this->assertReady();

        // Check llm_memory_entries.embedding column type.
        $columns = DB::select(
            "SELECT COLUMN_TYPE "
            . "FROM INFORMATION_SCHEMA.COLUMNS "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'llm_memory_entries' "
            . "AND COLUMN_NAME = 'embedding'"
        );

        $this->assertNotEmpty(
            $columns,
            'llm_memory_entries should have an embedding column at dimension 1536 '
            . '(query: embedding column on llm_memory_entries at dimension 1536)'
        );

        $columnType = $columns[0]->COLUMN_TYPE;
        $this->assertStringContainsString(
            'vector(1536)',
            strtolower($columnType),
            "Expected VECTOR(1536) column type, got '{$columnType}' "
            . '(query: embedding column type on llm_memory_entries at dimension 1536)'
        );
    }
}
