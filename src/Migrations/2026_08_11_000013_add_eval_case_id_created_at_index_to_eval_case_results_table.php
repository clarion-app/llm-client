<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive-only: adds one index to the existing eval_case_results
     * table, no column changed or dropped. Without this index, a per-case
     * "most recent N results" read (ORDER BY created_at DESC LIMIT n,
     * filtered by eval_case_id) has no efficient access path — the
     * existing unique(run_id, eval_case_id) index cannot serve an
     * eval_case_id-only lookup, since eval_case_id is not its leading
     * column — so the engine falls back to scanning matching rows in
     * something closer to table order and sorting them, a cost that grows
     * with the table's total size rather than staying bounded by the
     * requested limit. Measured directly against a real MariaDB instance
     * at ten times a seeded history's volume, without this index a
     * per-case capped read's wall-clock cost grew over 12x rather than
     * staying flat.
     */
    public function up(): void
    {
        if (Schema::hasTable('eval_case_results') && !Schema::hasIndex('eval_case_results', 'eval_case_results_eval_case_id_created_at_index')) {
            Schema::table('eval_case_results', function (Blueprint $table) {
                $table->index(['eval_case_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('eval_case_results') && Schema::hasIndex('eval_case_results', 'eval_case_results_eval_case_id_created_at_index')) {
            Schema::table('eval_case_results', function (Blueprint $table) {
                $table->dropIndex('eval_case_results_eval_case_id_created_at_index');
            });
        }
    }
};
