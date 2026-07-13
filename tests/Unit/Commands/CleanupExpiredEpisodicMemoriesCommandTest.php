<?php

namespace ClarionApp\LlmClient\Tests\Unit\Commands;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\EpisodicMemory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for CleanupExpiredEpisodicMemoriesCommand.
 *
 * Tests expiration threshold filtering, protected entry exemption,
 * per-user scoping, and dry-run mode.
 */
class CleanupExpiredEpisodicMemoriesCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    #[Test]
    public function cleanup_removes_non_protected_expired_memories()
    {
        // Create expired memory (100 days old, default retention is 90)
        $expiredMemory = EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-expired',
            'summary' => 'Old expired memory',
            'topics' => ['old'],
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);
        $expiredMemory->created_at = CarbonImmutable::now()->subDays(100);
        $expiredMemory->save();

        // Create recent memory (10 days old)
        $recentMemory = EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-recent',
            'summary' => 'Recent memory',
            'topics' => ['new'],
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);

        // Run cleanup
        $exitCode = Artisan::call('episodic-memory:cleanup');

        // Verify exit code
        $this->assertSame(0, $exitCode);

        // Expired memory should be soft-deleted
        $this->assertDatabaseMissing('episodic_memories', [
            'id' => $expiredMemory->id,
            'deleted_at' => null,
        ]);

        // Recent memory should still exist
        $this->assertDatabaseHas('episodic_memories', [
            'id' => $recentMemory->id,
        ]);
    }

    #[Test]
    public function cleanup_skips_protected_memories_even_when_expired()
    {
        // Create protected expired memory
        $protectedMemory = EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-protected',
            'summary' => 'Protected memory',
            'topics' => ['important'],
            'protected' => true,
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);
        $protectedMemory->created_at = CarbonImmutable::now()->subDays(100);
        $protectedMemory->save();

        // Run cleanup
        Artisan::call('episodic-memory:cleanup');

        // Protected memory should still exist (not soft-deleted)
        $this->assertDatabaseHas('episodic_memories', [
            'id' => $protectedMemory->id,
        ]);

        $refreshed = EpisodicMemory::find($protectedMemory->id);
        $this->assertNull($refreshed->deleted_at);
    }

    #[Test]
    public function cleanup_supports_custom_days_option()
    {
        // Create memory that's 30 days old
        $memory30Days = EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-30',
            'summary' => '30 days old',
            'topics' => ['medium'],
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);
        $memory30Days->created_at = CarbonImmutable::now()->subDays(30);
        $memory30Days->save();

        // Run cleanup with 20-day retention (memory should be expired)
        Artisan::call('episodic-memory:cleanup', ['--days' => 20]);

        // Memory should be soft-deleted
        $this->assertDatabaseMissing('episodic_memories', [
            'id' => $memory30Days->id,
            'deleted_at' => null,
        ]);

        // Run cleanup with 60-day retention (memory would not be expired)
        // Recreate the memory
        $memory30Days2 = EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-30-2',
            'summary' => '30 days old again',
            'topics' => ['medium'],
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);
        $memory30Days2->created_at = CarbonImmutable::now()->subDays(30);
        $memory30Days2->save();

        Artisan::call('episodic-memory:cleanup', ['--days' => 60]);

        // Memory should still exist (not expired with 60-day retention)
        $this->assertDatabaseHas('episodic_memories', [
            'id' => $memory30Days2->id,
        ]);
    }

    #[Test]
    public function cleanup_supports_dry_run_mode()
    {
        // Create expired memory
        $expiredMemory = EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-dry-run',
            'summary' => 'Dry run test',
            'topics' => ['test'],
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);
        $expiredMemory->created_at = CarbonImmutable::now()->subDays(100);
        $expiredMemory->save();

        // Run cleanup with dry-run
        Artisan::call('episodic-memory:cleanup', ['--dry-run' => true]);

        // Memory should still exist (dry-run doesn't delete)
        $this->assertDatabaseHas('episodic_memories', [
            'id' => $expiredMemory->id,
        ]);

        $refreshed = EpisodicMemory::find($expiredMemory->id);
        $this->assertNull($refreshed->deleted_at);
    }

    #[Test]
    public function cleanup_supports_user_id_scoping()
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        // Create expired memory for User A
        $memoryA = EpisodicMemory::create([
            'user_id' => $userA->id,
            'conversation_id' => 'conv-a',
            'summary' => 'User A memory',
            'topics' => ['a'],
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);
        $memoryA->created_at = CarbonImmutable::now()->subDays(100);
        $memoryA->save();

        // Create expired memory for User B
        $memoryB = EpisodicMemory::create([
            'user_id' => $userB->id,
            'conversation_id' => 'conv-b',
            'summary' => 'User B memory',
            'topics' => ['b'],
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);
        $memoryB->created_at = CarbonImmutable::now()->subDays(100);
        $memoryB->save();

        // Run cleanup scoped to User A only
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
    public function cleanup_force_deletes_old_soft_deleted_entries()
    {
        // Create memory and soft-delete it 10 days ago
        $oldSoftDeletedMemory = EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-old-soft',
            'summary' => 'Old soft-deleted memory',
            'topics' => ['old'],
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);
        $oldSoftDeletedMemory->delete();
        $oldSoftDeletedMemory->deleted_at = CarbonImmutable::now()->subDays(10);
        $oldSoftDeletedMemory->save();

        // Run cleanup
        Artisan::call('episodic-memory:cleanup');

        // Memory should be force-deleted (permanently removed)
        $this->assertNull(EpisodicMemory::withTrashed()->find($oldSoftDeletedMemory->id));
    }

    #[Test]
    public function cleanup_preserves_recent_soft_deleted_entries()
    {
        // Create memory and soft-delete it 2 days ago
        $recentSoftDeletedMemory = EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-recent-soft',
            'summary' => 'Recent soft-deleted memory',
            'topics' => ['recent'],
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);
        $recentSoftDeletedMemory->delete();

        // Run cleanup
        Artisan::call('episodic-memory:cleanup');

        // Memory should still exist in soft-deleted state (not force-deleted yet)
        $found = EpisodicMemory::withTrashed()->find($recentSoftDeletedMemory->id);
        $this->assertNotNull($found);
        $this->assertNotNull($found->deleted_at);
    }

    #[Test]
    public function dry_run_reports_would_be_deleted_count()
    {
        // Create expired memories
        EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-dry-1',
            'summary' => 'Dry run 1',
            'topics' => ['dry'],
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);
        EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => 'conv-dry-2',
            'summary' => 'Dry run 2',
            'topics' => ['dry'],
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);

        // Run dry-run cleanup
        $exitCode = Artisan::call('episodic-memory:cleanup', ['--dry-run' => true]);

        // Command should succeed
        $this->assertSame(0, $exitCode);

        // Output should contain "Dry-run" or "Would delete"
        $output = Artisan::output();
        $this->assertStringContainsString('Dry-run', $output);
    }
}
