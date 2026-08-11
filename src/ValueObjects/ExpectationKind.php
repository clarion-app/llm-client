<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The closed, six-member set of ways a response to a case can be judged
 * (FR-004). Deliberately not an open-ended expression language — an
 * unrecognized kind string is always rejected by Expectation::validate(),
 * never coerced.
 */
enum ExpectationKind: string
{
    case TextMatch = 'text_match';
    case InformationPresent = 'information_present';
    case ActionTaken = 'action_taken';
    case ActionNotTaken = 'action_not_taken';
    case HumanJudgment = 'human_judgment';
    case RubricJudgment = 'rubric_judgment';
}
