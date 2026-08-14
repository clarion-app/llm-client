<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * agent_delegations — the record of a single parent→helper task
     * handoff (data-model.md §1, 098-delegation-protocol), distinct from
     * the structural parent-helper relationship (agent_helper_assignments,
     * 097) it depends on, and distinct from the run trace it links
     * (agent_runs, 062/070).
     *
     * One row per delegate_to_helper call — never re-opened. A second
     * delegation to the same helper, even within the same parent
     * conversation, is a second, independent row (no identity/upsert
     * semantics, unlike agent_helper_assignments' restore-after-removal
     * idiom).
     *
     * No DB-level FKs anywhere on this table — matches agent_runs'
     * conversation_id and agent_helper_assignments' own no-FK posture.
     */
    public function up(): void
    {
        if (Schema::hasTable('agent_delegations')) {
            return;
        }

        Schema::create('agent_delegations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('parent_conversation_id');
            $table->uuid('parent_agent_id')->nullable();
            $table->uuid('helper_agent_id');
            $table->uuid('helper_conversation_id')->unique();
            $table->uuid('helper_agent_version_id')->nullable();
            $table->uuid('owner_user_id');
            $table->text('task');
            $table->longText('context')->nullable();
            $table->unsignedInteger('depth');
            $table->enum('status', ['in_progress', 'completed', 'exhausted', 'failed']);
            $table->uuid('parent_run_id')->nullable();
            $table->uuid('parent_action_id')->nullable();
            $table->uuid('helper_run_id')->nullable();
            $table->text('outcome_summary')->nullable();
            $table->timestamp('started_at', 6);
            $table->timestamp('completed_at', 6)->nullable();

            $table->index('parent_conversation_id');
            $table->index('helper_agent_id');
            $table->index('owner_user_id');
            $table->index('parent_run_id');
            $table->index('helper_run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_delegations');
    }
};
