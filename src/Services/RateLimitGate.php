<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Exceptions\RateLimitExceededException;
use ClarionApp\LlmClient\ValueObjects\BudgetWorkKind;
use ClarionApp\LlmClient\ValueObjects\RateLimitDecision;

/**
 * The sole decision authority for whether a user's request may start.
 *
 * This is the only class in src/ that compares a Cache-backed request count
 * to a configured max_requests. RateLimitCounter never makes an admission
 * decision of its own; it only returns a raw reading.
 *
 * Two properties are easy to lose and expensive to lose quietly:
 *
 *  - **Nothing configured costs nothing.** evaluate() resolves the user's
 *    limit first and returns before RateLimitCounter is ever called when
 *    none applies — zero cache traffic for a user who has never opted in.
 *  - **A unit of work is admitted once per instance.** After a successful
 *    admission the user is remembered on this instance, and a later
 *    admit() call for that same user returns immediately without a second
 *    Cache round trip. This exists specifically for the
 *    MessageController::store() -> AgentLoopService::start() double-call
 *    site in one request — without it, one HTTP request that both stores a
 *    message and synchronously starts the turn would consume two units of
 *    the user's allowance for what the user experiences as one action. The
 *    memo is safe only because the binding is scoped(): a queue worker
 *    flushes scoped instances between jobs, so it can never become a
 *    standing pass.
 *
 * Unlike BudgetGate, this class has no system-initiated call site to
 * re-enter from — a rate limit protects how often a user starts something,
 * and system-initiated work is definitionally not that, so it is never
 * presented to this gate at all. There is therefore no nested-call
 * protection to build: the per-instance memo above exists solely for the
 * one legitimate double-call-site case, not to guard against re-entry from
 * inside an already-admitted turn.
 */
class RateLimitGate
{
    /**
     * Users already admitted during this request or job.
     *
     * @var array<string, true>
     */
    private array $admitted = [];

    public function __construct(
        private readonly RateLimitService $limits,
        private readonly RateLimitCounter $counter,
    ) {
    }

    /**
     * Decide, without ever throwing.
     */
    public function evaluate(string $userId): RateLimitDecision
    {
        $limit = $this->limits->resolveForUser($userId);

        // This branch must come first: it is the promise that a user with
        // nothing configured for them never reaches the counter at all.
        if ($limit === null) {
            return RateLimitDecision::noLimitConfigured();
        }

        $reading = $this->counter->increment($userId, (int) $limit->window_seconds);

        // An unreadable counter fails open unconditionally: there is no
        // reliable count to compare, so no comparison is attempted.
        if (!$reading->available) {
            return new RateLimitDecision(RateLimitDecision::ALLOW, $limit, $reading);
        }

        if ($reading->count > $limit->max_requests) {
            return new RateLimitDecision(
                RateLimitDecision::STOP,
                $limit,
                $reading,
                RateLimitDecision::composeReason($limit, $reading),
            );
        }

        return new RateLimitDecision(RateLimitDecision::ALLOW, $limit, $reading);
    }

    /**
     * Decide, and refuse the work if the decision is a stop.
     *
     * @throws RateLimitExceededException on a stop outcome, and nothing else.
     */
    public function admit(string $userId, BudgetWorkKind $kind, ?string $conversationId = null): void
    {
        // Already admitted in this request or job: the second call at the
        // one legitimate double-call site is not re-evaluated. See the
        // class comment.
        if (isset($this->admitted[$userId])) {
            return;
        }

        $decision = $this->evaluate($userId);

        if ($decision->isStop()) {
            throw new RateLimitExceededException($decision, $kind);
        }

        $this->admitted[$userId] = true;
    }
}
