<?php

namespace Tests\Unit\Resume;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The F7 prerequisite (research.md F7, data-model.md §1/§2): closeRun() and
 * closeStep() must cut end_reason to 256 characters (multibyte-safe) before
 * their terminal UPDATE.
 *
 * Red now: on SQLite the uncut 500-character reason is stored whole, so the
 * length assertions fail while the run and step still close. The real-engine
 * half (tests/RealDatabase/RunEndReasonLengthTest) shows the other side: on
 * strict MySQL/MariaDB the uncut UPDATE is refused ("Data too long") and
 * swallowed by the methods' own isolation, leaving both rows in_progress.
 */
class RunTraceRecorderReasonLengthTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('llm-client.run_trace.enabled', true);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        DB::table('agent_run_steps')->delete();
        DB::table('agent_runs')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    private function recorder(): RunTraceRecorder
    {
        return app(RunTraceRecorder::class);
    }

    #[Test]
    public function close_run_cuts_a_500_character_multibyte_reason_to_256_and_still_closes(): void
    {
        $runId = $this->recorder()->openRun(RunKind::Interactive, $this->user->id);
        $this->assertNotNull($runId, 'fixture sanity: run tracing must produce a run id');

        $reason = str_repeat('é', 500);
        $this->recorder()->closeRun($runId, RunEndState::Failed, $reason);

        $row = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertSame('failed', $row->end_state, 'the run closed to its terminal state');
        $this->assertNotNull($row->end_reason, 'the reason was stored');
        $this->assertLessThanOrEqual(256, mb_strlen((string) $row->end_reason), 'end_reason is cut to 256 characters');
        $this->assertSame(mb_substr($reason, 0, 256), $row->end_reason, 'the cut keeps the first 256 characters');
    }

    #[Test]
    public function close_step_cuts_a_500_character_multibyte_reason_to_256_and_still_closes(): void
    {
        $runId = $this->recorder()->openRun(RunKind::Interactive, $this->user->id);
        $stepId = $this->recorder()->openStep($runId);
        $this->assertNotNull($stepId, 'fixture sanity: run tracing must produce a step id');

        $reason = str_repeat('é', 500);
        $this->recorder()->closeStep($stepId, RunEndState::Failed, $reason);

        $row = DB::table('agent_run_steps')->where('id', $stepId)->first();
        $this->assertSame('failed', $row->end_state, 'the step closed to its terminal state');
        $this->assertNotNull($row->end_reason, 'the reason was stored');
        $this->assertLessThanOrEqual(256, mb_strlen((string) $row->end_reason), 'end_reason is cut to 256 characters');
        $this->assertSame(mb_substr($reason, 0, 256), $row->end_reason, 'the cut keeps the first 256 characters');
    }

    #[Test]
    public function a_100_character_reason_is_stored_unchanged_on_both(): void
    {
        $runId = $this->recorder()->openRun(RunKind::Interactive, $this->user->id);
        $stepId = $this->recorder()->openStep($runId);

        $reason = str_repeat('x', 100);
        $this->recorder()->closeStep($stepId, RunEndState::Failed, $reason);
        $this->recorder()->closeRun($runId, RunEndState::Failed, $reason);

        $this->assertSame(
            $reason,
            DB::table('agent_run_steps')->where('id', $stepId)->value('end_reason'),
            'a short step reason is stored whole'
        );
        $this->assertSame(
            $reason,
            DB::table('agent_runs')->where('id', $runId)->value('end_reason'),
            'a short run reason is stored whole'
        );
    }
}
