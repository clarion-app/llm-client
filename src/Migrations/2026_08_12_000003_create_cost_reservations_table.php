<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cost_reservations')) {
            return;
        }

        Schema::create('cost_reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Every scope key this reservation was placed against at
            // admission time — ["installation"], ["user:<uuid>"], or both.
            // Recorded as a fact, not re-derived at release time
            // (research.md D3): a ceiling removed or reconfigured mid-flight
            // cannot make a release target the wrong row or silently skip
            // one.
            $table->json('scope_keys');

            // Attribution only — null for the same reason 076's null-user
            // direct-admit() call sites are null (research.md D6). Never
            // used to derive scope_keys; always read from the column above.
            $table->uuid('user_id')->nullable();

            // Attribution/debuggability only, mirroring
            // cost_summaries.entity_id for entity_type='conversation'.
            $table->uuid('conversation_id')->nullable();

            // Populated only when this admission opened an agent_runs row.
            // Null whenever tracing is off or the admission used a direct-
            // admit() path that never calls traceSystemRun()/openRun() —
            // see research.md D6 on why the abandonment sweep does not
            // depend on this column being populated.
            $table->uuid('run_id')->nullable();

            // BudgetWorkKind value — observability only, not a branch point
            // for release logic.
            $table->string('work_kind', 16);

            // The amount held. This exact figure, not the eventual actual
            // cost, is what is subtracted from
            // budget_reservation_ledger.reserved_total on release/
            // reconciliation.
            $table->decimal('estimated_amount', 20, 10);

            // Filled only on status = 'reconciled', from
            // MetricsRecorder::recordUsage()'s own computed cost. Null for
            // released/abandoned and, transiently, for held.
            $table->decimal('actual_amount', 20, 10)->nullable();

            // 'held' -> 'reconciled' | 'released' | 'abandoned'.
            $table->string('status', 16);

            // Set once, at creation. The abandonment sweep's cutoff column.
            $table->timestamp('held_at');

            // Set once, on the transition out of held.
            $table->timestamp('resolved_at')->nullable();

            // The abandonment sweep's driving query
            // (WHERE status = 'held' AND held_at < :cutoff), the same
            // shape agent_runs' ['end_state', 'started_at'] index serves
            // ResolveAbandonedRunsCommand's eligibility query.
            $table->index(['status', 'held_at']);

            // RunTraceRecorder::closeRun()'s fallback-release lookup.
            $table->index(['run_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_reservations');
    }
};
