<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Distinguishes an automatically triggered handoff (D7's
     * unavailability fallback) from an ordinary agent-decided one,
     * purely for disclosure wording (data-model.md §3) — no behavioral
     * branch anywhere else reads it. Null for every handoff written by
     * the existing handleHandoffToAgent() (093, unmodified behavior).
     */
    public function up(): void
    {
        if (!Schema::hasColumn('conversation_handoffs', 'reason')) {
            Schema::table('conversation_handoffs', function (Blueprint $table) {
                $table->string('reason', 32)->nullable()->after('disclosed_at');
            });
        }
    }

    public function down(): void
    {
        Schema::table('conversation_handoffs', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
    }
};
