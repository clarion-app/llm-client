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
                $table->uuid('parent_id')->nullable();
                $table->unsignedInteger('sequence_number')->default(0);
                $table->timestamps();
                $table->softDeletes();
                $table->index('conversation_id');
                $table->index(['conversation_id', 'sequence_number']);
            });
        }

        // usage_records table (for metrics tests).
        if (!Schema::hasTable('usage_records')) {
            Schema::create('usage_records', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id');
                $table->uuid('user_id');
                $table->uuid('attempt_group_id');
                $table->integer('input_tokens')->nullable()->default(0);
                $table->integer('output_tokens')->nullable()->default(0);
                $table->integer('total_tokens')->nullable()->default(0);
                $table->boolean('input_estimated')->default(false);
                $table->boolean('output_estimated')->default(false);
                $table->string('model', 128)->nullable();
                $table->string('provider_type', 32)->nullable();
                $table->json('co_member_tags')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index('conversation_id');
                $table->index('user_id');
                $table->index('attempt_group_id');
                $table->index(['user_id', 'created_at']);
            });
        }

        // tool_invocation_records table (for metrics tests).
        if (!Schema::hasTable('tool_invocation_records')) {
            Schema::create('tool_invocation_records', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id');
                $table->uuid('user_id');
                $table->uuid('attempt_group_id');
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

    }
}
