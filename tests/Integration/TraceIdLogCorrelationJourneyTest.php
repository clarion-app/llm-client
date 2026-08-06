<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Services\AgentLoopService;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Monolog\Handler\TestHandler;

/**
 * US3: log lines line up with the run they were written during, without any
 * manual correlation and with NO new production code (research.md D3).
 *
 * Phase 3 already made RunTraceRecorder::openRun()/closeRun() call
 * Context::add('run_id', ...)/Context::forget('run_id'). This file proves
 * that alone is sufficient: Laravel's LogManager taps the Context repository
 * on every channel by default (a Monolog processor pushed in
 * LogManager::get()), so ordinary Log::info()/Log::warning() calls scattered
 * across the package pick up run_id automatically.
 *
 * A captured Monolog TestHandler is swapped into a `testing` channel (made
 * the default channel for the duration of each test) so every Log::* call
 * the production code already makes is observable here. Verified directly
 * against this codebase's installed framework version: the Context values
 * land in each Monolog\LogRecord's `extra` array, not its `context` array
 * (LogManager::get()'s pushProcessor merges
 * `$app[ContextRepository::class]->all()` into `$record->extra`). The four
 * scenarios below assert against `extra['run_id']` for that reason — the
 * "log line's context" language in the spec/tasks refers to the log line's
 * structured payload in the operator sense (what `grep`/an aggregator would
 * filter on), not literally Monolog's `context` field name.
 */
class TraceIdLogCorrelationJourneyTest extends AssembledSystemTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('llm-client.run_trace.enabled', true);

        // Swap a captured Monolog test handler into a `testing` channel and
        // make it the default sink, so every Log::* call the package's
        // production code already makes (RunTraceRecorder, MetricsRecorder,
        // AgentLoopService, etc.) is captured here with zero call-site changes.
        $this->app['config']->set('logging.channels.testing', [
            'driver' => 'monolog',
            'handler' => TestHandler::class,
            'level' => 'debug',
        ]);
        $this->app['config']->set('logging.default', 'testing');
    }

    /**
     * All records captured so far by the TestHandler bound into the
     * `testing` channel.
     *
     * @return \Monolog\LogRecord[]
     */
    protected function capturedLogRecords(): array
    {
        $logger = Log::channel('testing')->getLogger();

        foreach ($logger->getHandlers() as $handler) {
            if ($handler instanceof TestHandler) {
                return $handler->getRecords();
            }
        }

        return [];
    }

    /**
     * Captured records whose Context-derived `extra.run_id` matches $runId —
     * "ordinary log-line filtering" (FR-011), reimplemented here exactly as
     * an operator would do it against a deployment's own log output.
     *
     * @return \Monolog\LogRecord[]
     */
    protected function logRecordsForRun(string $runId): array
    {
        return array_values(array_filter(
            $this->capturedLogRecords(),
            fn ($record) => ($record->extra['run_id'] ?? null) === $runId
        ));
    }

    /**
     * US3 scenario 1 (FR-011): a run executing while an unrelated second run
     * is also in progress — filtering captured log records by run_id yields
     * only the first run's lines, no lines from the concurrent run.
     *
     * True OS-level concurrency isn't reachable from a single PHPUnit
     * process; two runs against two independent conversations (same user,
     * separate conversation rows — a second fixture's own user would collide
     * on the fixture's hardcoded email), driven back-to-back with the log
     * buffer never cleared between them, stands in for "also in progress" —
     * the property under test (filtering separates them cleanly) is
     * identical either way, and this is the same simplification
     * TraceIdRecordAttributionJourneyTest's Phase 3 tests use for the
     * analogous "two runs never cross-attribute" claim.
     */
    public function test_filtering_by_run_id_yields_only_that_runs_lines(): void
    {
        $this->scenario = 'filtering_by_run_id_yields_only_that_runs_lines';
        $this->entryPath = 'sync';

        $fixtureA = $this->fixture()->build();

        $conversationB = Conversation::create([
            'id' => (string) Str::uuid(),
            'user_id' => $fixtureA->user->id,
            'server_id' => $fixtureA->server->id,
            'model' => 'gpt-4',
            'title' => 'Second (unrelated) Test Conversation',
        ]);

        $this->script()->finalAnswer('First run answer.');
        $firstResult = $this->app->make(AgentLoopService::class)->run(
            $fixtureA->conversation,
            'First run question',
        );
        $this->assertSame('completed', $firstResult['status']);

        $this->script()->finalAnswer('Second run answer.');
        $secondResult = $this->app->make(AgentLoopService::class)->run(
            $conversationB,
            'Second run question',
        );
        $this->assertSame('completed', $secondResult['status']);

        $firstRun = DB::table('agent_runs')->where('conversation_id', $fixtureA->conversation->id)->first();
        $secondRun = DB::table('agent_runs')->where('conversation_id', $conversationB->id)->first();
        $this->assertNotNull($firstRun);
        $this->assertNotNull($secondRun);
        $this->assertNotSame($firstRun->id, $secondRun->id);

        // The captured buffer holds log lines from BOTH runs — never cleared
        // between them, standing in for "a second, unrelated run also in
        // progress".
        $allRecords = $this->capturedLogRecords();
        $this->assertGreaterThanOrEqual(
            2,
            count($allRecords),
            'Two runs should together produce at least one captured log line each'
        );

        $firstRunLines = $this->logRecordsForRun($firstRun->id);
        $secondRunLines = $this->logRecordsForRun($secondRun->id);

        $this->assertNotEmpty($firstRunLines, 'The first run must have produced at least one log line carrying its run_id');
        $this->assertNotEmpty($secondRunLines, 'The second run must have produced at least one log line carrying its run_id');

        // Filtering by run_id yields exactly that run's lines — no cross-attribution.
        foreach ($firstRunLines as $record) {
            $this->assertNotSame(
                $secondRun->id,
                $record->extra['run_id'],
                'A line filtered under the first run must never carry the second run\'s id'
            );
        }
        foreach ($secondRunLines as $record) {
            $this->assertNotSame(
                $firstRun->id,
                $record->extra['run_id'],
                'A line filtered under the second run must never carry the first run\'s id'
            );
        }
    }

    /**
     * US3 scenario 2 (FR-010): the run_id on a captured log line matches the
     * run's own agent_runs.id exactly — no translation step.
     */
    public function test_log_line_run_id_matches_agent_runs_id_exactly(): void
    {
        $this->scenario = 'log_line_run_id_matches_agent_runs_id_exactly';
        $this->entryPath = 'sync';

        $fixture = $this->fixture()->build();

        $this->script()->finalAnswer('Answer for the exact-match scenario.');
        $result = $this->app->make(AgentLoopService::class)->run(
            $fixture->conversation,
            'Question for the exact-match scenario',
        );
        $this->assertSame('completed', $result['status']);

        $run = DB::table('agent_runs')->where('conversation_id', $fixture->conversation->id)->first();
        $this->assertNotNull($run);

        $recordsForRun = $this->logRecordsForRun($run->id);
        $this->assertNotEmpty($recordsForRun, 'At least one production log line must carry this run\'s id');

        foreach ($recordsForRun as $record) {
            $this->assertSame(
                $run->id,
                $record->extra['run_id'],
                'FR-010: the run_id on a captured log line must match agent_runs.id exactly, with no translation step'
            );
        }
    }

    /**
     * US3 scenario 3: a run whose work continues in a deferred queued job —
     * filtering by run_id includes log lines from both the originating
     * request and the later continuation.
     *
     * Phase 5 (US4) drives a real SendHttpStreamRequest across an actual
     * queue-job process boundary and proves run_id survives it (research.md
     * D2). This test proves the narrower claim Phase 4 owns: once ANY
     * second Context scope carries the same run_id — which is exactly what
     * a queue job's Context rehydration produces, per D2 — its log lines
     * resolve to the run the same way the originating request's did. No
     * openRun() call here: a continuation never mints a fresh run id, it
     * inherits the one already open.
     */
    public function test_continuation_log_lines_carry_the_same_run_id(): void
    {
        $this->scenario = 'continuation_log_lines_carry_the_same_run_id';
        $this->entryPath = 'sync';

        $fixture = $this->fixture()->build();

        $this->script()->finalAnswer('Originating request answer.');
        $result = $this->app->make(AgentLoopService::class)->run(
            $fixture->conversation,
            'Originating request question',
        );
        $this->assertSame('completed', $result['status']);

        $run = DB::table('agent_runs')->where('conversation_id', $fixture->conversation->id)->first();
        $this->assertNotNull($run);

        $originatingLines = $this->logRecordsForRun($run->id);
        $this->assertNotEmpty($originatingLines, 'Precondition: the originating request must have logged at least one line under this run');

        // Simulate the continuation: a rehydrated Context carrying the SAME
        // run_id, logging while it executes.
        Context::add('run_id', $run->id);
        try {
            Log::channel('testing')->info('Simulated deferred continuation work for run ' . $run->id);
        } finally {
            Context::forget('run_id');
        }

        $allLinesForRun = $this->logRecordsForRun($run->id);
        $this->assertGreaterThan(
            count($originatingLines),
            count($allLinesForRun),
            'Filtering by run_id must include the continuation log line alongside the originating request lines'
        );

        $messages = array_map(fn ($record) => $record->message, $allLinesForRun);
        $this->assertContains(
            'Simulated deferred continuation work for run ' . $run->id,
            $messages,
            'The continuation line must be present among the run\'s filtered log lines'
        );
    }

    /**
     * Edge case (FR-012): a log line written outside any run's boundary
     * carries no run_id key at all — not a null value, an absent key.
     * Covers both "before any run has ever opened" and "after the run's own
     * closeRun() has already fired".
     */
    public function test_log_line_outside_run_boundary_has_no_run_id_key(): void
    {
        $this->scenario = 'log_line_outside_run_boundary_has_no_run_id_key';
        $this->entryPath = 'sync';

        // Before any run: Context has never held run_id in this process.
        Log::channel('testing')->info('Log line before any run has opened.');

        $recordsBefore = $this->capturedLogRecords();
        $beforeRecord = end($recordsBefore);
        $this->assertSame('Log line before any run has opened.', $beforeRecord->message);
        $this->assertArrayNotHasKey(
            'run_id',
            $beforeRecord->extra,
            'A log line before any run has opened must carry no run_id key at all — not merely a null value'
        );

        // After a run has opened and closed: closeRun()'s finally block
        // (T014) clears Context, so the key disappears again, not merely
        // reverts to null.
        $fixture = $this->fixture()->build();
        $this->script()->finalAnswer('Answer for the closed-run edge case.');
        $result = $this->app->make(AgentLoopService::class)->run(
            $fixture->conversation,
            'Question for the closed-run edge case',
        );
        $this->assertSame('completed', $result['status']);

        Log::channel('testing')->info('Log line after the run has already closed.');

        $recordsAfter = $this->capturedLogRecords();
        $afterRecord = end($recordsAfter);
        $this->assertSame('Log line after the run has already closed.', $afterRecord->message);
        $this->assertArrayNotHasKey(
            'run_id',
            $afterRecord->extra,
            'A log line written after closeRun() must carry no run_id key at all'
        );
    }
}
