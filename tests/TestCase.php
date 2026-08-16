<?php

namespace Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use ClarionApp\LlmClient\LlmClientServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            LlmClientServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        // Set APP_KEY for encrypted casts (e.g., Server token encryption).
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        // Disable EloquentMultiChainBridge in tests to avoid dependencies
        // on the multichain service, data_stream_registries table, etc.
        $app['config']->set('eloquent-multichain-bridge.disabled', true);

        // Configure auth for tests (api guard with token driver).
        $app['config']->set('auth.defaults.guard', 'api');
        $app['config']->set('auth.guards.api', [
            'driver'   => 'token',
            'provider' => 'users',
        ]);
        $app['config']->set('auth.providers.users', [
            'driver' => 'eloquent',
            'model'  => \ClarionApp\Backend\Models\User::class,
        ]);
    }

    /**
     * Define environment setup. Creates stub classes needed by the package.
     */
    protected function defineEnvironment($app): void
    {
        // Create a stub App\Http\Controllers\Controller class if it doesn't exist.
        // The package routes/controllers extend this base Laravel app class.
        if (!class_exists('App\Http\Controllers\Controller')) {
            eval('namespace App\Http\Controllers { class Controller { } }');
        }

        // Stub the multichain service.
        // The User model uses EloquentMultiChainBridge which depends on this.
        // In tests we don't need actual multichain — a no-op stub is sufficient.
        $app->singleton('multichain', function () {
            $stub = new class {
                public function __call($method, $arguments) { return null; }
                public function publish($stream, $key, $value) { return 'stub-txid'; }
                public function liststreams($stream) { throw new \Exception('not found'); }
                public function create($type, $name, $private) { return null; }
                public function subscribe($stream) { return null; }
            };
            return $stub;
        });
    }

    /**
     * Define hooks for deploying the database.
     */
    protected function defineDatabaseMigrations(): void
    {
        // Note: Tests using RefreshDatabase trait should have @define-db none
        // annotation to skip this method, as RefreshDatabase runs actual
        // migrations that create these tables already.

        // Create the users table (required by tests that use User::factory()).
        // This mirrors the backend migration without pulling in the full
        // ClarionBackendServiceProvider and all its dependencies.
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->string('email')->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password');
                $table->rememberToken();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // llm_memory_entries table (for memory system tests).
        if (!Schema::hasTable('llm_memory_entries')) {
            Schema::create('llm_memory_entries', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('scope');
                $table->uuid('agent_id');
                $table->uuid('user_id');
                $table->uuid('conversation_id')->nullable();
                $table->string('turn_id')->nullable();
                $table->string('key', 64)->nullable();
                $table->text('content');
                $table->json('embedding')->nullable();
                $table->timestamp('last_accessed_at')->useCurrent();
                $table->timestamps();

                $table->unique(['scope', 'agent_id', 'key']);
                $table->index(['scope', 'agent_id']);
                $table->index(['scope', 'user_id']);
                $table->index(['scope', 'conversation_id']);
                $table->index(['scope', 'last_accessed_at']);
            });
        }

        // declarative_memories table (for declarative memory tests).
        if (!Schema::hasTable('declarative_memories')) {
            Schema::create('declarative_memories', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->string('type');
                $table->text('content');
                $table->string('source');
                $table->integer('confidence_level')->nullable();
                $table->json('embedding')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index('user_id');
                $table->index(['user_id', 'type']);
                $table->index('deleted_at');
            });
        }

        // llm_servers table (required by Server model and Conversation relationships).
        if (!Schema::hasTable('llm_servers')) {
            Schema::create('llm_servers', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name')->nullable();
                $table->string('server_url')->nullable();
                $table->string('token')->nullable();
                $table->string('provider_type')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // conversations table (required by Conversation model used in feature tests).
        if (!Schema::hasTable('conversations')) {
            Schema::create('conversations', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('server_id')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->uuid('user_id')->nullable();
                $table->string('title')->nullable();
                $table->string('model')->nullable();
                $table->string('character')->nullable();
                $table->string('channel')->nullable();
                $table->uuid('agent_id')->nullable();
                $table->uuid('agent_version_id')->nullable();
                $table->string('provider_override')->nullable();
                $table->boolean('is_processing')->default(false);
                $table->timestamp('ended_at')->nullable();
                $table->string('routing_reason', 16)->nullable();
                $table->timestamp('routing_disclosed_at')->nullable();
                $table->index('user_id');
                $table->index('agent_id');
                $table->index('agent_version_id');
            });
        }

        // messages table (required by Conversation model relationships).
        if (!Schema::hasTable('messages')) {
            Schema::create('messages', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id');
                $table->string('role');
                $table->longText('content')->nullable();
                $table->string('user')->nullable();
                $table->unsignedInteger('responseTime')->nullable();
                $table->json('tool_calls')->nullable();
                $table->json('tool_data')->nullable();
                $table->uuid('agent_id')->nullable();
                $table->uuid('agent_version_id')->nullable();
                $table->uuid('run_id')->nullable();
                $table->uuid('parent_id')->nullable();
                $table->unsignedInteger('sequence_number')->default(0);
                $table->timestamps();
                $table->softDeletes();
                $table->index('conversation_id');
                $table->index(['conversation_id', 'sequence_number']);
                $table->index('run_id');
            });
        }

        // usage_records table (for metrics tests).
        if (!Schema::hasTable('usage_records')) {
            Schema::create('usage_records', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id');
                $table->uuid('user_id');
                $table->uuid('attempt_group_id');
                $table->string('agent_id', 255)->nullable();
                $table->uuid('run_id')->nullable();
                $table->integer('input_tokens')->nullable()->default(0);
                $table->integer('output_tokens')->nullable()->default(0);
                $table->integer('total_tokens')->nullable()->default(0);
                $table->integer('reused_input_tokens')->nullable();
                $table->boolean('reused_input_estimated')->default(false);
                $table->boolean('reused_input_adjusted')->default(false);
                $table->boolean('input_estimated')->default(false);
                $table->boolean('output_estimated')->default(false);
                $table->string('model', 128)->nullable();
                $table->string('provider_type', 32)->nullable();
                $table->json('co_member_tags')->nullable();
                $table->uuid('model_price_id')->nullable();
                $table->decimal('reused_input_cost', 20, 10)->nullable();
                $table->decimal('fresh_input_cost', 20, 10)->nullable();
                $table->decimal('output_cost', 20, 10)->nullable();
                $table->decimal('total_cost', 20, 10)->nullable();
                $table->boolean('cost_unpriced')->default(false);
                $table->boolean('cost_estimated')->default(false);
                $table->timestamp('created_at')->useCurrent();

                $table->index('conversation_id');
                $table->index('user_id');
                $table->index('attempt_group_id');
                $table->index(['user_id', 'created_at']);
                $table->index('run_id');
                $table->index('agent_id');
            });
        }

        // model_prices table (for cost rollup tests — 073-usage-cost-rollups).
        if (!Schema::hasTable('model_prices')) {
            Schema::create('model_prices', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('provider_type', 32);
                $table->string('model', 128);
                $table->decimal('reused_input_rate', 14, 8);
                $table->decimal('fresh_input_rate', 14, 8);
                $table->decimal('output_rate', 14, 8);
                $table->timestamp('effective_from');
                $table->timestamp('effective_until')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['provider_type', 'model', 'effective_from']);
            });
        }

        // cost_summaries table (for cost rollup tests — 073-usage-cost-rollups).
        if (!Schema::hasTable('cost_summaries')) {
            Schema::create('cost_summaries', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->enum('entity_type', ['conversation', 'user', 'agent']);
                $table->string('entity_id', 255);
                $table->uuid('user_id');
                $table->date('period_date');
                $table->integer('request_count')->default(0);
                $table->decimal('priced_cost_total', 20, 10)->default(0);
                $table->integer('zero_priced_request_count')->default(0);
                $table->integer('unpriced_request_count')->default(0);
                $table->bigInteger('unpriced_total_tokens')->default(0);
                $table->integer('estimated_request_count')->default(0);
                $table->timestamp('updated_at')->useCurrent();

                $table->unique(['entity_type', 'entity_id', 'user_id', 'period_date']);
                $table->index(['entity_type', 'period_date']);
            });
        }

        $this->defineBudgetSchema();
        $this->defineReservationSchema();
        $this->defineRateLimitSchema();
        $this->defineConversationWorkSchema();
        $this->defineDegradationSchema();
        $this->defineEvalSuiteSchema();
        $this->defineEvalRunSchema();
        $this->defineEvalJudgmentSchema();
        $this->defineEvalReferenceSchema();
        $this->defineEvalPassRateSchema();
        $this->defineAgentSchema();
        $this->defineConversationHandoffSchema();
        $this->defineAgentShareGrantSchema();
        $this->defineAgentHelperAssignmentSchema();
        $this->defineAgentDelegationSchema();
        $this->defineManagedTaskSchema();
        $this->defineManagedTaskPartSchema();
        $this->defineConsensusRequestSchema();
        $this->defineStageSequenceDefinitionSchema();
        $this->defineStageSchema();
        $this->defineSequenceRunSchema();
        $this->defineStageResultSchema();
        $this->defineAgentMessageSchema();
        $this->defineTaskWorkspaceEntrySchema();

        // tool_invocation_records table (for metrics tests).
        if (!Schema::hasTable('tool_invocation_records')) {
            Schema::create('tool_invocation_records', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id');
                $table->uuid('user_id');
                $table->uuid('attempt_group_id');
                $table->uuid('run_id')->nullable();
                $table->string('agent_id', 255)->nullable();
                $table->string('tool_name', 256);
                $table->enum('outcome', ['success', 'failure']);
                $table->string('failure_category')->nullable();
                $table->json('co_member_tags')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index('conversation_id');
                $table->index('user_id');
                $table->index('attempt_group_id');
                $table->index(['tool_name', 'outcome']);
                $table->index('created_at');
                $table->index('run_id');
                $table->index('agent_id');
            });
        }

        // tool_reliability_summaries table (for tool reliability rate summaries).
        if (!Schema::hasTable('tool_reliability_summaries')) {
            Schema::create('tool_reliability_summaries', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('tool_name', 256);
                $table->string('agent_id', 255);
                $table->uuid('user_id');
                $table->date('period_date');
                $table->integer('invocation_count')->default(0);
                $table->integer('success_count')->default(0);
                $table->integer('failure_count')->default(0);
                $table->integer('failure_timeout_count')->default(0);
                $table->integer('failure_connection_failure_count')->default(0);
                $table->integer('failure_authentication_failure_count')->default(0);
                $table->integer('failure_invalid_input_count')->default(0);
                $table->integer('failure_server_error_count')->default(0);
                $table->integer('failure_other_count')->default(0);
                $table->integer('failure_uncategorized_count')->default(0);
                $table->timestamp('updated_at')->useCurrent();

                $table->unique(
                    ['tool_name', 'agent_id', 'user_id', 'period_date'],
                    'tool_reliability_summaries_bucket_unique'
                );
                $table->index(['tool_name', 'period_date']);
                $table->index(['tool_name', 'agent_id', 'period_date']);
            });
        }

        // usage_summaries table (for metrics tests).
        if (!Schema::hasTable('usage_summaries')) {
            Schema::create('usage_summaries', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->enum('entity_type', ['conversation', 'user']);
                $table->uuid('entity_id');
                $table->bigInteger('input_tokens')->default(0);
                $table->bigInteger('output_tokens')->default(0);
                $table->bigInteger('total_tokens')->default(0);
                $table->bigInteger('estimated_input_tokens')->default(0);
                $table->bigInteger('estimated_output_tokens')->default(0);
                $table->bigInteger('estimated_total_tokens')->default(0);
                $table->integer('request_count')->default(0);
                $table->timestamp('updated_at')->useCurrent();

                $table->unique(['entity_type', 'entity_id']);
                $table->index(['entity_type', 'updated_at']);
            });
        }

        // context_management_records table (for context management metrics tests).
        if (!Schema::hasTable('context_management_records')) {
            Schema::create('context_management_records', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id');
                $table->uuid('user_id');
                $table->uuid('attempt_group_id')->nullable();
                $table->enum('mechanism', ['trim', 'smart_trim', 'condense', 'none']);
                $table->integer('history_budget')->nullable();
                $table->integer('context_capacity')->nullable();
                $table->integer('tokens_before')->default(0);
                $table->integer('tokens_after')->default(0);
                $table->integer('request_tokens_before')->default(0);
                $table->integer('tokens_saved')->default(0);
                $table->string('model', 128)->nullable();
                $table->string('provider_type', 32)->nullable();
                $table->string('error', 256)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index('conversation_id');
                $table->index(['user_id', 'created_at']);
                $table->index('attempt_group_id');
                $table->index(['mechanism', 'created_at']);
            });
        }

        // context_management_summaries table (for context management metrics tests).
        if (!Schema::hasTable('context_management_summaries')) {
            Schema::create('context_management_summaries', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->enum('entity_type', ['conversation', 'user']);
                $table->uuid('entity_id');
                $table->bigInteger('trim_activations')->default(0);
                $table->bigInteger('smart_trim_activations')->default(0);
                $table->bigInteger('condense_activations')->default(0);
                $table->bigInteger('total_tokens_saved')->default(0);
                $table->bigInteger('total_requests')->default(0);
                $table->timestamp('updated_at')->useCurrent();

                $table->unique(['entity_type', 'entity_id']);
                $table->index(['entity_type', 'updated_at']);
            });
        }

        // operation_cache table (for operation search cache).
        if (!Schema::hasTable('operation_cache')) {
            Schema::create('operation_cache', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id');
                $table->string('operation_id');
                $table->string('method');
                $table->string('path');
                $table->text('summary')->nullable();
                $table->json('param_schema')->nullable();
                $table->timestamps();

                $table->index('conversation_id');
            });
        }

        // agent_runs table (for agent run trace).
        if (!Schema::hasTable('agent_runs')) {
            Schema::create('agent_runs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->enum('kind', ['interactive', 'system_initiated']);
                $table->uuid('user_id');
                $table->uuid('conversation_id')->nullable();
                $table->string('source', 64)->nullable();
                $table->enum('end_state', ['in_progress', 'completed', 'failed', 'stopped_early', 'abandoned'])->default('in_progress');
                $table->string('end_reason', 256)->nullable();
                $table->timestamp('started_at', 6);
                $table->timestamp('ended_at', 6)->nullable();
                $table->unsignedBigInteger('duration_ms')->nullable();
                $table->unsignedInteger('step_count')->default(0);
                $table->timestamp('created_at')->useCurrent();
                $table->boolean('is_streamed')->default(false);
                $table->unsignedBigInteger('first_output_ms')->nullable();
                $table->string('model', 128)->nullable();
                $table->string('agent_id', 255)->nullable();
                $table->unsignedBigInteger('model_wait_ms')->nullable();
                $table->unsignedBigInteger('tool_exec_ms')->nullable();
                $table->unsignedBigInteger('confirm_wait_ms')->nullable();
                $table->unsignedBigInteger('product_ms')->nullable();

                $table->index('conversation_id');
                $table->index(['user_id', 'started_at']);
                $table->index(['end_state', 'started_at']);
                $table->index(['model', 'started_at']);
                $table->index(['agent_id', 'started_at']);
            });
        }

        // agent_run_steps table (for agent run trace).
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

        // agent_run_messages table (for agent run trace).
        if (!Schema::hasTable('agent_run_messages')) {
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

        // language_models table (for model role resolver broken-check tests).
        if (!Schema::hasTable('language_models')) {
            Schema::create('language_models', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('server_id');
                $table->string('name');
                $table->timestamps();
                $table->softDeletes();

                $table->index('server_id');
            });
        }

        // llm_role_assignments table (for model role assignment feature).
        if (!Schema::hasTable('llm_role_assignments')) {
            Schema::create('llm_role_assignments', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('role', 20);
                $table->uuid('user_id');
                $table->uuid('server_id');
                $table->string('model', 255);
                $table->timestamps();
                $table->timestamp('deleted_at')->nullable();

                $table->unique(['role', 'user_id']);
                $table->index('server_id');
            });
        }

        // llm_server_statuses table (for server status tracking).
        if (!Schema::hasTable('llm_server_statuses')) {
            Schema::create('llm_server_statuses', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('server_id')->unique();
                $table->string('connection_status')->default('never_checked');
                $table->string('last_outcome')->nullable();
                $table->text('last_error')->nullable();
                $table->integer('model_count')->default(0);
                $table->timestamp('refresh_started_at')->nullable();
                $table->timestamp('refresh_finished_at')->nullable();
                $table->uuid('triggered_by')->nullable();
                $table->timestamps();

                $table->index('server_id');
            });
        }

        // agent_run_actions table (for agent step actions).
        if (!Schema::hasTable('agent_run_actions')) {
            Schema::create('agent_run_actions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('run_id');
                $table->uuid('step_id');
                $table->enum('action_type', ['llm_request', 'tool_invocation', 'context_reshape', 'delegation']);
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

        // agent_run_export_queue table (for trace forwarding buffer).
        if (!Schema::hasTable('agent_run_export_queue')) {
            Schema::create('agent_run_export_queue', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('run_id');
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->timestamp('next_attempt_at')->nullable();
                $table->string('last_error', 512)->nullable();
                $table->timestamp('created_at');

                $table->index('run_id');
                $table->index('next_attempt_at');
                $table->index('created_at');
            });
        }

    }

    /**
     * The two tables spending enforcement reads and writes.
     *
     * Extracted so the handful of test classes that hand-declare a schema
     * of their own can call it too. Every entry path now crosses the budget
     * gate, and the gate's first act is to ask whether any ceiling exists —
     * so a class whose schema omits spending_ceilings no longer describes a
     * deployment this package can run in, whatever else it is testing.
     */
    protected function defineBudgetSchema(): void
    {
        // spending_ceilings table (for budget ceiling tests).
        if (!Schema::hasTable('spending_ceilings')) {
            Schema::create('spending_ceilings', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('scope_type', 16);
                $table->uuid('scope_id');
                $table->decimal('amount', 20, 10)->nullable();
                $table->string('period_type', 8);
                $table->string('enforcement_mode', 8);
                $table->decimal('approach_threshold', 5, 4)->default(0.8);
                $table->boolean('waived')->default(false);
                $table->timestamps();
                $table->softDeletes();

                // Plain index, not unique — see the migration's own comment.
                $table->index(['scope_type', 'scope_id']);
            });
        }

        // budget_threshold_notifications table (for budget warning tests).
        if (!Schema::hasTable('budget_threshold_notifications')) {
            Schema::create('budget_threshold_notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('scope_type', 16);
                $table->uuid('scope_id');
                $table->string('period_type', 8);
                $table->date('period_start');
                $table->string('kind', 16);
                $table->uuid('ceiling_id')->nullable();
                $table->decimal('consumption_at_fire', 20, 10);
                $table->timestamp('created_at')->useCurrent();

                // The once-per-period latch, not merely a constraint. Named
                // to match the migration, whose generated name would exceed
                // the 64-character identifier limit MySQL/MariaDB enforce.
                $table->unique(
                    ['scope_type', 'scope_id', 'period_type', 'period_start', 'kind'],
                    'budget_threshold_notifications_latch_unique'
                );
            });
        }
    }

    /**
     * The budget_reservation_ledger/cost_reservations tables — the
     * atomic reservation-anchor and per-admission identity rows this
     * feature adds (data-model.md §1/§2). Once a stop-mode ceiling is
     * configured in a test's fixtures, BudgetGate::admit()'s deepened,
     * reservation-aware path can reach these tables, so a schema without
     * them no longer describes a deployment this package can run in.
     *
     * Extracted, guarded by Schema::hasTable(), and called from
     * defineDatabaseMigrations() directly, matching defineBudgetSchema()'s
     * own shape and call-site pattern so the same handful of test classes
     * that hand-declare a schema of their own can call it too.
     */
    protected function defineReservationSchema(): void
    {
        // budget_reservation_ledger table (the atomic per-scope anchor row).
        if (!Schema::hasTable('budget_reservation_ledger')) {
            Schema::create('budget_reservation_ledger', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('scope_type', 16);
                $table->uuid('scope_id');
                $table->decimal('reserved_total', 20, 10)->default(0);
                $table->timestamp('updated_at')->useCurrent();

                // A genuine unique constraint, not a plain index — see the
                // migration's own comment.
                $table->unique(['scope_type', 'scope_id']);
            });
        }

        // cost_reservations table (one row per admitted unit of work's
        // reservation).
        if (!Schema::hasTable('cost_reservations')) {
            Schema::create('cost_reservations', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->json('scope_keys');
                $table->uuid('user_id')->nullable();
                $table->uuid('conversation_id')->nullable();
                $table->uuid('run_id')->nullable();
                $table->string('work_kind', 16);
                $table->decimal('estimated_amount', 20, 10);
                $table->decimal('actual_amount', 20, 10)->nullable();
                $table->string('status', 16);
                $table->timestamp('held_at');
                $table->timestamp('resolved_at')->nullable();

                $table->index(['status', 'held_at']);
                $table->index(['run_id']);
            });
        }
    }

    /**
     * The rate_limits table — operator-authored, per-user request-rate
     * configuration. Governs admission, not reporting, so every entry-path
     * test that exercises AgentLoopService/MessageController now
     * potentially crosses this gate: a schema without rate_limits no
     * longer describes a deployment this package can run in.
     *
     * Extracted, guarded by Schema::hasTable(), and called from
     * defineDatabaseMigrations() directly, matching defineBudgetSchema()'s
     * own shape and call-site pattern so the same handful of test classes
     * that hand-declare a schema of their own can call it too.
     */
    protected function defineRateLimitSchema(): void
    {
        if (!Schema::hasTable('rate_limits')) {
            Schema::create('rate_limits', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('scope_type', 16);
                $table->uuid('scope_id');
                $table->unsignedInteger('max_requests')->nullable();
                $table->unsignedInteger('window_seconds')->nullable();
                $table->boolean('waived')->default(false);
                $table->timestamps();
                $table->softDeletes();

                // Plain index, not unique — see the migration's own comment.
                $table->index(['scope_type', 'scope_id']);
            });
        }
    }

    /**
     * The conversation_work_ceilings table — operator-authored, per-
     * conversation work-ceiling configuration. Governs mid-loop admission,
     * so every entry-path test that exercises AgentLoopService::run()/
     * resumeSync() or AgentLoopStreamHandler now potentially crosses this
     * gate: a schema without conversation_work_ceilings no longer
     * describes a deployment this package can run in.
     *
     * Extracted, guarded by Schema::hasTable(), and called from
     * defineDatabaseMigrations() directly, matching defineRateLimitSchema()'s
     * own shape and call-site pattern so the same handful of test classes
     * that hand-declare a schema of their own can call it too.
     */
    protected function defineConversationWorkSchema(): void
    {
        if (!Schema::hasTable('conversation_work_ceilings')) {
            Schema::create('conversation_work_ceilings', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('scope_type', 20);
                $table->uuid('scope_id');
                $table->unsignedInteger('max_work_units')->nullable();
                $table->unsignedInteger('window_seconds')->nullable();
                $table->boolean('waived')->default(false);
                $table->timestamps();
                $table->softDeletes();

                // Plain index, not unique — see the migration's own comment.
                $table->index(['scope_type', 'scope_id']);
            });
        }
    }

    /**
     * The three degradation tables (reduction_steps, degradation_events,
     * degradation_summaries) — Constitution §V, no migrations run under
     * test. Column shapes mirror the migrations exactly. Every entry-path
     * test that exercises AgentLoopService::admitInteractiveWork() now
     * potentially crosses DegradationGate's deepened, ladder-aware path
     * once a ladder is configured in a fixture, so a schema without these
     * three tables no longer describes a deployment this package can run
     * in.
     *
     * Extracted, guarded by Schema::hasTable(), and called from
     * defineDatabaseMigrations() directly, matching
     * defineConversationWorkSchema()'s own shape and call-site pattern so
     * the same handful of test classes that hand-declare a schema of their
     * own can call it too.
     */
    protected function defineDegradationSchema(): void
    {
        if (!Schema::hasTable('reduction_steps')) {
            Schema::create('reduction_steps', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('axis', 20);
                $table->decimal('threshold_ratio', 5, 4);
                $table->string('substitute_model')->nullable();
                $table->uuid('substitute_server_id')->nullable();
                $table->json('withheld_tools')->nullable();
                $table->decimal('history_budget_ratio', 5, 4)->nullable();
                $table->boolean('enabled')->default(true);
                $table->timestamps();
                $table->softDeletes();

                // Plain index, not unique — see the migration's own comment.
                $table->index(['axis', 'threshold_ratio']);
            });
        }

        if (!Schema::hasTable('degradation_events')) {
            Schema::create('degradation_events', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('run_id')->nullable();
                $table->uuid('conversation_id');
                $table->uuid('user_id')->nullable();
                $table->uuid('reduction_step_id');
                $table->string('axis', 20);
                $table->decimal('ratio', 5, 4);
                $table->timestamp('resets_at')->nullable();
                $table->timestamp('applied_at');

                $table->index(['conversation_id']);
                $table->index(['user_id']);
                $table->index(['run_id']);
            });
        }

        if (!Schema::hasTable('degradation_summaries')) {
            Schema::create('degradation_summaries', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('entity_type');
                $table->uuid('entity_id');
                $table->unsignedInteger('degraded_response_count')->default(0);
                $table->timestamp('last_degraded_at')->nullable();
                $table->timestamp('updated_at')->useCurrent();

                // The genuine unique constraint — insertOrIgnore()'s target.
                $table->unique(['entity_type', 'entity_id']);
            });
        }
    }

    /**
     * The three tables agent behavior test suite definitions read and
     * write — eval_suites, eval_cases (the identity/version split's stable
     * half), and eval_case_versions (the append-only content half).
     * Mirrors data-model.md §§1-3 exactly. Guarded by Schema::hasTable()
     * like every existing block here, and called from
     * defineDatabaseMigrations() directly, matching defineBudgetSchema()'s
     * own call-site pattern.
     */
    protected function defineEvalSuiteSchema(): void
    {
        if (!Schema::hasTable('eval_suites')) {
            Schema::create('eval_suites', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name', 255);
                $table->string('agent_identifier', 255);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['agent_identifier', 'name']);
            });
        }

        if (!Schema::hasTable('eval_cases')) {
            Schema::create('eval_cases', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('suite_id');
                $table->uuid('current_version_id')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index('suite_id');
                $table->index('current_version_id');
            });
        }

        if (!Schema::hasTable('eval_case_versions')) {
            Schema::create('eval_case_versions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('case_id');
                $table->unsignedInteger('version_number');
                $table->text('given');
                $table->text('expected_behavior');
                $table->json('expectations');
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['case_id', 'version_number']);
            });
        }
    }

    /**
     * The two tables Agent Version History (087) reads and writes —
     * agents (the identity/version-split's stable half) and agent_versions
     * (the append-only content half). Mirrors data-model.md §§1-2 exactly.
     * Guarded by Schema::hasTable() like every existing block here, and
     * called from defineDatabaseMigrations() directly, matching
     * defineEvalSuiteSchema()'s own call-site pattern.
     */
    protected function defineAgentSchema(): void
    {
        if (!Schema::hasTable('agents')) {
            Schema::create('agents', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->string('name', 255);
                $table->uuid('current_version_id')->nullable();
                $table->string('linked_repository_path', 1024)->nullable();
                $table->string('linked_file_path', 1024)->nullable();
                $table->string('linked_synced_file_hash', 64)->nullable();
                $table->uuid('cloned_from_agent_id')->nullable();
                $table->boolean('is_active')->default(true);
                $table->boolean('is_default_handler')->default(false);
                $table->timestamps();
                $table->softDeletes();

                $table->index('user_id');
                $table->index('current_version_id');
                $table->index('cloned_from_agent_id');
            });
        }

        if (!Schema::hasTable('agent_versions')) {
            Schema::create('agent_versions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('agent_id');
                $table->unsignedInteger('version_number');
                $table->text('raw_definition');
                $table->string('content_hash', 64);
                $table->string('source', 16);
                $table->uuid('changed_by_user_id')->nullable();
                $table->uuid('restored_from_version_id')->nullable();
                $table->string('git_commit_hash', 40)->nullable();
                $table->string('git_author_name', 255)->nullable();
                $table->timestamp('git_committed_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['agent_id', 'version_number']);
                $table->index('agent_id');
            });
        }
    }

    /**
     * conversation_handoffs — one row per handoff event, recording that
     * responsibility for a conversation passed from one agent to another
     * (093-agent-handoff, data-model.md §1). Mirrors the migration's own
     * column set exactly. Guarded by Schema::hasTable() like every
     * existing block here, and called from defineDatabaseMigrations()
     * directly, immediately after defineAgentSchema(), matching this
     * package's own established Foundational-phase precedent.
     */
    protected function defineConversationHandoffSchema(): void
    {
        if (!Schema::hasTable('conversation_handoffs')) {
            Schema::create('conversation_handoffs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id');
                $table->unsignedInteger('position');
                $table->uuid('from_agent_id')->nullable();
                $table->uuid('to_agent_id');
                $table->uuid('to_agent_version_id');
                $table->timestamp('created_at');
                $table->timestamp('disclosed_at')->nullable();
                $table->string('reason', 32)->nullable();

                $table->index('conversation_id');
            });
        }
    }

    /**
     * agent_share_grants — one lifetime row per (agent_id,
     * recipient_user_id) pair, recording that an owner granted a recipient
     * use or use-and-edit access to an agent (096-agent-sharing,
     * data-model.md §1). Mirrors the migration's own column set exactly.
     * Guarded by Schema::hasTable() like every existing block here, and
     * called from defineDatabaseMigrations() directly, immediately after
     * defineConversationHandoffSchema(), matching this package's own
     * established Foundational-phase precedent.
     */
    protected function defineAgentShareGrantSchema(): void
    {
        if (!Schema::hasTable('agent_share_grants')) {
            Schema::create('agent_share_grants', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('agent_id');
                $table->uuid('owner_user_id');
                $table->uuid('recipient_user_id');
                $table->string('permission', 20);
                $table->timestamps();
                $table->timestamp('deleted_at')->nullable();

                $table->unique(['agent_id', 'recipient_user_id']);
                $table->index('agent_id');
                $table->index('owner_user_id');
                $table->index('recipient_user_id');
            });
        }
    }

    /**
     * agent_helper_assignments — one lifetime row per ordered
     * (parent_agent_id, helper_agent_id) pair, recording that a parent
     * agent has a helper agent assigned to it (097-subagent-model,
     * data-model.md §1). Mirrors the migration's own column set exactly.
     * Guarded by Schema::hasTable() like every existing block here, and
     * called from defineDatabaseMigrations() directly, immediately after
     * defineAgentShareGrantSchema(), matching this package's own
     * established Foundational-phase precedent.
     */
    protected function defineAgentHelperAssignmentSchema(): void
    {
        if (!Schema::hasTable('agent_helper_assignments')) {
            Schema::create('agent_helper_assignments', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('parent_agent_id');
                $table->uuid('helper_agent_id');
                $table->uuid('owner_user_id');
                $table->timestamps();
                $table->timestamp('deleted_at')->nullable();

                $table->unique(['parent_agent_id', 'helper_agent_id']);
                $table->index('parent_agent_id');
                $table->index('helper_agent_id');
                $table->index('owner_user_id');
            });
        }
    }

    /**
     * agent_delegations — the record of a single parent→helper task
     * handoff (098-delegation-protocol, data-model.md §1). Mirrors
     * defineAgentHelperAssignmentSchema()'s exact shape: Schema::hasTable()
     * guarded, hand-declared here since no test in this package ever runs
     * real migrations (Constitution §V). Matches the column set in
     * src/Migrations/2026_08_14_000001_create_agent_delegations_table.php
     * exactly.
     */
    protected function defineAgentDelegationSchema(): void
    {
        if (!Schema::hasTable('agent_delegations')) {
            Schema::create('agent_delegations', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('parent_conversation_id');
                $table->uuid('parent_agent_id')->nullable();
                $table->uuid('helper_agent_id');
                $table->uuid('helper_conversation_id')->unique();
                $table->uuid('helper_agent_version_id')->nullable();
                $table->uuid('owner_user_id');
                $table->text('task');
                $table->longText('context')->nullable();
                $table->unsignedInteger('depth');
                $table->enum('status', ['queued', 'in_progress', 'completed', 'exhausted', 'failed']);
                $table->uuid('batch_id')->nullable()->index();
                $table->uuid('parent_run_id')->nullable();
                $table->uuid('parent_action_id')->nullable();
                $table->uuid('helper_run_id')->nullable();
                $table->text('outcome_summary')->nullable();
                $table->timestamp('started_at', 6);
                $table->timestamp('completed_at', 6)->nullable();

                $table->index('parent_conversation_id');
                $table->index('helper_agent_id');
                $table->index('owner_user_id');
                $table->index('parent_run_id');
                $table->index('helper_run_id');
            });
        }

        if (!Schema::hasColumn('agent_delegations', 'result_status')) {
            Schema::table('agent_delegations', function (Blueprint $table) {
                $table->enum('result_status', ['success', 'partial', 'failure'])->nullable();
                $table->string('result_reason', 32)->nullable();
                $table->text('result_summary')->nullable();
                $table->longText('result_output')->nullable();
                $table->text('result_undone')->nullable();
                $table->boolean('result_truncated')->default(false);
            });
        }

        if (!Schema::hasColumn('agent_delegations', 'managed_task_id')) {
            Schema::table('agent_delegations', function (Blueprint $table) {
                $table->uuid('managed_task_id')->nullable()->index();
                $table->uuid('part_id')->nullable()->index();
            });
        }
    }

    /**
     * managed_tasks — one row per manager-driven task (103-manager-agent,
     * data-model.md §1). Schema::hasTable() guarded, hand-declared here
     * since no test in this package ever runs real migrations
     * (Constitution §V). Matches the column set in
     * src/Migrations/2026_08_15_000000_create_managed_tasks_table.php
     * exactly.
     */
    protected function defineManagedTaskSchema(): void
    {
        if (!Schema::hasTable('managed_tasks')) {
            Schema::create('managed_tasks', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id')->unique();
                $table->uuid('owner_user_id');
                $table->uuid('manager_agent_id')->nullable();
                $table->longText('original_request');
                $table->enum('status', ['in_progress', 'completed', 'completed_with_shortfalls', 'failed'])->default('in_progress');
                $table->unsignedInteger('round_ceiling');
                $table->unsignedInteger('rounds_used')->default(0);
                $table->unsignedInteger('max_seconds');
                $table->timestamp('last_progress_at', 6);
                $table->longText('final_response')->nullable();
                $table->text('shortfall_note')->nullable();
                $table->text('conflict_note')->nullable();
                $table->timestamp('started_at', 6);
                $table->timestamp('completed_at', 6)->nullable();

                $table->index('owner_user_id');
                $table->index('status');
                $table->index('last_progress_at');
            });
        }
    }

    /**
     * managed_task_parts — a distinct, self-contained, bounded slice of a
     * managed task (103-manager-agent, data-model.md §2). Schema::hasTable()
     * guarded, hand-declared here since no test in this package ever runs
     * real migrations (Constitution §V). Matches the column set in
     * src/Migrations/2026_08_15_000001_create_managed_task_parts_table.php
     * exactly.
     */
    protected function defineManagedTaskPartSchema(): void
    {
        if (!Schema::hasTable('managed_task_parts')) {
            Schema::create('managed_task_parts', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('managed_task_id');
                $table->unsignedInteger('sequence');
                $table->text('description');
                $table->enum('state', ['not_yet_assigned', 'out_for_assignment', 'out_for_correction', 'accepted', 'reported_as_shortfall'])->default('not_yet_assigned');
                $table->uuid('current_delegation_id')->nullable();
                $table->uuid('accepted_delegation_id')->nullable();
                $table->text('accepted_summary')->nullable();
                $table->text('shortfall_reason')->nullable();
                $table->unsignedInteger('assignment_count')->default(0);
                $table->timestamp('created_at', 6);
                $table->timestamp('updated_at', 6);

                $table->index('managed_task_id');
                $table->index(['managed_task_id', 'state']);
                $table->index('current_delegation_id');
            });
        }
    }

    /**
     * stage_sequence_definitions — the named, reusable "run this again"
     * definition (105-stage-pipeline, data-model.md §1). Schema::hasTable()
     * guarded, hand-declared here since no test in this package ever runs
     * real migrations (Constitution §V). Matches the column set in
     * src/Migrations/2026_08_15_000003_create_stage_sequence_definitions_table.php
     * exactly.
     */
    protected function defineStageSequenceDefinitionSchema(): void
    {
        if (!Schema::hasTable('stage_sequence_definitions')) {
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
    }

    /**
     * stages — one named unit of work within a stage_sequence_definitions
     * row (105-stage-pipeline, data-model.md §2). Schema::hasTable()
     * guarded, hand-declared here since no test in this package ever runs
     * real migrations (Constitution §V). Matches the column set in
     * src/Migrations/2026_08_15_000004_create_stages_table.php exactly.
     */
    protected function defineStageSchema(): void
    {
        if (!Schema::hasTable('stages')) {
            Schema::create('stages', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('sequence_definition_id');
                $table->unsignedInteger('position');
                $table->string('name', 255);
                $table->uuid('helper_agent_id');
                $table->json('input_schema')->nullable();
                $table->json('output_schema')->nullable();
                $table->boolean('is_idempotent')->default(false);
                $table->timestamps(6);

                $table->index('sequence_definition_id');
                $table->unique(['sequence_definition_id', 'position']);
                $table->index('helper_agent_id');
            });
        }
    }

    /**
     * sequence_runs — one execution of a stage_sequence_definitions row
     * (105-stage-pipeline, data-model.md §3). Schema::hasTable() guarded,
     * hand-declared here since no test in this package ever runs real
     * migrations (Constitution §V). Matches the column set in
     * src/Migrations/2026_08_15_000005_create_sequence_runs_table.php
     * exactly.
     */
    protected function defineSequenceRunSchema(): void
    {
        if (!Schema::hasTable('sequence_runs')) {
            Schema::create('sequence_runs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('sequence_definition_id');
                $table->uuid('owner_user_id');
                $table->uuid('conversation_id');
                $table->enum('status', ['in_progress', 'resumed', 'completed', 'failed']);
                $table->longText('starting_input')->nullable();
                $table->unsignedInteger('current_stage_position')->nullable();
                $table->timestamp('last_progress_at', 6);
                $table->text('failure_reason')->nullable();
                $table->timestamp('resumed_at', 6)->nullable();
                $table->unsignedInteger('resume_count')->default(0);
                $table->timestamp('started_at', 6);
                $table->timestamp('completed_at', 6)->nullable();

                $table->index('sequence_definition_id');
                $table->index('owner_user_id');
                $table->index('status');
                $table->index('last_progress_at');
                $table->index('conversation_id');
            });
        }
    }

    /**
     * stage_results — the recorded outcome of one stage within one run
     * (105-stage-pipeline, data-model.md §4). Schema::hasTable() guarded,
     * hand-declared here since no test in this package ever runs real
     * migrations (Constitution §V). Matches the column set in
     * src/Migrations/2026_08_15_000006_create_stage_results_table.php
     * exactly.
     */
    protected function defineStageResultSchema(): void
    {
        if (!Schema::hasTable('stage_results')) {
            Schema::create('stage_results', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('sequence_run_id');
                $table->uuid('stage_id');
                $table->enum('status', ['pending', 'running', 'completed', 'failed', 'handoff_rejected'])->default('pending');
                $table->uuid('delegation_id')->nullable();
                $table->longText('input')->nullable();
                $table->longText('output')->nullable();
                $table->text('failure_reason')->nullable();
                $table->timestamp('started_at', 6)->nullable();
                $table->timestamp('completed_at', 6)->nullable();

                $table->index('sequence_run_id');
                $table->index('stage_id');
                $table->unique(['sequence_run_id', 'stage_id']);
                $table->index(['sequence_run_id', 'status']);
            });
        }
    }

    /**
     * agent_messages — the persisted record of every attempted inter-agent
     * message send, delivered or not (107-agent-message-protocol,
     * data-model.md §1). Schema::hasTable() guarded, hand-declared here
     * since no test in this package ever runs real migrations
     * (Constitution §V). Matches the column set in
     * src/Migrations/2026_08_15_000007_create_agent_messages_table.php
     * exactly.
     */
    protected function defineAgentMessageSchema(): void
    {
        if (!Schema::hasTable('agent_messages')) {
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
    }

    /**
     * task_workspace_entries — a single, immutable record within a
     * managed task's shared working area (108-shared-task-workspace,
     * data-model.md §1). Schema::hasTable() guarded, hand-declared here
     * since no test in this package ever runs real migrations
     * (Constitution §V). Matches the column set in
     * src/Migrations/2026_08_15_000008_create_task_workspace_entries_table.php
     * exactly.
     */
    protected function defineTaskWorkspaceEntrySchema(): void
    {
        if (!Schema::hasTable('task_workspace_entries')) {
            Schema::create('task_workspace_entries', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('managed_task_id');
                $table->uuid('owner_user_id');
                $table->uuid('author_agent_id');
                $table->text('content');
                $table->timestamp('created_at', 6);

                $table->index('managed_task_id');
                $table->index('owner_user_id');
                $table->index('author_agent_id');
                $table->index('created_at');
                $table->index(['managed_task_id', 'created_at']);
            });
        }
    }

    /**
     * consensus_requests — one row per user question submitted with
     * multi-opinion mode enabled (104-multi-agent-consensus,
     * data-model.md §1). Schema::hasTable() guarded, hand-declared here
     * since no test in this package ever runs real migrations
     * (Constitution §V). Matches the column set in
     * src/Migrations/2026_08_15_000000_create_consensus_requests_table.php
     * exactly.
     */
    protected function defineConsensusRequestSchema(): void
    {
        if (!Schema::hasTable('consensus_requests')) {
            Schema::create('consensus_requests', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id');
                $table->uuid('owner_user_id');
                $table->uuid('coordinator_agent_id')->nullable();
                $table->longText('question');
                $table->uuid('answer_message_id')->nullable();
                $table->uuid('batch_id')->nullable();
                $table->unsignedInteger('dispatched_count');
                $table->unsignedInteger('quorum_required')->nullable();
                $table->unsignedInteger('successful_count')->nullable();
                $table->enum('status', ['in_progress', 'completed', 'insufficient_quorum', 'single_contributor_fallback', 'failed']);
                $table->enum('agreement_classification', ['agreed', 'materially_disagreed', 'no_consensus'])->nullable();
                $table->longText('reconciled_answer')->nullable();
                $table->json('disagreement_detail')->nullable();
                $table->text('independence_note')->nullable();
                $table->decimal('estimated_additional_cost', 20, 10)->nullable();
                $table->decimal('actual_additional_cost', 20, 10)->nullable();
                $table->text('failure_reason')->nullable();
                $table->timestamp('started_at', 6);
                $table->timestamp('completed_at', 6)->nullable();

                $table->index('conversation_id');
                $table->index('owner_user_id');
                $table->index('coordinator_agent_id');
                $table->index('answer_message_id');
                $table->index('batch_id');
                $table->index('status');
            });
        }
    }

    /**
     * The three tables the batch evaluation runner reads and writes —
     * eval_runs, eval_run_cases (the per-run case snapshot), and
     * eval_case_results (the durable, operator-facing outcome of one
     * case within one run). Mirrors data-model.md §§1-3 exactly. Guarded
     * by Schema::hasTable() like every existing block here, and called
     * from defineDatabaseMigrations() directly, matching
     * defineEvalSuiteSchema()'s own call-site pattern.
     */
    protected function defineEvalRunSchema(): void
    {
        if (!Schema::hasTable('eval_runs')) {
            Schema::create('eval_runs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('suite_id');
                $table->string('agent_label', 255);
                $table->uuid('server_id')->nullable();
                $table->string('model', 255)->nullable();
                $table->string('status', 30);
                $table->unsignedInteger('case_count');
                $table->text('failure_reason')->nullable();
                $table->timestamp('started_at');
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index('suite_id');
                $table->index(['status', 'updated_at']);
            });
        }

        if (!Schema::hasTable('eval_run_cases')) {
            Schema::create('eval_run_cases', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('run_id');
                $table->uuid('eval_case_id');
                $table->uuid('eval_case_version_id');
                $table->unsignedInteger('position');
                $table->string('status', 20);
                $table->unsignedInteger('dispatch_attempts')->default(0);
                $table->timestamps();

                $table->unique(['run_id', 'eval_case_id']);
                $table->index(['run_id', 'status']);
            });
        }

        if (!Schema::hasTable('eval_case_results')) {
            Schema::create('eval_case_results', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('run_id');
                $table->uuid('eval_run_case_id');
                $table->uuid('eval_case_id');
                $table->uuid('eval_case_version_id');
                $table->uuid('conversation_id');
                $table->string('outcome', 20);
                $table->text('produced_response')->nullable();
                $table->json('attempted_actions')->default('[]');
                $table->json('expectation_results');
                $table->text('error_message')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['run_id', 'eval_case_id']);
                $table->index('run_id');
                $table->index('conversation_id');
                $table->index(['eval_case_id', 'created_at']);
            });
        }
    }

    /**
     * The three tables the rubric-judging feature reads and writes —
     * eval_judgments, eval_judgment_overrides, and
     * eval_judgment_consistency_samples — plus the one additive nullable
     * column (outcome_override) on the existing eval_case_results table.
     * Mirrors the production migrations exactly. Guarded by
     * Schema::hasTable()/Schema::hasColumn() like every existing block
     * here, and called from defineDatabaseMigrations() directly,
     * immediately after defineEvalRunSchema(), matching its own call-site
     * pattern.
     */
    protected function defineEvalJudgmentSchema(): void
    {
        if (!Schema::hasTable('eval_judgments')) {
            Schema::create('eval_judgments', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('eval_case_result_id')->nullable();
                $table->uuid('eval_case_version_id');
                $table->unsignedInteger('expectation_index');
                $table->text('criteria');
                $table->text('response_text')->nullable();
                $table->string('status', 20);
                $table->unsignedTinyInteger('score')->nullable();
                $table->text('justification')->nullable();
                $table->text('unjudged_reason')->nullable();
                $table->string('model', 255)->nullable();
                $table->uuid('server_id')->nullable();
                $table->uuid('conversation_id')->nullable();
                $table->uuid('consistency_sample_id')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index('eval_case_result_id');
                $table->index('consistency_sample_id');
                $table->index(['eval_case_version_id', 'expectation_index']);
            });
        }

        if (!Schema::hasTable('eval_judgment_overrides')) {
            Schema::create('eval_judgment_overrides', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('judgment_id');
                $table->uuid('user_id');
                $table->unsignedTinyInteger('score');
                $table->text('justification');
                $table->timestamp('created_at')->useCurrent();

                $table->index(['judgment_id', 'created_at']);
            });
        }

        if (!Schema::hasTable('eval_judgment_consistency_samples')) {
            Schema::create('eval_judgment_consistency_samples', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('eval_case_id');
                $table->uuid('eval_case_version_id');
                $table->unsignedInteger('expectation_index');
                $table->uuid('source_eval_case_result_id')->nullable();
                $table->text('response_text');
                $table->unsignedInteger('sample_size');
                $table->unsignedInteger('judged_count');
                $table->unsignedInteger('unjudged_count');
                $table->json('scores');
                $table->unsignedTinyInteger('score_min')->nullable();
                $table->unsignedTinyInteger('score_max')->nullable();
                $table->decimal('score_mean', 4, 2)->nullable();
                $table->unsignedTinyInteger('flag_threshold_used')->nullable();
                $table->boolean('flagged_unstable')->nullable();
                $table->uuid('requested_by');
                $table->timestamp('created_at')->useCurrent();

                $table->index('eval_case_id');
                $table->index('source_eval_case_result_id');
            });
        }

        if (!Schema::hasColumn('eval_case_results', 'outcome_override')) {
            Schema::table('eval_case_results', function (Blueprint $table) {
                $table->string('outcome_override', 20)->nullable();
            });
        }
    }

    /**
     * The one new table the regression-detection feature reads and
     * writes — eval_reference_designations, one row per designate-or-move
     * event. Mirrors the production migration exactly. Guarded by
     * Schema::hasTable() like every existing block here, and called from
     * defineDatabaseMigrations() directly, immediately after
     * defineEvalJudgmentSchema(), matching its own call-site pattern.
     */
    protected function defineEvalReferenceSchema(): void
    {
        if (!Schema::hasTable('eval_reference_designations')) {
            Schema::create('eval_reference_designations', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('agent_label', 255);
                $table->uuid('run_id');
                $table->uuid('designated_by')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['agent_label', 'created_at']);
                $table->index('run_id');
            });
        }
    }

    protected function defineEvalPassRateSchema(): void
    {
        if (!Schema::hasTable('eval_pass_rate_summaries')) {
            Schema::create('eval_pass_rate_summaries', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('agent_label', 255);
                $table->date('period_date');
                $table->unsignedInteger('pass_count')->default(0);
                $table->unsignedInteger('fail_count')->default(0);
                $table->unsignedInteger('needs_human_review_count')->default(0);
                $table->unsignedInteger('errored_count')->default(0);
                $table->unsignedInteger('unjudged_count')->default(0);
                $table->unsignedInteger('total_count')->default(0);
                $table->timestamp('updated_at')->useCurrent();

                $table->unique(['agent_label', 'period_date']);
                $table->index(['agent_label', 'period_date']);
            });
        }
    }

}
