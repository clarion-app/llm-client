<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('eval_judgments')) {
            return;
        }

        Schema::create('eval_judgments', function (Blueprint $table) {
            // Pre-minted by the caller (EvalCaseExecutor /
            // EvalJudgmentConsistencyService), never a creating listener —
            // so a sibling eval_case_results.expectation_results[] entry
            // written in the same logical operation can reference it.
            $table->uuid('id')->primary();

            // The case result this judgment belongs to. Null only for a
            // judgment produced purely as a consistency-check repeat.
            $table->uuid('eval_case_result_id')->nullable();

            // The pinned version whose expectations[] this judgment scored
            // against — never re-resolved against a later edit.
            $table->uuid('eval_case_version_id');

            // Position within eval_case_version.expectations[] this
            // judgment is for.
            $table->unsignedInteger('expectation_index');

            // Snapshot of the expectation's criteria at judgment time,
            // read directly off this row, never re-read from the
            // (possibly since-edited) live case.
            $table->text('criteria');

            // The exact response text judged. Null only when the case
            // produced no response at all.
            $table->text('response_text')->nullable();

            $table->string('status', 20);

            // 1..config('llm-client.eval_judging.score_scale_max'). Null
            // iff status = unjudged.
            $table->unsignedTinyInteger('score')->nullable();

            // Null iff status = unjudged.
            $table->text('justification')->nullable();

            // Populated only when status = unjudged.
            $table->text('unjudged_reason')->nullable();

            // Snapshot of the judge model/server actually used. Null when
            // status = unjudged because resolution itself failed.
            $table->string('model', 255)->nullable();
            $table->uuid('server_id')->nullable();

            // The dedicated judge-attribution Conversation id — the join
            // key judging-consumption summarization uses.
            $table->uuid('conversation_id')->nullable();

            // Set iff this judgment was produced as one of a consistency
            // sample's repeats.
            $table->uuid('consistency_sample_id')->nullable();

            // Insert-only — no updated_at (the UsageRecord /
            // ToolInvocationRecord precedent).
            $table->timestamp('created_at')->useCurrent();

            $table->index('eval_case_result_id');
            $table->index('consistency_sample_id');
            $table->index(['eval_case_version_id', 'expectation_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eval_judgments');
    }
};
