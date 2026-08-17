<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * conversations.scheduler_trigger_id — the one conversation a scheduler
     * trigger's firings all run in. Written by RunSchedulerTriggerJob alone,
     * lazily, at the trigger's first firing: a trigger that never fires never
     * accumulates an empty conversation, and every later firing appends to the
     * same conversation rather than creating a throwaway one per tick.
     *
     * Never set by ConversationController::store() or any other route — a
     * conversation created the ordinary way always leaves this null.
     *
     * Mirrors add_coding_project_id_to_conversations_table's additive shape:
     * one nullable column plus its index, guarded by Schema::hasColumn().
     */
    public function up(): void
    {
        if (!Schema::hasColumn('conversations', 'scheduler_trigger_id')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->uuid('scheduler_trigger_id')->nullable()->after('coding_project_id');
                $table->index('scheduler_trigger_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['scheduler_trigger_id']);
            $table->dropColumn('scheduler_trigger_id');
        });
    }
};
