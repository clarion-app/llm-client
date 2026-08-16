<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * task_workspace_entries — a single, immutable record within a
     * managed task's shared working area (108-shared-task-workspace,
     * data-model.md §1). One row per TaskWorkspaceService::recordEntry()
     * call; no update path anywhere in this feature, only insert and
     * bulk-delete (discardForTask()).
     *
     * No DB-level FK to managed_tasks/agents — matches agent_delegations'/
     * agent_messages' own no-FK posture for this package's execution-trace
     * -adjacent tables. owner_user_id is denormalized from
     * ManagedTask.owner_user_id at write time (defense-in-depth for the
     * ownership-scoped read path, data-model.md §1).
     *
     * Plain Eloquent (TaskWorkspaceEntry model), not EloquentMultiChainBridge
     * -backed, no SoftDeletes (Constitution Principle III — matches
     * Delegation/ManagedTask/AgentRun's own established precedent).
     */
    public function up(): void
    {
        if (Schema::hasTable('task_workspace_entries')) {
            return;
        }

        Schema::create('task_workspace_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('managed_task_id');
            $table->uuid('owner_user_id');
            $table->uuid('author_agent_id');
            $table->text('content');
            $table->timestamp('created_at', 6);

            $table->index('managed_task_id');
            $table->index('owner_user_id');
            $table->index('author_agent_id');
            $table->index('created_at');
            $table->index(['managed_task_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_workspace_entries');
    }
};
