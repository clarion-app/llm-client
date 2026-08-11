<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('eval_case_results')) {
            return;
        }

        Schema::create('eval_case_results', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Denormalized alongside eval_run_case_id below — lets every
            // consumption/listing query (D11) filter directly on run_id
            // without a join through eval_run_cases.
            $table->uuid('run_id');

            // The snapshot row this result completes.
            $table->uuid('eval_run_case_id');

            // Denormalized from the snapshot, for display without a join.
            $table->uuid('eval_case_id');

            // Denormalized from the snapshot — this is the FR-006 value:
            // the exact version of the case's definition it was evaluated
            // against, readable directly off the result row.
            $table->uuid('eval_case_version_id');

            // The dedicated system-owned Conversation this case executed
            // under (D6) — the join key EvalRunConsumptionQuery (D11)
            // uses, and the value SystemConversationIsolationTest (D2)
            // points at.
            $table->uuid('conversation_id');

            $table->string('outcome', 20);

            // The agent's final response text for this case (FR-003).
            // Null only for errored outcomes where no response was ever
            // produced.
            $table->text('produced_response')->nullable();

            // Every tool call attempted across the case's whole turn.
            $table->json('attempted_actions')->default('[]');

            // Per-expectation judgement detail, one entry per expectation
            // on the pinned EvalCaseVersion.
            $table->json('expectation_results');

            // Populated only for outcome = errored.
            $table->text('error_message')->nullable();

            // The one and only write instant. No updated_at is exposed at
            // the model level — a result row is never updated after
            // insert (the EvalCaseVersion/UsageRecord precedent).
            $table->timestamp('created_at')->useCurrent();

            // Defensive, in addition to EvalCaseExecutor's own idempotency
            // check (D8) — belt-and-suspenders against the redelivery race
            // the idempotency check is meant to prevent.
            $table->unique(['run_id', 'eval_case_id']);

            // Listing a run's results, computing its summary.
            $table->index('run_id');

            // The isolation test's own lookup, and manual support/debugging.
            $table->index('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eval_case_results');
    }
};
