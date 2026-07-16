<?php

namespace Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\DeclarativeMemoryService as DeclarativeMemoryServiceContract;
use ClarionApp\LlmClient\Events\PreferenceProposalEvent;
use ClarionApp\LlmClient\Jobs\ExtractFeedbackPreferencesJob;
use ClarionApp\LlmClient\Models\DeclarativeMemory;
use ClarionApp\LlmClient\Models\FeedbackExtractionLog;
use ClarionApp\LlmClient\Models\FeedbackOptOut;
use ClarionApp\LlmClient\Models\FeedbackSignal;
use ClarionApp\LlmClient\Services\EmbeddingService;
use ClarionApp\LlmClient\Services\FeedbackSignalAccumulator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * Feature test: ExtractFeedbackPreferencesJob integration.
 *
 * Covers extraction job processing, threshold promotion, contradiction handling,
 * opt-out enforcement, and signal purging.
 */
class ExtractFeedbackPreferencesJobTest extends TestCase
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
     * T019: Extraction Job Tests (US1)
     * ----------------------------------------------------------------- */

    #[Test]
    public function job_processes_signals_and_proposes_when_threshold_met(): void
    {
        $user = User::factory()->create();
        $patternKey = 'prefer_concise_responses';

        // Create 5 signals with pattern_key set
        for ($i = 0; $i < 5; $i++) {
            FeedbackSignal::withoutGlobalScope('user')->create([
                'id' => Str::uuid()->toString(),
                'user_id' => $user->id,
                'source_event_id' => Str::uuid()->toString(),
                'signal_type' => FeedbackSignal::SIGNAL_APPROVAL,
                'pattern_key' => $patternKey,
                'raw_context' => "Response was too verbose {$i}",
            ]);
        }

        // Mock DeclarativeMemoryService to track applyAgentWrite calls
        $mockService = $this->createMock(DeclarativeMemoryServiceContract::class);
        $mockService->method('applyAgentWrite')
            ->willThrowException(new \ClarionApp\LlmClient\Exceptions\ConfirmationRequiredException(
                'preference',
                'Test preference',
                null,
                100
            ));

        // Run job
        $job = new ExtractFeedbackPreferencesJob($user->id);
        $job->handle(
            new FeedbackSignalAccumulator(),
            $mockService
        );

        // Verify signals are now processed
        $pending = FeedbackSignal::withoutGlobalScope('user')
            ->where('user_id', $user->id)
            ->whereNull('processed_at')
            ->count();
        $this->assertEquals(0, $pending);

        // Verify extraction log was created with 'proposed' outcome
        $logs = FeedbackExtractionLog::withoutGlobalScope('user')
            ->where('user_id', $user->id)
            ->where('pattern_key', $patternKey)
            ->where('outcome', FeedbackExtractionLog::OUTCOME_PROPOSED)
            ->get();
        $this->assertCount(1, $logs);
        $this->assertEquals(5, $logs->first()->signals_count);
    }

    #[Test]
    public function job_does_not_propose_with_fewer_than_threshold_signals(): void
    {
        $user = User::factory()->create();
        $patternKey = 'prefer_concise_responses';

        // Create only 1 signal (below threshold of 5)
        // Note: recordSignal adds to DB count, so 1 signal in DB + 1 recordSignal = 2 effective (still < 5)
        FeedbackSignal::withoutGlobalScope('user')->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'source_event_id' => Str::uuid()->toString(),
            'signal_type' => FeedbackSignal::SIGNAL_APPROVAL,
            'pattern_key' => $patternKey,
            'raw_context' => 'Signal 0',
        ]);

        // Mock service — should NOT receive applyAgentWrite call
        $mockService = $this->createMock(DeclarativeMemoryServiceContract::class);
        $mockService->expects($this->never())
            ->method('applyAgentWrite');

        // Run job
        $job = new ExtractFeedbackPreferencesJob($user->id);
        $job->handle(
            new FeedbackSignalAccumulator(),
            $mockService
        );

        // Signal should still be processed (marked processed_at)
        $pending = FeedbackSignal::withoutGlobalScope('user')
            ->where('user_id', $user->id)
            ->whereNull('processed_at')
            ->count();
        $this->assertEquals(0, $pending);
    }

    /* -----------------------------------------------------------------
     * T042: Contradiction Handling (US3)
     * ----------------------------------------------------------------- */

    #[Test]
    public function job_detects_contradiction_and_logs_retired_outcome(): void
    {
        $user = User::factory()->create();
        $patternKey = 'prefer_concise_responses';

        // 2 approvals + 2 rejections → effective count = 2 - (2 * 2) = -2 → 0 (retired)
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

        $mockService = $this->createMock(DeclarativeMemoryServiceContract::class);
        $mockService->expects($this->never())
            ->method('applyAgentWrite');

        // Run job
        $job = new ExtractFeedbackPreferencesJob($user->id);
        $job->handle(
            new FeedbackSignalAccumulator(),
            $mockService
        );

        // Verify retired outcome logged
        $retiredLogs = FeedbackExtractionLog::withoutGlobalScope('user')
            ->where('user_id', $user->id)
            ->where('pattern_key', $patternKey)
            ->where('outcome', FeedbackExtractionLog::OUTCOME_RETIRED)
            ->get();
        $this->assertCount(1, $retiredLogs);
    }

    /* -----------------------------------------------------------------
     * T047: Opt-out Prevents Re-proposal (US4)
     * ----------------------------------------------------------------- */

    #[Test]
    public function job_skips_extraction_for_opt_outed_patterns(): void
    {
        $user = User::factory()->create();
        $patternKey = 'prefer_concise_responses';

        // Create opt-out first
        FeedbackOptOut::optOut($user->id, $patternKey);

        // Create 5+ signals (would normally trigger proposal)
        for ($i = 0; $i < 5; $i++) {
            FeedbackSignal::withoutGlobalScope('user')->create([
                'id' => Str::uuid()->toString(),
                'user_id' => $user->id,
                'source_event_id' => Str::uuid()->toString(),
                'signal_type' => FeedbackSignal::SIGNAL_APPROVAL,
                'pattern_key' => $patternKey,
                'raw_context' => "Signal {$i}",
            ]);
        }

        // Mock service — should NOT receive applyAgentWrite call
        $mockService = $this->createMock(DeclarativeMemoryServiceContract::class);
        $mockService->expects($this->never())
            ->method('applyAgentWrite');

        // Run job
        $job = new ExtractFeedbackPreferencesJob($user->id);
        $job->handle(
            new FeedbackSignalAccumulator(),
            $mockService
        );

        // Signals should be processed (marked processed_at)
        $pending = FeedbackSignal::withoutGlobalScope('user')
            ->where('user_id', $user->id)
            ->whereNull('processed_at')
            ->count();
        $this->assertEquals(0, $pending);

        // No extraction log with 'proposed' outcome
        $proposedLogs = FeedbackExtractionLog::withoutGlobalScope('user')
            ->where('user_id', $user->id)
            ->where('outcome', FeedbackExtractionLog::OUTCOME_PROPOSED)
            ->count();
        $this->assertEquals(0, $proposedLogs);
    }

    /* -----------------------------------------------------------------
     * Signal Purging
     * ----------------------------------------------------------------- */

    #[Test]
    public function job_purges_old_processed_signals(): void
    {
        $user = User::factory()->create();
        $patternKey = 'prefer_concise_responses';

        // Set short retention for testing
        config(['llm-client.learning_preferences.signal_retention_days' => 1]);

        // Create old processed signal (processed 2 days ago)
        FeedbackSignal::withoutGlobalScope('user')->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'source_event_id' => Str::uuid()->toString(),
            'signal_type' => FeedbackSignal::SIGNAL_APPROVAL,
            'pattern_key' => $patternKey,
            'raw_context' => 'Old signal',
            'processed_at' => now()->subDays(2),
        ]);

        // Create new pending signal
        FeedbackSignal::withoutGlobalScope('user')->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'source_event_id' => Str::uuid()->toString(),
            'signal_type' => FeedbackSignal::SIGNAL_APPROVAL,
            'pattern_key' => $patternKey,
            'raw_context' => 'New signal',
        ]);

        // Verify old signal exists before job run
        $this->assertEquals(2, FeedbackSignal::withoutGlobalScope('user')
            ->where('user_id', $user->id)->count());

        // Run job
        $mockService = $this->createMock(DeclarativeMemoryServiceContract::class);
        $job = new ExtractFeedbackPreferencesJob($user->id);
        $job->handle(
            new FeedbackSignalAccumulator(),
            $mockService
        );

        // Old signal should be purged
        $remaining = FeedbackSignal::withoutGlobalScope('user')
            ->where('user_id', $user->id)
            ->get();
        
        // Old processed signal should be gone, new signal should be processed
        $oldRemaining = $remaining->where('raw_context', 'Old signal')->count();
        $this->assertEquals(0, $oldRemaining);
    }
}
