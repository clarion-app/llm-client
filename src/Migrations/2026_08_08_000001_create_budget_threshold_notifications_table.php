<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('budget_threshold_notifications')) {
            return;
        }

        Schema::create('budget_threshold_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // The scope the warning is *about* — 'installation' or 'user' —
            // which is not necessarily the originating ceiling's own
            // scope_type (a user_default ceiling warns about a specific user).
            $table->string('scope_type', 16);
            $table->uuid('scope_id');

            $table->string('period_type', 8);
            $table->date('period_start');

            // 'approach' | 'reached'
            $table->string('kind', 16);

            // The ceiling that fired it, for audit only. Nullable and
            // deliberately NOT a foreign key, so removing a ceiling never
            // cascades over history.
            $table->uuid('ceiling_id')->nullable();

            // The figure at the moment the latch was won: an auditable
            // record of why the warning fired, never a second tally and
            // never read by enforcement.
            $table->decimal('consumption_at_fire', 20, 10);

            $table->timestamp('created_at')->useCurrent();

            // This unique index IS the atomic once-per-period test-and-set,
            // not merely a data-integrity constraint: insertOrIgnore()
            // returning 1 means this process won the latch and 0 means the
            // warning already fired. It must never be weakened to a plain
            // index. Because period_start is part of the key, a new period
            // is a new key — the warning fires again with nothing to clear
            // and no reset job.
            $table->unique(['scope_type', 'scope_id', 'period_type', 'period_start', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_threshold_notifications');
    }
};
