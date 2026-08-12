<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The four axes DegradationGate::evaluate() can select a governing
 * ReductionStep against (data-model.md §5, research.md D7). A plain string-
 * backed enum, not a DB enum column — ReductionStep.axis is a plain
 * string(20), matching rate_limits.scope_type's own reasoning that a future
 * axis is a code change, never an ALTER TABLE.
 */
enum LimitAxis: string
{
    case BudgetUser = 'budget_user';
    case BudgetInstallation = 'budget_installation';
    case RateLimit = 'rate_limit';
    case ConversationWork = 'conversation_work';
}
