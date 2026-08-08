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
                $table->string('provider_override')->nullable();
                $table->boolean('is_processing')->default(false);
                $table->timestamp('ended_at')->nullable();
                $table->index('user_id');
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

}
