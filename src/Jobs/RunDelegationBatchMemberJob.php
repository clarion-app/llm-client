<?php

namespace ClarionApp\LlmClient\Jobs;

use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Services\DelegationConcurrencyGate;
use ClarionApp\LlmClient\Services\DelegationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * 101-parallel-subagent-execution (US1, contracts §5, research.md D1/D4).
 *
 * One real, individually-dispatched queue job per concurrent batch member
 * -- a direct generalization of RunEvalCaseJob's exact shape (Grounding
 * note item 7): ShouldQueue + the four standard traits, a constructor-set
 * $timeout sourced from the SAME per-member bound
 * (llm-client.delegation.max_seconds) 098 already applies to a single
 * delegation's nested run() call, $tries = 1 (no Laravel-level auto-retry
 * -- "waiting for a slot" is handled explicitly via release(), never
 * conflated with "this attempt failed and should be retried"), and a
 * failed() hook mirroring RunEvalCaseJob::failed() exactly.
 *
 * Deliberately no middleware() override: the concurrency throttle IS
 * DelegationConcurrencyGate::tryAdmit() itself, not a queue-level rate
 * limiter -- stacking RunEvalCaseJob's own RateLimited middleware on top
 * would fight the gate rather than complement it.
 */
class RunDelegationBatchMemberJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout;

    public int $tries = 1;

    public function __construct(public readonly string $delegationId)
    {
        $this->timeout = (int) config('llm-client.delegation.max_seconds', 120);
    }

    public function handle(DelegationConcurrencyGate $gate, DelegationService $service): void
    {
        $delegation = Delegation::find($this->delegationId);

        // Idempotency guard (contracts §4's precondition note, mirrors
        // EvalCaseExecutor::execute()'s own redelivery handling): a
        // redelivered job whose row has already been admitted by an
        // earlier delivery, or has already reached a terminal status by
        // some other path (the parent's own join-wait deadline, or the
        // stale-batch sweep), is a no-op -- tryAdmit() is never even
        // called for a row no longer 'queued'.
        if ($delegation === null || $delegation->status !== 'queued') {
            return;
        }

        if (!$gate->tryAdmit($delegation->batch_id, $delegation->id)) {
            // A full ceiling is an ordinary, expected condition under load
            // -- release the job back onto the queue after the configured
            // delay rather than treating this as a failure (contracts §4).
            $this->release((int) config('llm-client.delegation.concurrency.admission_retry_delay_seconds', 2));

            return;
        }

        $service->runBatchMember($delegation->fresh());
    }

    public function failed(\Throwable $e): void
    {
        app(DelegationService::class)->recordBatchMemberTimeoutOrFailure($this->delegationId, $e);
    }
}
