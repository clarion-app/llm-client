<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use ClarionApp\LlmClient\ValueObjects\TraceExportConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-015/FR-016, SC-004/SC-005: a dead or pathologically slow destination
 * must have zero effect on the request/response hot path. Network delivery
 * lives exclusively in the scheduled ForwardRunTracesCommand
 * (research.md §3/§4) -- RunTraceRecorder::closeRun() only ever inserts a
 * local queue row via enqueueForwarding(), never an HTTP call.
 *
 * The assertion in every case here is "zero Http facade calls happened
 * during closeRun()," not merely "it returned quickly" -- a fast method that
 * happened to make (and complete) a call would not satisfy FR-015/FR-016 in
 * general, it would just be a coincidence of a fast mock. Since closeRun()
 * never calls Http in the current implementation either (delivery happens
 * only in ForwardRunTracesCommand), this file may legitimately pass green
 * today -- that is expected and still valuable as regression coverage
 * against a future change that accidentally moves delivery onto this path.
 */
class ForwardingHotPathLatencyTest extends TestCase
{
    private const ENDPOINT = 'https://tempo.example.com:4318/v1/traces';

    protected function setUp(): void
    {
        parent::setUp();

        TraceExportConfig::reset();

        $this->app['config']->set('llm-client.run_trace.enabled', true);
        $this->app['config']->set('llm-client.run_trace.export.destinations', ['internal', 'external']);
        $this->app['config']->set('llm-client.run_trace.export.otlp_endpoint', self::ENDPOINT);
    }

    protected function tearDown(): void
    {
        TraceExportConfig::reset();

        foreach (['agent_run_export_queue', 'agent_run_actions', 'agent_run_steps', 'agent_run_messages', 'agent_runs'] as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function close_run_makes_no_http_call_when_the_destination_refuses_the_connection(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $recorder = $this->app->make(RunTraceRecorder::class);

        $userId = (string) Str::uuid();
        $runId = $recorder->openRun(RunKind::Interactive, $userId);
        $this->assertNotNull($runId);

        $stepId = $recorder->openStep($runId);
        $this->assertNotNull($stepId);
        $recorder->closeStep($stepId, RunEndState::Completed);

        $start = microtime(true);
        $recorder->closeRun($runId, RunEndState::Completed);
        $elapsedMs = (microtime(true) - $start) * 1000;

        // Zero Http calls -- the connection-refusing closure was never
        // invoked, because closeRun() never touches the Http facade at all.
        Http::assertNothingSent();

        // closeRun()'s own effect on the record is unaffected by the
        // destination being unreachable -- the run still closed normally.
        $closed = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertNotNull($closed);
        $this->assertSame(RunEndState::Completed->value, $closed->end_state);

        // A generous ceiling: the point is "no network wait happened," not a
        // tight latency budget. 1 second is far more than a handful of local
        // SQLite writes and comfortably below any real network timeout, so a
        // regression that routed delivery through this path (and hung on a
        // refused connection) would still trip this even with slack to spare.
        $this->assertLessThan(1000.0, $elapsedMs, 'closeRun() should not have waited on any network call');
    }

    #[Test]
    public function close_run_makes_no_http_call_even_when_the_destination_would_stall_past_the_configured_timeout(): void
    {
        // A short configured http_timeout_seconds -- if delivery ran on this
        // path, this is the ceiling it would be bound by. The fake below
        // sleeps well past it, so if closeRun() ever called Http, this test
        // would take over a second to run; it must not, because the network
        // call belongs exclusively to the scheduled command.
        $this->app['config']->set('llm-client.run_trace.export.http_timeout_seconds', 1);

        $stalledCallDetected = false;
        Http::fake(function () use (&$stalledCallDetected) {
            $stalledCallDetected = true;
            usleep(1_500_000); // 1.5s -- longer than the 1s configured timeout.

            return Http::response('', 200);
        });

        $recorder = $this->app->make(RunTraceRecorder::class);

        $userId = (string) Str::uuid();
        $runId = $recorder->openRun(RunKind::Interactive, $userId);
        $this->assertNotNull($runId);

        $stepId = $recorder->openStep($runId);
        $this->assertNotNull($stepId);
        $recorder->closeStep($stepId, RunEndState::Completed);

        $start = microtime(true);
        $recorder->closeRun($runId, RunEndState::Completed);
        $elapsedMs = (microtime(true) - $start) * 1000;

        Http::assertNothingSent();
        $this->assertFalse($stalledCallDetected, 'the slow-destination fake must never have been invoked during closeRun()');

        $closed = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertNotNull($closed);
        $this->assertSame(RunEndState::Completed->value, $closed->end_state);

        $this->assertLessThan(1000.0, $elapsedMs, 'closeRun() should not have waited on any network call, let alone one slower than the configured timeout');
    }
}
