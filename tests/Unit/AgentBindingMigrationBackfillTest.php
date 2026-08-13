<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Mutation-checklist row 10 (090-agent-version-binding, quickstart.md):
 * 2026_08_13_000000_add_agent_binding_to_conversations_table.php must leave
 * agent_id/agent_version_id null on rows that existed before it ran — no
 * backfill (research.md D10).
 *
 * Every other test in this suite either (a) never runs this migration at
 * all — Constitution §V's hand-declared-schema convention, TestCase.php's
 * own conversations block already declares agent_id/agent_version_id
 * directly, so Schema::hasColumn() short-circuits the migration's own body
 * — or (b) uses RefreshDatabase, which runs every migration before any
 * fixture exists, so there is never a "pre-existing" row for a migration to
 * find. Neither shape can observe backfill behavior. This test drives the
 * real, shipped migration file directly (mirroring
 * MigrateUserSettingsToRoleAssignmentsTest's own established "run the
 * shipped migration, not a re-implementation" pattern) against a
 * purpose-built pre-migration table shape, so it is the one place in the
 * suite that actually exercises this migration's own up().
 */
class AgentBindingMigrationBackfillTest extends TestCase
{
    protected function tearDown(): void
    {
        // Restore the shared conversations table to the exact shape
        // TestCase::defineDatabaseMigrations() declares, so later tests in
        // the same process see the schema they expect.
        Schema::dropIfExists('conversations');
        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('server_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('user_id')->nullable();
            $table->string('title')->nullable();
            $table->string('model')->nullable();
            $table->string('character')->nullable();
            $table->string('channel')->nullable();
            $table->uuid('agent_id')->nullable();
            $table->uuid('agent_version_id')->nullable();
            $table->string('provider_override')->nullable();
            $table->boolean('is_processing')->default(false);
            $table->timestamp('ended_at')->nullable();
            $table->index('user_id');
            $table->index('agent_id');
            $table->index('agent_version_id');
        });

        parent::tearDown();
    }

    #[Test]
    public function the_migration_leaves_pre_existing_rows_agent_binding_columns_null(): void
    {
        // Recreate 'conversations' matching its exact pre-migration shape —
        // every column TestCase.php declares except agent_id/agent_version_id
        // (and their indexes) — so Schema::hasColumn('conversations',
        // 'agent_id') is false and the migration's own guarded body actually
        // runs, exactly as it would against a real, un-migrated database.
        Schema::dropIfExists('conversations');
        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('server_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('user_id')->nullable();
            $table->string('title')->nullable();
            $table->string('model')->nullable();
            $table->string('character')->nullable();
            $table->string('channel')->nullable();
            $table->string('provider_override')->nullable();
            $table->boolean('is_processing')->default(false);
            $table->timestamp('ended_at')->nullable();
            $table->index('user_id');
        });

        $this->assertFalse(Schema::hasColumn('conversations', 'agent_id'), 'the pre-migration fixture must not already carry the columns under test');

        $preExistingId = (string) Str::uuid();
        DB::table('conversations')->insert([
            'id' => $preExistingId,
            'title' => 'Pre-existing conversation',
            'character' => 'Clarion',
            'is_processing' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Run the *shipped* migration, not a copy of its logic.
        $migration = require __DIR__ . '/../../src/Migrations/2026_08_13_000000_add_agent_binding_to_conversations_table.php';
        $migration->up();

        $this->assertTrue(Schema::hasColumn('conversations', 'agent_id'));
        $this->assertTrue(Schema::hasColumn('conversations', 'agent_version_id'));

        $row = DB::table('conversations')->where('id', $preExistingId)->first();
        $this->assertNotNull($row, 'the pre-existing row must survive the migration unchanged in every other respect');
        $this->assertNull($row->agent_id, 'a pre-existing row must not be backfilled with an agent_id (research.md D10)');
        $this->assertNull($row->agent_version_id, 'a pre-existing row must not be backfilled with an agent_version_id (research.md D10)');
    }
}
