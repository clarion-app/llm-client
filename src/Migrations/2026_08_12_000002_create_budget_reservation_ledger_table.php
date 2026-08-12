<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('budget_reservation_ledger')) {
            return;
        }

        Schema::create('budget_reservation_ledger', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // A plain string rather than an enum column, matching
            // spending_ceilings.scope_type's own reasoning: adding a
            // future scope kind must be an additive code change, never an
            // ALTER TABLE on a table that may already carry live rows.
            $table->string('scope_type', 16);

            // The installation sentinel (SpendingCeiling::INSTALLATION_SCOPE_ID)
            // or a user UUID. Never nullable — a nullable column would make
            // MySQL's "every NULL is distinct" rule defeat any uniqueness
            // reasoning.
            $table->uuid('scope_id');

            // The sum of every currently-held cost_reservations row's
            // estimated_amount targeting this scope. Same precision as
            // cost_summaries.priced_cost_total/spending_ceilings.amount so a
            // comparison between them never rounds either side.
            $table->decimal('reserved_total', 20, 10)->default(0);

            // Matches cost_summaries' own $timestamps = false; only
            // updated_at shape — this table is never soft-deleted and has
            // no meaningful created_at distinct from its first insertOrIgnore.
            $table->timestamp('updated_at')->useCurrent();

            // A genuine unique constraint, not a plain index — unlike
            // spending_ceilings/rate_limits/conversation_work_ceilings, this
            // table has no soft deletes and no restore-a-prior-row concern,
            // so uniqueness is correct and is what makes insertOrIgnore
            // idempotent under concurrency (data-model.md §1).
            $table->unique(['scope_type', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_reservation_ledger');
    }
};
