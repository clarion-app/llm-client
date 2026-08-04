<?php

use ClarionApp\LlmClient\Services\UserSettingRoleBackfill;
use Illuminate\Database\Migrations\Migration;

/**
 * Every existing per-user default server/model becomes that user's user-scoped
 * inference assignment, "automatically, with no action from the user or the
 * node operator" (spec FR-019) — which is why this is a migration and not only
 * an artisan command. Data-only: it creates and drops nothing.
 *
 * "A user with no saved default gets no assignment, not an empty one" (FR-020)
 * and "no user's effective conversational model differs from what it was
 * before" (FR-021) are properties of UserSettingRoleBackfill, which this
 * delegates to unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new UserSettingRoleBackfill())->run();
    }

    public function down(): void
    {
        // Deliberately irreversible. `llm_user_settings` is left in place by
        // this feature (research.md D9), so rolling back loses nothing — and a
        // blind delete of every inference assignment would also destroy the
        // ones users have chosen since the upgrade.
    }
};
