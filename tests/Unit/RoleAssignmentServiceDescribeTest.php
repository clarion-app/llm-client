<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\After;

/**
 * Tests for RoleAssignmentService::describeAllRoles().
 * Covers the response shape, per-role effective status, and mixed-status scenarios.
 */
class RoleAssignmentServiceDescribeTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::table('llm_role_assignments')->delete();
        DB::table('language_models')->delete();
        DB::table('llm_servers')->delete();
        parent::tearDown();
    }

    // ========== describeAllRoles returns entries for all four roles ==========

    #[Test]
    public function describe_all_roles_returns_entries_for_all_four_roles(): void
    {
        $service = $this->app->make(RoleAssignmentService::class);
        $userId = (string) Str::uuid();

        $result = $service->describeAllRoles($userId);

        $this->assertArrayHasKey('inference', $result);
        $this->assertArrayHasKey('embedding', $result);
        $this->assertArrayHasKey('image', $result);
        $this->assertArrayHasKey('judge', $result);
        $this->assertCount(4, $result);
    }

    // ========== Per-role effective reflects resolver status ==========

    #[Test]
    public function resolved_role_has_correct_effective_shape(): void
    {
        $service = $this->app->make(RoleAssignmentService::class);
        $userId = (string) Str::uuid();

        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Test Server']);

        RoleAssignment::create([
            'role' => 'inference',
            'user_id' => $userId,
            'server_id' => $server->id,
            'model' => 'gpt-4',
        ]);

        $result = $service->describeAllRoles($userId);

        $effective = $result['inference']['effective'];
        $this->assertEquals('resolved', $effective['status']);
        $this->assertEquals('user', $effective['scope']);
        $this->assertNotNull($effective['server']);
        $this->assertEquals($server->id, $effective['server']['id']);
        $this->assertEquals('gpt-4', $effective['model']);
        $this->assertNull($effective['reason']);
    }

    #[Test]
    public function unassigned_role_has_correct_effective_shape(): void
    {
        $service = $this->app->make(RoleAssignmentService::class);
        $userId = (string) Str::uuid();

        $result = $service->describeAllRoles($userId);

        $effective = $result['embedding']['effective'];
        $this->assertEquals('unassigned', $effective['status']);
        $this->assertNull($effective['scope']);
        $this->assertNull($effective['server']);
        $this->assertNull($effective['model']);
        $this->assertNull($effective['reason']);
    }

    #[Test]
    public function broken_role_has_correct_effective_shape(): void
    {
        $service = $this->app->make(RoleAssignmentService::class);
        $userId = (string) Str::uuid();

        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Deleted Server']);

        RoleAssignment::create([
            'role' => 'image',
            'user_id' => $userId,
            'server_id' => $server->id,
            'model' => 'dall-e-3',
        ]);

        // Soft delete the server to make the assignment broken
        $server->delete();

        $result = $service->describeAllRoles($userId);

        $effective = $result['image']['effective'];
        $this->assertEquals('broken', $effective['status']);
        $this->assertNotNull($effective['reason']);
        $this->assertEquals('dall-e-3', $effective['model']);
    }

    // ========== user_assignment and installation_assignment raw rows ==========

    #[Test]
    public function user_assignment_populated_when_user_has_assignment(): void
    {
        $service = $this->app->make(RoleAssignmentService::class);
        $userId = (string) Str::uuid();

        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Test Server']);

        RoleAssignment::create([
            'role' => 'embedding',
            'user_id' => $userId,
            'server_id' => $server->id,
            'model' => 'text-embedding-3-small',
        ]);

        $result = $service->describeAllRoles($userId);

        $userAssignment = $result['embedding']['user_assignment'];
        $this->assertNotNull($userAssignment);
        $this->assertEquals($server->id, $userAssignment['server_id']);
        $this->assertEquals('text-embedding-3-small', $userAssignment['model']);

        // No installation assignment for this role
        $installAssignment = $result['embedding']['installation_assignment'];
        $this->assertNull($installAssignment);
    }

    #[Test]
    public function installation_assignment_populated_when_only_installation_exists(): void
    {
        $service = $this->app->make(RoleAssignmentService::class);
        $userId = (string) Str::uuid();

        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Install Server']);

        RoleAssignment::create([
            'role' => 'inference',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $server->id,
            'model' => 'gpt-4',
        ]);

        $result = $service->describeAllRoles($userId);

        $installAssignment = $result['inference']['installation_assignment'];
        $this->assertNotNull($installAssignment);
        $this->assertEquals($server->id, $installAssignment['server_id']);
        $this->assertEquals('gpt-4', $installAssignment['model']);

        // No user assignment for this user/role
        $userAssignment = $result['inference']['user_assignment'];
        $this->assertNull($userAssignment);
    }

    #[Test]
    public function both_assignments_null_when_nothing_exists(): void
    {
        $service = $this->app->make(RoleAssignmentService::class);
        $userId = (string) Str::uuid();

        $result = $service->describeAllRoles($userId);

        $this->assertNull($result['image']['user_assignment']);
        $this->assertNull($result['image']['installation_assignment']);
    }

    // ========== Mixed-status scenario ==========

    #[Test]
    public function mixed_status_returns_correct_shape_for_each_role(): void
    {
        $service = $this->app->make(RoleAssignmentService::class);
        $userId = (string) Str::uuid();

        // Resolved: user has a valid inference assignment
        $goodServer = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Good Server']);
        RoleAssignment::create([
            'role' => 'inference',
            'user_id' => $userId,
            'server_id' => $goodServer->id,
            'model' => 'gpt-4',
        ]);

        // Broken: installation has an embedding assignment on a deleted server
        $deletedServer = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Bad Server']);
        RoleAssignment::create([
            'role' => 'embedding',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $deletedServer->id,
            'model' => 'old-embed-model',
        ]);
        $deletedServer->delete();

        // Unassigned: no image assignment at any scope
        // (nothing to set up)

        $result = $service->describeAllRoles($userId);

        // Inference: resolved at user scope
        $this->assertEquals('resolved', $result['inference']['effective']['status']);
        $this->assertEquals('user', $result['inference']['effective']['scope']);
        $this->assertNotNull($result['inference']['user_assignment']);
        $this->assertNull($result['inference']['installation_assignment']);

        // Embedding: broken at installation scope
        $this->assertEquals('broken', $result['embedding']['effective']['status']);
        $this->assertEquals('installation', $result['embedding']['effective']['scope']);
        $this->assertNotNull($result['embedding']['effective']['reason']);
        $this->assertNull($result['embedding']['user_assignment']);
        $this->assertNotNull($result['embedding']['installation_assignment']);

        // Image: unassigned
        $this->assertEquals('unassigned', $result['image']['effective']['status']);
        $this->assertNull($result['image']['effective']['scope']);
        $this->assertNull($result['image']['user_assignment']);
        $this->assertNull($result['image']['installation_assignment']);
    }

    // ========== Role key in response ==========

    #[Test]
    public function each_entry_contains_role_key_matching_key(): void
    {
        $service = $this->app->make(RoleAssignmentService::class);
        $userId = (string) Str::uuid();

        $result = $service->describeAllRoles($userId);

        $this->assertEquals('inference', $result['inference']['role']);
        $this->assertEquals('embedding', $result['embedding']['role']);
        $this->assertEquals('image', $result['image']['role']);
    }
}
