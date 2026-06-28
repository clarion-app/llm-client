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
        // Create the users table (required by tests that use User::factory()).
        // This mirrors the backend migration without pulling in the full
        // ClarionBackendServiceProvider and all its dependencies.
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

        // llm_memory_entries table (for memory system tests).
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
}
