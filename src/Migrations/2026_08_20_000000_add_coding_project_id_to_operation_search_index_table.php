<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 128-project-command-indexing, Phase 2 (Foundational), T007.
 *
 * Adds the nullable, indexed coding_project_id column data-model.md §1
 * describes (no unique, no FK — a workspace has many rows, and this table's
 * existing FK-free design already leaves operation_id itself unconstrained).
 * NULL means "global" (every existing 'operation'/'prompt' row); a
 * type = 'project_command' row sets it to the owning CodingProject's id.
 * No backfill: every pre-existing row's value is already correctly null.
 *
 * Also relaxes package_name to nullable. The original
 * 2026_06_19_000000_create_operation_search_index_table migration declared
 * it NOT NULL, but a type = 'project_command' row has no owning package
 * (data-model.md §1: "package_name is null. Project commands have no
 * owning package") — without this change, every project-command insert
 * would hit a NOT NULL constraint violation. This uses Blueprint::change(),
 * the same idiom the sibling
 * 2026_06_19_000001_add_type_to_operation_search_index migration already
 * uses on this same table's method/path columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('operation_search_index', 'coding_project_id')) {
            Schema::table('operation_search_index', function (Blueprint $table) {
                $table->string('coding_project_id')->nullable()->index()->after('type');
            });
        }

        Schema::table('operation_search_index', function (Blueprint $table) {
            $table->string('package_name')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('operation_search_index', function (Blueprint $table) {
            $table->dropIndex(['coding_project_id']);
            $table->dropColumn('coding_project_id');
        });

        Schema::table('operation_search_index', function (Blueprint $table) {
            $table->string('package_name')->nullable(false)->change();
        });
    }
};
