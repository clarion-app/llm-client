<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * Per-case progress within a run's snapshot (data-model.md §2).
 *
 * pending (written at snapshot time) -> dispatched (the moment
 * RunEvalCaseJob::dispatch() is called) -> completed (set by
 * EvalCaseExecutor in the same write as the eval_case_results row).
 * dispatched can be revisited on resume/sweep redispatch without moving
 * back to pending — pending specifically means "never yet reached the
 * queue."
 */
enum EvalRunCaseStatus: string
{
    case Pending = 'pending';
    case Dispatched = 'dispatched';
    case Completed = 'completed';
}
