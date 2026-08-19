<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Services\DockerCommandExecutor;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * 123-sandboxed-shell-execution, US1 (research.md D1a/D3/D5, data-model.md
 * §3a). Exercises DockerCommandExecutor entirely against a mockable
 * process boundary -- an injectable process-factory closure the executor
 * accepts in its constructor -- never a real `docker` binary. Genuine
 * container behavior is proven separately, against a real Docker daemon,
 * by tests/RealDocker/ContainmentEscapeAttemptTest.php and
 * tests/RealDocker/DockerUnavailableFallbackTest.php; this file proves
 * only that DockerCommandExecutor *constructs* the right invocation and
 * *interprets* the mocked process boundary's results correctly.
 *
 * Written before DockerCommandExecutor exists -- expected to FAIL red
 * (class not found) until T015 creates it.
 */
class DockerCommandExecutorTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * A Mockery partial double of Symfony's own Process class -- never a
     * hand-rolled stand-in -- so the executor is exercised against the
     * exact same public surface (run()/getExitCode()/getOutput()/
     * getErrorOutput()) it uses against a genuine Process at runtime.
     * shouldIgnoreMissing() so an incidental call this test does not care
     * about (e.g. a getter this executor version never calls) is never a
     * spurious failure.
     */
    private function fakeProcess(int $exitCode, string $stdout = '', string $stderr = ''): Process
    {
        $process = Mockery::mock(Process::class)->shouldIgnoreMissing();
        $process->shouldReceive('run')->once()->andReturn($exitCode);
        $process->shouldReceive('getExitCode')->andReturn($exitCode);
        $process->shouldReceive('getOutput')->andReturn($stdout);
        $process->shouldReceive('getErrorOutput')->andReturn($stderr);

        return $process;
    }

    private function throwingProcess(\Throwable $e): Process
    {
        $process = Mockery::mock(Process::class)->shouldIgnoreMissing();
        $process->shouldReceive('run')->once()->andThrow($e);

        return $process;
    }

    #[Test]
    public function a_reachability_precheck_failure_returns_sandbox_unavailable_before_any_docker_run_is_attempted(): void
    {
        $calls = [];

        $factory = function (array $command) use (&$calls) {
            $calls[] = $command;

            // The precheck's own docker invocation fails (nonzero exit,
            // simulating an unreachable daemon) -- the mocked boundary
            // never sees a second, "docker run" call at all.
            return $this->fakeProcess(1, '', 'Cannot connect to the Docker daemon at unix:///var/run/docker.sock');
        };

        $executor = new DockerCommandExecutor($factory);

        $result = $executor->run('/tmp/some-workspace', 'echo hello');

        $this->assertSame('sandbox_unavailable', $result['status']);
        $this->assertArrayHasKey('reason', $result);
        $this->assertNotEmpty($result['reason'], 'sandbox_unavailable must always name a specific, non-generic reason');
        $this->assertStringContainsString('not reachable', $result['reason']);
        $this->assertNull($result['exit_code']);
        $this->assertNull($result['duration_ms']);

        $this->assertCount(1, $calls, 'exactly one process must be constructed: the reachability precheck itself -- a failed precheck must never be followed by a "docker run" attempt');
        $this->assertSame('docker', $calls[0][0]);
        $this->assertNotContains('run', $calls[0], 'the precheck call itself must never be the "docker run" subcommand');
    }

    #[Test]
    public function the_constructed_invocation_carries_exactly_one_bind_mount_scoped_to_the_workspace_root_and_no_other_volume_flag(): void
    {
        $runCommand = $this->capturedDockerRunCommand('/srv/workspaces/proj-1', 'echo hello');

        $mountFlagPositions = array_keys($runCommand, '-v', true);
        $this->assertCount(1, $mountFlagPositions, 'exactly one -v flag must be present -- a stray extra mount (e.g. the docker socket) must fail this assertion');

        $mountValue = $runCommand[$mountFlagPositions[0] + 1];
        $this->assertStringStartsWith('/srv/workspaces/proj-1:', $mountValue, 'the single bind mount must be scoped to the workspace root');
        $this->assertStringEndsWith(':rw', $mountValue);

        $this->assertNotContains('--mount', $runCommand, 'no --mount flag may ever be present alongside -v');
        $this->assertStringNotContainsString('/var/run/docker.sock', implode(' ', $runCommand), 'the docker socket must never be reachable from inside the container');
    }

    #[Test]
    public function the_constructed_invocation_carries_read_only_root_and_a_tmp_scratch_area(): void
    {
        $runCommand = $this->capturedDockerRunCommand('/srv/workspaces/proj-1', 'echo hello');

        $this->assertContains('--read-only', $runCommand);
        $tmpfsPositions = array_keys($runCommand, '--tmpfs', true);
        $this->assertCount(1, $tmpfsPositions);
        $this->assertSame('/tmp', $runCommand[$tmpfsPositions[0] + 1]);
    }

    #[Test]
    public function the_constructed_invocation_carries_no_new_privileges(): void
    {
        $runCommand = $this->capturedDockerRunCommand('/srv/workspaces/proj-1', 'echo hello');

        $securityOptPositions = array_keys($runCommand, '--security-opt', true);
        $this->assertNotEmpty($securityOptPositions);
        $this->assertContains('no-new-privileges', $runCommand);
    }

    #[Test]
    public function the_constructed_invocation_is_ephemeral_and_uniquely_named(): void
    {
        $runCommandA = $this->capturedDockerRunCommand('/srv/workspaces/proj-1', 'echo hello');
        $runCommandB = $this->capturedDockerRunCommand('/srv/workspaces/proj-1', 'echo hello');

        $this->assertContains('--rm', $runCommandA);

        $namePositionsA = array_keys($runCommandA, '--name', true);
        $this->assertCount(1, $namePositionsA);
        $nameA = $runCommandA[$namePositionsA[0] + 1];
        $this->assertMatchesRegularExpression('/^coding-cmd-[0-9a-f-]{36}$/', $nameA);

        $namePositionsB = array_keys($runCommandB, '--name', true);
        $nameB = $runCommandB[$namePositionsB[0] + 1];

        $this->assertNotSame($nameA, $nameB, 'every invocation must mint a fresh, unique container name');
    }

    #[Test]
    public function a_successful_run_reports_completed_with_the_exact_exit_code_stdout_stderr_the_mocked_process_returned(): void
    {
        $calls = [];
        $factory = function (array $command) use (&$calls) {
            $calls[] = $command;

            if ($command[1] === 'version') {
                return $this->fakeProcess(0, 'Docker version 29.7.2', '');
            }

            return $this->fakeProcess(3, "workspace output\n", "some warning\n");
        };

        $executor = new DockerCommandExecutor($factory);

        $result = $executor->run('/srv/workspaces/proj-1', 'my-script.sh');

        $this->assertSame('completed', $result['status']);
        $this->assertSame(3, $result['exit_code']);
        $this->assertSame("workspace output\n", $result['stdout']);
        $this->assertSame("some warning\n", $result['stderr']);
        $this->assertIsInt($result['duration_ms']);
        $this->assertGreaterThanOrEqual(0, $result['duration_ms'], 'duration_ms must be a measured, non-null wall-clock value regardless of any limit being configured');
    }

    #[Test]
    public function an_unreachable_docker_daemon_reported_via_a_thrown_process_exception_also_yields_sandbox_unavailable(): void
    {
        $factory = function (array $command) {
            return $this->throwingProcess(new \RuntimeException('docker: command not found'));
        };

        $executor = new DockerCommandExecutor($factory);

        $result = $executor->run('/tmp/some-workspace', 'echo hello');

        $this->assertSame('sandbox_unavailable', $result['status']);
        $this->assertNotEmpty($result['reason']);
    }

    /**
     * @return list<string>
     */
    private function capturedDockerRunCommand(string $rootPath, string $command): array
    {
        $captured = null;

        $factory = function (array $cmd) use (&$captured) {
            if ($cmd[1] === 'version') {
                return $this->fakeProcess(0, '', '');
            }

            $captured = $cmd;

            return $this->fakeProcess(0, '', '');
        };

        $executor = new DockerCommandExecutor($factory);
        $executor->run($rootPath, $command);

        $this->assertNotNull($captured, 'the docker run invocation was never constructed');

        return $captured;
    }
}
