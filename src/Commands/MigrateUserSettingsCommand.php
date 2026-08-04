<?php

namespace ClarionApp\LlmClient\Commands;

use ClarionApp\LlmClient\Services\UserSettingRoleBackfill;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Manual re-run of the FR-019 backfill.
 *
 * The backfill runs automatically as a data-only migration at deploy time
 * (2026_08_03_000001_migrate_llm_user_settings_to_role_assignments) — FR-019
 * requires no operator action. This command exists for the cases where an
 * operator wants to run it again on demand (a node restored from a backup taken
 * before the migration ran, say). It is idempotent: users who already have an
 * inference assignment are left alone.
 */
class MigrateUserSettingsCommand extends Command
{
    protected $signature = 'llm-client:migrate-user-settings {--force : Force the operation to run in production}';
    protected $description = 'Migrate existing user settings to inference role assignments (idempotent; normally already done by migration)';

    public function handle(UserSettingRoleBackfill $backfill): int
    {
        if (!app()->environment('local', 'testing') && !$this->option('force')) {
            $this->error('This command will modify production data. Use --force to proceed.');
            return self::FAILURE;
        }

        if (!DB::getSchemaBuilder()->hasTable('llm_user_settings')) {
            $this->info('No llm_user_settings table found. Nothing to migrate.');
            return self::SUCCESS;
        }

        if (!DB::getSchemaBuilder()->hasTable('llm_role_assignments')) {
            $this->error('llm_role_assignments table does not exist. Run migrations first.');
            return self::FAILURE;
        }

        $result = $backfill->run();

        $this->info(sprintf(
            'Migration complete: %d users migrated, %d skipped (null values), %d skipped (soft-deleted).',
            $result['migrated'],
            $result['skipped'],
            $result['soft_deleted'],
        ));

        return self::SUCCESS;
    }
}
