<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ActionOutcome;
use ClarionApp\LlmClient\ValueObjects\ActionType;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use ClarionApp\LlmClient\ValueObjects\TraceExportConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * User Story 4 (Phase 6), `destinations=internal,external`: both destinations
 * run at once, and a failure at either one must never affect the other, in
 * *either* direction (FR-007, spec.md US4 Acceptance Scenarios 2-4).
 *
 * (a) external unreachable -> the internal record is still written
 *     completely (US4 AC3).
 * (b) external reachable -> the delivered payload's clarion.run_id matches
 *     the internally-stored run's own id (US4 AC2, FR-006).
 * (c) the internal agent_runs terminal UPDATE itself fails -> the queue row
 *     must still be enqueued and closeRun()'s caller must be unaffected
 *     (US4 AC4, FR-007's *reverse* direction).
 *
 * Case (c) is expected to fail today: RunTraceRecorder::closeRun() calls
 * enqueueForwarding() *after* its terminal agent_runs UPDATE, with no
 * isolation between the two (see the method's current source) -- so a throw
 * in the UPDATE reaches closeRun()'s outer catch before enqueueForwarding()
 * ever runs, and no queue row is inserted. That decoupling is T031, not yet
 * implemented as of this file landing (T029). Cases (a) and (b) exercise
 * already-correct behavior and are expected to pass today.
 */
class BothDestinationsJourneyTest extends TestCase
{
    private const ENDPOINT = 'https://tempo.example.com:4318/v1/traces';

    protected function setUp(): void
    {
        parent::setUp();

        TraceExportConfig::reset();

        $this->app['config']->set('llm-client.run_trace.enabled', true);
        $this->app['config']->set('llm-client.run_trace.export.destinations', ['internal', 'external']);
        $this->app['config']->set('llm-client.run_trace.export.otlp_endpoint', self::ENDPOINT);
        $this->app['config']->set('llm-client.run_trace.export.otlp_auth_header', 'Authorization');
        $this->app['config']->set('llm-client.run_trace.export.otlp_auth_value', 'Bearer test-token');
        $this->app['config']->set('llm-client.run_trace.export.buffer_max_records', 10000);
        $this->app['config']->set('llm-client.run_trace.export.max_attempts', 3);
        $this->app['config']->set('llm-client.run_trace.export.retry_base_seconds', 30);
        $this->app['config']->set('llm-client.run_trace.export.retry_max_seconds', 900);
        $this->app['config']->set('llm-client.run_trace.export.http_timeout_seconds', 10);
        $this->app['config']->set('llm-client.run_trace.export.max_records_per_run', 100);
        $this->app['config']->set('llm-client.run_trace.export.max_payload_bytes', 65536);
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

    /**
     * Runs a full recorder journey (run -> step -> action) and closes it,
     * returning [$runId, $stepId, $actionId].
     */
    private function runFullJourney(RunTraceRecorder $recorder): array
    {
        $userId = (string) Str::uuid();
        $runId = $recorder->openRun(RunKind::Interactive, $userId);
        $this->assertNotNull($runId);

        $stepId = $recorder->openStep($runId);
        $this->assertNotNull($stepId);

        $actionId = $recorder->openAction($stepId, ActionType::ToolInvocation, 'some.tool');
        $this->assertNotNull($actionId);
        $recorder->closeAction($actionId, ActionOutcome::Success, null, 'tool result');

        $recorder->closeStep($stepId, RunEndState::Completed);
        $recorder->closeRun($runId, RunEndState::Completed);

        return [$runId, $stepId, $actionId];
    }

    // ========== (a) external unreachable -> internal record written completely (US4 AC3, FR-007) ==========

    #[Test]
    public function with_the_external_destination_unreachable_the_internal_record_is_still_written_completely(): void
    {
        // The recorder itself never touches Http (delivery lives only in the
        // scheduled command) -- so the recorder journey below succeeds
        // regardless of the destination's health. Faking a hard connection
        // failure here proves the *whole* journey (run/step/action, all the
        // way to a terminal state) is unaffected by it, and additionally
        // that attempting delivery via the scheduled command against that
        // same unreachable destination does not touch the internal rows.
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $recorder = $this->app->make(RunTraceRecorder::class);
        [$runId, $stepId, $actionId] = $this->runFullJourney($recorder);

        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertNotNull($run);
        $this->assertSame(RunEndState::Completed->value, $run->end_state);
        $this->assertNotNull($run->ended_at);
        $this->assertNotNull($run->duration_ms);
        $this->assertSame(1, $run->step_count);

        $step = DB::table('agent_run_steps')->where('id', $stepId)->first();
        $this->assertNotNull($step);
        $this->assertSame(RunEndState::Completed->value, $step->end_state);
        $this->assertNotNull($step->ended_at);

        $action = DB::table('agent_run_actions')->where('id', $actionId)->first();
        $this->assertNotNull($action);
        $this->assertSame(ActionOutcome::Success->value, $action->outcome);
        $this->assertNotNull($action->ended_at);
        $this->assertSame('tool result', $action->content);

        // Now let the scheduled command actually attempt delivery against
        // the unreachable destination -- the internal rows above must be
        // completely untouched by that failed attempt.
        $exitCode = Artisan::call('llm-client:forward-run-traces');
        $this->assertSame(0, $exitCode);

        $runAfterDeliveryAttempt = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertNotNull($runAfterDeliveryAttempt);
        $this->assertSame(RunEndState::Completed->value, $runAfterDeliveryAttempt->end_state);
        $this->assertEquals($run, $runAfterDeliveryAttempt, 'a failed forwarding attempt must never mutate the internal record');
    }

    // ========== (b) external reachable -> delivered clarion.run_id matches the internally-stored run's own id (US4 AC2, FR-006) ==========

    #[Test]
    public function with_the_external_destination_reachable_the_delivered_payload_correlates_to_the_internally_stored_run(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response('', 200),
        ]);

        $recorder = $this->app->make(RunTraceRecorder::class);
        [$runId] = $this->runFullJourney($recorder);

        $storedRun = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertNotNull($storedRun, 'the internal record must exist so it can be correlated against');
        $this->assertSame($runId, $storedRun->id);

        $this->assertSame(
            1,
            DB::table('agent_run_export_queue')->where('run_id', $runId)->count(),
            'closeRun() should have enqueued exactly one forwarding row for this run',
        );

        $exitCode = Artisan::call('llm-client:forward-run-traces');
        $this->assertSame(0, $exitCode);

        Http::assertSent(function ($request) use ($storedRun) {
            $body = $request->data();
            foreach ($body['resourceSpans'][0]['scopeSpans'][0]['spans'] as $span) {
                foreach ($span['attributes'] ?? [] as $attribute) {
                    if ($attribute['key'] === 'clarion.run_id') {
                        // The delivered clarion.run_id must match the run's
                        // own id as stored internally -- not a re-derived or
                        // copied value -- so an operator can move from the
                        // destination back to the same record in-product.
                        return ($attribute['value']['stringValue'] ?? null) === $storedRun->id;
                    }
                }
            }

            return false;
        });

        // Delivered -- the queue row is gone because it succeeded, and the
        // internal record it was delivered from is still present.
        $this->assertSame(0, DB::table('agent_run_export_queue')->where('run_id', $runId)->count());
        $this->assertNotNull(DB::table('agent_runs')->where('id', $runId)->first());
    }

    // ========== (c) internal agent_runs terminal UPDATE fails -> queue row still enqueued, caller unaffected (US4 AC4, FR-007 reverse) ==========

    /**
     * Fault-injects a failure into exactly RunTraceRecorder::closeRun()'s
     * terminal `agent_runs` UPDATE, using Connection::beforeExecuting()
     * (fires with the raw SQL immediately before execution, and a throw
     * from it aborts that one statement without touching any other query on
     * the same connection). Matched narrowly: an UPDATE whose SQL mentions
     * `agent_runs` -- closeRun()'s only UPDATE against that table is the
     * terminal one this case targets; the earlier SELECTs against
     * `agent_runs` inside closeRun() (the terminal-state check, `started_at`
     * lookup) start with `select`, not `update`, so they pass through
     * untouched, and no other table name contains `agent_runs` as a
     * substring (`agent_run_steps`, `agent_run_actions`,
     * `agent_run_export_queue` all diverge right after `agent_run`).
     */
    private function breakAgentRunsTerminalUpdate(): void
    {
        DB::connection()->beforeExecuting(function ($query, $bindings, $connection) {
            if (stripos($query, 'update') === 0 && stripos($query, 'agent_runs') !== false) {
                throw new \RuntimeException('simulated agent_runs terminal UPDATE failure');
            }
        });
    }

    #[Test]
    public function when_the_internal_terminal_update_fails_the_export_queue_row_is_still_enqueued_and_the_caller_is_unaffected(): void
    {
        // No delivery happens in this case -- only closeRun()'s local
        // enqueue behavior under fault injection is under test. Faked
        // anyway so an accidental real network call fails loud.
        Http::fake();

        $recorder = $this->app->make(RunTraceRecorder::class);

        $userId = (string) Str::uuid();
        $runId = $recorder->openRun(RunKind::Interactive, $userId);
        $this->assertNotNull($runId);

        $stepId = $recorder->openStep($runId);
        $this->assertNotNull($stepId);
        $recorder->closeStep($stepId, RunEndState::Completed);

        // Fault-inject *after* setup so only closeRun()'s own terminal
        // UPDATE is affected -- everything above this line must succeed
        // normally.
        $this->breakAgentRunsTerminalUpdate();

        // closeRun() must never let this exception escape to its caller --
        // its own outer try/catch already guarantees that today.
        $recorder->closeRun($runId, RunEndState::Completed);

        // FR-007's *reverse* direction: a failure in the internal write must
        // not prevent the run from being enqueued for external forwarding.
        // This is the assertion expected to be red today -- enqueueForwarding()
        // is called *after* the terminal UPDATE in the current closeRun()
        // implementation, so a throw from that UPDATE reaches the outer
        // catch before enqueueForwarding() ever runs, and this queue row is
        // never inserted.
        $this->assertSame(
            1,
            DB::table('agent_run_export_queue')->where('run_id', $runId)->count(),
            'a failure in the internal agent_runs UPDATE must not prevent enqueueForwarding() from running (FR-007, reverse direction)',
        );
    }
}
