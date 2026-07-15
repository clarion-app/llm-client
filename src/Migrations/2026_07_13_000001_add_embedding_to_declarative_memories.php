<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('declarative_memories', 'embedding')) {
            return;
        }

        Schema::table('declarative_memories', function (Blueprint $table) {
            $driver = DB::getDriverName();

            if ($driver === 'mysql') {
                $dimension = config('llm-client.memory.embedding.dimension', 1536);
                DB::statement(sprintf(
                    'ALTER TABLE declarative_memories ADD COLUMN embedding VECTOR(%d) NULL',
                    $dimension
                ));
            } elseif ($driver === 'pgsql') {
                $table->jsonb('embedding')->nullable();
            } else {
                // SQLite and other drivers use JSON fallback
                $table->json('embedding')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('declarative_memories', function (Blueprint $table) {
            $driver = DB::getDriverName();

            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE declarative_memories DROP COLUMN embedding');
            } else {
                $table->dropColumn('embedding');
            }
        });
    }
};
