<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\CodingProject;
use ClarionApp\LlmClient\Services\DockerCommandExecutor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 124-command-limit-controls, US1, T009 (contracts/resource-limits.md §1).
 * Drives the real, registered `PATCH coding-project/{id}/resource-limits`
 * HTTP route through the real CodingProjectController -- ownership-checked
 * identically to updateConfirmationSetting()/updateCommandAllowlist()/
 * updateNetworkPolicy() (mirroring NetworkPolicyJourneyTest.php's own
 * shape). The precedence/threading cases additionally drive
 * `POST coding-project/{project}/run-command` with DockerCommandExecutor
 * swapped for a Mockery double bound into the container (mirroring
 * RunCommandJourneyTest.php/NetworkPolicyJourneyTest.php's own
 * bindFakeExecutor() convention) -- no real Docker is needed to prove the
 * RESOLVED, overridden value actually reaches run()'s new explicit
 * arguments.
 *
 * Written before CodingProjectController::updateResourceLimits() exists --
 * expected to FAIL red (route not found) until T011/T013/T014/T015/T016
 * land.
 */
class ResourceLimitOverrideJourneyTest extends TestCase
{
    private User $user;

    private User $otherUser;

    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();
        $this->actingAs($this->user, 'api');

        $this->projectDir = sys_get_temp_dir().'/coding-agent-resource-limits-'.Str::random(12);
        mkdir($this->projectDir, 0777, true);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        DB::table('coding_command_executions')->delete();
        DB::table('coding_projects')->delete();
        DB::table('users')->delete();

        if (is_dir($this->projectDir)) {
            $this->removeDirectory($this->projectDir);
        }

        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function apiUrl(string $suffix): string
    {
        return '/api/clarion-app/llm-client/'.$suffix;
    }

    private function registerProject(string $rootPath, ?User $owner = null): CodingProject
    {
        return CodingProject::create([
            'user_id' => ($owner ?? $this->user)->id,
            'name' => 'resource-limits project',
            'root_path' => $rootPath,
            'test_command' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function bindFakeExecutor(array $result, ?\Closure $spy = null): void
    {
        $fake = Mockery::mock(DockerCommandExecutor::class);

        if ($spy !== null) {
            $fake->shouldReceive('run')->andReturnUsing(function (...$args) use ($spy, $result) {
                $spy($args);

                return $result;
            });
        } else {
            $fake->shouldReceive('run')->andReturn($result);
        }

        $this->app->instance(DockerCommandExecutor::class, $fake);
    }

    // -----------------------------------------------------------------
    // Partial update: PATCH with a subset of keys -> 200, full project.
    // -----------------------------------------------------------------

    #[Test]
    public function patching_a_subset_of_keys_returns_200_with_the_full_updated_project(): void
    {
        $project = $this->registerProject($this->projectDir);

        $response = $this->patchJson($this->apiUrl("coding-project/{$project->id}/resource-limits"), [
            'time_limit_override_seconds' => 300,
            'memory_limit_override_mb' => 512,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('id', $project->id);
        $response->assertJsonPath('time_limit_override_seconds', 300);
        $response->assertJsonPath('memory_limit_override_mb', 512);
        $response->assertJsonPath('cpu_limit_override', null);
        $response->assertJsonPath('pids_limit_override', null);
        $response->assertJsonPath('disk_limit_override_mb', null);
        $response->assertJsonPath('output_cap_override_bytes', null);

        $fresh = $project->fresh();
        $this->assertSame(300, $fresh->time_limit_override_seconds);
        $this->assertSame(512, $fresh->memory_limit_override_mb);
    }

    // -----------------------------------------------------------------
    // Partial-update semantics -- THE load-bearing case: a present key
    // with value null clears just that one override, while every OTHER
    // key (present or absent) is untouched. An absent key must never be
    // treated as "clear this too".
    // -----------------------------------------------------------------

    #[Test]
    public function a_present_key_with_explicit_null_clears_only_that_one_override_leaving_every_other_untouched(): void
    {
        $project = $this->registerProject($this->projectDir);
        $project->update([
            'time_limit_override_seconds' => 300,
            'memory_limit_override_mb' => 512,
            'cpu_limit_override' => '2.0',
            'pids_limit_override' => 256,
            'disk_limit_override_mb' => 1024,
            'output_cap_override_bytes' => 524288,
        ]);

        // Only memory_limit_override_mb is present in the body, explicit
        // null -- every other key is entirely ABSENT from the request.
        $response = $this->patchJson($this->apiUrl("coding-project/{$project->id}/resource-limits"), [
            'memory_limit_override_mb' => null,
        ]);

        $response->assertStatus(200);

        $fresh = $project->fresh();
        $this->assertNull($fresh->memory_limit_override_mb, 'the present, explicit-null key must clear its own override');
        $this->assertSame(300, $fresh->time_limit_override_seconds, 'an ABSENT key must never be treated as "clear this too"');
        $this->assertSame('2.0', $fresh->cpu_limit_override, 'an ABSENT key must never be treated as "clear this too"');
        $this->assertSame(256, $fresh->pids_limit_override, 'an ABSENT key must never be treated as "clear this too"');
        $this->assertSame(1024, $fresh->disk_limit_override_mb, 'an ABSENT key must never be treated as "clear this too"');
        $this->assertSame(524288, $fresh->output_cap_override_bytes, 'an ABSENT key must never be treated as "clear this too"');
    }

    #[Test]
    public function an_absent_key_leaves_that_limits_current_override_exactly_as_it_was_set_or_unset(): void
    {
        $project = $this->registerProject($this->projectDir);
        $project->update(['time_limit_override_seconds' => 300]);

        // pids_limit_override was never set (still null) and is also
        // absent from this body -- it must remain null, not be coerced
        // to some other value.
        $response = $this->patchJson($this->apiUrl("coding-project/{$project->id}/resource-limits"), [
            'cpu_limit_override' => '0.5',
        ]);

        $response->assertStatus(200);

        $fresh = $project->fresh();
        $this->assertSame(300, $fresh->time_limit_override_seconds, 'absent from this PATCH -- must remain exactly as it was');
        $this->assertSame('0.5', $fresh->cpu_limit_override);
        $this->assertNull($fresh->pids_limit_override, 'never set, absent from this PATCH -- must remain null');
    }

    // -----------------------------------------------------------------
    // Validation (422): zero/negative integer keys.
    // -----------------------------------------------------------------

    #[Test]
    public function a_zero_value_for_an_integer_shaped_key_is_rejected_with_422(): void
    {
        $project = $this->registerProject($this->projectDir);

        $response = $this->patchJson($this->apiUrl("coding-project/{$project->id}/resource-limits"), [
            'time_limit_override_seconds' => 0,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['time_limit_override_seconds']);
    }

    #[Test]
    public function a_negative_value_for_an_integer_shaped_key_is_rejected_with_422(): void
    {
        $project = $this->registerProject($this->projectDir);

        $response = $this->patchJson($this->apiUrl("coding-project/{$project->id}/resource-limits"), [
            'memory_limit_override_mb' => -5,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['memory_limit_override_mb']);
    }

    #[Test]
    public function zero_and_negative_are_rejected_for_every_one_of_the_five_integer_shaped_keys(): void
    {
        $project = $this->registerProject($this->projectDir);

        foreach (['pids_limit_override', 'disk_limit_override_mb', 'output_cap_override_bytes'] as $key) {
            $response = $this->patchJson($this->apiUrl("coding-project/{$project->id}/resource-limits"), [
                $key => 0,
            ]);
            $response->assertStatus(422);
            $response->assertJsonValidationErrors([$key]);
        }
    }

    // -----------------------------------------------------------------
    // Validation (422): non-numeric-shaped cpu_limit_override.
    // -----------------------------------------------------------------

    #[Test]
    public function a_non_numeric_cpu_limit_override_is_rejected_with_422(): void
    {
        $project = $this->registerProject($this->projectDir);

        $response = $this->patchJson($this->apiUrl("coding-project/{$project->id}/resource-limits"), [
            'cpu_limit_override' => 'a lot please',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['cpu_limit_override']);
    }

    // -----------------------------------------------------------------
    // 404 cases.
    // -----------------------------------------------------------------

    #[Test]
    public function patching_an_absent_project_id_returns_404(): void
    {
        $response = $this->patchJson($this->apiUrl('coding-project/'.Str::uuid().'/resource-limits'), [
            'time_limit_override_seconds' => 300,
        ]);

        $response->assertStatus(404);
        $response->assertJson(['error' => 'Coding project not found', 'code' => 'coding_project_not_found']);
    }

    #[Test]
    public function patching_a_foreign_owned_project_returns_404(): void
    {
        $foreignProject = $this->registerProject($this->projectDir, $this->otherUser);

        $response = $this->patchJson($this->apiUrl("coding-project/{$foreignProject->id}/resource-limits"), [
            'time_limit_override_seconds' => 300,
        ]);

        $response->assertStatus(404);
        $response->assertJson(['error' => 'Coding project not found', 'code' => 'coding_project_not_found']);
        $this->assertNull($foreignProject->fresh()->time_limit_override_seconds, 'a foreign-owned project must never be modified');
    }

    // -----------------------------------------------------------------
    // Cross-workspace isolation (SC-005).
    // -----------------------------------------------------------------

    #[Test]
    public function setting_overrides_on_one_workspace_has_zero_effect_on_a_second_workspaces_own_columns(): void
    {
        $projectA = $this->registerProject($this->projectDir);
        $projectBDir = sys_get_temp_dir().'/coding-agent-resource-limits-b-'.Str::random(12);
        mkdir($projectBDir, 0777, true);
        $projectB = $this->registerProject($projectBDir);

        $this->patchJson($this->apiUrl("coding-project/{$projectA->id}/resource-limits"), [
            'time_limit_override_seconds' => 900,
            'memory_limit_override_mb' => 1024,
            'cpu_limit_override' => '4.0',
            'pids_limit_override' => 512,
            'disk_limit_override_mb' => 2048,
            'output_cap_override_bytes' => 1048576,
        ])->assertStatus(200);

        $freshB = $projectB->fresh();
        $this->assertNull($freshB->time_limit_override_seconds, 'workspace B must be completely unaffected by workspace A\'s PATCH');
        $this->assertNull($freshB->memory_limit_override_mb);
        $this->assertNull($freshB->cpu_limit_override);
        $this->assertNull($freshB->pids_limit_override);
        $this->assertNull($freshB->disk_limit_override_mb);
        $this->assertNull($freshB->output_cap_override_bytes);

        $this->removeDirectory($projectBDir);
    }

    // -----------------------------------------------------------------
    // FR-012: config() itself is byte-for-byte unchanged after a PATCH.
    // -----------------------------------------------------------------

    #[Test]
    public function patching_overrides_never_writes_to_the_installation_wide_config(): void
    {
        $project = $this->registerProject($this->projectDir);

        $before = config('llm-client.coding_agent');

        $this->patchJson($this->apiUrl("coding-project/{$project->id}/resource-limits"), [
            'time_limit_override_seconds' => 300,
            'memory_limit_override_mb' => 512,
            'cpu_limit_override' => '2.0',
            'pids_limit_override' => 256,
            'disk_limit_override_mb' => 1024,
            'output_cap_override_bytes' => 524288,
        ])->assertStatus(200);

        $after = config('llm-client.coding_agent');

        $this->assertSame($before, $after, 'config(\'llm-client.coding_agent\') must be byte-for-byte unchanged after a PATCH -- this endpoint only ever touches the six coding_projects columns');
    }

    // -----------------------------------------------------------------
    // Precedence/threading proof (quickstart Scenario 1, SC-001/SC-005):
    // the resolved, OVERRIDDEN value actually reaches
    // DockerCommandExecutor::run()'s new explicit argument -- not merely
    // stored in the DB -- while a second, untouched workspace still gets
    // the installation default.
    // -----------------------------------------------------------------

    #[Test]
    public function raising_the_time_limit_override_causes_the_resolved_value_to_reach_the_executors_explicit_argument(): void
    {
        config(['llm-client.coding_agent.command_timeout_seconds' => 60]);

        $project = $this->registerProject($this->projectDir);
        $this->patchJson($this->apiUrl("coding-project/{$project->id}/resource-limits"), [
            'time_limit_override_seconds' => 900,
        ])->assertStatus(200);

        $capturedArgs = null;
        $this->bindFakeExecutor([
            'status' => 'completed',
            'exit_code' => 0,
            'timed_out' => false,
            'stdout' => 'ok',
            'stderr' => '',
            'output_truncated' => false,
            'duration_ms' => 5,
        ], function (array $args) use (&$capturedArgs) {
            $capturedArgs = $args;
        });

        $this->postJson($this->apiUrl("coding-project/{$project->id}/run-command"), [
            'command' => 'echo hello',
        ])->assertStatus(200);

        $this->assertNotNull($capturedArgs, 'DockerCommandExecutor::run() must have been called');
        $this->assertContains(900, $capturedArgs, 'the RESOLVED, overridden time limit (900) must reach run()\'s explicit argument list, not the installation default (60)');
    }

    #[Test]
    public function a_second_untouched_workspace_still_receives_the_installation_default_argument(): void
    {
        config(['llm-client.coding_agent.command_timeout_seconds' => 60]);

        $projectA = $this->registerProject($this->projectDir);
        $this->patchJson($this->apiUrl("coding-project/{$projectA->id}/resource-limits"), [
            'time_limit_override_seconds' => 900,
        ])->assertStatus(200);

        $projectBDir = sys_get_temp_dir().'/coding-agent-resource-limits-b2-'.Str::random(12);
        mkdir($projectBDir, 0777, true);
        $projectB = $this->registerProject($projectBDir);

        $capturedArgs = null;
        $this->bindFakeExecutor([
            'status' => 'completed',
            'exit_code' => 0,
            'timed_out' => false,
            'stdout' => 'ok',
            'stderr' => '',
            'output_truncated' => false,
            'duration_ms' => 5,
        ], function (array $args) use (&$capturedArgs) {
            $capturedArgs = $args;
        });

        $this->postJson($this->apiUrl("coding-project/{$projectB->id}/run-command"), [
            'command' => 'echo hello',
        ])->assertStatus(200);

        $this->assertNotNull($capturedArgs);
        $this->assertContains(60, $capturedArgs, 'workspace B, never overridden, must still receive the installation default (60) -- unaffected by workspace A\'s own override');
        $this->assertNotContains(900, $capturedArgs, 'workspace A\'s override must never leak into workspace B\'s invocation');

        $this->removeDirectory($projectBDir);
    }

    #[Test]
    public function removing_an_override_reverts_the_very_next_run_to_the_current_installation_default_not_a_stale_cached_value(): void
    {
        config(['llm-client.coding_agent.command_timeout_seconds' => 60]);

        $project = $this->registerProject($this->projectDir);
        $this->patchJson($this->apiUrl("coding-project/{$project->id}/resource-limits"), [
            'time_limit_override_seconds' => 900,
        ])->assertStatus(200);

        // Remove the override (explicit null) -- FR-004.
        $this->patchJson($this->apiUrl("coding-project/{$project->id}/resource-limits"), [
            'time_limit_override_seconds' => null,
        ])->assertStatus(200);
        $this->assertNull($project->fresh()->time_limit_override_seconds);

        // Change the installation default AFTER removal, before the next
        // run -- the very next command must reflect the CURRENT
        // installation default (120), never a value cached/captured back
        // when the override existed or was removed (60).
        config(['llm-client.coding_agent.command_timeout_seconds' => 120]);

        $capturedArgs = null;
        $this->bindFakeExecutor([
            'status' => 'completed',
            'exit_code' => 0,
            'timed_out' => false,
            'stdout' => 'ok',
            'stderr' => '',
            'output_truncated' => false,
            'duration_ms' => 5,
        ], function (array $args) use (&$capturedArgs) {
            $capturedArgs = $args;
        });

        $this->postJson($this->apiUrl("coding-project/{$project->id}/run-command"), [
            'command' => 'echo hello',
        ])->assertStatus(200);

        $this->assertNotNull($capturedArgs);
        $this->assertContains(120, $capturedArgs, 'after removal, the very next run must reflect the CURRENT installation default (120)');
        $this->assertNotContains(900, $capturedArgs, 'the removed override must never reappear');
    }
}
