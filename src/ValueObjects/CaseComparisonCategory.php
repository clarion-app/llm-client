<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The closed, eight-value classification every case in either run's
 * eval_run_cases snapshot lands on when a comparison is built. Added and
 * Removed cover a case present in only one run's snapshot; Edited covers
 * a case present in both but pinned to a different eval_case_version_id;
 * Inconclusive covers a matched case where either side lacks a clean
 * pass/fail result; the remaining four cover a matched case where both
 * sides have a clean pass/fail (or scored) result.
 */
enum CaseComparisonCategory: string
{
    case Added = 'added';
    case Removed = 'removed';
    case Edited = 'edited';
    case Inconclusive = 'inconclusive';
    case Regressed = 'regressed';
    case Improved = 'improved';
    case MateriallyDrifted = 'materially_drifted';
    case Unchanged = 'unchanged';
}
