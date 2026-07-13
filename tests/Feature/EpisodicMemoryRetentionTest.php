<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\EpisodicMemory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;

/**
 * Feature tests for episodic memory retention and cleanup.
 *
 * Seeds entries with varying ages and protection flags,
 * runs cleanup, and verifies only non-protected expired entries are deleted.
 */
class EpisodicMemoryRetentionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    #[Test]
    public function retention_cleanup_removes_only_non_protected_expired_entries()
    {
        // Create a mix of memories with different ages and protection flags
        $recentUnprotected = EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-recent-unprotected',
            'summary' => 'Recent unprotected memory',
            'topics' => ['recent'],
            'protected' => false,
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);

        $recentProtected = EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-recent-protected',
            'summary' => 'Recent protected memory',
            'topics' => ['important'],
            'protected' => true,
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);

        $expiredUnprotected = EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-expired-unprotected',
            'summary' => 'Expired unprotected memory',
            'topics' => ['old'],
            'protected' => false,
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);
        $expiredUnprotected->created_at = CarbonImmutable::now()->subDays(100);
        $expiredUnprotected->save();

        $expiredProtected = EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-expired-protected',
            'summary' => 'Expired protected memory',
            'topics' => ['important', 'old'],
            'protected' => true,
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);
        $expiredProtected->created_at = CarbonImmutable::now()->subDays(100);
        $expiredProtected->save();

        // Run cleanup
        $exitCode = Artisan::call('episodic-memory:cleanup');

        // Command should succeed
        $this->assertSame(0, $exitCode);

        // Recent unprotected: still exists
        $this->assertDatabaseHas('episodic_memories', [
            'id' => $recentUnprotected->id,
        ]);

        // Recent protected: still exists
        $this->assertDatabaseHas('episodic_memories', [
            'id' => $recentProtected->id,
        ]);

        // Expired unprotected: soft-deleted
        $this->assertDatabaseMissing('episodic_memories', [
            'id' => $expiredUnprotected->id,
            'deleted_at' => null,
        ]);

        // Expired protected: still exists (protected exemption)
        $this->assertDatabaseHas('episodic_memories', [
            'id' => $expiredProtected->id,
        ]);

        $refreshedProtected = EpisodicMemory::find($expiredProtected->id);
        $this->assertNotNull($refreshedProtected);
        $this->assertNull($refreshedProtected->deleted_at);
    }

    #[Test]
    public function retention_cleanup_handles_multiple_users_independently()
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        // User A: expired unprotected memory
        $memoryA = EpisodicMemory::create([
            'user_id' => $userA->id,
            'conversation_id' => 'conv-a-expired',
            'summary' => 'User A expired memory',
            'topics' => ['a'],
            'protected' => false,
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);
        $memoryA->created_at = CarbonImmutable::now()->subDays(100);
        $memoryA->save();

        // User B: expired unprotected memory
        $memoryB = EpisodicMemory::create([
            'user_id' => $userB->id,
            'conversation_id' => 'conv-b-expired',
            'summary' => 'User B expired memory',
            'topics' => ['b'],
            'protected' => false,
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);
        $memoryB->created_at = CarbonImmutable::now()->subDays(100);
        $memoryB->save();

        // Run cleanup scoped to User A
        Artisan::call('episodic-memory:cleanup', ['--user-id' => $userA->id]);

        // User A's memory should be soft-deleted
        $this->assertDatabaseMissing('episodic_memories', [
            'id' => $memoryA->id,
            'deleted_at' => null,
        ]);

        // User B's memory should still exist (not affected by scoped cleanup)
        $this->assertDatabaseHas('episodic_memories', [
            'id' => $memoryB->id,
        ]);
    }

    #[Test]
    public function retention_cleanup_with_custom_retention_period()
    {
        // Create memories at different ages
        $memory30Days = EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-30',
            'summary' => '30 days old',
            'topics' => ['medium'],
            'protected' => false,
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);
        $memory30Days->created_at = CarbonImmutable::now()->subDays(30);
        $memory30Days->save();

        $memory60Days = EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-60',
            'summary' => '60 days old',
            'topics' => ['older'],
            'protected' => false,
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);
        $memory60Days->created_at = CarbonImmutable::now()->subDays(60);
        $memory60Days->save();

        $memory90Days = EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-90',
            'summary' => '90 days old',
            'topics' => ['old'],
            'protected' => false,
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);
        $memory90Days->created_at = CarbonImmutable::now()->subDays(90);
        $memory90Days->save();

        // Run cleanup with 45-day retention
        Artisan::call('episodic-memory:cleanup', ['--days' => 45]);

        // 30-day memory: still exists (within retention)
        $this->assertDatabaseHas('episodic_memories', [
            'id' => $memory30Days->id,
        ]);

        // 60-day memory: soft-deleted (expired)
        $this->assertDatabaseMissing('episodic_memories', [
            'id' => $memory60Days->id,
            'deleted_at' => null,
        ]);

        // 90-day memory: soft-deleted (expired)
        $this->assertDatabaseMissing('episodic_memories', [
            'id' => $memory90Days->id,
            'deleted_at' => null,
        ]);
    }

    #[Test]
    public function dry_run_mode_preserves_all_entries()
    {
        // Create expired unprotected memory
        $expiredMemory = EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-dry-run-expired',
            'summary' => 'Dry run expired memory',
            'topics' => ['dry'],
            'protected' => false,
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);
        $expiredMemory->created_at = CarbonImmutable::now()->subDays(100);
        $expiredMemory->save();

        // Run cleanup with dry-run
        $exitCode = Artisan::call('episodic-memory:cleanup', ['--dry-run' => true]);

        // Command should succeed
        $this->assertSame(0, $exitCode);

        // Memory should still exist (dry-run doesn't delete)
        $this->assertDatabaseHas('episodic_memories', [
            'id' => $expiredMemory->id,
        ]);

        $refreshed = EpisodicMemory::find($expiredMemory->id);
        $this->assertNull($refreshed->deleted_at);
    }

    #[Test]
    public function cleanup_command_reports_metrics()
    {
        // Create expired unprotected memories
        EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-report-1',
            'summary' => 'Report test 1',
            'topics' => ['report'],
            'protected' => false,
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);
        EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-report-2',
            'summary' => 'Report test 2',
            'topics' => ['report'],
            'protected' => false,
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);

        // Run cleanup
        $exitCode = Artisan::call('episodic-memory:cleanup');

        // Command should succeed
        $this->assertSame(0, $exitCode);

        // Output should contain metrics table
        $output = Artisan::output();
        $this->assertStringContainsString('Expired memories', $output);
    }

    #[Test]
    public function cleanup_force_deletes_soft_deleted_entries_after_grace_period()
    {
        // Create memory and soft-delete it 10 days ago (beyond 7-day grace period)
        $oldSoftDeleted = EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-old-soft',
            'summary' => 'Old soft-deleted memory',
            'topics' => ['old'],
            'protected' => false,
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);
        $oldSoftDeleted->delete();
        $oldSoftDeleted->deleted_at = CarbonImmutable::now()->subDays(10);
        $oldSoftDeleted->save();

        // Run cleanup
        Artisan::call('episodic-memory:cleanup');

        // Memory should be force-deleted (permanently removed)
        $this->assertNull(EpisodicMemory::withTrashed()->find($oldSoftDeleted->id));
    }

    #[Test]
    public function cleanup_preserves_recent_soft_deleted_entries_within_grace_period()
    {
        // Create memory and soft-delete it 3 days ago (within 7-day grace period)
        $recentSoftDeleted = EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-recent-soft',
            'summary' => 'Recent soft-deleted memory',
            'topics' => ['recent'],
            'protected' => false,
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);
        $recentSoftDeleted->delete();

        // Run cleanup
        Artisan::call('episodic-memory:cleanup');

        // Memory should still exist in soft-deleted state
        $found = EpisodicMemory::withTrashed()->find($recentSoftDeleted->id);
        $this->assertNotNull($found);
        $this->assertNotNull($found->deleted_at);
    }
}
