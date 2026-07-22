<?php

namespace Tests\Integration\Harness;

use ClarionApp\LlmClient\Contracts\MemoryScope;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\EpisodicMemory;
use ClarionApp\LlmClient\Models\MemoryEntry;
use Carbon\CarbonInterface;

/**
 * T018: SessionArtifacts — end-state artifacts for turn/conversation boundary.
 *
 * Used for the two-sided claim: session-scoped entries present after later
 * turns and absent after the session ends.
 */
class SessionArtifacts
{
    public function __construct(
        public readonly string $conversationId,
    ) {
    }

    /**
     * Session-scoped MemoryEntry rows for the conversation.
     *
     * Reads MemoryScope::SHORT_TERM ('short_term') — the product's actual
     * enum value for session-scoped memory (Contracts\MemoryScope). This
     * previously queried scope = 'session', a value MemoryEntry::scope never
     * takes (the enum only has scratch|short_term|long_term — see
     * src/Contracts/MemoryScope.php), so this method always returned an
     * empty collection regardless of what had been written. No scenario had
     * exercised it before Phase 7 (060) — a genuine harness bug, not a
     * production one.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function shortTermEntries(): \Illuminate\Database\Eloquent\Collection
    {
        return MemoryEntry::query()
            ->where('conversation_id', $this->conversationId)
            ->where('scope', MemoryScope::SHORT_TERM->value)
            ->get();
    }

    /**
     * Scratch-scope rows, optionally for one turn.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function scratchEntries(?string $turnId = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = MemoryEntry::query()
            ->where('conversation_id', $this->conversationId)
            ->where('scope', MemoryScope::SCRATCH->value);

        if ($turnId !== null) {
            $query->where('turn_id', $turnId);
        }

        return $query->get();
    }

    /**
     * Get the EpisodicMemory for the conversation.
     */
    public function episodicRecord(): ?EpisodicMemory
    {
        return EpisodicMemory::query()
            ->where('conversation_id', $this->conversationId)
            ->whereNull('deleted_at')
            ->first();
    }

    /**
     * Get the ended_at timestamp from the conversation row.
     */
    public function endedAt(): ?CarbonInterface
    {
        $conv = Conversation::query()->where('id', $this->conversationId)->first();
        return $conv?->ended_at;
    }
}
