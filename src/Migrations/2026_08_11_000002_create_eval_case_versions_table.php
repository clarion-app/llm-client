<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('eval_case_versions')) {
            return;
        }

        Schema::create('eval_case_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // The owning case identity.
            $table->uuid('case_id');

            // 1 at creation, incremented by 1 on every subsequent edit of
            // the same case — the agent_run_steps.position precedent for
            // deterministic, human-readable ordering.
            $table->unsignedInteger('version_number');

            // What the agent is given (FR-003, part 1). Recorded verbatim.
            $table->text('given');

            // What a correct response should look like or do (FR-003, part 2).
            $table->text('expected_behavior');

            // How a response should be judged (FR-003, part 3) — an array of
            // the Expectation shape. Never empty (FR-009; enforced at the
            // service layer, not a DB constraint).
            $table->json('expectations');

            $table->timestamps();

            // Required by EloquentMultiChainBridge, not optional: the trait
            // declares `use SoftDeletes;` internally, so any model using it
            // registers Eloquent's soft-delete global scope whether or not
            // the model re-lists the trait. Omitting this column would make
            // every query against this table fail with a missing-column
            // error. Matches the messages.deleted_at precedent exactly: the
            // column exists, but no code path in this feature ever sets it.
            $table->softDeletes();

            // Two versions of the same case can never claim the same
            // number, and this is the natural "give me version N of case X"
            // lookup a future run capability will use. The usual
            // deleted_at-vs-unique-constraint tension does not apply here:
            // nothing in this feature's write path ever soft-deletes a
            // version row, so deleted_at stays NULL for every row.
            $table->unique(['case_id', 'version_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eval_case_versions');
    }
};
