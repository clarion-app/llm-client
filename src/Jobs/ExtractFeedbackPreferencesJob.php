<?php

namespace ClarionApp\LlmClient\Jobs;

use ClarionApp\LlmClient\Contracts\DeclarativeMemoryService as DeclarativeMemoryServiceContract;
use ClarionApp\LlmClient\Contracts\FeedbackSignalAccumulator;
use ClarionApp\LlmClient\Events\PreferenceProposalEvent;
use ClarionApp\LlmClient\Models\FeedbackExtractionLog;
use ClarionApp\LlmClient\Models\FeedbackOptOut;
use ClarionApp\LlmClient\Models\FeedbackSignal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Queued job that extracts preference patterns from accumulated feedback signals.
 *
 * Loads pending signals, groups by pattern, checks thresholds via the accumulator,
 * and proposes preferences that meet the promotion threshold through the
 * DeclarativeMemory confirmation gate.
 *
 * Dispatched by PersistFeedbackSignal listener after each new signal is persisted.
 * Non-blocking, 120-second timeout (matching GenerateEpisodicMemoryJob).
 */
class ExtractFeedbackPreferencesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 120;

    /**
     * The number of times to attempt to process the job.
     * Set to 1 — no retry on failure; signals remain pending for next run.
     */
    public int $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly string $userId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        FeedbackSignalAccumulator $accumulator,
        DeclarativeMemoryServiceContract $declarativeMemoryService
    ): void {
        $batchSize = config('llm-client.learning_preferences.extraction_batch_size', 20);

        // Load pending signals for this user
        $pendingSignals = FeedbackSignal::getPendingForUser($this->userId, $batchSize);

        if ($pendingSignals->isEmpty()) {
            return;
        }

        // Group signals by pattern_key (skip signals without pattern_key for now)
        $grouped = $pendingSignals->filter(fn ($s) => $s->pattern_key !== null)
            ->groupBy('pattern_key');

        foreach ($grouped as $patternKey => $signals) {
            // Skip if user opted out of this pattern
            if (FeedbackOptOut::isOptedOut($this->userId, $patternKey)) {
                // Mark signals as processed (but don't propose)
                foreach ($signals as $signal) {
                    $signal->update(['processed_at' => now()]);
                }
                continue;
            }

            // Record each signal in the accumulator
            foreach ($signals as $signal) {
                $shouldPropose = $accumulator->recordSignal(
                    $this->userId,
                    $patternKey,
                    $signal->signal_type
                );
            }

            // Mark signals as processed
            $signalIds = $signals->pluck('id')->toArray();
            foreach ($signals as $signal) {
                $signal->update(['processed_at' => now()]);
            }

            // Check if pattern should be retired
            if ($accumulator->shouldRetire($this->userId, $patternKey)) {
                FeedbackExtractionLog::withoutGlobalScope('user')->create([
                    'id' => Str::uuid()->toString(),
                    'user_id' => $this->userId,
                    'declarative_memory_id' => null,
                    'pattern_key' => $patternKey,
                    'signals_count' => $signals->count(),
                    'signal_ids' => $signalIds,
                    'confidence_score' => 0,
                    'outcome' => FeedbackExtractionLog::OUTCOME_RETIRED,
                    'llm_call_id' => null,
                ]);
                continue;
            }

            // Check if pattern should be proposed
            if ($shouldPropose && $accumulator->shouldPropose($this->userId, $patternKey)) {
                // Build preference description from signal context
                $preferenceDescription = $this->buildPreferenceDescription($signals);
                $confidenceScore = min($signals->count() * 20, 100);

                try {
                    // Attempt to write via confirmation gate (will throw ConfirmationRequiredException)
                    $declarativeMemoryService->applyAgentWrite(
                        $this->userId,
                        'preference',
                        $preferenceDescription,
                        false, // userConfirmed = false → triggers proposal
                        null,
                        $confidenceScore
                    );
                } catch (\ClarionApp\LlmClient\Exceptions\ConfirmationRequiredException $e) {
                    // Expected — broadcast proposal to user
                    event(new PreferenceProposalEvent(
                        $this->userId,
                        $patternKey,
                        $preferenceDescription,
                        $confidenceScore,
                        $signals->count()
                    ));

                    // Log extraction
                    FeedbackExtractionLog::withoutGlobalScope('user')->create([
                        'id' => Str::uuid()->toString(),
                        'user_id' => $this->userId,
                        'declarative_memory_id' => null,
                        'pattern_key' => $patternKey,
                        'signals_count' => $signals->count(),
                        'signal_ids' => $signalIds,
                        'confidence_score' => $confidenceScore,
                        'outcome' => FeedbackExtractionLog::OUTCOME_PROPOSED,
                        'llm_call_id' => null,
                    ]);
                }
            }
        }

        // Purge old processed signals
        $retentionDays = config('llm-client.learning_preferences.signal_retention_days', 30);
        FeedbackSignal::withoutGlobalScope('user')
            ->where('user_id', $this->userId)
            ->whereNotNull('processed_at')
            ->where('processed_at', '<', now()->subDays($retentionDays))
            ->delete();
    }

    /**
     * Build a preference description from accumulated signals.
     */
    protected function buildPreferenceDescription($signals): string
    {
        // Use the raw context from the most recent signal as the description base
        $latestSignal = $signals->sortByDesc('created_at')->first();
        return $latestSignal->raw_context ?? 'Learned preference pattern';
    }
}
