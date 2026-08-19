<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\LlmClient\Events\CommandExecutionProgress;
use ClarionApp\LlmClient\Services\DockerCommandExecutor;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * 123-sandboxed-shell-execution, US3, T034 (data-model.md §4, research.md
 * D2, FR-013). Proves DockerCommandExecutor fires a CommandExecutionProgress
 * "still running" heartbeat once a command it considers still-running has
 * crossed the configured broadcast threshold -- entirely against a mocked
 * process boundary, no real Docker, no real subscriber. Mirrors the
 * broadcastOn()-inspection assertion style already established for
 * RunActionUpdated by tests/Integration/RunLiveUpdateNonLeakTest.php.
 *
 * Written before CommandExecutionProgress/the executor's polling wiring
 * exist -- expected to FAIL red (class not found, then no event ever
 * dispatched) until T038 lands.
 */
class CommandExecutionProgressBroadcastTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    #[Test]
    public function a_command_whose_mocked_duration_exceeds_the_broadcast_threshold_dispatches_a_progress_event_on_the_acting_users_channel(): void
    {
        Event::fake([CommandExecutionProgress::class]);

        // A threshold of 0 means "past the threshold" is true from the
        // very first poll tick -- this test proves the wiring fires at
        // all, without needing a genuine multi-second sleep.
        config(['llm-client.coding_agent.command_progress_broadcast_after_seconds' => 0]);

        $codingProjectId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        $factory = function (array $command) {
            if ($command[1] === 'version') {
                $process = Mockery::mock(Process::class)->shouldIgnoreMissing();
                $process->shouldReceive('run')->andReturn(0);
                $process->shouldReceive('getExitCode')->andReturn(0);

                return $process;
            }

            $process = Mockery::mock(Process::class)->shouldIgnoreMissing();
            $process->shouldReceive('start')->andReturnNull();
            $process->shouldReceive('isRunning')->andReturn(true, false);
            $process->shouldReceive('checkTimeout')->andReturnNull();
            $process->shouldReceive('getIncrementalOutput')->andReturn('', '');
            $process->shouldReceive('getIncrementalErrorOutput')->andReturn('', '');
            $process->shouldReceive('getExitCode')->andReturn(0);

            return $process;
        };

        $executor = new DockerCommandExecutor($factory);
        $result = $executor->run(sys_get_temp_dir(), 'a-long-running-command', $codingProjectId, $userId);

        $this->assertSame('completed', $result['status']);

        $expectedChannel = 'private-User.'.$userId;

        Event::assertDispatched(CommandExecutionProgress::class, function (CommandExecutionProgress $event) use ($codingProjectId, $userId, $expectedChannel) {
            if ($event->codingProjectId !== $codingProjectId || $event->userId !== $userId) {
                return false;
            }

            $names = array_map(fn ($c) => (string) $c, $event->broadcastOn());

            return $names === [$expectedChannel];
        });
    }

    #[Test]
    public function no_progress_event_is_dispatched_when_the_coding_project_id_or_user_id_is_unknown(): void
    {
        // A DockerCommandExecutor invocation with no attribution (e.g. a
        // future call site that never passes one) must never fire an
        // unaddressable broadcast -- broadcastProgress() silently skips
        // rather than emitting an event with a null channel target.
        Event::fake([CommandExecutionProgress::class]);

        config(['llm-client.coding_agent.command_progress_broadcast_after_seconds' => 0]);

        $factory = function (array $command) {
            if ($command[1] === 'version') {
                $process = Mockery::mock(Process::class)->shouldIgnoreMissing();
                $process->shouldReceive('run')->andReturn(0);
                $process->shouldReceive('getExitCode')->andReturn(0);

                return $process;
            }

            $process = Mockery::mock(Process::class)->shouldIgnoreMissing();
            $process->shouldReceive('start')->andReturnNull();
            $process->shouldReceive('isRunning')->andReturn(true, false);
            $process->shouldReceive('checkTimeout')->andReturnNull();
            $process->shouldReceive('getIncrementalOutput')->andReturn('', '');
            $process->shouldReceive('getIncrementalErrorOutput')->andReturn('', '');
            $process->shouldReceive('getExitCode')->andReturn(0);

            return $process;
        };

        $executor = new DockerCommandExecutor($factory);
        $executor->run(sys_get_temp_dir(), 'echo hi');

        Event::assertNotDispatched(CommandExecutionProgress::class);
    }
}
