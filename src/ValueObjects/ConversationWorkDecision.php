<?php

namespace ClarionApp\LlmClient\ValueObjects;

use ClarionApp\LlmClient\Models\ConversationWorkCeiling;

/**
 * What ConversationWorkGate decided about one unit of agent-initiated work
 * within a conversation, and why.
 *
 * `ceiling`/`reading` are both null only in the no-ceiling-configured case,
 * where nothing was read because nothing needed to be — evaluate() never
 * touches ConversationWorkCounter when
 * ConversationWorkCeilingService::resolveForConversation() returns null.
 *
 * `reason` is built once, by composeReason(), naming the ceiling and the
 * window and stating plainly that this is a policy stop, not a failure —
 * reused verbatim by the returned content, the streamed assistant message,
 * and the closed run's end_reason, so the three can never drift into
 * describing the same stop differently. The word "step" is deliberately
 * never used here: the counted quantity is not the same granularity as an
 * agent_run_steps row.
 *
 * Unlike RateLimitDecision, this class carries no throw-carrying method at
 * all: ConversationWorkGate exposes only evaluate(), and every call site
 * interprets the returned decision with a plain conditional, mirroring
 * exactly how the existing max_iterations check is a plain conditional,
 * never an exception.
 *
 * There is no allow_with_warning outcome: outcome is plain allow/stop.
 */
final readonly class ConversationWorkDecision
{
    public const ALLOW = 'allow';
    public const STOP = 'stop';

    /**
     * The synthesized tool_result content for every tool call in a batch
     * that had not yet executed when a mid-batch stop was decided — so the
     * assistant message that batch produces is always a complete, valid
     * tool_calls/tool_results pairing, mirroring the existing declined-
     * confirmation shape ('User cancelled this operation.') verbatim in
     * kind.
     */
    public const UNEXECUTED_TOOL_RESULT = "This tool call was not executed: the conversation's per-response work ceiling was reached.";

    public function __construct(
        public string $outcome,
        public ?ConversationWorkCeiling $ceiling = null,
        public ?ConversationWorkReading $reading = null,
        public ?string $reason = null,
    ) {
    }

    /**
     * Nothing is configured for this conversation at all — no
     * conversation-scoped row and no conversation_default row. Nothing was
     * read because nothing needed to be.
     */
    public static function noCeilingConfigured(): self
    {
        return new self(self::ALLOW);
    }

    public function isStop(): bool
    {
        return $this->outcome === self::STOP;
    }

    /**
     * Compose the one sentence every surface reuses: the ceiling, the
     * window, and that this is a disclosed policy stop, not a failure.
     */
    public static function composeReason(ConversationWorkCeiling $ceiling, ConversationWorkReading $reading): string
    {
        $window = self::windowDescription((int) $ceiling->window_seconds);

        return sprintf(
            "I've reached this conversation's work ceiling (%d work units within %s) for now. "
            .'You can continue this conversation in a new message once the window resets, '
            .'or ask an operator to raise the ceiling for this conversation.',
            $ceiling->max_work_units,
            $window,
        );
    }

    private static function windowDescription(int $seconds): string
    {
        return match ($seconds) {
            60 => '1 minute',
            3600 => '1 hour',
            86400 => '1 day',
            604800 => '1 week',
            default => $seconds.' seconds',
        };
    }
}
