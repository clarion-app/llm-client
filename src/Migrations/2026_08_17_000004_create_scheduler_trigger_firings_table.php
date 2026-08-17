<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * scheduler_trigger_firings — the record that one logical trigger event
     * has already been claimed, and the run it produced.
     *
     * Append-only, derived bookkeeping: deliberately not bridged, not soft
     * deleted, and carrying only created_at, matching
     * budget_threshold_notifications and CostSummary/UsageSummary.
     */
    public function up(): void
    {
        if (Schema::hasTable('scheduler_trigger_firings')) {
            return;
        }

        Schema::create('scheduler_trigger_firings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('trigger_id');

            // Identifies the logical event, not the attempt to handle it:
            // "schedule:{trigger_id}:{Y-m-d\TH:i}" for a schedule trigger's
            // due minute, "condition:{trigger_id}:{ISO8601}" for the instant
            // a condition was observed becoming true. trigger_id is the first
            // component, so two different triggers due at the same moment can
            // never collide on one another's key.
            $table->string('fire_key');

            // The agent_runs row this firing produced. Nullable: filled in
            // just after the run opens, and legitimately left null when run
            // tracing is disabled — the firing still happened and is still
            // deduped either way. Deliberately not a foreign key, matching
            // budget_threshold_notifications.ceiling_id, so purging run
            // history never cascades over this record.
            $table->uuid('run_id')->nullable();

            $table->timestamp('created_at')->useCurrent();

            // This unique index IS the dedup guarantee, not a data-integrity
            // afterthought: insertOrIgnore() returning 1 means this process
            // claimed the event and may dispatch the run, 0 means someone
            // else already claimed it. It must never be weakened to a plain
            // index — doing so turns every overlapping evaluator tick into a
            // duplicate run of the same defined work.
            $table->unique(['trigger_id', 'fire_key'], 'scheduler_trigger_firings_latch_unique');

            $table->index('run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduler_trigger_firings');
    }
};
