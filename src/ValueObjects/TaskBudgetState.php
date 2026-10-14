<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * Lifecycle of a task budget. A task is live while running or paused (paused
 * at a ceiling, waiting on or stopped by its prompt); every other state is
 * terminal and is never left again.
 */
enum TaskBudgetState: string
{
    case Running = 'running';
    case Paused = 'paused';
    case Concluded = 'concluded';
    case Completed = 'completed';
    case Halted = 'halted';
    case Ended = 'ended';

    public function isTerminal(): bool
    {
        return $this !== self::Running && $this !== self::Paused;
    }

    /**
     * A budget state that cancels the work it was funding: a stop at the
     * ceiling (`halted`) or an accept of the partial result (`concluded`).
     * `completed` and `ended` are not cancellations — the work finished or
     * the task's lifecycle ended for another reason (superseded, discarded,
     * the request closed) — so an interrupted run is still resumed.
     */
    public function isWorkCancelled(): bool
    {
        return $this === self::Halted || $this === self::Concluded;
    }
}
