<?php

namespace Tests\Feature\Resume;

use ClarionApp\LlmClient\Models\AgentRun;
use ClarionApp\LlmClient\Services\RunProgressQuery;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsRunProgress;
use Tests\TestCase;

/**
 * RunProgressQuery — the derived progress view of a run.
 *
 * The state follows one fixed order: cancelled, finished, failed, waiting on
 * the user, waiting to retry the provider, interrupted, resuming, working.
 * The activity says what the task is doing at this moment without naming an
 * argument or an id. The structure (stages, parts, helpers) is present only
 * when it is known; otherwise the remaining work is "unknown". There is no
 * percentage anywhere in the payload, whatever the shape.
 */
class RunProgressQueryTest extends TestCase
{
    use SeedsRunProgress;

    private string $userId = '11111111-1111-1111-1111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:30:41.120000', 'UTC'));
        config(['llm-client.run_trace.enabled' => true]);
    }

    protected function tearDown(): void
    {
        $this->clearProgressTables();
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array<string, mixed> the progress of the run */
    private function progress(string $runId): array
    {
        return app(RunProgressQuery::class)->forRun(AgentRun::findOrFail($runId));
    }

    // ---------------------------------------------------------------
    // State derivation, in their fixed order
    // ---------------------------------------------------------------

    #[Test]
    public function a_cancelled_run_is_cancelled_whatever_its_end_state(): void
    {
        $stopped = $this->seedRun($this->userId, ['end_state' => 'stopped_early', 'end_reason' => 'cancelled', 'resume_state' => null, 'cancelled_at' => $this->stamp('2026-10-12 10:20:00')]);
        $completedButCancelled = $this->seedRun($this->userId, ['end_state' => 'completed', 'resume_state' => null, 'cancelled_at' => $this->stamp('2026-10-12 10:20:00')]);

        $this->assertSame('cancelled', $this->progress($stopped)['state']);
        $this->assertSame('cancelled', $this->progress($completedButCancelled)['state'], 'cancelled is the first rule: it beats finished');
    }

    #[Test]
    public function a_run_refused_a_resume_because_it_was_cancelled_is_cancelled(): void
    {
        $runId = $this->seedRun($this->userId, ['end_state' => 'stopped_early', 'end_reason' => 'The conversation was ended.', 'resume_state' => null]);
        $this->seedEvent($runId, 'resume_refused_cancelled', ['cause' => 'conversation_ended']);

        $this->assertSame('cancelled', $this->progress($runId)['state'], 'a stopped run that is not a failure: the event names the cancellation');
    }

    #[Test]
    public function a_run_stopped_at_its_budget_ceiling_is_cancelled(): void
    {
        $runId = $this->seedRun($this->userId, ['end_state' => 'stopped_early', 'end_reason' => 'budget', 'resume_state' => null]);
        $this->seedTask($runId, $this->userId, 'halted');

        $this->assertSame('cancelled', $this->progress($runId)['state'], 'a budget stop is a cancellation, not a failure');
    }

    #[Test]
    public function a_completed_run_is_finished_and_so_is_a_run_whose_budget_was_accepted(): void
    {
        $completed = $this->seedRun($this->userId, ['end_state' => 'completed', 'resume_state' => null]);
        $accepted = $this->seedRun($this->userId, ['end_state' => 'completed', 'end_reason' => 'concluded', 'resume_state' => null]);
        $this->seedTask($accepted, $this->userId, 'concluded');

        $this->assertSame('finished', $this->progress($completed)['state']);
        $this->assertSame('finished', $this->progress($accepted)['state'], 'accepting the partial result at the ceiling is a finish');
    }

    #[Test]
    #[DataProvider('failedEndStates')]
    public function a_failed_abandoned_or_uncancelled_stopped_run_is_failed(string $endState): void
    {
        $runId = $this->seedRun($this->userId, ['end_state' => $endState, 'end_reason' => 'it broke', 'resume_state' => null]);

        $this->assertSame('failed', $this->progress($runId)['state']);
    }

    public static function failedEndStates(): array
    {
        return ['failed' => ['failed'], 'abandoned' => ['abandoned'], 'stopped early, no cancellation' => ['stopped_early']];
    }

    #[Test]
    public function a_paused_run_is_waiting_on_the_user(): void
    {
        $runId = $this->seedRun($this->userId, ['resume_state' => 'paused']);

        $this->assertSame('waiting_on_user', $this->progress($runId)['state']);
    }

    #[Test]
    #[DataProvider('waitingQuestionStates')]
    public function a_pending_or_stopped_question_means_waiting_on_the_user(string $questionState): void
    {
        $runId = $this->seedRun($this->userId, ['resume_state' => 'working']);
        $this->seedQuestion($runId, $this->userId, $questionState);

        $this->assertSame('waiting_on_user', $this->progress($runId)['state']);
    }

    public static function waitingQuestionStates(): array
    {
        return ['pending' => ['pending'], 'stopped' => ['stopped']];
    }

    #[Test]
    public function an_answered_question_does_not_keep_the_run_waiting(): void
    {
        $runId = $this->seedRun($this->userId, ['resume_state' => 'working']);
        $this->seedQuestion($runId, $this->userId, 'answered');

        $this->assertSame('working', $this->progress($runId)['state']);
    }

    #[Test]
    public function a_task_paused_at_its_ceiling_is_waiting_on_the_user(): void
    {
        $runId = $this->seedRun($this->userId, ['resume_state' => null]);
        $this->seedTask($runId, $this->userId, 'paused', 200, 200, ['paused_run_id' => $runId, 'pause_reason' => 'steps']);

        $this->assertSame('waiting_on_user', $this->progress($runId)['state']);
    }

    #[Test]
    public function waiting_on_the_user_outranks_the_other_live_states(): void
    {
        $retry = $this->seedRun($this->userId, ['resume_state' => 'waiting_provider_retry', 'next_attempt_at' => $this->stamp('2026-10-12 10:31:10')]);
        $interrupted = $this->seedRun($this->userId, ['resume_state' => 'interrupted', 'interrupted_at' => $this->stamp('2026-10-12 10:29:00')]);
        $this->seedQuestion($retry, $this->userId, 'pending');
        $this->seedQuestion($interrupted, $this->userId, 'stopped');

        $this->assertSame('waiting_on_user', $this->progress($retry)['state']);
        $this->assertSame('waiting_on_user', $this->progress($interrupted)['state']);
    }

    #[Test]
    public function the_live_states_follow_the_resume_state(): void
    {
        $retry = $this->seedRun($this->userId, ['resume_state' => 'waiting_provider_retry', 'next_attempt_at' => $this->stamp('2026-10-12 10:31:10')]);
        $interrupted = $this->seedRun($this->userId, ['resume_state' => 'interrupted', 'interrupted_at' => $this->stamp('2026-10-12 10:29:00')]);
        $resuming = $this->seedRun($this->userId, ['resume_state' => 'resuming']);
        $working = $this->seedRun($this->userId, ['resume_state' => 'working']);

        $this->assertSame('waiting_provider_retry', $this->progress($retry)['state']);
        $this->assertSame('interrupted', $this->progress($interrupted)['state']);
        $this->assertSame('resuming', $this->progress($resuming)['state']);
        $this->assertSame('working', $this->progress($working)['state']);
    }

    // ---------------------------------------------------------------
    // Activity
    // ---------------------------------------------------------------

    #[Test]
    public function an_open_step_awaiting_the_model_is_waiting_for_the_model(): void
    {
        $runId = $this->seedRun($this->userId);
        $this->seedStep($runId, 1, 'completed', 'committed');
        $this->seedStep($runId, 2, 'in_progress', 'open');

        $this->assertSame(['kind' => 'waiting_for_model'], $this->progress($runId)['activity']);
    }

    #[Test]
    public function a_started_call_is_running_a_tool_named_by_its_label_and_never_by_arguments_or_ids(): void
    {
        $runId = $this->seedRun($this->userId);
        $stepId = $this->seedStep($runId, 1, 'in_progress', 'model_recorded', ['call_secret_77' => $this->checkpointCall('started', 'ledger.write')]);
        DB::table('agent_run_steps')->where('id', $stepId)->update(['checkpoint' => json_encode([
            'v' => 1,
            'iteration' => 1,
            'model' => ['content' => '', 'tool_calls' => [['id' => 'call_secret_77', 'type' => 'function', 'function' => ['name' => 'execute_operation', 'arguments' => '{"password":"hunter2"}']]]],
            'calls' => ['call_secret_77' => $this->checkpointCall('started', 'ledger.write')],
        ])]);

        $progress = $this->progress($runId);

        $this->assertSame(['kind' => 'running_tool', 'label' => 'ledger.write'], $progress['activity']);
        $json = json_encode($progress);
        $this->assertStringNotContainsString('hunter2', $json, 'an argument is never shown');
        $this->assertStringNotContainsString('call_secret_77', $json, 'a tool-call id is never shown');
        $this->assertStringNotContainsString($stepId, $json, 'a step id is never shown');
    }

    #[Test]
    public function an_open_batch_is_waiting_on_helpers_and_counts_those_finished(): void
    {
        $runId = $this->seedRun($this->userId);
        $this->seedStep($runId, 1, 'in_progress', 'model_recorded', ['call_a' => $this->checkpointCall('started', 'delegate_to_helper', 'delegate_to_helper')]);
        $batch = (string) Str::uuid();
        $this->seedDelegation($runId, $this->userId, 'completed', $batch);
        $this->seedDelegation($runId, $this->userId, 'completed', $batch);
        $this->seedDelegation($runId, $this->userId, 'in_progress', $batch);

        $activity = $this->progress($runId)['activity'];

        $this->assertSame('waiting_on_helpers', $activity['kind']);
        $this->assertSame('2 of 3 helpers finished', $activity['label']);
    }

    #[Test]
    public function a_run_waiting_for_a_provider_retry_is_waiting_to_retry(): void
    {
        $runId = $this->seedRun($this->userId, ['resume_state' => 'waiting_provider_retry', 'lease_holder' => null, 'next_attempt_at' => $this->stamp('2026-10-12 10:31:10')]);
        $this->seedStep($runId, 1, 'in_progress', 'open');

        $this->assertSame('waiting_to_retry', $this->progress($runId)['activity']['kind'], 'the retry wait outranks the open step it belongs to');
    }

    #[Test]
    public function a_run_with_no_open_step_is_between_steps(): void
    {
        $runId = $this->seedRun($this->userId);
        $this->seedStep($runId, 1, 'completed', 'committed');
        $this->seedStep($runId, 2, 'completed', 'committed');

        $this->assertSame(['kind' => 'between_steps'], $this->progress($runId)['activity']);
    }

    #[Test]
    public function a_run_that_is_over_has_no_activity(): void
    {
        $runId = $this->seedRun($this->userId, ['end_state' => 'completed', 'resume_state' => null, 'ended_at' => $this->stamp('2026-10-12 10:30:00')]);
        $this->seedStep($runId, 1, 'completed', 'committed');

        $this->assertSame(['kind' => 'none'], $this->progress($runId)['activity']);
    }

    // ---------------------------------------------------------------
    // Structure
    // ---------------------------------------------------------------

    #[Test]
    public function a_sequence_of_stages_says_which_stage_the_task_is_in(): void
    {
        $runId = $this->seedRun($this->userId);
        $this->seedSequence($runId, $this->userId, completed: 2, total: 5);

        $progress = $this->progress($runId);

        $this->assertSame(['kind' => 'stages', 'completed' => 2, 'total' => 5, 'label' => 'stage 3 of 5'], $progress['structure']);
        $this->assertNull($progress['remaining'], 'a known structure leaves the remaining work to it');
    }

    #[Test]
    public function a_managed_task_counts_its_accepted_parts(): void
    {
        $runId = $this->seedRun($this->userId);
        $this->seedManagedTask($runId, $this->userId, accepted: 2, total: 4);

        $structure = $this->progress($runId)['structure'];

        $this->assertSame('parts', $structure['kind']);
        $this->assertSame(2, $structure['completed']);
        $this->assertSame(4, $structure['total']);
        $this->assertIsString($structure['label']);
        $this->assertNotSame('', $structure['label']);
    }

    #[Test]
    public function an_open_batch_counts_its_finished_helpers(): void
    {
        $runId = $this->seedRun($this->userId);
        $batch = (string) Str::uuid();
        $this->seedDelegation($runId, $this->userId, 'completed', $batch);
        $this->seedDelegation($runId, $this->userId, 'failed', $batch);
        $this->seedDelegation($runId, $this->userId, 'queued', $batch);

        $structure = $this->progress($runId)['structure'];

        $this->assertSame('helpers', $structure['kind']);
        $this->assertSame(2, $structure['completed'], 'a helper that failed has finished');
        $this->assertSame(3, $structure['total']);
    }

    #[Test]
    public function a_run_with_no_known_structure_says_the_remaining_work_is_unknown(): void
    {
        $runId = $this->seedRun($this->userId);
        $this->seedStep($runId, 1, 'completed', 'committed');

        $progress = $this->progress($runId);

        $this->assertNull($progress['structure']);
        $this->assertSame('unknown', $progress['remaining']);
    }

    #[Test]
    public function no_percentage_without_structure(): void
    {
        $unstructured = $this->seedRun($this->userId);
        $this->seedStep($unstructured, 1, 'completed', 'committed');
        $this->seedStep($unstructured, 2, 'in_progress', 'open');
        $this->seedTask($unstructured, $this->userId, 'running', 38, 200);

        $structured = $this->seedRun($this->userId);
        $this->seedSequence($structured, $this->userId, completed: 2, total: 5);

        foreach ([$unstructured, $structured] as $runId) {
            $payload = $this->progress($runId);
            $this->assertNoPercentage($payload, $runId === $unstructured ? 'unstructured run' : 'structured run');
        }
    }

    /** No key named like a percentage, and no value holding a % sign, at any depth. */
    private function assertNoPercentage(mixed $value, string $where, string $path = ''): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $inner) {
                $this->assertDoesNotMatchRegularExpression('/percent|pct/i', (string) $key, "{$where}: key {$path}{$key} reads like a percentage");
                $this->assertNoPercentage($inner, $where, $path . $key . '.');
            }

            return;
        }
        if (is_string($value)) {
            $this->assertStringNotContainsString('%', $value, "{$where}: value at {$path} holds a percent sign");
        }
    }

    // ---------------------------------------------------------------
    // Allowance
    // ---------------------------------------------------------------

    #[Test]
    public function the_allowance_is_the_tasks_standing_labelled_allowance_used(): void
    {
        $runId = $this->seedRun($this->userId);
        $this->seedTask($runId, $this->userId, 'running', 38, 200);

        $this->assertSame(
            ['steps_used' => 38, 'max_steps' => 200, 'label' => 'allowance used'],
            $this->progress($runId)['allowance'],
        );
    }

    #[Test]
    public function a_run_with_no_task_has_no_allowance(): void
    {
        $runId = $this->seedRun($this->userId);

        $this->assertArrayNotHasKey('allowance', $this->progress($runId));
    }

    // ---------------------------------------------------------------
    // The counters and timestamps
    // ---------------------------------------------------------------

    #[Test]
    public function it_reports_steps_completed_time_and_resume_facts(): void
    {
        $runId = $this->seedRun($this->userId, [
            'resume_state' => 'waiting_provider_retry',
            'resume_count' => 1,
            'interrupted_at' => $this->stamp('2026-10-12 10:12:00'),
            'next_attempt_at' => $this->stamp('2026-10-12 10:31:10'),
            'last_progress_at' => $this->stamp('2026-10-12 10:30:41.12'),
        ]);
        $this->seedStep($runId, 1, 'completed', 'committed');
        $this->seedStep($runId, 2, 'completed', 'committed');
        $this->seedStep($runId, 3, 'completed', 'committed');
        $this->seedStep($runId, 4, 'failed', 'open');
        $this->seedStep($runId, 5, 'in_progress', 'open');

        $progress = $this->progress($runId);

        $this->assertSame($runId, $progress['run_id']);
        $this->assertSame(3, $progress['steps_completed'], 'only steps closed completed count');
        $this->assertSame('2026-10-12T10:00:00.000000Z', $progress['started_at']);
        $this->assertSame(1841120, $progress['elapsed_ms'], 'now minus the start, in milliseconds');
        $this->assertSame('2026-10-12T10:30:41.120000Z', $progress['last_progress_at']);
        $this->assertSame(1, $progress['resume_count']);
        $this->assertSame('2026-10-12T10:12:00.000000Z', $progress['interrupted_at']);
        $this->assertSame('2026-10-12T10:31:10.000000Z', $progress['next_attempt_at']);
    }

    #[Test]
    public function a_run_that_is_over_stops_its_clock_at_its_end(): void
    {
        $runId = $this->seedRun($this->userId, [
            'end_state' => 'completed',
            'resume_state' => null,
            'ended_at' => $this->stamp('2026-10-12 10:10:00'),
            'duration_ms' => 600000,
        ]);

        $this->assertSame(600000, $this->progress($runId)['elapsed_ms']);
    }

    #[Test]
    public function the_shape_always_carries_the_same_keys(): void
    {
        $runId = $this->seedRun($this->userId);

        $keys = array_keys($this->progress($runId));
        foreach (['run_id', 'state', 'activity', 'steps_completed', 'structure', 'remaining', 'started_at', 'elapsed_ms', 'last_progress_at', 'resume_count', 'interrupted_at', 'next_attempt_at'] as $key) {
            $this->assertContains($key, $keys, "the payload carries {$key}");
        }
    }

    // ---------------------------------------------------------------
    // Legacy runs
    // ---------------------------------------------------------------

    #[Test]
    public function a_legacy_run_gets_the_same_shape(): void
    {
        $legacy = $this->seedRun($this->userId, [
            'checkpoint_version' => null, 'resume_mode' => null, 'resume_driver' => null, 'resume_state' => null,
            'last_progress_at' => null, 'resume_count' => 0,
        ]);
        $this->seedStep($legacy, 1, 'completed', null, [], ['ended_at' => $this->stamp('2026-10-12 10:20:00')]);
        $this->seedStep($legacy, 2, 'in_progress', null, [], ['started_at' => $this->stamp('2026-10-12 10:25:00')]);

        $current = $this->seedRun($this->userId);
        $this->seedStep($current, 1, 'completed', 'committed');

        $legacyProgress = $this->progress($legacy);

        $this->assertSame(array_keys($this->progress($current)), array_keys($legacyProgress), 'one shape for every run');
        $this->assertSame('working', $legacyProgress['state']);
        $this->assertSame(0, $legacyProgress['resume_count']);
        $this->assertSame(1, $legacyProgress['steps_completed']);
        $this->assertSame('2026-10-12T10:25:00.000000Z', $legacyProgress['last_progress_at'], 'a legacy run shows its latest step activity');
        $this->assertSame('unknown', $legacyProgress['remaining']);
    }

    // ---------------------------------------------------------------
    // Cost
    // ---------------------------------------------------------------

    #[Test]
    public function the_number_of_queries_does_not_grow_with_the_size_of_the_run(): void
    {
        $small = $this->richRun(steps: 2, helpers: 3);
        $large = $this->richRun(steps: 40, helpers: 12);

        $smallQueries = $this->countQueries(fn () => $this->progress($small));
        $largeQueries = $this->countQueries(fn () => $this->progress($large));

        $this->assertLessThanOrEqual(15, $largeQueries, 'a constant number of queries');
        $this->assertSame($smallQueries, $largeQueries, 'a bigger run costs no more queries');
    }

    private function richRun(int $steps, int $helpers): string
    {
        $runId = $this->seedRun($this->userId);
        for ($position = 1; $position <= $steps; $position++) {
            $this->seedStep($runId, $position, 'completed', 'committed');
        }
        $this->seedStep($runId, $steps + 1, 'in_progress', 'model_recorded', ['call_a' => $this->checkpointCall('started', 'delegate_to_helper', 'delegate_to_helper')]);
        $batch = (string) Str::uuid();
        for ($n = 0; $n < $helpers; $n++) {
            $this->seedDelegation($runId, $this->userId, $n % 2 === 0 ? 'completed' : 'in_progress', $batch);
        }
        $this->seedTask($runId, $this->userId);

        return $runId;
    }

    /** @param callable(): mixed $work */
    private function countQueries(callable $work): int
    {
        $count = 0;
        DB::listen(function (QueryExecuted $query) use (&$count) {
            $count++;
        });
        $work();

        return $count;
    }
}
