<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reduction_steps')) {
            return;
        }

        Schema::create('reduction_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // A plain string rather than an enum column, matching
            // rate_limits.scope_type's identical reasoning: a future axis
            // is an additive code change (a new LimitAxis case), never an
            // ALTER TABLE on a table that may already carry live rows.
            $table->string('axis', 20);

            // 0 < ratio <= 1 — the fraction of the axis's ceiling/allowance
            // at which this rung activates. Validated by
            // ReductionLadderService, never at the DB layer alone.
            $table->decimal('threshold_ratio', 5, 4);

            // A model name from the installation's own catalog — no FK, no
            // enum, matching Conversation.model/RoleAssignment.model's own
            // "model name is just a string the provider understands"
            // convention.
            $table->string('substitute_model')->nullable();

            // Optional; when set alongside substitute_model, the substitute
            // is dispatched against this Server row instead of the
            // conversation's own. Null means "same server, different
            // model" — the common case.
            $table->uuid('substitute_server_id')->nullable();

            // A list of ReducibleTool enum values — never raw strings,
            // validated against the enum at write time so a typo can never
            // silently produce a no-op withholding.
            $table->json('withheld_tools')->nullable();

            // 0 < ratio <= 1, multiplies the model-resolved historyBudget
            // (research.md D5).
            $table->decimal('history_budget_ratio', 5, 4)->nullable();

            // A disabled rung is excluded from DegradationGate::evaluate()'s
            // ladder walk entirely — the "operator removes a rung" half of
            // FR-011/US3 Acceptance Scenario 2 without a destructive delete.
            $table->boolean('enabled')->default(true);

            $table->timestamps();
            $table->softDeletes();

            // A plain index, NOT unique — soft-deletes/uniqueness interact
            // badly, the rate_limits/conversation_work_ceilings precedent.
            // Uniqueness of the *live* set is enforced in
            // ReductionLadderService, not the schema.
            $table->index(['axis', 'threshold_ratio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reduction_steps');
    }
};
