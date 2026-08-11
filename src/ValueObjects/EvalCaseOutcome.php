<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The durable outcome recorded once per case per run (FR-003/FR-005).
 * `Errored` is not a judging outcome at all — it is set instead of
 * calling EvalCaseJudge, when the case's agent call itself threw, timed
 * out, or was refused by the budget gate.
 */
enum EvalCaseOutcome: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case NeedsHumanReview = 'needs_human_review';
    case Errored = 'errored';
}
