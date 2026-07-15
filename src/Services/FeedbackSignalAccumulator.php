<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Contracts\FeedbackSignalAccumulator as FeedbackSignalAccumulatorContract;
use ClarionApp\LlmClient\Models\FeedbackOptOut;
use ClarionApp\LlmClient\Models\FeedbackSignal;

/**
 * FeedbackSignalAccumulator service.
 *
 * Tracks feedback signals per pattern, applies contradiction decay,
 * and determines when a pattern reaches the promotion threshold.
 *
 * Uses an in-memory cache backed by the database for efficient threshold checks.
 * State is rebuilt from the database on each request (stateless service).
 */
class FeedbackSignalAccumulator implements FeedbackSignalAccumulatorContract
{
    /**
     * Cache of effective counts per user/pattern for the current request.
     *
     * @var array<string, int>
     */
    protected array $effectiveCountCache = [];

    /**
     * Create a new accumulator instance.
     */
    public function __construct(
        protected int $promotionThreshold = 5,
        protected int $contradictionDecay = 2,
    ) {
        $this->promotionThreshold = config('llm-client.learning_preferences.promotion_threshold', 5);
        $this->contradictionDecay = config('llm-client.learning_preferences.contradiction_decay', 2);
    }

    /**
     * Record a new feedback signal for a user and pattern.
     *
     * @return bool True if this signal caused the pattern to reach promotion threshold
     */
    public function recordSignal(string $userId, string $patternKey, string $signalType): bool
    {
        // Don't propose if opted out
        if ($this->isOptedOut($userId, $patternKey)) {
            return false;
        }

        $cacheKey = "{$userId}:{$patternKey}";

        // Get current effective count
        $currentCount = $this->getEffectiveCount($userId, $patternKey);

        // Check if this signal contradicts existing signals
        $isContradictory = $this->isContradictory($userId, $patternKey, $signalType);

        if ($isContradictory) {
            // Apply contradiction decay
            $newCount = $currentCount - $this->contradictionDecay;
        } else {
            // Increment count
            $newCount = $currentCount + 1;
        }

        $this->effectiveCountCache[$cacheKey] = $newCount;

        // Return true if we just crossed the threshold
        return $newCount >= $this->promotionThreshold && $currentCount < $this->promotionThreshold;
    }

    /**
     * Get the effective signal count for a user and pattern.
     */
    public function getEffectiveCount(string $userId, string $patternKey): int
    {
        $cacheKey = "{$userId}:{$patternKey}";

        if (isset($this->effectiveCountCache[$cacheKey])) {
            return $this->effectiveCountCache[$cacheKey];
        }

        // Calculate effective count from database signals
        $signals = FeedbackSignal::getSignalsForPattern($userId, $patternKey);

        if ($signals->isEmpty()) {
            // Check processed signals too for existing preferences
            $allSignals = FeedbackSignal::withoutGlobalScope('user')
                ->where('user_id', $userId)
                ->where('pattern_key', $patternKey)
                ->get();

            return $this->calculateEffectiveCount($allSignals);
        }

        return $this->calculateEffectiveCount($signals);
    }

    /**
     * Check if a pattern has reached the promotion threshold.
     */
    public function shouldPropose(string $userId, string $patternKey): bool
    {
        if ($this->isOptedOut($userId, $patternKey)) {
            return false;
        }

        return $this->getEffectiveCount($userId, $patternKey) >= $this->promotionThreshold;
    }

    /**
     * Check if a pattern should be retired (effective count <= 0).
     */
    public function shouldRetire(string $userId, string $patternKey): bool
    {
        return $this->getEffectiveCount($userId, $patternKey) <= 0;
    }

    /**
     * Check if a pattern is opt-outed for a user.
     */
    public function isOptedOut(string $userId, string $patternKey): bool
    {
        return FeedbackOptOut::isOptedOut($userId, $patternKey);
    }

    /**
     * Get all patterns that should be proposed for a user.
     */
    public function getReadyPatterns(string $userId): array
    {
        $patternKeys = FeedbackSignal::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->whereNotNull('pattern_key')
            ->whereNull('processed_at')
            ->distinct()
            ->pluck('pattern_key')
            ->toArray();

        $ready = [];
        foreach ($patternKeys as $patternKey) {
            if ($this->shouldPropose($userId, $patternKey)) {
                $ready[] = $patternKey;
            }
        }

        return $ready;
    }

    /**
     * Calculate effective count from a collection of signals.
     */
    protected function calculateEffectiveCount($signals): int
    {
        if ($signals->isEmpty()) {
            return 0;
        }

        // Count signals by type
        $approvals = $signals->where('signal_type', FeedbackSignal::SIGNAL_APPROVAL)->count();
        $rejections = $signals->where('signal_type', FeedbackSignal::SIGNAL_REJECTION)->count();
        $corrections = $signals->where('signal_type', FeedbackSignal::SIGNAL_CORRECTION)->count();

        // Approvals count positively, rejections count negatively with decay
        $effectiveCount = $approvals + $corrections - ($rejections * $this->contradictionDecay);

        return max(0, $effectiveCount);
    }

    /**
     * Check if a signal type contradicts existing signals for a pattern.
     */
    protected function isContradictory(string $userId, string $patternKey, string $signalType): bool
    {
        // Get existing signals for this pattern
        $existingSignals = FeedbackSignal::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->where('pattern_key', $patternKey)
            ->get();

        if ($existingSignals->isEmpty()) {
            return false;
        }

        // A rejection contradicts approvals/corrections
        if ($signalType === FeedbackSignal::SIGNAL_REJECTION) {
            $positiveSignals = $existingSignals->whereIn('signal_type', [
                FeedbackSignal::SIGNAL_APPROVAL,
                FeedbackSignal::SIGNAL_CORRECTION,
            ]);

            return $positiveSignals->count() > 0;
        }

        // An approval contradicts rejections
        if ($signalType === FeedbackSignal::SIGNAL_APPROVAL) {
            $negativeSignals = $existingSignals->where('signal_type', FeedbackSignal::SIGNAL_REJECTION);

            return $negativeSignals->count() > $existingSignals->where('signal_type', FeedbackSignal::SIGNAL_APPROVAL)->count();
        }

        return false;
    }
}
