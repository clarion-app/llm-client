<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * stages — one named unit of work within a stage_sequence_definitions
     * row (105-stage-pipeline, data-model.md §2). `helper_agent_id` is the
     * agent this stage delegates to when it runs (research.md D7) — must
     * have an active AgentHelperAssignment with parent_agent_id = the
     * owning definition's coordinator_agent_id, checked at
     * definition-creation time and again at every run-invocation time
     * (FR-016). `position` is named (not `order`, not an implicit array
     * index) so a future dependency-graph generalization (research.md D1)
     * can add a depends_on_stage_ids column without `position` losing
     * meaning.
     *
     * Plain Eloquent, not EloquentMultiChainBridge-backed, no SoftDeletes
     * (Constitution Principle III). No DB-level FKs anywhere on this table
     * — matches every sibling table in this package's own no-FK posture.
     */
    public function up(): void
    {
        if (Schema::hasTable('stages')) {
            return;
        }

        Schema::create('stages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('sequence_definition_id');
            $table->unsignedInteger('position');
            $table->string('name', 255);
            $table->uuid('helper_agent_id');
            $table->json('input_schema')->nullable();
            $table->json('output_schema')->nullable();
            $table->boolean('is_idempotent')->default(false);
            $table->timestamps(6);

            $table->index('sequence_definition_id');
            $table->unique(['sequence_definition_id', 'position']);
            $table->index('helper_agent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stages');
    }
};
