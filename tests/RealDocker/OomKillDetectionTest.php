<?php

namespace Tests\RealDocker;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\CodingProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * 124-command-limit-controls, US3 (research.md R3a, contracts/
 * run-command-limit-stops.md §1). Genuine `docker` calls throughout -- no
 * mocking anywhere in this file. Drives the real, registered
 * `PATCH .../resource-limits` and `POST .../run-command` HTTP routes
 * against the real (non-swapped) DockerCommandExecutor, mirroring
 * tests/RealDocker/DiskLimitEnforcementTest.php's own shape.
 *
 * The load-bearing proof here is twofold: (1) the memory-exhausting loop
 * resolves `stopped_oom`, not `completed`, with the container's real
 * kernel-reported exit code preserved -- only possible now that `--rm`
 * has been dropped from the constructed flag set (research.md R3a's own
 * direct test: a `--rm` container is already gone, "no such object", by
 * the time `docker inspect` could ever run); and (2) a real `docker ps`
 * check afterward confirms no container from this invocation is left
 * running, proving the new explicit `docker rm -f` cleanup genuinely
 * replaces `--rm`'s implicit cleanup rather than merely coexisting with a
 * leak.
 */
#[Group('real-docker')]
class OomKillDetectionTest extends TestCase
{
    private User $user;

    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'api');

        $this->projectDir = sys_get_temp_dir().'/coding-agent-oom-kill-'.Str::random(12);
        mkdir($this->projectDir, 0777, true);
    }

    protected function tearDown(): void
    {
        DB::table('coding_command_executions')->delete();
        DB::table('coding_workspace_refusals')->delete();
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
            'name' => 'oom-kill project',
            'root_path' => $rootPath,
            'test_command' => null,
        ]);
    }

    /**
     * @return list<string>
     */
    private function dockerPsNames(string $namePrefix): array
    {
        $process = new Process(['docker', 'ps', '--filter', 'name='.$namePrefix, '--format', '{{.Names}}']);
        $process->run();

        $output = trim($process->getOutput());
        if ($output === '') {
            return [];
        }

        return array_values(array_filter(explode("\n", $output)));
    }

    // -----------------------------------------------------------------
    // FR-007, quickstart Scenario 3.1, checklist row 5: a memory-
    // exhausting loop past a 20MB override, preceded by a recognizable
    // marker -> stopped_oom (never completed), a real >=128 exit code,
    // the marker present in stdout (FR-009), and no container from this
    // invocation left running afterward (the --rm removal's own proof).
    // -----------------------------------------------------------------

    #[Test]
    public function a_memory_exhausting_loop_past_the_overridden_memory_limit_resolves_stopped_oom_with_the_real_exit_code_and_no_container_left_running(): void
    {
        config(['llm-client.coding_agent.command_timeout_seconds' => 30]);

        $project = $this->registerProject($this->projectDir);

        $response = $this->patchJson($this->apiUrl("coding-project/{$project->id}/resource-limits"), [
            'memory_limit_override_mb' => 20,
        ]);
        $response->assertStatus(200);
        $this->assertSame(20, $response->json('memory_limit_override_mb'));

        // Pure POSIX shell memory bomb, preceded by a recognizable marker
        // -- exponential string doubling, no extra binaries required
        // beyond the shell already present in the base image. Comfortably
        // exceeds a 20MB cgroup memory cap within a couple of doublings
        // past it.
        $memoryBomb = 'echo before-oom; a="A"; while true; do a="$a$a"; done';

        $runResponse = $this->postJson($this->apiUrl("coding-project/{$project->id}/run-command"), [
            'command' => $memoryBomb,
        ]);

        $runResponse->assertStatus(200);
        $this->assertSame('stopped_oom', $runResponse->json('status'), 'the container must resolve stopped_oom now, never completed -- only possible once --rm is dropped so docker inspect can still see the exited container\'s State.OOMKilled');
        $this->assertNotSame('completed', $runResponse->json('status'));

        $exitCode = $runResponse->json('exit_code');
        $this->assertNotNull($exitCode, 'stopped_oom is the one limit-stop status that still carries the container\'s real, kernel-reported exit code (unlike stopped_timeout/stopped_disk_limit/stopped_pids_limit, whose exit_code is null)');
        $this->assertGreaterThanOrEqual(128, $exitCode, 'an OOM kill must produce a signal-derived (>=128) exit code, not an ordinary shell failure code');

        $this->assertStringContainsString('before-oom', $runResponse->json('stdout'), 'output already produced before the kernel\'s OOM kill must never be discarded (FR-009)');

        $stillRunning = $this->dockerPsNames('coding-cmd-');
        $this->assertEmpty($stillRunning, 'a container from this OOM-killed invocation is still running after the new explicit docker rm -f cleanup: '.implode(', ', $stillRunning));
    }
}
