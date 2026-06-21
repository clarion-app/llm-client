<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operation_search_index', function (Blueprint $table) {
            // Add type column with default 'operation' for backward compatibility
            $table->string('type', 20)->default('operation')->after('id');

            // Make method/path nullable for prompt entries (they don't have HTTP method/path)
            $table->string('method', 10)->nullable()->change();
            $table->string('path', 1000)->nullable()->change();

            // Add prompt_content for prompt entries
            $table->text('prompt_content')->nullable()->after('param_schema');
        });

        // Drop old fulltext index (separate statement to avoid MySQL combining with CREATE)
        // Index name may vary based on Laravel version, try common patterns
        $indexNames = [
            'operation_search_index_searchable_text_index',
            'operation_search_index_searchable_text_fulltext',
            'searchable_text_index',
        ];
        foreach ($indexNames as $indexName) {
            try {
                DB::statement("ALTER TABLE operation_search_index DROP INDEX {$indexName}");
                break;
            } catch (\Exception $e) {
                // Index doesn't exist with this name, try next
                continue;
            }
        }

        // Create new combined fulltext index (skip on SQLite)
        $driver = config('database.default', 'sqlite');
        $connection = DB::connection($driver);
        if ($connection->getDriverName() !== 'sqlite') {
            Schema::table('operation_search_index', function (Blueprint $table) {
                $table->fullText(['type', 'searchable_text']);
            });
        }
    }

    public function down(): void
    {
        // Drop combined fulltext index and recreate original
        Schema::table('operation_search_index', function (Blueprint $table) {
            try {
                $table->dropIndex(['type', 'searchable_text']);
            } catch (\Exception $e) {
                // May have different name
            }
        });

        // Recreate original fulltext index (skip on SQLite)
        $driver = config('database.default', 'sqlite');
        $connection = DB::connection($driver);
        if ($connection->getDriverName() !== 'sqlite') {
            Schema::table('operation_search_index', function (Blueprint $table) {
                $table->fullText(['searchable_text']);
            });
        }

        Schema::table('operation_search_index', function (Blueprint $table) {
            $table->dropColumn('type', 'prompt_content');
            $table->string('method', 10)->nullable(false)->default('')->change();
            $table->string('path', 1000)->nullable(false)->default('')->change();
        });
    }
};
