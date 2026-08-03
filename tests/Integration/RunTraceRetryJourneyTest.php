<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use Illuminate\Support\Facades\DB;

/**
 * FR-011 driven through the real retry path (T029, research §D5a).
 *
 * A round that internally retries must stay ONE step whose duration includes its
 * retries, with `attempt_count` reflecting them — not two steps of one attempt
 * each, and no gap in `position` where the retry consumed a loop iteration.
 *
 * The distinction that matters here is *how* the retry is produced. Asserting it
 * by calling `RunTraceRecorder::recordStepAttempt()` directly proves only that the
 * recorder increments a column; it says nothing about whether `AgentLoopService`
 * keeps the step open when its schema-validation retry re-enters the loop, which
 * is the behaviour FR-011 actually constrains. So the retry here is provoked the
 * way production provokes one: a scripted first response that violates the
 * configured schema, with `retry_on_validation_failure` on.
 */
class RunTraceRetryJourneyTest extends AssembledSystemTestCase
{
    /** The schema the first scripted response violates and the second satisfies. */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'answer' => ['type' => 'string'],
            ],
            'required' => ['answer'],
        ];
    }

    public function test_retried_round_stays_one_step_with_incremented_attempt_count(): void
    {
        $this->scenario = 'retried_round_stays_one_step';
        $this->entryPath = 'sync';

        $fixture = $this->fixture()->build();

        $this->app['config']->set('llm-client.run_trace.enabled', true);

        // First response is not valid against the schema; the correction round
        // that follows it is. Two model calls, one round.
        $this->script()
            ->finalAnswer('I am afraid I have replied in prose rather than JSON.')
            ->finalAnswer(json_encode(['answer' => 'Corrected.']));

        $result = $this->app->make(AgentLoopService::class)->run(
            $fixture->conversation,
            'Answer in the required shape.',
            [
                'schema' => $this->schema(),
                'retry_on_validation_failure' => true,
                'max_schema_retries' => 2,
            ],
        );

        $this->assertSame('completed', $result['status']);

        $run = DB::table('agent_runs')
            ->where('conversation_id', $fixture->conversation->id)
            ->first();
        $this->assertNotNull($run, 'The run must be recorded');
        $this->assertEquals(RunEndState::Completed->value, $run->end_state);

        $steps = DB::table('agent_run_steps')
            ->where('run_id', $run->id)
            ->orderBy('position')
            ->get();

        // The retry did not open a second step (FR-011).
        $this->assertCount(
            1,
            $steps,
            'A round that retried internally is one step, not one step per model call',
        );

        // ...and the retry is accounted for on that step (SC-002: the calls are
        // not lost just because they share a step).
        $this->assertEquals(
            2,
            (int) $steps[0]->attempt_count,
            'Both model calls of the retried round are accounted for',
        );

        // The step ordinal is contiguous — no gap where the retry consumed an
        // iteration of the loop (research §D5a).
        $this->assertEquals(1, (int) $steps[0]->position);
        $this->assertEquals(1, (int) $run->step_count);
    }

    /**
     * The companion property: a retry followed by further rounds still leaves
     * contiguous positions. Deriving `position` from the loop's `$iteration`
     * counter instead of a step ordinal would leave a hole at 2 here.
     */
    public function test_positions_stay_contiguous_after_a_retry(): void
    {
        $this->scenario = 'positions_stay_contiguous_after_a_retry';
        $this->entryPath = 'sync';

        $fixture = $this->fixture()->build();

        $this->app['config']->set('llm-client.run_trace.enabled', true);

        // Round 1 retries once, then asks for a tool; round 2 answers.
        $this->script()
            ->finalAnswer('Still prose, still wrong.')
            ->toolRequest('search_operations', ['query' => 'after the correction'])
            ->finalAnswer(json_encode(['answer' => 'Done.']));

        $result = $this->app->make(AgentLoopService::class)->run(
            $fixture->conversation,
            'Answer in the required shape.',
            [
                'schema' => $this->schema(),
                'retry_on_validation_failure' => true,
                'max_schema_retries' => 2,
            ],
        );

        $this->assertSame('completed', $result['status']);

        $run = DB::table('agent_runs')
            ->where('conversation_id', $fixture->conversation->id)
            ->first();
        $this->assertNotNull($run);

        $positions = DB::table('agent_run_steps')
            ->where('run_id', $run->id)
            ->orderBy('position')
            ->pluck('position')
            ->map(fn ($p) => (int) $p)
            ->all();

        $this->assertSame(
            range(1, count($positions)),
            $positions,
            'Positions are a contiguous 1..N sequence, with no gap for the retry',
        );
    }
}
