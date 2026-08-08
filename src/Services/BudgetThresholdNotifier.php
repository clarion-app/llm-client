<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Events\SpendingCeilingReached;
use ClarionApp\LlmClient\Events\SpendingThresholdWarned;
use ClarionApp\LlmClient\Models\BudgetThresholdNotification;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Support\CalendarPeriod;
use ClarionApp\LlmClient\ValueObjects\BudgetScope;
use ClarionApp\LlmClient\ValueObjects\ConsumptionSnapshot;
use ClarionApp\LlmClient\ValueObjects\EnforcementDecision;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Decides whether a scope has just crossed something worth telling somebody
 * about, and tells them at most once per period.
 *
 * Called from the only two moments the answer can change — the write that
 * increases consumption (MetricsRecorder::recordUsage(), after its
 * transaction commits) and the gate's own evaluation, which is what makes a
 * ceiling *lowered* below existing consumption warn on the next request
 * rather than waiting for the next completion. There is no polling loop, no
 * scheduled sweep, and no scheduler entry anywhere in this feature.
 *
 * Three properties are load-bearing and each has a plausible-looking wrong
 * implementation:
 *
 *  - **The memo is discarded first.** BudgetLedger memoizes consumption for
 *    the life of a request or job, and on an interactive path the gate has
 *    already read that figure earlier in the same request — before this
 *    unit of work's cost was added. Comparing against the memo runs every
 *    threshold test one unit of work behind, so the crossing unit's own
 *    warning fires late or, for the common case of one unit carrying a
 *    scope over its threshold near the end of a period, never. This is the
 *    one place in the feature where the memo is deliberately thrown away,
 *    because its premise — "the number cannot change during this request" —
 *    is false precisely when recordUsage() has just changed it.
 *  - **The latch is the unique index, not a lookup.** insertOrIgnore()
 *    returns 1 for the process that won the row and 0 for every other, which
 *    is an atomic cross-worker test-and-set. A SELECT-then-INSERT passes
 *    every single-process test and duplicates the moment two workers cross
 *    the same threshold at once. The row is won *before* the event is
 *    dispatched, so the gate and the metrics path evaluating in the same
 *    request produce one notification rather than two.
 *  - **Nothing here may reach the caller.** The callers are the metrics
 *    path, which is fire-and-forget and must never throw into a
 *    conversation, and the gate, which decides whether work may start. A
 *    broadcast that could not be delivered is not a reason to fail either
 *    one, so the whole method body is wrapped once and logged.
 *
 * consumption_at_fire is written for audit — a record of *why* a warning
 * fired — and is never read back by enforcement. Two figures answering one
 * question would eventually disagree.
 *
 * All arithmetic is bcmath on plain-decimal strings; no float is formed
 * anywhere in this class.
 */
class BudgetThresholdNotifier
{
    /** Decimal places every stored monetary figure carries. */
    private const SCALE = 10;

    public function __construct(
        private readonly SpendingCeilingService $ceilings,
        private readonly BudgetLedger $ledger,
    ) {
    }

    /**
     * Evaluate every ceiling that applies to a scope and fire whatever has
     * not yet fired in this period.
     *
     * A null $userId evaluates the installation scope alone — the same shape
     * BudgetGate::evaluate() accepts, for the paths that have no user to
     * attribute work to. A non-null one evaluates the user chain *and* the
     * installation, because both can be crossed by the same unit of work.
     *
     * Never throws.
     */
    public function notify(?string $userId): void
    {
        try {
            $installationCeiling = $this->ceilings->resolveInstallation();
            $userCeiling = $userId === null ? null : $this->ceilings->resolveForUser($userId);

            // Nothing configured costs nothing: this returns before the
            // ledger is touched, so an installation that has not opted in
            // issues no consumption query on the metrics path either.
            if ($installationCeiling === null && $userCeiling === null) {
                return;
            }

            // See the class comment — the figure this class exists to compare
            // is the post-increment one, and the memo predates the increment.
            $this->ledger->forget();

            if ($installationCeiling !== null) {
                $this->consider(
                    $installationCeiling,
                    BudgetScope::Installation->value,
                    SpendingCeiling::INSTALLATION_SCOPE_ID,
                    null,
                    $this->ledger->forInstallation($installationCeiling->period_type),
                );
            }

            if ($userCeiling !== null && $userId !== null) {
                // The scope a notification is *about*, which is not the
                // originating ceiling's own scope_type: a user_default
                // ceiling warns about one named user, and latching it under
                // 'user_default' would let the first user over the line
                // silence the warning for everybody else.
                $this->consider(
                    $userCeiling,
                    'user',
                    $userId,
                    $userId,
                    $this->ledger->forUser($userId, $userCeiling->period_type),
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Budget threshold notification failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * One ceiling against one figure. Both thresholds are at-or-above, not
     * strictly above: a scope that has spent exactly its ceiling has no
     * headroom left, and one sitting exactly on its approach threshold has
     * reached it.
     *
     * A ceiling can produce both kinds from a single call — a unit of work
     * large enough to carry a scope from below the threshold to past the
     * ceiling has genuinely done both things.
     */
    private function consider(
        SpendingCeiling $ceiling,
        string $scopeType,
        string $scopeId,
        ?string $audienceUserId,
        ConsumptionSnapshot $snapshot,
    ): void {
        // An unreadable figure is the gate's problem, not this one's: there
        // is nothing to compare, and announcing a crossing that may not have
        // happened would burn the period's single notification on a guess.
        if (!$snapshot->available || $snapshot->amount === null || $ceiling->amount === null) {
            return;
        }

        $amount = (string) $ceiling->amount;
        $consumption = (string) $snapshot->amount;

        $thresholdAmount = bcmul($amount, (string) $ceiling->approach_threshold, self::SCALE);

        if (bccomp($consumption, $thresholdAmount, self::SCALE) >= 0) {
            $this->fire(
                BudgetThresholdNotification::KIND_APPROACH,
                $ceiling,
                $scopeType,
                $scopeId,
                $audienceUserId,
                $snapshot,
            );
        }

        if (bccomp($consumption, $amount, self::SCALE) >= 0) {
            $this->fire(
                BudgetThresholdNotification::KIND_REACHED,
                $ceiling,
                $scopeType,
                $scopeId,
                $audienceUserId,
                $snapshot,
            );
        }
    }

    /**
     * Claim the period's single notification of this kind for this scope,
     * and announce it only on a win.
     *
     * period_start is part of the key, which is the entire reset mechanism:
     * a new period is a new key, so the warning fires again with nothing
     * cleared, no reset job, and no operator action.
     *
     * ceiling_id is recorded without a foreign key so that removing or
     * replacing the ceiling never cascades over the record of a warning
     * about it — precisely the moment that history is most worth keeping.
     */
    private function fire(
        string $kind,
        SpendingCeiling $ceiling,
        string $scopeType,
        string $scopeId,
        ?string $audienceUserId,
        ConsumptionSnapshot $snapshot,
    ): void {
        [$periodStart] = CalendarPeriod::containing($ceiling->period_type);

        $won = DB::table('budget_threshold_notifications')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'period_type' => $ceiling->period_type,
            'period_start' => $periodStart,
            'kind' => $kind,
            'ceiling_id' => $ceiling->id,
            'consumption_at_fire' => (string) $snapshot->amount,
            'created_at' => now(),
        ]);

        // 0 means another process — or an earlier moment in this same
        // request — already fired this one. Nothing further is owed.
        if ($won !== 1) {
            return;
        }

        // The sentence, the ceiling shape, the period shape, and the
        // consumption shape all come from the same value object the refusal
        // body is built from, so a warning and a refusal about the same
        // ceiling cannot describe it differently.
        $decision = new EnforcementDecision(
            outcome: EnforcementDecision::ALLOW_WITH_WARNING,
            governingCeiling: $ceiling,
            snapshot: $snapshot,
            degraded: false,
            reason: EnforcementDecision::composeNoticeReason($ceiling, $snapshot, $scopeType, $kind),
        );

        event($kind === BudgetThresholdNotification::KIND_REACHED
            ? new SpendingCeilingReached($audienceUserId, $decision)
            : new SpendingThresholdWarned($audienceUserId, $decision));
    }
}
