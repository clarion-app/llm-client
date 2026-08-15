<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records why a conversation's agent_id was bound — automatic,
     * default, or explicit (data-model.md §2) — and whether that reason
     * has yet been disclosed to the user. Written once, at the same
     * moment agent_id is first bound; never updated afterward except
     * routing_disclosed_at, which is set the first time
     * AgentLoopService::composeRoutingDisclosure() fires for this
     * conversation.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('conversations', 'routing_reason')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->string('routing_reason', 16)->nullable()->after('ended_at');
                $table->timestamp('routing_disclosed_at')->nullable()->after('routing_reason');
            });
        }
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['routing_reason', 'routing_disclosed_at']);
        });
    }
};
