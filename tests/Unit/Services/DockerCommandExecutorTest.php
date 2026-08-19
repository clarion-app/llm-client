<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Services\DockerCommandExecutor;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * 123-sandboxed-shell-execution, US1/US3 (research.md D1a/D3/D4/D5,
 * data-model.md §3a). Exercises DockerCommandExecutor entirely against a
 * mockable process boundary -- an injectable process-factory closure the
 * executor accepts in its constructor -- never a real `docker` binary.
 * Genuine container behavior is proven separately, against a real Docker
 * daemon, by tests/RealDocker/ContainmentEscapeAttemptTest.php,
 * tests/RealDocker/DockerUnavailableFallbackTest.php, and (US3)
 * tests/RealDocker/ResourceLimitEnforcementTest.php; this file proves
 * only that DockerCommandExecutor *constructs* the right invocation and
 * *interprets* the mocked process boundary's results correctly.
 *
 * Written before DockerCommandExecutor exists -- expected to FAIL red
 * (class not found) until T015 creates it. The US3 cases below (T032)
 * were added, and confirmed red, once T015's US1-only class already
 * existed but before T037 extended it with resource limits, the
 * timeout/kill sequence, and output capping.
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
     * exact same public surface it uses against a genuine Process at
     * runtime. Covers BOTH shapes this class's run() method depends on:
     * the reachability precheck (run()/getExitCode()/getErrorOutput(),
     * unchanged since Phase 3) and the main invocation's poll loop
     * (start()/isRunning()/checkTimeout()/getIncrementalOutput()/
     * getIncrementalErrorOutput(), US3/T037). isRunning() returns true
     * once then false, and the incremental-output getters return the
     * given content once then '' -- exactly one loop iteration, after
     * which the "process ended on its own" branch's own trailing capture
     * call sees no further data, mirroring how Symfony's real
     * getIncrementalOutput() only ever returns bytes new since the last
     * call. shouldIgnoreMissing() so an incidental call this test does
     * not care about is never a spurious failure.
     */
    private function fakeProcess(int $exitCode, string $stdout = '', string $stderr = ''): Process
    {
        $process = Mockery::mock(Process::class)->shouldIgnoreMissing();
        $process->shouldReceive('run')->andReturn($exitCode);
        $process->shouldReceive('getExitCode')->andReturn($exitCode);
        $process->shouldReceive('getOutput')->andReturn($stdout);
        $process->shouldReceive('getErrorOutput')->andReturn($stderr);
        $process->shouldReceive('start')->andReturnNull();
        $process->shouldReceive('isRunning')->andReturn(true, false);
        $process->shouldReceive('checkTimeout')->andReturnNull();
        $process->shouldReceive('getIncrementalOutput')->andReturn($stdout, '');
        $process->shouldReceive('getIncrementalErrorOutput')->andReturn($stderr, '');

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

    // -----------------------------------------------------------------
    // T032 (US3, research.md D1a/D4): resource-limit flags, the wired
    // Process::setTimeout(), the timeout/kill/rm sequence, and
    // output-cap/truncation. Written before T037 extends
    // DockerCommandExecutor with any of this -- expected to FAIL red
    // (the flags are simply absent, setTimeout() is never called, no
    // ProcessTimedOutException handling exists, no cap is applied).
    // -----------------------------------------------------------------

    #[Test]
    public function the_constructed_invocation_carries_memory_and_memory_swap_set_to_the_same_value_plus_cpus_and_pids_limit(): void
    {
        config([
            'llm-client.coding_agent.command_memory_limit_mb' => 512,
            'llm-client.coding_agent.command_cpu_limit' => '2.0',
            'llm-client.coding_agent.command_pids_limit' => 256,
        ]);

        $runCommand = $this->capturedDockerRunCommand('/srv/workspaces/proj-1', 'echo hello');

        $memoryPositions = array_keys($runCommand, '--memory', true);
        $this->assertCount(1, $memoryPositions);
        $memoryValue = $runCommand[$memoryPositions[0] + 1];
        $this->assertSame('512m', $memoryValue);

        $memorySwapPositions = array_keys($runCommand, '--memory-swap', true);
        $this->assertCount(1, $memorySwapPositions);
        $memorySwapValue = $runCommand[$memorySwapPositions[0] + 1];

        // The load-bearing assertion: --memory-swap must carry the exact
        // SAME value as --memory -- a larger swap value would silently
        // let the container consume up to 2x the stated memory cap.
        $this->assertSame($memoryValue, $memorySwapValue, '--memory-swap must be set to the exact same value as --memory, closing the "swap doubles the cap" gap');

        $cpusPositions = array_keys($runCommand, '--cpus', true);
        $this->assertCount(1, $cpusPositions);
        $this->assertSame('2.0', $runCommand[$cpusPositions[0] + 1]);

        $pidsLimitPositions = array_keys($runCommand, '--pids-limit', true);
        $this->assertCount(1, $pidsLimitPositions);
        $this->assertSame('256', $runCommand[$pidsLimitPositions[0] + 1]);
    }

    #[Test]
    public function process_set_timeout_is_wired_from_the_configured_command_timeout_seconds(): void
    {
        config(['llm-client.coding_agent.command_timeout_seconds' => 77]);

        $calledWith = null;

        $factory = function (array $command) use (&$calledWith) {
            if ($command[1] === 'version') {
                return $this->fakeProcess(0, '', '');
            }

            $process = Mockery::mock(Process::class)->shouldIgnoreMissing();
            $process->shouldReceive('setTimeout')->once()->withArgs(function ($value) use (&$calledWith) {
                $calledWith = $value;

                return (float) $value === 77.0;
            })->andReturnSelf();
            $process->shouldReceive('start')->andReturnNull();
            $process->shouldReceive('isRunning')->andReturn(false);
            $process->shouldReceive('getExitCode')->andReturn(0);

            return $process;
        };

        $executor = new DockerCommandExecutor($factory);
        $executor->run('/srv/workspaces/proj-1', 'echo hi');

        $this->assertSame(77.0, (float) $calledWith, 'Process::setTimeout() must be called with exactly the configured command_timeout_seconds value');
    }

    #[Test]
    public function a_process_timed_out_exception_triggers_an_explicit_docker_kill_then_a_docker_rm_dash_f_fallback_and_reports_stopped_timeout(): void
    {
        $calls = [];
        $containerName = null;

        $factory = function (array $command) use (&$calls, &$containerName) {
            $calls[] = $command;

            if ($command[1] === 'version') {
                return $this->fakeProcess(0, '', '');
            }

            if ($command[1] === 'kill' || $command[1] === 'rm') {
                return $this->fakeProcess(0, '', '');
            }

            // The main "docker run" invocation -- capture its --name so
            // the kill/rm assertions below can confirm the exact same
            // container is targeted.
            $namePositions = array_keys($command, '--name', true);
            $containerName = $command[$namePositions[0] + 1];

            $mainProcess = Mockery::mock(Process::class)->shouldIgnoreMissing();
            $mainProcess->shouldReceive('start')->andReturnNull();
            // Running through two poll iterations: the first captures
            // partial output before anything times out (proving FR-008 --
            // output already produced must survive a timeout-kill); the
            // second iteration's checkTimeout() call is where the
            // simulated timeout actually fires.
            $mainProcess->shouldReceive('isRunning')->andReturn(true, true);
            $mainProcess->shouldReceive('getIncrementalOutput')->andReturn("partial output before timeout\n", '');
            $mainProcess->shouldReceive('getIncrementalErrorOutput')->andReturn('', '');

            $timeoutCallCount = 0;
            $mainProcess->shouldReceive('checkTimeout')->andReturnUsing(function () use (&$timeoutCallCount, &$mainProcess) {
                $timeoutCallCount++;
                if ($timeoutCallCount >= 2) {
                    throw new ProcessTimedOutException($mainProcess, ProcessTimedOutException::TYPE_GENERAL);
                }
            });

            return $mainProcess;
        };

        $executor = new DockerCommandExecutor($factory);
        $result = $executor->run('/srv/workspaces/proj-1', 'sleep 300');

        $this->assertSame('stopped_timeout', $result['status']);
        $this->assertTrue($result['timed_out']);
        $this->assertNull($result['exit_code']);
        $this->assertSame("partial output before timeout\n", $result['stdout'], 'output already captured before the timeout-kill must be preserved, never discarded (FR-008)');
        $this->assertIsInt($result['duration_ms']);

        $this->assertNotNull($containerName);

        // The kill-then-rm-fallback sequence, in order, targeting the
        // exact same container name the "docker run" invocation used.
        $subcommands = array_map(fn ($c) => $c[1], $calls);
        $this->assertContains('kill', $subcommands);
        $this->assertContains('rm', $subcommands);
        $killIndex = array_search('kill', $subcommands, true);
        $rmIndex = array_search('rm', $subcommands, true);
        $this->assertLessThan($rmIndex, $killIndex, 'docker kill must be issued before the docker rm -f fallback');

        $killCall = $calls[$killIndex];
        $this->assertSame(['docker', 'kill', $containerName], $killCall);

        $rmCall = $calls[$rmIndex];
        $this->assertSame(['docker', 'rm', '-f', $containerName], $rmCall);
    }

    #[Test]
    public function output_exceeding_the_configured_cap_is_bounded_and_marked_truncated_while_preserving_what_was_captured(): void
    {
        config(['llm-client.coding_agent.command_output_cap_bytes' => 20]);

        $oversizedChunk = str_repeat('X', 50);

        $factory = function (array $command) use ($oversizedChunk) {
            if ($command[1] === 'version') {
                return $this->fakeProcess(0, '', '');
            }

            $process = Mockery::mock(Process::class)->shouldIgnoreMissing();
            $process->shouldReceive('start')->andReturnNull();
            $process->shouldReceive('isRunning')->andReturn(true, false);
            $process->shouldReceive('checkTimeout')->andReturnNull();
            $process->shouldReceive('getIncrementalOutput')->andReturn($oversizedChunk, '');
            $process->shouldReceive('getIncrementalErrorOutput')->andReturn('', '');
            $process->shouldReceive('getExitCode')->andReturn(0);

            return $process;
        };

        $executor = new DockerCommandExecutor($factory);
        $result = $executor->run('/srv/workspaces/proj-1', 'yes | head -c 999999999');

        $this->assertSame('completed', $result['status']);
        $this->assertTrue($result['output_truncated']);
        $this->assertSame(20, strlen($result['stdout']), 'stdout must be bounded to the configured cap, never the full oversized content');
        $this->assertSame(str_repeat('X', 20), $result['stdout'], 'the bytes captured before the cap must be preserved, never discarded');
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
