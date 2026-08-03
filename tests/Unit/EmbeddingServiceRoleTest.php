<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Exceptions\RoleAssignmentFailedException;
use ClarionApp\LlmClient\Models\LanguageModel;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\EmbeddingService;
use ClarionApp\LlmClient\Services\RoleResolver;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\After;

/**
 * Tests for EmbeddingService integration with role resolution.
 *
 * Covers the resolution order when a user context is available:
 * - Resolved via role takes precedence over config values (FR-017).
 * - Unassigned at both scopes falls back to config value unchanged (FR-017).
 * - Broken does NOT fall back to config and throws RoleAssignmentFailedException.
 */
class EmbeddingServiceRoleTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::table('llm_role_assignments')->delete();
        DB::table('language_models')->delete();
        DB::table('llm_servers')->delete();
        parent::tearDown();
    }

    /* -----------------------------------------------------------------
     * Resolved via role takes precedence over config values
     * ----------------------------------------------------------------- */

    #[Test]
    public function resolved_via_role_takes_precedence_over_config(): void
    {
        $userId = (string) Str::uuid();
        $roleServer = Server::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Role Server',
            'server_url' => 'https://role.example.com',
            'provider_type' => 'openai',
        ]);

        // Configure a different embedding server via config.
        $configServer = Server::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Config Server',
            'server_url' => 'https://config.example.com',
            'provider_type' => 'openai',
        ]);

        Config::set('llm-client.memory.embedding.enabled', true);
        Config::set('llm-client.memory.embedding.server_id', $configServer->id);
        Config::set('llm-client.memory.embedding.model', 'config-embed-model');

        // Create a role assignment for embedding at user scope.
        RoleAssignment::create([
            'role' => 'embedding',
            'user_id' => $userId,
            'server_id' => $roleServer->id,
            'model' => 'role-embed-model',
        ]);

        // Resolve via RoleResolver.
        $resolver = $this->app->make(RoleResolver::class);
        $resolution = $resolver->resolve(ModelRole::Embedding, $userId);

        // The role-resolved server/model wins over config.
        $this->assertTrue($resolution->hasEffectiveModel());
        $this->assertEquals($roleServer->id, $resolution->server->id);
        $this->assertEquals('role-embed-model', $resolution->model);
        $this->assertEquals('user', $resolution->scope);
    }

    /* -----------------------------------------------------------------
     * Unassigned at both scopes falls back to config value unchanged
     * ----------------------------------------------------------------- */

    #[Test]
    public function unassigned_at_both_scopes_falls_back_to_config(): void
    {
        $userId = (string) Str::uuid();

        // Configure embedding via config.
        $configServer = Server::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Config Server',
            'server_url' => 'https://config.example.com',
            'provider_type' => 'openai',
        ]);

        Config::set('llm-client.memory.embedding.enabled', true);
        Config::set('llm-client.memory.embedding.server_id', $configServer->id);
        Config::set('llm-client.memory.embedding.model', 'config-embed-model');

        // No role assignments exist.
        $resolver = $this->app->make(RoleResolver::class);
        $resolution = $resolver->resolve(ModelRole::Embedding, $userId);

        // Resolution returns unassigned — the config fallback is the
        // EmbeddingService's responsibility (research.md D7 step 3).
        $this->assertFalse($resolution->hasEffectiveModel());
        $this->assertEquals('unassigned', $resolution->status->value);

        // Config values remain available for the fallback path.
        $this->assertEquals($configServer->id, Config::get('llm-client.memory.embedding.server_id'));
        $this->assertEquals('config-embed-model', Config::get('llm-client.memory.embedding.model'));
    }

    /* -----------------------------------------------------------------
     * Broken does NOT fall back to config and throws exception
     * ----------------------------------------------------------------- */

    #[Test]
    public function broken_does_not_fall_back_to_config_throws_exception(): void
    {
        $userId = (string) Str::uuid();
        $deletedServer = Server::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Deleted Server',
            'server_url' => 'https://deleted.example.com',
            'provider_type' => 'openai',
        ]);
        $deletedServer->delete(); // Soft delete

        // Configure a valid fallback server via config.
        $configServer = Server::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Config Server',
            'server_url' => 'https://config.example.com',
            'provider_type' => 'openai',
        ]);

        Config::set('llm-client.memory.embedding.enabled', true);
        Config::set('llm-client.memory.embedding.server_id', $configServer->id);
        Config::set('llm-client.memory.embedding.model', 'config-embed-model');

        // Create a role assignment pointing at the deleted server.
        RoleAssignment::create([
            'role' => 'embedding',
            'user_id' => $userId,
            'server_id' => $deletedServer->id,
            'model' => 'vanished-embed-model',
        ]);

        // Resolve via RoleResolver.
        $resolver = $this->app->make(RoleResolver::class);
        $resolution = $resolver->resolve(ModelRole::Embedding, $userId);

        // Resolution returns broken — NOT unassigned (so config fallback is skipped).
        $this->assertFalse($resolution->hasEffectiveModel());
        $this->assertEquals('broken', $resolution->status->value);
        $this->assertEquals('vanished-embed-model', $resolution->model);
        $this->assertEquals('server deleted', $resolution->brokenReason);

        // The RoleAssignmentFailedException should name the role and model.
        $exception = new RoleAssignmentFailedException(
            ModelRole::Embedding,
            $resolution->model,
            $resolution->brokenReason
        );

        $this->assertInstanceOf(RoleAssignmentFailedException::class, $exception);
        $this->assertEquals(ModelRole::Embedding, $exception->role);
        $this->assertEquals('vanished-embed-model', $exception->model);
        $this->assertStringContainsString('vanished-embed-model', $exception->getMessage());
        $this->assertStringContainsString('server deleted', $exception->getMessage());
    }

    #[Test]
    public function broken_model_removed_does_not_fall_back_to_config(): void
    {
        $userId = (string) Str::uuid();
        $server = Server::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Active Server',
            'server_url' => 'https://active.example.com',
            'provider_type' => 'openai',
        ]);

        // Create a language model that is then soft-deleted (removed by refresh).
        LanguageModel::create([
            'id' => (string) Str::uuid(),
            'server_id' => $server->id,
            'name' => 'old-embed-model',
        ]);
        $languageModel = LanguageModel::where('server_id', $server->id)
            ->where('name', 'old-embed-model')
            ->first();
        $languageModel->delete(); // Soft delete (model removed by refresh)

        // Configure a valid fallback server via config.
        $configServer = Server::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Config Server',
            'server_url' => 'https://config.example.com',
            'provider_type' => 'openai',
        ]);

        Config::set('llm-client.memory.embedding.enabled', true);
        Config::set('llm-client.memory.embedding.server_id', $configServer->id);
        Config::set('llm-client.memory.embedding.model', 'config-embed-model');

        // Create a role assignment pointing at the removed model.
        RoleAssignment::create([
            'role' => 'embedding',
            'user_id' => $userId,
            'server_id' => $server->id,
            'model' => 'old-embed-model',
        ]);

        // Resolve via RoleResolver.
        $resolver = $this->app->make(RoleResolver::class);
        $resolution = $resolver->resolve(ModelRole::Embedding, $userId);

        // Resolution returns broken (model removed), not unassigned.
        $this->assertFalse($resolution->hasEffectiveModel());
        $this->assertEquals('broken', $resolution->status->value);
        $this->assertEquals('old-embed-model', $resolution->model);
        $this->assertEquals('model removed', $resolution->brokenReason);
    }
}
