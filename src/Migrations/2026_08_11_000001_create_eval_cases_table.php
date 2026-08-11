<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('eval_cases')) {
            return;
        }

        Schema::create('eval_cases', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // The owning suite. No DB-level FK — this package's established
            // posture for this shape (spending_ceilings/agent_run_steps both
            // omit them too), relying on application-level integrity and
            // soft deletes rather than ON DELETE CASCADE.
            $table->uuid('suite_id');

            // Points at the eval_case_versions row currently in effect.
            // Nullable at the schema level only because the case row must
            // exist before the first version can reference case_id;
            // EvalCaseService::addCase() sets this in the same transaction
            // that creates both rows, so it is never observed null by any
            // read outside that transaction.
            $table->uuid('current_version_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('suite_id');
            $table->index('current_version_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eval_cases');
    }
};
