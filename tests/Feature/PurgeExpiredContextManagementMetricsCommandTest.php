<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\ContextManagementRecord;
use ClarionApp\LlmClient\Models\ContextManagementSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;

/**
 * Feature tests for PurgeExpiredContextManagementMetricsCommand.
 *
 * Tests expiration threshold filtering, conversation summary purge,
 * user summary exemption, and dry-run mode.
 */
class PurgeExpiredContextManagementMetricsCommandTest extends TestCase
{
    use RefreshDatabase;

    // Tables are created by TestCase::defineDatabaseMigrations().

    #[Test]
    public function purge_removes_expired_detail_records()
    {
        // Create expired record (100 days old, default retention is 90)
        DB::table('context_management_records')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'conversation_id' => 'conv-expired',
            'user_id' => 'user-1',
            'mechanism' => 'none',
            'context_capacity' => 128000,
            'tokens_before' => 50000,
            'tokens_after' => 50000,
            'tokens_saved' => 0,
            'created_at' => CarbonImmutable::now()->subDays(100),
        ]);

        // Create recent record (10 days old)
        $recentId = (string) \Illuminate\Support\Str::uuid();
        DB::table('context_management_records')->insert([
            'id' => $recentId,
            'conversation_id' => 'conv-recent',
            'user_id' => 'user-1',
            'mechanism' => 'none',
            'context_capacity' => 128000,
            'tokens_before' => 30000,
            'tokens_after' => 30000,
            'tokens_saved' => 0,
            'created_at' => CarbonImmutable::now()->subDays(10),
        ]);

        // Run purge
        $exitCode = Artisan::call('llm-client:purge-context-metrics');

        $this->assertSame(0, $exitCode);

        // Expired record should be gone
        $this->assertDatabaseMissing('context_management_records', [
            'conversation_id' => 'conv-expired',
        ]);

        // Recent record should still exist
        $this->assertDatabaseHas('context_management_records', [
            'id' => $recentId,
        ]);
    }

    #[Test]
    public function purge_removes_expired_conversation_summaries()
    {
        // Create expired conversation summary
        DB::table('context_management_summaries')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'entity_type' => 'conversation',
            'entity_id' => 'conv-expired',
            'trim_activations' => 5,
            'smart_trim_activations' => 0,
            'condense_activations' => 2,
            'total_tokens_saved' => 10000,
            'total_requests' => 50,
            'updated_at' => CarbonImmutable::now()->subDays(100),
        ]);

        // Create recent conversation summary
        $recentEntityId = 'conv-recent';
        DB::table('context_management_summaries')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'entity_type' => 'conversation',
            'entity_id' => $recentEntityId,
            'trim_activations' => 1,
            'smart_trim_activations' => 0,
            'condense_activations' => 0,
            'total_tokens_saved' => 500,
            'total_requests' => 5,
            'updated_at' => CarbonImmutable::now()->subDays(10),
        ]);

        // Run purge
        $exitCode = Artisan::call('llm-client:purge-context-metrics');

        $this->assertSame(0, $exitCode);

        // Expired conversation summary should be gone
        $this->assertDatabaseMissing('context_management_summaries', [
            'entity_type' => 'conversation',
            'entity_id' => 'conv-expired',
        ]);

        // Recent conversation summary should still exist
        $this->assertDatabaseHas('context_management_summaries', [
            'entity_type' => 'conversation',
            'entity_id' => $recentEntityId,
        ]);
    }

    #[Test]
    public function purge_never_removes_user_summaries()
    {
        // Create user summary that is very old
        $userId = 'user-retained';
        DB::table('context_management_summaries')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'entity_type' => 'user',
            'entity_id' => $userId,
            'trim_activations' => 100,
            'smart_trim_activations' => 50,
            'condense_activations' => 20,
            'total_tokens_saved' => 500000,
            'total_requests' => 1000,
            'updated_at' => CarbonImmutable::now()->subDays(200),
        ]);

        // Run purge
        $exitCode = Artisan::call('llm-client:purge-context-metrics');

        $this->assertSame(0, $exitCode);

        // User summary should still exist (exempt from purging)
        $this->assertDatabaseHas('context_management_summaries', [
            'entity_type' => 'user',
            'entity_id' => $userId,
        ]);
    }

    #[Test]
    public function dry_run_does_not_delete_anything()
    {
        // Create expired record
        DB::table('context_management_records')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'conversation_id' => 'conv-dry-run',
            'user_id' => 'user-1',
            'mechanism' => 'trim',
            'context_capacity' => 128000,
            'tokens_before' => 100000,
            'tokens_after' => 80000,
            'tokens_saved' => 20000,
            'created_at' => CarbonImmutable::now()->subDays(100),
        ]);

        // Create expired conversation summary
        DB::table('context_management_summaries')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'entity_type' => 'conversation',
            'entity_id' => 'conv-dry-run',
            'trim_activations' => 10,
            'smart_trim_activations' => 0,
            'condense_activations' => 0,
            'total_tokens_saved' => 50000,
            'total_requests' => 30,
            'updated_at' => CarbonImmutable::now()->subDays(100),
        ]);

        // Run purge with dry-run flag
        $exitCode = Artisan::call('llm-client:purge-context-metrics', ['--dry-run' => true]);

        $this->assertSame(0, $exitCode);

        // Expired record should still exist
        $this->assertDatabaseHas('context_management_records', [
            'conversation_id' => 'conv-dry-run',
        ]);

        // Expired summary should still exist
        $this->assertDatabaseHas('context_management_summaries', [
            'entity_type' => 'conversation',
            'entity_id' => 'conv-dry-run',
        ]);
    }

    #[Test]
    public function custom_days_option_overrides_config()
    {
        // Create record that is 50 days old (within default 90-day retention)
        DB::table('context_management_records')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'conversation_id' => 'conv-custom',
            'user_id' => 'user-1',
            'mechanism' => 'none',
            'context_capacity' => 128000,
            'tokens_before' => 40000,
            'tokens_after' => 40000,
            'tokens_saved' => 0,
            'created_at' => CarbonImmutable::now()->subDays(50),
        ]);

        // Run purge with --days=30 (shorter retention)
        $exitCode = Artisan::call('llm-client:purge-context-metrics', ['--days' => 30]);

        $this->assertSame(0, $exitCode);

        // Record should be purged (50 days > 30 day cutoff)
        $this->assertDatabaseMissing('context_management_records', [
            'conversation_id' => 'conv-custom',
        ]);
    }

    #[Test]
    public function purge_handles_empty_tables_gracefully()
    {
        // No records or summaries exist
        $exitCode = Artisan::call('llm-client:purge-context-metrics');

        $this->assertSame(0, $exitCode);
    }
}
