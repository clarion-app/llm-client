<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use ClarionApp\LlmClient\ValueObjects\TraceExportConfig;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * User Story 4 (Phase 6), US4 AC1/FR-005/FR-027/SC-011: switching the
 * destination selection at any point must never discard or hide a
 * previously-retained internal record, and enabling external forwarding on
 * an installation with pre-existing internal history must not trigger any
 * bulk backfill of that history into the forwarding queue.
 *
 * Both cases in this file are expected to pass today: nothing in
 * RunTraceRecorder ever deletes or hides an agent_runs/agent_run_steps/
 * agent_run_actions row because of a destination-selection change (only
 * PurgeExpiredRunTracesCommand's retention window removes rows, and this
 * file never invokes it), and enqueueForwarding() only ever runs from
 * closeRun() at the moment a run closes -- there is no scan/backfill
 * process anywhere in this feature that could retroactively enqueue a run
 * that closed before 'external' was selected.
 */
class TraceExportDestinationSwitchTest extends TestCase
{
    private const ENDPOINT = 'https://tempo.example.com:4318/v1/traces';

    protected function setUp(): void
    {
        parent::setUp();

        TraceExportConfig::reset();

        $this->app['config']->set('llm-client.run_trace.enabled', true);
        $this->app['config']->set('llm-client.run_trace.export.destinations', ['internal']);
        $this->app['config']->set('llm-client.run_trace.export.otlp_auth_header', 'Authorization');
        $this->app['config']->set('llm-client.run_trace.export.otlp_auth_value', 'Bearer test-token');
        $this->app['config']->set('llm-client.run_trace.export.buffer_max_records', 10000);
        $this->app['config']->set('llm-client.run_trace.export.max_attempts', 3);
        $this->app['config']->set('llm-client.run_trace.export.retry_base_seconds', 30);
        $this->app['config']->set('llm-client.run_trace.export.retry_max_seconds', 900);
        $this->app['config']->set('llm-client.run_trace.export.http_timeout_seconds', 10);
        $this->app['config']->set('llm-client.run_trace.export.max_records_per_run', 100);
        $this->app['config']->set('llm-client.run_trace.export.max_payload_bytes', 65536);

        // No real network call should ever happen in this file, whichever
        // destination is selected at a given point.
        Http::fake([
            self::ENDPOINT => Http::response('', 200),
        ]);
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

    private function closeOneRun(RunTraceRecorder $recorder): string
    {
        $userId = (string) Str::uuid();
        $runId = $recorder->openRun(RunKind::Interactive, $userId);
        $this->assertNotNull($runId);

        $stepId = $recorder->openStep($runId);
        $this->assertNotNull($stepId);

        $recorder->closeStep($stepId, RunEndState::Completed);
        $recorder->closeRun($runId, RunEndState::Completed);

        return $runId;
    }

    private function assertRunReadable(string $runId): void
    {
        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertNotNull($run, "run {$runId} should remain present and readable");
        $this->assertSame(RunEndState::Completed->value, $run->end_state);
        $this->assertNotNull($run->ended_at);
    }

    // ========== US4 AC1/FR-005/SC-011: every switch preserves every previously-retained record ==========

    #[Test]
    public function switching_destinations_back_and_forth_never_loses_a_previously_retained_internal_record(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);

        // --- internal only ---
        $this->app['config']->set('llm-client.run_trace.export.destinations', ['internal']);
        TraceExportConfig::reset();

        $runA = $this->closeOneRun($recorder);
        $this->assertRunReadable($runA);
        $this->assertSame(0, DB::table('agent_run_export_queue')->count(), 'internal-only must not enqueue anything');

        // --- switch to internal + external ---
        $this->app['config']->set('llm-client.run_trace.export.destinations', ['internal', 'external']);
        $this->app['config']->set('llm-client.run_trace.export.otlp_endpoint', self::ENDPOINT);
        TraceExportConfig::reset();

        // FR-005: the switch itself must not touch runA.
        $this->assertRunReadable($runA);

        // FR-027: no bulk backfill -- runA closed before 'external' was
        // selected, so it must never be enqueued, even now that 'external'
        // is active.
        $this->assertSame(
            0,
            DB::table('agent_run_export_queue')->where('run_id', $runA)->count(),
            'enabling external forwarding must not retroactively enqueue a run that closed before it was enabled',
        );

        $runB = $this->closeOneRun($recorder);
        $this->assertRunReadable($runA);
        $this->assertRunReadable($runB);
        $this->assertSame(1, DB::table('agent_run_export_queue')->where('run_id', $runB)->count(), 'runB closed with external selected, so it must be enqueued');
        $this->assertSame(0, DB::table('agent_run_export_queue')->where('run_id', $runA)->count());

        // --- switch to external only ---
        $this->app['config']->set('llm-client.run_trace.export.destinations', ['external']);
        TraceExportConfig::reset();

        $this->assertRunReadable($runA);
        $this->assertRunReadable($runB);

        $runC = $this->closeOneRun($recorder);
        $this->assertRunReadable($runA);
        $this->assertRunReadable($runB);
        $this->assertRunReadable($runC);
        $this->assertSame(1, DB::table('agent_run_export_queue')->where('run_id', $runC)->count());

        // --- switch back to internal only ---
        $this->app['config']->set('llm-client.run_trace.export.destinations', ['internal']);
        TraceExportConfig::reset();

        $this->assertRunReadable($runA);
        $this->assertRunReadable($runB);
        $this->assertRunReadable($runC);

        $runD = $this->closeOneRun($recorder);
        $this->assertRunReadable($runA);
        $this->assertRunReadable($runB);
        $this->assertRunReadable($runC);
        $this->assertRunReadable($runD);
        $this->assertSame(0, DB::table('agent_run_export_queue')->where('run_id', $runD)->count(), 'back to internal-only must not enqueue anything for runD');
    }

    // ========== FR-027: enabling external on pre-existing internal history enqueues nothing for the past ==========

    #[Test]
    public function enabling_external_on_an_installation_with_pre_existing_internal_history_enqueues_nothing_for_past_runs(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);

        $this->app['config']->set('llm-client.run_trace.export.destinations', ['internal']);
        TraceExportConfig::reset();

        $preExistingRunIds = [];
        for ($i = 0; $i < 5; $i++) {
            $preExistingRunIds[] = $this->closeOneRun($recorder);
        }

        $this->assertSame(0, DB::table('agent_run_export_queue')->count());

        // Enable external on an installation that already has months
        // (here: 5 runs' worth) of internal history.
        $this->app['config']->set('llm-client.run_trace.export.destinations', ['internal', 'external']);
        $this->app['config']->set('llm-client.run_trace.export.otlp_endpoint', self::ENDPOINT);
        TraceExportConfig::reset();

        // The switch itself is a config change only -- there is no
        // scan/backfill process anywhere in this feature, so it produces no
        // queue rows on its own.
        $this->assertSame(0, DB::table('agent_run_export_queue')->count());
        foreach ($preExistingRunIds as $id) {
            $this->assertSame(0, DB::table('agent_run_export_queue')->where('run_id', $id)->count());
            $this->assertRunReadable($id);
        }

        // Running the delivery command confirms the same thing operationally:
        // it only drains agent_run_export_queue, which is empty, so it makes
        // no HTTP attempt and every pre-existing run remains untouched.
        $exitCode = Artisan::call('llm-client:forward-run-traces');
        $this->assertSame(0, $exitCode);
        Http::assertNothingSent();

        foreach ($preExistingRunIds as $id) {
            $this->assertRunReadable($id);
        }

        // Only a run that closes *after* external was enabled is enqueued.
        $newRunId = $this->closeOneRun($recorder);
        $this->assertSame(1, DB::table('agent_run_export_queue')->where('run_id', $newRunId)->count());
        foreach ($preExistingRunIds as $id) {
            $this->assertSame(0, DB::table('agent_run_export_queue')->where('run_id', $id)->count());
        }
    }
}
