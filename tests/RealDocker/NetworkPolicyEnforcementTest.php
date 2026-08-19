<?php

namespace Tests\RealDocker;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\CodingProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 123-sandboxed-shell-execution, US4, T044 (research.md D7, FR-011/
 * FR-012, quickstart.md Scenario 4, checklist row 3). Genuine `docker`
 * calls throughout -- no mocking anywhere in this file. Drives the real,
 * registered `POST coding-project/{project}/run-command` HTTP route with
 * the real (non-swapped) DockerCommandExecutor, exactly like
 * tests/RealDocker/ContainmentEscapeAttemptTest.php and
 * tests/RealDocker/ResourceLimitEnforcementTest.php.
 *
 * `wget` is present in the base Alpine image this feature already uses
 * (llm-client.coding_agent.command_image) -- no extra tooling installed
 * for this test. A short (-T 2) timeout keeps a genuinely-blocked attempt
 * from hanging the test run.
 */
#[Group('real-docker')]
class NetworkPolicyEnforcementTest extends TestCase
{
    private User $user;

    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'api');

        $this->projectDir = sys_get_temp_dir().'/coding-agent-network-policy-'.Str::random(12);
        mkdir($this->projectDir, 0777, true);
    }

    protected function tearDown(): void
    {
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

    private function registerProject(string $rootPath): CodingProject
    {
        return CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'network-policy project',
            'root_path' => $rootPath,
            'test_command' => null,
        ]);
    }

    private const EGRESS_COMMAND = 'wget -T 2 -q -O- http://example.com';

    // -----------------------------------------------------------------
    // 1. Default policy (network_enabled: false) -- a genuine egress
    // attempt must fail.
    // -----------------------------------------------------------------

    #[Test]
    public function a_genuine_egress_attempt_fails_against_the_default_no_network_policy(): void
    {
        $project = $this->registerProject($this->projectDir);
        $this->assertFalse((bool) $project->network_enabled, 'fixture sanity: network access is off by default');

        $response = $this->postJson($this->apiUrl("coding-project/{$project->id}/run-command"), [
            'command' => self::EGRESS_COMMAND,
        ]);

        $response->assertStatus(200);
        $this->assertSame('completed', $response->json('status'), 'the container itself must have run -- the network attempt fails from inside it, this is not sandbox_unavailable');
        $this->assertNotSame(0, $response->json('exit_code'), 'a real egress attempt must fail (non-zero exit) against the default, no-network policy');
    }

    // -----------------------------------------------------------------
    // 2. Network enabled -- the identical command must succeed.
    // -----------------------------------------------------------------

    #[Test]
    public function the_identical_command_succeeds_once_network_is_enabled_for_the_workspace(): void
    {
        $project = $this->registerProject($this->projectDir);

        $this->patchJson($this->apiUrl("coding-project/{$project->id}/network-policy"), [
            'network_enabled' => true,
        ])->assertStatus(200);
        $this->assertTrue((bool) $project->fresh()->network_enabled, 'fixture sanity: network access is now enabled for this workspace');

        $response = $this->postJson($this->apiUrl("coding-project/{$project->id}/run-command"), [
            'command' => self::EGRESS_COMMAND,
        ]);

        $response->assertStatus(200);
        $this->assertSame('completed', $response->json('status'));
        $this->assertSame(0, $response->json('exit_code'), 'the identical egress attempt must succeed once network_enabled is true for this workspace');
        $this->assertNotEmpty($response->json('stdout'), 'a successful fetch of a real page must produce non-empty output');
    }

    // -----------------------------------------------------------------
    // 3. No cross-workspace leakage -- a second workspace whose own
    // policy is still false is unaffected by the first workspace's
    // opt-in.
    // -----------------------------------------------------------------

    #[Test]
    public function a_second_workspace_still_at_the_default_policy_still_fails_even_after_a_different_workspace_enabled_network(): void
    {
        $projectA = $this->registerProject($this->projectDir);
        $this->patchJson($this->apiUrl("coding-project/{$projectA->id}/network-policy"), [
            'network_enabled' => true,
        ])->assertStatus(200);

        $projectBDir = sys_get_temp_dir().'/coding-agent-network-policy-b-'.Str::random(12);
        mkdir($projectBDir, 0777, true);
        $projectB = $this->registerProject($projectBDir);
        $this->assertFalse((bool) $projectB->network_enabled, 'fixture sanity: workspace B never had its own policy changed');

        $response = $this->postJson($this->apiUrl("coding-project/{$projectB->id}/run-command"), [
            'command' => self::EGRESS_COMMAND,
        ]);

        $response->assertStatus(200);
        $this->assertSame('completed', $response->json('status'));
        $this->assertNotSame(0, $response->json('exit_code'), 'workspace B must still be denied network access -- enabling it on workspace A must never leak to workspace B');

        $this->removeDirectory($projectBDir);
    }
}
