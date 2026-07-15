<?php

namespace ClarionApp\LlmClient\Contracts;

/**
 * Contract for feedback signal accumulation and threshold management.
 *
 * Responsible for tracking feedback signals per pattern, applying
 * contradiction decay, and determining when a pattern reaches the
 * promotion threshold for user proposal.
 */
interface FeedbackSignalAccumulator
{
    /**
     * Record a new feedback signal for a user and pattern.
     *
     * Applies contradiction decay if the signal contradicts an existing
     * proposed or confirmed preference for the same pattern.
     *
     * @param string $userId Owning user
     * @param string $patternKey Normalized pattern identifier
     * @param string $signalType Signal type: approval, rejection, or correction
     * @return bool True if this signal caused the pattern to reach promotion threshold
     */
    public function recordSignal(string $userId, string $patternKey, string $signalType): bool;

    /**
     * Get the effective signal count for a user and pattern.
     *
     * Accounts for contradiction decay in the count.
     *
     * @param string $userId Owning user
     * @param string $patternKey Normalized pattern identifier
     * @return int Effective count (may be negative after contradiction)
     */
    public function getEffectiveCount(string $userId, string $patternKey): int;

    /**
     * Check if a pattern has reached the promotion threshold.
     *
     * @param string $userId Owning user
     * @param string $patternKey Normalized pattern identifier
     * @return bool True if effective count meets or exceeds threshold
     */
    public function shouldPropose(string $userId, string $patternKey): bool;

    /**
     * Check if a pattern should be retired (effective count <= 0).
     *
     * @param string $userId Owning user
     * @param string $patternKey Normalized pattern identifier
     * @return bool True if the pattern should be retired
     */
    public function shouldRetire(string $userId, string $patternKey): bool;

    /**
     * Check if a pattern is opt-outed for a user.
     *
     * @param string $userId Owning user
     * @param string $patternKey Normalized pattern identifier
     * @return bool True if the user declined this pattern before
     */
    public function isOptedOut(string $userId, string $patternKey): bool;

    /**
     * Get all patterns that should be proposed for a user.
     *
     * @param string $userId Owning user
     * @return array Array of pattern keys ready for proposal
     */
    public function getReadyPatterns(string $userId): array;
}
