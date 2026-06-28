<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add embedding vector column to llm_memory_entries table.
     *
     * Uses MariaDB VECTOR<F32,N> type for native vector similarity search,
     * with JSON fallback for SQLite and JSONB for PostgreSQL.
     */
    public function up(): void
    {
        $dimension = config('llm-client.memory.embedding.dimension', 1536);

        if (!Schema::hasColumn('llm_memory_entries', 'embedding')) {
            $driver = DB::getDriverName();

            if ($driver === 'mysql') {
                // MariaDB 11.0+ / MySQL 8.0+ VECTOR type via raw SQL
                DB::statement(sprintf(
                    'ALTER TABLE llm_memory_entries ADD COLUMN embedding VECTOR<F32,%d> NULL',
                    $dimension
                ));
            } elseif ($driver === 'pgsql') {
                // PostgreSQL: JSONB fallback
                Schema::table('llm_memory_entries', function (Blueprint $table) {
                    $table->jsonb('embedding')->nullable();
                });
            } else {
                // SQLite: JSON fallback (used in tests)
                Schema::table('llm_memory_entries', function (Blueprint $table) {
                    $table->json('embedding')->nullable();
                });
            }
        }
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        if (Schema::hasColumn('llm_memory_entries', 'embedding')) {
            $driver = DB::getDriverName();

            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE llm_memory_entries DROP COLUMN embedding');
            } else {
                Schema::table('llm_memory_entries', function (Blueprint $table) {
                    $table->dropColumn('embedding');
                });
            }
        }
    }
};
