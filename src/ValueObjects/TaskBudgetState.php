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
}
