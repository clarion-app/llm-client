<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('eval_judgment_consistency_samples')) {
            return;
        }

        Schema::create('eval_judgment_consistency_samples', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // The case whose rubric is being tested for consistency.
            $table->uuid('eval_case_id');

            // The pinned version supplying criteria — the version current
            // at request time.
            $table->uuid('eval_case_version_id');

            // Which rubric_judgment expectation on that version was
            // tested.
            $table->unsignedInteger('expectation_index');

            // The prior run's case result whose produced_response was
            // reused as the fixed input, when sourced from a real run.
            // Null when the operator instead supplied response text
            // directly.
            $table->uuid('source_eval_case_result_id')->nullable();

            // The fixed input judged sample_size times.
            $table->text('response_text');

            $table->unsignedInteger('sample_size');
            $table->unsignedInteger('judged_count');
            $table->unsignedInteger('unjudged_count');

            // The raw list of every judged repeat's score, in the order
            // produced. [] when judged_count = 0.
            $table->json('scores');

            // Null iff judged_count = 0 — no scores exist to summarize.
            $table->unsignedTinyInteger('score_min')->nullable();
            $table->unsignedTinyInteger('score_max')->nullable();
            $table->decimal('score_mean', 4, 2)->nullable();

            // Snapshot of the consistency flag threshold at the moment
            // this sample was computed, so a later config change never
            // silently reinterprets an old sample's flagged_unstable
            // value.
            $table->unsignedTinyInteger('flag_threshold_used')->nullable();

            // Null iff judged_count = 0 — "insufficient data to assess
            // stability" is a distinct, honest state from "assessed and
            // found stable."
            $table->boolean('flagged_unstable')->nullable();

            // The operator who triggered the check.
            $table->uuid('requested_by');

            // Insert-only — written once, in full, when the request
            // completes.
            $table->timestamp('created_at')->useCurrent();

            $table->index('eval_case_id');
            $table->index('source_eval_case_result_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eval_judgment_consistency_samples');
    }
};
