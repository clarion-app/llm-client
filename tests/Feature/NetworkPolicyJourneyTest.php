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
 * 123-sandboxed-shell-execution, US4, T043 (contracts/network-policy.md
 * §1, data-model.md §3's historical-snapshot guarantee). Drives the real,
 * registered `PATCH coding-project/{id}/network-policy` HTTP route
 * through the real CodingProjectController -- ownership-checked
 * identically to updateConfirmationSetting()/updateCommandAllowlist(),
 * no Docker involved for the CRUD cases. The historical-snapshot case
 * additionally drives `POST coding-project/{project}/run-command` with
 * DockerCommandExecutor swapped for a Mockery double bound into the
 * container (mirroring RunCommandJourneyTest.php's own bindFakeExecutor()
 * shape) -- no real Docker is needed to prove that a CodingCommandExecution
 * row's network_enabled column reflects the workspace's setting at the
 * time that command ran, unaffected by a later policy change.
 *
 * Written before CodingProjectController::updateNetworkPolicy() exists --
 * expected to FAIL red (route not found) until T045/T046 land, and the
 * historical-snapshot case additionally needs T047's real-column read in
 * CodingWorkspaceController::runCommand() to go green.
 */
class NetworkPolicyJourneyTest extends TestCase
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

        $this->projectDir = sys_get_temp_dir().'/coding-agent-network-policy-'.Str::random(12);
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
            'name' => 'network-policy project',
            'root_path' => $rootPath,
            'test_command' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function bindFakeExecutor(array $result): void
    {
        $fake = Mockery::mock(DockerCommandExecutor::class);
        $fake->shouldReceive('run')->andReturn($result);
        $this->app->instance(DockerCommandExecutor::class, $fake);
    }

    // -----------------------------------------------------------------
    // Setting the policy (contracts/network-policy.md §1)
    // -----------------------------------------------------------------

    #[Test]
    public function patching_the_network_policy_returns_200_with_the_full_updated_project(): void
    {
        $project = $this->registerProject($this->projectDir);
        $this->assertFalse((bool) $project->network_enabled, 'fixture sanity: network access is off by default');

        $response = $this->patchJson($this->apiUrl("coding-project/{$project->id}/network-policy"), [
            'network_enabled' => true,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('id', $project->id);
        $response->assertJsonPath('network_enabled', true);

        $this->assertTrue((bool) $project->fresh()->network_enabled);
    }

    #[Test]
    public function patching_the_network_policy_back_to_false_is_accepted(): void
    {
        $project = $this->registerProject($this->projectDir);
        $project->update(['network_enabled' => true]);

        $response = $this->patchJson($this->apiUrl("coding-project/{$project->id}/network-policy"), [
            'network_enabled' => false,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('network_enabled', false);
        $this->assertFalse((bool) $project->fresh()->network_enabled);
    }

    // -----------------------------------------------------------------
    // Ownership (404)
    // -----------------------------------------------------------------

    #[Test]
    public function patching_an_absent_project_id_returns_404(): void
    {
        $response = $this->patchJson($this->apiUrl('coding-project/'.Str::uuid().'/network-policy'), [
            'network_enabled' => true,
        ]);

        $response->assertStatus(404);
        $response->assertJson(['error' => 'Coding project not found', 'code' => 'coding_project_not_found']);
    }

    #[Test]
    public function patching_a_foreign_owned_project_returns_404(): void
    {
        $foreignProject = $this->registerProject($this->projectDir, $this->otherUser);

        $response = $this->patchJson($this->apiUrl("coding-project/{$foreignProject->id}/network-policy"), [
            'network_enabled' => true,
        ]);

        $response->assertStatus(404);
        $response->assertJson(['error' => 'Coding project not found', 'code' => 'coding_project_not_found']);
        $this->assertFalse((bool) $foreignProject->fresh()->network_enabled, 'a foreign-owned project must never be modified');
    }

    // -----------------------------------------------------------------
    // Validation (422)
    // -----------------------------------------------------------------

    #[Test]
    public function a_missing_network_enabled_field_is_rejected_with_422(): void
    {
        $project = $this->registerProject($this->projectDir);

        $response = $this->patchJson($this->apiUrl("coding-project/{$project->id}/network-policy"), []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['network_enabled']);
    }

    #[Test]
    public function a_non_boolean_network_enabled_field_is_rejected_with_422(): void
    {
        $project = $this->registerProject($this->projectDir);

        $response = $this->patchJson($this->apiUrl("coding-project/{$project->id}/network-policy"), [
            'network_enabled' => 'yes please',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['network_enabled']);
    }

    // -----------------------------------------------------------------
    // Cross-workspace isolation (SC-007)
    // -----------------------------------------------------------------

    #[Test]
    public function enabling_network_on_one_workspace_has_zero_effect_on_a_second_workspaces_own_setting(): void
    {
        $projectA = $this->registerProject($this->projectDir);
        $projectBDir = sys_get_temp_dir().'/coding-agent-network-policy-b-'.Str::random(12);
        mkdir($projectBDir, 0777, true);
        $projectB = $this->registerProject($projectBDir);

        $this->patchJson($this->apiUrl("coding-project/{$projectA->id}/network-policy"), [
            'network_enabled' => true,
        ])->assertStatus(200);

        $this->assertTrue((bool) $projectA->fresh()->network_enabled);
        $this->assertFalse(
            (bool) $projectB->fresh()->network_enabled,
            'workspace B\'s own network policy must be completely unaffected by workspace A\'s PATCH',
        );

        $this->removeDirectory($projectBDir);
    }

    // -----------------------------------------------------------------
    // Historical-snapshot guarantee (data-model.md §3): a completed
    // CodingCommandExecution row's network_enabled column reflects the
    // workspace's setting AT THE TIME that command ran, unaffected by a
    // LATER policy change on the same workspace.
    // -----------------------------------------------------------------

    #[Test]
    public function a_command_executions_network_enabled_column_reflects_the_policy_at_the_time_it_ran_and_is_unaffected_by_a_later_change(): void
    {
        $project = $this->registerProject($this->projectDir);
        $this->assertFalse((bool) $project->network_enabled, 'fixture sanity: network access is off by default');

        $this->bindFakeExecutor([
            'status' => 'completed',
            'exit_code' => 0,
            'timed_out' => false,
            'stdout' => 'ok',
            'stderr' => '',
            'output_truncated' => false,
            'duration_ms' => 5,
        ]);

        // Run a command while the workspace's network policy is still the
        // default (off) -- the recorded row must capture that.
        $this->postJson($this->apiUrl("coding-project/{$project->id}/run-command"), [
            'command' => 'echo hello',
        ])->assertStatus(200);

        $firstRow = DB::table('coding_command_executions')->where('coding_project_id', $project->id)->first();
        $this->assertNotNull($firstRow);
        $this->assertSame(0, (int) $firstRow->network_enabled, 'the first row must record network_enabled = false, matching the policy in effect when it ran');

        // Now enable network access for the workspace -- a policy change
        // strictly after the first command already ran.
        $this->patchJson($this->apiUrl("coding-project/{$project->id}/network-policy"), [
            'network_enabled' => true,
        ])->assertStatus(200);

        // The first row's historical record must never be rewritten by
        // the later policy change.
        $firstRowAfterPolicyChange = DB::table('coding_command_executions')->where('id', $firstRow->id)->first();
        $this->assertSame(0, (int) $firstRowAfterPolicyChange->network_enabled, 'a later policy change on the same workspace must never rewrite an earlier command execution\'s historical network_enabled value');

        // A second command, run after the policy change, must capture the
        // NEW value -- proving the column is read fresh per invocation,
        // not cached from the first run.
        $this->postJson($this->apiUrl("coding-project/{$project->id}/run-command"), [
            'command' => 'echo hello again',
        ])->assertStatus(200);

        $secondRow = DB::table('coding_command_executions')
            ->where('coding_project_id', $project->id)
            ->where('id', '!=', $firstRow->id)
            ->first();
        $this->assertNotNull($secondRow);
        $this->assertSame(1, (int) $secondRow->network_enabled, 'a command run after the policy change must record network_enabled = true');
    }
}
