<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\UserSettingRoleBackfill;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * Regression guard for research.md D10: `UserSettingRoleBackfill::run()` reads
 * and writes exclusively through `DB::table()` and guards on `Schema::hasTable()`
 * first, so deleting the Eloquent `UserSetting` model (a later task in this
 * feature) cannot affect it. This test proves the backfill still works end to
 * end against seeded `llm_user_settings` rows, and separately asserts that the
 * `UserSetting` class is gone.
 *
 * That class-absence assertion is expected to FAIL until the later deletion
 * task runs — do not "fix" it here by deleting the class. Everything else in
 * this file should be green from the moment it's written.
 */
class UserSettingBackfillSurvivesRetirementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Create llm_user_settings table if it doesn't exist (for migration tests).
        if (!Schema::hasTable('llm_user_settings')) {
            Schema::create('llm_user_settings', function ($table) {
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

    #[Test]
    public function backfill_runs_end_to_end_through_db_table_only(): void
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
        ]);

        $result = (new UserSettingRoleBackfill())->run();

        $this->assertTrue($result['ran']);
        $this->assertEquals(1, $result['migrated']);
        $this->assertEquals(0, $result['skipped']);
        $this->assertEquals(0, $result['soft_deleted']);

        $row = DB::table('llm_role_assignments')
            ->where('user_id', $userId)
            ->where('role', 'inference')
            ->first();

        $this->assertNotNull($row);
        $this->assertEquals('inference', $row->role);
        $this->assertEquals($userId, $row->user_id);
        $this->assertEquals($server->id, $row->server_id);
        $this->assertEquals('gpt-4', $row->model);
    }

    /**
     * research.md D10: the backfill reads/writes exclusively through
     * `DB::table()`, so it does not depend on the `UserSetting` Eloquent
     * model at all. This asserts the model class is gone — proving the
     * retirement (a later task) is safe to do. Expected to FAIL right now,
     * since that later task hasn't run yet; do not delete the class here to
     * make it pass.
     */
    #[Test]
    public function user_setting_model_class_is_absent(): void
    {
        $this->assertFalse(
            class_exists(\ClarionApp\LlmClient\Models\UserSetting::class),
            'UserSetting model class should have been deleted once the backfill no longer needs it (research.md D10).'
        );
    }

    #[Test]
    public function backfill_run_is_reported_via_app_container_too(): void
    {
        $server = Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Server']);
        $userId = (string) Str::uuid();

        DB::table('llm_user_settings')->insert([
            'id' => $userId,
            'user_id' => $userId,
            'server_id' => $server->id,
            'model' => 'llama-3-70b',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(UserSettingRoleBackfill::class)->run();

        $this->assertTrue($result['ran']);
        $this->assertEquals(1, $result['migrated']);

        $count = DB::table('llm_role_assignments')
            ->where('user_id', $userId)
            ->where('role', 'inference')
            ->count();
        $this->assertEquals(1, $count);
    }
}
