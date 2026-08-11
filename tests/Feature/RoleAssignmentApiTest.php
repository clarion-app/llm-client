<?php

namespace Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\LanguageModel;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\After;

/**
 * Feature test: Role Assignment API endpoints.
 *
 * Covers:
 * - GET /role-assignment shape for all three statuses (resolved, unassigned, broken)
 * - PUT /role-assignment validation (exists:llm_servers,id, Rule::in for role/scope)
 * - PUT with scope=installation succeeds for a user with no special privilege
 * - DELETE /role-assignment returns fallen-back effective state in same response
 *
 * Tests use the controller layer directly (Passport unavailable in test bench).
 */
class RoleAssignmentApiTest extends TestCase
{
    protected function tearDown(): void
    {
        \Illuminate\Support\Facades\DB::table('llm_role_assignments')->delete();
        \Illuminate\Support\Facades\DB::table('language_models')->delete();
        \Illuminate\Support\Facades\DB::table('llm_servers')->delete();
        \Illuminate\Support\Facades\DB::table('users')->delete();
        parent::tearDown();
    }

    /* -----------------------------------------------------------------
     * GET /role-assignment — shape for all three statuses
     * ----------------------------------------------------------------- */

    #[Test]
    public function get_role_assignment_resolved_shape(): void
    {
        $user = User::factory()->create();
        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Test Server']);

        $service = $this->app->make(RoleAssignmentService::class);
        $service->set(ModelRole::Inference, $user->id, $server->id, 'gpt-4');

        $result = $service->describeAllRoles($user->id);

        $inference = $result['inference'];
        $this->assertEquals('inference', $inference['role']);
        $this->assertEquals('resolved', $inference['effective']['status']);
        $this->assertEquals('user', $inference['effective']['scope']);
        $this->assertNotNull($inference['effective']['server']);
        $this->assertEquals($server->id, $inference['effective']['server']['id']);
        $this->assertEquals('gpt-4', $inference['effective']['model']);
        $this->assertNull($inference['effective']['reason']);
        // User assignment is populated.
        $this->assertNotNull($inference['user_assignment']);
        $this->assertEquals($server->id, $inference['user_assignment']['server_id']);
        $this->assertEquals('gpt-4', $inference['user_assignment']['model']);
    }

    #[Test]
    public function get_role_assignment_unassigned_shape(): void
    {
        $user = User::factory()->create();

        $service = $this->app->make(RoleAssignmentService::class);
        $result = $service->describeAllRoles($user->id);

        $embedding = $result['embedding'];
        $this->assertEquals('embedding', $embedding['role']);
        $this->assertEquals('unassigned', $embedding['effective']['status']);
        $this->assertNull($embedding['effective']['scope']);
        $this->assertNull($embedding['effective']['server']);
        $this->assertNull($embedding['effective']['model']);
        $this->assertNull($embedding['effective']['reason']);
        $this->assertNull($embedding['user_assignment']);
        $this->assertNull($embedding['installation_assignment']);
    }

    #[Test]
    public function get_role_assignment_broken_shape(): void
    {
        $user = User::factory()->create();
        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Deleted Server']);
        $server->delete(); // Soft delete the server

        $service = $this->app->make(RoleAssignmentService::class);

        // Set an installation-scope assignment pointing at the deleted server.
        RoleAssignment::create([
            'role' => 'image',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $server->id,
            'model' => 'old-image-model',
        ]);

        $result = $service->describeAllRoles($user->id);

        $image = $result['image'];
        $this->assertEquals('image', $image['role']);
        $this->assertEquals('broken', $image['effective']['status']);
        $this->assertEquals('installation', $image['effective']['scope']);
        $this->assertNull($image['effective']['server']);
        $this->assertEquals('old-image-model', $image['effective']['model']);
        $this->assertEquals('server deleted', $image['effective']['reason']);
        // Installation assignment is still visible.
        $this->assertNotNull($image['installation_assignment']);
        $this->assertEquals($server->id, $image['installation_assignment']['server_id']);
        $this->assertEquals('old-image-model', $image['installation_assignment']['model']);
    }

    #[Test]
    public function get_role_assignment_returns_all_four_roles(): void
    {
        $user = User::factory()->create();

        $service = $this->app->make(RoleAssignmentService::class);
        $result = $service->describeAllRoles($user->id);

        $this->assertArrayHasKey('inference', $result);
        $this->assertArrayHasKey('embedding', $result);
        $this->assertArrayHasKey('image', $result);
        $this->assertArrayHasKey('judge', $result);
        $this->assertCount(4, $result);
    }

    /* -----------------------------------------------------------------
     * PUT /role-assignment — validation
     * ----------------------------------------------------------------- */

    #[Test]
    public function put_role_assignment_validates_server_exists(): void
    {
        $user = User::factory()->create();
        $validator = \Illuminate\Support\Facades\Validator::make(
            ['role' => 'inference', 'scope' => 'user', 'server_id' => (string) Str::uuid(), 'model' => 'gpt-4'],
            [
                'role' => ['required', \Illuminate\Validation\Rule::in(['inference', 'embedding', 'image'])],
                'scope' => ['required', \Illuminate\Validation\Rule::in(['user', 'installation'])],
                'server_id' => ['required', 'uuid', 'exists:llm_servers,id'],
                'model' => ['required', 'string'],
            ]
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('server_id', $validator->errors()->toArray());
    }

    #[Test]
    public function put_role_assignment_validates_role_value(): void
    {
        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Test Server']);
        $validator = \Illuminate\Support\Facades\Validator::make(
            ['role' => 'nonexistent_role', 'scope' => 'user', 'server_id' => $server->id, 'model' => 'gpt-4'],
            [
                'role' => ['required', \Illuminate\Validation\Rule::in(['inference', 'embedding', 'image'])],
                'scope' => ['required', \Illuminate\Validation\Rule::in(['user', 'installation'])],
                'server_id' => ['required', 'uuid', 'exists:llm_servers,id'],
                'model' => ['required', 'string'],
            ]
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('role', $validator->errors()->toArray());
    }

    #[Test]
    public function put_role_assignment_validates_scope_value(): void
    {
        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Test Server']);
        $validator = \Illuminate\Support\Facades\Validator::make(
            ['role' => 'embedding', 'scope' => 'admin', 'server_id' => $server->id, 'model' => 'text-embedding-3-small'],
            [
                'role' => ['required', \Illuminate\Validation\Rule::in(['inference', 'embedding', 'image'])],
                'scope' => ['required', \Illuminate\Validation\Rule::in(['user', 'installation'])],
                'server_id' => ['required', 'uuid', 'exists:llm_servers,id'],
                'model' => ['required', 'string'],
            ]
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('scope', $validator->errors()->toArray());
    }

    #[Test]
    public function put_role_assignment_validates_required_fields(): void
    {
        $validator = \Illuminate\Support\Facades\Validator::make(
            [],
            [
                'role' => ['required', \Illuminate\Validation\Rule::in(['inference', 'embedding', 'image'])],
                'scope' => ['required', \Illuminate\Validation\Rule::in(['user', 'installation'])],
                'server_id' => ['required', 'uuid', 'exists:llm_servers,id'],
                'model' => ['required', 'string'],
            ]
        );

        $this->assertTrue($validator->fails());
        $errors = $validator->errors()->toArray();
        $this->assertArrayHasKey('role', $errors);
        $this->assertArrayHasKey('scope', $errors);
        $this->assertArrayHasKey('server_id', $errors);
        $this->assertArrayHasKey('model', $errors);
    }

    #[Test]
    public function put_role_assignment_valid_passes_for_valid_input(): void
    {
        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Test Server']);
        $validator = \Illuminate\Support\Facades\Validator::make(
            ['role' => 'embedding', 'scope' => 'user', 'server_id' => $server->id, 'model' => 'text-embedding-3-small'],
            [
                'role' => ['required', \Illuminate\Validation\Rule::in(['inference', 'embedding', 'image'])],
                'scope' => ['required', \Illuminate\Validation\Rule::in(['user', 'installation'])],
                'server_id' => ['required', 'uuid', 'exists:llm_servers,id'],
                'model' => ['required', 'string'],
            ]
        );

        $this->assertFalse($validator->fails());
    }

    /* -----------------------------------------------------------------
     * PUT with scope=installation — any user can set installation defaults
     * ----------------------------------------------------------------- */

    #[Test]
    public function put_installation_scope_succeeds_for_regular_user(): void
    {
        $user = User::factory()->create();
        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Test Server']);

        $service = $this->app->make(RoleAssignmentService::class);

        // Any signed-in user can set installation-scope assignments.
        // No authorization check beyond auth:api (single-tenant trust model).
        $result = $service->set(
            ModelRole::Embedding,
            RoleAssignment::INSTALLATION_SCOPE_ID,
            $server->id,
            'text-embedding-3-small'
        );

        $this->assertInstanceOf(RoleAssignment::class, $result);
        $this->assertEquals('installation', $result->scope);
        $this->assertEquals(RoleAssignment::INSTALLATION_SCOPE_ID, $result->user_id);
        $this->assertEquals($server->id, $result->server_id);
        $this->assertEquals('text-embedding-3-small', $result->model);
    }

    /* -----------------------------------------------------------------
     * DELETE /role-assignment — returns fallen-back effective state
     * ----------------------------------------------------------------- */

    #[Test]
    public function delete_role_assignment_returns_fallen_back_effective_state(): void
    {
        $user = User::factory()->create();
        $userServer = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'User Server']);
        $installServer = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Install Server']);

        $service = $this->app->make(RoleAssignmentService::class);

        // Set both installation and user assignments for embedding.
        $service->set(ModelRole::Embedding, RoleAssignment::INSTALLATION_SCOPE_ID, $installServer->id, 'install-embed');
        $service->set(ModelRole::Embedding, $user->id, $userServer->id, 'user-embed');

        // Before clear: user assignment is effective.
        $before = $service->describeAllRoles($user->id);
        $this->assertEquals('resolved', $before['embedding']['effective']['status']);
        $this->assertEquals('user', $before['embedding']['effective']['scope']);
        $this->assertEquals('user-embed', $before['embedding']['effective']['model']);

        // Clear the user assignment.
        $service->clear(ModelRole::Embedding, $user->id);

        // After clear: falls back to installation assignment (same response shape).
        $after = $service->describeAllRoles($user->id);
        $this->assertEquals('resolved', $after['embedding']['effective']['status']);
        $this->assertEquals('installation', $after['embedding']['effective']['scope']);
        $this->assertEquals('install-embed', $after['embedding']['effective']['model']);
        $this->assertNull($after['embedding']['user_assignment']);
        $this->assertNotNull($after['embedding']['installation_assignment']);
    }

    #[Test]
    public function delete_role_assignment_with_no_fallback_returns_unassigned(): void
    {
        $user = User::factory()->create();
        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Test Server']);

        $service = $this->app->make(RoleAssignmentService::class);

        // Set only a user assignment (no installation assignment).
        $service->set(ModelRole::Image, $user->id, $server->id, 'dall-e-3');

        // Clear the user assignment.
        $service->clear(ModelRole::Image, $user->id);

        // After clear: unassigned (no installation assignment to fall back to).
        $after = $service->describeAllRoles($user->id);
        $this->assertEquals('unassigned', $after['image']['effective']['status']);
        $this->assertNull($after['image']['effective']['scope']);
        $this->assertNull($after['image']['effective']['model']);
        $this->assertNull($after['image']['user_assignment']);
        $this->assertNull($after['image']['installation_assignment']);
    }
}
