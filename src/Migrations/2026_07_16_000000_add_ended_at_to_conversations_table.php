<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mark when a conversation session ended.
     *
     * Session end was previously inferred from "the agent finished a response",
     * which fires on every turn. A durable marker lets the end fire once, stay
     * idempotent across idle sweeps, and be cleared when the user returns.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('conversations', 'ended_at')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->timestamp('ended_at')->nullable()->after('is_processing');
                $table->index(['ended_at', 'updated_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['ended_at', 'updated_at']);
            $table->dropColumn('ended_at');
        });
    }
};
