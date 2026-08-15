<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether a given agent is the caller's default handler — the
     * routing fallback consulted only when no candidate scores a match
     * (data-model.md §1, research.md D5). At most one true row per
     * user_id, enforced in AgentService::setDefaultHandler(), never at
     * the DB level.
     *
     * Defaults false so every pre-existing agent, and every agent
     * created by the unmodified AgentService::create()/clone() paths,
     * has no default handler until someone explicitly sets one. Written
     * only by AgentService::setDefaultHandler()/clearDefaultHandler() —
     * no controller, route, or other service ever sets it directly.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('agents', 'is_default_handler')) {
            Schema::table('agents', function (Blueprint $table) {
                $table->boolean('is_default_handler')->default(false)->after('is_active');
            });
        }
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('is_default_handler');
        });
    }
};
