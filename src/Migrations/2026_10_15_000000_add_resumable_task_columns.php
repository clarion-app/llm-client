<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resumable long tasks: the execution state and lease columns on
 * agent_runs, the per-step checkpoint columns on agent_run_steps, the
 * tool-call link columns on agent_run_actions and agent_delegations, and
 * the append-only agent_run_resume_events log (data-model.md §1–§5).
 * Purely additive — every change is guarded by Schema::hasColumn/hasTable
 * and no existing column or index is altered. Never run outside the
 * real-db harness's run-created ephemeral database (constitution §V).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agent_runs')) {
            Schema::table('agent_runs', function (Blueprint $table) {
                if (!Schema::hasColumn('agent_runs', 'checkpoint_version')) {
                    $table->unsignedTinyInteger('checkpoint_version')->nullable();
                }
                if (!Schema::hasColumn('agent_runs', 'resume_mode')) {
                    $table->string('resume_mode', 8)->nullable();
                }
                if (!Schema::hasColumn('agent_runs', 'resume_driver')) {
                    $table->string('resume_driver', 16)->nullable();
                }
                if (!Schema::hasColumn('agent_runs', 'resume_options')) {
                    $table->longText('resume_options')->nullable();
                }
                if (!Schema::hasColumn('agent_runs', 'resume_state')) {
                    $table->string('resume_state', 24)->nullable();
                }
                if (!Schema::hasColumn('agent_runs', 'dispatch_id')) {
                    $table->uuid('dispatch_id')->nullable();
                }
                if (!Schema::hasColumn('agent_runs', 'lease_holder')) {
                    $table->uuid('lease_holder')->nullable();
                }
                if (!Schema::hasColumn('agent_runs', 'lease_expires_at')) {
                    $table->timestamp('lease_expires_at', 6)->nullable();
                }
                if (!Schema::hasColumn('agent_runs', 'last_progress_at')) {
                    $table->timestamp('last_progress_at', 6)->nullable();
                }
                if (!Schema::hasColumn('agent_runs', 'interrupted_at')) {
                    $table->timestamp('interrupted_at', 6)->nullable();
                }
                if (!Schema::hasColumn('agent_runs', 'consecutive_interruptions')) {
                    $table->unsignedSmallInteger('consecutive_interruptions')->default(0);
                }
                if (!Schema::hasColumn('agent_runs', 'resume_count')) {
                    $table->unsignedInteger('resume_count')->default(0);
                }
                if (!Schema::hasColumn('agent_runs', 'provider_attempts')) {
                    $table->unsignedTinyInteger('provider_attempts')->default(0);
                }
                if (!Schema::hasColumn('agent_runs', 'provider_first_failure_at')) {
                    $table->timestamp('provider_first_failure_at', 6)->nullable();
                }
                if (!Schema::hasColumn('agent_runs', 'next_attempt_at')) {
                    $table->timestamp('next_attempt_at', 6)->nullable();
                }
                if (!Schema::hasColumn('agent_runs', 'cancelled_at')) {
                    $table->timestamp('cancelled_at', 6)->nullable();
                }
                if (!Schema::hasIndex('agent_runs', 'agent_runs_end_state_resume_state_lease_expires_at_index')) {
                    $table->index(['end_state', 'resume_state', 'lease_expires_at']);
                }
                if (!Schema::hasIndex('agent_runs', 'agent_runs_end_state_next_attempt_at_index')) {
                    $table->index(['end_state', 'next_attempt_at']);
                }
            });
        }

        if (Schema::hasTable('agent_run_steps')) {
            Schema::table('agent_run_steps', function (Blueprint $table) {
                if (!Schema::hasColumn('agent_run_steps', 'iteration')) {
                    $table->unsignedInteger('iteration')->nullable();
                }
                if (!Schema::hasColumn('agent_run_steps', 'checkpoint_state')) {
                    $table->string('checkpoint_state', 16)->nullable();
                }
                if (!Schema::hasColumn('agent_run_steps', 'checkpoint')) {
                    $table->longText('checkpoint')->nullable();
                }
                if (!Schema::hasColumn('agent_run_steps', 'message_id')) {
                    $table->uuid('message_id')->nullable();
                }
            });
        }

        if (Schema::hasTable('agent_run_actions')) {
            Schema::table('agent_run_actions', function (Blueprint $table) {
                if (!Schema::hasColumn('agent_run_actions', 'tool_call_id')) {
                    $table->string('tool_call_id', 255)->nullable();
                }
                if (!Schema::hasIndex('agent_run_actions', 'agent_run_actions_run_id_tool_call_id_index')) {
                    $table->index(['run_id', 'tool_call_id']);
                }
            });
        }

        if (Schema::hasTable('agent_delegations')) {
            Schema::table('agent_delegations', function (Blueprint $table) {
                if (!Schema::hasColumn('agent_delegations', 'parent_tool_call_id')) {
                    $table->string('parent_tool_call_id', 255)->nullable();
                }
                if (!Schema::hasIndex('agent_delegations', 'agent_delegations_parent_run_id_parent_tool_call_id_index')) {
                    $table->index(['parent_run_id', 'parent_tool_call_id']);
                }
            });
        }

        if (!Schema::hasTable('agent_run_resume_events')) {
            Schema::create('agent_run_resume_events', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('run_id');
                $table->string('kind', 32);
                $table->unsignedInteger('step_position')->nullable();
                $table->unsignedInteger('attempt')->nullable();
                $table->json('payload');
                $table->timestamp('created_at', 6)->useCurrent();

                $table->index(['run_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_run_resume_events');

        $drops = [
            ['agent_runs', 'checkpoint_version', null],
            ['agent_runs', 'resume_mode', null],
            ['agent_runs', 'resume_driver', null],
            ['agent_runs', 'resume_options', null],
            ['agent_runs', 'resume_state', null],
            ['agent_runs', 'dispatch_id', null],
            ['agent_runs', 'lease_holder', null],
            ['agent_runs', 'lease_expires_at', ['end_state', 'resume_state', 'lease_expires_at']],
            ['agent_runs', 'last_progress_at', null],
            ['agent_runs', 'interrupted_at', null],
            ['agent_runs', 'consecutive_interruptions', null],
            ['agent_runs', 'resume_count', null],
            ['agent_runs', 'provider_attempts', null],
            ['agent_runs', 'provider_first_failure_at', null],
            ['agent_runs', 'next_attempt_at', ['end_state', 'next_attempt_at']],
            ['agent_runs', 'cancelled_at', null],
            ['agent_run_steps', 'iteration', null],
            ['agent_run_steps', 'checkpoint_state', null],
            ['agent_run_steps', 'checkpoint', null],
            ['agent_run_steps', 'message_id', null],
            ['agent_run_actions', 'tool_call_id', ['run_id', 'tool_call_id']],
            ['agent_delegations', 'parent_tool_call_id', ['parent_run_id', 'parent_tool_call_id']],
        ];

        foreach ($drops as [$tableName, $column, $index]) {
            if (!Schema::hasTable($tableName) || !Schema::hasColumn($tableName, $column)) {
                continue;
            }
            // The index may have been created by the hand-declared test schema
            // rather than this migration; only drop it if it is present.
            $indexName = $index !== null
                ? $tableName . '_' . implode('_', $index) . '_index'
                : null;
            Schema::table($tableName, function (Blueprint $table) use ($column, $indexName) {
                if ($indexName !== null && Schema::hasIndex($table->getTable(), $indexName)) {
                    $table->dropIndex($indexName);
                }
                $table->dropColumn($column);
            });
        }
    }
};
