<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('eval_reference_designations')) {
            return;
        }

        Schema::create('eval_reference_designations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // The scope key — copied from the designated run's own
            // agent_label at write time, never supplied independently by
            // the caller, so a designation can never be recorded under a
            // scope mismatching the run it names.
            $table->string('agent_label', 255);

            // The run becoming the reference. No FK (package convention —
            // eval_runs/eval_run_cases both omit FKs).
            $table->uuid('run_id');

            // The operator who made the designation. Nullable defensively,
            // though every route reaching this write is auth:api +
            // OperatorAccess-gated, so a real value is always available in
            // practice.
            $table->uuid('designated_by')->nullable();

            // Insert-only — append-only designation record, never a
            // mutation of an earlier designation. Moving the reference is
            // always a new row.
            $table->timestamp('created_at')->useCurrent();

            // "Current reference for this agent" / "reference active as
            // of timestamp T".
            $table->index(['agent_label', 'created_at']);

            // "Has this run ever been a reference, and when" — supporting,
            // since run_id has no FK to walk backward from otherwise.
            $table->index('run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eval_reference_designations');
    }
};
