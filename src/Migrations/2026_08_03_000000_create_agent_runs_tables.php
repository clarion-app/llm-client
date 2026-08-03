<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each table is guarded independently: a partially-created schema must
        // still converge, rather than an early return leaving the remaining
        // tables absent.
        if (!Schema::hasTable('agent_runs')) {
            Schema::create('agent_runs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->enum('kind', ['interactive', 'system_initiated']);
                $table->uuid('user_id');
                $table->uuid('conversation_id')->nullable();
                $table->string('source', 64)->nullable();
                $table->enum('end_state', ['in_progress', 'completed', 'failed', 'stopped_early', 'abandoned'])->default('in_progress');
                $table->string('end_reason', 256)->nullable();
                // Sub-second precision: duration_ms is derived from these two
                // endpoints (data-model.md §3).
                $table->timestamp('started_at', 6);
                $table->timestamp('ended_at', 6)->nullable();
                $table->unsignedBigInteger('duration_ms')->nullable();
                $table->unsignedInteger('step_count')->default(0);
                $table->timestamp('created_at')->useCurrent();

                $table->index('conversation_id');
                $table->index(['user_id', 'started_at']);
                $table->index(['end_state', 'started_at']);
            });
        }

        if (!Schema::hasTable('agent_run_steps')) {
            Schema::create('agent_run_steps', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('run_id');
                $table->unsignedInteger('position');
                $table->uuid('attempt_group_id')->nullable();
                $table->enum('end_state', ['in_progress', 'completed', 'failed', 'stopped_early', 'abandoned'])->default('in_progress');
                $table->string('end_reason', 256)->nullable();
                $table->timestamp('started_at', 6);
                $table->timestamp('ended_at', 6)->nullable();
                $table->unsignedBigInteger('duration_ms')->nullable();
                $table->unsignedBigInteger('wait_ms')->nullable();
                $table->unsignedSmallInteger('attempt_count')->default(1);

                $table->unique(['run_id', 'position']);
                $table->index('attempt_group_id');
                $table->index(['run_id', 'started_at']);
            });
        }

        if (Schema::hasTable('agent_run_messages')) {
            return;
        }

        Schema::create('agent_run_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('run_id');
            $table->uuid('message_id');
            $table->enum('relation', ['trigger', 'reply']);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['run_id', 'relation']);
            $table->index('message_id');
            $table->index('run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_run_messages');
        Schema::dropIfExists('agent_run_steps');
        Schema::dropIfExists('agent_runs');
    }
};
