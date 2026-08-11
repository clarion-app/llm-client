<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Jobs\RunEvalCaseJob;
use ClarionApp\LlmClient\Models\EvalCaseResult;
use ClarionApp\LlmClient\Models\EvalRun;
use ClarionApp\LlmClient\Models\EvalRunCase;
use ClarionApp\LlmClient\Models\EvalSuite;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\EvalCaseService;
use ClarionApp\LlmClient\Services\EvalRunService;
use ClarionApp\LlmClient\Services\EvalSuiteService;
use ClarionApp\LlmClient\ValueObjects\EvalCaseOutcome;
use ClarionApp\LlmClient\ValueObjects\EvalRunCaseStatus;
use ClarionApp\LlmClient\ValueObjects\EvalRunStatus;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * research.md D7/D12, data-model.md §1/§5: EvalRunService::start()
 * snapshots a suite's live cases into eval_run_cases before any
 * RunEvalCaseJob is dispatched, resolves the installation's one effective
 * inference configuration once up front (failing the whole run cleanly,
 * with zero eval_run_cases rows, when there is none), and summarize()
 * computes the read-time overall-result/progress aggregate exactly per
 * data-model.md §5's formula.
 */
class EvalRunServiceTest extends TestCase
{
    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function suite(): EvalSuite
    {
        return app(EvalSuiteService::class)->create('Run service fixture suite', 'home-automation-agent');
    }

    private function addCase(EvalSuite $suite, string $given = 'given')
    {
        return app(EvalCaseService::class)->addCase(
            $suite,
            $given,
            'expected behavior',
            [['kind' => 'text_match', 'expected_text' => 'ok']],
        );
    }

    private function assignInstallationInferenceRole(): Server
    {
        $server = Server::create([
            'name' => 'Fixture server',
            'server_url' => 'https://example.test/v1/chat/completions',
            'provider_type' => 'openai',
        ]);

        RoleAssignment::create([
            'role' => 'inference',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $server->id,
            'model' => 'test-model',
        ]);

        return $server;
    }

    private function makeRun(EvalRunStatus $status, int $caseCount): EvalRun
    {
        $run = new EvalRun();
        $run->suite_id = (string) Str::uuid();
        $run->agent_label = 'home-automation-agent';
        $run->status = $status;
        $run->case_count = $caseCount;
        $run->started_at = now();
        $run->save();

        return $run;
    }

    private function makeResult(EvalRun $run, EvalCaseOutcome $outcome): EvalCaseResult
    {
        return EvalCaseResult::create([
            'run_id' => $run->id,
            'eval_run_case_id' => (string) Str::uuid(),
            'eval_case_id' => (string) Str::uuid(),
            'eval_case_version_id' => (string) Str::uuid(),
            'conversation_id' => (string) Str::uuid(),
            'outcome' => $outcome,
            'produced_response' => 'a response',
            'attempted_actions' => [],
            'expectation_results' => [],
            'error_message' => null,
        ]);
    }

    // ---------------------------------------------------------------
    // start() — snapshot happens before dispatch (research.md D7,
    // mutation-checklist row 9)
    // ---------------------------------------------------------------

    #[Test]
    public function start_snapshots_every_live_case_before_any_job_is_dispatched(): void
    {
        Bus::fake([RunEvalCaseJob::class]);

        $this->assignInstallationInferenceRole();
        $suite = $this->suite();
        $caseA = $this->addCase($suite, 'first given');
        $caseB = $this->addCase($suite, 'second given');
        $caseC = $this->addCase($suite, 'third given');

        $run = app(EvalRunService::class)->start($suite);

        $this->assertSame(EvalRunStatus::InProgress, $run->status);
        $this->assertSame(3, $run->case_count);

        // The snapshot rows exist and are correct regardless of whether
        // dispatch is faked/inspected — proving the write is not a side
        // effect of dispatching, but happens (and is durable) before it.
        $snapshotRows = EvalRunCase::where('run_id', $run->id)->orderBy('position')->get();
        $this->assertCount(3, $snapshotRows);
        $this->assertSame($caseA->id, $snapshotRows[0]->eval_case_id);
        $this->assertSame($caseA->current_version_id, $snapshotRows[0]->eval_case_version_id);
        $this->assertSame(0, $snapshotRows[0]->position);
        $this->assertSame($caseB->id, $snapshotRows[1]->eval_case_id);
        $this->assertSame($caseB->current_version_id, $snapshotRows[1]->eval_case_version_id);
        $this->assertSame(1, $snapshotRows[1]->position);
        $this->assertSame($caseC->id, $snapshotRows[2]->eval_case_id);
        $this->assertSame($caseC->current_version_id, $snapshotRows[2]->eval_case_version_id);
        $this->assertSame(2, $snapshotRows[2]->position);

        // mutation-checklist row 9's real, synchronously-provable target:
        // "snapshot before dispatch" cannot be observed as literal
        // wall-clock interleaving in a single-process test (dispatch is
        // faked, so nothing ever actually races against the snapshot
        // write) — but what it must guarantee for correctness is that,
        // by the moment start() hands control back, the whole
        // snapshot-then-dispatch cycle has completed as one atomic unit
        // for every case, not just some. A partial/interleaved
        // implementation — e.g. one that dispatches before the snapshot
        // row it refers to is durably marked as dispatched, or that
        // leaves a case behind mid-loop — would leave at least one row
        // here still `pending` with `dispatch_attempts = 0` even though
        // Bus recorded every dispatch call.
        foreach ($snapshotRows as $row) {
            $this->assertSame(
                EvalRunCaseStatus::Dispatched,
                $row->status,
                "eval_run_case {$row->id} must already be marked dispatched by the time start() returns",
            );
            $this->assertSame(1, $row->dispatch_attempts);
        }

        Bus::assertDispatchedTimes(RunEvalCaseJob::class, 3);
    }

    // ---------------------------------------------------------------
    // start() — no effective inference model (FR-014/SC-006,
    // mutation-checklist row 11)
    // ---------------------------------------------------------------

    #[Test]
    public function start_fails_the_whole_run_cleanly_and_writes_zero_case_rows_when_no_inference_model_is_assigned(): void
    {
        Bus::fake([RunEvalCaseJob::class]);

        // No RoleAssignment row of any kind exists — RoleResolver resolves
        // "unassigned".
        $suite = $this->suite();
        $this->addCase($suite);
        $this->addCase($suite);

        $run = app(EvalRunService::class)->start($suite);

        $this->assertSame(EvalRunStatus::FailedToStart, $run->status);
        $this->assertNotEmpty($run->failure_reason);
        $this->assertSame(0, $run->case_count);
        $this->assertSame(0, EvalRunCase::where('run_id', $run->id)->count());
        Bus::assertNothingDispatched();
    }

    #[Test]
    public function start_fails_the_whole_run_cleanly_when_the_assigned_role_is_broken(): void
    {
        Bus::fake([RunEvalCaseJob::class]);

        $server = $this->assignInstallationInferenceRole();
        $server->delete(); // soft-deleted server — RoleResolver::isBroken()

        $suite = $this->suite();
        $this->addCase($suite);

        $run = app(EvalRunService::class)->start($suite);

        $this->assertSame(EvalRunStatus::FailedToStart, $run->status);
        $this->assertNotEmpty($run->failure_reason);
        $this->assertSame(0, $run->case_count);
        $this->assertSame(0, EvalRunCase::where('run_id', $run->id)->count());
        Bus::assertNothingDispatched();
    }

    // ---------------------------------------------------------------
    // start() — resolution succeeds: server_id/model snapshot + status
    // ---------------------------------------------------------------

    #[Test]
    public function start_snapshots_the_resolved_server_and_model_when_resolution_succeeds(): void
    {
        Bus::fake([RunEvalCaseJob::class]);

        $server = $this->assignInstallationInferenceRole();
        $suite = $this->suite();
        $this->addCase($suite);

        $run = app(EvalRunService::class)->start($suite);

        $this->assertSame(EvalRunStatus::InProgress, $run->status);
        $this->assertSame($server->id, $run->server_id);
        $this->assertSame('test-model', $run->model);
    }

    // ---------------------------------------------------------------
    // summarize() — data-model.md §5's formula
    // ---------------------------------------------------------------

    #[Test]
    public function summarize_computes_counts_and_leaves_overall_null_while_a_run_is_still_in_progress_even_if_every_case_so_far_passed(): void
    {
        $run = $this->makeRun(EvalRunStatus::InProgress, 3);
        $this->makeResult($run, EvalCaseOutcome::Pass);
        $this->makeResult($run, EvalCaseOutcome::Pass);

        $summary = app(EvalRunService::class)->summarize($run);

        $this->assertSame(3, $summary['total']);
        $this->assertSame(2, $summary['pass']);
        $this->assertSame(0, $summary['fail']);
        $this->assertSame(0, $summary['needs_human_review']);
        $this->assertSame(0, $summary['errored']);
        $this->assertSame(2, $summary['completed_count']);
        $this->assertSame(1, $summary['remaining_count']);
        $this->assertNull(
            $summary['overall'],
            'overall must stay null until the run itself is completed, even if every case seen so far passed'
        );
    }

    #[Test]
    public function summarize_reports_fail_overall_when_a_completed_run_has_any_failed_or_errored_case(): void
    {
        $run = $this->makeRun(EvalRunStatus::Completed, 3);
        $this->makeResult($run, EvalCaseOutcome::Pass);
        $this->makeResult($run, EvalCaseOutcome::Fail);
        $this->makeResult($run, EvalCaseOutcome::Errored);

        $summary = app(EvalRunService::class)->summarize($run);

        $this->assertSame(3, $summary['completed_count']);
        $this->assertSame(0, $summary['remaining_count']);
        $this->assertSame('fail', $summary['overall']);
    }

    #[Test]
    public function summarize_reports_needs_human_review_overall_when_a_completed_run_has_no_failures_but_one_needs_review(): void
    {
        $run = $this->makeRun(EvalRunStatus::Completed, 2);
        $this->makeResult($run, EvalCaseOutcome::Pass);
        $this->makeResult($run, EvalCaseOutcome::NeedsHumanReview);

        $summary = app(EvalRunService::class)->summarize($run);

        $this->assertSame('needs_human_review', $summary['overall']);
    }

    #[Test]
    public function summarize_reports_pass_overall_when_a_completed_run_has_only_passes(): void
    {
        $run = $this->makeRun(EvalRunStatus::Completed, 2);
        $this->makeResult($run, EvalCaseOutcome::Pass);
        $this->makeResult($run, EvalCaseOutcome::Pass);

        $summary = app(EvalRunService::class)->summarize($run);

        $this->assertSame('pass', $summary['overall']);
    }

    #[Test]
    public function summarize_counts_an_unjudged_case_as_completed_and_never_reports_the_run_as_an_outright_pass(): void
    {
        $run = $this->makeRun(EvalRunStatus::Completed, 2);
        $this->makeResult($run, EvalCaseOutcome::Pass);
        $this->makeResult($run, EvalCaseOutcome::Unjudged);

        $summary = app(EvalRunService::class)->summarize($run);

        $this->assertSame(1, $summary['unjudged']);
        $this->assertSame(
            2,
            $summary['completed_count'],
            'an unjudged case has a written result row like any other — leaving it out would report work still remaining that is already done'
        );
        $this->assertSame(0, $summary['remaining_count']);
        $this->assertSame(
            'unjudged',
            $summary['overall'],
            'a run containing a case the judge could never score must never roll up to an outright pass'
        );
    }

    #[Test]
    public function summarize_keeps_fail_ahead_of_unjudged_and_unjudged_ahead_of_needs_human_review(): void
    {
        $failing = $this->makeRun(EvalRunStatus::Completed, 2);
        $this->makeResult($failing, EvalCaseOutcome::Fail);
        $this->makeResult($failing, EvalCaseOutcome::Unjudged);

        $this->assertSame(
            'fail',
            app(EvalRunService::class)->summarize($failing)['overall'],
            'a real, known failure elsewhere in the run outranks a case the judge could not score'
        );

        $unscored = $this->makeRun(EvalRunStatus::Completed, 2);
        $this->makeResult($unscored, EvalCaseOutcome::NeedsHumanReview);
        $this->makeResult($unscored, EvalCaseOutcome::Unjudged);

        $this->assertSame(
            'unjudged',
            app(EvalRunService::class)->summarize($unscored)['overall'],
            'an unknown outranks a by-design "a human must decide"'
        );
    }
}
