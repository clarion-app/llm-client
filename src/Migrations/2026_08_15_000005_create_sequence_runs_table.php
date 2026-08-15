<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * sequence_runs — one execution of a stage_sequence_definitions row
     * (105-stage-pipeline, data-model.md §3). `owner_user_id` is
     * denormalized from the definition at run creation (research.md D10),
     * matching ManagedTask.owner_user_id's own denormalization from its
     * conversation. `conversation_id` is a dedicated Conversation created
     * at run-invocation time, bound to the definition's
     * coordinator_agent_id (data-model.md §8) — the $parentConversation
     * every stage's DelegationService::delegate() call in this run is made
     * against.
     *
     * `status` — in_progress|resumed|completed|failed — `resumed` is
     * treated identically to `in_progress` by every "is this run still
     * running" check (research.md D3); a resumed run is the same row, not
     * a new one.
     *
     * Plain Eloquent, not EloquentMultiChainBridge-backed, no SoftDeletes
     * (Constitution Principle III). Own started_at/completed_at columns,
     * no Eloquent timestamps. No DB-level FKs anywhere on this table —
     * matches every sibling table in this package's own no-FK posture.
     */
    public function up(): void
    {
        if (Schema::hasTable('sequence_runs')) {
            return;
        }

        Schema::create('sequence_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('sequence_definition_id');
            $table->uuid('owner_user_id');
            $table->uuid('conversation_id');
            $table->enum('status', ['in_progress', 'resumed', 'completed', 'failed']);
            $table->longText('starting_input')->nullable();
            $table->unsignedInteger('current_stage_position')->nullable();
            $table->timestamp('last_progress_at', 6);
            $table->text('failure_reason')->nullable();
            $table->timestamp('resumed_at', 6)->nullable();
            $table->unsignedInteger('resume_count')->default(0);
            $table->timestamp('started_at', 6);
            $table->timestamp('completed_at', 6)->nullable();

            $table->index('sequence_definition_id');
            $table->index('owner_user_id');
            $table->index('status');
            $table->index('last_progress_at');
            $table->index('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sequence_runs');
    }
};
