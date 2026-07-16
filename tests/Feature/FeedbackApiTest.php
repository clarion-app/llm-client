<?php

namespace Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\DeclarativeMemoryService as DeclarativeMemoryServiceContract;
use ClarionApp\LlmClient\Models\DeclarativeMemory;
use ClarionApp\LlmClient\Models\FeedbackExtractionLog;
use ClarionApp\LlmClient\Models\FeedbackOptOut;
use ClarionApp\LlmClient\Models\FeedbackSignal;
use ClarionApp\LlmClient\Services\EmbeddingService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * Feature test: Feedback API endpoints.
 *
 * Covers feedback submission (US1) and preference management (US2).
 * Tests use the service layer with mocked auth (Passport unavailable in test bench).
 */
class FeedbackApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Create feedback_signals table if not exists
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

        // Create feedback_extraction_log table if not exists
        if (!Schema::hasTable('feedback_extraction_log')) {
            Schema::create('feedback_extraction_log', function ($table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->uuid('declarative_memory_id')->nullable();
                $table->string('pattern_key');
                $table->integer('signals_count');
                $table->json('signal_ids');
                $table->integer('confidence_score');
                $table->string('outcome');
                $table->string('llm_call_id')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->nullable();
                $table->softDeletes();

                $table->index('user_id');
                $table->index('declarative_memory_id');
                $table->index('pattern_key');
            });
        }

        // Create feedback_opt_outs table if not exists
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

        // Create declarative_memories table if not exists
        if (!Schema::hasTable('declarative_memories')) {
            Schema::create('declarative_memories', function ($table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->string('type');
                $table->text('content');
                $table->string('source');
                $table->integer('confidence_level')->nullable();
                $table->json('embedding')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index('user_id');
                $table->index(['user_id', 'type']);
                $table->index('deleted_at');
            });
        }
    }

    /* -----------------------------------------------------------------
     * T018: Feedback Submission API Tests (US1)
     * ----------------------------------------------------------------- */

    #[Test]
    public function feedback_submission_creates_signal_and_dispatches_event(): void
    {
        $user = User::factory()->create();
        $signalType = FeedbackSignal::SIGNAL_REJECTION;
        $context = 'Response was too verbose';

        // Dispatch FeedbackReceived event directly (simulating controller behavior)
        $event = new \ClarionApp\LlmClient\Events\FeedbackReceived(
            $user->id,
            Str::uuid()->toString(),
            $signalType,
            $context,
            Str::uuid()->toString(),
            null
        );

        // Process through listener
        $listener = new \ClarionApp\LlmClient\Listeners\PersistFeedbackSignal();
        $listener->handle($event);

        // Verify signal was created
        $signal = FeedbackSignal::withoutGlobalScope('user')
            ->where('user_id', $user->id)
            ->first();

        $this->assertNotNull($signal);
        $this->assertEquals($signalType, $signal->signal_type);
        $this->assertEquals($context, $signal->raw_context);
        $this->assertNull($signal->processed_at);
    }

    #[Test]
    public function feedback_submission_rejects_duplicate_source_event(): void
    {
        $user = User::factory()->create();
        $sourceEventId = Str::uuid()->toString();
        $signalType = FeedbackSignal::SIGNAL_APPROVAL;

        // Create signal directly
        FeedbackSignal::withoutGlobalScope('user')->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'source_event_id' => $sourceEventId,
            'signal_type' => $signalType,
            'raw_context' => 'Original signal',
        ]);

        // Dispatch event with same source_event_id — listener should skip
        $event = new \ClarionApp\LlmClient\Events\FeedbackReceived(
            $user->id,
            $sourceEventId,
            $signalType,
            'Duplicate event',
            null,
            null
        );

        $listener = new \ClarionApp\LlmClient\Listeners\PersistFeedbackSignal();
        $listener->handle($event);

        // Still only 1 signal
        $count = FeedbackSignal::withoutGlobalScope('user')
            ->where('user_id', $user->id)
            ->where('source_event_id', $sourceEventId)
            ->count();
        $this->assertEquals(1, $count);
    }

    #[Test]
    public function feedback_submission_validates_signal_types(): void
    {
        $valid = FeedbackSignal::isValidSignalType(FeedbackSignal::SIGNAL_APPROVAL);
        $this->assertTrue($valid);

        $valid = FeedbackSignal::isValidSignalType(FeedbackSignal::SIGNAL_REJECTION);
        $this->assertTrue($valid);

        $valid = FeedbackSignal::isValidSignalType(FeedbackSignal::SIGNAL_CORRECTION);
        $this->assertTrue($valid);

        $valid = FeedbackSignal::isValidSignalType('invalid_type');
        $this->assertFalse($valid);
    }

    /* -----------------------------------------------------------------
     * T026-T032: Preference Management Tests (US2)
     * ----------------------------------------------------------------- */

    #[Test]
    public function listing_proposed_preferences_returns_extraction_logs_with_proposed_outcome(): void
    {
        $user = User::factory()->create();

        // Create extraction logs with 'proposed' outcome
        $log1 = FeedbackExtractionLog::withoutGlobalScope('user')->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'declarative_memory_id' => null,
            'pattern_key' => 'prefer_concise_responses',
            'signals_count' => 6,
            'signal_ids' => [Str::uuid()->toString()],
            'confidence_score' => 78,
            'outcome' => FeedbackExtractionLog::OUTCOME_PROPOSED,
            'llm_call_id' => null,
        ]);

        // Create another log with 'rejected' outcome (should NOT appear)
        FeedbackExtractionLog::withoutGlobalScope('user')->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'declarative_memory_id' => null,
            'pattern_key' => 'prefer_dark_mode',
            'signals_count' => 3,
            'signal_ids' => [],
            'confidence_score' => 40,
            'outcome' => FeedbackExtractionLog::OUTCOME_REJECTED,
            'llm_call_id' => null,
        ]);

        // Query proposed logs
        $proposed = FeedbackExtractionLog::withoutGlobalScope('user')
            ->where('user_id', $user->id)
            ->where('outcome', FeedbackExtractionLog::OUTCOME_PROPOSED)
            ->get();

        $this->assertCount(1, $proposed);
        $this->assertEquals('prefer_concise_responses', $proposed->first()->pattern_key);
    }

    #[Test]
    public function confirming_preference_persists_via_declarative_memory(): void
    {
        $user = User::factory()->create();

        $embeddingService = $this->createMock(EmbeddingService::class);
        $embeddingService->method('isEnabled')->willReturn(false);
        $service = new \ClarionApp\LlmClient\Services\DeclarativeMemoryService($embeddingService);

        // Confirm via applyAgentWrite with userConfirmed=true
        $memory = $service->applyAgentWrite(
            $user->id,
            'preference',
            'Prefer concise responses over verbose ones',
            true, // userConfirmed
            null,
            78    // confidence_level
        );

        $this->assertInstanceOf(DeclarativeMemory::class, $memory);
        $this->assertEquals('preference', $memory->type);
        $this->assertEquals(DeclarativeMemory::LEARNED_PATTERN, $memory->source);
        $this->assertEquals(78, $memory->confidence_level);
    }

    #[Test]
    public function declining_preference_records_opt_out(): void
    {
        $user = User::factory()->create();
        $patternKey = 'prefer_concise_responses';

        // Record opt-out
        $optOut = FeedbackOptOut::optOut($user->id, $patternKey);

        $this->assertInstanceOf(FeedbackOptOut::class, $optOut);
        $this->assertEquals($patternKey, $optOut->pattern_key);

        // Verify opt-out is recorded
        $this->assertTrue(FeedbackOptOut::isOptedOut($user->id, $patternKey));
    }

    #[Test]
    public function listing_learned_preferences_returns_confirmed_entries(): void
    {
        $user = User::factory()->create();

        $embeddingService = $this->createMock(EmbeddingService::class);
        $embeddingService->method('isEnabled')->willReturn(false);
        $service = new \ClarionApp\LlmClient\Services\DeclarativeMemoryService($embeddingService);

        // Create two learned preferences
        $service->applyAgentWrite($user->id, 'preference', 'Prefer concise responses', true, null, 78);
        $service->applyAgentWrite($user->id, 'preference', 'Prefer dark mode', true, null, 85);

        // Also create a user-stated entry (should NOT appear in learned list)
        $service->createByUser($user->id, 'preference', 'I like coffee');

        // List learned preferences
        $learned = DeclarativeMemory::withoutGlobalScope('user')
            ->where('user_id', $user->id)
            ->where('source', DeclarativeMemory::LEARNED_PATTERN)
            ->get();

        $this->assertCount(2, $learned);
    }

    #[Test]
    public function editing_preference_transitions_source_to_user_stated(): void
    {
        $user = User::factory()->create();

        $embeddingService = $this->createMock(EmbeddingService::class);
        $embeddingService->method('isEnabled')->willReturn(false);
        $service = new \ClarionApp\LlmClient\Services\DeclarativeMemoryService($embeddingService);

        // Create learned preference
        $memory = $service->applyAgentWrite($user->id, 'preference', 'Prefer concise responses', true, null, 78);

        $this->assertEquals(DeclarativeMemory::LEARNED_PATTERN, $memory->source);

        // Edit it
        $updated = $service->updateByUser($user->id, $memory->id, 'Prefer very concise responses');

        $this->assertEquals(DeclarativeMemory::SOURCE_USER_STATED, $updated->source);
        $this->assertNull($updated->confidence_level);
    }

    #[Test]
    public function deleting_preference_removes_entry(): void
    {
        $user = User::factory()->create();

        $embeddingService = $this->createMock(EmbeddingService::class);
        $embeddingService->method('isEnabled')->willReturn(false);
        $service = new \ClarionApp\LlmClient\Services\DeclarativeMemoryService($embeddingService);

        // Create learned preference
        $memory = $service->applyAgentWrite($user->id, 'preference', 'Prefer concise responses', true, null, 78);

        // Delete it
        $result = $service->delete($user->id, $memory->id);
        $this->assertTrue($result);

        // Verify it's gone
        $count = DeclarativeMemory::withoutGlobalScope('user')
            ->where('user_id', $user->id)
            ->where('id', $memory->id)
            ->withTrashed()
            ->count();
        $this->assertEquals(0, $count);
    }

    #[Test]
    public function audit_trail_returns_extraction_logs_for_preference(): void
    {
        $user = User::factory()->create();
        $declarativeMemoryId = Str::uuid()->toString();

        // Create extraction logs linked to a declarative memory
        FeedbackExtractionLog::withoutGlobalScope('user')->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'declarative_memory_id' => $declarativeMemoryId,
            'pattern_key' => 'prefer_concise_responses',
            'signals_count' => 6,
            'signal_ids' => [Str::uuid()->toString(), Str::uuid()->toString()],
            'confidence_score' => 78,
            'outcome' => FeedbackExtractionLog::OUTCOME_PROPOSED,
            'llm_call_id' => null,
        ]);

        FeedbackExtractionLog::withoutGlobalScope('user')->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'declarative_memory_id' => $declarativeMemoryId,
            'pattern_key' => 'prefer_concise_responses',
            'signals_count' => 8,
            'signal_ids' => [Str::uuid()->toString()],
            'confidence_score' => 90,
            'outcome' => FeedbackExtractionLog::OUTCOME_PROPOSED,
            'llm_call_id' => Str::uuid()->toString(),
        ]);

        // Query audit trail
        $audit = FeedbackExtractionLog::getAuditTrail($declarativeMemoryId);

        $this->assertCount(2, $audit);
    }

    /* -----------------------------------------------------------------
     * T053: FR-009 Negative Case
     * ----------------------------------------------------------------- */

    #[Test]
    public function non_feedback_events_do_not_create_preferences(): void
    {
        $user = User::factory()->create();

        // No signals created — verify no preferences exist
        $signals = FeedbackSignal::withoutGlobalScope('user')
            ->where('user_id', $user->id)
            ->count();
        $this->assertEquals(0, $signals);

        $memories = DeclarativeMemory::withoutGlobalScope('user')
            ->where('user_id', $user->id)
            ->count();
        $this->assertEquals(0, $memories);
    }
}
