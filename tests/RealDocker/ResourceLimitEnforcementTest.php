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
 * 123-sandboxed-shell-execution, US3, T035 (research.md D1a/D4/D5,
 * FR-007/FR-008/FR-009, quickstart.md Scenario 3.1/3.3, checklist rows 2
 * and 6). Genuine `docker` calls throughout -- no mocking anywhere in
 * this file. Cases 1-2 drive the real, registered
 * `POST coding-project/{project}/run-command` HTTP route with the real
 * (non-swapped) DockerCommandExecutor, exactly like
 * tests/RealDocker/ContainmentEscapeAttemptTest.php. Case 3 samples
 * `docker stats` while a real container runs a CPU-bound busy loop --
 * proving actual cgroup enforcement, not merely that the --cpus flag
 * string is constructed (that narrower claim is already covered by
 * tests/Unit/Services/DockerCommandExecutorTest.php's T032 cases).
 *
 * NOTE: this file involves genuine sleep/OOM/CPU-loop timing -- it can
 * take a few minutes to run for real. That is expected, not a hang.
 */
#[Group('real-docker')]
class ResourceLimitEnforcementTest extends TestCase
{
    private User $user;

    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'api');

        $this->projectDir = sys_get_temp_dir().'/coding-agent-resource-limit-'.Str::random(12);
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
            'name' => 'resource-limit project',
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

    private function containerIsRunning(string $name): bool
    {
        return in_array($name, $this->dockerPsNames($name), true);
    }

    // -----------------------------------------------------------------
    // 1. Wall-clock stop + genuine teardown (quickstart Scenario 3.1,
    // FR-007/FR-008, checklist row 6): a non-terminating command is
    // stopped, output-so-far is still reported, and -- the load-bearing
    // check -- no container from this invocation is still running
    // afterward, proving kill-by-name tears down the container itself,
    // not merely the wrapping `docker run` client process.
    // -----------------------------------------------------------------

    #[Test]
    public function a_non_terminating_command_is_stopped_at_the_wall_clock_limit_with_no_container_left_running(): void
    {
        config(['llm-client.coding_agent.command_timeout_seconds' => 3]);

        $project = $this->registerProject($this->projectDir);

        $before = microtime(true);
        $response = $this->postJson($this->apiUrl("coding-project/{$project->id}/run-command"), [
            'command' => 'sleep 300',
        ]);
        $elapsed = microtime(true) - $before;

        $response->assertStatus(200);
        $this->assertSame('stopped_timeout', $response->json('status'));
        $this->assertTrue($response->json('timed_out'));
        $this->assertNull($response->json('exit_code'));
        $this->assertLessThan(60.0, $elapsed, 'the request must not have blocked anywhere near the full 300s sleep -- the timeout/kill sequence must have actually stopped it');

        $stillRunning = $this->dockerPsNames('coding-cmd-');
        $this->assertEmpty($stillRunning, 'a container from this timed-out invocation is still running after the kill+rm sequence: '.implode(', ', $stillRunning));
    }

    // -----------------------------------------------------------------
    // 2. Memory bound (quickstart Scenario 3.3, FR-009, checklist row 2):
    // a genuine memory-allocate-and-touch loop past the configured
    // --memory limit is killed by the kernel's cgroup OOM mechanism -- a
    // signal-derived (non-zero, conventionally >=128) exit, never a
    // silently-hung process, never a completed run reporting success.
    // -----------------------------------------------------------------

    #[Test]
    public function a_memory_exhausting_loop_past_the_configured_memory_limit_is_oom_killed(): void
    {
        config([
            'llm-client.coding_agent.command_memory_limit_mb' => 20,
            'llm-client.coding_agent.command_timeout_seconds' => 30,
        ]);

        $project = $this->registerProject($this->projectDir);

        // Pure POSIX shell memory bomb -- exponential string doubling,
        // no extra binaries required beyond the shell already present in
        // the base image. Comfortably exceeds a 20MB cgroup memory cap
        // within a couple of doublings past it.
        $memoryBomb = 'a="A"; while true; do a="$a$a"; done';

        $response = $this->postJson($this->apiUrl("coding-project/{$project->id}/run-command"), [
            'command' => $memoryBomb,
        ]);

        $response->assertStatus(200);
        $this->assertSame('completed', $response->json('status'), 'the container itself must have run and been killed by the kernel -- never stopped_timeout (the OOM kill must occur well within the 30s wall-clock budget) and never sandbox_unavailable');
        $this->assertNotSame(0, $response->json('exit_code'), 'an OOM-killed process must never report a clean zero exit');
        $this->assertGreaterThanOrEqual(128, $response->json('exit_code'), 'an OOM kill must produce a signal-derived (>=128) exit code, not an ordinary shell failure code');

        $stillRunning = $this->dockerPsNames('coding-cmd-');
        $this->assertEmpty($stillRunning, 'the OOM-killed container must not still be running (its own --rm cleanup should have completed)');
    }

    // -----------------------------------------------------------------
    // 3. CPU bound, real enforcement (FR-009's other named minimum,
    // checklist row 2's CPU half): a genuine CPU-bound busy loop against
    // a low --cpus quota stays measurably bounded near that quota,
    // sampled live via `docker stats` while the container runs -- this
    // closes the gap where the unit test only proves the --cpus flag
    // string is *constructed*, never that cgroups actually *enforces*
    // it. Bypasses the HTTP layer and starts the container directly via
    // Symfony Process::start() (the identical flag shape
    // DockerCommandExecutor itself constructs), mirroring
    // ContainmentEscapeAttemptTest.php's own concurrency case, purely so
    // this test can sample `docker stats` *during* the run rather than
    // only after the (blocking) HTTP call returns.
    // -----------------------------------------------------------------

    #[Test]
    public function a_cpu_bound_busy_loop_stays_measurably_bounded_near_a_low_cpus_quota(): void
    {
        $project = $this->registerProject($this->projectDir);

        $image = (string) config('llm-client.coding_agent.command_image', 'alpine:latest');
        $cpuLimit = '0.5';
        $name = 'coding-cmd-cpu-quota-test-'.Str::random(8);

        // A tight busy loop, bounded to a fixed wall-clock duration using
        // only shell builtins -- no extra tooling required.
        $busyLoop = 'end=$(($(date +%s)+6)); while [ "$(date +%s)" -lt "$end" ]; do :; done';

        $dockerRunCommand = [
            'docker', 'run',
            '--rm',
            '--name', $name,
            '-v', $project->root_path.':/workspace:rw',
            '--read-only',
            '--tmpfs', '/tmp',
            '--security-opt', 'no-new-privileges',
            '--memory', '256m',
            '--memory-swap', '256m',
            '--cpus', $cpuLimit,
            '--pids-limit', '128',
            '--workdir', '/workspace',
            $image,
            'sh', '-c', $busyLoop,
        ];

        $process = new Process($dockerRunCommand);
        $process->start();

        // Wait briefly for the container to actually appear before
        // sampling -- docker run's own startup has a small, variable
        // lead time before the container is visible to `docker ps`.
        $deadline = microtime(true) + 5;
        while (!$this->containerIsRunning($name) && microtime(true) < $deadline) {
            usleep(100_000);
        }
        $this->assertTrue($this->containerIsRunning($name), 'fixture sanity: the container must actually be running before its CPU usage can be sampled');

        $samples = [];
        for ($i = 0; $i < 3; $i++) {
            $statsProcess = new Process(['docker', 'stats', '--no-stream', '--format', '{{.CPUPerc}}', $name]);
            $statsProcess->run();
            if ($statsProcess->isSuccessful()) {
                $raw = trim($statsProcess->getOutput());
                if ($raw !== '' && str_ends_with($raw, '%')) {
                    $samples[] = (float) rtrim($raw, '%');
                }
            }
            usleep(700_000);
        }

        $process->wait();

        $this->assertNotEmpty($samples, 'at least one docker stats CPU sample must have been captured while the container was running');

        // --cpus 0.5 caps usage at 50% of one core. Generous headroom
        // (Docker's own accounting window plus host scheduling jitter)
        // without allowing the measurement to pass by coincidence -- an
        // unenforced quota on a multi-core host would show usage well
        // above 100%, not merely a little over 50%.
        foreach ($samples as $sample) {
            $this->assertLessThan(80.0, $sample, "CPU usage sample {$sample}% exceeded the bounded envelope for a --cpus {$cpuLimit} quota -- the limit is not being enforced");
        }
    }
}
