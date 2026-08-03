<?php

namespace ClarionApp\LlmClient\Commands;

use ClarionApp\LlmClient\Models\RoleAssignment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MigrateUserSettingsCommand extends Command
{
    protected $signature = 'llm-client:migrate-user-settings {--force : Force the operation to run in production}';
    protected $description = 'Migrate existing user settings to role assignments';

    public function handle(): int
    {
        if (!app()->environment('local', 'testing') && !$this->option('force')) {
            $this->error('This command will modify production data. Use --force to proceed.');
            return self::FAILURE;
        }

        // Check if the source table exists.
        if (!DB::getSchemaBuilder()->hasTable('llm_user_settings')) {
            $this->info('No llm_user_settings table found. Nothing to migrate.');
            return self::SUCCESS;
        }

        // Check if the target table exists.
        if (!DB::getSchemaBuilder()->hasTable('llm_role_assignments')) {
            $this->error('llm_role_assignments table does not exist. Run migrations first.');
            return self::FAILURE;
        }

        // Count total rows to migrate (active, non-null settings).
        $totalSettings = DB::table('llm_user_settings')
            ->whereNull('deleted_at')
            ->whereNotNull('server_id')
            ->whereNotNull('model')
            ->count();

        $skippedCount = DB::table('llm_user_settings')
            ->whereNull('deleted_at')
            ->where(function ($query) {
                $query->whereNull('server_id')->orWhereNull('model');
            })
            ->count();

        $softDeletedCount = DB::table('llm_user_settings')
            ->whereNotNull('deleted_at')
            ->count();

        $this->info("Found {$totalSettings} active settings to migrate, {$skippedCount} skipped (null values), {$softDeletedCount} soft-deleted.");

        if ($totalSettings === 0) {
            $this->info('Nothing to migrate.');
            return self::SUCCESS;
        }

        // Disable events on RoleAssignment to avoid chain sync overhead during migration.
        // This is safe because we're doing a bulk data migration, not setting up
        // assignments that need to be replicated across nodes.
        RoleAssignment::unsetEventDispatcher();

        $migratedCount = 0;
        $progress = $this->output->createProgressBar($totalSettings);
        $progress->start();

        DB::table('llm_user_settings')
            ->whereNull('deleted_at')
            ->whereNotNull('server_id')
            ->whereNotNull('model')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$migratedCount, $progress) {
                foreach ($rows as $row) {
                    // Skip if an assignment already exists (idempotency).
                    $exists = DB::table('llm_role_assignments')
                        ->where('role', 'inference')
                        ->where('user_id', $row->user_id)
                        ->exists();

                    if ($exists) {
                        $progress->advance();
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

                    $migratedCount++;
                    $progress->advance();
                }
            });

        $progress->finish();
        $this->newLine(2);

        // Re-enable events after migration is complete.
        RoleAssignment::setEventDispatcher(app('events'));

        $this->info("Migration complete: {$migratedCount} users migrated, {$skippedCount} skipped (null values), {$softDeletedCount} skipped (soft-deleted).");

        return self::SUCCESS;
    }
}
