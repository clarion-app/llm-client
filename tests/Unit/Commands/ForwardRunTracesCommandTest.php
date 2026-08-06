<?php

namespace ClarionApp\LlmClient\Tests\Unit\Commands;

use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ActionOutcome;
use ClarionApp\LlmClient\ValueObjects\ActionType;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use ClarionApp\LlmClient\ValueObjects\TraceExportConfig;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for ForwardRunTracesCommand — the scheduled `llm-client:forward-run-traces`
 * command that drains `agent_run_export_queue` against a configured OTLP
 * destination, per contracts/cli-commands.md.
 *
 * ForwardRunTracesCommand (and its OtlpPayloadBuilder dependency) do not
 * exist yet — every test in this file is expected to fail/error until T017-T019
 * land. The one test built directly on RunTraceRecorder::closeRun() (see
 * "closes_a_run_and_delivers_it_via_the_full_enqueue_flow" below) additionally
 * depends on RunTraceRecorder::enqueueForwarding() (T018), which is also not
 * implemented yet — its own comment explains why it is written this way now.
 */
class ForwardRunTracesCommandTest extends TestCase
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

    // ========== fixture helpers ==========

    private function insertRun(
        string $id,
        string $userId,
        RunEndState $endState,
        ?string $endReason,
        CarbonImmutable $startedAt,
        CarbonImmutable $endedAt,
    ): void {
        DB::table('agent_runs')->insert([
            'id' => $id,
            'kind' => RunKind::Interactive->value,
            'user_id' => $userId,
            'conversation_id' => null,
            'source' => null,
            'end_state' => $endState->value,
            'end_reason' => $endReason,
            'started_at' => $startedAt->format('Y-m-d H:i:s.u'),
            'ended_at' => $endedAt->format('Y-m-d H:i:s.u'),
            'duration_ms' => $startedAt->diffInMilliseconds($endedAt),
            'step_count' => 1,
            'created_at' => $startedAt->format('Y-m-d H:i:s.u'),
        ]);
    }

    private function insertStep(
        string $id,
        string $runId,
        RunEndState $endState,
        ?string $endReason,
        CarbonImmutable $startedAt,
        CarbonImmutable $endedAt,
    ): void {
        DB::table('agent_run_steps')->insert([
            'id' => $id,
            'run_id' => $runId,
            'position' => 1,
            'attempt_group_id' => null,
            'end_state' => $endState->value,
            'end_reason' => $endReason,
            'started_at' => $startedAt->format('Y-m-d H:i:s.u'),
            'ended_at' => $endedAt->format('Y-m-d H:i:s.u'),
            'duration_ms' => $startedAt->diffInMilliseconds($endedAt),
            'wait_ms' => null,
            'attempt_count' => 1,
        ]);
    }

    private function insertAction(
        string $id,
        string $stepId,
        string $runId,
        ActionOutcome $outcome,
        ?string $failureReason,
        CarbonImmutable $startedAt,
        CarbonImmutable $endedAt,
    ): void {
        DB::table('agent_run_actions')->insert([
            'id' => $id,
            'run_id' => $runId,
            'step_id' => $stepId,
            'action_type' => ActionType::ToolInvocation->value,
            'target' => 'some.tool',
            'attempt_group_id' => null,
            'parent_action_id' => null,
            'outcome' => $outcome->value,
            'failure_reason' => $failureReason,
            'paused_at' => null,
            'started_at' => $startedAt->format('Y-m-d H:i:s.u'),
            'ended_at' => $endedAt->format('Y-m-d H:i:s.u'),
            'duration_ms' => $startedAt->diffInMilliseconds($endedAt),
            'content' => null,
            'created_at' => $startedAt->format('Y-m-d H:i:s.u'),
        ]);
    }

    /**
     * Inserts a minimal but complete run (one step, one action) and its
     * matching agent_run_export_queue row, ready for the command to pick up
     * immediately (next_attempt_at NULL = due now).
     */
    private function insertRunWithQueueRow(
        RunEndState $endState = RunEndState::Completed,
        ?string $endReason = null,
        ActionOutcome $actionOutcome = ActionOutcome::Success,
        ?string $failureReason = null,
        ?CarbonImmutable $createdAt = null,
    ): array {
        $runId = (string) Str::uuid();
        $userId = (string) Str::uuid();
        $stepId = (string) Str::uuid();
        $actionId = (string) Str::uuid();
        $t0 = CarbonImmutable::now()->subMinutes(2);
        $t1 = $t0->addSeconds(5);

        $this->insertRun($runId, $userId, $endState, $endReason, $t0, $t1);
        $this->insertStep($stepId, $runId, $endState, $endReason, $t0, $t1);
        $this->insertAction($actionId, $stepId, $runId, $actionOutcome, $failureReason, $t0, $t1);

        $queueId = (string) Str::uuid();
        DB::table('agent_run_export_queue')->insert([
            'id' => $queueId,
            'run_id' => $runId,
            'attempts' => 0,
            'next_attempt_at' => null,
            'last_error' => null,
            'created_at' => ($createdAt ?? CarbonImmutable::now())->format('Y-m-d H:i:s'),
        ]);

        return [$runId, $queueId];
    }

    // ========== US2 AC1/AC3: healthy destination delivers and clears the queue row ==========

    #[Test]
    public function a_queued_run_is_delivered_to_a_healthy_destination_and_its_queue_row_is_removed(): void
    {
        [$runId, $queueId] = $this->insertRunWithQueueRow();

        Http::fake([
            self::ENDPOINT => Http::response('', 200),
        ]);

        $exitCode = Artisan::call('llm-client:forward-run-traces');

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, DB::table('agent_run_export_queue')->where('id', $queueId)->count());

        Http::assertSent(function ($request) use ($runId) {
            $body = $request->data();
            foreach ($body['resourceSpans'][0]['scopeSpans'][0]['spans'] as $span) {
                foreach ($span['attributes'] ?? [] as $attribute) {
                    if ($attribute['key'] === 'clarion.run_id') {
                        return ($attribute['value']['stringValue'] ?? null) === $runId;
                    }
                }
            }

            return false;
        });
    }

    // ========== US2: closeRun() -> enqueue -> deliver, full flow ==========

    #[Test]
    public function closes_a_run_and_delivers_it_via_the_full_enqueue_flow(): void
    {
        // This exercises RunTraceRecorder::closeRun() directly rather than
        // seeding agent_run_export_queue by hand, to prove the full
        // production hand-off (closeRun() -> enqueueForwarding() -> queue row
        // -> command -> delivery) actually works end to end, not just that
        // the command can drain a hand-seeded row.
        //
        // RunTraceRecorder::enqueueForwarding() is T018, not yet implemented
        // as of this test file landing (T016) — so this case is *expected*
        // to fail/error today for that additional reason (no queue row is
        // ever inserted, so nothing is ever delivered), on top of
        // ForwardRunTracesCommand/OtlpPayloadBuilder themselves not existing.
        // It becomes green only once T017, T018, and T019 have all landed.
        Http::fake([
            self::ENDPOINT => Http::response('', 200),
        ]);

        $recorder = $this->app->make(RunTraceRecorder::class);

        $userId = (string) Str::uuid();
        $runId = $recorder->openRun(RunKind::Interactive, $userId);
        $this->assertNotNull($runId);

        $stepId = $recorder->openStep($runId);
        $recorder->closeStep($stepId, RunEndState::Completed);
        $recorder->closeRun($runId, RunEndState::Completed);

        $this->assertSame(
            1,
            DB::table('agent_run_export_queue')->where('run_id', $runId)->count(),
            'closeRun() should have enqueued exactly one forwarding row for this run',
        );

        $exitCode = Artisan::call('llm-client:forward-run-traces');

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, DB::table('agent_run_export_queue')->where('run_id', $runId)->count());

        Http::assertSent(function ($request) use ($runId) {
            $body = $request->data();
            foreach ($body['resourceSpans'][0]['scopeSpans'][0]['spans'] as $span) {
                foreach ($span['attributes'] ?? [] as $attribute) {
                    if ($attribute['key'] === 'clarion.run_id') {
                        return ($attribute['value']['stringValue'] ?? null) === $runId;
                    }
                }
            }

            return false;
        });
    }

    // ========== US2 AC2: a failed/abandoned run is forwarded, not omitted ==========

    #[Test]
    public function a_failed_runs_forwarded_payload_reflects_the_failure_rather_than_being_omitted(): void
    {
        [$runId, $queueId] = $this->insertRunWithQueueRow(
            endState: RunEndState::Failed,
            endReason: 'provider unreachable',
            actionOutcome: ActionOutcome::Failure,
            failureReason: 'tool call failed',
        );

        Http::fake([
            self::ENDPOINT => Http::response('', 200),
        ]);

        $exitCode = Artisan::call('llm-client:forward-run-traces');

        $this->assertSame(0, $exitCode);
        // Still delivered (not skipped) — the row is gone because it was sent, not discarded unsent.
        $this->assertSame(0, DB::table('agent_run_export_queue')->where('id', $queueId)->count());

        Http::assertSent(function ($request) use ($runId) {
            $body = $request->data();
            foreach ($body['resourceSpans'][0]['scopeSpans'][0]['spans'] as $span) {
                foreach ($span['attributes'] ?? [] as $attribute) {
                    if ($attribute['key'] === 'clarion.run_id' && ($attribute['value']['stringValue'] ?? null) === $runId) {
                        // Found the run's root span — it must report ERROR (2), not OK.
                        return ($span['status']['code'] ?? null) === 2;
                    }
                }
            }

            return false;
        });
    }

    #[Test]
    public function an_abandoned_runs_forwarded_payload_reflects_the_abandonment_rather_than_being_omitted(): void
    {
        [$runId, $queueId] = $this->insertRunWithQueueRow(
            endState: RunEndState::Abandoned,
            endReason: 'no activity within abandonment window',
        );

        Http::fake([
            self::ENDPOINT => Http::response('', 200),
        ]);

        $exitCode = Artisan::call('llm-client:forward-run-traces');

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, DB::table('agent_run_export_queue')->where('id', $queueId)->count());

        Http::assertSent(function ($request) use ($runId) {
            $body = $request->data();
            foreach ($body['resourceSpans'][0]['scopeSpans'][0]['spans'] as $span) {
                foreach ($span['attributes'] ?? [] as $attribute) {
                    if ($attribute['key'] === 'clarion.run_id' && ($attribute['value']['stringValue'] ?? null) === $runId) {
                        return ($span['status']['code'] ?? null) === 2;
                    }
                }
            }

            return false;
        });
    }

    // ========== US2 AC4: missing/malformed otlp_endpoint — logged once, no per-record noise, exits SUCCESS ==========

    #[Test]
    public function a_malformed_otlp_endpoint_is_logged_exactly_once_with_no_per_record_noise_and_exits_success(): void
    {
        $this->app['config']->set('llm-client.run_trace.export.otlp_endpoint', 'not-a-url');

        // Several queued rows — if the misconfiguration were logged per
        // record rather than once at command start, this would multiply.
        $this->insertRunWithQueueRow();
        $this->insertRunWithQueueRow();
        $this->insertRunWithQueueRow();

        $warnings = [];
        Log::listen(function ($entry) use (&$warnings) {
            if ($entry->level === 'warning') {
                $warnings[] = $entry;
            }
        });

        Http::fake();

        $exitCode = Artisan::call('llm-client:forward-run-traces');

        $this->assertSame(0, $exitCode);
        $this->assertCount(1, $warnings, 'expected exactly one warning for the whole invocation, not one per record');

        // external effectively dropped (invalid endpoint) -> behaves as
        // internal-only -> no HTTP attempt is ever made.
        Http::assertNothingSent();
    }

    // ========== US2 AC5 / FR-003: forwarding disabled discards leftovers and makes no further attempts ==========

    #[Test]
    public function disabling_forwarding_deletes_leftover_queue_rows_and_makes_no_further_attempts(): void
    {
        $this->insertRunWithQueueRow();
        $this->insertRunWithQueueRow();
        $this->assertSame(2, DB::table('agent_run_export_queue')->count());

        $this->app['config']->set('llm-client.run_trace.export.destinations', ['internal']);

        Http::fake();

        $exitCode = Artisan::call('llm-client:forward-run-traces');

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, DB::table('agent_run_export_queue')->count());
        Http::assertNothingSent();
    }

    // ========== secret-handling contract: anonymous ingest omits the auth header entirely ==========

    #[Test]
    public function with_no_auth_value_configured_the_delivered_request_carries_no_auth_header_at_all(): void
    {
        $this->app['config']->set('llm-client.run_trace.export.otlp_auth_value', null);

        $this->insertRunWithQueueRow();

        Http::fake([
            self::ENDPOINT => Http::response('', 200),
        ]);

        $exitCode = Artisan::call('llm-client:forward-run-traces');

        $this->assertSame(0, $exitCode);

        Http::assertSent(function ($request) {
            return !$request->hasHeader('Authorization');
        });
    }

    #[Test]
    public function with_an_empty_string_auth_value_the_delivered_request_carries_no_auth_header_at_all(): void
    {
        $this->app['config']->set('llm-client.run_trace.export.otlp_auth_value', '');

        $this->insertRunWithQueueRow();

        Http::fake([
            self::ENDPOINT => Http::response('', 200),
        ]);

        Artisan::call('llm-client:forward-run-traces');

        Http::assertSent(function ($request) {
            return !$request->hasHeader('Authorization');
        });
    }

    #[Test]
    public function with_an_auth_value_configured_the_delivered_request_carries_the_configured_header(): void
    {
        $this->insertRunWithQueueRow();

        Http::fake([
            self::ENDPOINT => Http::response('', 200),
        ]);

        Artisan::call('llm-client:forward-run-traces');

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization')
                && $request->header('Authorization')[0] === 'Bearer test-token';
        });
    }

    // ========== design invariant: delivery never routes through the queue ==========

    #[Test]
    public function handle_never_dispatches_a_queue_job_while_delivering(): void
    {
        Queue::fake();

        $this->insertRunWithQueueRow();
        $this->insertRunWithQueueRow();

        Http::fake([
            self::ENDPOINT => Http::response('', 200),
        ]);

        $exitCode = Artisan::call('llm-client:forward-run-traces');

        $this->assertSame(0, $exitCode);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function handle_never_dispatches_a_queue_job_even_when_delivery_fails(): void
    {
        Queue::fake();

        $this->insertRunWithQueueRow();

        Http::fake([
            self::ENDPOINT => Http::response('', 503),
        ]);

        $exitCode = Artisan::call('llm-client:forward-run-traces');

        $this->assertSame(0, $exitCode);
        Queue::assertNothingPushed();
    }

    // ========== --dry-run: no HTTP call, no row mutation ==========

    #[Test]
    public function dry_run_reports_without_sending_http_or_mutating_any_row(): void
    {
        [, $queueId] = $this->insertRunWithQueueRow();

        Http::fake([
            self::ENDPOINT => Http::response('', 200),
        ]);

        $exitCode = Artisan::call('llm-client:forward-run-traces', ['--dry-run' => true]);

        $this->assertSame(0, $exitCode);
        Http::assertNothingSent();
        $this->assertSame(1, DB::table('agent_run_export_queue')->where('id', $queueId)->count());
    }

    // ========================================================================
    // Phase 5 (US3): retry/backoff, discard-on-payload-size, resume-after-
    // recovery, and aggregate delivered/retried/discarded/buffer_evicted
    // logging. None of these exist yet as of Phase 4 -- ForwardRunTracesCommand
    // never increments `attempts`, never sets `next_attempt_at`, never
    // pre-checks payload size, and its aggregate log line has no `retried` or
    // `buffer_evicted` key at all. Every case below is expected to fail/red
    // until T025/T026 land.
    // ========================================================================

    /**
     * Inserts a run whose single action carries an oversized `content`
     * field, so its built OTLP payload trivially exceeds any reasonable
     * max_payload_bytes cap -- used to exercise the payload-size precheck
     * without needing hundreds of real actions.
     */
    private function insertOversizedRunWithQueueRow(): string
    {
        $runId = (string) Str::uuid();
        $userId = (string) Str::uuid();
        $stepId = (string) Str::uuid();
        $actionId = (string) Str::uuid();
        $t0 = CarbonImmutable::now()->subMinutes(2);
        $t1 = $t0->addSeconds(5);

        $this->insertRun($runId, $userId, RunEndState::Completed, null, $t0, $t1);
        $this->insertStep($stepId, $runId, RunEndState::Completed, null, $t0, $t1);

        DB::table('agent_run_actions')->insert([
            'id' => $actionId,
            'run_id' => $runId,
            'step_id' => $stepId,
            'action_type' => ActionType::ToolInvocation->value,
            'target' => 'some.tool',
            'attempt_group_id' => null,
            'parent_action_id' => null,
            'outcome' => ActionOutcome::Success->value,
            'failure_reason' => null,
            'paused_at' => null,
            'started_at' => $t0->format('Y-m-d H:i:s.u'),
            'ended_at' => $t1->format('Y-m-d H:i:s.u'),
            'duration_ms' => 5000,
            // Comfortably past even the default 65536-byte cap once wrapped
            // in the OTLP envelope.
            'content' => str_repeat('x', 100_000),
            'created_at' => $t0->format('Y-m-d H:i:s.u'),
        ]);

        $queueId = (string) Str::uuid();
        DB::table('agent_run_export_queue')->insert([
            'id' => $queueId,
            'run_id' => $runId,
            'attempts' => 0,
            'next_attempt_at' => null,
            'last_error' => null,
            'created_at' => CarbonImmutable::now()->format('Y-m-d H:i:s'),
        ]);

        return $queueId;
    }

    private function assertNextAttemptNear(CarbonImmutable $expected, ?string $actualTimestamp, string $message = ''): void
    {
        $this->assertNotNull($actualTimestamp, $message !== '' ? $message : 'next_attempt_at must be set after a failed delivery below max_attempts');
        $actual = CarbonImmutable::parse($actualTimestamp);
        $diff = abs($actual->diffInSeconds($expected, false));
        $this->assertLessThanOrEqual(
            5,
            $diff,
            "expected next_attempt_at near {$expected->toDateTimeString()}, got {$actual->toDateTimeString()}",
        );
    }

    // ========== FR-019/SC-007: exponential-with-cap backoff, discard after max_attempts ==========

    #[Test]
    public function repeated_delivery_failures_follow_the_exponential_backoff_formula_and_the_row_is_deleted_after_max_attempts(): void
    {
        // retry_base_seconds=30, retry_max_seconds=900, max_attempts=3 (setUp defaults).
        [, $queueId] = $this->insertRunWithQueueRow();

        Http::fake([
            self::ENDPOINT => Http::response('', 503),
        ]);

        // Attempt 1: fails -> attempts=1, next_attempt_at ~= now + 30s (base * 2^0).
        $exitCode = Artisan::call('llm-client:forward-run-traces');
        $this->assertSame(0, $exitCode);

        $row = DB::table('agent_run_export_queue')->where('id', $queueId)->first();
        $this->assertNotNull($row, 'row must survive a failure below max_attempts');
        $this->assertSame(1, (int) $row->attempts);
        $this->assertNextAttemptNear(CarbonImmutable::now()->addSeconds(30), $row->next_attempt_at);

        // Simulate the backoff window having elapsed.
        DB::table('agent_run_export_queue')->where('id', $queueId)->update([
            'next_attempt_at' => CarbonImmutable::now()->subSecond()->format('Y-m-d H:i:s'),
        ]);

        // Attempt 2: fails again -> attempts=2, next_attempt_at ~= now + 60s (base * 2^1).
        $exitCode = Artisan::call('llm-client:forward-run-traces');
        $this->assertSame(0, $exitCode);

        $row = DB::table('agent_run_export_queue')->where('id', $queueId)->first();
        $this->assertNotNull($row, 'row must survive a second failure below max_attempts');
        $this->assertSame(2, (int) $row->attempts);
        $this->assertNextAttemptNear(CarbonImmutable::now()->addSeconds(60), $row->next_attempt_at);

        DB::table('agent_run_export_queue')->where('id', $queueId)->update([
            'next_attempt_at' => CarbonImmutable::now()->subSecond()->format('Y-m-d H:i:s'),
        ]);

        // Attempt 3: this reaches max_attempts (3) -- the row must be
        // deleted outright, not retried a fourth time.
        $exitCode = Artisan::call('llm-client:forward-run-traces');
        $this->assertSame(0, $exitCode);

        $this->assertSame(
            0,
            DB::table('agent_run_export_queue')->where('id', $queueId)->count(),
            'the row must be deleted once attempts reaches max_attempts, with zero further attempts',
        );

        Http::assertSentCount(3);
    }

    #[Test]
    public function backoff_delay_is_capped_at_retry_max_seconds(): void
    {
        $this->app['config']->set('llm-client.run_trace.export.max_attempts', 10);
        $this->app['config']->set('llm-client.run_trace.export.retry_base_seconds', 100);
        $this->app['config']->set('llm-client.run_trace.export.retry_max_seconds', 150);

        [, $queueId] = $this->insertRunWithQueueRow();

        Http::fake([
            self::ENDPOINT => Http::response('', 503),
        ]);

        // Attempt 1: base * 2^0 = 100s (below the 150s cap).
        Artisan::call('llm-client:forward-run-traces');
        $row = DB::table('agent_run_export_queue')->where('id', $queueId)->first();
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertNextAttemptNear(CarbonImmutable::now()->addSeconds(100), $row->next_attempt_at);

        DB::table('agent_run_export_queue')->where('id', $queueId)->update([
            'next_attempt_at' => CarbonImmutable::now()->subSecond()->format('Y-m-d H:i:s'),
        ]);

        // Attempt 2: base * 2^1 = 200s, but must be capped at retry_max_seconds = 150s.
        Artisan::call('llm-client:forward-run-traces');
        $row = DB::table('agent_run_export_queue')->where('id', $queueId)->first();
        $this->assertNotNull($row);
        $this->assertSame(2, (int) $row->attempts);
        $this->assertNextAttemptNear(CarbonImmutable::now()->addSeconds(150), $row->next_attempt_at, 'the backoff delay must be capped at retry_max_seconds, not allowed to keep doubling');
    }

    // ========== research.md §5: oversized payload discarded with no HTTP attempt ==========

    #[Test]
    public function a_payload_exceeding_max_payload_bytes_is_discarded_immediately_with_no_http_attempt(): void
    {
        $this->app['config']->set('llm-client.run_trace.export.max_payload_bytes', 1024);

        $queueId = $this->insertOversizedRunWithQueueRow();

        Http::fake();

        $exitCode = Artisan::call('llm-client:forward-run-traces');

        $this->assertSame(0, $exitCode);
        $this->assertSame(
            0,
            DB::table('agent_run_export_queue')->where('id', $queueId)->count(),
            'an oversized payload must be discarded outright, never retried',
        );
        Http::assertNothingSent();
    }

    // ========== FR-023/US3 AC6: a recovered destination resumes delivery automatically ==========

    #[Test]
    public function a_destination_that_recovers_mid_outage_resumes_delivery_on_the_next_due_tick_without_operator_action(): void
    {
        [, $queueId] = $this->insertRunWithQueueRow();

        // Outage then recovery: the first request against this endpoint
        // fails, the second succeeds. A second, separate Http::fake([...])
        // call for the same URL would not achieve this -- Laravel's fake
        // stubs accumulate and are matched in registration order, so a later
        // fake() call for an already-stubbed URL never overrides the
        // earlier one (verified directly against this package's Laravel
        // version). Http::sequence() is the correct tool for "this URL
        // responds differently across successive requests" and is the same
        // pattern already used below in
        // the_aggregate_log_line_reports_delivered_retried_discarded_and_buffer_evicted_counts_exactly_once().
        Http::fake([
            self::ENDPOINT => Http::sequence()
                ->push('', 503)
                ->push('', 200),
        ]);

        Artisan::call('llm-client:forward-run-traces');

        $row = DB::table('agent_run_export_queue')->where('id', $queueId)->first();
        $this->assertNotNull($row, 'row must still be pending after one failure');
        $this->assertSame(1, (int) $row->attempts);

        // Simulate the backoff window elapsing naturally (time passing, not
        // an operator action) -- the destination itself already "recovers"
        // via the second entry in the sequence above.
        DB::table('agent_run_export_queue')->where('id', $queueId)->update([
            'next_attempt_at' => CarbonImmutable::now()->subSecond()->format('Y-m-d H:i:s'),
        ]);

        $exitCode = Artisan::call('llm-client:forward-run-traces');

        $this->assertSame(0, $exitCode);
        $this->assertSame(
            0,
            DB::table('agent_run_export_queue')->where('id', $queueId)->count(),
            'the row must be delivered once the destination recovers, with no further operator action',
        );
    }

    // ========== FR-021/FR-022/SC-008: one aggregate log line, all four counts ==========

    #[Test]
    public function the_aggregate_log_line_reports_delivered_retried_discarded_and_buffer_evicted_counts_exactly_once(): void
    {
        // ---- Phase 1: produce a known buffer_evicted count in isolation,
        // via the real overflow-trim path (RunTraceRecorder::enqueueForwarding()),
        // before setting up the delivery scenario below. ----
        $this->app['config']->set('llm-client.run_trace.export.buffer_max_records', 1);
        TraceExportConfig::reset();

        DB::table('agent_run_export_queue')->insert([
            'id' => (string) Str::uuid(),
            'run_id' => (string) Str::uuid(),
            'attempts' => 0,
            'next_attempt_at' => null,
            'last_error' => null,
            'created_at' => CarbonImmutable::now()->subMinutes(30)->format('Y-m-d H:i:s'),
        ]);
        DB::table('agent_run_export_queue')->insert([
            'id' => (string) Str::uuid(),
            'run_id' => (string) Str::uuid(),
            'attempts' => 0,
            'next_attempt_at' => null,
            'last_error' => null,
            'created_at' => CarbonImmutable::now()->subMinutes(20)->format('Y-m-d H:i:s'),
        ]);
        DB::table('agent_run_export_queue')->insert([
            'id' => (string) Str::uuid(),
            'run_id' => (string) Str::uuid(),
            'attempts' => 0,
            'next_attempt_at' => null,
            'last_error' => null,
            'created_at' => CarbonImmutable::now()->subMinutes(10)->format('Y-m-d H:i:s'),
        ]);

        $recorder = $this->app->make(RunTraceRecorder::class);
        $evictingUserId = (string) Str::uuid();
        $evictingRunId = $recorder->openRun(RunKind::Interactive, $evictingUserId);
        $this->assertNotNull($evictingRunId);
        $evictingStepId = $recorder->openStep($evictingRunId);
        $recorder->closeStep($evictingStepId, RunEndState::Completed);
        $recorder->closeRun($evictingRunId, RunEndState::Completed);

        // 3 seeded + 1 new, trimmed to buffer_max_records=1 -> 3 evicted.
        // (This is the *expected* correct behavior per FR-018/T025; against
        // today's un-trimmed enqueueForwarding() the count will differ,
        // which is exactly the red signal this case is meant to produce.)

        // Clean the slate for the delivery scenario below, restoring a
        // generous buffer bound.
        DB::table('agent_run_export_queue')->delete();
        $this->app['config']->set('llm-client.run_trace.export.buffer_max_records', 10000);
        TraceExportConfig::reset();

        // ---- Phase 2: one delivered, one retried, one discarded (oversized). ----
        [, $deliveredQueueId] = $this->insertRunWithQueueRow(createdAt: CarbonImmutable::now()->subMinutes(3));
        [, $retriedQueueId] = $this->insertRunWithQueueRow(createdAt: CarbonImmutable::now()->subMinutes(2));
        $oversizedQueueId = $this->insertOversizedRunWithQueueRow();

        Http::fake([
            self::ENDPOINT => Http::sequence()
                ->push('', 200) // delivered row (oldest, processed first)
                ->push('', 503), // retried row (second-oldest)
        ]);

        $forwardedLogEntries = [];
        Log::listen(function ($entry) use (&$forwardedLogEntries) {
            if ($entry->message === 'Agent run traces forwarded') {
                $forwardedLogEntries[] = $entry;
            }
        });

        $exitCode = Artisan::call('llm-client:forward-run-traces');

        $this->assertSame(0, $exitCode);

        // Per-row outcomes.
        $this->assertSame(0, DB::table('agent_run_export_queue')->where('id', $deliveredQueueId)->count(), 'the healthy-destination row must have been delivered');
        $retriedRow = DB::table('agent_run_export_queue')->where('id', $retriedQueueId)->first();
        $this->assertNotNull($retriedRow, 'the failing-destination row must still be pending, not discarded');
        $this->assertSame(1, (int) $retriedRow->attempts);
        $this->assertSame(0, DB::table('agent_run_export_queue')->where('id', $oversizedQueueId)->count(), 'the oversized row must have been discarded');

        // Exactly one aggregate summary line for the whole invocation.
        $this->assertCount(
            1,
            $forwardedLogEntries,
            'the aggregate summary must be logged exactly once per invocation, regardless of how many records were processed',
        );

        $context = $forwardedLogEntries[0]->context;
        $this->assertArrayHasKey('delivered', $context);
        $this->assertArrayHasKey('retried', $context);
        $this->assertArrayHasKey('discarded', $context);
        $this->assertArrayHasKey('buffer_evicted', $context);

        $this->assertSame(1, $context['delivered']);
        $this->assertSame(1, $context['retried']);
        $this->assertSame(1, $context['discarded']);
        $this->assertSame(3, $context['buffer_evicted']);
    }
}
