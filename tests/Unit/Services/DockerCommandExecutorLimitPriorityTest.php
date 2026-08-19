<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Services\DockerCommandExecutor;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * 124-command-limit-controls, US2/US3 (research.md R2/R3b/R3c). Exercises
 * DockerCommandExecutor's disk-usage-delta and (Phase 5) pids-limit
 * enforcement entirely against a mocked process boundary -- the SAME
 * injectable `$processFactory` seam (a closure taking the command array and
 * returning a Process-shaped object) every other shell-out in this class
 * already goes through, per research.md R3b's own "testability seam" note.
 * No real Docker daemon, no real `du`/cgroup filesystem, ever required here
 * -- genuine enforcement against a real container is proven separately by
 * tests/RealDocker/DiskLimitEnforcementTest.php (Phase 4) and
 * tests/RealDocker/{OomKillDetectionTest,PidsLimitDetectionTest}.php
 * (Phase 5). This file proves only that DockerCommandExecutor's poll loop
 * *evaluates the right check, in the right order, against a simulated
 * boundary*.
 *
 * Phase 4 (US2, T019): the disk-usage-delta breach case, and the
 * timeout-before-disk check-order proof. Phase 5 (US3, T025) extends this
 * same file with the pids-limit cases and the full three-way (timeout,
 * disk, pids) precedence proof, once DockerCommandExecutor gains its own
 * separate $pidsCurrentReader seam -- not added here.
 *
 * Written before DockerCommandExecutor's disk-usage-delta check exists --
 * expected to FAIL red (no du-based poll-loop check is performed at all,
 * so both cases below resolve to `completed`/`stopped_timeout` respectively
 * without ever considering a disk breach).
 */
class DockerCommandExecutorLimitPriorityTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * A Mockery double standing in for the Symfony Process a genuine
     * `du -sb <path>` shell-out would produce -- run()/getExitCode()/
     * getOutput() only, mirroring real `du`'s own "<bytes>\t<path>\n"
     * stdout shape.
     */
    private function fakeDuProcess(int $bytes): Process
    {
        $process = Mockery::mock(Process::class)->shouldIgnoreMissing();
        $process->shouldReceive('run')->andReturn(0);
        $process->shouldReceive('getExitCode')->andReturn(0);
        $process->shouldReceive('getOutput')->andReturn($bytes."\t/workspace\n");

        return $process;
    }

    private function fakeDockerVersionProcess(): Process
    {
        $process = Mockery::mock(Process::class)->shouldIgnoreMissing();
        $process->shouldReceive('run')->andReturn(0);
        $process->shouldReceive('getExitCode')->andReturn(0);
        $process->shouldReceive('getOutput')->andReturn('Docker version 29.7.2');
        $process->shouldReceive('getErrorOutput')->andReturn('');

        return $process;
    }

    private function fakeKillOrRmProcess(): Process
    {
        $process = Mockery::mock(Process::class)->shouldIgnoreMissing();
        $process->shouldReceive('run')->andReturn(0);

        return $process;
    }

    // -----------------------------------------------------------------
    // 1. A simulated disk-usage delta exceeding the configured limit ->
    //    the existing killContainer() sequence (kill then rm), status
    //    stopped_disk_limit, exit_code null (research.md R2).
    // -----------------------------------------------------------------

    #[Test]
    public function a_disk_usage_delta_exceeding_the_configured_limit_kills_the_container_and_reports_stopped_disk_limit(): void
    {
        // Forcing the coarse-cadence accumulator to fire on the very
        // first poll tick -- this test is only about proving the disk
        // check exists and fires correctly, not about the exact cadence
        // interval, which stays whatever the progress-heartbeat cadence
        // already is.
        config(['llm-client.coding_agent.command_progress_broadcast_after_seconds' => 0]);

        $calls = [];
        $duCallCount = 0;

        $factory = function (array $command) use (&$calls, &$duCallCount) {
            $calls[] = $command;

            if ($command[0] === 'du') {
                $duCallCount++;

                // First du call is the pre-run baseline (0 bytes already
                // present); every call after that simulates 20MB having
                // been written since -- comfortably past the 5MB limit
                // this test configures below.
                $bytes = $duCallCount === 1 ? 0 : 20 * 1024 * 1024;

                return $this->fakeDuProcess($bytes);
            }

            if ($command[1] === 'version') {
                return $this->fakeDockerVersionProcess();
            }

            if ($command[1] === 'kill' || $command[1] === 'rm') {
                return $this->fakeKillOrRmProcess();
            }

            // The main "docker run" invocation -- stays "running" across
            // several poll ticks so the disk check has a genuine chance
            // to fire before any natural process exit could be reached.
            // checkTimeout() never throws in this test -- the whole point
            // is proving the DISK check independently, with no timeout
            // condition anywhere near being met.
            $process = Mockery::mock(Process::class)->shouldIgnoreMissing();
            $process->shouldReceive('start')->andReturnNull();
            $process->shouldReceive('isRunning')->andReturn(true, true, true, false);
            $process->shouldReceive('checkTimeout')->andReturnNull();
            $process->shouldReceive('getIncrementalOutput')->andReturn('before the breach', '', '', '');
            $process->shouldReceive('getIncrementalErrorOutput')->andReturn('', '', '', '');
            $process->shouldReceive('getExitCode')->andReturn(0);

            return $process;
        };

        $executor = new DockerCommandExecutor($factory);

        $result = $executor->run(
            '/srv/workspaces/proj-1',
            'dd if=/dev/zero of=big.bin bs=1M count=50',
            null,
            null,
            false,
            null,
            null,
            null,
            null,
            null,
            5, // diskLimitMb
        );

        $this->assertSame('stopped_disk_limit', $result['status']);
        $this->assertNull($result['exit_code'], 'a proactively-killed disk-limit stop has no exit code of its own to report, matching stopped_timeout\'s existing convention');
        $this->assertFalse($result['timed_out'] ?? false);
        $this->assertIsInt($result['duration_ms']);

        $this->assertGreaterThanOrEqual(2, $duCallCount, 'at least a baseline du call and one re-measurement du call must have been made');

        $subcommands = array_map(fn ($c) => $c[0] === 'du' ? 'du' : ($c[1] ?? null), $calls);
        $this->assertContains('kill', $subcommands, 'the existing killContainer() sequence must have been used to stop the container');
        $this->assertContains('rm', $subcommands, 'the existing killContainer() sequence must have been used to stop the container');
    }

    // -----------------------------------------------------------------
    // 2. checkTimeout() is evaluated BEFORE the disk check on each tick --
    //    a simulated timeout wins over a simulated, later-checked disk
    //    breach on the same invocation (research.md R3c's fixed order:
    //    timeout, then disk, then pids).
    // -----------------------------------------------------------------

    #[Test]
    public function check_timeout_is_evaluated_before_the_disk_check_on_each_tick_so_a_simulated_timeout_wins(): void
    {
        // Maximally eager disk-check cadence -- if the disk check were
        // (incorrectly) evaluated ahead of checkTimeout(), this config
        // would make that bug immediately visible; the fact that the
        // test still expects stopped_timeout, never stopped_disk_limit,
        // is exactly the point.
        config(['llm-client.coding_agent.command_progress_broadcast_after_seconds' => 0]);

        $calls = [];
        $duCallCount = 0;
        $mainProcessRef = null;

        $factory = function (array $command) use (&$calls, &$duCallCount, &$mainProcessRef) {
            $calls[] = $command;

            if ($command[0] === 'du') {
                $duCallCount++;

                // Every du call after the baseline reports a huge delta
                // (999MB) -- if the disk check were ever actually reached
                // on the same tick the timeout fires, this would
                // (wrongly) win. It must never be read on that tick.
                $bytes = $duCallCount === 1 ? 0 : 999 * 1024 * 1024;

                return $this->fakeDuProcess($bytes);
            }

            if ($command[1] === 'version') {
                return $this->fakeDockerVersionProcess();
            }

            if ($command[1] === 'kill' || $command[1] === 'rm') {
                return $this->fakeKillOrRmProcess();
            }

            $process = Mockery::mock(Process::class)->shouldIgnoreMissing();
            $process->shouldReceive('start')->andReturnNull();
            $process->shouldReceive('isRunning')->andReturn(true, true);
            $process->shouldReceive('getIncrementalOutput')->andReturn('', '');
            $process->shouldReceive('getIncrementalErrorOutput')->andReturn('', '');
            // checkTimeout() throws on its very first call -- simulating
            // a timeout condition met on the very first tick, strictly
            // before any coarse-cadence disk re-check on that same tick
            // could ever be reached.
            $process->shouldReceive('checkTimeout')->andReturnUsing(function () use (&$mainProcessRef) {
                throw new ProcessTimedOutException($mainProcessRef, ProcessTimedOutException::TYPE_GENERAL);
            });

            $mainProcessRef = $process;

            return $process;
        };

        $executor = new DockerCommandExecutor($factory);

        $result = $executor->run(
            '/srv/workspaces/proj-1',
            'sleep 300',
            null,
            null,
            false,
            null,
            null,
            null,
            null,
            null,
            5, // diskLimitMb -- would be breached if the disk check were ever consulted
        );

        $this->assertSame('stopped_timeout', $result['status'], 'checkTimeout() must be consulted before the disk check on each tick -- a simulated timeout must win over a simulated, later-checked disk breach');
        $this->assertTrue($result['timed_out'] ?? false);

        $this->assertSame(1, $duCallCount, 'only the pre-run baseline du call may have happened -- the in-loop, breach-simulating du re-check must never be reached once checkTimeout() has already thrown on that same tick');
    }
}
