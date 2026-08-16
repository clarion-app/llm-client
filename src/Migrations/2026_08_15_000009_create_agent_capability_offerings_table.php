<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * agent_capability_offerings — the configured relationship stating that
     * a specific agent (offered_agent_id) stands behind a specific
     * capability entry, and which specific caller agent (caller_agent_id)
     * may see and invoke it (109-agent-as-capability, data-model.md §1).
     * Distinct from agent_helper_assignments (097) — an agent can be
     * offered as a capability without being configured as a helper.
     *
     * One lifetime row per ordered (offered_agent_id, caller_agent_id)
     * pair: deleted_at doubles as the withdrawal timestamp (mirrors
     * agent_helper_assignments.deleted_at) — non-null means withdrawn, null
     * means currently active, so a re-offer after a withdrawal restores the
     * same row rather than inserting a second one.
     *
     * No DB-level cascade on offered_agent_id/caller_agent_id — matches
     * agent_helper_assignments' own "archiving never cascades" posture.
     *
     * Mirrors 2026_08_14_000000_create_agent_helper_assignments_table.php's
     * exact shape. Author only, never executed (Constitution §V).
     */
    public function up(): void
    {
        if (Schema::hasTable('agent_capability_offerings')) {
            return;
        }

        Schema::create('agent_capability_offerings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('offered_agent_id');
            $table->uuid('caller_agent_id');
            $table->uuid('owner_user_id');
            $table->string('capability_name');
            $table->text('capability_description');
            $table->text('input_description');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['offered_agent_id', 'caller_agent_id']);
            $table->index('offered_agent_id');
            $table->index('caller_agent_id');
            $table->index('owner_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_capability_offerings');
    }
};
