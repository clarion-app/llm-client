<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('spending_ceilings')) {
            return;
        }

        Schema::create('spending_ceilings', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // A plain string rather than an enum column: adding a future
            // scope kind must be an additive code change, never an
            // ALTER TABLE on a table that may already carry live rows.
            $table->string('scope_type', 16);

            // The user UUID for scope_type='user'; the all-zeros
            // installation sentinel for 'installation' and 'user_default'.
            // Never nullable — a nullable column would make MySQL's "every
            // NULL is distinct" rule defeat any uniqueness reasoning.
            $table->uuid('scope_id');

            // Same precision as cost_summaries.priced_cost_total, so
            // comparing a ceiling to a consumption figure never has to
            // round either side. Null only when waived = true.
            $table->decimal('amount', 20, 10)->nullable();

            $table->string('period_type', 8);
            $table->string('enforcement_mode', 8);
            $table->decimal('approach_threshold', 5, 4)->default(0.8);
            $table->boolean('waived')->default(false);

            $table->timestamps();
            $table->softDeletes();

            // A plain index, NOT unique — the model_prices precedent.
            // SoftDeletes and a unique constraint interact badly in both
            // directions: a soft-deleted row keeps the key occupied so a
            // previously-removed ceiling can never be re-added, while
            // including deleted_at in the key lets two live rows through on
            // MySQL. SpendingCeilingService is the sole write path and
            // restores-and-updates a soft-deleted row instead.
            $table->index(['scope_type', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spending_ceilings');
    }
};
