<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\RoleResolver;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\After;

/**
 * Tests for the MigrateUserSettingsToRoleAssignments command logic.
 *
 * Covers the migration from llm_user_settings to llm_role_assignments:
 * - User with both server_id/model set gets one inference row.
 * - User with both null gets no row (FR-020).
 * - User with model string matching no language_models row gets a row
 *   (research.md D1/D9), and conversation for that user resolves to
 *   the same server/model unchanged (FR-021).
 */
class MigrateUserSettingsToRoleAssignmentsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Create llm_user_settings table if it doesn't exist (for migration tests).
        if (!\Illuminate\Support\Facades\Schema::hasTable('llm_user_settings')) {
            \Illuminate\Support\Facades\Schema::create('llm_user_settings', function ($table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id')->unique();
                $table->uuid('server_id')->nullable();
                $table->string('model')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    protected function tearDown(): void
    {
        DB::table('llm_role_assignments')->delete();
        DB::table('llm_user_settings')->delete();
        DB::table('language_models')->delete();
        DB::table('llm_servers')->delete();
        parent::tearDown();
    }

    /**
     * Simulate the migration logic from llm_user_settings to llm_role_assignments.
     * This mirrors the data-only migration in research.md D9.
     */
    private function runMigration(): void
    {
        DB::table('llm_user_settings')->whereNull('deleted_at')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                if ($row->server_id === null || $row->model === null) {
                    continue; // FR-020: no placeholder for an empty setting
                }
                DB::table('llm_role_assignments')->insert([
                    'id' => (string) Str::uuid(),
                    'role' => 'inference',
                    'user_id' => $row->user_id,
                    'server_id' => $row->server_id,
                    'model' => $row->model,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    /* -----------------------------------------------------------------
     * Three-row migration scenario
     * ----------------------------------------------------------------- */

    #[Test]
    public function user_with_both_server_and_model_gets_one_inference_row(): void
    {
        // Create three users and servers.
        $server1 = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Server 1']);
        $server2 = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Server 2']);
        $server3 = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Server 3']);

        $userId1 = (string) Str::uuid();
        $userId2 = (string) Str::uuid();
        $userId3 = (string) Str::uuid();

        // User 1: both server_id and model set.
        DB::table('llm_user_settings')->insert([
            'id' => $userId1,
            'user_id' => $userId1,
            'server_id' => $server1->id,
            'model' => 'gpt-4',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // User 2: both null (no saved default).
        DB::table('llm_user_settings')->insert([
            'id' => $userId2,
            'user_id' => $userId2,
            'server_id' => null,
            'model' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // User 3: both server_id and model set.
        DB::table('llm_user_settings')->insert([
            'id' => $userId3,
            'user_id' => $userId3,
            'server_id' => $server3->id,
            'model' => 'llama-3-70b',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Run migration.
        $this->runMigration();

        // User 1: should have one inference row.
        $count1 = DB::table('llm_role_assignments')
            ->where('user_id', $userId1)
            ->where('role', 'inference')
            ->count();
        $this->assertEquals(1, $count1);

        $row1 = DB::table('llm_role_assignments')
            ->where('user_id', $userId1)
            ->where('role', 'inference')
            ->first();
        $this->assertEquals($server1->id, $row1->server_id);
        $this->assertEquals('gpt-4', $row1->model);

        // User 2: should have NO row (FR-020).
        $count2 = DB::table('llm_role_assignments')
            ->where('user_id', $userId2)
            ->count();
        $this->assertEquals(0, $count2);

        // User 3: should have one inference row.
        $count3 = DB::table('llm_role_assignments')
            ->where('user_id', $userId3)
            ->where('role', 'inference')
            ->count();
        $this->assertEquals(1, $count3);

        $row3 = DB::table('llm_role_assignments')
            ->where('user_id', $userId3)
            ->where('role', 'inference')
            ->first();
        $this->assertEquals($server3->id, $row3->server_id);
        $this->assertEquals('llama-3-70b', $row3->model);
    }

    /* -----------------------------------------------------------------
     * User with model string matching no language_models row
     * ----------------------------------------------------------------- */

    #[Test]
    public function user_with_model_not_in_language_models_gets_row_and_resolves(): void
    {
        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Server']);
        $userId = (string) Str::uuid();

        // User has a model string that doesn't match any language_models row.
        // This is valid per research.md D1 — the model string is trusted.
        DB::table('llm_user_settings')->insert([
            'id' => $userId,
            'user_id' => $userId,
            'server_id' => $server->id,
            'model' => 'some-undiscovered-model',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Run migration.
        $this->runMigration();

        // The row should be created (research.md D1/D9).
        $count = DB::table('llm_role_assignments')
            ->where('user_id', $userId)
            ->where('role', 'inference')
            ->count();
        $this->assertEquals(1, $count);

        // Resolution should succeed (the model string is trusted even if
        // it's not in language_models — research.md D2 asymmetry).
        $resolver = $this->app->make(RoleResolver::class);
        $resolution = $resolver->resolve(ModelRole::Inference, $userId);

        $this->assertTrue($resolution->hasEffectiveModel());
        $this->assertEquals($server->id, $resolution->server->id);
        $this->assertEquals('some-undiscovered-model', $resolution->model);
    }

    /* -----------------------------------------------------------------
     * Conversation for migrated user resolves to same server/model
     * ----------------------------------------------------------------- */

    #[Test]
    public function conversation_resolves_to_same_server_model_after_migration(): void
    {
        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Server']);
        $userId = (string) Str::uuid();

        // Original user setting.
        $originalServerId = $server->id;
        $originalModel = 'gpt-4-32k';

        DB::table('llm_user_settings')->insert([
            'id' => $userId,
            'user_id' => $userId,
            'server_id' => $originalServerId,
            'model' => $originalModel,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Run migration.
        $this->runMigration();

        // FR-021: no user's effective conversational model differs from before.
        $resolver = $this->app->make(RoleResolver::class);
        $resolution = $resolver->resolve(ModelRole::Inference, $userId);

        $this->assertTrue($resolution->hasEffectiveModel());
        $this->assertEquals($originalServerId, $resolution->server->id);
        $this->assertEquals($originalModel, $resolution->model);
    }

    /* -----------------------------------------------------------------
     * Edge cases
     * ----------------------------------------------------------------- */

    #[Test]
    public function user_with_only_server_id_no_model_gets_no_row(): void
    {
        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Server']);
        $userId = (string) Str::uuid();

        DB::table('llm_user_settings')->insert([
            'id' => $userId,
            'user_id' => $userId,
            'server_id' => $server->id,
            'model' => null, // Model is null — skip per FR-020
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runMigration();

        $count = DB::table('llm_role_assignments')
            ->where('user_id', $userId)
            ->count();
        $this->assertEquals(0, $count);
    }

    #[Test]
    public function user_with_only_model_no_server_id_gets_no_row(): void
    {
        $userId = (string) Str::uuid();

        DB::table('llm_user_settings')->insert([
            'id' => $userId,
            'user_id' => $userId,
            'server_id' => null, // Server is null — skip per FR-020
            'model' => 'gpt-4',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runMigration();

        $count = DB::table('llm_role_assignments')
            ->where('user_id', $userId)
            ->count();
        $this->assertEquals(0, $count);
    }

    #[Test]
    public function soft_deleted_user_setting_is_not_migrated(): void
    {
        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Server']);
        $userId = (string) Str::uuid();

        DB::table('llm_user_settings')->insert([
            'id' => $userId,
            'user_id' => $userId,
            'server_id' => $server->id,
            'model' => 'gpt-4',
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => now(), // Soft deleted — should be skipped
        ]);

        $this->runMigration();

        $count = DB::table('llm_role_assignments')
            ->where('user_id', $userId)
            ->count();
        $this->assertEquals(0, $count);
    }
}
