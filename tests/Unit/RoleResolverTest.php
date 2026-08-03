<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\LanguageModel;
use ClarionApp\LlmClient\Services\RoleResolver;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use ClarionApp\LlmClient\ValueObjects\RoleResolution;
use ClarionApp\LlmClient\ValueObjects\RoleResolutionStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\After;

/**
 * Tests for RoleResolver resolve() method.
 * Covers the full resolution chain: user -> installation -> unassigned,
 * and broken detection for deleted servers and models.
 */
class RoleResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::table('llm_role_assignments')->delete();
        DB::table('language_models')->delete();
        DB::table('llm_servers')->delete();
        parent::tearDown();
    }

    // ========== User overrides installation ==========

    #[Test]
    public function user_assignment_overrides_installation(): void
    {
        $resolver = $this->app->make(RoleResolver::class);
        $userId = (string) Str::uuid();

        // Create servers (forceCreate because 'id' is not in $fillable)
        $installServer = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Install Server']);
        $userServer = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'User Server']);

        // Installation assignment
        RoleAssignment::create([
            'role' => 'inference',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $installServer->id,
            'model' => 'install-model',
        ]);

        // User assignment (override)
        RoleAssignment::create([
            'role' => 'inference',
            'user_id' => $userId,
            'server_id' => $userServer->id,
            'model' => 'user-model',
        ]);

        $result = $resolver->resolve(ModelRole::Inference, $userId);

        $this->assertEquals(RoleResolutionStatus::Resolved, $result->status);
        $this->assertEquals('user', $result->scope);
        $this->assertEquals($userServer->id, $result->server->id);
        $this->assertEquals('user-model', $result->model);
        $this->assertTrue($result->hasEffectiveModel());
    }

    // ========== Installation-only when no user row ==========

    #[Test]
    public function installation_only_when_no_user_row(): void
    {
        $resolver = $this->app->make(RoleResolver::class);
        $userId = (string) Str::uuid();

        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Install Server']);

        RoleAssignment::create([
            'role' => 'embedding',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $server->id,
            'model' => 'embed-v3',
        ]);

        $result = $resolver->resolve(ModelRole::Embedding, $userId);

        $this->assertEquals(RoleResolutionStatus::Resolved, $result->status);
        $this->assertEquals('installation', $result->scope);
        $this->assertEquals($server->id, $result->server->id);
        $this->assertEquals('embed-v3', $result->model);
    }

    // ========== Unassigned when neither ==========

    #[Test]
    public function unassigned_when_no_assignment_at_any_scope(): void
    {
        $resolver = $this->app->make(RoleResolver::class);
        $userId = (string) Str::uuid();

        $result = $resolver->resolve(ModelRole::Image, $userId);

        $this->assertEquals(RoleResolutionStatus::Unassigned, $result->status);
        $this->assertNull($result->scope);
        $this->assertNull($result->server);
        $this->assertNull($result->model);
        $this->assertFalse($result->hasEffectiveModel());
    }

    // ========== Broken when server is soft-deleted ==========

    #[Test]
    public function broken_when_assigned_server_is_soft_deleted(): void
    {
        $resolver = $this->app->make(RoleResolver::class);
        $userId = (string) Str::uuid();

        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Deleted Server']);

        RoleAssignment::create([
            'role' => 'inference',
            'user_id' => $userId,
            'server_id' => $server->id,
            'model' => 'gpt-4',
        ]);

        // Soft delete the server
        $server->delete();

        $result = $resolver->resolve(ModelRole::Inference, $userId);

        $this->assertEquals(RoleResolutionStatus::Broken, $result->status);
        $this->assertNotNull($result->brokenReason);
        $this->assertFalse($result->hasEffectiveModel());
    }

    // ========== Broken when model is soft-deleted for that server ==========

    #[Test]
    public function broken_when_assigned_model_is_soft_deleted_for_server(): void
    {
        $resolver = $this->app->make(RoleResolver::class);
        $userId = (string) Str::uuid();

        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Active Server']);

        $languageModel = LanguageModel::forceCreate([
            'id' => (string) Str::uuid(),
            'server_id' => $server->id,
            'name' => 'text-embedding-ada-002',
        ]);

        RoleAssignment::create([
            'role' => 'embedding',
            'user_id' => $userId,
            'server_id' => $server->id,
            'model' => 'text-embedding-ada-002',
        ]);

        // Soft delete the language model
        $languageModel->delete();

        $result = $resolver->resolve(ModelRole::Embedding, $userId);

        $this->assertEquals(RoleResolutionStatus::Broken, $result->status);
        $this->assertNotNull($result->brokenReason);
        $this->assertFalse($result->hasEffectiveModel());
    }

    // ========== NOT broken when model string matches no language_models row (D2 asymmetry) ==========

    #[Test]
    public function not_broken_when_model_string_matches_no_language_models_row(): void
    {
        // D2 asymmetry: a model name that was never in the discovery cache is
        // trusted (resolved), not treated as broken. Only a known-then-removed
        // model is broken.
        $resolver = $this->app->make(RoleResolver::class);
        $userId = (string) Str::uuid();

        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Active Server']);

        // Do NOT create a LanguageModel row for this model name.
        // The model string is trusted as-is (D1/D2).
        RoleAssignment::create([
            'role' => 'inference',
            'user_id' => $userId,
            'server_id' => $server->id,
            'model' => 'some-model-not-in-discovery-cache',
        ]);

        $result = $resolver->resolve(ModelRole::Inference, $userId);

        $this->assertEquals(RoleResolutionStatus::Resolved, $result->status);
        $this->assertEquals('user', $result->scope);
        $this->assertEquals($server->id, $result->server->id);
        $this->assertEquals('some-model-not-in-discovery-cache', $result->model);
        $this->assertTrue($result->hasEffectiveModel());
    }

    // ========== $userId = null resolves installation-only ==========

    #[Test]
    public function null_user_id_resolves_installation_only(): void
    {
        $resolver = $this->app->make(RoleResolver::class);

        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Install Server']);

        RoleAssignment::create([
            'role' => 'embedding',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $server->id,
            'model' => 'embed-v3',
        ]);

        // Pass null for userId — should skip user scope and resolve installation
        $result = $resolver->resolve(ModelRole::Embedding, null);

        $this->assertEquals(RoleResolutionStatus::Resolved, $result->status);
        $this->assertEquals('installation', $result->scope);
        $this->assertEquals($server->id, $result->server->id);
        $this->assertEquals('embed-v3', $result->model);
    }

    #[Test]
    public function null_user_id_with_no_installation_assignment_returns_unassigned(): void
    {
        $resolver = $this->app->make(RoleResolver::class);

        $result = $resolver->resolve(ModelRole::Image, null);

        $this->assertEquals(RoleResolutionStatus::Unassigned, $result->status);
        $this->assertNull($result->scope);
        $this->assertFalse($result->hasEffectiveModel());
    }

    // ========== Broken on installation scope ==========

    #[Test]
    public function broken_on_installation_scope_when_server_deleted(): void
    {
        $resolver = $this->app->make(RoleResolver::class);
        $userId = (string) Str::uuid();

        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Install Server']);

        RoleAssignment::create([
            'role' => 'inference',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $server->id,
            'model' => 'gpt-4',
        ]);

        $server->delete();

        // User with no own assignment — falls through to installation, which is broken
        $result = $resolver->resolve(ModelRole::Inference, $userId);

        $this->assertEquals(RoleResolutionStatus::Broken, $result->status);
        $this->assertEquals('installation', $result->scope);
        $this->assertNotNull($result->brokenReason);
    }
}
