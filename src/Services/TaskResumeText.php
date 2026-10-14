<?php

namespace ClarionApp\LlmClient\Services;

use Carbon\CarbonInterface;
use ClarionApp\Backend\ApiManager;

/**
 * Every user-facing word of the resume feature. No other class composes
 * user-facing text for this feature. All strings are plain language, name
 * "this task", and never contain identifiers (run, step, action, tool-call,
 * conversation ids), argument values, credentials, provider error text or
 * HTTP status codes.
 */
class TaskResumeText
{
    /**
     * The tool result given to the agent for an effectful call started
     * without an outcome: the action was interrupted while running, whether
     * it took effect is unknown, check before repeating it, it was not run
     * again.
     */
    public function outcomeUnknownToolResult(string $label): string
    {
        return "The action {$label} was interrupted while it was running, so whether it took effect is unknown. It was not run again. Check before repeating it.";
    }

    /**
     * Conversation notice to the user (one per resumed step): this task was
     * interrupted while doing the named actions; they were not repeated; the
     * agent has been told the result is unknown and will check.
     *
     * @param string[] $labels
     */
    public function outcomeUnknownNotice(array $labels): string
    {
        return "This task was interrupted while doing {$this->joinLabels($labels)}. Those actions were not repeated. The agent has been told the result is unknown and will check before continuing.";
    }

    /**
     * Notice when a resume starts after a user-visible gap (only when the
     * run had been interrupted for at least one detection window): this task
     * was interrupted and is continuing from where it left off (step N).
     */
    public function resumedNotice(int $fromStep): string
    {
        return "This task was interrupted and is continuing from where it left off (step {$fromStep}).";
    }

    /**
     * From the 2nd consecutive provider failure: waiting to retry the model
     * provider; next attempt at HH:MM (the time's own timezone, UTC with
     * "UTC" when it has no short name).
     */
    public function providerWaitingNotice(CarbonInterface $next): string
    {
        return "This task is waiting to retry the model provider. Next attempt at {$this->formatTime($next)}.";
    }

    /**
     * The content of a 202-continuing sync response (sent at the first
     * transient failure on the sync path): the task is still working and its
     * reply will arrive here. Never mentions a failure, an error or the
     * provider — a single blip shows no failure.
     */
    public function continuingText(CarbonInterface $next): string
    {
        return "This task is still working. Its reply will arrive here. It will continue at {$this->formatTime($next)}.";
    }

    /**
     * end_reason (and the report's reason line): the model provider stayed
     * unavailable; no further automatic attempts.
     */
    public function providerUnavailableReason(): string
    {
        return 'This task stopped because the model provider stayed unavailable, so there are no further automatic attempts.';
    }

    /**
     * Channel report: the provider stayed unavailable, no further automatic
     * attempts, and N steps were completed and are kept.
     */
    public function providerUnavailableReport(int $completedSteps): string
    {
        return $this->providerUnavailableReason()." {$completedSteps} steps were completed and are kept.";
    }

    /**
     * end_reason: the model provider refused the request itself, which
     * waiting cannot change.
     */
    public function providerRejectedReason(): string
    {
        return 'The model provider refused the request, so this task stopped and will not be retried.';
    }

    /**
     * Channel report: the provider refused the request, nothing is retried,
     * and N steps were completed and are kept.
     */
    public function providerRejectedReport(int $completedSteps): string
    {
        return $this->providerRejectedReason()." {$completedSteps} steps were completed and are kept.";
    }

    /**
     * end_reason (one sentence per reason code). Every one names "this task"
     * and fits the 256-character end_reason column.
     */
    public function couldNotResumeReason(string $code): string
    {
        if (str_starts_with($code, 'checkpoint_incoherent')) {
            return "This task's saved progress could not be read safely, so it stopped and will not be resumed automatically.";
        }

        return match ($code) {
            'repeated_interruption' => 'This task was interrupted too many times in a row, so it stopped and will not be resumed automatically.',
            'legacy_insufficient' => 'This task was started before this version, and its progress cannot be continued safely, so it stopped.',
            'model_unavailable' => 'This task stopped because the model stayed unavailable, so it will not be resumed automatically.',
            'tool_unavailable' => 'This task stopped because a tool it needed was unavailable, so it will not be resumed automatically.',
            'superseded' => 'This task was superseded by a newer run, so it stopped and will not be resumed.',
            'ended_without_outcome' => 'This task ended without a final outcome, so it stopped and will not be resumed automatically.',
            default => "This task could not be resumed, so it stopped and will not be resumed automatically.",
        };
    }

    /**
     * Channel report: what was completed (the steps kept in the
     * conversation), where it stopped, why, that nothing more will run
     * automatically, and that a new request is welcome.
     */
    public function couldNotResumeReport(string $code, int $completedSteps, ?string $stoppedDuring): string
    {
        $where = $stoppedDuring !== null && $stoppedDuring !== ''
            ? "This task stopped at {$stoppedDuring}."
            : 'This task stopped.';

        return "{$where} {$completedSteps} steps were completed and are kept in the conversation. "
            . $this->couldNotResumeReason($code)
            . ' Nothing more will run automatically. A new request is welcome.';
    }

    /**
     * What the user is told when a request for several opinions was
     * interrupted and, once finished, produced no combined answer.
     */
    public function consensusRequestNotAnswered(): string
    {
        return 'Your request for several opinions was interrupted and could not be turned into a combined answer. You are welcome to ask again.';
    }

    /**
     * What a task is told when the helper it delegated to was interrupted
     * and could not be continued.
     */
    public function helperCouldNotResume(): string
    {
        return 'The helper was interrupted and its work could not be continued safely, so it was stopped.';
    }

    /**
     * end_reason: the task was cancelled.
     */
    public function cancelledReason(): string
    {
        return 'This task was cancelled.';
    }

    /**
     * Label resolution: the operation's summary (the registry's title
     * annotation) → the operation id → the tool name. Never argument values.
     *
     * @param array<string, mixed> $arguments
     */
    public function actionLabel(string $tool, array $arguments): string
    {
        $operationId = $arguments['operationId'] ?? null;

        if (is_string($operationId) && $operationId !== '') {
            $summary = $this->operationSummary($operationId);
            if ($summary !== null) {
                return $summary;
            }

            return $operationId;
        }

        return $tool;
    }

    /**
     * The operation's summary (the same field that populates the registry's
     * title annotation), read through the same ApiManager lookup the registry
     * and the executor's confirmation path already use. Null when the
     * operation is unknown or carries no summary.
     */
    private function operationSummary(string $operationId): ?string
    {
        // Best-effort: the lookup may trigger the OpenAPI doc generation,
        // which is unavailable in some contexts (a test harness with a reset
        // doc cache). A failure here falls back to the operation id, which
        // is the correct label for an unresolvable summary.
        try {
            $details = ApiManager::getOperationDetails($operationId);
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($details) || !isset($details['details'])) {
            return null;
        }

        $summary = $details['details']['summary'] ?? null;

        return is_string($summary) && $summary !== '' ? $summary : null;
    }

    /**
     * Join the named actions: one as-is, two as "X (and Y)", three or more
     * as "X, Y and Z".
     *
     * @param string[] $labels
     */
    private function joinLabels(array $labels): string
    {
        $labels = array_values(array_filter(
            array_map(fn ($label) => trim((string) $label), $labels),
            fn ($label) => $label !== '',
        ));

        $count = count($labels);
        if ($count === 0) {
            return 'its work';
        }
        if ($count === 1) {
            return $labels[0];
        }
        if ($count === 2) {
            return $labels[0].' (and '.$labels[1].')';
        }

        $last = array_pop($labels);

        return implode(', ', $labels).' and '.$last;
    }

    /**
     * Format a moment as HH:MM with its timezone (the short name, or "UTC"
     * when the timezone has no short name).
     */
    private function formatTime(CarbonInterface $next): string
    {
        $timezone = $next->format('e');
        if ($timezone === '') {
            $timezone = 'UTC';
        }

        return $next->format('H:i').' '.$timezone;
    }
}
