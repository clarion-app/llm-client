<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds managed_task_id/part_id to the existing agent_delegations table
     * (103-manager-agent, data-model.md §3). Purely additive: every row
     * written by 098/099/101's own delegate_to_helper path stays null for
     * both, remaining fully valid unchanged.
     *
     * managed_task_id is set on every delegation anywhere in a managed
     * task's tree (a direct assign_part assignment, and any further
     * delegation a helper itself makes), inherited the same way depth/
     * parent_run_id already propagate through
     * DelegationService::resolveAndValidate() (research.md D2/D10).
     * part_id is set only on a delegation created directly by assign_part.
     *
     * Matches 2026_08_14_000003_add_batch_columns_to_agent_delegations_table.php's
     * own Schema::hasColumn()-guarded additive shape.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('agent_delegations', 'managed_task_id')) {
            Schema::table('agent_delegations', function (Blueprint $table) {
                $table->uuid('managed_task_id')->nullable()->index();
                $table->uuid('part_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('agent_delegations', 'managed_task_id')) {
            Schema::table('agent_delegations', function (Blueprint $table) {
                $table->dropColumn(['managed_task_id', 'part_id']);
            });
        }
    }
};
