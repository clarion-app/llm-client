<?php

namespace Tests\Integration\Harness;

use ClarionApp\LlmClient\Models\ContextManagementRecord;
use ClarionApp\LlmClient\Models\ChunkSummary;
use ClarionApp\LlmClient\Models\EpisodicMemory;

/**
 * T017: BoundaryWitness — evidence that a mechanism ran (FR-012).
 *
 * Reads only persisted product artifacts. A failed witness fails as
 * "inconclusive" with observed counts — distinguishable from a property failure.
 */
class BoundaryWitness
{
    public function __construct(
        public readonly string $conversationId,
    ) {
    }

    /**
     * Check if context management acted at least once.
     *
     * ≥1 ContextManagementRecord with tokens_after < tokens_before.
     */
    public function contextManagementActed(): bool
    {
        $count = ContextManagementRecord::query()
            ->where('conversation_id', $this->conversationId)
            ->whereColumn('tokens_after', '<', 'tokens_before')
            ->count();
        return $count >= 1;
    }

    /**
     * Assert context management acted — fails as "inconclusive" if not.
     */
    public function assertContextManagementActed(): void
    {
        $count = ContextManagementRecord::query()
            ->where('conversation_id', $this->conversationId)
            ->whereColumn('tokens_after', '<', 'tokens_before')
            ->count();

        if ($count < 1) {
            throw new \RuntimeException(
                "INCONCLUSIVE: context management did not act. " .
                "Observed {$count} ContextManagementRecord(s) with tokens_after < tokens_before " .
                "for conversation {$this->conversationId}."
            );
        }
    }

    /**
     * Check if context management acted at least n times (distinct attempt groups).
     */
    public function contextManagementActedAtLeast(int $n): bool
    {
        $count = ContextManagementRecord::query()
            ->where('conversation_id', $this->conversationId)
            ->whereColumn('tokens_after', '<', 'tokens_before')
            ->distinct('attempt_group_id')
            ->count('attempt_group_id');
        return $count >= $n;
    }

    /**
     * Assert context management acted at least n times — fails as "inconclusive" if not.
     */
    public function assertContextManagementActedAtLeast(int $n): void
    {
        $count = ContextManagementRecord::query()
            ->where('conversation_id', $this->conversationId)
            ->whereColumn('tokens_after', '<', 'tokens_before')
            ->distinct('attempt_group_id')
            ->count('attempt_group_id');

        if ($count < $n) {
            throw new \RuntimeException(
                "INCONCLUSIVE: context management acted {$count} time(s), expected at least {$n}. " .
                "Conversation {$this->conversationId}."
            );
        }
    }

    /**
     * Check if context management acted on an already-reduced history.
     *
     * A record whose tokens_before is below an earlier record's tokens_before peak.
     */
    public function actedOnAlreadyReducedHistory(): bool
    {
        $records = ContextManagementRecord::query()
            ->where('conversation_id', $this->conversationId)
            ->whereColumn('tokens_after', '<', 'tokens_before')
            ->orderBy('created_at')
            ->get();

        if ($records->count() < 2) {
            return false;
        }

        $peakBefore = 0;
        foreach ($records as $record) {
            $before = (int) $record->tokens_before;
            if ($before > 0 && $before < $peakBefore) {
                return true;
            }
            $peakBefore = max($peakBefore, $before);
        }

        return false;
    }

    /**
     * Check if condensation ran (≥1 ChunkSummary for the conversation).
     */
    public function condensationRan(): bool
    {
        $count = ChunkSummary::query()
            ->where('conversation_id', $this->conversationId)
            ->count();
        return $count >= 1;
    }

    /**
     * Check if an EpisodicMemory row exists for the conversation.
     */
    public function recordCaptured(): bool
    {
        $count = EpisodicMemory::query()
            ->where('conversation_id', $this->conversationId)
            ->whereNull('deleted_at')
            ->count();
        return $count >= 1;
    }

    /**
     * Check if the episodic record was regenerated (word_count or updated_at moved).
     */
    public function recordRegenerated(EpisodicMemory $before): bool
    {
        $current = EpisodicMemory::query()
            ->where('conversation_id', $this->conversationId)
            ->whereNull('deleted_at')
            ->first();

        if ($current === null) {
            return false;
        }

        return $current->word_count !== $before->word_count
            || $current->updated_at->gt($before->updated_at);
    }
}
