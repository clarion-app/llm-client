<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;

class MigrationConsistencyTest extends TestCase
{
    /** @test T043 — servers migration down() references correct table name */
    public function servers_migration_drops_correct_table()
    {
        $migrationPath = __DIR__ . '/../../src/Migrations/2023_09_27_000000_create_servers_table.php';
        $content = file_get_contents($migrationPath);

        // Ensure down() drops 'llm_servers', not 'servers'
        $this->assertStringContainsString("Schema::dropIfExists('llm_servers')", $content);
        $this->assertStringNotContainsString("Schema::dropIfExists('servers')", $content);
    }

    /** @test T043 — all migrations use anonymous classes */
    public function migrations_use_anonymous_classes()
    {
        $migrationFiles = glob(__DIR__ . '/../../src/Migrations/*.php');

        foreach ($migrationFiles as $file) {
            $content = file_get_contents($file);
            $this->assertStringContainsString(
                'return new class extends Migration',
                $content,
                "Migration file " . basename($file) . " should use anonymous class"
            );
        }
    }

    /** @test T043 — user settings migration drops correct table */
    public function user_settings_migration_drops_correct_table()
    {
        $migrationPath = __DIR__ . '/../../src/Migrations/2026_03_30_000000_create_llm_user_settings_table.php';
        $content = file_get_contents($migrationPath);

        $this->assertStringContainsString("Schema::dropIfExists('llm_user_settings')", $content);
    }
}
