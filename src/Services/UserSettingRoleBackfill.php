<?php

namespace ClarionApp\LlmClient\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * One-time backfill of `llm_user_settings` into user-scoped inference
 * assignments (FR-019/FR-020/FR-021, research.md D9).
 *
 * Lives in one class because it has two callers that must not diverge: the
 * data-only migration that runs it automatically at deploy time — FR-019 is
 * explicit that no operator action is required — and the artisan command that
 * lets an operator re-run it by hand afterwards. Both are idempotent.
 *
 * Writes go through `DB::table()` rather than `RoleAssignment::create()` on
 * purpose: bypassing the model bypasses EloquentMultiChainBridge's `created`
 * hook, so deploying this feature does not emit one MultiChain publish per
 * pre-existing user for data no one just touched (research.md D9).
 */
class UserSettingRoleBackfill
{
    /**
     * @return array{migrated: int, skipped: int, soft_deleted: int, ran: bool}
     *         `ran` is false when either table is absent — nothing to do, not a failure.
     */
    public function run(): array
    {
        $result = ['migrated' => 0, 'skipped' => 0, 'soft_deleted' => 0, 'ran' => false];

        if (!Schema::hasTable('llm_user_settings') || !Schema::hasTable('llm_role_assignments')) {
            return $result;
        }

        $result['ran'] = true;

        $result['skipped'] = DB::table('llm_user_settings')
            ->whereNull('deleted_at')
            ->where(function ($query) {
                $query->whereNull('server_id')->orWhereNull('model');
            })
            ->count();

        $result['soft_deleted'] = DB::table('llm_user_settings')
            ->whereNotNull('deleted_at')
            ->count();

        DB::table('llm_user_settings')
            ->whereNull('deleted_at')
            ->whereNotNull('server_id')
            ->whereNotNull('model')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$result) {
                foreach ($rows as $row) {
                    // Idempotent: a user who already has an inference assignment
                    // — migrated on an earlier run, or chosen since — keeps it.
                    $exists = DB::table('llm_role_assignments')
                        ->where('role', 'inference')
                        ->where('user_id', $row->user_id)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    DB::table('llm_role_assignments')->insert([
                        'id' => (string) Str::uuid(),
                        'role' => 'inference',
                        'user_id' => $row->user_id,
                        'server_id' => $row->server_id,
                        'model' => $row->model,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $result['migrated']++;
                }
            });

        return $result;
    }
}
