<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\ValueObjects\ConversationWorkDecision;

/**
 * The sole decision authority for whether one more unit of agent-initiated
 * work — a tool-call execution or a schema-validation retry — may proceed
 * within a conversation's current window.
 *
 * This is the only class in src/ that compares a Cache-backed work-unit
 * count to a configured max_work_units. ConversationWorkCounter never makes
 * an admission decision of its own; it only returns a raw reading.
 *
 * Unlike RateLimitGate, this class exposes no admit() and carries no
 * per-instance "already evaluated" memo: every one of the four in-loop call
 * sites this gate serves is a genuinely distinct unit of work that must be
 * counted, not the same unit of work reachable two ways within one request.
 * Every call site interprets the returned ConversationWorkDecision with a
 * plain conditional, mirroring exactly how the existing
 * $iteration >= $maxIterations check is a plain conditional, never a caught
 * exception.
 *
 * Unlike RateLimitGate/BudgetGate, this gate has no entry-edge call site to
 * re-enter from at all — it is checked only from inside an
 * already-in-progress agent loop, immediately before a tool call executes
 * or a schema-validation retry is attempted.
 */
class ConversationWorkGate
{
    public function __construct(
        private readonly ConversationWorkCeilingService $ceilings,
        private readonly ConversationWorkCounter $counter,
    ) {
    }

    /**
     * Decide, without ever throwing.
     */
    public function evaluate(string $conversationId): ConversationWorkDecision
    {
        $ceiling = $this->ceilings->resolveForConversation($conversationId);

        // This branch must come first: it is the promise that a
        // conversation with nothing configured for it never reaches the
        // counter at all — zero cache traffic (FR-011).
        if ($ceiling === null) {
            return ConversationWorkDecision::noCeilingConfigured();
        }

        $reading = $this->counter->increment($conversationId, (int) $ceiling->window_seconds);

        // An unreadable counter fails open unconditionally: there is no
        // reliable count to compare, so no comparison is attempted.
        if (!$reading->available) {
            return new ConversationWorkDecision(ConversationWorkDecision::ALLOW, $ceiling, $reading);
        }

        if ($reading->count > $ceiling->max_work_units) {
            return new ConversationWorkDecision(
                ConversationWorkDecision::STOP,
                $ceiling,
                $reading,
                ConversationWorkDecision::composeReason($ceiling, $reading),
            );
        }

        return new ConversationWorkDecision(ConversationWorkDecision::ALLOW, $ceiling, $reading);
    }
}
