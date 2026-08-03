<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\After;

/**
 * Tests for RoleAssignmentService set/clear write paths.
 * Extends TestCase for database access.
 */
class RoleAssignmentServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::table('llm_role_assignments')->delete();
        parent::tearDown();
    }

    #[Test]
    public function set_creates_new_row(): void
    {
        $service = $this->app->make(RoleAssignmentService::class);
        $userId = (string) Str::uuid();
        $serverId = (string) Str::uuid();

        $result = $service->set(ModelRole::Inference, $userId, $serverId, 'gpt-4');

        $this->assertInstanceOf(RoleAssignment::class, $result);
        // getAttributeValue('role') goes through the accessor and returns ModelRole enum.
        // Check the raw stored attribute instead.
        $this->assertEquals('inference', $result->getAttributes()['role']);
        $this->assertEquals($userId, $result->user_id);
        $this->assertEquals($serverId, $result->server_id);
        $this->assertEquals('gpt-4', $result->model);
        $this->assertNotNull($result->id);

        $count = DB::table('llm_role_assignments')->where('role', 'inference')->where('user_id', $userId)->count();
        $this->assertEquals(1, $count);
    }

    #[Test]
    public function set_on_existing_live_row_updates_in_place(): void
    {
        $service = $this->app->make(RoleAssignmentService::class);
        $userId = (string) Str::uuid();
        $serverId1 = (string) Str::uuid();
        $serverId2 = (string) Str::uuid();

        $original = $service->set(ModelRole::Embedding, $userId, $serverId1, 'text-embedding-ada');
        $originalId = $original->id;

        $updated = $service->set(ModelRole::Embedding, $userId, $serverId2, 'text-embedding-3-small');

        // Same row updated, not a new row created
        $this->assertEquals($originalId, $updated->id);
        $this->assertEquals($serverId2, $updated->server_id);
        $this->assertEquals('text-embedding-3-small', $updated->model);

        $count = DB::table('llm_role_assignments')->where('role', 'embedding')->where('user_id', $userId)->count();
        $this->assertEquals(1, $count);
    }

    #[Test]
    public function set_after_clear_restores_trashed_row(): void
    {
        $service = $this->app->make(RoleAssignmentService::class);
        $userId = (string) Str::uuid();
        $serverId1 = (string) Str::uuid();
        $serverId2 = (string) Str::uuid();

        $original = $service->set(ModelRole::Image, $userId, $serverId1, 'dall-e-3');
        $originalId = $original->id;

        // Clear the assignment (soft delete)
        $service->clear(ModelRole::Image, $userId);

        $trashedRow = RoleAssignment::withTrashed()
            ->where('id', $originalId)
            ->first();
        $this->assertNotNull($trashedRow->deleted_at);

        // Re-set — should restore, not throw unique-constraint violation
        $restored = $service->set(ModelRole::Image, $userId, $serverId2, 'flux-1');

        $this->assertEquals($originalId, $restored->id);
        $this->assertEquals($serverId2, $restored->server_id);
        $this->assertEquals('flux-1', $restored->model);
        $this->assertNull($restored->deleted_at);
    }

    #[Test]
    public function clear_on_nothing_is_noop(): void
    {
        $service = $this->app->make(RoleAssignmentService::class);
        $userId = (string) Str::uuid();

        // Should not throw — no assignment exists
        $service->clear(ModelRole::Inference, $userId);

        $count = DB::table('llm_role_assignments')->count();
        $this->assertEquals(0, $count);
    }

    #[Test]
    public function clear_actually_soft_deletes(): void
    {
        $service = $this->app->make(RoleAssignmentService::class);
        $userId = (string) Str::uuid();
        $serverId = (string) Str::uuid();

        $assignment = $service->set(ModelRole::Inference, $userId, $serverId, 'gpt-4');
        $service->clear(ModelRole::Inference, $userId);

        // Row still exists but is soft-deleted
        $trashedRow = RoleAssignment::withTrashed()
            ->where('id', $assignment->id)
            ->first();
        $this->assertNotNull($trashedRow);
        $this->assertNotNull($trashedRow->deleted_at);

        // Normal query (without withTrashed) should find nothing
        $liveRow = RoleAssignment::where('id', $assignment->id)->first();
        $this->assertNull($liveRow);
    }

    #[Test]
    public function unique_constraint_rejects_second_live_installation_row_for_same_role(): void
    {
        $serverId1 = (string) Str::uuid();
        $serverId2 = (string) Str::uuid();

        // Create the first installation-scope row directly (bypassing the service)
        RoleAssignment::create([
            'role' => 'inference',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $serverId1,
            'model' => 'gpt-4',
        ]);

        // Attempting to create a second live row for the same (role, user_id)
        // should fail with a unique constraint violation
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        RoleAssignment::create([
            'role' => 'inference',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $serverId2,
            'model' => 'claude-3',
        ]);
    }
}
