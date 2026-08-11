<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('eval_suites')) {
            return;
        }

        Schema::create('eval_suites', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Operator-entered. Uniqueness of (agent_identifier, name) is
            // enforced by EvalSuiteService — the sole write path — not by a
            // DB constraint (see the index note below).
            $table->string('name', 255);

            // Free-text, operator-entered. No foreign key: no persisted
            // Agent model exists yet in this codebase.
            $table->string('agent_identifier', 255);

            $table->timestamps();
            $table->softDeletes();

            // A plain index, NOT unique — the spending_ceilings/model_prices
            // precedent. SoftDeletes and a unique constraint interact badly
            // in both directions: a soft-deleted row would occupy the key
            // forever (blocking reuse of a name after archival), while
            // MySQL's "every NULL is distinct" rule would admit two live
            // duplicates the other way. EvalSuiteService upholds "at most
            // one live suite per (agent_identifier, name)" as a property of
            // the code, not the schema.
            $table->index(['agent_identifier', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eval_suites');
    }
};
