<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Events\SpendingEnforcementDegraded;
use ClarionApp\LlmClient\Exceptions\BudgetExceededException;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\CostReservation;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\ValueObjects\BudgetScope;
use ClarionApp\LlmClient\ValueObjects\BudgetWorkKind;
use ClarionApp\LlmClient\ValueObjects\ConsumptionSnapshot;
use ClarionApp\LlmClient\ValueObjects\EnforcementDecision;
use ClarionApp\LlmClient\ValueObjects\EnforcementMode;
use ClarionApp\LlmClient\ValueObjects\ReservationSnapshot;
use ClarionApp\LlmClient\ValueObjects\UnpricedModelPolicy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
     * Where a standing report's applicable ceiling came from, so a user can
     * see *why* their ceiling is what it is.
     */
    public const SOURCE_OVERRIDE = 'override';
    public const SOURCE_DEFAULT = 'default';
    public const SOURCE_INSTALLATION = 'installation';

    /** Why a standing report has no ceiling to show for a scope. */
    public const REASON_NO_CEILING = 'no_ceiling_configured';
    public const REASON_WAIVED = 'waived';

    /**
     * Scopes already admitted during this request or job, keyed by the same
     * scope key the ledger uses. Per-instance and never static.
     *
     * @var array<string, true>
     */
    private array $admitted = [];

    /**
     * The reservation id placed for each scope key already admitted during
     * this request or job, keyed the same way $admitted is. Read by
     * linkRun()/reconcileHeld() (Phase 4/US2) so a reservation placed here
     * can later be attached to the run it belongs to, or reconciled once
     * real usage lands, without a second lookup.
     *
     * @var array<string, string>
     */
    private array $reservationIds = [];

    public function __construct(
        private readonly SpendingCeilingService $ceilings,
        private readonly BudgetLedger $ledger,
        private readonly CostEstimator $estimator,
        private readonly ReservationLedger $reservations,
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
                $this->reservations->heldFor('installation'),
            );
        }

        if ($userCeiling !== null && $userId !== null) {
            $assessments[] = $this->assess(
                $userCeiling,
                'user',
                $this->ledger->forUser($userId, $userCeiling->period_type),
                $this->reservations->heldFor('user:'.$userId),
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
            $this->refuseWork($decision, $kind, $userId, $conversationId, $source, $existingRunId);
        }

        // The second of the two moments a threshold can be crossed. The
        // first is the write that increases consumption; this one is what
        // makes a ceiling *lowered* below existing consumption warn on the
        // next request, rather than staying silent until somebody happens to
        // complete another unit of work. It returns normally either way — a
        // warning never blocks — and the notifier's own latch is what keeps
        // the two moments from producing two notifications in one request.
        if ($decision->outcome === EnforcementDecision::ALLOW_WITH_WARNING) {
            try {
                app(BudgetThresholdNotifier::class)->notify($userId);
            } catch (\Throwable $e) {
                // notify() swallows its own failures; this catch covers the
                // resolution itself, because nothing about announcing a
                // warning may stop work that the gate has already allowed.
                Log::warning('Failed to evaluate spending thresholds at the gate', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->attemptReservation($userId, $kind, $conversationId, $source, $existingRunId, $decision, $scopeKey);

        $this->admitted[$scopeKey] = true;
    }

    /**
     * Attach the reservation this instance placed for $userId's scope to
     * the run that admission just opened. Called by
     * RunTraceRecorder::openRun(), which always runs immediately after
     * admit() in both funnels (see the class docblock's reservation note),
     * so at the moment this fires $this->reservationIds still holds the
     * reservation id from that same admission.
     *
     * A no-op when $runId is null or when no reservation was ever placed
     * for this admission (admit_untracked policy, no ceiling configured,
     * or every applicable axis was warn-mode) — never a reason to fail an
     * already-open run.
     */
    public function linkRun(?string $userId, ?string $runId): void
    {
        if ($runId === null) {
            return;
        }

        $reservationId = $this->reservationIds[$this->scopeKey($userId)] ?? null;

        if ($reservationId === null) {
            return;
        }

        try {
            DB::table('cost_reservations')
                ->where('id', $reservationId)
                ->whereNull('run_id')
                ->update(['run_id' => $runId]);
        } catch (\Throwable $e) {
            Log::warning('Failed to link a reservation to its run', [
                'reservation_id' => $reservationId,
                'run_id' => $runId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Reconcile the reservation this instance placed for $userId's scope
     * against a real recorded cost. Safe to call only from within the same
     * request/job that ran admit() — it reads $this->reservationIds, this
     * instance's own per-request/job memo (research.md D7's grounding for
     * why MetricsRecorder::recordUsage()'s reconciliation is safe to route
     * through BudgetGate at all, unlike RunTraceRecorder::closeRun()'s
     * fallback, which may run from a different process entirely).
     *
     * A no-op when no reservation was ever placed for this admission.
     * Never throws into the caller.
     */
    public function reconcileHeld(string $userId, string $actualAmount): void
    {
        $reservationId = $this->reservationIds['user:'.$userId] ?? null;

        if ($reservationId === null) {
            return;
        }

        try {
            $reservation = CostReservation::find($reservationId);

            if ($reservation === null) {
                return;
            }

            $this->reservations->reconcile($reservation, $actualAmount);
        } catch (\Throwable $e) {
            Log::warning('Failed to reconcile a held reservation', [
                'reservation_id' => $reservationId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Compute an estimate for the unit of work evaluate() just allowed and
     * atomically attempt to hold it (research.md D4/D5/D8/D9). A no-op — no
     * estimate computed, nothing reserved — whenever there is nothing to
     * estimate against ($conversationId null: the same shape every existing
     * direct-admit() call site with no conversation already has, e.g.
     * EmbeddingService's unresolvable-owner fallback and RoleTestRunner),
     * whenever an unpriced model's policy says to skip tracking, or whenever
     * every axis this admission would otherwise reserve against is
     * warn-mode. Refuses exactly like evaluate()'s own stop path — via the
     * same refuseWork() — when the atomic reservation itself finds no room,
     * when an unpriced model is refused under an active stop-mode ceiling,
     * or when the reservation ledger itself fails to write under a
     * stop-mode ceiling and the default fail-closed policy applies.
     */
    private function attemptReservation(
        ?string $userId,
        BudgetWorkKind $kind,
        ?string $conversationId,
        ?string $source,
        ?string $existingRunId,
        EnforcementDecision $decision,
        string $scopeKey,
    ): void {
        if ($conversationId === null) {
            return;
        }

        // Nothing configured costs nothing — the same short-circuit
        // evaluate() itself opens with. A null governingCeiling here means
        // no installation or user ceiling applied at all (noCeilingConfigured()),
        // so there is nothing an estimate or a reservation could ever be
        // measured against; computing one anyway would add a Message-history
        // scan and a model_prices query to every admission everywhere, the
        // exact "quietly add a scan to every request" failure mode this
        // class's own docblock names.
        if ($decision->governingCeiling === null) {
            return;
        }

        $conversation = Conversation::find($conversationId);
        $providerType = null;
        $model = null;

        if ($conversation !== null) {
            $model = $conversation->model;

            try {
                $providerType = $conversation->effective_provider_type?->value;
            } catch (\Throwable) {
                // getEffectiveProviderTypeAttribute() throws when the
                // conversation's server is missing — treated the same as an
                // unresolvable provider, never as a reason to fail
                // admission itself.
                $providerType = null;
            }
        }

        $estimate = $this->estimator->estimate($conversationId, $providerType, $model);

        if ($estimate->unpriced) {
            $policy = UnpricedModelPolicy::from((string) config('llm-client.budget.on_unpriced_model', 'stop'));

            if ($policy === UnpricedModelPolicy::AdmitUntracked) {
                return;
            }

            if ($policy === UnpricedModelPolicy::Stop) {
                $this->declineIfGoverningCeilingStops(
                    $decision,
                    $kind,
                    $userId,
                    $conversationId,
                    $source,
                    $existingRunId,
                    degraded: true,
                );

                return;
            }

            // ReserveFlatEstimate: $estimate->amount already carries the
            // configured flat figure (CostEstimator resolves the policy
            // internally) — fall through to the ordinary reservation
            // attempt below using it.
        }

        if ($estimate->amount === null) {
            // Nothing to reserve — a priced estimate could not be formed
            // for some other reason, or an unpriced estimate reached here
            // under a policy that carries no numeric amount.
            return;
        }

        $scopeKeys = $this->stopModeScopeKeys($userId);

        if ($scopeKeys === []) {
            // Every applicable axis is warn-mode: a warn ceiling can never
            // block admission regardless of what is held against it, so
            // there is nothing to bound a reservation against
            // (research.md D5, corrected).
            return;
        }

        try {
            $reservation = $this->reservations->reserve(
                $scopeKeys,
                $estimate->amount,
                $kind,
                userId: $userId,
                conversationId: $conversationId,
                runId: $existingRunId,
            );
        } catch (\Throwable $e) {
            Log::warning('Reservation ledger write failed while admitting work', [
                'scope' => $scopeKey,
                'error' => $e->getMessage(),
            ]);

            $this->declineIfGoverningCeilingStops(
                $decision,
                $kind,
                $userId,
                $conversationId,
                $source,
                $existingRunId,
                degraded: true,
                policyConfigKey: 'llm-client.budget.on_unreadable_consumption',
            );

            return;
        }

        if ($reservation === null) {
            // The atomic bound rejected it — indistinguishable, by design,
            // from a plain evaluate()-level stop (contracts §2).
            $this->refuseWork(
                $this->stopDecisionFrom($decision, degraded: false),
                $kind,
                $userId,
                $conversationId,
                $source,
                $existingRunId,
            );

            return;
        }

        $this->reservationIds[$scopeKey] = $reservation->id;
    }

    /**
     * The scope keys admit() may reserve against for this admission —
     * mirroring which of the installation/user ceilings evaluate() just
     * measured, filtered to the axes whose ceiling is in stop mode only
     * (research.md D5, corrected: ReservationLedger::reserve() carries no
     * per-axis enforcement-mode information, so a warn-mode axis is never
     * passed to it at all — its held figure in a standing report is always
     * "0.0000000000").
     *
     * @return list<string>
     */
    private function stopModeScopeKeys(?string $userId): array
    {
        $scopeKeys = [];

        $installationCeiling = $this->ceilings->resolveInstallation();

        if ($installationCeiling !== null && $installationCeiling->enforcement_mode === EnforcementMode::Stop->value) {
            $scopeKeys[] = 'installation';
        }

        if ($userId !== null) {
            $userCeiling = $this->ceilings->resolveForUser($userId);

            if ($userCeiling !== null && $userCeiling->enforcement_mode === EnforcementMode::Stop->value) {
                $scopeKeys[] = 'user:'.$userId;
            }
        }

        return $scopeKeys;
    }

    /**
     * Refuse exactly like evaluate()'s own stop path — reusing the
     * already-allowed $decision's governing ceiling/snapshot — when, and
     * only when, that governing ceiling is itself in stop mode. A warn-mode
     * governing ceiling (or no ceiling at all) means nothing here may block
     * admission, so this is a no-op: an unpriced model or an unreadable
     * reservation ledger must never start refusing work a warn-only scope
     * would otherwise let through.
     *
     * $policyConfigKey selects which configured policy gates the block —
     * on_unpriced_model's own 'stop' branch has already chosen to be here
     * (the caller only reaches this method once it knows it must), so it
     * passes no key and always blocks when the ceiling is stop-mode; a
     * reservation-ledger write failure instead reuses
     * on_unreadable_consumption, exactly as handleUnreadable() applies it
     * to an unreadable consumption figure.
     */
    private function declineIfGoverningCeilingStops(
        EnforcementDecision $decision,
        BudgetWorkKind $kind,
        ?string $userId,
        ?string $conversationId,
        ?string $source,
        ?string $existingRunId,
        bool $degraded,
        ?string $policyConfigKey = null,
    ): void {
        if ($policyConfigKey !== null && (string) config($policyConfigKey, 'stop') !== 'stop') {
            return;
        }

        if ($decision->governingCeiling === null
            || $decision->governingCeiling->enforcement_mode !== EnforcementMode::Stop->value) {
            return;
        }

        $this->refuseWork(
            $this->stopDecisionFrom($decision, $degraded),
            $kind,
            $userId,
            $conversationId,
            $source,
            $existingRunId,
        );
    }

    /**
     * A fresh STOP decision that reuses an already-allowed $decision's
     * governing ceiling/snapshot — the shape every reservation-driven
     * refusal in this class throws, so a caller can never tell it apart
     * from a plain evaluate()-level stop (contracts §2). governingCeiling
     * and snapshot are always set or unset together on any EnforcementDecision
     * this class constructs, so the one guard below covers both.
     */
    private function stopDecisionFrom(EnforcementDecision $decision, bool $degraded): EnforcementDecision
    {
        return new EnforcementDecision(
            outcome: EnforcementDecision::STOP,
            governingCeiling: $decision->governingCeiling,
            snapshot: $decision->snapshot,
            degraded: $degraded,
            reason: $decision->governingCeiling === null || $decision->snapshot === null ? null : EnforcementDecision::composeReason(
                $decision->governingCeiling,
                $decision->snapshot,
                (string) $decision->governingScope(),
                degraded: $degraded,
            ),
        );
    }

    /**
     * Record the refusal and throw — the single sequence every stop path in
     * this class uses, so a caller can never distinguish a plain
     * evaluate()-level stop from a reservation-driven one (contracts §2).
     */
    private function refuseWork(
        EnforcementDecision $decision,
        BudgetWorkKind $kind,
        ?string $userId,
        ?string $conversationId,
        ?string $source,
        ?string $existingRunId,
    ): never {
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

    /**
     * Where a scope stands right now — asked and answered without anything
     * being warned about, refused, or otherwise disturbed.
     *
     * Built from evaluate()'s own parts: the same ceiling resolution, the
     * same ledger read, the same per-ceiling assessment, and the same
     * EnforcementDecision renderers the 402 body and the warning payload use.
     * A standing report and a refusal for the same instant therefore cannot
     * disagree about the same ceiling.
     *
     * Deliberately built on evaluate()'s parts rather than on admit(): admit()
     * fires a threshold notification on an allow_with_warning outcome, and
     * asking where you stand must never be the thing that warns you. Nothing
     * here writes a row, fires an event, or records a run.
     *
     * The envelope is the same on every route that serves it. The
     * installation block is an installation-wide aggregate rather than any
     * individual's figures, so it is reported to whoever asks — it is the
     * ceiling that will stop them, and the entire point of this surface is
     * that nobody is stopped by a limit they had no way to see. It is also
     * exactly what the refusal body already hands a non-operator when the
     * installation ceiling stops them, and a report that contradicted the
     * refusal would defeat the purpose of building the two from one
     * computation. No *other individual user's* id,
     * ceiling, or consumption appears in any block, whoever is asking.
     *
     * @return array{
     *   user_ceiling: array<string, mixed>,
     *   installation_ceiling: array<string, mixed>,
     *   degraded: bool,
     * }
     */
    public function standingFor(?string $userId): array
    {
        // The pre-waiver row, so a waived user can be told they are waived
        // rather than that nothing is configured. resolveForUser() walks the
        // chain by calling the same method, so the row reported here is
        // always the row enforcement measures against.
        $userRow = $userId === null ? null : $this->ceilings->applicableUserRow($userId);
        $installationCeiling = $this->ceilings->resolveInstallation();

        if ($userRow === null) {
            $userBlock = self::inapplicable(self::REASON_NO_CEILING);
        } elseif ($userRow->waived) {
            $userBlock = self::inapplicable(self::REASON_WAIVED);
        } else {
            $userBlock = $this->standingBlock(
                $userRow,
                'user',
                $userRow->scope_type === BudgetScope::User->value
                    ? self::SOURCE_OVERRIDE
                    : self::SOURCE_DEFAULT,
                $this->ledger->forUser((string) $userId, $userRow->period_type),
                $userId,
            );
        }

        $installationBlock = $installationCeiling === null
            ? self::inapplicable(self::REASON_NO_CEILING)
            : $this->standingBlock(
                $installationCeiling,
                BudgetScope::Installation->value,
                self::SOURCE_INSTALLATION,
                $this->ledger->forInstallation($installationCeiling->period_type),
                $userId,
            );

        return [
            'user_ceiling' => $userBlock,
            'installation_ceiling' => $installationBlock,
            'degraded' => self::blockIsDegraded($userBlock) || self::blockIsDegraded($installationBlock),
        ];
    }

    /**
     * A scope that has no ceiling to report.
     *
     * Exactly two keys, and never a ceiling of "0": an unconstrained scope
     * rendered as a zero ceiling reads as "you may spend nothing", which is
     * the precise opposite of the truth (FR-037).
     *
     * @return array{applies: false, reason: string}
     */
    private static function inapplicable(string $reason): array
    {
        return ['applies' => false, 'reason' => $reason];
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private static function blockIsDegraded(array $block): bool
    {
        return ($block['consumption']['available'] ?? true) === false
            || ($block['held']['available'] ?? true) === false;
    }

    /**
     * One applicable ceiling, its figure, and the headroom between them.
     *
     * $userId is only used to build the held-reservation scope key ('user:'
     * .$userId for the user axis, 'installation' for the installation axis
     * regardless of who is asking — an installation-wide figure never
     * depends on the caller) — the same scope key evaluate() already uses
     * via ReservationLedger::heldFor(), so a standing report and a live
     * admission decision read the identical held total.
     *
     * @return array<string, mixed>
     */
    private function standingBlock(
        SpendingCeiling $ceiling,
        string $axis,
        string $source,
        ConsumptionSnapshot $snapshot,
        ?string $userId,
    ): array {
        $heldSnapshot = $this->reservations->heldFor(
            $axis === BudgetScope::Installation->value ? 'installation' : 'user:'.$userId
        );

        $assessment = $this->assess($ceiling, $axis, $snapshot, $heldSnapshot);

        // The same value object a refusal and a warning are rendered from —
        // which is what makes remaining()'s flooring, the ceiling shape, and
        // the period shape identical across all three surfaces rather than
        // three implementations that agree today. No `reason` sentence: every
        // one this class composes opens by announcing that something was
        // stopped or crossed, and a standing report has done neither.
        $decision = new EnforcementDecision(
            outcome: $this->outcomeFor($assessment),
            governingCeiling: $ceiling,
            snapshot: $snapshot,
            degraded: !$snapshot->available,
            held: $heldSnapshot,
        );

        return [
            'applies' => true,
            'source' => $source,
            'ceiling' => $decision->ceilingArray(),
            'period' => $decision->periodArray(),
            'consumption' => $snapshot->toArray(),
            'held' => $heldSnapshot->toArray(),
            // Null when the figure could not be read: no headroom can be
            // computed from a number nobody has.
            'remaining' => $decision->remaining(),
            'threshold_crossed' => $assessment['thresholdCrossed'],
            'reached' => $assessment['reached'],
        ];
    }

    /**
     * The outcome this one ceiling would produce on its own, by the same
     * rule combine() applies across all of them.
     *
     * Standing does not publish it — a block reports `reached` and
     * `threshold_crossed` instead — but an EnforcementDecision is never
     * constructed without the outcome that produced it, and hard-coding an
     * allow here would leave the object claiming a ceiling was fine while
     * reporting consumption past it. An unreadable figure decides nothing:
     * the fail-closed policy belongs to admit(), not to a report.
     *
     * @param  array<string, mixed>  $assessment
     */
    private function outcomeFor(array $assessment): string
    {
        if (!$assessment['snapshot']->available) {
            return EnforcementDecision::ALLOW;
        }

        if ($assessment['reached'] && $assessment['ceiling']->enforcement_mode === EnforcementMode::Stop->value) {
            return EnforcementDecision::STOP;
        }

        return $assessment['reached'] || $assessment['thresholdCrossed']
            ? EnforcementDecision::ALLOW_WITH_WARNING
            : EnforcementDecision::ALLOW;
    }

    /**
     * One ceiling measured against one figure.
     *
     * $held is the scope's currently-held reservation total (evaluate()'s
     * own, already-existing figure — see the class docblock's reservation
     * note): when present and available, every comparison below measures
     * consumption *plus* held against the ceiling, not consumption alone.
     * When $held is null or unavailable, held is treated as 0 for this
     * arithmetic, but the returned 'heldAvailable' key still reports the
     * failure so combine() can route it through the same fail-closed/
     * fail-open policy a consumption-read failure already gets — the
     * degraded policy itself is never applied here.
     *
     * @return array{
     *   ceiling: SpendingCeiling,
     *   axis: string,
     *   snapshot: ConsumptionSnapshot,
     *   reached: bool,
     *   thresholdCrossed: bool,
     *   headroom: ?string,
     *   heldAvailable: bool,
     * }
     */
    private function assess(
        SpendingCeiling $ceiling,
        string $axis,
        ConsumptionSnapshot $snapshot,
        ?ReservationSnapshot $held = null,
    ): array {
        $amount = (string) $ceiling->amount;
        $heldAvailable = $held === null || $held->available;

        if (!$snapshot->available) {
            return [
                'ceiling' => $ceiling,
                'axis' => $axis,
                'snapshot' => $snapshot,
                'reached' => false,
                'thresholdCrossed' => false,
                'headroom' => null,
                'heldAvailable' => $heldAvailable,
            ];
        }

        $consumption = (string) $snapshot->amount;
        $heldAmount = $held !== null && $held->available ? (string) $held->amount : '0';
        $projected = bcadd($consumption, $heldAmount, self::SCALE);

        // "Reached" is at-or-above, not strictly above: a scope that has spent
        // (or currently holds in reservation) at least its ceiling has no
        // headroom left.
        $reached = bccomp($projected, $amount, self::SCALE) >= 0;

        $thresholdAmount = bcmul($amount, (string) $ceiling->approach_threshold, self::SCALE);
        $thresholdCrossed = bccomp($projected, $thresholdAmount, self::SCALE) >= 0;

        return [
            'ceiling' => $ceiling,
            'axis' => $axis,
            'snapshot' => $snapshot,
            'reached' => $reached,
            'thresholdCrossed' => $thresholdCrossed,
            // Signed, deliberately unfloored: a scope 30 over its ceiling is
            // tighter than one 10 over, and flooring both at zero would make
            // every over-spent ceiling tie with every other.
            'headroom' => bcsub($amount, $projected, self::SCALE),
            'heldAvailable' => $heldAvailable,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $assessments
     */
    private function combine(array $assessments, ?string $userId): EnforcementDecision
    {
        $unreadable = array_values(array_filter(
            $assessments,
            fn (array $a) => !$a['snapshot']->available || !$a['heldAvailable']
        ));

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
