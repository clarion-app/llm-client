<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the six Delegation Result columns (data-model.md §1,
     * 099-result-aggregation) to the existing agent_delegations table
     * (098-delegation-protocol). Purely additive — every row 098 has
     * already written, or will write before this feature's own code
     * runs, remains valid with these columns null/default. No existing
     * column is altered or dropped, no index is added.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('agent_delegations', 'result_status')) {
            Schema::table('agent_delegations', function (Blueprint $table) {
                $table->enum('result_status', ['success', 'partial', 'failure'])->nullable();
                $table->string('result_reason', 32)->nullable();
                $table->text('result_summary')->nullable();
                $table->longText('result_output')->nullable();
                $table->text('result_undone')->nullable();
                $table->boolean('result_truncated')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('agent_delegations', 'result_status')) {
            Schema::table('agent_delegations', function (Blueprint $table) {
                $table->dropColumn([
                    'result_status',
                    'result_reason',
                    'result_summary',
                    'result_output',
                    'result_undone',
                    'result_truncated',
                ]);
            });
        }
    }
};
