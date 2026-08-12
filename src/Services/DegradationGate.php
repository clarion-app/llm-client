<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\ReductionStep;
use ClarionApp\LlmClient\ValueObjects\DegradationDecision;
use ClarionApp\LlmClient\ValueObjects\EnforcementDecision;
use ClarionApp\LlmClient\ValueObjects\LimitAxis;
use ClarionApp\LlmClient\ValueObjects\RateLimitDecision;
use Illuminate\Support\Facades\Log;

/**
 * The sole decision authority for whether one response is served in a
 * reduced form (data-model.md §2, contracts §3, research.md D1-D3/D7/D8/D11).
 *
 * evaluate() is called exactly once per response, from
 * AgentLoopService::admitInteractiveWork(), immediately after
 * RateLimitGate::admit() and BudgetGate::admit() have both already
 * succeeded (research.md D1). It reuses the two decisions those gates just
 * produced rather than re-reading either counter (research.md D2) — the
 * only fresh read this class performs is a non-mutating
 * ConversationWorkCounter::peek() for the conversation_work axis, and only
 * when a rung is actually configured against it.
 *
 * linkRun() and forRun() are the persistence half of this class
 * (research.md D3): linkRun() is called once, from inside
 * RunTraceRecorder::openRun(), only when a new run is being minted, and
 * writes the durable degradation_events row a later re-entry into the same
 * response reads back via forRun() — never re-evaluating standing, which
 * is what keeps a single response from switching model, tools, or history
 * handling partway through (FR-006/SC-003).
 *
 * Bound scoped() in LlmClientServiceProvider, matching BudgetGate/
 * RateLimitGate: linkRun() needs a same-request instance property
 * ($decisions, below) to read back what evaluate() just computed a few
 * lines earlier in the same request/job — the identical shape
 * BudgetGate::linkRun() already relies on for its own $this->reservationIds.
 *
 * Never throws: every read failure on any one axis excludes that axis and
 * is logged, exactly as every sibling gate's own unreadable-figure handling
 * already does (contracts §3 Failure contract).
 *
 * Deliberately not `final` — matching BudgetGate/RateLimitGate/
 * ConversationWorkGate, none of which is final either, and required for
 * ReducedNotRefusedJourneyTest's own `Mockery::mock(DegradationGate::class)`
 * spy (scenario 3: proving evaluate() is never reached at the absolute
 * ceiling).
 */
class DegradationGate
{
    /** bcmath scale for every ratio/margin computation in this class. */
    private const SCALE = 10;

    /** bcmath scale a threshold/ratio comparison is made at. */
    private const COMPARE_SCALE = 4;

    /**
     * The axis a governing step was selected against wins ties in this
     * fixed order (research.md D7) — installation-first, then narrowing by
     * scope.
     */
    private const AXIS_TIE_ORDER = [
        LimitAxis::BudgetInstallation->value,
        LimitAxis::BudgetUser->value,
        LimitAxis::ConversationWork->value,
        LimitAxis::RateLimit->value,
    ];

    /**
     * The decision evaluate() just computed for a conversation, read back
     * moments later in the same request/job by linkRun() — never a
     * cross-process memo (see the class docblock and research.md D3).
     *
     * @var array<string, DegradationDecision>
     */
    private array $decisions = [];

    /**
     * Decide whether this response is served in a reduced form.
     *
     * Never throws. Returns DegradationDecision::full() whenever
     * degradation is disabled, run tracing is disabled (research.md D3 — a
     * decision with nowhere durable to be anchored cannot survive a
     * streamed response's later re-entries), no ladder is configured, or
     * nothing crossed.
     */
    public function evaluate(
        ?string $userId,
        string $conversationId,
        RateLimitDecision $rateLimitDecision,
        EnforcementDecision $budgetDecision,
    ): DegradationDecision {
        try {
            if (
                !config('llm-client.degradation.enabled', true)
                || !config('llm-client.run_trace.enabled', true)
            ) {
                return $this->remember($conversationId, DegradationDecision::full());
            }

            // The no-ladder short-circuit: exactly one query, regardless of
            // standing on any axis (mutation-checklist row 9).
            if (!ReductionStep::where('enabled', true)->exists()) {
                return $this->remember($conversationId, DegradationDecision::full());
            }

            $steps = ReductionStep::where('enabled', true)->get()->groupBy('axis');

            /** @var array<string, array{ratio: string, resetsAt: ?\Carbon\CarbonImmutable}> $axisReadings */
            $axisReadings = [];

            foreach ([LimitAxis::BudgetInstallation, LimitAxis::BudgetUser] as $axis) {
                if (!$steps->has($axis->value)) {
                    continue;
                }
                $reading = $this->budgetAxisReading($budgetDecision);
                if ($reading !== null) {
                    $axisReadings[$axis->value] = $reading;
                }
            }

            if ($steps->has(LimitAxis::RateLimit->value)) {
                $reading = $this->rateLimitAxisReading($rateLimitDecision);
                if ($reading !== null) {
                    $axisReadings[LimitAxis::RateLimit->value] = $reading;
                }
            }

            if ($steps->has(LimitAxis::ConversationWork->value)) {
                $reading = $this->conversationWorkAxisReading($conversationId);
                if ($reading !== null) {
                    $axisReadings[LimitAxis::ConversationWork->value] = $reading;
                }
            }

            // Two-level selection (research.md D7): within an axis, several
            // rungs can be crossed at once (a genuine ladder — 0.75 and
            // 0.90 both crossed at 0.95 standing), and the rung actually
            // governing must always be the most severe one currently
            // crossed, i.e. the one with the SMALLEST margin
            // (ratio - threshold), equivalently the largest threshold_ratio
            // not exceeding the ratio — never the mildest one, which would
            // always have the largest margin and would otherwise win a
            // flat, single-level comparison, defeating the ladder entirely
            // (a milder rung is always crossed whenever a stricter rung on
            // the same axis is). Each axis's own tightest-crossed rung is
            // then compared ACROSS axes by the LARGEST margin — the axis
            // whose representative rung has been overshot by the widest
            // fraction governs overall, ties broken installation-first
            // (AXIS_TIE_ORDER).
            $axisCandidates = [];

            foreach ($axisReadings as $axisValue => $reading) {
                $ratio = $reading['ratio'];
                $resetsAt = $reading['resetsAt'];

                $tightest = null;
                foreach ($steps->get($axisValue, collect()) as $step) {
                    if (bccomp($ratio, (string) $step->threshold_ratio, self::COMPARE_SCALE) < 0) {
                        continue; // Not yet crossed.
                    }

                    $margin = bcsub($ratio, (string) $step->threshold_ratio, self::SCALE);

                    if ($tightest === null || bccomp($margin, $tightest['margin'], self::SCALE) < 0) {
                        $tightest = ['step' => $step, 'margin' => $margin];
                    }
                }

                if ($tightest !== null) {
                    $axisCandidates[$axisValue] = [
                        'step' => $tightest['step'],
                        'margin' => $tightest['margin'],
                        'ratio' => $ratio,
                        'resetsAt' => $resetsAt,
                    ];
                }
            }

            $best = null;

            foreach (self::AXIS_TIE_ORDER as $axisValue) {
                if (!isset($axisCandidates[$axisValue])) {
                    continue;
                }

                $candidate = $axisCandidates[$axisValue];

                if ($best === null || bccomp($candidate['margin'], $best['margin'], self::SCALE) > 0) {
                    $best = $candidate + ['axis' => $axisValue];
                }
            }

            if ($best === null) {
                return $this->remember($conversationId, DegradationDecision::full());
            }

            /** @var ReductionStep $step */
            $step = $best['step'];

            $decision = new DegradationDecision(
                outcome: DegradationDecision::OUTCOME_REDUCED,
                governingStep: $step,
                axis: $best['axis'],
                ratio: $best['ratio'],
                effectiveModel: $step->substitute_model,
                effectiveServerId: $step->substitute_server_id,
                withheldTools: $step->withheld_tools ?? [],
                historyBudgetRatio: $step->history_budget_ratio,
                resetsAt: $best['resetsAt'],
            );

            return $this->remember($conversationId, $decision);
        } catch (\Throwable $e) {
            Log::warning('DegradationGate: evaluate() failed, defaulting to full capacity', [
                'conversation_id' => $conversationId,
                'error' => $e->getMessage(),
            ]);

            return DegradationDecision::full();
        }
    }

    /**
     * Called exactly once, from inside RunTraceRecorder::openRun(), only
     * when a NEW run is being minted (research.md D3). Reads the decision
     * back out of $this->decisions, populated moments earlier in the same
     * request/job by evaluate() — takes no DegradationDecision parameter
     * of its own (contracts §3, corrected). A no-op when $runId is null,
     * when no decision was recorded for $conversationId, or when the
     * recorded decision is 'full'.
     */
    public function linkRun(?string $userId, string $conversationId, ?string $runId): void
    {
        if ($runId === null) {
            return;
        }

        $decision = $this->decisions[$conversationId] ?? null;

        if ($decision === null || $decision->outcome !== DegradationDecision::OUTCOME_REDUCED) {
            return;
        }

        if ($decision->governingStep === null || $decision->axis === null || $decision->ratio === null) {
            return;
        }

        app(MetricsRecorder::class)->recordDegradation(
            $conversationId,
            $userId,
            $runId,
            $decision->governingStep,
            $decision->axis,
            $decision->ratio,
        );
    }

    /**
     * Read back the decision linkRun() wrote for a run — never
     * re-evaluates standing (research.md D3, FR-006/SC-003). Returns
     * DegradationDecision::full() when $runId is null or no
     * degradation_events row exists for it.
     */
    public function forRun(?string $runId): DegradationDecision
    {
        if ($runId === null) {
            return DegradationDecision::full();
        }

        try {
            $event = \ClarionApp\LlmClient\Models\DegradationEvent::where('run_id', $runId)->first();
        } catch (\Throwable $e) {
            Log::warning('DegradationGate: forRun() failed to read degradation_events', [
                'run_id' => $runId,
                'error' => $e->getMessage(),
            ]);

            return DegradationDecision::full();
        }

        if ($event === null) {
            return DegradationDecision::full();
        }

        // withTrashed(): a since-deleted governing rung must not revert an
        // already-in-progress response to full capacity mid-flight
        // (research.md D3's "does not depend on the rung still existing").
        $step = ReductionStep::withTrashed()->find($event->reduction_step_id);

        return new DegradationDecision(
            outcome: DegradationDecision::OUTCOME_REDUCED,
            governingStep: $step,
            axis: $event->axis,
            ratio: $event->ratio,
            effectiveModel: $step?->substitute_model,
            effectiveServerId: $step?->substitute_server_id,
            withheldTools: $step?->withheld_tools ?? [],
            historyBudgetRatio: $step?->history_budget_ratio,
            resetsAt: null,
        );
    }

    private function remember(string $conversationId, DegradationDecision $decision): DegradationDecision
    {
        $this->decisions[$conversationId] = $decision;

        return $decision;
    }

    /**
     * (consumption + held) / ceiling.amount, reused verbatim from the
     * EnforcementDecision admitInteractiveWork() already produced
     * (research.md D2) — never a fresh BudgetGate::standingFor() call.
     * Excluded (null) whenever any figure involved is unreadable
     * (research.md D8) — never treated as 0% or 100% consumed.
     */
    private function budgetAxisReading(EnforcementDecision $budgetDecision): ?array
    {
        if ($budgetDecision->snapshot === null || !$budgetDecision->snapshot->available) {
            return null;
        }

        if ($budgetDecision->governingCeiling === null) {
            return null;
        }

        $held = $budgetDecision->held;
        if ($held !== null && !$held->available) {
            return null;
        }

        $ceilingAmount = (string) $budgetDecision->governingCeiling->amount;
        if (bccomp($ceilingAmount, '0', self::SCALE) <= 0) {
            return null;
        }

        $heldAmount = $held?->amount ?? '0';
        $numerator = bcadd((string) $budgetDecision->snapshot->amount, $heldAmount, self::SCALE);
        $ratio = bcdiv($numerator, $ceilingAmount, self::SCALE);

        return ['ratio' => $ratio, 'resetsAt' => $budgetDecision->snapshot->resetsAt];
    }

    /**
     * reading.count / limit.max_requests, reused verbatim from the
     * RateLimitDecision admitInteractiveWork() already produced
     * (research.md D2) — never a second RateLimitCounter call of any kind.
     */
    private function rateLimitAxisReading(RateLimitDecision $rateLimitDecision): ?array
    {
        if ($rateLimitDecision->reading === null || !$rateLimitDecision->reading->available) {
            return null;
        }

        if ($rateLimitDecision->limit === null || (int) $rateLimitDecision->limit->max_requests <= 0) {
            return null;
        }

        $ratio = bcdiv(
            (string) $rateLimitDecision->reading->count,
            (string) $rateLimitDecision->limit->max_requests,
            self::SCALE,
        );

        return ['ratio' => $ratio, 'resetsAt' => $rateLimitDecision->reading->resetsAt];
    }

    /**
     * peek.count / ceiling.max_work_units — the one axis this class reads
     * fresh, via a non-mutating ConversationWorkCounter::peek() (never
     * increment()), and only when a conversation_work-axis rung is
     * actually configured (research.md D7/D9).
     */
    private function conversationWorkAxisReading(string $conversationId): ?array
    {
        $ceiling = app(ConversationWorkCeilingService::class)->resolveForConversation($conversationId);
        if ($ceiling === null) {
            return null;
        }

        $reading = app(ConversationWorkCounter::class)->peek($conversationId, (int) $ceiling->window_seconds);
        if (!$reading->available) {
            return null;
        }

        if ((int) $ceiling->max_work_units <= 0) {
            return null;
        }

        $ratio = bcdiv((string) $reading->count, (string) $ceiling->max_work_units, self::SCALE);

        return ['ratio' => $ratio, 'resetsAt' => $reading->resetsAt];
    }
}
