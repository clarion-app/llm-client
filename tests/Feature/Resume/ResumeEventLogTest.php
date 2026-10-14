<?php

namespace Tests\Feature\Resume;

use ClarionApp\LlmClient\Services\ResumeEventLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ResumeEventLog — the resume/retry event log (FR-031, data-model.md §5).
 *
 * Every event kind is written with its payload shape; strings are cut
 * (labels 256, reasons 512), lists hold at most 50 entries, arguments and
 * provider text are never stored, and each write logs one line whose
 * context carries the run id (the Context-merge pattern spec 069 proved).
 */
class ResumeEventLogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Swap a captured Monolog test handler into a `testing` channel and
        // make it the default sink, so the log line each write produces is
        // observable here (the spec 069 pattern: Context values land in the
        // record's `extra` array).
        $this->app['config']->set('logging.channels.testing', [
            'driver' => 'monolog',
            'handler' => TestHandler::class,
            'level' => 'debug',
        ]);
        $this->app['config']->set('logging.default', 'testing');
    }

    /** The event writer under test. */
    private function events(): ResumeEventLog
    {
        return app(ResumeEventLog::class);
    }

    /** @return object the newest event row for the run */
    private function lastEvent(string $runId): object
    {
        $row = DB::table('agent_run_resume_events')->where('run_id', $runId)->orderByDesc('created_at')->first();
        $this->assertNotNull($row, "an event row for {$runId} was written");

        return $row;
    }

    /**
     * Captured records whose Context-derived `extra.run_id` matches $runId.
     *
     * @return \Monolog\LogRecord[]
     */
    private function logRecordsForRun(string $runId): array
    {
        $logger = Log::channel('testing')->getLogger();

        foreach ($logger->getHandlers() as $handler) {
            if ($handler instanceof TestHandler) {
                return array_values(array_filter(
                    $handler->getRecords(),
                    fn ($record) => ($record->extra['run_id'] ?? null) === $runId,
                ));
            }
        }

        return [];
    }

    // ---------------------------------------------------------------
    // Payload shape per kind (data-model.md §5)
    // ---------------------------------------------------------------

    public static function kindPayloads(): array
    {
        return [
            'interruption_detected' => [
                'interruption_detected', 3, 2,
                [
                    'last_progress_at' => '2026-10-15T10:00:00Z',
                    'lease_expired_at' => '2026-10-15T10:05:00Z',
                    'resume_state_before' => 'working',
                    'detected_by' => 'sweep',
                ],
            ],
            'takeover' => [
                'takeover', 3, 2,
                [],
            ],
            'resume_started' => [
                'resume_started', 8, 1,
                [
                    'from_step' => 8,
                    'iteration' => 8,
                    'mode' => 'sync',
                    'reused' => [['tool_call_id' => 'call_a', 'tool' => 'execute_operation', 'label' => 'Store a note']],
                    'rerun' => [],
                    'outcome_unknown' => [],
                    'model_call_repeated' => false,
                ],
            ],
            'resume_refused_cancelled' => [
                'resume_refused_cancelled', null, null,
                ['cause' => 'conversation_ended'],
            ],
            'provider_retry_scheduled' => [
                'provider_retry_scheduled', null, 2,
                [
                    'reason' => 'rate_limited',
                    'status' => 429,
                    'next_attempt_at' => '2026-10-15T10:06:40Z',
                    'deferred' => true,
                ],
            ],
            'provider_retry_exhausted' => [
                'provider_retry_exhausted', null, 5,
                [
                    'reason' => 'rate_limited',
                    'attempts' => 5,
                    'window_seconds' => 600,
                ],
            ],
            'stopped_unresumable' => [
                'stopped_unresumable', null, 3,
                [
                    'reason_code' => 'repeated_interruption',
                    'completed_steps' => 3,
                ],
            ],
            'legacy_adopted' => [
                'legacy_adopted', null, null,
                ['assessment' => 'no open step'],
            ],
        ];
    }

    #[Test]
    #[DataProvider('kindPayloads')]
    public function each_kind_is_written_with_its_payload_shape(string $kind, ?int $stepPosition, ?int $attempt, array $payload): void
    {
        $this->events()->record('run-1', $kind, $stepPosition, $attempt, $payload);

        $row = $this->lastEvent('run-1');
        $this->assertSame($kind, $row->kind, "the row carries the kind ({$kind})");
        $this->assertSame($stepPosition, $stepPosition === null ? null : (int) $row->step_position, "step_position ({$kind})");
        $this->assertSame($attempt, $attempt === null ? null : (int) $row->attempt, "attempt ({$kind})");

        $stored = json_decode((string) $row->payload, true);
        $this->assertIsArray($stored, "the payload is stored JSON ({$kind})");
        foreach (array_keys($payload) as $key) {
            $this->assertArrayHasKey($key, $stored, "the payload keeps its {$key} key ({$kind})");
        }
    }

    // ---------------------------------------------------------------
    // Bounded payload
    // ---------------------------------------------------------------

    #[Test]
    public function labels_are_cut_to_256(): void
    {
        $label = str_repeat('x', 300);
        $this->events()->record('run-1', 'resume_started', 8, 1, [
            'from_step' => 8,
            'iteration' => 8,
            'mode' => 'sync',
            'reused' => [],
            'rerun' => [],
            'outcome_unknown' => [['tool_call_id' => 'call_a', 'tool' => 'execute_operation', 'label' => $label]],
            'model_call_repeated' => false,
        ]);

        $stored = json_decode((string) $this->lastEvent('run-1')->payload, true);
        $this->assertSame(256, strlen($stored['outcome_unknown'][0]['label']), 'a 300-character label is cut to 256');
    }

    #[Test]
    public function reasons_are_cut_to_512(): void
    {
        $reason = str_repeat('y', 600);
        $this->events()->record('run-1', 'stopped_unresumable', null, 3, [
            'reason_code' => 'tool_unavailable',
            'completed_steps' => 3,
            'reason' => $reason,
        ]);

        $stored = json_decode((string) $this->lastEvent('run-1')->payload, true);
        $this->assertSame(512, strlen($stored['reason']), 'a 600-character reason is cut to 512');
    }

    #[Test]
    public function lists_hold_at_most_50_entries(): void
    {
        $reused = [];
        for ($i = 0; $i < 60; $i++) {
            $reused[] = ['tool_call_id' => "call_{$i}", 'tool' => 'execute_operation', 'label' => "Label {$i}"];
        }

        $this->events()->record('run-1', 'resume_started', 8, 1, [
            'from_step' => 8,
            'iteration' => 8,
            'mode' => 'sync',
            'reused' => $reused,
            'rerun' => [],
            'outcome_unknown' => [],
            'model_call_repeated' => false,
        ]);

        $stored = json_decode((string) $this->lastEvent('run-1')->payload, true);
        $this->assertCount(50, $stored['reused'], 'a 60-entry list is cut to 50');
    }

    #[Test]
    public function arguments_and_provider_text_are_never_stored(): void
    {
        $this->events()->record('run-1', 'provider_retry_scheduled', null, 2, [
            'reason' => 'rate_limited',
            'status' => 429,
            'next_attempt_at' => '2026-10-15T10:06:40Z',
            'deferred' => true,
            'arguments' => ['note' => 'a secret argument value'],
            'provider_error' => 'Rate limited (scripted)',
        ]);

        $stored = json_decode((string) $this->lastEvent('run-1')->payload, true);
        $this->assertArrayNotHasKey('arguments', $stored, 'tool call arguments are never stored');
        $this->assertArrayNotHasKey('provider_error', $stored, 'provider error text is never stored');
        $flat = json_encode($stored);
        $this->assertStringNotContainsString('a secret argument value', $flat, 'no argument value anywhere in the payload');
        $this->assertStringNotContainsString('Rate limited (scripted)', $flat, 'no provider error text anywhere in the payload');
    }

    // ---------------------------------------------------------------
    // The log line
    // ---------------------------------------------------------------

    #[Test]
    public function each_write_logs_one_line_carrying_the_run_id(): void
    {
        $this->events()->record('run-1', 'interruption_detected', 3, 2, [
            'last_progress_at' => '2026-10-15T10:00:00Z',
            'lease_expired_at' => '2026-10-15T10:05:00Z',
            'resume_state_before' => 'working',
            'detected_by' => 'sweep',
        ]);

        $records = $this->logRecordsForRun('run-1');
        $this->assertCount(1, $records, 'exactly one log line per write, carrying the run id in its context');
    }
}
