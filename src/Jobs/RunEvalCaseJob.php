<?php

namespace ClarionApp\LlmClient\Jobs;

use ClarionApp\LlmClient\Services\EvalCaseExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;

/**
 * One real, individually-dispatched queue job per eval-run case
 * (research.md D5) — not one job for the whole run. A worker-level
 * $timeout/failed() hook only fires for a job actually pulled off a real
 * queue, which is why FR-013's bounded-wait guarantee needs a genuine
 * per-case dispatch rather than an inline loop.
 *
 * Deliberately no automatic retry (tries = 1) — this feature's own
 * resumption mechanism (research.md D8), not Laravel's queue retry, is
 * the recovery path.
 */
class RunEvalCaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout;

    public int $tries = 1;

    public function __construct(
        public readonly string $runId,
        public readonly string $evalRunCaseId,
    ) {
        $this->timeout = (int) config('llm-client.eval_runs.case_timeout_seconds', 300);
    }

    public function handle(EvalCaseExecutor $executor): void
    {
        $executor->execute($this->runId, $this->evalRunCaseId);
    }

    /**
     * Bounds how many case executions may be admitted to run per minute,
     * installation-wide (research.md D9) — independent of BudgetGate
     * (money) and independent of how many cases a single run enqueues at
     * once. The named 'eval-run-cases' limiter is registered in
     * LlmClientServiceProvider.
     *
     * @return array<int, RateLimited>
     */
    public function middleware(): array
    {
        return [new RateLimited('eval-run-cases')];
    }

    public function failed(\Throwable $e): void
    {
        app(EvalCaseExecutor::class)->recordTimeoutOrFailure($this->runId, $this->evalRunCaseId, $e);
    }
}
