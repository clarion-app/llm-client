<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds origin to the existing agent_delegations table
     * (109-agent-as-capability, data-model.md §6). Purely additive: every
     * row written by 098/099/101/103's own delegate_to_helper path defaults
     * to 'delegate_to_helper', remaining fully valid unchanged.
     *
     * Read-only, audit/reconstruction purpose only (FR-020) — no runtime
     * branch anywhere reads this column; EffectiveBoundResolver's ancestor
     * walk and resolveAndValidate()'s depth computation treat every
     * Delegation row identically regardless of origin.
     *
     * Matches 2026_08_15_000002_add_manager_columns_to_agent_delegations_table.php's
     * own Schema::hasColumn()-guarded additive shape.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('agent_delegations', 'origin')) {
            Schema::table('agent_delegations', function (Blueprint $table) {
                $table->enum('origin', ['delegate_to_helper', 'capability_offering'])->default('delegate_to_helper');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('agent_delegations', 'origin')) {
            Schema::table('agent_delegations', function (Blueprint $table) {
                $table->dropColumn('origin');
            });
        }
    }
};
