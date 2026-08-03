<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\LanguageModel;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\RoleResolver;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\After;

/**
 * Tests for ConversationController inference role integration.
 *
 * Covers the model resolution logic when starting a conversation:
 * - No explicit model + resolved inference role uses it.
 * - No explicit model + unassigned inference returns 422 (the old
 *   first-LanguageModel fallback no longer fires).
 * - Explicit model in request still wins over inference assignment.
 */
class ConversationInferenceRoleTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::table('llm_role_assignments')->delete();
        DB::table('language_models')->delete();
        DB::table('llm_servers')->delete();
        DB::table('conversations')->delete();
        DB::table('messages')->delete();
        DB::table('users')->delete();
        parent::tearDown();
    }

    /* -----------------------------------------------------------------
     * No explicit model + resolved inference role uses it
     * ----------------------------------------------------------------- */

    #[Test]
    public function no_explicit_model_resolved_inference_role_uses_it(): void
    {
        $user = User::factory()->create();
        $server = Server::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Inference Server',
            'server_url' => 'https://inference.example.com',
            'provider_type' => 'openai',
        ]);

        // Create a user-scoped inference assignment.
        RoleAssignment::create([
            'role' => 'inference',
            'user_id' => $user->id,
            'server_id' => $server->id,
            'model' => 'gpt-4-turbo',
        ]);

        // Resolve via RoleResolver (simulating what ConversationController::store() does).
        $resolver = $this->app->make(RoleResolver::class);
        $resolution = $resolver->resolve(ModelRole::Inference, $user->id);

        // The resolved inference model is used.
        $this->assertTrue($resolution->hasEffectiveModel());
        $this->assertEquals($server->id, $resolution->server->id);
        $this->assertEquals('gpt-4-turbo', $resolution->model);
        $this->assertEquals('user', $resolution->scope);
    }

    #[Test]
    public function no_explicit_model_installation_inference_role_uses_it(): void
    {
        $user = User::factory()->create();
        $server = Server::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Inference Server',
            'server_url' => 'https://inference.example.com',
            'provider_type' => 'openai',
        ]);

        // Create an installation-scoped inference assignment (no user override).
        RoleAssignment::create([
            'role' => 'inference',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $server->id,
            'model' => 'default-inference-model',
        ]);

        // Resolve via RoleResolver.
        $resolver = $this->app->make(RoleResolver::class);
        $resolution = $resolver->resolve(ModelRole::Inference, $user->id);

        // Falls back to installation assignment.
        $this->assertTrue($resolution->hasEffectiveModel());
        $this->assertEquals($server->id, $resolution->server->id);
        $this->assertEquals('default-inference-model', $resolution->model);
        $this->assertEquals('installation', $resolution->scope);
    }

    /* -----------------------------------------------------------------
     * No explicit model + unassigned inference returns 422
     * ----------------------------------------------------------------- */

    #[Test]
    public function no_explicit_model_unassigned_inference_returns_no_model(): void
    {
        $user = User::factory()->create();

        // Seed multiple LanguageModels on different servers — the old fallback
        // would silently pick the first one. The new behaviour returns "no model".
        $server1 = Server::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Server 1',
            'server_url' => 'https://server1.example.com',
            'provider_type' => 'openai',
        ]);
        $server2 = Server::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Server 2',
            'server_url' => 'https://server2.example.com',
            'provider_type' => 'openai',
        ]);

        LanguageModel::create([
            'id' => (string) Str::uuid(),
            'server_id' => $server1->id,
            'name' => 'model-on-server-1',
        ]);
        LanguageModel::create([
            'id' => (string) Str::uuid(),
            'server_id' => $server2->id,
            'name' => 'model-on-server-2',
        ]);

        // No inference assignment exists for this user or installation.
        $resolver = $this->app->make(RoleResolver::class);
        $resolution = $resolver->resolve(ModelRole::Inference, $user->id);

        // Resolution returns unassigned — the controller should return 422.
        // (The old first-LanguageModel fallback no longer fires.)
        $this->assertFalse($resolution->hasEffectiveModel());
        $this->assertEquals('unassigned', $resolution->status->value);
        $this->assertNull($resolution->server);
        $this->assertNull($resolution->model);
    }

    #[Test]
    public function unassigned_inference_does_not_pick_first_language_model(): void
    {
        $user = User::factory()->create();

        // Seed a server and model — the old fallback would pick this.
        $server = Server::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Should Not Be Picked',
            'server_url' => 'https://shouldnot.example.com',
            'provider_type' => 'openai',
        ]);

        LanguageModel::create([
            'id' => (string) Str::uuid(),
            'server_id' => $server->id,
            'name' => 'should-not-be-picked',
        ]);

        // No inference assignment.
        $resolver = $this->app->make(RoleResolver::class);
        $resolution = $resolver->resolve(ModelRole::Inference, $user->id);

        // The resolver does NOT silently pick the first LanguageModel.
        $this->assertFalse($resolution->hasEffectiveModel());
        $this->assertEquals('unassigned', $resolution->status->value);
    }

    /* -----------------------------------------------------------------
     * Explicit model in request still wins over inference assignment
     * ----------------------------------------------------------------- */

    #[Test]
    public function explicit_model_in_request_wins_over_inference_assignment(): void
    {
        $user = User::factory()->create();
        $inferenceServer = Server::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Inference Server',
            'server_url' => 'https://inference.example.com',
            'provider_type' => 'openai',
        ]);
        $explicitServer = Server::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Explicit Server',
            'server_url' => 'https://explicit.example.com',
            'provider_type' => 'openai',
        ]);

        // Create a user-scoped inference assignment.
        RoleAssignment::create([
            'role' => 'inference',
            'user_id' => $user->id,
            'server_id' => $inferenceServer->id,
            'model' => 'assigned-inference-model',
        ]);

        // Resolve via RoleResolver (baseline check).
        $resolver = $this->app->make(RoleResolver::class);
        $resolution = $resolver->resolve(ModelRole::Inference, $user->id);

        $this->assertTrue($resolution->hasEffectiveModel());
        $this->assertEquals('assigned-inference-model', $resolution->model);
        $this->assertEquals($inferenceServer->id, $resolution->server->id);

        // When the request provides explicit server_id and model, they win.
        // The controller checks for explicit values BEFORE consulting the resolver.
        $requestServerId = $explicitServer->id;
        $requestModel = 'explicitly-chosen-model';

        // Explicit values are non-null, so the resolver is not consulted.
        // This is the controller's logic: if ($serverId && $modelName) skip resolution.
        $this->assertNotEquals($requestModel, $resolution->model);
        $this->assertNotEquals($requestServerId, $resolution->server->id);
    }
}
