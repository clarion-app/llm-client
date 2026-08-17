<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * conversations.coding_project_id — the fixed, server-set binding a
     * conversation is pointed at (112-coding-agent, Foundational,
     * data-model.md §2). Set only at conversation creation
     * (ConversationController::store()); no route or tool call ever
     * updates it afterward. Nullable — the overwhelming majority of
     * conversations carry no project binding at all.
     *
     * Mirrors add_channel_to_conversations_table's own additive-column
     * shape (a nullable column plus its own index, guarded by
     * Schema::hasColumn()).
     */
    public function up(): void
    {
        if (!Schema::hasColumn('conversations', 'coding_project_id')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->uuid('coding_project_id')->nullable()->after('agent_version_id');
                $table->index('coding_project_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['coding_project_id']);
            $table->dropColumn('coding_project_id');
        });
    }
};
