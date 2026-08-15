<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * agent_messages — the persisted record of every attempted inter-agent
     * message send, delivered or not (107-agent-message-protocol,
     * data-model.md §1). One row per AgentMessageService::send() call.
     *
     * No DB-level FK anywhere on this table — from_agent_id/to_agent_id are
     * not FKs to agents.id, matching agent_delegations.helper_agent_id's own
     * no-FK posture (an agent may later be soft-deleted without breaking
     * historical message rows); run_id has no FK either, matching
     * messages.run_id/tool_invocation_records.run_id/usage_records.run_id
     * (069-trace-id-propagation).
     *
     * Plain Eloquent (AgentMessage model), not EloquentMultiChainBridge-
     * backed, no SoftDeletes (research.md D7 — matches Delegation's/
     * AgentRun's own established precedent for write-once inter-agent audit
     * rows).
     */
    public function up(): void
    {
        if (Schema::hasTable('agent_messages')) {
            return;
        }

        Schema::create('agent_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('from_agent_id')->nullable();
            $table->uuid('to_agent_id')->nullable();
            $table->uuid('owner_user_id');
            $table->uuid('conversation_id')->nullable();
            $table->uuid('run_id')->nullable();
            $table->json('content')->nullable();
            $table->json('context')->nullable();
            $table->text('expected_response')->nullable();
            $table->enum('status', ['delivered', 'refused', 'rejected_oversized', 'unavailable']);
            $table->string('refusal_reason')->nullable();
            $table->unsignedInteger('size_bytes');
            $table->timestamps();

            $table->index('owner_user_id');
            $table->index('conversation_id');
            $table->index('run_id');
            $table->index('from_agent_id');
            $table->index('to_agent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_messages');
    }
};
