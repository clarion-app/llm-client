<?php

namespace Tests\Feature\Resume;

use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * closeRun() clears the resumable-task columns (data-model §1 invariant).
 *
 * A terminal end_state means resume_state, dispatch_id and lease_holder are
 * NULL — the run is over and nothing may still claim it. The clearing is in
 * the same terminal UPDATE, and a swallowed broadcast failure (the run's
 * RunUpdated push) must not prevent it.
 */
class RunTraceRecorderResumeClearingTest extends TestCase
{
    private string $userId = '11111111-1111-1111-1111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        config(['llm-client.run_trace.enabled' => true]);
    }

    /**
     * Insert an in-progress run row that still carries its resumable-task
     * state — the state a running (or paused) run has before it closes.
     */
    private function makeRunWithLease(string $id): void
    {
        DB::table('agent_runs')->insert([
            'id' => $id,
            'kind' => 'interactive',
            'user_id' => $this->userId,
            'started_at' => now()->format('Y-m-d H:i:s.u'),
            'end_state' => 'in_progress',
            'checkpoint_version' => 1,
            'resume_mode' => 'sync',
            'resume_driver' => 'root',
            'resume_state' => 'working',
            'dispatch_id' => '10000000-0000-0000-0000-000000000001',
            'lease_holder' => '20000000-0000-0000-0000-000000000002',
            'lease_expires_at' => now()->addSeconds(300)->format('Y-m-d H:i:s.u'),
        ]);
    }

    /** @return object the run row */
    private function runRow(string $id): object
    {
        $row = DB::table('agent_runs')->where('id', $id)->first();
        $this->assertNotNull($row, "run {$id} must exist");

        return $row;
    }

    /** @return array<string, array{0: RunEndState, 1: ?string}> */
    public static function terminalStates(): array
    {
        return [
            'completed' => [RunEndState::Completed, null],
            'failed' => [RunEndState::Failed, 'the run failed (test)'],
            'stopped_early' => [RunEndState::StoppedEarly, 'the run stopped (test)'],
            'abandoned' => [RunEndState::Abandoned, 'the run was abandoned (test)'],
        ];
    }

    #[Test]
    #[DataProvider('terminalStates')]
    public function closeRun_clears_resume_state_dispatch_and_lease(RunEndState $state, ?string $reason): void
    {
        $this->makeRunWithLease('run-1');

        app(RunTraceRecorder::class)->closeRun('run-1', $state, $reason);

        $row = $this->runRow('run-1');
        $this->assertSame($state->value, $row->end_state, 'the run is terminal');
        $this->assertNull($row->resume_state, 'resume_state is cleared in the terminal UPDATE');
        $this->assertNull($row->dispatch_id, 'dispatch_id is cleared in the terminal UPDATE');
        $this->assertNull($row->lease_holder, 'lease_holder is cleared in the terminal UPDATE');
    }

    #[Test]
    public function a_swallowed_broadcast_failure_does_not_prevent_the_clearing(): void
    {
        // The run's RunUpdated push throws; the clearing must still happen.
        $manager = Mockery::mock(\Illuminate\Contracts\Broadcasting\Factory::class);
        $manager->shouldReceive('event')->andThrow(new \RuntimeException('broadcast failed (scripted)'));
        Broadcast::swap($manager);

        $this->makeRunWithLease('run-1');

        app(RunTraceRecorder::class)->closeRun('run-1', RunEndState::Completed, null);

        $row = $this->runRow('run-1');
        $this->assertSame('completed', $row->end_state, 'the run is terminal');
        $this->assertNull($row->resume_state, 'resume_state is cleared despite the broadcast failure');
        $this->assertNull($row->dispatch_id, 'dispatch_id is cleared despite the broadcast failure');
        $this->assertNull($row->lease_holder, 'lease_holder is cleared despite the broadcast failure');
    }
}
