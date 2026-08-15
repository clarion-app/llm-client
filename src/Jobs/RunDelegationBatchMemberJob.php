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
 * delegation's nested run() call, $tries = 1 (no Laravel-level
 * *exception-driven* auto-retry -- "waiting for a slot" is handled
 * explicitly via release(), never conflated with "this attempt threw and
 * should be retried"), and a failed() hook mirroring RunEvalCaseJob::failed()
 * exactly.
 *
 * Deliberately no middleware() override: the concurrency throttle IS
 * DelegationConcurrencyGate::tryAdmit() itself, not a queue-level rate
 * limiter -- stacking RunEvalCaseJob's own RateLimited middleware on top
 * would fight the gate rather than complement it.
 *
 * retryUntil() (reconciliation fix, roadmap.implement step 5): $tries = 1
 * ALONE is not sufficient to let handle()'s own deliberate
 * $this->release() calls actually retry admission on a real, non-`sync`
 * queue connection. Confirmed empirically against a real `database`
 * connection and Laravel's own Worker::process(): attempts() increments
 * on every redelivery regardless of *why* the job was released, so the
 * VERY FIRST time tryAdmit() refuses and this job releases itself, the
 * next delivery's own attempts() (2) already exceeds tries (1) --
 * Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts() throws
 * MaxAttemptsExceededException and permanently fails the delegation
 * BEFORE handle() (and therefore tryAdmit()) is ever called again. This
 * was invisible to every test in this feature: the fast suite's
 * `queue.default=sync` never re-delivers a released job at all
 * (SyncJob::release() does not requeue), and DelegationConcurrencyCeilingTest
 * drives handle() directly rather than through a real queue/Worker cycle
 * -- so US4's own "a batch waiting for a slot admits the next member as
 * soon as one frees" guarantee (FR-006) was, on any real non-sync queue
 * connection, silently broken for every member beyond the ceiling: the
 * very first admission refusal would permanently fail it instead of
 * retrying. Declaring retryUntil() makes Laravel bypass the tries/attempts
 * check entirely until this deadline passes (Worker::
 * markJobAsFailedIfAlreadyExceedsMaxAttempts()'s own `$retryUntil &&
 * now <= $retryUntil` early-return), so admission may be retried freely
 * up to the same max_seconds + grace bound the parent's own join-wait
 * already applies to this exact member (DelegationService::
 * JOIN_WAIT_GRACE_SECONDS) -- after which a real worker's own next
 * delivery is finally allowed to fail it, by which point the parent's own
 * join-wait (or the stale-batch sweep) has already force-finalized the
 * row anyway.
 */
class RunDelegationBatchMemberJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout;

    public int $tries = 1;

    /** Captured once at (original) dispatch time -- survives every
     *  redelivery's unserialize() unchanged, so retryUntil() reflects a
     *  fixed point rather than perpetually sliding into the future. */
    private \DateTimeInterface $retryDeadline;

    public function __construct(public readonly string $delegationId)
    {
        $this->timeout = (int) config('llm-client.delegation.max_seconds', 120);
        $this->retryDeadline = now()->addSeconds($this->timeout + DelegationService::JOIN_WAIT_GRACE_SECONDS);
    }

    /**
     * Bypasses the $tries/attempts() check entirely while the deadline has
     * not yet passed (see this class's own docblock) -- the mechanism that
     * actually lets a refused admission's release() genuinely retry on a
     * real queue connection.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return $this->retryDeadline;
    }

    public function handle(DelegationConcurrencyGate $gate, DelegationService $service): void
    {
        // retryUntil() (above) necessarily also opens a second door: without
        // it, ANY uncaught exception here would already fail on its very
        // first attempt (tries=1, attempts()=1 >= maxTries=1 -- Worker::
        // markJobAsFailedIfWillExceedMaxAttempts()'s own check), matching
        // this class's documented "no Laravel-level exception-driven
        // auto-retry" intent. WITH retryUntil() set, that same check no
        // longer applies (Worker::markJobAsFailedIfWillExceedMaxAttempts()
        // requires `!retryUntil()` to fail-fast on an exception), so
        // Laravel's own handleJobException() would instead silently
        // release()-with-backoff an exception it never expected to retry --
        // confirmed empirically (a real `database` connection, one
        // deliberately-thrown exception: the row stayed 'in_progress' and
        // the job re-queued itself instead of failing). The try/catch below
        // restores the original fail-fast intent explicitly: any exception
        // NOT already handled inside DelegationService (i.e. anything
        // escaping runBatchMember() itself, before/outside
        // runDelegatedTask()'s own try/catch) fails this job immediately
        // via $this->fail(), which never throws back out to Worker::process()
        // -- so Laravel's own exception-driven retry path is never reached.
        // Only the deliberate release() call below (admission refusal) is
        // ever retried; that is what retryUntil() exists to protect.
        try {
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
                // A full ceiling is an ordinary, expected condition under
                // load -- release the job back onto the queue after the
                // configured delay rather than treating this as a failure
                // (contracts §4).
                $this->release((int) config('llm-client.delegation.concurrency.admission_retry_delay_seconds', 2));

                return;
            }

            $service->runBatchMember($delegation->fresh());
        } catch (\Throwable $e) {
            $this->fail($e);
        }
    }

    public function failed(\Throwable $e): void
    {
        app(DelegationService::class)->recordBatchMemberTimeoutOrFailure($this->delegationId, $e);
    }
}
