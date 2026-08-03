<?php

namespace ClarionApp\LlmClient\Tests\Integration;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\LanguageModel;
use ClarionApp\LlmClient\Services\RoleResolver;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use ClarionApp\LlmClient\ValueObjects\RoleResolutionStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\After;

/**
 * Integration tests for installation-wide default assignments.
 *
 * Validates immediate effect without restart and isolation across roles
 * and users.
 */
class InstallationDefaultJourneyTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::table('llm_role_assignments')->delete();
        DB::table('language_models')->delete();
        DB::table('llm_servers')->delete();
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Two users, one override — isolation across all three roles
    // -----------------------------------------------------------------------

    #[Test]
    public function two_users_one_override_isolation_across_all_roles(): void
    {
        $resolver = $this->app->make(RoleResolver::class);
        $userA = (string) Str::uuid();
        $userB = (string) Str::uuid();

        // Create a server and models
        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Primary']);
        LanguageModel::create(['id' => (string) Str::uuid(), 'name' => 'gpt-4', 'server_id' => $server->id]);
        LanguageModel::create(['id' => (string) Str::uuid(), 'name' => 'embed-v3', 'server_id' => $server->id]);
        LanguageModel::create(['id' => (string) Str::uuid(), 'name' => 'dall-e-3', 'server_id' => $server->id]);

        // Set installation defaults for all three roles
        RoleAssignment::create([
            'role' => 'inference', 'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $server->id, 'model' => 'gpt-4',
        ]);
        RoleAssignment::create([
            'role' => 'embedding', 'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $server->id, 'model' => 'embed-v3',
        ]);
        RoleAssignment::create([
            'role' => 'image', 'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $server->id, 'model' => 'dall-e-3',
        ]);

        // User A overrides only inference
        RoleAssignment::create([
            'role' => 'inference', 'user_id' => $userA,
            'server_id' => $server->id, 'model' => 'gpt-4',
        ]);

        // User A: inference should be user-scoped (override), embedding and image installation
        $aInf = $resolver->resolve(ModelRole::Inference, $userA);
        $aEmb = $resolver->resolve(ModelRole::Embedding, $userA);
        $aImg = $resolver->resolve(ModelRole::Image, $userA);

        $this->assertEquals(RoleResolutionStatus::Resolved, $aInf->status);
        $this->assertEquals('user', $aInf->scope);
        $this->assertEquals('gpt-4', $aInf->model);

        $this->assertEquals(RoleResolutionStatus::Resolved, $aEmb->status);
        $this->assertEquals('installation', $aEmb->scope);
        $this->assertEquals('embed-v3', $aEmb->model);

        $this->assertEquals(RoleResolutionStatus::Resolved, $aImg->status);
        $this->assertEquals('installation', $aImg->scope);
        $this->assertEquals('dall-e-3', $aImg->model);

        // User B: all three roles from installation (no overrides)
        $bInf = $resolver->resolve(ModelRole::Inference, $userB);
        $bEmb = $resolver->resolve(ModelRole::Embedding, $userB);
        $bImg = $resolver->resolve(ModelRole::Image, $userB);

        $this->assertEquals(RoleResolutionStatus::Resolved, $bInf->status);
        $this->assertEquals('installation', $bInf->scope);
        $this->assertEquals('gpt-4', $bInf->model);

        $this->assertEquals(RoleResolutionStatus::Resolved, $bEmb->status);
        $this->assertEquals('installation', $bEmb->scope);
        $this->assertEquals('embed-v3', $bEmb->model);

        $this->assertEquals(RoleResolutionStatus::Resolved, $bImg->status);
        $this->assertEquals('installation', $bImg->scope);
        $this->assertEquals('dall-e-3', $bImg->model);
    }

    // -----------------------------------------------------------------------
    // Installation assignment change takes effect immediately
    // -----------------------------------------------------------------------

    #[Test]
    public function installation_assignment_change_takes_effect_immediately(): void
    {
        $service = $this->app->make(RoleAssignmentService::class);
        $resolver = $this->app->make(RoleResolver::class);
        $userId = (string) Str::uuid();

        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Primary']);
        LanguageModel::create(['id' => (string) Str::uuid(), 'name' => 'gpt-3.5', 'server_id' => $server->id]);
        LanguageModel::create(['id' => (string) Str::uuid(), 'name' => 'gpt-4', 'server_id' => $server->id]);

        // Set installation default to gpt-3.5
        $service->set(ModelRole::Inference, RoleAssignment::INSTALLATION_SCOPE_ID, $server->id, 'gpt-3.5');

        $result1 = $resolver->resolve(ModelRole::Inference, $userId);
        $this->assertEquals('gpt-3.5', $result1->model);
        $this->assertEquals('installation', $result1->scope);

        // Change installation default to gpt-4 (no restart, no sign-out)
        $service->set(ModelRole::Inference, RoleAssignment::INSTALLATION_SCOPE_ID, $server->id, 'gpt-4');

        $result2 = $resolver->resolve(ModelRole::Inference, $userId);
        $this->assertEquals('gpt-4', $result2->model);
        $this->assertEquals('installation', $result2->scope);
    }

    // -----------------------------------------------------------------------
    // Installation assignment for a role + no user assignment → any user gets installation's model
    // -----------------------------------------------------------------------

    #[Test]
    public function us2_scenario1_installation_only_any_user_gets_it(): void
    {
        $resolver = $this->app->make(RoleResolver::class);
        $userA = (string) Str::uuid();
        $userB = (string) Str::uuid();

        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'S1']);
        LanguageModel::create(['id' => (string) Str::uuid(), 'name' => 'model-a', 'server_id' => $server->id]);

        RoleAssignment::create([
            'role' => 'embedding', 'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $server->id, 'model' => 'model-a',
        ]);

        // Both users get the installation assignment
        $rA = $resolver->resolve(ModelRole::Embedding, $userA);
        $rB = $resolver->resolve(ModelRole::Embedding, $userB);

        $this->assertEquals(RoleResolutionStatus::Resolved, $rA->status);
        $this->assertEquals('installation', $rA->scope);
        $this->assertEquals('model-a', $rA->model);

        $this->assertEquals(RoleResolutionStatus::Resolved, $rB->status);
        $this->assertEquals('installation', $rB->scope);
        $this->assertEquals('model-a', $rB->model);
    }

    // -----------------------------------------------------------------------
    // Installation + user assignment for same role → that user gets their own model,
    // other users get installation's
    // -----------------------------------------------------------------------

    #[Test]
    public function us2_scenario2_user_override_other_users_get_installation(): void
    {
        $resolver = $this->app->make(RoleResolver::class);
        $userA = (string) Str::uuid();
        $userB = (string) Str::uuid();

        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'S2']);
        LanguageModel::create(['id' => (string) Str::uuid(), 'name' => 'install-model', 'server_id' => $server->id]);
        LanguageModel::create(['id' => (string) Str::uuid(), 'name' => 'user-model', 'server_id' => $server->id]);

        // Installation default
        RoleAssignment::create([
            'role' => 'inference', 'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $server->id, 'model' => 'install-model',
        ]);

        // User A overrides
        RoleAssignment::create([
            'role' => 'inference', 'user_id' => $userA,
            'server_id' => $server->id, 'model' => 'user-model',
        ]);

        // User A gets their override
        $rA = $resolver->resolve(ModelRole::Inference, $userA);
        $this->assertEquals(RoleResolutionStatus::Resolved, $rA->status);
        $this->assertEquals('user', $rA->scope);
        $this->assertEquals('user-model', $rA->model);

        // User B gets installation default
        $rB = $resolver->resolve(ModelRole::Inference, $userB);
        $this->assertEquals(RoleResolutionStatus::Resolved, $rB->status);
        $this->assertEquals('installation', $rB->scope);
        $this->assertEquals('install-model', $rB->model);
    }

    // -----------------------------------------------------------------------
    // User with override for one role only → gets installation's models for other two roles
    // -----------------------------------------------------------------------

    #[Test]
    public function us2_scenario3_one_override_inherits_others(): void
    {
        $resolver = $this->app->make(RoleResolver::class);
        $userId = (string) Str::uuid();

        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'S3']);
        LanguageModel::create(['id' => (string) Str::uuid(), 'name' => 'user-inf', 'server_id' => $server->id]);
        LanguageModel::create(['id' => (string) Str::uuid(), 'name' => 'install-emb', 'server_id' => $server->id]);
        LanguageModel::create(['id' => (string) Str::uuid(), 'name' => 'install-img', 'server_id' => $server->id]);

        // Installation defaults for all three roles
        RoleAssignment::create([
            'role' => 'inference', 'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $server->id, 'model' => 'install-inf',
        ]);
        RoleAssignment::create([
            'role' => 'embedding', 'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $server->id, 'model' => 'install-emb',
        ]);
        RoleAssignment::create([
            'role' => 'image', 'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $server->id, 'model' => 'install-img',
        ]);

        // User overrides only inference
        RoleAssignment::create([
            'role' => 'inference', 'user_id' => $userId,
            'server_id' => $server->id, 'model' => 'user-inf',
        ]);

        // Inference: user override
        $inf = $resolver->resolve(ModelRole::Inference, $userId);
        $this->assertEquals('user', $inf->scope);
        $this->assertEquals('user-inf', $inf->model);

        // Embedding: installation default
        $emb = $resolver->resolve(ModelRole::Embedding, $userId);
        $this->assertEquals('installation', $emb->scope);
        $this->assertEquals('install-emb', $emb->model);

        // Image: installation default
        $img = $resolver->resolve(ModelRole::Image, $userId);
        $this->assertEquals('installation', $img->scope);
        $this->assertEquals('install-img', $img->model);
    }

    // -----------------------------------------------------------------------
    // Installation assignment changed → user without override gets new model immediately
    // -----------------------------------------------------------------------

    #[Test]
    public function us2_scenario4_installation_change_immediate_for_no_override_user(): void
    {
        $service = $this->app->make(RoleAssignmentService::class);
        $resolver = $this->app->make(RoleResolver::class);
        $userId = (string) Str::uuid();

        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'S4']);
        LanguageModel::create(['id' => (string) Str::uuid(), 'name' => 'model-v1', 'server_id' => $server->id]);
        LanguageModel::create(['id' => (string) Str::uuid(), 'name' => 'model-v2', 'server_id' => $server->id]);

        // Initial installation assignment
        $service->set(ModelRole::Embedding, RoleAssignment::INSTALLATION_SCOPE_ID, $server->id, 'model-v1');

        $r1 = $resolver->resolve(ModelRole::Embedding, $userId);
        $this->assertEquals('model-v1', $r1->model);
        $this->assertEquals('installation', $r1->scope);

        // Change installation assignment
        $service->set(ModelRole::Embedding, RoleAssignment::INSTALLATION_SCOPE_ID, $server->id, 'model-v2');

        // User without override sees the new model immediately
        $r2 = $resolver->resolve(ModelRole::Embedding, $userId);
        $this->assertEquals('model-v2', $r2->model);
        $this->assertEquals('installation', $r2->scope);
    }

    // -----------------------------------------------------------------------
    // Deployment-file embedding model vs assignment → assignment wins
    // -----------------------------------------------------------------------

    #[Test]
    public function us2_scenario5_assignment_wins_over_deployment_file(): void
    {
        $service = $this->app->make(RoleAssignmentService::class);
        $resolver = $this->app->make(RoleResolver::class);
        $userId = (string) Str::uuid();

        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'S5']);
        LanguageModel::create(['id' => (string) Str::uuid(), 'name' => 'assigned-embed', 'server_id' => $server->id]);

        // Set an installation-level embedding assignment
        $service->set(ModelRole::Embedding, RoleAssignment::INSTALLATION_SCOPE_ID, $server->id, 'assigned-embed');

        // The resolver returns the assignment, not a config value
        $result = $resolver->resolve(ModelRole::Embedding, $userId);
        $this->assertEquals(RoleResolutionStatus::Resolved, $result->status);
        $this->assertEquals('installation', $result->scope);
        $this->assertEquals('assigned-embed', $result->model);

        // The resolver does not consult config — it only resolves from assignments.
        // EmbeddingService::getProvider() is the layer that falls back to config
        // when the resolver returns unassigned. Here the resolver returns resolved,
        // so the assignment wins.
    }

    // -----------------------------------------------------------------------
    // describeAllRoles with installation defaults
    // -----------------------------------------------------------------------

    #[Test]
    public function describeAllRoles_shows_installation_assignments(): void
    {
        $service = $this->app->make(RoleAssignmentService::class);
        $userId = (string) Str::uuid();

        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'S6']);
        LanguageModel::create(['id' => (string) Str::uuid(), 'name' => 'gpt-4', 'server_id' => $server->id]);
        LanguageModel::create(['id' => (string) Str::uuid(), 'name' => 'embed-v3', 'server_id' => $server->id]);

        // Installation defaults only
        $service->set(ModelRole::Inference, RoleAssignment::INSTALLATION_SCOPE_ID, $server->id, 'gpt-4');
        $service->set(ModelRole::Embedding, RoleAssignment::INSTALLATION_SCOPE_ID, $server->id, 'embed-v3');

        $described = $service->describeAllRoles($userId);

        // Inference: resolved via installation
        $this->assertEquals('resolved', $described['inference']['effective']['status']);
        $this->assertEquals('installation', $described['inference']['effective']['scope']);
        $this->assertEquals('gpt-4', $described['inference']['effective']['model']);
        $this->assertNull($described['inference']['user_assignment']);
        $this->assertNotNull($described['inference']['installation_assignment']);
        $this->assertEquals('gpt-4', $described['inference']['installation_assignment']['model']);

        // Embedding: resolved via installation
        $this->assertEquals('resolved', $described['embedding']['effective']['status']);
        $this->assertEquals('installation', $described['embedding']['effective']['scope']);
        $this->assertEquals('embed-v3', $described['embedding']['effective']['model']);
        $this->assertNull($described['embedding']['user_assignment']);
        $this->assertNotNull($described['embedding']['installation_assignment']);

        // Image: unassigned (no assignment at any scope)
        $this->assertEquals('unassigned', $described['image']['effective']['status']);
        $this->assertNull($described['image']['user_assignment']);
        $this->assertNull($described['image']['installation_assignment']);
    }

    // -----------------------------------------------------------------------
    // describeAllRoles with mixed user override + installation defaults
    // -----------------------------------------------------------------------

    #[Test]
    public function describeAllRoles_shows_mixed_override_and_installation(): void
    {
        $service = $this->app->make(RoleAssignmentService::class);
        $userId = (string) Str::uuid();

        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'S7']);
        LanguageModel::create(['id' => (string) Str::uuid(), 'name' => 'user-gpt', 'server_id' => $server->id]);
        LanguageModel::create(['id' => (string) Str::uuid(), 'name' => 'install-gpt', 'server_id' => $server->id]);
        LanguageModel::create(['id' => (string) Str::uuid(), 'name' => 'install-embed', 'server_id' => $server->id]);

        // Installation defaults for inference and embedding
        $service->set(ModelRole::Inference, RoleAssignment::INSTALLATION_SCOPE_ID, $server->id, 'install-gpt');
        $service->set(ModelRole::Embedding, RoleAssignment::INSTALLATION_SCOPE_ID, $server->id, 'install-embed');

        // User overrides inference only
        $service->set(ModelRole::Inference, $userId, $server->id, 'user-gpt');

        $described = $service->describeAllRoles($userId);

        // Inference: user override wins
        $this->assertEquals('resolved', $described['inference']['effective']['status']);
        $this->assertEquals('user', $described['inference']['effective']['scope']);
        $this->assertEquals('user-gpt', $described['inference']['effective']['model']);
        $this->assertNotNull($described['inference']['user_assignment']);
        $this->assertEquals('user-gpt', $described['inference']['user_assignment']['model']);
        $this->assertNotNull($described['inference']['installation_assignment']);
        $this->assertEquals('install-gpt', $described['inference']['installation_assignment']['model']);

        // Embedding: from installation
        $this->assertEquals('resolved', $described['embedding']['effective']['status']);
        $this->assertEquals('installation', $described['embedding']['effective']['scope']);
        $this->assertEquals('install-embed', $described['embedding']['effective']['model']);
        $this->assertNull($described['embedding']['user_assignment']);
        $this->assertNotNull($described['embedding']['installation_assignment']);

        // Image: unassigned
        $this->assertEquals('unassigned', $described['image']['effective']['status']);
        $this->assertNull($described['image']['user_assignment']);
        $this->assertNull($described['image']['installation_assignment']);
    }

    // -----------------------------------------------------------------------
    // Clearing installation assignment falls back correctly
    // -----------------------------------------------------------------------

    #[Test]
    public function clearing_installation_assignment_falls_back_to_unassigned(): void
    {
        $service = $this->app->make(RoleAssignmentService::class);
        $resolver = $this->app->make(RoleResolver::class);
        $userId = (string) Str::uuid();

        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'S8']);
        LanguageModel::create(['id' => (string) Str::uuid(), 'name' => 'gpt-4', 'server_id' => $server->id]);

        // Set installation default
        $service->set(ModelRole::Inference, RoleAssignment::INSTALLATION_SCOPE_ID, $server->id, 'gpt-4');

        $r1 = $resolver->resolve(ModelRole::Inference, $userId);
        $this->assertEquals(RoleResolutionStatus::Resolved, $r1->status);
        $this->assertEquals('gpt-4', $r1->model);

        // Clear installation default
        $service->clear(ModelRole::Inference, RoleAssignment::INSTALLATION_SCOPE_ID);

        $r2 = $resolver->resolve(ModelRole::Inference, $userId);
        $this->assertEquals(RoleResolutionStatus::Unassigned, $r2->status);
        $this->assertFalse($r2->hasEffectiveModel());
    }
}
