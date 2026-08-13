<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether a given agent currently accepts new conversations (active) or
     * does not (deactivated) — a reversible state distinct from the agent's
     * definition, version history, and everything it has produced (data-model.md
     * §1, research.md D1).
     *
     * Defaults true so every pre-existing agent, and every agent created by
     * the unmodified AgentService::create()/clone() paths, is active unless
     * someone explicitly deactivates it. Written only by
     * AgentService::activate()/deactivate() — no controller, route, or other
     * service ever sets it directly.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('agents', 'is_active')) {
            Schema::table('agents', function (Blueprint $table) {
                $table->boolean('is_active')->default(true)->after('cloned_from_agent_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
