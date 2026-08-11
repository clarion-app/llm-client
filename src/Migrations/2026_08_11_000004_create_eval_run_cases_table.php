<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('eval_run_cases')) {
            return;
        }

        Schema::create('eval_run_cases', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('run_id');

            // The case identity (eval_cases.id) — stable even if the case
            // is later edited or archived.
            $table->uuid('eval_case_id');

            // Pinned at snapshot time from eval_cases.current_version_id
            // (FR-006/D7) — never re-resolved, regardless of later edits
            // to the live case.
            $table->uuid('eval_case_version_id');

            // Snapshot of the suite's cases() ordering at start time — the
            // agent_run_steps.position precedent.
            $table->unsignedInteger('position');

            $table->string('status', 20);

            // Incremented each time RunEvalCaseJob::dispatch() is issued
            // for this row (initial dispatch + every resume()/sweep
            // redispatch) — what ResolveStalledEvalRunsCommand compares
            // against max_stale_sweeps (D8).
            $table->unsignedInteger('dispatch_attempts')->default(0);

            $table->timestamps();

            // Exactly one snapshot row per case per run.
            $table->unique(['run_id', 'eval_case_id']);

            // The query both resume() and the per-job "any pending
            // siblings left" check use.
            $table->index(['run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eval_run_cases');
    }
};
