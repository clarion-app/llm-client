<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * agent_helper_assignments — the relationship between one parent Agent
     * and one helper Agent, both owned by the same user (data-model.md §1,
     * 097-subagent-model). Stores membership only — no permitted-operations
     * data of any kind, since that is always computed live from each
     * agent's own current definition (research.md D3).
     *
     * One lifetime row per ordered (parent_agent_id, helper_agent_id) pair:
     * deleted_at doubles as the removal timestamp (research.md D4, mirrors
     * agent_share_grants.deleted_at) — non-null means currently removed,
     * null means currently active — so a re-assignment after a removal
     * restores the same row rather than inserting a second one.
     *
     * No DB-level cascade on parent_agent_id/helper_agent_id — matches
     * Agent's and agent_share_grants' own established "archiving never
     * cascades" posture.
     */
    public function up(): void
    {
        if (Schema::hasTable('agent_helper_assignments')) {
            return;
        }

        Schema::create('agent_helper_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('parent_agent_id');
            $table->uuid('helper_agent_id');
            $table->uuid('owner_user_id');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['parent_agent_id', 'helper_agent_id']);
            $table->index('parent_agent_id');
            $table->index('helper_agent_id');
            $table->index('owner_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_helper_assignments');
    }
};
