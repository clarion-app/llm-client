<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * managed_task_parts — a distinct, self-contained, bounded slice of a
     * managed task, produced by the manager's own decomposition
     * (103-manager-agent, data-model.md §2).
     *
     * Plain Eloquent, not EloquentMultiChainBridge-backed. Unlike
     * managed_tasks, a part is a mutable, repeatedly-updated record, so
     * ordinary Eloquent timestamps() are used rather than a manual
     * started_at/completed_at pair.
     *
     * No DB-level FKs anywhere on this table — matches managed_tasks' and
     * agent_delegations' own no-FK posture.
     */
    public function up(): void
    {
        if (Schema::hasTable('managed_task_parts')) {
            return;
        }

        Schema::create('managed_task_parts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('managed_task_id');
            $table->unsignedInteger('sequence');
            $table->text('description');
            $table->enum('state', ['not_yet_assigned', 'out_for_assignment', 'out_for_correction', 'accepted', 'reported_as_shortfall'])->default('not_yet_assigned');
            $table->uuid('current_delegation_id')->nullable();
            $table->uuid('accepted_delegation_id')->nullable();
            $table->text('accepted_summary')->nullable();
            $table->text('shortfall_reason')->nullable();
            $table->unsignedInteger('assignment_count')->default(0);
            $table->timestamp('created_at', 6);
            $table->timestamp('updated_at', 6);

            $table->index('managed_task_id');
            $table->index(['managed_task_id', 'state']);
            $table->index('current_delegation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_task_parts');
    }
};
