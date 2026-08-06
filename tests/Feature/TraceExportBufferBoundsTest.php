<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use ClarionApp\LlmClient\ValueObjects\TraceExportConfig;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-018/SC-006, mutation-testing checklist item 2: the forwarding buffer
 * (`agent_run_export_queue`) is bounded at `export.buffer_max_records`. When
 * a hand-off would push the table over that bound, the *oldest* rows by
 * `created_at` are discarded to bring it back down -- never the newest.
 *
 * RunTraceRecorder::enqueueForwarding() (Phase 4/US2) only ever inserts
 * today -- it has no trim logic at all, so the table can grow without bound
 * during a sustained outage. Every case in this file is expected to
 * fail/red until Phase 5/US3's T025 adds the buffer-overflow trim.
 */
class TraceExportBufferBoundsTest extends TestCase
{
    private const ENDPOINT = 'https://tempo.example.com:4318/v1/traces';
    private const BUFFER_MAX = 5;

    protected function setUp(): void
    {
        parent::setUp();

        TraceExportConfig::reset();

        $this->app['config']->set('llm-client.run_trace.enabled', true);
        $this->app['config']->set('llm-client.run_trace.export.destinations', ['internal', 'external']);
        $this->app['config']->set('llm-client.run_trace.export.otlp_endpoint', self::ENDPOINT);
        $this->app['config']->set('llm-client.run_trace.export.buffer_max_records', self::BUFFER_MAX);

        // No delivery ever happens in this file -- only closeRun()'s local
        // insert/trim behavior is under test -- but fake the facade anyway
        // so an accidental real network call fails loud rather than hanging.
        Http::fake();
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
     * Seeds a queue row directly (bypassing enqueueForwarding() entirely)
     * with an explicit, widely-spaced created_at so ordering can never be
     * ambiguous -- several rows produced by real closeRun() calls in quick
     * succession could otherwise land in the same second and tie.
     */
    private function seedQueueRow(int $minutesAgo): string
    {
        $id = (string) Str::uuid();

        DB::table('agent_run_export_queue')->insert([
            'id' => $id,
            'run_id' => (string) Str::uuid(),
            'attempts' => 0,
            'next_attempt_at' => null,
            'last_error' => null,
            'created_at' => CarbonImmutable::now()->subMinutes($minutesAgo)->format('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    #[Test]
    public function the_buffer_never_exceeds_its_configured_maximum_and_discards_the_oldest_rows_first(): void
    {
        // Seed BUFFER_MAX + 3 rows directly, oldest first: index 0 is the
        // oldest (80 minutes ago), index 7 is the newest of the seeded rows
        // (10 minutes ago). 8 rows total against a max of 5.
        $seededIds = [];
        for ($i = self::BUFFER_MAX + 3; $i >= 1; $i--) {
            $seededIds[] = $this->seedQueueRow($i * 10);
        }

        $this->assertSame(self::BUFFER_MAX + 3, DB::table('agent_run_export_queue')->count());

        // The 4 oldest seeded rows -- these, and only these, must be gone
        // once the buffer is trimmed back to BUFFER_MAX (5) after one more
        // row is added (9 total - 5 max = 4 to discard).
        $expectedDiscardedIds = array_slice($seededIds, 0, count($seededIds) - self::BUFFER_MAX + 1);
        $expectedSurvivingSeededIds = array_slice($seededIds, count($seededIds) - (self::BUFFER_MAX - 1));

        // One more hand-off through the real production path: closeRun()
        // with 'external' selected inserts one more row via
        // enqueueForwarding(), which must then trim the buffer back down to
        // buffer_max_records, oldest-first.
        $recorder = $this->app->make(RunTraceRecorder::class);
        $userId = (string) Str::uuid();
        $runId = $recorder->openRun(RunKind::Interactive, $userId);
        $this->assertNotNull($runId);
        $stepId = $recorder->openStep($runId);
        $this->assertNotNull($stepId);
        $recorder->closeStep($stepId, RunEndState::Completed);
        $recorder->closeRun($runId, RunEndState::Completed);

        $newRowId = DB::table('agent_run_export_queue')->where('run_id', $runId)->value('id');
        $this->assertNotNull($newRowId, 'closeRun() should have enqueued a new forwarding row for this run');

        // The bound must never be exceeded.
        $this->assertSame(
            self::BUFFER_MAX,
            DB::table('agent_run_export_queue')->count(),
            'the buffer must never exceed buffer_max_records after a hand-off that would overflow it',
        );

        // The oldest rows -- and only the oldest -- must be the ones gone.
        foreach ($expectedDiscardedIds as $discardedId) {
            $this->assertSame(
                0,
                DB::table('agent_run_export_queue')->where('id', $discardedId)->count(),
                'the oldest rows by created_at must be discarded when the buffer overflows',
            );
        }

        // The newest seeded rows, and the row just inserted, must all survive.
        foreach ($expectedSurvivingSeededIds as $survivingId) {
            $this->assertSame(
                1,
                DB::table('agent_run_export_queue')->where('id', $survivingId)->count(),
                'the newest rows must never be discarded by an oldest-first trim',
            );
        }
        $this->assertSame(1, DB::table('agent_run_export_queue')->where('id', $newRowId)->count());
    }

    #[Test]
    public function repeated_overflow_across_several_hand_offs_never_lets_the_buffer_grow_past_the_maximum(): void
    {
        // Simulate a sustained period where far more runs close than the
        // buffer can hold -- BUFFER_MAX + 10 hand-offs in a row. At no
        // point (and certainly not at the end) may the table exceed
        // buffer_max_records.
        $recorder = $this->app->make(RunTraceRecorder::class);
        $lastRunId = null;

        for ($i = 0; $i < self::BUFFER_MAX + 10; $i++) {
            $userId = (string) Str::uuid();
            $runId = $recorder->openRun(RunKind::Interactive, $userId);
            $this->assertNotNull($runId);
            $stepId = $recorder->openStep($runId);
            $this->assertNotNull($stepId);
            $recorder->closeStep($stepId, RunEndState::Completed);
            $recorder->closeRun($runId, RunEndState::Completed);
            $lastRunId = $runId;

            $this->assertLessThanOrEqual(
                self::BUFFER_MAX,
                DB::table('agent_run_export_queue')->count(),
                "the buffer must never exceed buffer_max_records, even mid-sequence (iteration {$i})",
            );
        }

        $this->assertSame(self::BUFFER_MAX, DB::table('agent_run_export_queue')->count());

        // The most recently closed run's row must have survived every trim.
        $this->assertSame(1, DB::table('agent_run_export_queue')->where('run_id', $lastRunId)->count());
    }
}
