<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agent_run_actions')) {
            return;
        }

        Schema::create('agent_run_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('run_id');
            $table->uuid('step_id');
            $table->enum('action_type', ['llm_request', 'tool_invocation', 'context_reshape']);
            $table->string('target', 256)->nullable();
            $table->uuid('attempt_group_id')->nullable();
            $table->uuid('parent_action_id')->nullable();
            $table->enum('outcome', ['in_progress', 'awaiting_confirmation', 'success', 'failure', 'unfinished'])->default('in_progress');
            $table->string('failure_reason', 512)->nullable();
            $table->timestamp('paused_at', 6)->nullable();
            $table->timestamp('started_at', 6);
            $table->timestamp('ended_at', 6)->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->text('content')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['run_id', 'started_at']);
            $table->index(['step_id', 'started_at']);
            $table->index('attempt_group_id');
            $table->index('parent_action_id');
            $table->index(['run_id', 'outcome']);
            $table->index(['action_type', 'started_at']);
            $table->index(['attempt_group_id', 'target']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_run_actions');
    }
};
