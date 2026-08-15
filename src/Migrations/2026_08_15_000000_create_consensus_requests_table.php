<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * consensus_requests — one row per user question submitted with
     * multi-opinion mode enabled (104-multi-agent-consensus,
     * data-model.md §1). Contributor Responses are reused, not new —
     * ordinary agent_delegations rows sharing this row's batch_id
     * (data-model.md §2), exactly as 103 tagged Delegation rows with
     * managed_task_id/part_id rather than inventing a parallel table.
     *
     * Plain Eloquent, not EloquentMultiChainBridge-backed, no SoftDeletes —
     * system-written, execution-trace-adjacent data, the same category
     * agent_delegations/agent_runs/managed_tasks already established
     * (Constitution Principle III). Own started_at/completed_at columns,
     * no Eloquent timestamps.
     *
     * No DB-level FKs anywhere on this table — matches agent_delegations'
     * and managed_tasks' own no-FK posture.
     */
    public function up(): void
    {
        if (Schema::hasTable('consensus_requests')) {
            return;
        }

        Schema::create('consensus_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id');
            $table->uuid('owner_user_id');
            $table->uuid('coordinator_agent_id')->nullable();
            $table->longText('question');
            $table->uuid('answer_message_id')->nullable();
            $table->uuid('batch_id')->nullable();
            $table->unsignedInteger('dispatched_count');
            $table->unsignedInteger('quorum_required')->nullable();
            $table->unsignedInteger('successful_count')->nullable();
            $table->enum('status', ['in_progress', 'completed', 'insufficient_quorum', 'single_contributor_fallback', 'failed']);
            $table->enum('agreement_classification', ['agreed', 'materially_disagreed', 'no_consensus'])->nullable();
            $table->longText('reconciled_answer')->nullable();
            $table->json('disagreement_detail')->nullable();
            $table->text('independence_note')->nullable();
            $table->decimal('estimated_additional_cost', 20, 10)->nullable();
            $table->decimal('actual_additional_cost', 20, 10)->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('started_at', 6);
            $table->timestamp('completed_at', 6)->nullable();

            $table->index('conversation_id');
            $table->index('owner_user_id');
            $table->index('coordinator_agent_id');
            $table->index('answer_message_id');
            $table->index('batch_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consensus_requests');
    }
};
