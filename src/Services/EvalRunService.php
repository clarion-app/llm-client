<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Jobs\RunEvalCaseJob;
use ClarionApp\LlmClient\Models\EvalCaseResult;
use ClarionApp\LlmClient\Models\EvalRun;
use ClarionApp\LlmClient\Models\EvalRunCase;
use ClarionApp\LlmClient\Models\EvalSuite;
use ClarionApp\LlmClient\ValueObjects\EvalRunCaseStatus;
use ClarionApp\LlmClient\ValueObjects\EvalRunStatus;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use Illuminate\Support\Facades\DB;

/**
 * The sole write path for eval_runs/eval_run_cases (research.md D7/D12,
 * data-model.md §1/§5). start() and summarize() are this file's User
 * Story 1 surface; resume() is added in Phase 5 (US3).
 */
class EvalRunService
{
    public function __construct(
        private readonly RoleResolver $roleResolver,
    ) {
    }

    /**
     * Starts a new run: resolves the installation's one effective
     * inference configuration once, up front (research.md D12), before
     * ever writing an eval_run_cases row. When resolution fails, the run
     * fails as a whole with one clear reason (FR-014/SC-006) and zero
     * eval_run_cases rows are ever written. When it succeeds, the suite's
     * live cases are snapshotted once, inside one transaction
     * (research.md D7), and only after that transaction commits is one
     * real RunEvalCaseJob dispatched per case.
     */
    public function start(EvalSuite $suite): EvalRun
    {
        $run = EvalRun::create([
            'suite_id' => $suite->id,
            'agent_label' => $suite->agent_identifier,
            'status' => EvalRunStatus::InProgress,
            'case_count' => 0,
            'started_at' => now(),
        ]);

        $resolution = $this->roleResolver->resolve(ModelRole::Inference, null);

        if (!$resolution->hasEffectiveModel()) {
            $run->update([
                'status' => EvalRunStatus::FailedToStart,
                'failure_reason' => $resolution->brokenReason
                    ?? 'No inference model is assigned. Configure one in LLM settings, or a server first if none exist.',
                'completed_at' => now(),
            ]);

            return $run->fresh();
        }

        $evalRunCases = DB::transaction(function () use ($suite, $run, $resolution) {
            $position = 0;
            $created = [];

            foreach ($suite->cases as $case) {
                $created[] = EvalRunCase::create([
                    'run_id' => $run->id,
                    'eval_case_id' => $case->id,
                    'eval_case_version_id' => $case->current_version_id,
                    'position' => $position++,
                    'status' => EvalRunCaseStatus::Pending,
                    'dispatch_attempts' => 0,
                ]);
            }

            $run->update([
                'server_id' => $resolution->server->id,
                'model' => $resolution->model,
                'case_count' => count($created),
            ]);

            return $created;
        });

        foreach ($evalRunCases as $evalRunCase) {
            RunEvalCaseJob::dispatch($run->id, $evalRunCase->id)
                ->onQueue(config('llm-client.eval_runs.queue', 'eval-runs'));

            $evalRunCase->update([
                'status' => EvalRunCaseStatus::Dispatched,
                'dispatch_attempts' => 1,
            ]);
        }

        return $run->fresh();
    }

    /**
     * The read-time overall-result / progress aggregate (data-model.md
     * §5) — never a stored column, computed fresh from eval_case_results
     * on every call.
     *
     * @return array{total: int, pass: int, fail: int, needs_human_review: int, errored: int, completed_count: int, remaining_count: int, overall: ?string}
     */
    public function summarize(EvalRun $run): array
    {
        $counts = DB::table('eval_case_results')
            ->where('run_id', $run->id)
            ->selectRaw('outcome, COUNT(*) as total')
            ->groupBy('outcome')
            ->pluck('total', 'outcome')
            ->all();

        $pass = (int) ($counts['pass'] ?? 0);
        $fail = (int) ($counts['fail'] ?? 0);
        $needsHumanReview = (int) ($counts['needs_human_review'] ?? 0);
        $errored = (int) ($counts['errored'] ?? 0);
        $completedCount = $pass + $fail + $needsHumanReview + $errored;

        $overall = match (true) {
            $run->status !== EvalRunStatus::Completed => null,
            $fail > 0 || $errored > 0 => 'fail',
            $needsHumanReview > 0 => 'needs_human_review',
            default => 'pass',
        };

        return [
            'total' => $run->case_count,
            'pass' => $pass,
            'fail' => $fail,
            'needs_human_review' => $needsHumanReview,
            'errored' => $errored,
            'completed_count' => $completedCount,
            'remaining_count' => $run->case_count - $completedCount,
            'overall' => $overall,
        ];
    }
}
