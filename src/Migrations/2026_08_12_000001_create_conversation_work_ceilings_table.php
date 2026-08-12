<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('conversation_work_ceilings')) {
            return;
        }

        Schema::create('conversation_work_ceilings', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // A plain string rather than an enum column, matching
            // rate_limits.scope_type's identical reasoning: adding a
            // future scope kind must be an additive code change, never an
            // ALTER TABLE on a table that may already carry live rows.
            // Wider than rate_limits.scope_type's string(16) because
            // 'conversation_default' (20 chars) is longer than
            // 'user_default' (12 chars).
            $table->string('scope_type', 20);

            // The conversation UUID for scope_type='conversation'; the
            // all-zeros installation sentinel (RateLimit::INSTALLATION_SCOPE_ID)
            // for 'conversation_default'. Never a user id. Never nullable —
            // a nullable column would make MySQL's "every NULL is
            // distinct" rule defeat any uniqueness reasoning.
            $table->uuid('scope_id');

            // The ceiling. Null only when waived = true.
            $table->unsignedInteger('max_work_units')->nullable();

            // The window's duration in seconds. Null only when
            // waived = true. No upper or lower bound is imposed here — an
            // operator-chosen duration is accepted directly. Also doubles
            // as the idle-reset threshold — there is no second "idle
            // timeout" field.
            $table->unsignedInteger('window_seconds')->nullable();

            $table->boolean('waived')->default(false);

            $table->timestamps();
            $table->softDeletes();

            // A plain index, NOT unique — the rate_limits/spending_ceilings
            // precedent. SoftDeletes and a unique constraint interact badly
            // in both directions: a soft-deleted row keeps the key
            // occupied so a previously-removed ceiling can never be
            // re-added, while including deleted_at in the key lets two
            // live rows through on MySQL. ConversationWorkCeilingService is
            // the sole write path and restores-and-updates a soft-deleted
            // row instead.
            $table->index(['scope_type', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_work_ceilings');
    }
};
