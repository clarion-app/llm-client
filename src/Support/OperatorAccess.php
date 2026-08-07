<?php

namespace ClarionApp\LlmClient\Support;

/**
 * Config-driven operator allow-list (research.md D4).
 *
 * This codebase has no real elevated/administrative role system wired up yet
 * (Spatie's `HasRoles` trait is present on the User model but its supporting
 * tables are never migrated) — so "operator" for this feature's FR-017/FR-021
 * role-scoping is a small, self-contained allow-list of user UUID strings,
 * sourced from `config('llm-client.cost.operator_user_ids')`.
 */
final class OperatorAccess
{
    public static function isOperator(?string $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        return in_array($userId, config('llm-client.cost.operator_user_ids', []), true);
    }
}
