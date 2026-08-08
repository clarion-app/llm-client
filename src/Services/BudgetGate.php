<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Events\SpendingEnforcementDegraded;
use ClarionApp\LlmClient\Exceptions\BudgetExceededException;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\ValueObjects\BudgetScope;
use ClarionApp\LlmClient\ValueObjects\BudgetWorkKind;
use ClarionApp\LlmClient\ValueObjects\ConsumptionSnapshot;
use ClarionApp\LlmClient\ValueObjects\EnforcementDecision;
use ClarionApp\LlmClient\ValueObjects\EnforcementMode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The sole decision authority on whether a unit of work may start.
 *
 * This is the only class in src/ that compares a consumption figure to a
 * ceiling amount. Everything else either asks it (the two funnels) or reads
 * what it decided (the 402 body, the recorded run, the warning payload).
 * Concentrating the comparison here is what makes "every kind of work is
 * evaluated by the same rule" a structural fact rather than a convention.
 *
 * Two properties are easy to lose and expensive to lose quietly:
 *
 *  - **Nothing configured costs nothing.** The short-circuit below comes
 *    before any ledger read, so an installation that has declared no ceiling
 *    issues no consumption query on any entry path. A version of this class
 *    that read consumption first and then found no ceiling would pass every
 *    behavioural test and quietly add a scan to every request.
 *  - **A unit of work is admitted once.** After a successful admission the
 *    scope is remembered on this instance, and a later admit() for it returns
 *    immediately. Without that, an embedding call made *inside* a live turn
 *    would be re-evaluated mid-turn and would throw the moment some other
 *    request crossed the ceiling — abandoning a half-built response, which is
 *    worse than the overshoot it would be preventing. The memory is safe only
 *    because the binding is scoped(): a queue worker flushes scoped instances
 *    between jobs, so it can never become a standing pass.
 *
 * All arithmetic is bcmath on plain-decimal strings. A float formed anywhere
 * here propagates straight into a currency comparison and into a number a
 * user reads.
 */
class BudgetGate
{
    /** Decimal places every stored monetary figure carries. */
    private const SCALE = 10;

    /** Cache key prefix for the degraded-notice throttle. */
    private const DEGRADED_THROTTLE_KEY = 'llm-client:budget:degraded-notice';

    /**
     * Scopes already admitted during this request or job, keyed by the same
     * scope key the ledger uses. Per-instance and never static.
     *
     * @var array<string, true>
     */
    private array $admitted = [];

    public function __construct(
        private readonly SpendingCeilingService $ceilings,
        private readonly BudgetLedger $ledger,
    ) {
    }

    /**
     * Decide, without ever throwing.
     *
     * A null $userId evaluates the installation scope alone: there is no user
     * whose ceiling could apply, and the user chain is not walked at all. That
     * is the shape EmbeddingService::generate() and RoleTestRunner::run() need,
     * both of which accept a nullable user id while traceSystemRun()'s own is
     * a plain string.
     */
    public function evaluate(?string $userId): EnforcementDecision
    {
        $installationCeiling = $this->ceilings->resolveInstallation();
        $userCeiling = $userId === null ? null : $this->ceilings->resolveForUser($userId);

        // This branch must come first: it is the promise that an installation
        // which has not opted in pays nothing for the feature.
        if ($installationCeiling === null && $userCeiling === null) {
            return EnforcementDecision::noCeilingConfigured();
        }

        $assessments = [];

        if ($installationCeiling !== null) {
            $assessments[] = $this->assess(
                $installationCeiling,
                BudgetScope::Installation->value,
                $this->ledger->forInstallation($installationCeiling->period_type),
            );
        }

        if ($userCeiling !== null && $userId !== null) {
            $assessments[] = $this->assess(
                $userCeiling,
                'user',
                $this->ledger->forUser($userId, $userCeiling->period_type),
            );
        }

        return $this->combine($assessments, $userId);
    }

    /**
     * Decide, and refuse the work if the decision is a stop.
     *
     * Returns normally on allow and on allow_with_warning alike — a warning
     * never blocks. The refusal is recorded exactly once, before the
     * exception leaves, so a stop is visible to an operator whether or not
     * anybody was waiting on a status code.
     *
     * @throws BudgetExceededException on a stop outcome, and nothing else.
     */
    public function admit(
        ?string $userId,
        BudgetWorkKind $kind,
        ?string $conversationId = null,
        ?string $source = null,
        ?string $existingRunId = null,
    ): void {
        $scopeKey = $this->scopeKey($userId);

        // Already admitted in this request or job: nested work inside a live
        // unit is not re-evaluated. See the class comment — this is what keeps
        // an in-flight response from being abandoned mid-build.
        if (isset($this->admitted[$scopeKey])) {
            return;
        }

        $decision = $this->evaluate($userId);

        if ($decision->isStop()) {
            app(RunTraceRecorder::class)->recordRefusedRun(
                $userId,
                $conversationId,
                $source,
                (string) $decision->reason,
                $kind,
                $existingRunId,
            );

            throw new BudgetExceededException($decision, $kind);
        }

        $this->admitted[$scopeKey] = true;
    }

    /**
     * One ceiling measured against one figure.
     *
     * @return array{
     *   ceiling: SpendingCeiling,
     *   axis: string,
     *   snapshot: ConsumptionSnapshot,
     *   reached: bool,
     *   thresholdCrossed: bool,
     *   headroom: ?string,
     * }
     */
    private function assess(SpendingCeiling $ceiling, string $axis, ConsumptionSnapshot $snapshot): array
    {
        $amount = (string) $ceiling->amount;

        if (!$snapshot->available) {
            return [
                'ceiling' => $ceiling,
                'axis' => $axis,
                'snapshot' => $snapshot,
                'reached' => false,
                'thresholdCrossed' => false,
                'headroom' => null,
            ];
        }

        $consumption = (string) $snapshot->amount;

        // "Reached" is at-or-above, not strictly above: a scope that has spent
        // exactly its ceiling has no headroom left.
        $reached = bccomp($consumption, $amount, self::SCALE) >= 0;

        $thresholdAmount = bcmul($amount, (string) $ceiling->approach_threshold, self::SCALE);
        $thresholdCrossed = bccomp($consumption, $thresholdAmount, self::SCALE) >= 0;

        return [
            'ceiling' => $ceiling,
            'axis' => $axis,
            'snapshot' => $snapshot,
            'reached' => $reached,
            'thresholdCrossed' => $thresholdCrossed,
            // Signed, deliberately unfloored: a scope 30 over its ceiling is
            // tighter than one 10 over, and flooring both at zero would make
            // every over-spent ceiling tie with every other.
            'headroom' => bcsub($amount, $consumption, self::SCALE),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $assessments
     */
    private function combine(array $assessments, ?string $userId): EnforcementDecision
    {
        $unreadable = array_values(array_filter($assessments, fn (array $a) => !$a['snapshot']->available));

        if ($unreadable !== []) {
            $decision = $this->handleUnreadable($unreadable, $userId);

            if ($decision !== null) {
                return $decision;
            }
        }

        $readable = array_values(array_filter($assessments, fn (array $a) => $a['snapshot']->available));

        $stopping = array_values(array_filter(
            $readable,
            fn (array $a) => $a['reached'] && $a['ceiling']->enforcement_mode === EnforcementMode::Stop->value
        ));

        if ($stopping !== []) {
            return $this->decisionFrom($stopping, EnforcementDecision::STOP, degraded: false);
        }

        $warning = array_values(array_filter(
            $readable,
            fn (array $a) => $a['reached'] || $a['thresholdCrossed']
        ));

        if ($warning !== []) {
            return $this->decisionFrom($warning, EnforcementDecision::ALLOW_WITH_WARNING, degraded: false);
        }

        if ($readable !== []) {
            return $this->decisionFrom($readable, EnforcementDecision::ALLOW, degraded: false);
        }

        return EnforcementDecision::noCeilingConfigured();
    }

    /**
     * The figure could not be read.
     *
     * Logged on every occurrence — the log is the complete record, even where
     * the broadcast is throttled — and announced to operators at most once per
     * throttle window while the condition persists.
     *
     * The configured policy bites in exactly one place: a ceiling in STOP mode
     * whose figure is unreadable. A warn-only ceiling never blocks, however the
     * read went, because a warn ceiling that starts refusing work over a query
     * timeout is a capability reduction nobody asked for.
     *
     * @param  list<array<string, mixed>>  $unreadable
     * @return EnforcementDecision|null null when nothing here forces a stop,
     *                                  leaving the readable ceilings to decide.
     */
    private function handleUnreadable(array $unreadable, ?string $userId): ?EnforcementDecision
    {
        $policy = (string) config('llm-client.budget.on_unreadable_consumption', 'stop');

        foreach ($unreadable as $assessment) {
            Log::warning('Budget enforcement is degraded: consumption could not be read', [
                'scope' => $assessment['axis'],
                'scope_id' => $assessment['axis'] === 'user' ? $userId : null,
                'period_type' => $assessment['ceiling']->period_type,
                'enforcement_mode' => $assessment['ceiling']->enforcement_mode,
                'policy' => $policy,
            ]);

            $this->announceDegraded($assessment, $userId, $policy);
        }

        if ($policy !== 'stop') {
            return null;
        }

        $blocking = array_values(array_filter(
            $unreadable,
            fn (array $a) => $a['ceiling']->enforcement_mode === EnforcementMode::Stop->value
        ));

        if ($blocking === []) {
            return null;
        }

        // No figure means no headroom to compare, so the tie-break rule alone
        // chooses: installation first.
        $governing = $this->installationFirst($blocking);

        return new EnforcementDecision(
            outcome: EnforcementDecision::STOP,
            governingCeiling: $governing['ceiling'],
            snapshot: $governing['snapshot'],
            degraded: true,
            reason: EnforcementDecision::composeReason(
                $governing['ceiling'],
                $governing['snapshot'],
                $governing['axis'],
                degraded: true,
            ),
        );
    }

    /**
     * At most one degraded broadcast per throttle window. Cache::add is the
     * test-and-set: it returns true only for the process that wrote the key.
     */
    private function announceDegraded(array $assessment, ?string $userId, string $policy): void
    {
        try {
            $seconds = (int) config('llm-client.budget.degraded_notice_throttle_seconds', 60);
            $scopeId = $assessment['axis'] === 'user' ? $userId : null;
            $key = self::DEGRADED_THROTTLE_KEY.':'.$assessment['axis'].':'.($scopeId ?? 'installation');

            if ($seconds > 0 && !Cache::add($key, true, $seconds)) {
                return;
            }

            event(new SpendingEnforcementDegraded(
                scopeType: $assessment['axis'],
                scopeId: $scopeId,
                periodType: $assessment['ceiling']->period_type,
                policy: $policy,
            ));
        } catch (\Throwable $e) {
            // Announcing a degraded state must never itself become the fault
            // that fails the request.
            Log::warning('Failed to announce degraded budget enforcement', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $candidates  never empty
     */
    private function decisionFrom(array $candidates, string $outcome, bool $degraded): EnforcementDecision
    {
        $governing = $this->tightest($candidates);

        return new EnforcementDecision(
            outcome: $outcome,
            governingCeiling: $governing['ceiling'],
            snapshot: $governing['snapshot'],
            degraded: $degraded,
            reason: EnforcementDecision::composeReason(
                $governing['ceiling'],
                $governing['snapshot'],
                $governing['axis'],
                $degraded,
            ),
        );
    }

    /**
     * The ceiling with the smallest remaining headroom, ties broken
     * installation-first.
     *
     * Determinism is the point: a refusal has to name the ceiling that
     * actually stopped the work, and "whichever matched first" makes that
     * claim untestable. The installation ceiling wins a tie because it is the
     * one a user-scoped waiver cannot remove, and so the more useful thing to
     * tell the caller.
     *
     * @param  list<array<string, mixed>>  $candidates  never empty
     * @return array<string, mixed>
     */
    private function tightest(array $candidates): array
    {
        $best = null;

        foreach ($candidates as $candidate) {
            if ($best === null) {
                $best = $candidate;
                continue;
            }

            $comparison = bccomp((string) $candidate['headroom'], (string) $best['headroom'], self::SCALE);

            if ($comparison < 0) {
                $best = $candidate;
                continue;
            }

            if ($comparison === 0 && $candidate['axis'] === BudgetScope::Installation->value) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * @param  list<array<string, mixed>>  $candidates  never empty
     * @return array<string, mixed>
     */
    private function installationFirst(array $candidates): array
    {
        foreach ($candidates as $candidate) {
            if ($candidate['axis'] === BudgetScope::Installation->value) {
                return $candidate;
            }
        }

        return $candidates[0];
    }

    private function scopeKey(?string $userId): string
    {
        return $userId === null ? 'installation' : 'user:'.$userId;
    }
}
