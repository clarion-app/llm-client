<?php

namespace Tests\RealDatabase;

use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * quickstart M24's target, on the real engine: a 500-character end_reason
 * must close the run and the step on strict MySQL/MariaDB.
 *
 * Red now: closeRun() and closeStep() write the reason uncut, so the
 * terminal UPDATE is refused ("Data too long for column 'end_reason'") and
 * swallowed by the methods' own isolation — both rows stay in_progress and
 * the run is never closed.
 */
#[Group('real-db')]
class RunEndReasonLengthTest extends RealDatabaseTestCase
{
    protected array $seedTables = ['agent_runs', 'agent_run_steps', 'agent_run_actions'];

    #[Test]
    public function a_500_character_multibyte_reason_closes_the_run_and_the_step_on_strict_mysql(): void
    {
        $this->assertReady();
        $this->app['config']->set('llm-client.run_trace.enabled', true);

        $recorder = app(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, (string) Str::uuid());
        $this->assertNotNull($runId, 'fixture sanity: the real recorder opened a run');
        $stepId = $recorder->openStep($runId);
        $this->assertNotNull($stepId, 'fixture sanity: the real recorder opened a step');

        $reason = str_repeat('é', 500);
        $recorder->closeStep($stepId, RunEndState::Failed, $reason);
        $recorder->closeRun($runId, RunEndState::Failed, $reason);

        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertSame('failed', $run->end_state, 'the run closed; strict mode did not refuse the terminal write');
        $this->assertLessThanOrEqual(256, mb_strlen((string) $run->end_reason), 'end_reason is cut to 256 characters');
        $this->assertSame(mb_substr($reason, 0, 256), $run->end_reason, 'the cut keeps the first 256 characters');

        $step = DB::table('agent_run_steps')->where('id', $stepId)->first();
        $this->assertSame('failed', $step->end_state, 'the step closed; strict mode did not refuse the terminal write');
        $this->assertLessThanOrEqual(256, mb_strlen((string) $step->end_reason), 'the step end_reason is cut to 256 characters');
        $this->assertSame(mb_substr($reason, 0, 256), $step->end_reason, 'the cut keeps the first 256 characters');
    }
}
