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
 * 124-command-limit-controls, US3 (research.md R3b/R3c, contracts/
 * run-command-limit-stops.md §1). Genuine `docker` calls throughout -- no
 * mocking anywhere in this file. Drives the real, registered
 * `PATCH .../resource-limits` and `POST .../run-command` HTTP routes
 * against the real (non-swapped) DockerCommandExecutor, mirroring
 * tests/RealDocker/DiskLimitEnforcementTest.php's/OomKillDetectionTest's
 * own shape.
 *
 * research.md R3b's own direct test found Docker exposes NO post-mortem
 * "pids-limit was hit" signal at all -- `--pids-limit` is a soft,
 * syscall-level limit; the container is never killed by Docker or the
 * kernel because of it, and the container's own command is left to react
 * to a failed `fork()` however it happens to. The load-bearing proof here
 * is that this mechanism proactively detects and kills BEFORE that
 * unreliable natural reaction, via a live cgroup `pids.current` poll --
 * plus the companion case proving a command that simply exits non-zero on
 * its own is never conflated with a limit stop (FR-008).
 */
#[Group('real-docker')]
class PidsLimitDetectionTest extends TestCase
{
    private User $user;

    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'api');

        $this->projectDir = sys_get_temp_dir().'/coding-agent-pids-limit-'.Str::random(12);
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
            'name' => 'pids-limit project',
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
    // FR-008, quickstart Scenario 3.2, checklist row 6: a command
    // attempting to fork well past a 10-process override, preceded by a
    // recognizable marker -> stopped_pids_limit, exit_code null,
    // proactively killed via the live cgroup poll before the command's
    // own unreliable reaction to a failed fork() could produce an
    // ambiguous natural exit, and no container left running afterward.
    // -----------------------------------------------------------------

    #[Test]
    public function a_fork_bomb_shaped_command_past_the_overridden_pids_limit_is_proactively_stopped_before_its_own_natural_exit(): void
    {
        config([
            'llm-client.coding_agent.command_timeout_seconds' => 30,
            'llm-client.coding_agent.command_progress_broadcast_after_seconds' => 1,
        ]);

        $project = $this->registerProject($this->projectDir);

        $response = $this->patchJson($this->apiUrl("coding-project/{$project->id}/resource-limits"), [
            'pids_limit_override' => 10,
        ]);
        $response->assertStatus(200);
        $this->assertSame(10, $response->json('pids_limit_override'));

        // Preceded by a recognizable marker, then a loop that forks well
        // past 10 background sleep processes -- each iteration paced with
        // a short sleep so the poll loop's coarser-cadence pids.current
        // re-check has a genuine chance to observe the breach mid-command
        // rather than the whole fork attempt completing (or failing on
        // its own) before any poll tick could ever fire.
        //
        // Confirmed directly on this host: a bare `sleep 60 &` in
        // alpine's ash, once it hits the pids-limit ceiling, prints
        // "sh: can't fork: Resource temporarily unavailable" and the
        // WHOLE shell terminates immediately (exit 2, in well under a
        // second) -- far faster than any poll cadence could ever observe
        // it, which would make this test race-lose against the command's
        // own crash every time rather than genuinely proving the
        // proactive kill. Wrapping the background attempt in a subshell
        // (`(sleep 60 &) 2>/dev/null`) contains that failure -- confirmed
        // directly that the loop then survives repeated fork failures and
        // keeps running, giving the poll loop a genuine, sustained window
        // in which to detect and kill it first.
        $forkLoop = 'echo before-pids-limit; i=0; while [ $i -lt 200 ]; do (sleep 60 &) 2>/dev/null; i=$((i+1)); sleep 0.05; done; echo loop-finished-on-its-own';

        $runResponse = $this->postJson($this->apiUrl("coding-project/{$project->id}/run-command"), [
            'command' => $forkLoop,
        ]);

        $runResponse->assertStatus(200);
        $this->assertSame('stopped_pids_limit', $runResponse->json('status'), 'the pids-limit poll must proactively detect and kill before the fork loop\'s own natural exit -- Docker exposes no post-mortem pids-limit signal to detect this after the fact (research.md R3b)');
        $this->assertNull($runResponse->json('exit_code'), 'a proactively-killed pids-limit stop has no exit code of its own to report, matching stopped_timeout\'s/stopped_disk_limit\'s existing convention');
        $this->assertStringContainsString('before-pids-limit', $runResponse->json('stdout'), 'output already produced before the pids-limit stop must never be discarded (FR-009)');

        $stillRunning = $this->dockerPsNames('coding-cmd-');
        $this->assertEmpty($stillRunning, 'a container from this pids-limit-stopped invocation is still running after the kill sequence: '.implode(', ', $stillRunning));
    }

    // -----------------------------------------------------------------
    // FR-008's other named minimum, quickstart Scenario 3.3: an ordinary
    // command that exits non-zero entirely on its own, unrelated to any
    // limit -> completed/exit_code 7, never conflated with any
    // limit-stop status. The load-bearing distinguishability proof.
    // -----------------------------------------------------------------

    #[Test]
    public function an_ordinary_command_that_exits_non_zero_on_its_own_is_reported_as_a_plain_completed_failure_never_any_limit_stop_status(): void
    {
        $project = $this->registerProject($this->projectDir);

        $response = $this->patchJson($this->apiUrl("coding-project/{$project->id}/resource-limits"), [
            'pids_limit_override' => 10,
        ]);
        $response->assertStatus(200);

        $runResponse = $this->postJson($this->apiUrl("coding-project/{$project->id}/run-command"), [
            'command' => 'exit 7',
        ]);

        $runResponse->assertStatus(200);
        $status = $runResponse->json('status');
        $this->assertSame('completed', $status);
        $this->assertSame(7, $runResponse->json('exit_code'));
        $this->assertNotSame('stopped_oom', $status);
        $this->assertNotSame('stopped_pids_limit', $status);
        $this->assertNotSame('stopped_disk_limit', $status);
        $this->assertNotSame('stopped_timeout', $status);
    }
}
