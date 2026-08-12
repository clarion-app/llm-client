<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The empirical-precedent verdict attached only to a Regressed or
 * MateriallyDrifted CaseComparisonResult — never to any other category.
 * OrdinaryVariation means this agent's own run history already contains
 * a prior instance of this exact category of difference for this case;
 * LikelyRegression means it does not; InsufficientHistory means there
 * isn't yet enough comparable history to answer either way.
 */
enum VarianceConfidence: string
{
    case OrdinaryVariation = 'ordinary_variation';
    case LikelyRegression = 'likely_regression';
    case InsufficientHistory = 'insufficient_history';
}
