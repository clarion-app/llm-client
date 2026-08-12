<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rate_limits')) {
            return;
        }

        Schema::create('rate_limits', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // A plain string rather than an enum column: adding a future
            // scope kind must be an additive code change, never an
            // ALTER TABLE on a table that may already carry live rows.
            $table->string('scope_type', 16);

            // The user UUID for scope_type='user'; the all-zeros
            // installation sentinel for 'user_default'. Never nullable —
            // a nullable column would make MySQL's "every NULL is
            // distinct" rule defeat any uniqueness reasoning.
            $table->uuid('scope_id');

            // The limit. Null only when waived = true.
            $table->unsignedInteger('max_requests')->nullable();

            // The window's duration in seconds. Null only when
            // waived = true. No upper or lower bound is imposed here — an
            // operator-chosen one-second or one-week window is accepted.
            $table->unsignedInteger('window_seconds')->nullable();

            $table->boolean('waived')->default(false);

            $table->timestamps();
            $table->softDeletes();

            // A plain index, NOT unique — the spending_ceilings/model_prices
            // precedent. SoftDeletes and a unique constraint interact badly
            // in both directions: a soft-deleted row keeps the key
            // occupied so a previously-removed limit can never be
            // re-added, while including deleted_at in the key lets two
            // live rows through on MySQL. RateLimitService is the sole
            // write path and restores-and-updates a soft-deleted row
            // instead.
            $table->index(['scope_type', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_limits');
    }
};
