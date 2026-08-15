<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * managed_tasks — one row per manager-driven task (103-manager-agent,
     * data-model.md §1). One dedicated `channel = 'managed-task'`
     * Conversation per row (research.md D1), never reused.
     *
     * Plain Eloquent, not EloquentMultiChainBridge-backed, no SoftDeletes —
     * system-written, execution-trace-adjacent data, the same category
     * agent_delegations/agent_runs already established (Constitution
     * Principle III). Own started_at/completed_at columns, no Eloquent
     * timestamps.
     *
     * No DB-level FKs anywhere on this table — matches agent_delegations'
     * and agent_runs' own no-FK posture.
     */
    public function up(): void
    {
        if (Schema::hasTable('managed_tasks')) {
            return;
        }

        Schema::create('managed_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id')->unique();
            $table->uuid('owner_user_id');
            $table->uuid('manager_agent_id')->nullable();
            $table->longText('original_request');
            $table->enum('status', ['in_progress', 'completed', 'completed_with_shortfalls', 'failed'])->default('in_progress');
            $table->unsignedInteger('round_ceiling');
            $table->unsignedInteger('rounds_used')->default(0);
            $table->unsignedInteger('max_seconds');
            $table->timestamp('last_progress_at', 6);
            $table->longText('final_response')->nullable();
            $table->text('shortfall_note')->nullable();
            $table->text('conflict_note')->nullable();
            $table->timestamp('started_at', 6);
            $table->timestamp('completed_at', 6)->nullable();

            $table->index('owner_user_id');
            $table->index('status');
            $table->index('last_progress_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_tasks');
    }
};
