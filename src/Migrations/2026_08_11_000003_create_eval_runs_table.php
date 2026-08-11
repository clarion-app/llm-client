<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('eval_runs')) {
            return;
        }

        Schema::create('eval_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // The suite this run executed. No foreign key (package
            // convention — spending_ceilings/agent_run_steps both omit
            // FKs). Not unique: a suite may have many runs (FR-018).
            $table->uuid('suite_id');

            // Snapshot of suite.agent_identifier at start time, so display
            // stays correct even if the suite is later renamed/reassigned.
            $table->string('agent_label', 255);

            // Snapshot of the resolved inference server/model (D12). Both
            // remain null only on a failed_to_start row, where resolution
            // never completed.
            $table->uuid('server_id')->nullable();
            $table->string('model', 255)->nullable();

            $table->string('status', 30);

            // count() of the eval_run_cases snapshot (D7). 0 is valid and
            // meaningful (FR-020: an empty suite is refused before this
            // row would ever be created, so 0 here only ever appears on a
            // failed_to_start row).
            $table->unsignedInteger('case_count');

            // Populated only when status = failed_to_start (D12) or
            // incomplete (D8's exhausted-recovery case).
            $table->text('failure_reason')->nullable();

            $table->timestamp('started_at');

            // Set when status transitions to completed, incomplete, or
            // failed_to_start. Null while in_progress.
            $table->timestamp('completed_at')->nullable();

            // updated_at is load-bearing: ResolveStalledEvalRunsCommand
            // (D8) reads it to find stale in_progress runs, so every
            // case-completion write that touches this row must bump it.
            $table->timestamps();

            $table->index('suite_id');
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eval_runs');
    }
};
