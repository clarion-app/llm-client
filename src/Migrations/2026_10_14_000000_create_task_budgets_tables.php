<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task budgets: one row per task with its ceilings, claimed steps and state,
 * an append-only event log per task, and four nullable columns linking
 * usage records, conversations and ceiling prompts to a task. Purely
 * additive — no existing column or ENUM is altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('task_budgets')) {
            Schema::create('task_budgets', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id')->nullable();
                $table->uuid('root_conversation_id');
                $table->uuid('root_run_id')->nullable();
                $table->uuid('managed_task_id')->nullable();
                $table->string('scope', 16);
                $table->boolean('unattended')->default(false);
                $table->string('state', 16)->default('running');
                $table->string('end_reason', 256)->nullable();
                $table->unsignedInteger('max_steps');
                $table->string('max_steps_origin', 32);
                $table->unsignedInteger('original_max_steps');
                $table->decimal('max_spend', 20, 10);
                $table->string('max_spend_origin', 32);
                $table->decimal('original_max_spend', 20, 10);
                $table->unsignedInteger('steps_used')->default(0);
                $table->decimal('approach_threshold', 5, 4);
                $table->timestamp('steps_approach_noticed_at', 6)->nullable();
                $table->timestamp('spend_approach_noticed_at', 6)->nullable();
                $table->timestamp('paused_at', 6)->nullable();
                $table->string('pause_reason', 16)->nullable();
                $table->uuid('paused_run_id')->nullable();
                $table->unsignedInteger('paused_iteration')->nullable();
                $table->string('resume_mode', 8)->nullable();
                $table->unsignedInteger('paused_steps_used')->nullable();
                $table->decimal('paused_spend', 20, 10)->nullable();
                $table->uuid('prompt_question_id')->nullable();
                $table->timestamp('ended_at', 6)->nullable();
                $table->timestamp('created_at', 6)->nullable();
                $table->timestamp('updated_at', 6)->nullable();

                $table->index('root_conversation_id');
                $table->index(['user_id', 'updated_at']);
                $table->index(['state', 'updated_at']);
                $table->index('managed_task_id');
                $table->index('prompt_question_id');
                $table->index('root_run_id');
                $table->index('paused_run_id');
            });
        }

        if (!Schema::hasTable('task_budget_events')) {
            Schema::create('task_budget_events', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('task_budget_id');
                $table->string('kind', 32);
                $table->json('payload');
                $table->uuid('actor_user_id')->nullable();
                $table->timestamp('created_at', 6)->useCurrent();

                $table->index(['task_budget_id', 'created_at']);
            });
        }

        if (Schema::hasTable('usage_records') && !Schema::hasColumn('usage_records', 'task_budget_id')) {
            Schema::table('usage_records', function (Blueprint $table) {
                $table->uuid('task_budget_id')->nullable()->index();
            });
        }

        if (Schema::hasTable('conversations') && !Schema::hasColumn('conversations', 'active_task_budget_id')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->uuid('active_task_budget_id')->nullable()->index();
            });
        }

        if (Schema::hasTable('agent_questions')) {
            Schema::table('agent_questions', function (Blueprint $table) {
                if (!Schema::hasColumn('agent_questions', 'origin')) {
                    $table->string('origin', 16)->nullable();
                }
                if (!Schema::hasColumn('agent_questions', 'task_budget_id')) {
                    $table->uuid('task_budget_id')->nullable()->index();
                }
            });
        }
    }

    public function down(): void
    {
        foreach ([['agent_questions', 'task_budget_id'], ['agent_questions', 'origin'], ['conversations', 'active_task_budget_id'], ['usage_records', 'task_budget_id']] as [$tableName, $column]) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, $column)) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName, $column) {
                    if ($column !== 'origin') {
                        $table->dropIndex([$column]);
                    }
                    $table->dropColumn($column);
                });
            }
        }

        Schema::dropIfExists('task_budget_events');
        Schema::dropIfExists('task_budgets');
    }
};
