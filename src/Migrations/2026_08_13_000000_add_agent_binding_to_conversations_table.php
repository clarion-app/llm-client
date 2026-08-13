<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bind a conversation to the exact agent version it started on.
     *
     * A conversation started against a stored agent (087) records which
     * agent, and which of that agent's versions was current at the moment
     * of creation. The binding is immutable for the life of the
     * conversation — later edits to the agent produce new versions, but
     * never change what an already-started conversation recorded.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('conversations', 'agent_id')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->uuid('agent_id')->nullable()->after('character');
                $table->uuid('agent_version_id')->nullable()->after('agent_id');
                $table->index('agent_id');
                $table->index('agent_version_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['agent_id']);
            $table->dropIndex(['agent_version_id']);
            $table->dropColumn('agent_id');
            $table->dropColumn('agent_version_id');
        });
    }
};
