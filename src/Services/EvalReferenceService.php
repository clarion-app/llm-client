<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\EvalReferenceDesignation;
use ClarionApp\LlmClient\Models\EvalRun;
use ClarionApp\LlmClient\ValueObjects\EvalRunStatus;
use Illuminate\Support\Collection;

/**
 * The sole write path for eval_reference_designations. "Current
 * reference" and "reference active as of a given timestamp" are both
 * derived reads over this table's append-only history (latest row wins,
 * the EvalJudgment::effective() pattern applied to a second, independent
 * history) — nothing here is ever updated or deleted.
 */
class EvalReferenceService
{
    /**
     * Designate (or move to) a run as the reference for its own agent.
     * agent_label always comes from the named run's own column — never a
     * caller-supplied scope — so a designation can never be recorded
     * under a scope mismatching the run it names.
     *
     * @throws \InvalidArgumentException when the run does not exist, or
     *   its status is in_progress or failed_to_start. No row is written
     *   in either case.
     */
    public function designate(string $runId, ?string $userId): EvalReferenceDesignation
    {
        $run = EvalRun::find($runId);

        if ($run === null) {
            throw new \InvalidArgumentException('Run not found.');
        }

        if (in_array($run->status, [EvalRunStatus::InProgress, EvalRunStatus::FailedToStart], true)) {
            throw new \InvalidArgumentException('Only a completed or incomplete run can be designated as a reference.');
        }

        return EvalReferenceDesignation::create([
            'agent_label' => $run->agent_label,
            'run_id' => $run->id,
            'designated_by' => $userId,
        ]);
    }

    /**
     * The latest designation for this agent, or null when none has ever
     * been made (AC5 — never an error, never a misleading empty report).
     */
    public function current(string $agentLabel): ?EvalReferenceDesignation
    {
        return $this->historyQuery($agentLabel)->first();
    }

    /**
     * Every designation ever made for this agent, newest first — nothing
     * ever summarized away (FR-009/FR-013).
     *
     * @return Collection<int, EvalReferenceDesignation>
     */
    public function history(string $agentLabel): Collection
    {
        return $this->historyQuery($agentLabel)->get();
    }

    /**
     * The designation that was active for this agent at a specific point
     * in time — the reference a run's own comparison resolves against,
     * anchored to that run's own completion instant rather than to
     * "whatever is current now" (research.md D6). Null when no
     * designation existed yet at that time.
     *
     * $excludeRunId, when given, skips every designation naming that run,
     * continuing further back through the history rather than stopping.
     * A comparison passes the compared run's own id here: created_at is
     * only second-precision, so designating a run in the same wall-clock
     * second it completed would otherwise resolve that run as its own
     * baseline — reporting every case "unchanged" against itself, which
     * is a misleading report rather than the plain "no reference is set
     * for this run yet" answer such a run actually warrants. Skipping it
     * also keeps an already-shown comparison stable when its own run is
     * later promoted to reference: the earlier designation that was
     * genuinely active when that run finished still wins.
     */
    public function activeAt(string $agentLabel, \DateTimeInterface $at, ?string $excludeRunId = null): ?EvalReferenceDesignation
    {
        $query = $this->historyQuery($agentLabel)
            ->where('created_at', '<=', $at);

        if ($excludeRunId !== null) {
            $query->where('run_id', '!=', $excludeRunId);
        }

        return $query->first();
    }

    private function historyQuery(string $agentLabel)
    {
        return EvalReferenceDesignation::where('agent_label', $agentLabel)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }
}
