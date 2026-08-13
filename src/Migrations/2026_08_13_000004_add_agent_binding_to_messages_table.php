<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The agent identity actually responsible for producing each message,
     * stamped at creation from ConversationHandoff::currentAgentIdentityFor()
     * (093-agent-handoff, data-model.md §2) — never updated afterward.
     *
     * DELIBERATE naming collision, called out explicitly here: this
     * `agent_id` is unrelated to AgentRun.agent_id / UsageRecord.agent_id /
     * ToolInvocationRecord.agent_id, which are all the pre-existing
     * Conversation.character label — a different concept entirely, never
     * touched by this feature (research.md D7).
     */
    public function up(): void
    {
        if (Schema::hasColumn('messages', 'agent_id')) {
            return;
        }

        Schema::table('messages', function (Blueprint $table) {
            $table->uuid('agent_id')->nullable()->after('tool_data');
            $table->uuid('agent_version_id')->nullable()->after('agent_id');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['agent_id', 'agent_version_id']);
        });
    }
};
