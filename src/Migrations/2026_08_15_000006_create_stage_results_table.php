<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * stage_results — the recorded outcome of one stage within one run
     * (105-stage-pipeline, data-model.md §4). One row per
     * (sequence_run_id, stage_id) pair is pre-created up front (all
     * `pending`) at run-invocation time so "which stages have not yet
     * started" is a plain query with no gaps to infer.
     *
     * `handoff_rejected` is distinct from `failed` (research.md D4/D9,
     * FR-007/SC-005) — recorded against the stage that was about to run
     * when its declared input_schema rejected the previous stage's output
     * (or, for the first stage, the run's starting_input); that stage's
     * own execution is never attempted in that case.
     *
     * Plain Eloquent, not EloquentMultiChainBridge-backed, no SoftDeletes
     * (Constitution Principle III). Own started_at/completed_at columns,
     * no Eloquent timestamps. No DB-level FKs anywhere on this table —
     * matches every sibling table in this package's own no-FK posture.
     */
    public function up(): void
    {
        if (Schema::hasTable('stage_results')) {
            return;
        }

        Schema::create('stage_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('sequence_run_id');
            $table->uuid('stage_id');
            $table->enum('status', ['pending', 'running', 'completed', 'failed', 'handoff_rejected'])->default('pending');
            $table->uuid('delegation_id')->nullable();
            $table->longText('input')->nullable();
            $table->longText('output')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('started_at', 6)->nullable();
            $table->timestamp('completed_at', 6)->nullable();

            $table->index('sequence_run_id');
            $table->index('stage_id');
            $table->unique(['sequence_run_id', 'stage_id']);
            $table->index(['sequence_run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_results');
    }
};
