<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * stage_sequence_definitions — the named, reusable "run this again"
     * definition (105-stage-pipeline, data-model.md §1). `coordinator_agent_id`
     * resolves the parent-agent-scoping gap tasks.md's Grounding note item 1
     * flagged: DelegationService::delegate()'s resolveAndValidate() requires
     * its $parentConversation->agent_id to have an active
     * AgentHelperAssignment naming the helper, a check scoped to a parent
     * agent, never to a user directly — so a coordinator agent is named
     * explicitly here, mirroring ManagedTask.manager_agent_id/
     * ConsensusRequest.coordinator_agent_id's identical precedent
     * (data-model.md §8).
     *
     * Plain Eloquent, not EloquentMultiChainBridge-backed, no SoftDeletes —
     * system-written, execution-trace-adjacent data, the same category
     * agent_delegations/managed_tasks/consensus_requests already
     * established (Constitution Principle III).
     *
     * A definition's Stage rows are immutable at creation (research.md D2)
     * — no versioning in this feature.
     *
     * No DB-level FKs anywhere on this table — matches agent_delegations'/
     * managed_tasks'/consensus_requests' own no-FK posture.
     */
    public function up(): void
    {
        if (Schema::hasTable('stage_sequence_definitions')) {
            return;
        }

        Schema::create('stage_sequence_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('owner_user_id');
            $table->uuid('coordinator_agent_id');
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->timestamps(6);

            $table->index('owner_user_id');
            $table->index('coordinator_agent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_sequence_definitions');
    }
};
