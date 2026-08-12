<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('degradation_events')) {
            return;
        }

        Schema::create('degradation_events', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // The run this decision governs. Nullable only in the
            // theoretical case linkRun() is reached with a null run id
            // (never in practice — linkRun() is only called from inside
            // openRun()'s own success path, where a run id was just
            // minted); kept nullable rather than removing the row entirely
            // so a metrics query never has to special-case a genuinely-
            // orphaned decision differently from a normal one. No FK
            // constraint — this package places no cross-table FK
            // constraints on agent_runs anywhere (cost_reservations.run_id
            // is this column's own sibling precedent).
            $table->uuid('run_id')->nullable();

            $table->uuid('conversation_id');

            // Null exactly when the conversation itself has no owning
            // user — mirrors agent_runs.user_id's own nullability
            // reasoning where it applies.
            $table->uuid('user_id')->nullable();

            // The governing rung. FK-free, matching cost_reservations's
            // own convention — a rung later deleted must never make an
            // old event unreadable; the id is kept for audit even if the
            // row it names is gone.
            $table->uuid('reduction_step_id');

            // Snapshotted at decision time — a LimitAxis value — so a
            // later change to the rung's own axis column can never
            // retroactively change what an already-recorded event says
            // happened.
            $table->string('axis', 20);

            // The consumption ratio that crossed the threshold,
            // snapshotted.
            $table->decimal('ratio', 5, 4);

            $table->timestamp('applied_at');

            // No created_at/updated_at — matches cost_reservations's own
            // $timestamps = false shape.

            // The three lookups forRun()/a future rollup/US4's status
            // check each need.
            $table->index(['conversation_id']);
            $table->index(['user_id']);
            $table->index(['run_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('degradation_events');
    }
};
