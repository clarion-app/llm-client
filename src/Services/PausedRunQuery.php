<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Support\Facades\DB;

/**
 * The runs the abandonment sweep (and the resume-detection sweep) must not
 * touch: a run waiting on a pending, still-answerable human confirmation,
 * or a pending question its user can still answer. Shared by both sweeps
 * so they exempt the same runs.
 */
class PausedRunQuery
{
    /**
     * How long after its deadline a still-pending question keeps its run
     * exempt, covering the expiry sweep that runs after this one in the
     * same scheduler tick.
     */
    private const PENDING_QUESTION_GRACE_MINUTES = 10;

    /**
     * The run ids the sweep must exempt: a pending confirmation still
     * within its timeout window, or a question the user can still answer
     * (or a just-resolved question whose resume is still queued).
     *
     * @return array<string>
     */
    public function exemptRunIds(\DateTimeInterface $cutoff): array
    {
        return array_values(array_unique(array_merge(
            $this->getPendingConfirmationRunIds(),
            $this->getPendingQuestionRunIds($cutoff),
        )));
    }

    /**
     * Get run ids that have a pending confirmation still within the timeout window.
     *
     * These runs are exempt from the sweep because the step is legitimately
     * waiting on a human response (FR-018, SC-008's second clause).
     *
     * The shape read here is the one the agent loop actually writes at a pause
     * (contracts §3.2): `pending_confirmation` is a nested array carrying
     * `expires_at`, and `run_id` sits beside it. A resumed pause sets
     * `pending_confirmation` to null, which is why the array check matters.
     *
     * @return array<string>
     */
    private function getPendingConfirmationRunIds(): array
    {
        if (!DB::getSchemaBuilder()->hasTable('messages')) {
            return [];
        }

        // A confirmation that has not yet expired was written less than
        // confirmation_timeout seconds ago, so only that slice of the message
        // table can hold one. Without this bound the sweep would load and JSON-decode
        // every message ever written, every five minutes. The window is doubled to
        // stay clear of the streamed path, where the message is created earlier in
        // the round than the pause that updates it.
        $timeout = (int) config('llm-client.agent_loop.confirmation_timeout', 300);
        $window = now()->subSeconds(max($timeout * 2, 600));

        $candidates = DB::table('messages')
            ->whereNotNull('tool_data')
            ->where('created_at', '>=', $window)
            ->select('tool_data')
            ->get();

        $exemptRunIds = [];
        foreach ($candidates as $msg) {
            $toolData = is_string($msg->tool_data)
                ? json_decode($msg->tool_data, true)
                : $msg->tool_data;

            if (!is_array($toolData)) {
                continue;
            }

            $pending = $toolData['pending_confirmation'] ?? null;
            $runId = $toolData['run_id'] ?? null;

            if (!is_array($pending) || $runId === null) {
                continue;
            }

            // Decided already: the run is no longer waiting on the person.
            if (isset($pending['decision'])) {
                continue;
            }

            $expiresAt = $pending['expires_at'] ?? null;
            if ($expiresAt === null) {
                continue;
            }

            try {
                if (\Carbon\Carbon::parse($expiresAt)->isFuture()) {
                    $exemptRunIds[] = (string) $runId;
                }
            } catch (\Throwable $e) {
                // An unparseable expiry is not a reason to sweep a run that may
                // still be waiting; treat it as exempt and let the operator see it.
                $exemptRunIds[] = (string) $runId;
            }
        }

        return array_values(array_unique($exemptRunIds));
    }

    /**
     * Get run ids paused on a question that can still be answered.
     *
     * The paused step stays open for as long as the user takes to answer,
     * which is exactly what this sweep looks for. A stopped question waits
     * for a late answer or a discard with no time bound, so its run is
     * always exempt.
     *
     * A pending question's exemption lasts until a short grace after its
     * `expires_at`: this sweep runs before the expiry sweep when both are
     * due in the same scheduler tick, so a question whose deadline has just
     * passed may not have been stopped yet. The bound still ends the
     * exemption when the expiry sweep has stalled, so a stalled sweep can
     * never pin a run open forever.
     *
     * A question resolved since $cutoff whose run has not resumed yet (its
     * resume is queued — an expiry, an answer tapped after the work stopped)
     * is the run's latest activity: the run is about to continue, not
     * abandoned. Once the threshold has passed with no resume, it is swept
     * like any other.
     *
     * @return array<string>
     */
    private function getPendingQuestionRunIds(\DateTimeInterface $cutoff): array
    {
        if (!DB::getSchemaBuilder()->hasTable('agent_questions')) {
            return [];
        }

        return DB::table('agent_questions')
            ->where(function ($query) use ($cutoff) {
                $query->where(function ($pending) {
                    $pending->where('state', QuestionState::Pending->value)
                        ->where('expires_at', '>', now()->subMinutes(self::PENDING_QUESTION_GRACE_MINUTES));
                })->orWhere('state', QuestionState::Stopped->value)
                    ->orWhere(function ($resolved) use ($cutoff) {
                        $resolved->whereNotIn('state', [QuestionState::Pending->value, QuestionState::Stopped->value])
                            ->whereNull('resumed_at')
                            ->where('resolved_at', '>', $cutoff);
                    });
            })
            ->whereNotNull('run_id')
            ->distinct()
            ->pluck('run_id')
            ->map(fn ($runId) => (string) $runId)
            ->all();
    }
}
