<?php

namespace Tests\Feature\Resume;

use ClarionApp\LlmClient\Jobs\ResumeInterruptedRunJob;
use ClarionApp\LlmClient\Services\RunResumer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ResumeInterruptedRunJob — the re-drive of a detected interruption
 * (lease contract §3).
 *
 * `$tries = 1` (a retry would be a second, uncounted resume), the timeout
 * is the stream job timeout (a long step is not killed by the worker's
 * 60 s default), a lost claim writes nothing besides a debug log, and a
 * resumer that throws closes the run `failed` — never left `resuming` —
 * without rethrowing.
 */
class ResumeInterruptedRunJobTest extends TestCase
{
    private string $userId = '11111111-1111-1111-1111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        config(['llm-client.resume.enabled' => true]);

        $this->app['config']->set('logging.channels.testing', [
            'driver' => 'monolog',
            'handler' => TestHandler::class,
            'level' => 'debug',
        ]);
        $this->app['config']->set('logging.default', 'testing');
    }

    /** Insert a resumable in-progress run row. */
    private function makeRun(string $id, array $overrides = []): void
    {
        DB::table('agent_runs')->insert(array_merge([
            'id' => $id,
            'kind' => 'interactive',
            'user_id' => $this->userId,
            'conversation_id' => 'conv-1',
            'started_at' => now()->subMinutes(10)->format('Y-m-d H:i:s.u'),
            'end_state' => 'in_progress',
            'checkpoint_version' => 1,
            'resume_mode' => 'sync',
            'resume_driver' => 'root',
            'resume_state' => 'working',
            'dispatch_id' => '10000000-0000-0000-0000-000000000001',
            'lease_holder' => '99999999-9999-9999-9999-999999999999',
            'lease_expires_at' => now()->addMinutes(5)->format('Y-m-d H:i:s.u'),
            'consecutive_interruptions' => 0,
        ], $overrides));
    }

    /** @return object the run row */
    private function runRow(string $id): object
    {
        $row = DB::table('agent_runs')->where('id', $id)->first();
        $this->assertNotNull($row, "run {$id} must exist");

        return $row;
    }

    /** @return \Monolog\LogRecord[] */
    private function capturedLogRecords(): array
    {
        $logger = Log::channel('testing')->getLogger();

        foreach ($logger->getHandlers() as $handler) {
            if ($handler instanceof TestHandler) {
                return $handler->getRecords();
            }
        }

        return [];
    }

    // ---------------------------------------------------------------
    // Job shape
    // ---------------------------------------------------------------

    #[Test]
    public function tries_is_one_and_timeout_is_the_stream_job_timeout(): void
    {
        $job = new ResumeInterruptedRunJob('run-1', '10000000-0000-0000-0000-000000000001');

        $this->assertSame(
            1,
            $job->tries,
            'a retry would be a second, uncounted resume',
        );
        $this->assertSame(
            (int) config('llm-client.resume.stream_job_timeout_seconds', 600),
            $job->timeout,
            'a long step is not killed by the worker default',
        );
    }

    // ---------------------------------------------------------------
    // A lost claim
    // ---------------------------------------------------------------

    #[Test]
    public function a_lost_claim_writes_nothing_besides_a_debug_log(): void
    {
        // The lease is live (held by another process, not expired): the
        // claim inside the resumer loses.
        $this->makeRun('run-1');

        $job = new ResumeInterruptedRunJob('run-1', '10000000-0000-0000-0000-000000000001');
        $job->handle(app(RunResumer::class)); // returns normally — nothing went wrong

        $row = $this->runRow('run-1');
        $this->assertSame('working', $row->resume_state, 'a lost claim leaves the run untouched');
        $this->assertNull($row->interrupted_at, 'a lost claim writes no interrupted_at');
        $this->assertSame(
            0,
            DB::table('agent_run_resume_events')->where('run_id', 'run-1')->count(),
            'a lost claim writes no events',
        );

        $debugLines = array_filter(
            $this->capturedLogRecords(),
            fn ($record) => $record->level === \Monolog\Level::Debug,
        );
        $this->assertNotEmpty($debugLines, 'a lost claim leaves a debug log line');
    }

    // ---------------------------------------------------------------
    // A resumer that throws
    // ---------------------------------------------------------------

    #[Test]
    public function a_resumer_that_throws_closes_the_run_failed_and_does_not_rethrow(): void
    {
        // The sweep already claimed the run (resuming, lease released);
        // the resumer itself fails.
        $this->makeRun('run-1', [
            'resume_state' => 'resuming',
            'lease_holder' => null,
        ]);

        $job = new ResumeInterruptedRunJob('run-1', '10000000-0000-0000-0000-000000000001');

        $resumer = Mockery::mock(RunResumer::class);
        $resumer->shouldReceive('resume')->once()->andThrow(new \RuntimeException('the resumer failed'));

        $job->handle($resumer); // must not rethrow (no Laravel retry)

        $row = $this->runRow('run-1');
        $this->assertSame('failed', $row->end_state, 'the run is closed failed');
        $this->assertNotNull($row->end_reason, 'the run carries a plain report as its end reason');
        $this->assertStringContainsStringIgnoringCase('this task', $row->end_reason, 'the report names "this task"');
        $this->assertNotSame(
            'resuming',
            $row->resume_state,
            'the run is never left resuming',
        );
    }
}
