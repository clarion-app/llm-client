<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per handoff event — a recorded transfer of responsibility
     * for a conversation from one agent to another, forming part of the
     * conversation's own history (093-agent-handoff, data-model.md §1).
     *
     * Append-only: no code path in this feature ever updates
     * from_agent_id/to_agent_id/to_agent_version_id/position/created_at
     * after creation. disclosed_at is the sole field ever written a
     * second time.
     */
    public function up(): void
    {
        if (Schema::hasTable('conversation_handoffs')) {
            return;
        }

        Schema::create('conversation_handoffs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id');
            $table->unsignedInteger('position');
            $table->uuid('from_agent_id')->nullable();
            $table->uuid('to_agent_id');
            $table->uuid('to_agent_version_id');
            $table->timestamp('created_at');
            $table->timestamp('disclosed_at')->nullable();

            $table->index('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_handoffs');
    }
};
