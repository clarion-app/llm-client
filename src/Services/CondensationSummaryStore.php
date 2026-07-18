<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\ChunkSummary;
use ClarionApp\LlmClient\Models\CondensationState;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

class CondensationSummaryStore
{
    /** @var array<string, mixed> */
    private array $config;

    private bool $failureRecorded = false;

    /**
     * Message from the most recent condensation failure, so callers can surface *why*
     * condensation fell back instead of only that it did. Null when the last attempt
     * succeeded or none has run.
     */
    private ?string $lastError = null;

    /**
     * @param CacheRepository $cache Cache repository for distributed locks
     * @param array<string, mixed> $config The 'condensation' config block
     */
    public function __construct(
        private CacheRepository $cache,
        ?array $config = null
    ) {
        $this->config = $config ?? self::resolveConfig();
    }

    private static function resolveConfig(): array
    {
        try {
            return function_exists('config') ? config('llm-client.condensation', []) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Get cached summary if present and source_hash matches.
     */
    public function get(string $conversationId, int $chunkIndex, string $sourceHash): ?ChunkSummary
    {
        $summary = ChunkSummary::where('conversation_id', $conversationId)
            ->where('chunk_index', $chunkIndex)
            ->first();

        if (!$summary) {
            return null;
        }

        // Stale hash → treat as miss
        if ($summary->source_hash !== $sourceHash) {
            return null;
        }

        return $summary;
    }

    /**
     * Serialized per (conversation, chunkIndex): only ONE worker runs $produce for a given chunk.
     *
     * @param callable(): array{summary: array, summary_tokens?: int, usage?: array} $produce
     * @return ChunkSummary|null
     */
    public function remember(string $conversationId, int $chunkIndex, string $sourceHash, callable $produce): ?ChunkSummary
    {
        // Check if already cached with matching hash
        $existing = $this->get($conversationId, $chunkIndex, $sourceHash);
        if ($existing) {
            return $existing;
        }

        $this->lastError = null;

        $lockKey = "condense:{$conversationId}:{$chunkIndex}";
        $lock = $this->cache->lock($lockKey, 30);

        try {
            $lock->block(5);
        } catch (LockTimeoutException) {
            $this->lastError = 'Condensation lock timeout';
            return null;
        }

        try {
            // Double-check after acquiring lock
            $existing = $this->get($conversationId, $chunkIndex, $sourceHash);
            if ($existing) {
                return $existing;
            }

            // Run the produce callable
            $result = $produce();

            // Extract values from result
            $summaryData = $result['summary'] ?? [];
            $summaryTokens = $result['summary_tokens'] ?? null;
            $usage = $result['usage'] ?? null;

            // Persist via firstOrCreate (atomic unique insert)
            $summary = ChunkSummary::firstOrCreate(
                [
                    'conversation_id' => $conversationId,
                    'chunk_index' => $chunkIndex,
                ],
                [
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'source_hash' => $sourceHash,
                    'source_message_count' => 0,
                    'summary' => $summaryData,
                    'summary_tokens' => $summaryTokens,
                    'condensation_model' => $result['condensation_model'] ?? null,
                    'condensation_provider' => $result['condensation_provider'] ?? null,
                ]
            );

            // Update fields that might not be in the where clause
            if (!$summary->wasRecentlyCreated) {
                $summary->update([
                    'source_hash' => $sourceHash,
                    'summary' => $summaryData,
                    'summary_tokens' => $summaryTokens,
                ]);
            }

            return $summary;
        } catch (\Throwable $e) {
            $this->failureRecorded = true;
            $this->lastError = $e->getMessage();
            $this->recordFailure($conversationId);
            return null;
        } finally {
            $lock->release();
        }
    }

    /**
     * Check whether the conversation is currently in cooldown.
     */
    public function inCooldown(string $conversationId): bool
    {
        $state = CondensationState::firstOrCreate(
            ['conversation_id' => $conversationId],
            ['id' => (string) \Illuminate\Support\Str::uuid()]
        );

        if ($state->cooldown_until === null) {
            return false;
        }

        return now()->lt($state->cooldown_until);
    }

    /**
     * Record a failure, incrementing consecutive_failures and tripping cooldown at threshold.
     */
    public function recordFailure(string $conversationId): void
    {
        $this->failureRecorded = true;

        $state = CondensationState::firstOrCreate(
            ['conversation_id' => $conversationId],
            ['id' => (string) \Illuminate\Support\Str::uuid()]
        );

        $state->consecutive_failures = ($state->consecutive_failures ?? 0) + 1;

        $threshold = (int) ($this->config['failure_threshold'] ?? 3);
        $cooldownSeconds = (int) ($this->config['cooldown_seconds'] ?? 300);

        if ($state->consecutive_failures >= $threshold) {
            $state->cooldown_until = now()->addSeconds($cooldownSeconds);
        }

        $state->save();
    }

    /**
     * Record a success, resetting failures and clearing cooldown.
     */
    public function recordSuccess(string $conversationId): void
    {
        $state = CondensationState::firstOrCreate(
            ['conversation_id' => $conversationId],
            ['id' => (string) \Illuminate\Support\Str::uuid()]
        );

        $state->consecutive_failures = 0;
        $state->cooldown_until = null;
        $state->save();
    }

    /**
     * Helper for tests to check if recordFailure was called during remember().
     */
    public function recordFailureWasCalled(): bool
    {
        return $this->failureRecorded;
    }

    /**
     * Message from the most recent failed remember() call, or null if it succeeded.
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }
}
