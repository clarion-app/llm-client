<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The scope vocabulary shared by spending ceilings, threshold
 * notifications, and enforcement decisions.
 *
 * `Installation` and `UserDefault` rows both carry the all-zeros sentinel
 * scope id; only a `User` row carries a real user UUID.
 */
enum BudgetScope: string
{
    case Installation = 'installation';
    case UserDefault = 'user_default';
    case User = 'user';
}
