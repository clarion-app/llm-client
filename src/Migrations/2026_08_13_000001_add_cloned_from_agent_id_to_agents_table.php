<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record which agent, if any, a given agent was cloned from.
     *
     * A copy produced by AgentService::clone() (091) points back at its
     * immediate source via this nullable, no-FK uuid column (data-model.md
     * §1, research.md D4) — never backfilled, so every pre-existing agent's
     * cloned_from_agent_id stays null, correctly meaning "not a clone."
     */
    public function up(): void
    {
        if (!Schema::hasColumn('agents', 'cloned_from_agent_id')) {
            Schema::table('agents', function (Blueprint $table) {
                $table->uuid('cloned_from_agent_id')->nullable()->after('linked_synced_file_hash');
                $table->index('cloned_from_agent_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropIndex(['cloned_from_agent_id']);
            $table->dropColumn('cloned_from_agent_id');
        });
    }
};
