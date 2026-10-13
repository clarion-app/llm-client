<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The plan a resume applies (checkpoint-ledger contract §4). Built by
 * `ResumePlanner::plan()` purely from durable records (the run row, the
 * steps, the step's message, the delegations, the questions); the
 * resumer applies it. Pure except reads.
 */
final class ResumePlan
{
    /**
     * @param array{content: ?string, tool_calls: list<array<string, mixed>>} $assistant
     * @param array<string, CallDisposition> $dispositions keyed by tool_call_id, in tool_calls order
     * @param array<string, mixed> $options resume_options with deadline_at shifted by the gap
     */
    public function __construct(
        public readonly string $runId,
        public readonly ResumeMode $mode,
        public readonly int $iteration,
        public readonly ?string $openStepId,
        public readonly PlanKind $kind,
        public readonly ?string $partialMessageId,
        public readonly array $assistant,
        public readonly array $dispositions,
        public readonly array $options,
    ) {
    }
}
