<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\FeedbackSignal;
use ClarionApp\LlmClient\Models\FeedbackOptOut;
use ClarionApp\LlmClient\Services\FeedbackSignalAccumulator;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

class FeedbackSignalAccumulatorTest extends TestCase
{
    protected FeedbackSignalAccumulator $accumulator;
    protected string $testUserToken = 'test-token';

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure tables exist
        if (!Schema::hasTable('feedback_signals')) {
            Schema::create('feedback_signals', function ($table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->uuid('source_event_id')->nullable();
                $table->uuid('conversation_id')->nullable();
                $table->string('signal_type');
                $table->string('pattern_key')->nullable();
                $table->text('raw_context');
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->softDeletes();

                $table->unique(['user_id', 'source_event_id']);
                $table->index(['user_id', 'pattern_key', 'processed_at']);
                $table->index(['user_id', 'processed_at']);
            });
        }

        if (!Schema::hasTable('feedback_opt_outs')) {
            Schema::create('feedback_opt_outs', function ($table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->string('pattern_key');
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->nullable();
                $table->softDeletes();

                $table->unique(['user_id', 'pattern_key']);
            });
        }

        $this->accumulator = new FeedbackSignalAccumulator();
    }

    /* -----------------------------------------------------------------
     * T015: Threshold Promotion Tests
     * ----------------------------------------------------------------- */

    #[Test]
    public function threshold_promotion_five_consistent_signals_trigger_proposal(): void
    {
        $user = User::factory()->create();
        $patternKey = 'prefer_concise_responses';

        // Record 4 signals — should NOT trigger proposal yet
        for ($i = 0; $i < 4; $i++) {
            FeedbackSignal::withoutGlobalScope('user')->create([
                'id' => Str::uuid()->toString(),
                'user_id' => $user->id,
                'source_event_id' => Str::uuid()->toString(),
                'signal_type' => FeedbackSignal::SIGNAL_APPROVAL,
                'pattern_key' => $patternKey,
                'raw_context' => "Test signal {$i}",
            ]);
        }

        // Create fresh accumulator to read from DB
        $accumulator = new FeedbackSignalAccumulator();
        $this->assertFalse($accumulator->shouldPropose($user->id, $patternKey));
        $this->assertEquals(4, $accumulator->getEffectiveCount($user->id, $patternKey));

        // Record 5th signal — should trigger proposal
        FeedbackSignal::withoutGlobalScope('user')->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'source_event_id' => Str::uuid()->toString(),
            'signal_type' => FeedbackSignal::SIGNAL_APPROVAL,
            'pattern_key' => $patternKey,
            'raw_context' => 'Test signal 5',
        ]);

        $accumulator = new FeedbackSignalAccumulator();
        $this->assertTrue($accumulator->shouldPropose($user->id, $patternKey));
        $this->assertEquals(5, $accumulator->getEffectiveCount($user->id, $patternKey));
    }

    #[Test]
    public function threshold_promotion_fewer_than_five_signals_no_proposal(): void
    {
        $user = User::factory()->create();
        $patternKey = 'prefer_concise_responses';

        for ($i = 0; $i < 3; $i++) {
            FeedbackSignal::withoutGlobalScope('user')->create([
                'id' => Str::uuid()->toString(),
                'user_id' => $user->id,
                'source_event_id' => Str::uuid()->toString(),
                'signal_type' => FeedbackSignal::SIGNAL_APPROVAL,
                'pattern_key' => $patternKey,
                'raw_context' => "Test signal {$i}",
            ]);
        }

        $this->assertFalse($this->accumulator->shouldPropose($user->id, $patternKey));
    }

    #[Test]
    public function threshold_promotion_corrections_count_toward_threshold(): void
    {
        $user = User::factory()->create();
        $patternKey = 'prefer_dark_mode';

        // 3 approvals + 2 corrections = 5 effective
        for ($i = 0; $i < 3; $i++) {
            FeedbackSignal::withoutGlobalScope('user')->create([
                'id' => Str::uuid()->toString(),
                'user_id' => $user->id,
                'source_event_id' => Str::uuid()->toString(),
                'signal_type' => FeedbackSignal::SIGNAL_APPROVAL,
                'pattern_key' => $patternKey,
                'raw_context' => "Approval {$i}",
            ]);
        }

        for ($i = 0; $i < 2; $i++) {
            FeedbackSignal::withoutGlobalScope('user')->create([
                'id' => Str::uuid()->toString(),
                'user_id' => $user->id,
                'source_event_id' => Str::uuid()->toString(),
                'signal_type' => FeedbackSignal::SIGNAL_CORRECTION,
                'pattern_key' => $patternKey,
                'raw_context' => "Correction {$i}",
            ]);
        }

        $this->assertTrue($this->accumulator->shouldPropose($user->id, $patternKey));
        $this->assertEquals(5, $this->accumulator->getEffectiveCount($user->id, $patternKey));
    }

    /* -----------------------------------------------------------------
     * T016: Contradiction Decay Tests
     * ----------------------------------------------------------------- */

    #[Test]
    public function contradiction_decay_reduces_effective_count(): void
    {
        $user = User::factory()->create();
        $patternKey = 'prefer_concise_responses';

        // 5 approvals
        for ($i = 0; $i < 5; $i++) {
            FeedbackSignal::withoutGlobalScope('user')->create([
                'id' => Str::uuid()->toString(),
                'user_id' => $user->id,
                'source_event_id' => Str::uuid()->toString(),
                'signal_type' => FeedbackSignal::SIGNAL_APPROVAL,
                'pattern_key' => $patternKey,
                'raw_context' => "Approval {$i}",
            ]);
        }

        $this->assertTrue($this->accumulator->shouldPropose($user->id, $patternKey));

        // 1 rejection — reduces effective count by contradiction_decay (2)
        FeedbackSignal::withoutGlobalScope('user')->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'source_event_id' => Str::uuid()->toString(),
            'signal_type' => FeedbackSignal::SIGNAL_REJECTION,
            'pattern_key' => $patternKey,
            'raw_context' => 'Rejection',
        ]);

        // Effective count: 5 - (1 * 2) = 3
        $this->assertFalse($this->accumulator->shouldPropose($user->id, $patternKey));
        $this->assertEquals(3, $this->accumulator->getEffectiveCount($user->id, $patternKey));
    }

    #[Test]
    public function contradiction_decay_multiple_rejections_drop_below_threshold(): void
    {
        $user = User::factory()->create();
        $patternKey = 'prefer_concise_responses';

        // 5 approvals
        for ($i = 0; $i < 5; $i++) {
            FeedbackSignal::withoutGlobalScope('user')->create([
                'id' => Str::uuid()->toString(),
                'user_id' => $user->id,
                'source_event_id' => Str::uuid()->toString(),
                'signal_type' => FeedbackSignal::SIGNAL_APPROVAL,
                'pattern_key' => $patternKey,
                'raw_context' => "Approval {$i}",
            ]);
        }

        // 3 rejections — 5 - (3 * 2) = -1 → clamped to 0
        for ($i = 0; $i < 3; $i++) {
            FeedbackSignal::withoutGlobalScope('user')->create([
                'id' => Str::uuid()->toString(),
                'user_id' => $user->id,
                'source_event_id' => Str::uuid()->toString(),
                'signal_type' => FeedbackSignal::SIGNAL_REJECTION,
                'pattern_key' => $patternKey,
                'raw_context' => "Rejection {$i}",
            ]);
        }

        $this->assertFalse($this->accumulator->shouldPropose($user->id, $patternKey));
        $this->assertEquals(0, $this->accumulator->getEffectiveCount($user->id, $patternKey));
    }

    /* -----------------------------------------------------------------
     * T017: Idempotence Tests
     * ----------------------------------------------------------------- */

    #[Test]
    public function idempotence_duplicate_signal_not_counted_twice(): void
    {
        $user = User::factory()->create();
        $patternKey = 'prefer_concise_responses';
        $sourceEventId = Str::uuid()->toString();

        // Create signal once
        FeedbackSignal::withoutGlobalScope('user')->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'source_event_id' => $sourceEventId,
            'signal_type' => FeedbackSignal::SIGNAL_APPROVAL,
            'pattern_key' => $patternKey,
            'raw_context' => 'Test signal',
        ]);

        $count1 = $this->accumulator->getEffectiveCount($user->id, $patternKey);
        $this->assertEquals(1, $count1);

        // Attempt to create duplicate (same source_event_id) — should fail unique constraint
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        FeedbackSignal::withoutGlobalScope('user')->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'source_event_id' => $sourceEventId,
            'signal_type' => FeedbackSignal::SIGNAL_APPROVAL,
            'pattern_key' => $patternKey,
            'raw_context' => 'Duplicate signal',
        ]);
    }

    #[Test]
    public function idempotence_persist_listener_skips_existing_signal(): void
    {
        $user = User::factory()->create();
        $sourceEventId = Str::uuid()->toString();
        $patternKey = 'prefer_concise_responses';

        // Pre-create the signal
        FeedbackSignal::withoutGlobalScope('user')->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'source_event_id' => $sourceEventId,
            'signal_type' => FeedbackSignal::SIGNAL_APPROVAL,
            'pattern_key' => $patternKey,
            'raw_context' => 'Original signal',
        ]);

        // Dispatch FeedbackReceived event with same source_event_id
        // The listener should detect existing signal and skip
        $event = new \ClarionApp\LlmClient\Events\FeedbackReceived(
            $user->id,
            $sourceEventId,
            FeedbackSignal::SIGNAL_APPROVAL,
            'Duplicate event',
            null,
            $patternKey
        );

        $listener = new \ClarionApp\LlmClient\Listeners\PersistFeedbackSignal();
        $listener->handle($event);

        // Still only 1 signal in DB
        $total = FeedbackSignal::withoutGlobalScope('user')
            ->where('user_id', $user->id)
            ->where('source_event_id', $sourceEventId)
            ->count();
        $this->assertEquals(1, $total);
    }

    /* -----------------------------------------------------------------
     * Opt-out Tests (shared with US4)
     * ----------------------------------------------------------------- */

    #[Test]
    public function opted_out_pattern_skipped_during_threshold_check(): void
    {
        $user = User::factory()->create();
        $patternKey = 'prefer_concise_responses';

        // Create 5+ signals
        for ($i = 0; $i < 6; $i++) {
            FeedbackSignal::withoutGlobalScope('user')->create([
                'id' => Str::uuid()->toString(),
                'user_id' => $user->id,
                'source_event_id' => Str::uuid()->toString(),
                'signal_type' => FeedbackSignal::SIGNAL_APPROVAL,
                'pattern_key' => $patternKey,
                'raw_context' => "Signal {$i}",
            ]);
        }

        // Without opt-out, should propose
        $this->assertTrue($this->accumulator->shouldPropose($user->id, $patternKey));

        // Now opt-out
        FeedbackOptOut::optOut($user->id, $patternKey);

        // After opt-out, should NOT propose
        $this->assertFalse($this->accumulator->shouldPropose($user->id, $patternKey));
        $this->assertTrue($this->accumulator->isOptedOut($user->id, $patternKey));
    }

    /* -----------------------------------------------------------------
     * Retirement Tests (shared with US3)
     * ----------------------------------------------------------------- */

    #[Test]
    public function retirement_effective_count_zero_should_retire(): void
    {
        $user = User::factory()->create();
        $patternKey = 'prefer_concise_responses';

        // 2 approvals only
        for ($i = 0; $i < 2; $i++) {
            FeedbackSignal::withoutGlobalScope('user')->create([
                'id' => Str::uuid()->toString(),
                'user_id' => $user->id,
                'source_event_id' => Str::uuid()->toString(),
                'signal_type' => FeedbackSignal::SIGNAL_APPROVAL,
                'pattern_key' => $patternKey,
                'raw_context' => "Approval {$i}",
            ]);
        }

        // 2 rejections → effective count = 2 - (2 * 2) = -2 → clamped to 0
        for ($i = 0; $i < 2; $i++) {
            FeedbackSignal::withoutGlobalScope('user')->create([
                'id' => Str::uuid()->toString(),
                'user_id' => $user->id,
                'source_event_id' => Str::uuid()->toString(),
                'signal_type' => FeedbackSignal::SIGNAL_REJECTION,
                'pattern_key' => $patternKey,
                'raw_context' => "Rejection {$i}",
            ]);
        }

        $this->assertTrue($this->accumulator->shouldRetire($user->id, $patternKey));
    }

    /* -----------------------------------------------------------------
     * Per-user Isolation Tests
     * ----------------------------------------------------------------- */

    #[Test]
    public function per_user_isolation_signals_not_shared_between_users(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $patternKey = 'prefer_concise_responses';

        // User1 has 5 signals
        for ($i = 0; $i < 5; $i++) {
            FeedbackSignal::withoutGlobalScope('user')->create([
                'id' => Str::uuid()->toString(),
                'user_id' => $user1->id,
                'source_event_id' => Str::uuid()->toString(),
                'signal_type' => FeedbackSignal::SIGNAL_APPROVAL,
                'pattern_key' => $patternKey,
                'raw_context' => "User1 signal {$i}",
            ]);
        }

        // User2 has no signals
        $this->assertTrue($this->accumulator->shouldPropose($user1->id, $patternKey));
        $this->assertFalse($this->accumulator->shouldPropose($user2->id, $patternKey));
    }

    /* -----------------------------------------------------------------
     * Single Signal Safety (US5)
     * ----------------------------------------------------------------- */

    #[Test]
    public function single_signal_does_not_trigger_proposal(): void
    {
        $user = User::factory()->create();
        $patternKey = 'prefer_concise_responses';

        FeedbackSignal::withoutGlobalScope('user')->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'source_event_id' => Str::uuid()->toString(),
            'signal_type' => FeedbackSignal::SIGNAL_APPROVAL,
            'pattern_key' => $patternKey,
            'raw_context' => 'Single signal',
        ]);

        $this->assertFalse($this->accumulator->shouldPropose($user->id, $patternKey));
        $this->assertEquals(1, $this->accumulator->getEffectiveCount($user->id, $patternKey));
    }

    /* -----------------------------------------------------------------
     * GetReadyPatterns Tests
     * ----------------------------------------------------------------- */

    #[Test]
    public function get_ready_patterns_returns_only_threshold_patterns(): void
    {
        $user = User::factory()->create();

        // Pattern A: 5 signals (should be ready)
        for ($i = 0; $i < 5; $i++) {
            FeedbackSignal::withoutGlobalScope('user')->create([
                'id' => Str::uuid()->toString(),
                'user_id' => $user->id,
                'source_event_id' => Str::uuid()->toString(),
                'signal_type' => FeedbackSignal::SIGNAL_APPROVAL,
                'pattern_key' => 'pattern_a',
                'raw_context' => "A signal {$i}",
            ]);
        }

        // Pattern B: 3 signals (should NOT be ready)
        for ($i = 0; $i < 3; $i++) {
            FeedbackSignal::withoutGlobalScope('user')->create([
                'id' => Str::uuid()->toString(),
                'user_id' => $user->id,
                'source_event_id' => Str::uuid()->toString(),
                'signal_type' => FeedbackSignal::SIGNAL_APPROVAL,
                'pattern_key' => 'pattern_b',
                'raw_context' => "B signal {$i}",
            ]);
        }

        $ready = $this->accumulator->getReadyPatterns($user->id);
        $this->assertContains('pattern_a', $ready);
        $this->assertNotContains('pattern_b', $ready);
    }
}
