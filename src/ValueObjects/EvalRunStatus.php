<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The closed, four-value state machine an eval run moves through
 * (data-model.md §1). Deliberately no "cancelled"/"paused" case.
 *
 *   absent --start()--> failed_to_start                 [terminal]
 *                    \-> in_progress --(last case out)--> completed  [terminal]
 *                                    \-> incomplete --resume()--> in_progress
 */
enum EvalRunStatus: string
{
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Incomplete = 'incomplete';
    case FailedToStart = 'failed_to_start';
}
