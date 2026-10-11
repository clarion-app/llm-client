<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * Everything PausedTurnWriter::pause() needs beyond the kind's own pending
 * payload: which call paused, the results already produced earlier in the
 * same turn, and the run-trace ids the resuming process closes.
 */
final readonly class PausedTurnContext
{
    /**
     * @param  list<array{tool_call_id: string, content: string}>  $pendingToolResults
     */
    public function __construct(
        public string $pausedToolCallId,
        public array $pendingToolResults,
        public int $iteration,
        public ?string $runId = null,
        public ?string $stepId = null,
        public ?string $actionId = null,
    ) {
    }
}
