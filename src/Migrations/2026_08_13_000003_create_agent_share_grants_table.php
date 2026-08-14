<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * agent_share_grants — the relationship between one owned Agent, one
     * recipient user, and a permission level (data-model.md §1,
     * 096-agent-sharing). One lifetime row per (agent_id, recipient_user_id)
     * pair: deleted_at doubles as the revocation timestamp (research.md
     * D7) — non-null means currently revoked, null means currently active —
     * so a re-grant after a revocation restores the same row rather than
     * inserting a second one.
     *
     * No DB-level cascade on agent_id — matches Agent's own established
     * "archiving never cascades" posture
     * (2026_08_12_000007_create_agents_table.php:65-66).
     */
    public function up(): void
    {
        if (Schema::hasTable('agent_share_grants')) {
            return;
        }

        Schema::create('agent_share_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('agent_id');
            $table->uuid('owner_user_id');
            $table->uuid('recipient_user_id');
            $table->string('permission', 20);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['agent_id', 'recipient_user_id']);
            $table->index('agent_id');
            $table->index('owner_user_id');
            $table->index('recipient_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_share_grants');
    }
};
